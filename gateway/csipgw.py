#!/usr/bin/env python3
"""CSIP gateway - Phase 1: read-only monitor for SOFAR TLM string inverters.

Polls each configured inverter over Modbus RTU (RS485) and serves a status page
and JSON API. This build contains NO write path: it only sends Modbus function 3
(read holding registers). Python standard library only.

    python3 csipgw.py --config /etc/csip-gateway/config.json
"""
import argparse
import json
import logging
import os
import select
import signal
import sqlite3
import termios
import threading
import time
from http.server import ThreadingHTTPServer, BaseHTTPRequestHandler
from urllib.parse import urlparse, parse_qs

log = logging.getLogger("csipgw")

DEFAULTS = {
    "mode": "monitor",
    "poll_seconds": 2,
    "offline_after_misses": 3,
    "http_host": "0.0.0.0",
    "http_port": 80,
    "db_path": "/var/lib/csip-gateway/gateway.db",
    "keep_days": 90,
    "inverters": [],
}

BAUDS = {4800: termios.B4800, 9600: termios.B9600, 19200: termios.B19200}


# ---------------------------------------------------------------- Modbus RTU (read only)

def crc16(data):
    crc = 0xFFFF
    for b in data:
        crc ^= b
        for _ in range(8):
            crc = (crc >> 1) ^ 0xA001 if crc & 1 else crc >> 1
    return bytes([crc & 0xFF, crc >> 8])


class ModbusError(Exception):
    pass


class RtuPort:
    """One serial port. Only read_holding() exists: there is deliberately no write method."""

    def __init__(self, path, baud=9600):
        self.path, self.baud, self.fd = path, baud, None
        self.lock = threading.Lock()

    def _open(self):
        fd = os.open(self.path, os.O_RDWR | os.O_NOCTTY | os.O_NONBLOCK)
        a = termios.tcgetattr(fd)
        a[0] = a[1] = a[3] = 0
        a[2] = termios.CS8 | termios.CREAD | termios.CLOCAL
        a[4] = a[5] = BAUDS[self.baud]
        termios.tcsetattr(fd, termios.TCSANOW, a)
        termios.tcflush(fd, termios.TCIOFLUSH)
        self.fd = fd

    def close(self):
        if self.fd is not None:
            try:
                os.close(self.fd)
            except OSError:
                pass
            self.fd = None

    def read_holding(self, addr, start, count, timeout=1.0):
        frame = bytes([addr, 3, start >> 8, start & 0xFF, count >> 8, count & 0xFF])
        frame += crc16(frame)
        want = 5 + 2 * count
        with self.lock:
            try:
                if self.fd is None:
                    self._open()
                termios.tcflush(self.fd, termios.TCIOFLUSH)
                os.write(self.fd, frame)
                buf = b""
                end = time.monotonic() + timeout
                while time.monotonic() < end and len(buf) < want:
                    r, _, _ = select.select([self.fd], [], [], 0.05)
                    if r:
                        buf += os.read(self.fd, 256)
                        if len(buf) >= 3 and buf[1] == 0x83 and len(buf) >= 5:
                            break
            except OSError as e:
                self.close()
                raise ModbusError(f"port error: {e}")
            time.sleep(0.05)  # inter-frame gap before the next request
        if not buf:
            raise ModbusError("no reply")
        if len(buf) >= 5 and buf[1] == 0x83 and crc16(buf[:3]) == buf[3:5]:
            raise ModbusError(f"exception code {buf[2]}")
        if len(buf) < want:
            raise ModbusError(f"short reply ({len(buf)} of {want} bytes)")
        buf = buf[:want]
        if crc16(buf[:-2]) != buf[-2:]:
            raise ModbusError("bad CRC")
        if buf[0] != addr or buf[1] != 3 or buf[2] != 2 * count:
            raise ModbusError("unexpected reply header")
        return [int.from_bytes(buf[3 + i:5 + i], "big") for i in range(0, 2 * count, 2)]


# ---------------------------------------------------------------- SOFAR TLM driver

class TlmDriver:
    """SOFAR 3-6 kW TLM (G1-era) register map, checked on a 4000TLM against its Wi-Fi stick.

    Function 3 only; function 4 gets no reply on this model.
    """
    START, COUNT = 0x0000, 0x1D
    STATES = {0: "Waiting", 1: "Checking", 2: "Generating", 3: "Fault", 4: "Permanent fault"}

    @classmethod
    def decode(cls, r):
        def u32(i):
            return (r[i] << 16) | r[i + 1]
        faults = [f"0x{v:04X}" for v in r[1:6] if v]
        return {
            "state_code": r[0x00],
            "state": cls.STATES.get(r[0x00], f"Unknown ({r[0x00]})"),
            "faults": faults,
            "pv1_v": r[0x06] / 10, "pv1_a": r[0x07] / 100,
            "pv2_v": r[0x08] / 10, "pv2_a": r[0x09] / 100,
            "pv1_w": r[0x0A] * 10, "pv2_w": r[0x0B] * 10,
            "power_w": r[0x0C] * 10,
            "grid_hz": r[0x0E] / 100, "grid_v": r[0x0F] / 10, "grid_a": r[0x10] / 100,
            "energy_total_kwh": u32(0x15), "hours_total": u32(0x17),
            "energy_today_kwh": r[0x19] / 100,
            "temp_c": r[0x1C],
            "raw": r,
        }


# ---------------------------------------------------------------- storage

class Store:
    """SQLite, written once a minute at most (SD card wear)."""

    def __init__(self, path, keep_days):
        os.makedirs(os.path.dirname(path), exist_ok=True)
        self.path, self.keep_days = path, keep_days
        with self._db() as db:
            db.executescript("""
            CREATE TABLE IF NOT EXISTS minute (
                ts INTEGER NOT NULL, inverter TEXT NOT NULL,
                power_w REAL, power_max_w REAL, pv1_v REAL, pv2_v REAL,
                grid_v REAL, grid_hz REAL, temp_c REAL,
                energy_today_kwh REAL, energy_total_kwh REAL, samples INTEGER,
                PRIMARY KEY (inverter, ts));
            CREATE TABLE IF NOT EXISTS event (
                ts INTEGER NOT NULL, inverter TEXT, kind TEXT, detail TEXT);
            CREATE INDEX IF NOT EXISTS event_ts ON event(ts);
            """)

    def _db(self):
        return sqlite3.connect(self.path, timeout=10)

    def write(self, minutes, events):
        if not minutes and not events:
            return
        with self._db() as db:
            db.executemany("INSERT OR REPLACE INTO minute VALUES (?,?,?,?,?,?,?,?,?,?,?,?)", minutes)
            db.executemany("INSERT INTO event VALUES (?,?,?,?)", events)

    def prune(self):
        cut = int(time.time()) - self.keep_days * 86400
        with self._db() as db:
            db.execute("DELETE FROM minute WHERE ts < ?", (cut,))
            db.execute("DELETE FROM event WHERE ts < ?", (cut,))

    def history(self, inverter, since):
        with self._db() as db:
            return db.execute(
                "SELECT ts, power_w, power_max_w FROM minute WHERE inverter=? AND ts>=? ORDER BY ts",
                (inverter, since)).fetchall()

    def events(self, limit=50):
        with self._db() as db:
            return db.execute("SELECT ts, inverter, kind, detail FROM event ORDER BY ts DESC LIMIT ?",
                              (limit,)).fetchall()


# ---------------------------------------------------------------- poller

class Inverter:
    def __init__(self, cfg, ports):
        self.id = cfg["id"]
        self.name = cfg.get("name", self.id)
        self.address = int(cfg.get("address", 1))
        self.rated_w = int(cfg.get("rated_w", 0))
        key = (cfg["port"], int(cfg.get("baud", 9600)))
        self.port = ports.setdefault(key, RtuPort(*key))
        self.port_name = cfg["port"]
        self.latest, self.last_ok, self.last_error = None, None, None
        self.misses, self.online, self.polls, self.fails = 0, None, 0, 0
        self.bucket = []  # samples for the current minute


class Gateway:
    def __init__(self, cfg):
        self.cfg = cfg
        self.started = time.time()
        ports = {}
        self.inverters = [Inverter(c, ports) for c in cfg["inverters"]]
        self.store = Store(cfg["db_path"], cfg["keep_days"])
        self.pending_events = []
        self.stop = threading.Event()

    def event(self, inv, kind, detail=""):
        log.info("%s %s %s", inv.id if inv else "-", kind, detail)
        self.pending_events.append((int(time.time()), inv.id if inv else None, kind, detail))

    def poll_once(self, inv):
        inv.polls += 1
        try:
            regs = inv.port.read_holding(inv.address, TlmDriver.START, TlmDriver.COUNT)
        except ModbusError as e:
            inv.fails += 1
            inv.misses += 1
            inv.last_error = str(e)
            if inv.online is not False and inv.misses >= self.cfg["offline_after_misses"]:
                inv.online = False
                self.event(inv, "offline", f"{inv.misses} missed polls: {e}")
            return
        data = TlmDriver.decode(regs)
        now = time.time()
        if inv.online is not True:
            self.event(inv, "online", data["state"])
        if inv.latest and data["faults"] != inv.latest.get("faults"):
            self.event(inv, "faults", ", ".join(data["faults"]) or "cleared")
        if inv.latest and data["state_code"] != inv.latest.get("state_code"):
            self.event(inv, "state", data["state"])
        inv.latest, inv.last_ok, inv.misses, inv.online, inv.last_error = data, now, 0, True, None
        inv.bucket.append(data)

    def flush_minute(self, minute_ts):
        rows = []
        for inv in self.inverters:
            b, inv.bucket = inv.bucket, []
            if not b:
                continue
            p = [d["power_w"] for d in b]
            last = b[-1]
            rows.append((minute_ts, inv.id, round(sum(p) / len(p), 1), max(p), last["pv1_v"], last["pv2_v"],
                         last["grid_v"], last["grid_hz"], last["temp_c"], last["energy_today_kwh"],
                         last["energy_total_kwh"], len(b)))
        events, self.pending_events = self.pending_events, []
        try:
            self.store.write(rows, events)
        except sqlite3.Error as e:
            log.error("database write failed: %s", e)

    def run(self):
        self.event(None, "start", f"mode={self.cfg['mode']} inverters={len(self.inverters)}")
        interval = max(1.0, float(self.cfg["poll_seconds"]))
        minute = int(time.time() // 60)
        last_prune = 0
        next_poll = time.monotonic()
        while not self.stop.is_set():
            for inv in self.inverters:
                self.poll_once(inv)
            now_min = int(time.time() // 60)
            if now_min != minute:
                self.flush_minute(minute * 60)
                minute = now_min
                if time.time() - last_prune > 86400:
                    try:
                        self.store.prune()
                    except sqlite3.Error as e:
                        log.error("prune failed: %s", e)
                    last_prune = time.time()
            next_poll += interval
            delay = next_poll - time.monotonic()
            if delay < 0:
                next_poll = time.monotonic()
                delay = 0
            self.stop.wait(delay)

    def status(self):
        now = time.time()
        invs = []
        for inv in self.inverters:
            d = dict(inv.latest) if inv.latest else None
            if d:
                d.pop("raw", None)
            invs.append({
                "id": inv.id, "name": inv.name, "address": inv.address, "rated_w": inv.rated_w,
                "port": inv.port_name,
                "online": bool(inv.online),
                "age_s": round(now - inv.last_ok, 1) if inv.last_ok else None,
                "last_error": inv.last_error, "polls": inv.polls, "fails": inv.fails,
                "data": d,
            })
        online = [i for i in invs if i["online"] and i["data"]]
        return {
            "now": int(now), "mode": self.cfg["mode"], "uptime_s": int(now - self.started),
            "poll_seconds": self.cfg["poll_seconds"],
            "site": {
                "power_w": sum(i["data"]["power_w"] for i in online),
                "energy_today_kwh": round(sum(i["data"]["energy_today_kwh"] for i in online), 2),
                "rated_w": sum(i["rated_w"] for i in invs),
                "online": len(online), "count": len(invs),
            },
            "inverters": invs,
        }


# ---------------------------------------------------------------- HTTP

WEB_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), "web")


def make_handler(gw):
    class Handler(BaseHTTPRequestHandler):
        server_version = "csipgw"

        def log_message(self, fmt, *args):
            pass  # keep the journal quiet; requests are not interesting

        def _send(self, code, body, ctype):
            self.send_response(code)
            self.send_header("Content-Type", ctype)
            self.send_header("Content-Length", str(len(body)))
            self.send_header("Cache-Control", "no-store")
            self.send_header("X-Content-Type-Options", "nosniff")
            self.end_headers()
            self.wfile.write(body)

        def _json(self, obj, code=200):
            self._send(code, json.dumps(obj).encode(), "application/json")

        def do_GET(self):
            u = urlparse(self.path)
            q = parse_qs(u.query)
            if u.path in ("/", "/index.html"):
                try:
                    with open(os.path.join(WEB_DIR, "index.html"), "rb") as f:
                        self._send(200, f.read(), "text/html; charset=utf-8")
                except OSError:
                    self._send(500, b"page missing", "text/plain")
            elif u.path == "/api/status":
                self._json(gw.status())
            elif u.path == "/api/history":
                try:
                    hours = min(48.0, max(0.5, float(q.get("hours", ["12"])[0])))
                except ValueError:
                    hours = 12.0
                since = int(time.time() - hours * 3600)
                out = {}
                for inv in gw.inverters:
                    try:
                        out[inv.id] = gw.store.history(inv.id, since)
                    except sqlite3.Error:
                        out[inv.id] = []
                self._json({"since": since, "series": out})
            elif u.path == "/api/events":
                try:
                    rows = gw.store.events(50)
                except sqlite3.Error:
                    rows = []
                self._json({"events": [dict(zip(("ts", "inverter", "kind", "detail"), r)) for r in rows]})
            else:
                self._json({"error": "not found"}, 404)

    return Handler


# ---------------------------------------------------------------- main

def load_config(path):
    cfg = dict(DEFAULTS)
    with open(path) as f:
        cfg.update(json.load(f))
    if cfg["mode"] != "monitor":
        raise SystemExit("this build supports mode 'monitor' only")
    if not cfg["inverters"]:
        raise SystemExit("no inverters configured")
    return cfg


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--config", default="/etc/csip-gateway/config.json")
    args = ap.parse_args()
    logging.basicConfig(level=logging.INFO, format="%(levelname)s %(message)s")
    cfg = load_config(args.config)
    gw = Gateway(cfg)
    httpd = ThreadingHTTPServer((cfg["http_host"], int(cfg["http_port"])), make_handler(gw))
    httpd.daemon_threads = True
    threading.Thread(target=httpd.serve_forever, daemon=True).start()
    log.info("serving on %s:%s", cfg["http_host"], cfg["http_port"])
    signal.signal(signal.SIGTERM, lambda *_: gw.stop.set())
    try:
        gw.run()
    finally:
        gw.flush_minute(int(time.time() // 60) * 60)


if __name__ == "__main__":
    main()
