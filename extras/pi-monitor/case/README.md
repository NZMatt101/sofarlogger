# Raspberry Pi 3B case for a 2.8" ILI9341 display HAT (solar monitor)

On Thingiverse: https://www.thingiverse.com/thing:7433627

A two-part case for a **Raspberry Pi 3 Model B** with a plug-on **2.8" ILI9341 SPI display HAT** (the common
"2.4/2.8inch RPi Display" board with XPT2046 touch and three side buttons, K1–K3). I made it for a small
always-on screen that shows a home solar and battery system at a glance, but it suits any project using this
Pi and HAT.

## The project

The screen is part of **sofarlogger**, an open-source Raspberry Pi logger and battery controller for SOFAR
solar inverters and the SOFAR ME3000SP battery inverter:

**https://github.com/NZMatt101/sofarlogger**

The display Pi (in `extras/pi-monitor`) reads the logger over the home network every 15 seconds and shows:

- **Main page:** solar power now and today, house load, battery charge and power, and grid import or export.
- **Battery page:** charge %, energy stored, all 16 cell voltages (lowest and highest highlighted), cell
  spread, temperatures, and the BMS status.
- **Buttons:** K1 or K3 switches between the pages; K2 flips the picture 180° so the case can sit either way up.

It's one Python script driving the screen directly over SPI: no desktop or display driver needed, so it runs on
Raspberry Pi OS Lite.

## The case

- **Base tray:** holds the Pi on four standoffs, with openings for the micro-USB power, HDMI, audio, Ethernet,
  both USB stacks and the micro-SD card, plus floor vents.
- **Lid:** a window for the screen (bevelled so the edges don't shade the picture), holes for the K1–K3
  buttons, and side vents. Its skirt fits over a stepped rim on the base.
- **Outside size:** about 90 × 61 × 31 mm.

## Files

| File | What it is |
|---|---|
| `pi3-28hat-case-base.stl` | Base tray, print as is |
| `pi3-28hat-case-lid.stl` | Lid, already upside down for printing |
| `pi3-28hat-case.scad` | OpenSCAD source: every dimension is a parameter at the top |

## Printing

- PLA or PETG, 0.2 mm layers, 15–20% infill, 2–3 walls.
- No supports needed: print the base as is and the lid flat on its top face.
- Hardware: four **M2.5 × 6 mm self-tapping screws** to hold the Pi to the standoffs.

## Check before printing

The Pi 3B dimensions come from the official mechanical drawing. The **HAT's measurements are estimates from
photos**, and boards from different sellers vary: the height of the screen above the Pi, the position of the
lit screen area, and the button positions. Print the lid on its own first and check the fit, or measure your
board and change these values at the top of the `.scad` file:

| Setting | Measure |
|---|---|
| `hat_gap` | Top of the Pi board to the underside of the HAT board |
| `lcd_h` | Top of the HAT board to the top of the screen glass |
| `view_x0`, `view_y0` | From the Pi board's SD-card end and power/HDMI edge to the edges of the lit screen area |
| `btn_x`, `btn_y` | Centres of K1, K2 and K3, measured the same way |
| `btn_h` | Height of the buttons above the HAT board |

Then open the file in OpenSCAD (free, openscad.org), set `part` to `"base"` or `"lid"`, render (F6) and export
the STL. `part = "assembled"` shows the lid in place, to check the fit on screen.

## Assembly

1. Fit the micro-SD card first (the slot is reachable through the base, but it's easier before the lid goes on).
2. Screw the Pi onto the standoffs.
3. Push the HAT fully onto the GPIO header, with its socket on pins 1–26 (the SD-card end). A half-seated HAT
   gives a white screen.
4. Press the lid down over the base rim.
5. Use a proper 5 V 2.5 A power supply: a weak one gives undervoltage warnings with the screen fitted.

Software setup and wiring: see the GitHub repo above.
