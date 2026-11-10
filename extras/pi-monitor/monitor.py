#!/usr/bin/env python3
"""Pi monitor: a small always-on display of solar, house, battery and grid from the solar logger.

Reads one JSON request from the logger (api.php?action=site) and draws it on a 2.8" ILI9341 SPI screen
(320x240). No desktop, browser or kernel display driver: the screen is driven over SPI from Python.

    python3 monitor.py --url http://solar-pi.local             # run on the Pi with the screen
    python3 monitor.py --url http://solar-pi.local --png out.png --once   # preview anywhere (no screen needed)

Needs Pillow; on the Pi also spidev and gpiod (libgpiod 2). See README.md.
"""
import argparse
import json
import os
import time
import urllib.request

from PIL import Image, ImageChops, ImageDraw, ImageFont

W, H = 320, 240

# Colours (RGB)
BG = (12, 14, 18)
CARD = (28, 32, 40)
TEXT = (235, 238, 242)
MUTED = (140, 148, 160)
SOLAR = (250, 190, 40)
HOUSE = (90, 170, 255)
BATT = (60, 200, 120)
IMPORT = (240, 90, 80)
EXPORT = (60, 200, 120)
WARN = (240, 170, 60)


# ---------------------------------------------------------------- data

def fetch(url, timeout=8):
    with urllib.request.urlopen(url.rstrip("/") + "/api.php?action=site", timeout=timeout) as r:
        return json.load(r)


def summarise(site):
    """The few figures the screen shows, from the logger's site JSON. Missing values are None."""
    devs = [d for d in site.get("devices", []) if not d.get("retired")]
    inverters = [d for d in devs if d.get("type") == "inverter"]
    me = next((d for d in devs if d.get("driver") == "me3000"), None)
    bms = next((d for d in devs if d.get("driver") == "jbd"), None)
    data = lambda d: ((d or {}).get("latest") or {}).get("data") or {}
    online = lambda d: bool(d) and d.get("state") == "online"

    solar = site.get("totals", {}).get("power_w") if any(online(d) for d in inverters) else None
    night = bool(inverters) and all(d.get("state") == "night" for d in inverters)
    bat_src = bms if online(bms) else me if online(me) else None
    return {
        "name": site.get("site", {}).get("name", "Solar"),
        "solar_w": 0 if night else solar,
        "solar_today": site.get("totals", {}).get("energy_today_kwh"),
        "house_w": data(me).get("load_w") if online(me) else None,
        "grid_w": data(me).get("grid_w") if online(me) else None,       # + export / - import
        "soc": data(bat_src).get("soc") if bat_src else None,
        "soc_source": "BMS" if bat_src is bms and bms else "inverter",
        "batt_w": data(bat_src).get("batt_power_w") if bat_src else None,  # + charging / - discharging
        "faults": next((f for f in (data(bms).get("faults"), data(me).get("faults")) if f and f != "None"), None),
        "bms": data(bms) if online(bms) else None,     # everything the BMS reports, for the battery page
        "inv_soc": data(me).get("soc") if online(me) else None,
    }


# ---------------------------------------------------------------- drawing

def load_fonts(font_dir):
    def f(name, size):
        for d in [font_dir, "/usr/share/fonts/truetype/dejavu", "C:/Windows/Fonts"]:
            if d and os.path.exists(os.path.join(d, name)):
                return ImageFont.truetype(os.path.join(d, name), size)
        return ImageFont.load_default(size)
    return {"big": f("DejaVuSans-Bold.ttf", 34), "mid": f("DejaVuSans-Bold.ttf", 20),
            "label": f("DejaVuSans-Bold.ttf", 13), "small": f("DejaVuSans.ttf", 12),
            "tiny": f("DejaVuSans.ttf", 11)}


def watts(w):
    """(number, unit) for a card, e.g. ('640', 'W') or ('2.35', 'kW')."""
    if w is None:
        return ("—", "")
    w = abs(w)
    return (f"{w / 1000:.2f}", "kW") if w >= 1000 else (f"{round(w)}", "W")


def watts_text(w):
    """Short form for status lines: one decimal for kW so it fits beside the battery bar."""
    w = abs(w)
    return f"{w / 1000:.1f} kW" if w >= 1000 else f"{round(w)} W"


def card(draw, fonts, box, label, colour, value, sub=None, sub_colour=MUTED, stale=False, reserve=0):
    """value is text, or (number, unit) to draw the unit smaller. reserve: pixels kept free on the right."""
    x0, y0, x1, y1 = box
    draw.rounded_rectangle(box, 8, fill=CARD)
    draw.rectangle((x0, y0 + 8, x0 + 3, y1 - 8), fill=colour)
    draw.text((x0 + 12, y0 + 8), label, font=fonts["label"], fill=colour)
    number, unit = value if isinstance(value, tuple) else (value, "")
    room = x1 - x0 - 20 - reserve
    big, small = fonts["big"], fonts["mid"]
    width = lambda: draw.textlength(number, font=big) + (draw.textlength(" " + unit, font=small) if unit else 0)
    if width() > room:   # too wide: drop a size
        big, small = fonts["mid"], fonts["small"]
    fill = MUTED if stale else TEXT
    draw.text((x0 + 12, y0 + 28), number, font=big, fill=fill)
    if unit:
        draw.text((x0 + 12 + draw.textlength(number, font=big) + 4, y0 + 28 + big.size - small.size - 1),
                  unit, font=small, fill=fill)
    if sub:
        while len(sub) > 1 and draw.textlength(sub, font=fonts["small"]) > room:
            sub = sub[:-2] + "…"
        draw.text((x0 + 12, y1 - 22), sub, font=fonts["small"], fill=sub_colour)


def header(d, fonts, title):
    d.text((10, 6), title, font=fonts["mid"], fill=TEXT)
    d.text((W - 10, 9), time.strftime("%H:%M"), font=fonts["mid"], fill=MUTED, anchor="ra")


def footer(d, fonts, s, updated, error, extra=None):
    if error:
        text, colour = f"No data: {error}"[:52], IMPORT
    elif s.get("faults"):
        text, colour = f"Fault: {s['faults']}"[:52], WARN
    else:
        text, colour = f"Updated {time.strftime('%H:%M:%S', time.localtime(updated))}", MUTED
    if error and updated:
        text += f" (last {time.strftime('%H:%M', time.localtime(updated))})"
    d.text((10, H - 20), text, font=fonts["small"], fill=colour)
    if extra and not error:
        d.text((W - 10, H - 20), extra, font=fonts["small"], fill=MUTED, anchor="ra")


def render(s, fonts, updated, error=None, page=0):
    return (render_main, render_battery)[page](s, fonts, updated, error)


def render_main(s, fonts, updated, error=None):
    img = Image.new("RGB", (W, H), BG)
    d = ImageDraw.Draw(img)
    header(d, fonts, s["name"] if s else "Solar")

    pad, top, gap = 8, 36, 8
    cw, ch = (W - 2 * pad - gap) // 2, (H - top - 26 - gap) // 2
    boxes = [(pad + c * (cw + gap), top + r * (ch + gap), pad + c * (cw + gap) + cw, top + r * (ch + gap) + ch)
             for r in range(2) for c in range(2)]

    s = s or {}
    today = s.get("solar_today")
    card(d, fonts, boxes[0], "SOLAR", SOLAR, watts(s.get("solar_w")),
         f"Today {today:.1f} kWh" if today is not None else None, stale=s.get("solar_w") is None)
    card(d, fonts, boxes[1], "HOUSE", HOUSE, watts(s.get("house_w")), stale=s.get("house_w") is None)

    soc, bw = s.get("soc"), s.get("batt_w")
    flow = "—" if bw is None else "Idle" if abs(bw) < 30 else \
        f"Charging {watts_text(bw)}" if bw > 0 else f"Supplying {watts_text(bw)}"
    # "% est": the inverter's own estimate (no BMS reading), which can read far too high
    unit = "%" if s.get("soc_source") == "BMS" else "% est"
    card(d, fonts, boxes[2], "BATTERY", BATT, (f"{soc}", unit) if soc is not None else "—", flow,
         stale=soc is None, reserve=18)   # the charge bar starts 22 px in from the card's right edge
    if soc is not None:   # charge bar along the right of the battery card
        x0, y0, x1, y1 = boxes[2]
        bx0, by0, bx1, by1 = x1 - 22, y0 + 10, x1 - 10, y1 - 10
        d.rounded_rectangle((bx0, by0, bx1, by1), 3, outline=MUTED)
        fill_top = by1 - 2 - (by1 - by0 - 4) * max(0, min(100, soc)) / 100
        d.rectangle((bx0 + 2, fill_top, bx1 - 2, by1 - 2), fill=IMPORT if soc <= 20 else BATT)

    g = s.get("grid_w")
    if g is None:
        card(d, fonts, boxes[3], "GRID", MUTED, "—", stale=True)
    elif abs(g) < 30:
        card(d, fonts, boxes[3], "GRID", MUTED, ("0", "W"), "Not using the grid")
    else:
        exporting = g > 0
        card(d, fonts, boxes[3], "GRID", EXPORT if exporting else IMPORT, watts(g),
             "Exporting" if exporting else "Importing", EXPORT if exporting else IMPORT)

    footer(d, fonts, s, updated, error)
    return img


def render_battery(s, fonts, updated, error=None):
    """Battery detail from the BMS: charge, energy stored, the 16 cell voltages, temperatures and status."""
    img = Image.new("RGB", (W, H), BG)
    d = ImageDraw.Draw(img)
    header(d, fonts, "Battery")
    s = s or {}
    b = s.get("bms")
    if not b:
        d.text((W // 2, H // 2), "No BMS reading", font=fonts["mid"], fill=MUTED, anchor="mm")
        footer(d, fonts, s, updated, error)
        return img
    f = lambda k: b.get(k) if isinstance(b.get(k), (int, float)) else None
    soc, bw, v, a = f("soc"), f("batt_power_w") or 0, f("batt_v"), f("batt_a")

    # Top card: charge %, bar, state and energy
    x0, y0, x1, y1 = 8, 34, W - 8, 104
    d.rounded_rectangle((x0, y0, x1, y1), 8, fill=CARD)
    low = soc is not None and soc <= 20
    d.text((x0 + 12, y0 + 6), f"{soc}" if soc is not None else "—", font=fonts["big"], fill=TEXT)
    if soc is not None:
        d.text((x0 + 14 + d.textlength(f"{soc}", font=fonts["big"]), y0 + 18), "%", font=fonts["mid"], fill=TEXT)
        bx0, by0, bx1, by1 = x0 + 12, y0 + 48, x0 + 132, y0 + 60
        d.rounded_rectangle((bx0, by0, bx1, by1), 3, outline=MUTED)
        d.rectangle((bx0 + 2, by0 + 2, bx0 + 2 + (bx1 - bx0 - 4) * max(0, min(100, soc)) / 100, by1 - 2),
                    fill=IMPORT if low else BATT)
    state, colour = ("Idle", MUTED) if abs(bw) < 30 else \
        (f"Charging {watts_text(bw)}", BATT) if bw > 0 else (f"Supplying {watts_text(bw)}", WARN)
    tx = x0 + 148
    d.text((tx, y0 + 8), state, font=fonts["label"], fill=colour)
    rem, full = f("remaining_ah"), f("full_ah")
    if rem is not None and full and v:
        d.text((tx, y0 + 28), f"{rem * v / 1000:.1f} of {full * 51.2 / 1000:.1f} kWh", font=fonts["small"], fill=TEXT)
    inv = s.get("inv_soc")
    d.text((tx, y0 + 46), f"Inverter estimate {inv}%" if inv is not None else "", font=fonts["small"], fill=MUTED)

    # Cells: 2 rows of 8, lowest outlined blue, highest amber
    try:
        cells = [int(c) for c in str(b.get("cells_mv") or "").split()]
    except ValueError:
        cells = []
    spread = f("cell_spread_mv")
    d.text((10, 110), "CELLS", font=fonts["label"], fill=BATT)
    if spread is not None:
        d.text((W - 10, 110), f"spread {spread:.0f} mV", font=fonts["small"], fill=WARN if spread > 30 else MUTED,
               anchor="ra")
    if cells:
        lo, hi = min(cells), max(cells)
        tw, th, gx = 36, 24, 2
        left = (W - (8 * tw + 7 * gx)) // 2
        for i, mv in enumerate(cells[:16]):
            cx, cy = left + (i % 8) * (tw + gx), 128 + (i // 8) * (th + gx)
            edge = HOUSE if mv == lo and lo != hi else WARN if mv == hi and lo != hi else None
            bad = mv < 2900 or mv > 3550   # outside the Pi control's safe cell range
            d.rounded_rectangle((cx, cy, cx + tw, cy + th), 4, fill=CARD, outline=IMPORT if bad else edge,
                                width=2 if (bad or edge) else 1)
            d.text((cx + tw / 2, cy + th / 2), f"{mv / 1000:.3f}", font=fonts["tiny"],
                   fill=IMPORT if bad else TEXT, anchor="mm")

    # Status line: voltage, current, temperatures, switches
    t_lo, t_hi = f("temp_min"), f("temp_max")
    parts = [f"{v:.2f} V" if v is not None else "— V", f"{a:+.1f} A" if a is not None else "— A",
             f"{t_lo:.0f}–{t_hi:.0f} °C" if t_lo is not None and t_hi is not None else "— °C"]
    d.text((10, 184), "   ".join(parts), font=fonts["label"], fill=TEXT)
    fets = "on" if b.get("charge_fet") == "Yes" and b.get("discharge_fet") == "Yes" else \
        f"charge {b.get('charge_fet', '?')}, discharge {b.get('discharge_fet', '?')}"
    bal = b.get("balancing") or "None"
    status = f"Switches {fets}  ·  Balancing {'no' if bal == 'None' else bal}"
    d.text((10, 202), status, font=fonts["small"], fill=MUTED if fets == "on" else WARN)

    cyc = b.get("cycles")
    footer(d, fonts, s, updated, error, f"{cyc} cycles" if cyc is not None else None)
    return img


# ---------------------------------------------------------------- ILI9341 over SPI

def rgb565(img):
    """Big-endian RGB565 bytes for the ILI9341, built with Pillow channel ops (no numpy, fast on a Pi Zero)."""
    r, g, b = img.split()
    hi = ImageChops.add(r.point(lambda v: v & 0xF8), g.point(lambda v: v >> 5))
    lo = ImageChops.add(g.point(lambda v: (v << 3) & 0xE0), b.point(lambda v: v >> 3))
    return Image.merge("LA", (hi, lo)).tobytes()


class Pins:
    """Output pins through libgpiod 2 (current Raspberry Pi OS); falls back to RPi.GPIO if that's installed."""

    def __init__(self, pins, chip="/dev/gpiochip0"):
        pins = [p for p in pins if p is not None]
        try:
            import gpiod
            from gpiod.line import Direction, Value
            self._v = {0: Value.INACTIVE, 1: Value.ACTIVE}
            self._req = gpiod.request_lines(chip, consumer="pi-monitor",
                                            config={tuple(pins): gpiod.LineSettings(direction=Direction.OUTPUT)})
            self.set = lambda pin, v: self._req.set_value(pin, self._v[v])
        except ImportError:
            import RPi.GPIO as GPIO
            GPIO.setmode(GPIO.BCM)
            GPIO.setwarnings(False)
            for p in pins:
                GPIO.setup(p, GPIO.OUT)
            self.set = lambda pin, v: GPIO.output(pin, v)


class Button:
    """A HAT push button (closes to ground) read with the Pi's internal pull-up. pressed() is True once per press."""

    def __init__(self, pin, chip="/dev/gpiochip0"):
        try:
            import gpiod
            from gpiod.line import Bias, Direction, Value
            req = gpiod.request_lines(chip, consumer="pi-monitor-button", config={
                pin: gpiod.LineSettings(direction=Direction.INPUT, bias=Bias.PULL_UP)})
            self._down = lambda: req.get_value(pin) == Value.INACTIVE
        except ImportError:
            import RPi.GPIO as GPIO
            GPIO.setmode(GPIO.BCM)
            GPIO.setup(pin, GPIO.IN, pull_up_down=GPIO.PUD_UP)
            self._down = lambda: GPIO.input(pin) == 0
        self._was_down, self._since = False, 0.0

    def pressed(self):
        down, now = self._down(), time.monotonic()
        if down and not self._was_down and now - self._since > 0.3:   # 0.3 s debounce
            self._was_down, self._since = True, now
            return True
        if not down:
            self._was_down = False
        return False


class ILI9341:
    def __init__(self, dc, rst=None, bl=None, bus=0, device=0, hz=32_000_000, rotate=0):
        import spidev
        self.spi = spidev.SpiDev()
        self.spi.open(bus, device)
        self.spi.max_speed_hz = hz
        self.spi.mode = 0
        self.dc, self.rst, self.bl = dc, rst, bl
        self.pins = Pins([dc, rst, bl])
        self.chunk = 4096   # spidev's default buffer size
        if bl is not None:
            self.pins.set(bl, 1)
        self.reset()
        self.init(rotate)

    def cmd(self, c, *data):
        self.pins.set(self.dc, 0)
        self.spi.writebytes([c])
        if data:
            self.pins.set(self.dc, 1)
            self.spi.writebytes(list(data))

    def reset(self):
        if self.rst is not None:
            self.pins.set(self.rst, 1); time.sleep(0.01)
            self.pins.set(self.rst, 0); time.sleep(0.02)
            self.pins.set(self.rst, 1); time.sleep(0.15)
        else:
            self.cmd(0x01); time.sleep(0.15)   # software reset

    def init(self, rotate):
        # Standard ILI9341 power-up sequence (power, VCOM, gamma), 16-bit colour, landscape.
        self.cmd(0xCB, 0x39, 0x2C, 0x00, 0x34, 0x02)
        self.cmd(0xCF, 0x00, 0xC1, 0x30)
        self.cmd(0xE8, 0x85, 0x00, 0x78)
        self.cmd(0xEA, 0x00, 0x00)
        self.cmd(0xED, 0x64, 0x03, 0x12, 0x81)
        self.cmd(0xF7, 0x20)
        self.cmd(0xC0, 0x23)              # power control 1
        self.cmd(0xC1, 0x10)              # power control 2
        self.cmd(0xC5, 0x3E, 0x28)        # VCOM
        self.cmd(0xC7, 0x86)
        self.set_rotation(rotate)
        self.cmd(0x3A, 0x55)             # 16 bits per pixel
        self.cmd(0xB1, 0x00, 0x18)        # frame rate
        self.cmd(0xB6, 0x08, 0x82, 0x27)  # display function
        self.cmd(0xF2, 0x00)
        self.cmd(0x26, 0x01)              # gamma curve
        self.cmd(0xE0, 0x0F, 0x31, 0x2B, 0x0C, 0x0E, 0x08, 0x4E, 0xF1, 0x37, 0x07, 0x10, 0x03, 0x0E, 0x09, 0x00)
        self.cmd(0xE1, 0x00, 0x0E, 0x14, 0x03, 0x11, 0x07, 0x31, 0xC1, 0x48, 0x08, 0x0F, 0x0C, 0x31, 0x36, 0x0F)
        self.cmd(0x11); time.sleep(0.12)  # sleep out
        self.cmd(0x29)                    # display on

    def set_rotation(self, rotate):
        # Memory access control: landscape (MV) with BGR panel order; 180° flips both axes.
        self.cmd(0x36, {0: 0x28, 180: 0xE8}.get(rotate, 0x28))

    def show(self, img):
        buf = rgb565(img)
        self.cmd(0x2A, 0, 0, (W - 1) >> 8, (W - 1) & 0xFF)
        self.cmd(0x2B, 0, 0, (H - 1) >> 8, (H - 1) & 0xFF)
        self.cmd(0x2C)
        self.pins.set(self.dc, 1)
        for i in range(0, len(buf), self.chunk):
            self.spi.writebytes2(buf[i:i + self.chunk])


# ---------------------------------------------------------------- main loop

def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--url", default=os.environ.get("LOGGER_URL", "http://solar-pi.local"), help="solar logger address")
    ap.add_argument("--interval", type=int, default=15, help="seconds between updates")
    # Defaults suit the 2.8" HAT with K1-K3 buttons (DC on P3 = GPIO22, RESET on P2 = GPIO27).
    ap.add_argument("--dc", type=int, default=22, help="GPIO (BCM) for the screen's DC pin")
    ap.add_argument("--rst", type=int, default=27, help="GPIO for RESET, or -1 if not connected")
    ap.add_argument("--bl", type=int, default=-1, help="GPIO for the backlight (LED) pin, or -1 if wired to 3.3 V")
    ap.add_argument("--spi-hz", type=int, default=32_000_000, help="lower (e.g. 16000000) if the picture glitches")
    ap.add_argument("--rotate", type=int, choices=(0, 180), default=180,
                    help="0 or 180; 180 suits this HAT mounted with the buttons on the right")
    ap.add_argument("--rotate-button", type=int, default=23,
                    help="GPIO of the HAT button that flips the picture 180° (K1-K3 are GPIO18, 23, 24); -1 = none")
    ap.add_argument("--page-buttons", default="18,24",
                    help="GPIOs of the buttons that switch between the main and battery pages (K1, K3); '' = none")
    ap.add_argument("--page", type=int, choices=(0, 1), default=0, help="start page: 0 main, 1 battery")
    ap.add_argument("--font-dir", help="folder with DejaVuSans fonts (default: system fonts)")
    ap.add_argument("--png", help="save the screen image to this file instead of using the display")
    ap.add_argument("--once", action="store_true", help="draw one update and exit")
    args = ap.parse_args()

    fonts = load_fonts(args.font_dir)
    # The rotate button's choice is saved next to the script and wins over --rotate after a restart.
    state = os.path.join(os.path.dirname(os.path.abspath(__file__)), "rotate.txt")
    rotate = args.rotate
    try:
        with open(state) as f:
            rotate = 180 if f.read().strip() == "180" else 0
    except (OSError, ValueError):
        pass
    screen = None if args.png else ILI9341(args.dc, None if args.rst < 0 else args.rst,
                                           None if args.bl < 0 else args.bl, hz=args.spi_hz, rotate=rotate)
    button = None
    if screen and args.rotate_button >= 0:
        try:
            button = Button(args.rotate_button)
        except Exception as e:   # carry on without the button rather than stop the display
            print(f"Rotate button on GPIO{args.rotate_button} not available: {e}", flush=True)
    page_buttons = []
    for p in (int(x) for x in args.page_buttons.split(",") if x.strip()) if screen else ():
        try:
            page_buttons.append(Button(p))
        except Exception as e:
            print(f"Page button on GPIO{p} not available: {e}", flush=True)
    page = args.page
    last, updated, previous = None, 0, None
    while True:
        error = None
        try:
            last, updated = summarise(fetch(args.url)), time.time()
        except Exception as e:   # keep showing the last figures, greyed by the footer, until the logger is back
            error = str(getattr(e, "reason", e)) or type(e).__name__
        img = render(last, fonts, updated, error, page)
        if args.png:
            img.save(args.png)
        elif img.tobytes() != previous:   # skip the SPI transfer when nothing on screen changed
            screen.show(img)
            previous = img.tobytes()
        if args.once:
            return
        wake = time.time() + args.interval - time.time() % args.interval
        while time.time() < wake:   # watch the button between updates
            if button and button.pressed():
                rotate = 0 if rotate == 180 else 180
                screen.set_rotation(rotate)
                screen.show(img)
                print(f"Rotate button: picture now at {rotate}°", flush=True)
                try:
                    with open(state, "w") as f:
                        f.write(str(rotate))
                except OSError as e:
                    print(f"Couldn't save the rotation: {e}", flush=True)
            if any([b.pressed() for b in page_buttons]):   # list, so every button's state is read
                page = 1 - page
                img = render(last, fonts, updated, error, page)
                screen.show(img)
                previous = img.tobytes()
            time.sleep(0.05)


if __name__ == "__main__":
    main()
