#!/usr/bin/env python3
"""Send an SMS when equipment stops responding, and again when it recovers.

Runs beside the poller (separate service) and only READS the logger database.
Checks every 30 s:
  - battery-side devices (ME3000SP, BMS): always; down after `down_after_s` without a good reading
  - solar inverters on a Wi-Fi stick: only while the sun is up (they sleep at night)
  - the poller itself: down if it has stopped polling anything
  - extra HTTP checks from notify.json (e.g. the CSIP gateway Pi)
SMS goes through SMSGlobal (REST v2 with API key/secret, or the legacy HTTP API).

    python3 notifier.py --notify /etc/solar-logger/notify.json            # run
    python3 notifier.py --notify ... --test                               # send one test SMS and exit
    python3 notifier.py --notify ... --dry-run                            # print what it would send
Python standard library only.
"""
import argparse
import base64
import datetime as dt
import hashlib
import hmac
import json
import logging
import math
import os
import secrets
import sqlite3
import time
import urllib.parse
import urllib.request

log = logging.getLogger("notifier")

DEFAULTS = {
    "enabled": True,
    "prefix": "Solar Logger",      # start of every message, so you know where it came from
    "check_seconds": 30,
    "down_after_s": 180,            # battery / BMS / any always-on device
    "solar_down_after_s": 900,      # solar inverter on a Wi-Fi stick, daylight only
    "solar_min_sun_deg": 15,        # only expect the solar inverter when the sun is this high
    "poller_down_after_s": 300,
    "remind_hours": 6,              # repeat while still down (0 = never)
    "max_sms_per_day": 20,
    "http_checks": [],              # [{"name": "Gateway Pi", "url": "http://192.168.1.60/api/status", "down_after_s": 300}]
    "heartbeat_url": None,          # optional dead-man URL (e.g. healthchecks.io) pinged every 5 min
    "state_path": "/var/lib/solar-logger/notify-state.json",
    "smsglobal": {
        "api": "rest",              # "rest" (api_key + api_secret) or "http" (user + password)
        "api_key": "", "api_secret": "",
        "user": "", "password": "",
        "from": "",                 # sender ID or number, international without "+"
        "to": [],                   # recipients, international without "+", e.g. "6421..."
    },
}


# ---------------------------------------------------------------- SMS

class SmsError(Exception):
    pass


def send_sms(cfg, text, dry_run=False):
    s = cfg["smsglobal"]
    to = [n.lstrip("+").replace(" ", "") for n in s["to"]]
    if not to:
        raise SmsError("no recipients configured")
    if dry_run:
        print(f"[dry-run] SMS to {', '.join(to)}: {text}")
        return
    for number in to:
        if s.get("api", "rest") == "http":
            _send_http(s, number, text)
        else:
            _send_rest(s, number, text)
        log.info("SMS sent to ...%s: %s", number[-3:], text)


def _send_http(s, number, text):
    params = {"action": "sendsms", "user": s["user"], "password": s["password"], "to": number, "text": text}
    if s.get("from"):
        params["from"] = s["from"]
    q = urllib.parse.urlencode(params)
    with urllib.request.urlopen("https://api.smsglobal.com/http-api.php?" + q, timeout=20) as r:
        body = r.read().decode(errors="replace").strip()
    if not body.startswith("OK"):
        raise SmsError(body[:200])


def _send_rest(s, number, text):
    path = "/v2/sms"   # must match the signed path exactly
    ts, nonce = str(int(time.time())), secrets.token_hex(16)
    signing = "\n".join([ts, nonce, "POST", path, "api.smsglobal.com", "443", ""]) + "\n"
    mac = base64.b64encode(hmac.new(s["api_secret"].strip().encode(), signing.encode(), hashlib.sha256).digest()).decode()
    msg = {"destination": number, "message": text}
    if s.get("from"):
        msg["origin"] = s["from"]   # blank = SMSGlobal's default sender (NZ networks reject unregistered names)
    body = json.dumps(msg).encode()
    req = urllib.request.Request("https://api.smsglobal.com" + path, data=body, method="POST", headers={
        "Authorization": f'MAC id="{s["api_key"].strip()}", ts="{ts}", nonce="{nonce}", mac="{mac}"',
        "Content-Type": "application/json", "Accept": "application/json"})
    try:
        with urllib.request.urlopen(req, timeout=20) as r:
            r.read()
    except urllib.error.HTTPError as e:
        raise SmsError(f"HTTP {e.code}: {e.read()[:200].decode(errors='replace')}")


# ---------------------------------------------------------------- checks

def sun_elevation(lat, lon, when=None):
    """Approximate solar elevation in degrees (good to a degree or two)."""
    t = when or dt.datetime.now(dt.timezone.utc)
    n = t.timetuple().tm_yday
    decl = math.radians(23.44) * math.sin(2 * math.pi * (284 + n) / 365)
    b = 2 * math.pi * (n - 81) / 364
    eot = 9.87 * math.sin(2 * b) - 7.53 * math.cos(b) - 1.5 * math.sin(b)          # minutes
    solar_hours = t.hour + t.minute / 60 + t.second / 3600 + lon / 15 + eot / 60
    ha = math.radians(15 * (solar_hours - 12))
    la = math.radians(lat)
    return math.degrees(math.asin(math.sin(la) * math.sin(decl) + math.cos(la) * math.cos(decl) * math.cos(ha)))


def ago(seconds):
    m = int(seconds // 60)
    if m < 60:
        return f"{m} min"
    h, m = divmod(m, 60)
    return f"{h} h {m} min" if m else f"{h} h"


def hhmm(ts):
    return dt.datetime.fromtimestamp(ts).strftime("%H:%M") if ts else "unknown"


class Checker:
    def __init__(self, cfg, main_cfg, db_path):
        self.cfg, self.db_path = cfg, db_path
        site = main_cfg.get("site", {})
        self.site_name = site.get("name", "Solar")
        self.lat, self.lon = site.get("lat", -36.85), site.get("lon", 174.76)

    def _db(self):
        return sqlite3.connect(f"file:{self.db_path}?mode=ro", uri=True, timeout=10)

    def _last_detail(self, db, device):
        r = db.execute("SELECT data FROM readings WHERE device=? ORDER BY ts DESC LIMIT 1", (device,)).fetchone()
        if not r:
            return ""
        try:
            d = json.loads(r[0])
        except ValueError:
            return ""
        bits = []
        if d.get("state"):
            bits.append(str(d["state"]).lower())
        if d.get("batt_power_w") is not None:
            bits.append(f"{abs(d['batt_power_w'])} W")
        elif d.get("pac") is not None:
            bits.append(f"{d['pac']} W")
        if d.get("soc") is not None and "remaining_ah" in d:   # BMS charge %; the ME3000SP's own % is unreliable
            bits.append(f"{d['soc']}%")
        return ", ".join(bits)

    def run_checks(self):
        """Return {key: (down: bool, name, since_ts, detail)} for everything checked now."""
        now = time.time()
        out = {}
        sun = sun_elevation(self.lat, self.lon)
        with self._db() as db:
            devices = db.execute(
                "SELECT d.id, d.name, d.type, d.driver, s.last_poll, s.last_ok, s.detail FROM devices d "
                "LEFT JOIN device_status s ON s.device = d.id WHERE d.enabled = 1 AND d.retired = 0").fetchall()
            polls = [r[4] for r in devices if r[4]]
            newest_poll = max(polls) if polls else 0
            poller_down = now - newest_poll > self.cfg["poller_down_after_s"]
            out["poller"] = (poller_down, "Logger poller", newest_poll, "no devices polled")
            if poller_down:
                return out          # device ages mean nothing while the poller is stopped
            for dev_id, name, typ, driver, last_poll, last_ok, detail in devices:
                if driver == "import":
                    continue
                if driver == "omnik":
                    if sun < self.cfg["solar_min_sun_deg"]:
                        continue    # inverter asleep: neither down nor up
                    limit = self.cfg["solar_down_after_s"]
                else:
                    limit = self.cfg["down_after_s"]
                age = now - (last_ok or 0)
                out[f"dev:{dev_id}"] = (age > limit, name, last_ok, self._last_detail(db, dev_id) if age > limit else "")
        for chk in self.cfg["http_checks"]:
            key = "http:" + chk["url"]
            ok = False
            try:
                with urllib.request.urlopen(chk["url"], timeout=8) as r:
                    ok = 200 <= r.status < 300
            except Exception:
                ok = False
            out[key] = (not ok, chk["name"], None, "")
        return out


class Notifier:
    def __init__(self, cfg, checker, dry_run=False):
        self.cfg, self.checker, self.dry_run = cfg, checker, dry_run
        self.state = self._load()
        self.last_heartbeat = 0

    def _load(self):
        try:
            with open(self.cfg["state_path"]) as f:
                return json.load(f)
        except (OSError, ValueError):
            return {"items": {}, "sent": []}

    def _save(self):
        tmp = self.cfg["state_path"] + ".tmp"
        with open(tmp, "w") as f:
            json.dump(self.state, f)
        os.replace(tmp, self.cfg["state_path"])

    def _record(self, text, ok, error=""):
        entries = self.state.setdefault("log", [])
        entries.append({"ts": int(time.time()), "text": text, "ok": ok, "error": error[:200]})
        del entries[:-30]   # keep the last 30 for the admin page

    def _send(self, text):
        now = time.time()
        sent = [t for t in self.state["sent"] if now - t < 86400]
        if len(sent) >= self.cfg["max_sms_per_day"]:
            log.warning("daily SMS limit reached, not sent: %s", text)
            self._record(text, False, "daily limit reached")
            return False
        try:
            send_sms(self.cfg, text, self.dry_run)
        except Exception as e:
            log.error("SMS failed: %s (%s)", e, text)
            self._record(text, False, str(e))
            return False
        sent.append(now)
        self.state["sent"] = sent
        self._record(text, True)
        return True

    def alive(self):
        """Mark the state file every 10 minutes so the admin page can show the notifier is running."""
        now = time.time()
        if now - self.state.get("alive", 0) > 600:
            self.state["alive"] = int(now)
            self.state["enabled"] = bool(self.cfg["enabled"])
            self._save()

    def tick(self):
        now = time.time()
        site = self.cfg.get("prefix") or self.checker.site_name
        items = self.state["items"]
        changed = False
        for key, (down, name, since, detail) in self.checker.run_checks().items():
            it = items.setdefault(key, {"down": False, "first_bad": None, "alerted": False, "last_alert": 0})
            it["name"] = name
            if down:
                if it["first_bad"] is None:
                    it["first_bad"] = since or now
                    changed = True
                # http checks count from when we first saw them fail
                limit = next((c.get("down_after_s", 300) for c in self.cfg["http_checks"]
                              if key == "http:" + c["url"]), 0)
                if key.startswith("http:") and now - it["first_bad"] < limit:
                    continue
                remind = self.cfg["remind_hours"] * 3600
                if not it["alerted"] or (remind and now - it["last_alert"] > remind):
                    first = "still not responding" if it["alerted"] else "not responding"
                    text = f"{site}: {name} {first} since {hhmm(it['first_bad'])} ({ago(now - it['first_bad'])})."
                    if detail:
                        text += f" Last: {detail}."
                    if self._send(text[:300]):
                        it["alerted"], it["last_alert"] = True, now
                        changed = True
            elif it["first_bad"] is not None:
                if it["alerted"]:
                    self._send(f"{site}: {name} is back ({hhmm(now)}), after {ago(now - it['first_bad'])}.")
                it.update(first_bad=None, alerted=False, last_alert=0)
                changed = True
        if changed or not os.path.exists(self.cfg["state_path"]):
            self._save()
        if self.cfg.get("heartbeat_url") and now - self.last_heartbeat > 300:
            try:
                urllib.request.urlopen(self.cfg["heartbeat_url"], timeout=10).read()
                self.last_heartbeat = now
            except Exception as e:
                log.warning("heartbeat failed: %s", e)


def load_cfg(path):
    cfg = json.loads(json.dumps(DEFAULTS))
    with open(path) as f:
        user = json.load(f)
    sms = {**cfg["smsglobal"], **user.pop("smsglobal", {})}
    cfg.update(user)
    cfg["smsglobal"] = sms
    return cfg


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--notify", default="/etc/solar-logger/notify.json")
    ap.add_argument("--config", default="/etc/solar-logger/config.json")
    ap.add_argument("--db", default="/var/lib/solar-logger/solar.db")
    ap.add_argument("--test", action="store_true", help="send one test SMS and exit")
    ap.add_argument("--dry-run", action="store_true", help="print messages instead of sending")
    ap.add_argument("--once", action="store_true", help="run one check and exit")
    args = ap.parse_args()
    logging.basicConfig(level=logging.INFO, format="%(levelname)s %(message)s")
    def main_config():
        try:
            with open(args.config) as f:
                return json.load(f)
        except (OSError, ValueError):
            return {}

    if args.test:
        cfg = load_cfg(args.notify)
        site = cfg.get("prefix") or Checker(cfg, main_config(), args.db).site_name
        send_sms(cfg, f"{site}: test message. Alerts are working.", args.dry_run)
        return

    # Settings come from the admin page: reload notify.json whenever it changes, no restart needed.
    cfg, mtime, n = None, None, None
    while True:
        try:
            m = os.path.getmtime(args.notify)
            if m != mtime:
                cfg, mtime = load_cfg(args.notify), m
                checker = Checker(cfg, main_config(), args.db)
                if n is None:
                    n = Notifier(cfg, checker, args.dry_run)
                else:
                    n.cfg, n.checker = cfg, checker
                log.info("settings loaded: alerts %s, sun now %.0f deg",
                         "on" if cfg["enabled"] else "off", sun_elevation(checker.lat, checker.lon))
            if cfg["enabled"]:
                n.tick()
            n.alive()
        except FileNotFoundError:
            log.warning("%s not found; waiting for settings", args.notify)
        except Exception as e:
            log.exception("check failed: %s", e)
        if args.once:
            break
        time.sleep(cfg["check_seconds"] if cfg else 60)


if __name__ == "__main__":
    main()
