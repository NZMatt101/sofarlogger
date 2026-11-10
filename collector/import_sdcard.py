#!/usr/bin/env python3
"""Import SOFAR inverter SD card history into the solar logger database.

Card layout (per year folder YYYY/):
    Daily/YY-MM-DD.csv     24 rows "HH:00,kWh"  (energy produced in that hour; blank = none)
    Event/YY-MM-DD.csv     rows "HH:MM:SS,EventCode"
Monthly/yearly files are summaries of Daily and are not imported.

Each hour with energy becomes one reading at the middle of the hour, with
power_w = average power over the hour and energy_today_kwh = running total for
the day. Re-running replaces this device's previously imported rows.

Usage:
    python3 import_sdcard.py --device inv-old --path /tmp/sdcard-history \
        --db /var/lib/solar-logger/solar.db [--skip-before 2015-01-01]
"""
import argparse
import csv
import glob
import json
import os
import re
import sqlite3
from datetime import datetime, timedelta
from zoneinfo import ZoneInfo

TZ = ZoneInfo("Pacific/Auckland")


def local_ts(day, hh, mm=0, ss=0):
    return int(datetime(day.year, day.month, day.day, hh, mm, ss, tzinfo=TZ).timestamp())


def parse_day(name):
    m = re.fullmatch(r"(\d\d)-(\d\d)-(\d\d)\.csv", name)
    return datetime(2000 + int(m[1]), int(m[2]), int(m[3])).date() if m else None


def read_rows(path):
    with open(path, newline="", encoding="ascii", errors="replace") as f:
        return [r for r in csv.reader(f) if len(r) >= 2]


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--device", required=True)
    ap.add_argument("--path", required=True, help="root of the SD card copy (contains YYYY/ folders)")
    ap.add_argument("--db", default="/var/lib/solar-logger/solar.db")
    ap.add_argument("--skip-before", default="2015-01-01",
                    help="ignore data before this date (factory test data on SOFAR cards)")
    args = ap.parse_args()
    skip_before = datetime.fromisoformat(args.skip_before).date()

    db = sqlite3.connect(args.db)
    if not db.execute("SELECT 1 FROM devices WHERE id = ?", (args.device,)).fetchone():
        raise SystemExit(f"device {args.device!r} not in database; add it to config.json and restart the poller")
    db.execute("DELETE FROM readings WHERE device = ? AND json_extract(data, '$.source') = 'sdcard'", (args.device,))
    db.execute("DELETE FROM events WHERE device = ? AND source = 'sdcard'", (args.device,))

    readings, events, days = [], [], 0
    lifetime = 0.0
    for path in sorted(glob.glob(os.path.join(args.path, "[0-9][0-9][0-9][0-9]", "Daily", "*.csv")),
                       key=os.path.basename):
        day = parse_day(os.path.basename(path))
        if not day or day < skip_before:
            continue
        days += 1
        running = 0.0
        for hour, value in read_rows(path):
            value = value.strip()
            if not value:
                continue
            kwh = float(value)
            hh = int(hour.strip().split(":")[0])
            running += kwh
            lifetime += kwh
            ts = local_ts(day, hh, 30)
            data = {"source": "sdcard", "hour_kwh": kwh, "avg_power_w": round(kwh * 1000)}
            readings.append((args.device, ts, 1, round(kwh * 1000, 1), round(running, 2), round(lifetime, 2),
                             json.dumps(data), f"{os.path.basename(path)}:{hour.strip()},{value}"))

    for path in glob.glob(os.path.join(args.path, "[0-9][0-9][0-9][0-9]", "Event", "*.csv")):
        day = parse_day(os.path.basename(path))
        if not day or day < skip_before:
            continue
        for t, code in read_rows(path):
            code = code.strip()
            m = re.fullmatch(r"(\d\d):(\d\d):(\d\d)", t.strip())
            if not code or not m:
                continue
            events.append((args.device, local_ts(day, int(m[1]), int(m[2]), int(m[3])), code, "sdcard"))

    db.executemany("INSERT INTO readings(device, ts, clock_synced, power_w, energy_today_kwh, energy_total_kwh, "
                   "data, raw) VALUES (?,?,?,?,?,?,?,?)", readings)
    db.executemany("INSERT INTO events(device, ts, code, source) VALUES (?,?,?,?)", events)
    db.commit()
    print(f"{args.device}: imported {days} days, {len(readings)} hourly readings, {len(events)} events, "
          f"{lifetime:.1f} kWh total")


if __name__ == "__main__":
    main()
