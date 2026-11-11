#!/usr/bin/env python3
"""Poll site equipment (inverters, and later other devices) and store readings in SQLite.

Stdlib only. Config is a JSON file:
    {"site": {"name": "Home", "lat": -36.85, "lon": 174.76},
     "poll_seconds": 60, "store_every_seconds": 300,
     "devices": [{"id": "inv-4000", "name": "SOFAR 4000TLM", "type": "inverter",
                  "driver": "omnik", "ip": "192.168.1.50", "serial": 123456789,
                  "model": "4000TLM", "rated_w": 4000, "location": "Garage"}]}

Every reading stores common summary columns (power_w, energy_today_kwh,
energy_total_kwh) used for site totals, plus driver-specific values as JSON and
the raw reply so decoding can be corrected later. To support new equipment,
add a driver class and register it in DRIVERS.
"""
import argparse
import json
import logging
import os
import select
import socket
import sqlite3
import struct
import time

log = logging.getLogger("poller")

SCHEMA = """
CREATE TABLE IF NOT EXISTS devices (
    id TEXT PRIMARY KEY,
    name TEXT NOT NULL,
    type TEXT NOT NULL,             -- inverter | battery | meter | ...
    driver TEXT NOT NULL,
    model TEXT, serial TEXT, ip TEXT, location TEXT,
    rated_w REAL,
    fields TEXT NOT NULL,           -- JSON list of {key, label, unit, decimals}
    enabled INTEGER NOT NULL DEFAULT 1,
    sort INTEGER NOT NULL DEFAULT 0,
    retired INTEGER NOT NULL DEFAULT 0,
    notes TEXT
);
CREATE TABLE IF NOT EXISTS events (
    id INTEGER PRIMARY KEY,
    device TEXT NOT NULL,
    ts INTEGER NOT NULL,            -- UTC unix seconds
    code TEXT NOT NULL,
    source TEXT                     -- e.g. sdcard
);
CREATE INDEX IF NOT EXISTS idx_events_device_ts ON events(device, ts);
CREATE TABLE IF NOT EXISTS readings (
    id INTEGER PRIMARY KEY,
    device TEXT NOT NULL,
    ts INTEGER NOT NULL,            -- UTC unix seconds (Pi receive time)
    clock_synced INTEGER NOT NULL,
    power_w REAL,
    energy_today_kwh REAL,
    energy_total_kwh REAL,
    data TEXT NOT NULL,             -- JSON of driver-specific values
    raw TEXT
);
CREATE INDEX IF NOT EXISTS idx_readings_device_ts ON readings(device, ts);
CREATE INDEX IF NOT EXISTS idx_readings_ts ON readings(ts);
CREATE TABLE IF NOT EXISTS device_snapshot (
    device TEXT PRIMARY KEY,        -- latest values only, overwritten each poll (no history, no SD growth)
    ts INTEGER,
    live TEXT,
    settings TEXT
);
CREATE TABLE IF NOT EXISTS control_commands (
    id INTEGER PRIMARY KEY,         -- queued by admin/battery.php, executed only by this process
    device TEXT NOT NULL,
    ts INTEGER NOT NULL,
    action TEXT NOT NULL,           -- auto | standby | charge | discharge
    watts INTEGER,
    minutes INTEGER,
    status TEXT NOT NULL DEFAULT 'pending',  -- pending | accepted | rejected | refused | failed | expired
    detail TEXT,
    done_ts INTEGER
);
CREATE TABLE IF NOT EXISTS control_state (
    device TEXT PRIMARY KEY,        -- a row means the inverter is under manual control; none means auto
    mode TEXT NOT NULL,
    watts INTEGER,
    started_ts INTEGER NOT NULL,
    until_ts INTEGER,               -- NULL for standby
    stopping TEXT                   -- why it is being returned to auto (retried until the inverter accepts)
);
CREATE TABLE IF NOT EXISTS pi_control (
    device TEXT PRIMARY KEY,        -- a row exists while the Pi is the battery controller (config "controller": "pi")
    ts INTEGER,                     -- last decision
    sent TEXT,                      -- auto | standby, last command the inverter accepted
    sent_ts INTEGER,
    hold TEXT,                      -- NULL | low (at the floor) | high (at the ceiling)
    target_pct INTEGER,             -- ceiling in force (100 on a full-charge day)
    reason TEXT,                    -- why the Pi chose what it sent, in plain words
    problem TEXT,                   -- e.g. the inverter isn't in passive mode; NULL when all is well
    last_full_ts INTEGER            -- last time the BMS reported 100 %
);
CREATE TABLE IF NOT EXISTS device_status (
    device TEXT PRIMARY KEY,
    last_poll INTEGER,
    last_ok INTEGER,
    status TEXT,                    -- ok | no_data | unreachable | bad_reply | error
    detail TEXT
);
"""


class Reading:
    def __init__(self, power_w, energy_today_kwh, energy_total_kwh, data, raw=None, snapshot=False, settings=None):
        self.power_w = power_w
        self.energy_today_kwh = energy_today_kwh
        self.energy_total_kwh = energy_total_kwh
        self.data = data
        self.raw = raw
        self.snapshot = snapshot  # keep the latest values in device_snapshot
        self.settings = settings


class PollError(Exception):
    def __init__(self, status, detail=""):
        super().__init__(detail)
        self.status = status
        self.detail = detail


class OmnikDriver:
    """SOFAR TL-M / Omnik-family Wi-Fi logger (IGEN firmware), polled on TCP 8899."""
    CTRL_DATA = 0x51B0
    CTRL_NO_DATA = 0x51F0
    FIELDS = [
        {"key": "pac", "label": "AC power", "unit": "W", "decimals": 0},
        {"key": "e_today", "label": "Energy today", "unit": "kWh", "decimals": 2},
        {"key": "e_total", "label": "Lifetime energy", "unit": "kWh", "decimals": 1},
        {"key": "vpv1", "label": "PV1 voltage", "unit": "V", "decimals": 1},
        {"key": "ipv1", "label": "PV1 current", "unit": "A", "decimals": 1},
        {"key": "vpv2", "label": "PV2 voltage", "unit": "V", "decimals": 1},
        {"key": "ipv2", "label": "PV2 current", "unit": "A", "decimals": 1},
        {"key": "vac1", "label": "Grid voltage", "unit": "V", "decimals": 1},
        {"key": "iac1", "label": "Grid current", "unit": "A", "decimals": 1},
        {"key": "fac", "label": "Grid frequency", "unit": "Hz", "decimals": 2},
        {"key": "temp", "label": "Temperature", "unit": "°C", "decimals": 1},
        {"key": "h_total", "label": "Run hours", "unit": "h", "decimals": 0},
        {"key": "inverter_sn", "label": "Inverter serial", "unit": "", "decimals": None},
    ]

    @staticmethod
    def build_request(serial):
        body = bytes([0x02, 0x40, 0x30]) + struct.pack("<I", serial) * 2 + b"\x01\x00"
        return b"\x68" + body + bytes([sum(body) & 0xFF, 0x16])

    @staticmethod
    def query(ip, serial, timeout=8):
        with socket.create_connection((ip, 8899), timeout) as s:
            s.settimeout(timeout)
            s.sendall(OmnikDriver.build_request(serial))
            data = b""
            while True:
                chunk = s.recv(1024)
                if not chunk:
                    break
                data += chunk
                if len(data) > 2 and len(data) >= data[1] + 14:
                    break
        return data

    @staticmethod
    def check_frame(data):
        """Return control code if data is a complete, valid frame, else raise ValueError."""
        if len(data) < 14 or data[0] != 0x68 or len(data) < data[1] + 14:
            raise ValueError(f"short/invalid frame ({len(data)}B)")
        frame = data[:data[1] + 14]
        if frame[-1] != 0x16:
            raise ValueError("bad end byte")
        if (sum(frame[1:-2]) & 0xFF) != frame[-2]:
            raise ValueError("bad checksum")
        return struct.unpack_from(">H", frame, 2)[0]

    @staticmethod
    def decode(data):
        """Decode an Omnik-style data frame (offsets from frame start)."""
        def u16(off, div):
            v = struct.unpack_from(">H", data, off)[0]
            return None if v == 0xFFFF else round(v / div, 2)

        def u32(off, div):
            v = struct.unpack_from(">I", data, off)[0]
            return None if v == 0xFFFFFFFF else round(v / div, 2)

        r = {
            "inverter_sn": data[15:31].decode("ascii", "replace").strip(" \x00"),
            "temp": u16(31, 10),
            "fac": u16(57, 100),
            "e_today": u16(69, 100),
            "e_total": u32(71, 10),
            "h_total": struct.unpack_from(">I", data, 75)[0],
        }
        for i in range(3):
            r[f"vpv{i + 1}"] = u16(33 + 2 * i, 10)
            r[f"ipv{i + 1}"] = u16(39 + 2 * i, 10)
            r[f"iac{i + 1}"] = u16(45 + 2 * i, 10)
            r[f"vac{i + 1}"] = u16(51 + 2 * i, 10)
            r[f"pac{i + 1}"] = u16(59 + 4 * i, 1)
        r["pac"] = sum(r[f"pac{i}"] or 0 for i in (1, 2, 3))
        return r

    def poll(self, dev):
        try:
            data = self.query(dev["ip"], int(dev["serial"]))
        except OSError as e:
            raise PollError("unreachable", str(e))
        try:
            ctrl = self.check_frame(data)
        except ValueError as e:
            raise PollError("bad_reply", f"{e}: {data[:64].hex()}")
        if ctrl == self.CTRL_NO_DATA:
            raise PollError("no_data", data[12:-2].decode("ascii", "replace").strip())
        if ctrl != self.CTRL_DATA or len(data) < 79:
            raise PollError("bad_reply", f"control {ctrl:#06x}, {len(data)}B")
        r = self.decode(data)
        return Reading(r["pac"], r["e_today"], r["e_total"], r, data.hex())


class ImportedDriver:
    """Not polled: history loaded from the inverter's SD card by import_sdcard.py."""
    FIELDS = [
        {"key": "avg_power_w", "label": "Average power (hour)", "unit": "W", "decimals": 0},
        {"key": "hour_kwh", "label": "Energy this hour", "unit": "kWh", "decimals": 2},
    ]


class Me3000Driver:
    """SOFAR ME3000SP battery inverter on RS485 (Modbus RTU, 9600 8N1), default slave address 1.

    Read-only: only Modbus functions 3 and 4 are ever sent. The summary columns stay empty because a
    battery is not generation, so it never reaches site totals, savings or the Going Green page.
    Registers are from SOFAR's ModBus-RTU protocol document. On software V300 battery voltage reads in
    0.01 V (the document says 0.1 V). The charged/discharged today registers are 'reserved' in the
    document; their meaning comes from the greentangerine/ME3000 project.
    Signs as observed: battery + charging / - discharging; grid + export / - import; inverter + output.
    """
    STORE_ON_CHANGE = False  # values move every poll: store on the interval only, the SD card is small
    LIVE_START = 0x0200
    LIVE_COUNT = 69
    INFO_START = 0x2000
    INFO_COUNT = 12
    SETTINGS_START = 0x10B0
    SETTINGS_COUNT = 13
    BATTERY_TYPES = {0: "DARFON", 1: "PYLON", 2: "SOLTARO", 3: "ALPHA.ESS", 4: "GENERAL", 0x100: "DEFAULT"}
    STATES = ("Standby", "Checking (charge)", "Charging", "Checking (discharge)", "Discharging",
              "Backup (EPS)", "Fault", "Permanent fault")
    FIELDS = [
        {"key": "state", "label": "State", "unit": "", "decimals": None},
        {"key": "soc", "label": "Battery charge", "unit": "%", "decimals": 0},
        {"key": "batt_power_w", "label": "Battery power (+ charge / − discharge)", "unit": "W", "decimals": 0},
        {"key": "batt_v", "label": "Battery voltage", "unit": "V", "decimals": 2},
        {"key": "batt_a", "label": "Battery current", "unit": "A", "decimals": 2},
        {"key": "batt_temp", "label": "Battery temperature", "unit": "°C", "decimals": 0},
        {"key": "inverter_w", "label": "Inverter power (+ output)", "unit": "W", "decimals": 0},
        {"key": "grid_w", "label": "Grid power (+ export / − import)", "unit": "W", "decimals": 0},
        {"key": "load_w", "label": "House load", "unit": "W", "decimals": 0},
        {"key": "gen_w", "label": "Solar reported to inverter", "unit": "W", "decimals": 0},
        {"key": "grid_v", "label": "Grid voltage", "unit": "V", "decimals": 1},
        {"key": "grid_hz", "label": "Grid frequency", "unit": "Hz", "decimals": 2},
        {"key": "inner_temp", "label": "Inverter temperature", "unit": "°C", "decimals": 0},
        {"key": "heatsink_temp", "label": "Heatsink temperature", "unit": "°C", "decimals": 0},
        {"key": "charge_today", "label": "Battery charged today", "unit": "kWh", "decimals": 2},
        {"key": "discharge_today", "label": "Battery discharged today", "unit": "kWh", "decimals": 2},
        {"key": "bought_today", "label": "Grid import today", "unit": "kWh", "decimals": 2},
        {"key": "sold_today", "label": "Grid export today", "unit": "kWh", "decimals": 2},
        {"key": "load_today", "label": "House use today", "unit": "kWh", "decimals": 2},
        {"key": "gen_today", "label": "Solar today (inverter's figure)", "unit": "kWh", "decimals": 2},
        {"key": "cycles", "label": "Battery cycles", "unit": "", "decimals": 0},
        {"key": "faults", "label": "Faults", "unit": "", "decimals": None},
        {"key": "alert", "label": "Alert flags", "unit": "", "decimals": None},
        {"key": "inverter_sn", "label": "Inverter serial", "unit": "", "decimals": None},
        {"key": "software", "label": "Software version", "unit": "", "decimals": None},
    ]

    def __init__(self):
        self.identity = {}  # (port, slave) -> {inverter_sn, software}
        self.settings = {}  # (port, slave) -> last battery settings read

    @staticmethod
    def crc16(data):
        crc = 0xFFFF
        for b in data:
            crc ^= b
            for _ in range(8):
                crc = (crc >> 1) ^ 0xA001 if crc & 1 else crc >> 1
        return crc

    @classmethod
    def build_read(cls, slave, function, start, count):
        if function not in (3, 4):
            raise ValueError("this driver only reads")
        frame = struct.pack(">BBHH", slave, function, start, count)
        return frame + struct.pack("<H", cls.crc16(frame))

    @classmethod
    def parse_reply(cls, buf, slave, function, count):
        """Registers from a CRC-valid reply anywhere in buf (some adapters echo the request first)."""
        size = 5 + count * 2
        for i in range(len(buf)):
            f = buf[i:i + size]
            if (len(f) == size and f[0] == slave and f[1] == function and f[2] == count * 2
                    and struct.unpack("<H", f[-2:])[0] == cls.crc16(f[:-2])):
                return list(struct.unpack(">%dH" % count, f[3:-2])), f
            e = buf[i:i + 5]
            if (len(e) == 5 and e[0] == slave and e[1] == (function | 0x80)
                    and struct.unpack("<H", e[-2:])[0] == cls.crc16(e[:-2])):
                raise PollError("bad_reply", f"inverter refused the read (Modbus exception {e[2]})")
        raise PollError("bad_reply", f"invalid reply: {buf[:32].hex()}")

    @staticmethod
    def open_port(port):
        import termios  # Linux only; the rest of this module also runs on the PC for tests
        fd = os.open(port, os.O_RDWR | os.O_NOCTTY | os.O_NONBLOCK)
        try:
            a = termios.tcgetattr(fd)
            a[0] = a[1] = a[3] = 0
            a[2] = termios.CS8 | termios.CREAD | termios.CLOCAL
            a[4] = a[5] = termios.B9600
            termios.tcsetattr(fd, termios.TCSANOW, a)
            termios.tcflush(fd, termios.TCIOFLUSH)
        except Exception:
            os.close(fd)
            raise
        return fd

    @staticmethod
    def transact(fd, frame, timeout=2.0):
        """Send one frame and return whatever comes back until the line goes quiet."""
        import termios
        termios.tcflush(fd, termios.TCIOFLUSH)
        os.write(fd, frame)
        buf = b""
        end = time.monotonic() + timeout
        while time.monotonic() < end:
            ready, _, _ = select.select([fd], [], [], 0.25 if buf else 0.1)
            if ready:
                buf += os.read(fd, 512)
            elif buf:
                break
        return buf

    @classmethod
    def read_registers(cls, fd, port, slave, function, start, count, timeout=2.0):
        last = None
        for _ in range(2):  # one retry: a single garbled frame shouldn't mark the device down
            buf = cls.transact(fd, cls.build_read(slave, function, start, count), timeout)
            try:
                return cls.parse_reply(buf, slave, function, count)
            except PollError as e:
                last = e if buf else PollError("unreachable", f"no reply from the inverter on {port}")
            time.sleep(0.2)
        raise last

    @staticmethod
    def fault_list(words, first_id):
        """Active fault IDs: bit 0 of the first word is first_id (SOFAR numbers them from the low byte up)."""
        return [first_id + w * 16 + bit for w, v in enumerate(words) for bit in range(16) if v >> bit & 1]

    @classmethod
    def decode(cls, regs):
        def r(addr):
            return regs[addr - cls.LIVE_START]

        def s(addr):
            v = r(addr)
            return v - 65536 if v >= 32768 else v

        state = r(0x0200)
        faults = cls.fault_list([r(a) for a in range(0x0201, 0x0206)], 1) + \
            cls.fault_list([r(a) for a in range(0x023D, 0x0242)], 100)
        return {
            "state": cls.STATES[state] if state < len(cls.STATES) else f"Unknown ({state})",
            "soc": r(0x0210),
            "batt_power_w": s(0x020D) * 10,
            "batt_v": r(0x020E) / 100,
            "batt_a": s(0x020F) / 100,
            "batt_temp": s(0x0211),
            "inverter_w": s(0x0214) * 10,
            "grid_w": s(0x0212) * 10,
            "load_w": r(0x0213) * 10,
            "gen_w": r(0x0215) * 10,
            "grid_v": r(0x0206) / 10,
            "grid_hz": r(0x020C) / 100,
            "inner_temp": s(0x0238),
            "heatsink_temp": s(0x0239),
            "charge_today": r(0x0224) / 100,
            "discharge_today": r(0x0225) / 100,
            "bought_today": r(0x021A) / 100,
            "sold_today": r(0x0219) / 100,
            "load_today": r(0x021B) / 100,
            "gen_today": r(0x0218) / 100,
            "gen_total": r(0x021C) << 16 | r(0x021D),
            "sold_total": r(0x021E) << 16 | r(0x021F),
            "bought_total": r(0x0220) << 16 | r(0x0221),
            "load_total": r(0x0222) << 16 | r(0x0223),
            "cycles": r(0x022C),
            "faults": ", ".join(f"ID{n:02d}" for n in faults) or "None",
            "alert": f"{r(0x022B):#06x}",
        }

    @classmethod
    def decode_identity(cls, regs):
        text = struct.pack(">%dH" % len(regs), *regs).decode("ascii", "replace")
        return {"inverter_sn": text[2:16].strip(" \x00"), "software": text[16:20].strip(" \x00")}

    @classmethod
    def decode_settings(cls, regs):
        """Battery settings as the inverter reports them (0x10BA is undocumented and left out)."""
        return {
            "battery_type": cls.BATTERY_TYPES.get(regs[0], f"Unknown ({regs[0]:#06x})"),
            "capacity_ah": regs[1],
            "max_charge_v": regs[3] / 10,
            "max_charge_a": regs[4] / 100,
            "over_voltage_v": regs[5] / 10,
            "min_discharge_v": regs[6] / 10,
            "max_discharge_a": regs[7] / 100,
            "under_voltage_v": regs[8] / 10,
            "discharge_depth_pct": regs[9],
            "empty_v": regs[11] / 100,
            "full_v": regs[12] / 100,
        }

    def poll(self, dev):
        port = dev.get("port", "/dev/ttyUSB0")
        slave = int(dev.get("slave", 1))
        try:
            fd = self.open_port(port)
        except OSError as e:
            raise PollError("unreachable", f"cannot open {port}: {e.strerror or e}")
        try:
            regs, frame = self.read_registers(fd, port, slave, 3, self.LIVE_START, self.LIVE_COUNT)
            key = (port, slave)
            if key not in self.identity:
                try:
                    info, _ = self.read_registers(fd, port, slave, 4, self.INFO_START, self.INFO_COUNT)
                    self.identity[key] = self.decode_identity(info)
                except PollError as e:
                    log.warning("%s: could not read inverter identity: %s", dev.get("id"), e.detail)
            try:
                stored, _ = self.read_registers(fd, port, slave, 4, self.SETTINGS_START, self.SETTINGS_COUNT)
                self.settings[key] = self.decode_settings(stored)
            except PollError as e:
                log.warning("%s: could not read battery settings: %s", dev.get("id"), e.detail)
        except OSError as e:
            raise PollError("unreachable", f"{port}: {e.strerror or e}")
        finally:
            os.close(fd)
        data = self.decode(regs)
        data.update(self.identity.get(key, {}))
        return Reading(None, None, None, data, frame.hex(), snapshot=True, settings=self.settings.get(key))


class JbdBmsDriver:
    """JBD (Jiabaida) battery BMS on RS485, 9600 8N1, using JBD's own protocol (the one the JBD phone app speaks).

    Read-only: only the 0x03 (basic info) and 0x04 (cell voltages) read requests are ever sent; the protocol's
    write and parameter commands (0x5A) are never built. Frames are DD A5 <reg> 00 <checksum> 77, and replies
    DD <reg> <status> <len> <data> <checksum> 77, checksum = 0x10000 minus the sum of len and data.
    Current is + charging / - discharging, so battery power has the same sign convention as the ME3000SP.
    The BMS's own state of charge (coulomb counting) is the reference; inverters on a 'DEFAULT' battery type guess.
    """
    STORE_ON_CHANGE = False  # values move every poll: store on the interval only, the SD card is small
    PROTECTION = ("cell over-voltage", "cell under-voltage", "pack over-voltage", "pack under-voltage",
                  "charge over-temperature", "charge under-temperature", "discharge over-temperature",
                  "discharge under-temperature", "charge over-current", "discharge over-current", "short circuit",
                  "front-end chip error", "MOSFETs locked by software")
    FIELDS = [
        {"key": "soc", "label": "Battery charge (BMS)", "unit": "%", "decimals": 0},
        {"key": "batt_power_w", "label": "Battery power (+ charge / − discharge)", "unit": "W", "decimals": 0},
        {"key": "batt_v", "label": "Pack voltage", "unit": "V", "decimals": 2},
        {"key": "batt_a", "label": "Current (+ charge / − discharge)", "unit": "A", "decimals": 2},
        {"key": "remaining_ah", "label": "Remaining capacity", "unit": "Ah", "decimals": 1},
        {"key": "full_ah", "label": "Full capacity", "unit": "Ah", "decimals": 0},
        {"key": "cell_min_v", "label": "Lowest cell", "unit": "V", "decimals": 3},
        {"key": "cell_max_v", "label": "Highest cell", "unit": "V", "decimals": 3},
        {"key": "cell_spread_mv", "label": "Cell spread", "unit": "mV", "decimals": 0},
        {"key": "temp_min", "label": "Lowest temperature", "unit": "°C", "decimals": 1},
        {"key": "temp_max", "label": "Highest temperature", "unit": "°C", "decimals": 1},
        {"key": "charge_fet", "label": "Charging allowed", "unit": "", "decimals": None},
        {"key": "discharge_fet", "label": "Discharging allowed", "unit": "", "decimals": None},
        {"key": "balancing", "label": "Cells balancing", "unit": "", "decimals": None},
        {"key": "faults", "label": "Protection", "unit": "", "decimals": None},
        {"key": "cycles", "label": "Cycles", "unit": "", "decimals": 0},
        {"key": "cells_mv", "label": "Cell voltages (mV)", "unit": "", "decimals": None},
        {"key": "temps", "label": "Temperature sensors (°C)", "unit": "", "decimals": None},
        {"key": "bms_version", "label": "BMS version", "unit": "", "decimals": None},
    ]

    @staticmethod
    def build_read(reg):
        if reg not in (0x03, 0x04):
            raise ValueError("this driver only reads")
        return bytes([0xDD, 0xA5, reg, 0x00]) + ((0x10000 - reg) & 0xFFFF).to_bytes(2, "big") + b"\x77"

    @staticmethod
    def parse_reply(buf, reg):
        """Data bytes from a checksum-valid reply anywhere in buf."""
        for i in range(len(buf) - 6):
            if buf[i] != 0xDD or buf[i + 1] != reg:
                continue
            n = buf[i + 3]
            f = buf[i:i + n + 7]
            if len(f) < n + 7 or f[-1] != 0x77:
                continue
            data = f[4:4 + n]
            if (0x10000 - (n + sum(data))) & 0xFFFF != int.from_bytes(f[4 + n:6 + n], "big"):
                continue
            if f[2] != 0:
                raise PollError("bad_reply", f"BMS refused the read (status {f[2]})")
            return data, f
        raise PollError("bad_reply", f"invalid reply: {buf[:32].hex()}")

    @classmethod
    def read(cls, fd, port, reg):
        last = None
        for _ in range(2):  # one retry: a single garbled frame shouldn't mark the device down
            buf = Me3000Driver.transact(fd, cls.build_read(reg), 1.5)
            try:
                return cls.parse_reply(buf, reg)
            except PollError as e:
                last = e if buf else PollError("unreachable", f"no reply from the BMS on {port}")
            time.sleep(0.2)
        raise last

    @classmethod
    def decode(cls, basic, cells):
        u = lambda d, i: int.from_bytes(d[i:i + 2], "big")
        volts = u(basic, 0) / 100
        amps = int.from_bytes(basic[2:4], "big", signed=True) / 100
        protection = u(basic, 16)
        balance = u(basic, 12) | u(basic, 14) << 16
        fet = basic[20]
        ntc = basic[22]
        temps = [round((u(basic, 23 + 2 * k) - 2731) / 10, 1) for k in range(ntc) if 25 + 2 * k <= len(basic)]
        mv = [u(cells, 2 * k) for k in range(len(cells) // 2)]
        flags = [name for bit, name in enumerate(cls.PROTECTION) if protection >> bit & 1]
        flags += [f"bit {bit}" for bit in range(len(cls.PROTECTION), 16) if protection >> bit & 1]
        return {
            "soc": basic[19],
            "batt_power_w": round(volts * amps),
            "batt_v": volts,
            "batt_a": amps,
            "remaining_ah": u(basic, 4) / 100,
            "full_ah": u(basic, 6) / 100,
            "cell_min_v": min(mv) / 1000 if mv else None,
            "cell_max_v": max(mv) / 1000 if mv else None,
            "cell_spread_mv": max(mv) - min(mv) if mv else None,
            "temp_min": min(temps) if temps else None,
            "temp_max": max(temps) if temps else None,
            "charge_fet": "Yes" if fet & 1 else "No",
            "discharge_fet": "Yes" if fet & 2 else "No",
            "balancing": ", ".join(str(k + 1) for k in range(32) if balance >> k & 1) or "None",
            "faults": ", ".join(flags) or "None",
            "cycles": u(basic, 8),
            "cells_mv": " ".join(str(v) for v in mv),
            "temps": " ".join(f"{t:g}" for t in temps),
            "bms_version": f"{basic[18]:#04x}",
        }

    def poll(self, dev):
        port = dev.get("port", "/dev/ttyUSB1")
        try:
            fd = Me3000Driver.open_port(port)
        except OSError as e:
            raise PollError("unreachable", f"cannot open {port}: {e.strerror or e}")
        try:
            basic, f3 = self.read(fd, port, 0x03)
            cells, f4 = self.read(fd, port, 0x04)
        except OSError as e:
            raise PollError("unreachable", f"{port}: {e.strerror or e}")
        finally:
            os.close(fd)
        if len(basic) < 23:
            raise PollError("bad_reply", f"basic info too short ({len(basic)} bytes)")
        return Reading(None, None, None, self.decode(basic, cells), f3.hex() + " " + f4.hex(), snapshot=True)


class Me3000Control:
    """Commands to the ME3000SP (SOFAR's Passive Mode protocol). Kept apart from Me3000Driver, which only reads.

    Sent only by Poller.control_tick, in response to a command an admin queued or to return the inverter to
    auto. There is no documented way to change battery voltages or limits over RS485.
    """
    REGISTERS = {"standby": 0x0100, "discharge": 0x0101, "charge": 0x0102, "auto": 0x0103}
    STANDBY_VALUE = 0x5555
    MAX_WATTS = 3000
    FUNCTION = 0x42
    STATUS = {1: "wrong work mode, check the mode on the inverter's screen", 2: "the inverter reported a CRC error",
              3: "the inverter is busy", 4: "the inverter refused the value"}

    @classmethod
    def build_command(cls, slave, action, watts=None):
        if action not in cls.REGISTERS:
            raise ValueError(f"unknown action {action}")
        if action == "standby":
            value = cls.STANDBY_VALUE
        elif action == "auto":
            value = 0  # register is the grid target in watts; 0 is normal auto
        else:
            if not isinstance(watts, int) or not 0 < watts <= cls.MAX_WATTS:
                raise ValueError(f"power must be 1 to {cls.MAX_WATTS} W")
            value = watts
        frame = struct.pack(">BBHH", slave, cls.FUNCTION, cls.REGISTERS[action], value)
        return frame + struct.pack("<H", Me3000Driver.crc16(frame))

    @staticmethod
    def build_heartbeat(slave):
        frame = struct.pack(">BBHH", slave, 0x49, 0x2201, 0x2202)
        return frame + struct.pack("<H", Me3000Driver.crc16(frame))

    @classmethod
    def parse_reply(cls, buf, slave):
        """(result, flags) from a CRC-valid reply: result 0 is accepted; flags are the status word's high byte."""
        for i in range(len(buf)):
            f = buf[i:i + 7]
            if (len(f) == 7 and f[0] == slave and f[1] == cls.FUNCTION and f[2] == 2
                    and struct.unpack("<H", f[-2:])[0] == Me3000Driver.crc16(f[:-2])):
                return f[4], f[3]
            e = buf[i:i + 5]
            if (len(e) == 5 and e[0] == slave and e[1] == (cls.FUNCTION | 0x80)
                    and struct.unpack("<H", e[-2:])[0] == Me3000Driver.crc16(e[:-2])):
                raise PollError("bad_reply", f"Modbus exception {e[2]}")
        raise PollError("bad_reply", f"invalid reply: {buf[:32].hex()}" if buf else "no reply from the inverter")

    @classmethod
    def send(cls, dev, action, watts=None):
        port = dev.get("port", "/dev/ttyUSB0")
        slave = int(dev.get("slave", 1))
        frame = cls.build_command(slave, action, watts)
        try:
            fd = Me3000Driver.open_port(port)
        except OSError as e:
            raise PollError("unreachable", f"cannot open {port}: {e.strerror or e}")
        try:
            last = None
            for _ in range(2):  # resending is safe: the same command just applies again
                try:
                    return cls.parse_reply(Me3000Driver.transact(fd, frame), slave)
                except PollError as e:
                    last = e
                time.sleep(0.2)
            raise last
        except OSError as e:
            raise PollError("unreachable", f"{port}: {e.strerror or e}")
        finally:
            os.close(fd)

    @classmethod
    def heartbeat(cls, dev):
        """SOFAR asks for one at least once a minute in passive mode. The document doesn't define the reply."""
        slave = int(dev.get("slave", 1))
        try:
            fd = Me3000Driver.open_port(dev.get("port", "/dev/ttyUSB0"))
        except OSError as e:
            raise PollError("unreachable", str(e))
        try:
            return Me3000Driver.transact(fd, cls.build_heartbeat(slave), 0.5)
        finally:
            os.close(fd)

    @staticmethod
    def flags_text(flags):
        notes = []
        if flags & 4:
            notes.append("battery full, charging prohibited")
        if flags & 8:
            notes.append("battery flat, discharging prohibited")
        return "; ".join(notes)


DRIVERS = {"omnik": OmnikDriver, "import": ImportedDriver, "me3000": Me3000Driver, "jbd": JbdBmsDriver}

# Control timing (seconds) and default safety limits; limits can be changed per device on the battery page.
CONTROL_COMMAND_MAX_AGE = 120   # a queued command older than this is dropped, never applied late
CONTROL_GUARD_SECONDS = 10
CONTROL_HEARTBEAT_SECONDS = 30
CONTROL_MAX_READ_FAILS = 6
DEFAULT_MIN_DISCHARGE_V = 50.0
DEFAULT_MAX_CHARGE_V = 53.5

# Pi as battery controller (inverter screen in Passive mode). The Pi only ever chooses the inverter's own
# passive "auto" (it follows the house load itself) or standby; it never sets a power level.
PI_DEFAULTS = {"floor_pct": 20, "ceiling_pct": 95, "full_every_days": 7, "full_spread_mv": 30}
PI_TICK_SECONDS = 15            # read the inverter and decide this often
PI_MIN_SWITCH_SECONDS = 60      # don't flip auto/standby faster than this (passing clouds), except for safety
PI_RESEND_SECONDS = 300         # repeat the current choice now and then in case the inverter restarted
PI_DEADBAND_W = 100             # solar vs house load difference treated as "about equal"
PI_RESUME_MARGIN_PCT = 3        # leave the floor/ceiling hold only this far back inside the band
PI_BMS_MAX_AGE = 180            # older BMS readings count as missing
PI_CELL_LOW_V = 2.90            # any cell at or below: treat as the floor whatever the BMS percentage says
PI_CELL_HIGH_V = 3.55           # any cell at or above: treat as the ceiling


def linked_bms_id(cfg, dev):
    """The battery BMS an ME3000SP is linked to (same rule as inc/common.php linked_bms_id): its "bms" setting if
    present ("" = none), else the older Pi-control setting, else the only BMS when the site has exactly one
    ME3000SP and one BMS. Returns a device id or None."""
    active = [d for d in cfg.get("devices", []) if not d.get("retired")]
    bms_ids = [d["id"] for d in active if d["driver"] == "jbd"]
    if "bms" in dev:
        return dev["bms"] if dev["bms"] in bms_ids else None
    old = (dev.get("pi_control") or {}).get("bms")
    if old in bms_ids:
        return old
    mes = [d for d in active if d["driver"] == "me3000"]
    return bms_ids[0] if len(mes) == 1 and len(bms_ids) == 1 else None


def pi_settings(dev):
    return {**PI_DEFAULTS, **(dev.get("pi_control") or {})}


def pi_decide(opts, bms, inv, hold, last_full_ts, now):
    """The Pi controller's choice: (command, hold, target_pct, reason). Pure, so it can be tested without hardware.

    bms: latest BMS values with their 'ts', or None. inv: fresh inverter values. hold: None, 'low' or 'high'
    from the last decision (hysteresis). Fails safe: anything doubtful about the BMS means standby.
    """
    if not bms or now - bms.get("ts", 0) > PI_BMS_MAX_AGE:
        return "standby", hold, None, "no recent reading from the battery BMS"
    faults = [] if bms.get("faults", "None") == "None" else bms["faults"].split(", ")
    # Voltage protections and switched-off MOSFETs block one direction only; anything else stops both.
    no_charge = bms.get("charge_fet") == "No" or any("over-voltage" in f for f in faults)
    no_discharge = bms.get("discharge_fet") == "No" or any("under-voltage" in f for f in faults)
    other = [f for f in faults if "over-voltage" not in f and "under-voltage" not in f]
    if other or (no_charge and no_discharge):
        return "standby", hold, None, f"BMS protection active: {bms.get('faults')}" if faults \
            else "BMS has switched off both charging and discharging"
    soc, floor = bms["soc"], opts["floor_pct"]
    cmin, cmax = bms.get("cell_min_v") or 0, bms.get("cell_max_v") or 0
    full_due = (opts["full_every_days"] and (last_full_ts is None or now - last_full_ts >= opts["full_every_days"] * 86400)) \
        or (bms.get("cell_spread_mv") or 0) >= opts["full_spread_mv"]
    target = 100 if full_due else opts["ceiling_pct"]

    if soc <= floor or cmin <= PI_CELL_LOW_V or no_discharge:
        hold = "low"
    elif soc >= target or cmax >= PI_CELL_HIGH_V or no_charge:
        hold = "high"
    elif hold == "low" and soc >= floor + PI_RESUME_MARGIN_PCT and cmin > PI_CELL_LOW_V + 0.05:
        hold = None
    elif hold == "high" and soc <= target - PI_RESUME_MARGIN_PCT and cmax < PI_CELL_HIGH_V - 0.05:
        hold = None

    net = (inv.get("gen_w") or 0) - (inv.get("load_w") or 0)   # + spare solar / - house needs more
    if hold == "low":
        if net > PI_DEADBAND_W:
            return "auto", hold, target, f"battery at {soc}% (floor {floor}%): charging from {net} W of spare solar"
        return "standby", hold, target, f"battery at {soc}% (floor {floor}%): holding, no spare solar"
    if hold == "high":
        if net < -PI_DEADBAND_W:
            return "auto", hold, target, f"battery at {soc}% (limit {target}%): supplying the house"
        return "standby", hold, target, f"battery at {soc}% (limit {target}%): full enough, spare solar exported"
    return "auto", hold, target, f"battery at {soc}%: normal operation" + (" (full charge due)" if target == 100 else "")


# Time-of-use (TOU) slots: up to 10 per ME3000SP in config "tou", used only under Pi control. Each slot:
# {"on": true, "name": "Night rate", "days": [0..6] (Mon = 0), "start": "HH:MM", "end": "HH:MM",
#  "action": "charge" | "hold", "watts": 100..3000, "target_pct": 20..100}. A slot may run past midnight
# (start later than end); its days are the days it starts on. Overlapping slots: the lowest number wins.
TOU_MAX_SLOTS = 10
TOU_RESUME_MARGIN_PCT = 3       # after reaching the target, charge again only once this far below it


def tou_minutes(hhmm):
    h, m = (int(x) for x in str(hhmm).split(":"))
    if not (0 <= h < 24 and 0 <= m < 60):
        raise ValueError(hhmm)
    return h * 60 + m


def tou_active(slots, lt):
    """(slot number from 1, slot) for the first switched-on slot covering local time lt, else (None, None)."""
    now_m, day, prev = lt.tm_hour * 60 + lt.tm_min, lt.tm_wday, (lt.tm_wday - 1) % 7
    for num, s in enumerate((slots or [])[:TOU_MAX_SLOTS], 1):
        if not s.get("on") or s.get("action") not in ("charge", "hold"):
            continue
        try:
            a, b = tou_minutes(s["start"]), tou_minutes(s["end"])
        except (KeyError, ValueError):
            continue
        days = set(s.get("days", range(7)))
        if a < b:
            hit = day in days and a <= now_m < b
        elif a > b:   # runs past midnight
            hit = (day in days and now_m >= a) or (prev in days and now_m < b)
        else:
            hit = False
        if hit:
            return num, s
    return None, None


def tou_decide(slot, num, bms, inv, done, max_charge_v):
    """Pi choice inside a TOU slot: (command, watts, done, reason). Pure.

    Called only after pi_decide found the BMS fresh and free of faults, so this only adds the slot's own stops.
    done: the slot already reached its target (stay off until TOU_RESUME_MARGIN_PCT below it).
    """
    label = f"TOU {num}" + (f" ({slot['name']})" if slot.get("name") else "") + f" until {slot['end']}"
    soc = bms["soc"]
    net = (inv.get("gen_w") or 0) - (inv.get("load_w") or 0)
    faults = [] if bms.get("faults", "None") == "None" else bms["faults"].split(", ")
    blocked = None
    if bms.get("charge_fet") == "No" or any("over-voltage" in f for f in faults):
        blocked = "the BMS has stopped charging"
    elif (bms.get("cell_max_v") or 0) >= PI_CELL_HIGH_V:
        blocked = f"a cell reached {bms['cell_max_v']} V"
    elif (inv.get("batt_v") or 0) >= max_charge_v:
        blocked = f"battery reached {inv['batt_v']} V (limit {max_charge_v} V)"
    elif inv.get("faults", "None") != "None":
        blocked = f"inverter fault {inv['faults']}"

    def keep(why):   # no discharging; spare solar may still charge it unless charging is blocked
        if net > PI_DEADBAND_W and not blocked:
            return "auto", None, done, f"{label}: {why}, charging from {net} W of spare solar"
        return "standby", None, done, f"{label}: {why}"

    if slot["action"] == "hold":
        return keep(f"keeping the battery for later (at {soc}%)")
    target = int(slot.get("target_pct", 100))
    if soc >= target or blocked:
        done = True
    elif done and soc <= target - TOU_RESUME_MARGIN_PCT:
        done = False
    if blocked:
        return keep(f"charging stopped: {blocked}")
    if done:
        return keep(f"battery at {soc}%, target {target}% reached")
    watts = max(100, min(Me3000Control.MAX_WATTS, int(slot.get("watts", 1000))))
    return "charge", watts, done, f"{label}: charging at {watts} W to {target}% (now {soc}%)"


def migrate(db):
    """Add columns introduced after the first schema version."""
    cols = {r[1] for r in db.execute("PRAGMA table_info(devices)")}
    if "retired" not in cols:
        db.execute("ALTER TABLE devices ADD COLUMN retired INTEGER NOT NULL DEFAULT 0")
    if "notes" not in cols:
        db.execute("ALTER TABLE devices ADD COLUMN notes TEXT")


def clock_synced():
    try:
        with open("/run/systemd/timesync/synchronized"):
            return 1
    except OSError:
        return 0


class Poller:
    def __init__(self, config_path, db_path):
        self.config_path = config_path
        self.config_mtime = None
        self.db = sqlite3.connect(db_path)
        self.db.execute("PRAGMA journal_mode=WAL")
        try:  # group (www-data) write access for Admin → History import/clear; -wal/-shm follow this mode
            os.chmod(db_path, 0o664)
        except OSError:
            pass
        self.db.executescript(SCHEMA)
        migrate(self.db)
        self.drivers = {name: cls() for name, cls in DRIVERS.items()}
        self.last_stored = {}  # device id -> (ts, data)
        self.day_state = {}    # device id -> {day, acc, base, last_raw}; see local_today()
        self.control_timers = {}  # device id -> {hb, guard, fails, retry}
        self.pi_state = {}        # device id -> Pi control state; see pi_tick()
        self.pi_skipped = set()   # Pi-control devices control_tick won't run (no CT, or a second unit)
        self.reload_config()
        # After a restart the clock may be wrong and nobody was guarding a timed run, so end it. Standby is safe to keep.
        self.db.execute("UPDATE control_state SET stopping = 'Logger restarted during a timed run' "
                        "WHERE until_ts IS NOT NULL AND stopping IS NULL")
        self.db.commit()

    def local_today(self, dev_id, now, raw):
        """Energy for the local (Pacific/Auckland) day from the inverter's own 'today' counter.

        The inverter keeps UTC and resets its counter at UTC midnight (13:00 NZDT), and also
        when it restarts. Track the local day here: when the counter drops mid-day, carry the
        energy counted before the drop. At a new local day, the counter's value carried over
        from the previous evening is the baseline. Raw values stay in the reading's data JSON.
        """
        if raw is None:
            return None
        day = time.strftime("%Y-%m-%d", time.localtime(now))
        st = self.day_state.get(dev_id)
        if st is None:
            # Resume from the last stored reading so a restart doesn't lose the day so far.
            row = self.db.execute("SELECT ts, energy_today_kwh, data FROM readings WHERE device = ? "
                                  "ORDER BY ts DESC LIMIT 1", (dev_id,)).fetchone()
            if row and row[1] is not None:
                prev_raw = json.loads(row[2]).get("e_today", row[1])
                st = {"day": time.strftime("%Y-%m-%d", time.localtime(row[0])), "acc": row[1],
                      "base": prev_raw, "last_raw": prev_raw}
            else:
                # Brand-new device: no history, so take the inverter's own 'today' as the best estimate.
                st = {"day": day, "acc": 0.0, "base": 0.0, "last_raw": raw}
        if day != st["day"]:
            # Counter still holding last evening's value -> that's the baseline; if it has reset, start at 0.
            base = st["last_raw"] if raw >= st["last_raw"] else 0.0
            st = {"day": day, "acc": 0.0, "base": base, "last_raw": raw}
        elif raw < st["last_raw"] - 0.05:
            log.info("%s: inverter 'today' counter dropped %.2f -> %.2f kWh; carrying %.2f kWh",
                     dev_id, st["last_raw"], raw, st["last_raw"] - st["base"])
            st["acc"] += st["last_raw"] - st["base"]
            st["base"] = 0.0
        st["last_raw"] = raw
        self.day_state[dev_id] = st
        return round(st["acc"] + raw - st["base"], 3)

    def reload_config(self):
        """(Re)load config when the file changes (the admin page edits it). A bad file keeps the old config."""
        try:
            mtime = os.stat(self.config_path).st_mtime
            if mtime == self.config_mtime:
                return
            with open(self.config_path, encoding="utf-8") as f:
                cfg = json.load(f)
            for d in cfg["devices"]:
                DRIVERS[d["driver"]]  # unknown driver -> KeyError
        except (OSError, ValueError, KeyError) as e:
            if self.config_mtime is None:
                raise
            log.error("config reload failed, keeping previous config: %s", e)
            self.config_mtime = os.stat(self.config_path).st_mtime
            return
        self.cfg = cfg
        self.config_mtime = mtime
        # Re-read running 'today' totals from the DB (Admin touches the config after importing or clearing history).
        self.day_state.clear()
        self.devices = [d for d in cfg["devices"] if d.get("enabled", True) and not d.get("retired")
                        and hasattr(DRIVERS[d["driver"]], "poll")]
        self.sync_devices()
        log.info("config loaded: %d device(s), %d polled", len(cfg["devices"]), len(self.devices))

    def sync_devices(self):
        """Mirror the configured devices into the DB; devices removed from config keep their history."""
        self.db.execute("UPDATE devices SET enabled = 0")
        for i, d in enumerate(self.cfg["devices"]):
            drv = DRIVERS[d["driver"]]
            self.db.execute(
                "INSERT INTO devices(id, name, type, driver, model, serial, ip, location, rated_w, fields, enabled, "
                "sort, retired, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON CONFLICT(id) DO UPDATE SET "
                "name=excluded.name, type=excluded.type, driver=excluded.driver, model=excluded.model, "
                "serial=excluded.serial, ip=excluded.ip, location=excluded.location, rated_w=excluded.rated_w, "
                "fields=excluded.fields, enabled=excluded.enabled, sort=excluded.sort, retired=excluded.retired, "
                "notes=excluded.notes",
                (d["id"], d["name"], d["type"], d["driver"], d.get("model"), str(d.get("serial", "")),
                 d.get("ip") or d.get("port"), d.get("location"), d.get("rated_w"), json.dumps(drv.FIELDS),
                 1 if d.get("enabled", True) else 0, i, 1 if d.get("retired") else 0, d.get("notes")))
        self.db.commit()

    def set_status(self, dev, now, status, detail="", ok=False):
        self.db.execute(
            "INSERT INTO device_status(device, last_poll, last_ok, status, detail) VALUES (?,?,?,?,?) "
            "ON CONFLICT(device) DO UPDATE SET last_poll=excluded.last_poll, "
            "last_ok=COALESCE(excluded.last_ok, device_status.last_ok), status=excluded.status, "
            "detail=excluded.detail",
            (dev["id"], now, now if ok else None, status, detail))

    def poll_one(self, dev):
        now = int(time.time())
        try:
            driver = self.drivers[dev["driver"]]
            r = driver.poll(dev)
        except PollError as e:
            self.set_status(dev, now, e.status, e.detail)
            return
        self.save_snapshot(dev["id"], now, r)
        today = self.local_today(dev["id"], now, r.energy_today_kwh)
        prev = self.last_stored.get(dev["id"])
        changed = prev is not None and prev[1] != r.data and getattr(driver, "STORE_ON_CHANGE", True)
        if prev is None or changed or now - prev[0] >= self.cfg.get("store_every_seconds", 300):
            self.db.execute(
                "INSERT INTO readings(device, ts, clock_synced, power_w, energy_today_kwh, energy_total_kwh, data, raw) "
                "VALUES (?,?,?,?,?,?,?,?)",
                (dev["id"], now, clock_synced(), r.power_w, today, r.energy_total_kwh,
                 json.dumps(r.data), r.raw))
            self.last_stored[dev["id"]] = (now, r.data)
            log.info("%s: stored power=%sW today=%skWh (inverter %s) total=%skWh",
                     dev["id"], r.power_w, today, r.energy_today_kwh, r.energy_total_kwh)
        self.set_status(dev, now, "ok", ok=True)

    def save_snapshot(self, dev_id, now, r):
        if r.snapshot:
            self.db.execute("INSERT OR REPLACE INTO device_snapshot(device, ts, live, settings) VALUES (?,?,?,?)",
                            (dev_id, now, json.dumps(r.data), json.dumps(r.settings)))

    def control_event(self, dev_id, now, text):
        log.info("%s: control: %s", dev_id, text)
        self.db.execute("INSERT INTO events(device, ts, code, source) VALUES (?,?,?,?)", (dev_id, now, text, "control"))

    def control_read(self, dev, now):
        """Fresh values for a guard check; also keeps the battery page's figures current during a run."""
        r = self.drivers[dev["driver"]].poll(dev)
        self.save_snapshot(dev["id"], now, r)
        return r.data

    def control_command(self, dev, action, watts, minutes, now):
        """Run one queued command. Returns (status, detail)."""
        floor = float(dev.get("min_discharge_v", DEFAULT_MIN_DISCHARGE_V))
        ceiling = float(dev.get("max_charge_v", DEFAULT_MAX_CHARGE_V))
        try:
            if action in ("charge", "discharge"):
                if not minutes or minutes <= 0:
                    return "refused", "A time limit is required"
                live = self.control_read(dev, now)
                if live["faults"] != "None":
                    return "refused", f"Inverter has an active fault ({live['faults']})"
                if action == "discharge" and live["batt_v"] <= floor:
                    return "refused", f"Battery is at {live['batt_v']} V, at or below the {floor} V limit"
                if action == "charge" and live["batt_v"] >= ceiling:
                    return "refused", f"Battery is at {live['batt_v']} V, at or above the {ceiling} V limit"
            result, flags = Me3000Control.send(dev, action, watts)
        except PollError as e:
            return "failed", e.detail
        except ValueError as e:
            return "refused", str(e)
        label = {"auto": "Auto", "standby": "Standby"}.get(action) or f"{action.capitalize()} {watts} W for {minutes} min"
        notes = Me3000Control.flags_text(flags)
        if result != 0:
            detail = f"{label} rejected: {Me3000Control.STATUS.get(result, f'status {result}')}"
            self.control_event(dev["id"], now, detail)
            return "rejected", detail
        if action == "auto":
            self.db.execute("DELETE FROM control_state WHERE device = ?", (dev["id"],))
        else:
            until = now + minutes * 60 if action != "standby" else None
            self.db.execute("INSERT OR REPLACE INTO control_state(device, mode, watts, started_ts, until_ts, stopping) "
                            "VALUES (?,?,?,?,?,NULL)", (dev["id"], action, watts, now, until))
        self.control_timers[dev["id"]] = {"hb": now, "guard": now, "fails": 0, "retry": 0}
        detail = f"{label} accepted" + (f" ({notes})" if notes else "")
        self.control_event(dev["id"], now, detail)
        return "accepted", detail

    def control_stop(self, dev, reason, now, already_stopping=False):
        """Return to auto. If the inverter doesn't accept, keep retrying from control_watch."""
        try:
            result, _ = Me3000Control.send(dev, "auto")
            problem = None if result == 0 else Me3000Control.STATUS.get(result, f"status {result}")
        except PollError as e:
            problem = e.detail
        if problem is None:
            self.db.execute("DELETE FROM control_state WHERE device = ?", (dev["id"],))
            self.control_event(dev["id"], now, f"Returned to auto: {reason}")
            return
        self.db.execute("UPDATE control_state SET stopping = ? WHERE device = ?", (reason, dev["id"]))
        if not already_stopping:
            self.control_event(dev["id"], now, f"Could not return to auto ({problem}), retrying: {reason}")

    def control_watch(self, dev, mode, until, stopping, now):
        t = self.control_timers.setdefault(dev["id"], {"hb": 0, "guard": 0, "fails": 0, "retry": 0})
        if stopping:
            if now - t["retry"] >= CONTROL_GUARD_SECONDS:
                t["retry"] = now
                self.control_stop(dev, stopping, now, already_stopping=True)
            return
        if until is not None and now >= until:
            self.control_stop(dev, "time limit reached", now)
            return
        if until is not None and now - t["guard"] >= CONTROL_GUARD_SECONDS:
            t["guard"] = now
            try:
                live = self.control_read(dev, now)
                t["fails"] = 0
            except PollError as e:
                t["fails"] += 1
                if t["fails"] >= CONTROL_MAX_READ_FAILS:
                    self.control_stop(dev, f"inverter stopped answering ({e.detail})", now)
                return
            floor = float(dev.get("min_discharge_v", DEFAULT_MIN_DISCHARGE_V))
            ceiling = float(dev.get("max_charge_v", DEFAULT_MAX_CHARGE_V))
            if live["faults"] != "None":
                self.control_stop(dev, f"fault {live['faults']}", now)
                return
            if mode == "discharge" and live["batt_v"] <= floor:
                self.control_stop(dev, f"battery reached {live['batt_v']} V (limit {floor} V)", now)
                return
            if mode == "charge" and live["batt_v"] >= ceiling:
                self.control_stop(dev, f"battery reached {live['batt_v']} V (limit {ceiling} V)", now)
                return
        if now - t["hb"] >= CONTROL_HEARTBEAT_SECONDS:
            t["hb"] = now
            try:
                Me3000Control.heartbeat(dev)
            except (PollError, OSError) as e:
                log.warning("%s: heartbeat failed: %s", dev["id"], e)

    def pi_bms(self, dev):
        """Latest values from the battery BMS linked to this inverter (see linked_bms_id)."""
        bms_id = linked_bms_id(self.cfg, dev)
        if not bms_id:
            return None
        row = self.db.execute("SELECT ts, live FROM device_snapshot WHERE device = ?", (bms_id,)).fetchone()
        return {**json.loads(row[1]), "ts": row[0]} if row else None

    def pi_save(self, dev_id, st, now):
        self.db.execute(
            "INSERT OR REPLACE INTO pi_control(device, ts, sent, sent_ts, hold, target_pct, reason, problem, last_full_ts) "
            "VALUES (?,?,?,?,?,?,?,?,?)", (dev_id, now, st["sent"], st["sent_ts"], st["hold"], st["target"],
                                           st["reason"], st["problem"], st["last_full"]))

    def pi_problem(self, dev, st, problem, now):
        if problem != st["problem"]:
            self.control_event(dev["id"], now, f"Pi control: {problem}" if problem else "Pi control: working again")
        st["problem"] = problem

    def pi_tick(self, dev, now, manual):
        """One step of Pi control for an ME3000SP whose config says "controller": "pi"."""
        st = self.pi_state.get(dev["id"])
        if st is None:
            row = self.db.execute("SELECT hold, last_full_ts FROM pi_control WHERE device = ?", (dev["id"],)).fetchone()
            st = {"tick": 0, "hb": 0, "sent": None, "sent_ts": 0, "hold": row[0] if row else None, "target": None,
                  "reason": None, "problem": None, "last_full": row[1] if row else None, "fails": 0}
            self.pi_state[dev["id"]] = st
            if row is None:   # newly switched on, not just a poller restart
                self.control_event(dev["id"], now, "Pi control on: the Pi decides when the battery charges and discharges")
        if manual:
            # A manual run from the battery page takes priority; control_watch keeps its heartbeat going.
            st["sent"], st["reason"] = None, "paused for a manual run on the battery page"
            if now - st["tick"] >= PI_TICK_SECONDS:
                st["tick"] = now
                self.pi_save(dev["id"], st, now)
            return
        if now - st["tick"] < PI_TICK_SECONDS:
            if now - st["hb"] >= CONTROL_HEARTBEAT_SECONDS:
                st["hb"] = now
                try:
                    Me3000Control.heartbeat(dev)
                except (PollError, OSError) as e:
                    log.warning("%s: heartbeat failed: %s", dev["id"], e)
            return
        st["tick"] = now
        try:
            inv = self.control_read(dev, now)
            st["fails"] = 0
        except PollError as e:
            st["fails"] += 1
            if st["fails"] >= 3:
                self.pi_problem(dev, st, f"the inverter isn't answering ({e.detail})", now)
            self.pi_save(dev["id"], st, now)
            return
        bms = self.pi_bms(dev)
        if bms and bms.get("soc", 0) >= 100 and now - bms["ts"] <= PI_BMS_MAX_AGE:
            st["last_full"] = now
        want, hold, target, reason = pi_decide(pi_settings(dev), bms, inv, st["hold"], st["last_full"], now)
        num, slot = tou_active(dev.get("tou"), time.localtime(now))
        if hold != st["hold"] and not (slot and target is not None):   # a TOU slot logs its own events
            self.control_event(dev["id"], now, f"Pi control: {reason}")
        st["hold"], st["reason"] = hold, reason
        if target is not None:
            st["target"] = target
        safety = target is None   # missing BMS data or BMS protection: act at once

        # Time-of-use slot: overrides normal Pi control, but never pi_decide's safety standby.
        action, watts = want, None
        if num != st.get("tou_num"):
            if num:
                what = (f"charge from the grid at {slot.get('watts')} W to {slot.get('target_pct')}%"
                        if slot["action"] == "charge" else "keep the battery (no discharging)")
                self.control_event(dev["id"], now, f"TOU {num}{' (' + slot['name'] + ')' if slot.get('name') else ''} "
                                                   f"started: {what} until {slot['end']}")
            else:
                self.control_event(dev["id"], now, f"TOU {st['tou_num']} ended: back to normal Pi control")
            st["tou_num"], st["tou_done"] = num, False
        if slot and not safety:
            action, watts, st["tou_done"], reason = tou_decide(
                slot, num, bms, inv, st.get("tou_done", False), float(dev.get("max_charge_v", DEFAULT_MAX_CHARGE_V)))
            st["reason"] = reason
            want = action if watts is None else f"{action} {watts}"   # what st["sent"] records
        # Stopping a grid charge is always immediate, like a safety change.
        safety = safety or (str(st["sent"] or "").startswith("charge") and action != "charge")

        due = st["sent"] is None or now - st["sent_ts"] >= (PI_RESEND_SECONDS if want == st["sent"] else PI_MIN_SWITCH_SECONDS)
        if want != st["sent"] and (safety or due) or want == st["sent"] and due:
            try:
                result, flags = Me3000Control.send(dev, action, watts)
            except PollError as e:
                result, flags = None, 0
                self.pi_problem(dev, st, f"command not delivered ({e.detail})", now)
            if result == 0:
                if want != st["sent"]:
                    log.info("%s: Pi control sent %s: %s", dev["id"], want, reason)
                st["sent"], st["sent_ts"] = want, now
                self.pi_problem(dev, st, None, now)
                notes = Me3000Control.flags_text(flags)
                if notes and want == "auto":
                    st["reason"] = f"{reason} (inverter reports {notes})"
            elif result is not None:
                st["sent"] = None
                self.pi_problem(dev, st, Me3000Control.STATUS.get(result, f"inverter refused (status {result})")
                                if result != 1 else "the inverter isn't in Passive mode: set Work Mode to Passive "
                                "on its screen (Settings > Work Mode Set > Passive Mode)", now)
        st["hb"] = now if st["sent_ts"] == now else st["hb"]
        self.pi_save(dev["id"], st, now)

    def pi_off(self, dev_id, devs, now):
        """Pi control was switched off: hand back with a final passive auto, then forget the Pi state."""
        self.pi_state.pop(dev_id, None)
        self.db.execute("DELETE FROM pi_control WHERE device = ?", (dev_id,))
        note = ""
        if dev_id in devs:
            try:
                result, _ = Me3000Control.send(devs[dev_id], "auto")
                note = "" if result == 0 else " (inverter is already out of Passive mode)"
            except PollError as e:
                note = f" (final auto not delivered: {e.detail})"
        self.control_event(dev_id, now, "Pi control off: set Work Mode back to Auto on the inverter's screen" + note)

    def control_tick(self):
        """Run queued commands and guard any manual run. Does nothing on sites without a battery inverter."""
        devs = {d["id"]: d for d in self.cfg["devices"] if d["driver"] == "me3000"}
        now = int(time.time())
        pi_devs = [i for i, d in devs.items() if d.get("controller") == "pi" and not d.get("retired")]
        # Standalone Pi control follows the unit's own CT in passive Auto, so it needs a CT, and two such units on
        # one grid cable would fight. Until they're paired, run only the first unit with a CT; leave the rest alone
        # (no commands, not even the hand-back Auto). The admin page refuses these settings too: this is a backstop.
        runnable = [i for i in pi_devs if devs[i].get("has_ct", True)][:1]
        for dev_id in pi_devs:
            if dev_id not in runnable and dev_id not in self.pi_skipped:
                self.pi_skipped.add(dev_id)
                why = "it has no CT" if not devs[dev_id].get("has_ct", True) else "another ME3000SP is already under Pi control"
                self.control_event(dev_id, now, f"Pi control not started: {why}")
        self.pi_skipped &= set(pi_devs) - set(runnable)
        for dev_id in [r[0] for r in self.db.execute("SELECT device FROM pi_control")]:
            if dev_id not in pi_devs:
                self.pi_off(dev_id, devs, now)
        if not devs:
            self.db.commit()
            return
        pending = self.db.execute("SELECT id, device, ts, action, watts, minutes FROM control_commands "
                                  "WHERE status = 'pending' ORDER BY id").fetchall()
        for cmd_id, device, ts, action, watts, minutes in pending:
            if device not in devs:
                status, detail = "failed", "Unknown device"
            elif now - ts > CONTROL_COMMAND_MAX_AGE:
                status, detail = "expired", "Not sent: it waited too long in the queue"
            else:
                status, detail = self.control_command(devs[device], action, watts, minutes, now)
            self.db.execute("UPDATE control_commands SET status = ?, detail = ?, done_ts = ? WHERE id = ?",
                            (status, detail, now, cmd_id))
        states = self.db.execute("SELECT device, mode, until_ts, stopping FROM control_state").fetchall()
        for device, mode, until, stopping in states:
            if device in devs:
                self.control_watch(devs[device], mode, until, stopping, now)
        manual = {s[0] for s in states}
        for dev_id in runnable:
            self.pi_tick(devs[dev_id], now, dev_id in manual)
        self.db.commit()

    def run(self):
        while True:
            self.reload_config()
            interval = self.cfg.get("poll_seconds", 60)
            start = time.monotonic()
            for dev in self.devices:
                try:
                    self.poll_one(dev)
                except Exception as e:
                    log.exception("unexpected error polling %s", dev.get("id"))
                    self.set_status(dev, int(time.time()), "error", str(e))
                self.db.commit()
            next_poll = start + max(1.0, interval)
            while True:
                try:
                    self.control_tick()
                except Exception:
                    log.exception("unexpected error in control loop")
                    self.db.rollback()
                remaining = next_poll - time.monotonic()
                if remaining <= 0:
                    break
                time.sleep(min(1.0, remaining))


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--config", default="/etc/solar-logger/config.json")
    ap.add_argument("--db", default="/var/lib/solar-logger/solar.db")
    ap.add_argument("--once", action="store_true", help="poll each device once, print decoded values, exit")
    args = ap.parse_args()
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(message)s")
    with open(args.config, encoding="utf-8") as f:
        cfg = json.load(f)

    if args.once:
        for dev in cfg["devices"]:
            if dev.get("retired") or not hasattr(DRIVERS[dev["driver"]], "poll"):
                continue
            try:
                r = DRIVERS[dev["driver"]]().poll(dev)
                print(dev["id"], json.dumps(r.data, indent=2, ensure_ascii=False), r.raw)
            except PollError as e:
                print(dev["id"], e.status, e.detail)
        return

    Poller(args.config, args.db).run()


if __name__ == "__main__":
    main()
