# Pi monitor

A small always-on screen showing **solar now, house use, battery charge and grid** from the solar logger
(the sofarlogger Pi). One Python script fetches `http://<logger>/api.php?action=site` every 15 seconds and draws
on a 2.8" **ILI9341** SPI screen (320×240). It needs no desktop, browser or kernel display driver, so it runs
on Raspberry Pi OS **Lite**, even on a Pi Zero.

It only reads the logger's existing API and changes nothing on the logger.

## Screen layout

| Card | Value | Source |
|---|---|---|
| Solar | Power now, energy today | Logger site totals (all solar inverters) |
| House | House load | ME3000SP `load_w` |
| Battery | Charge %, charging/supplying/idle, bar (red at 20% or below) | Battery BMS; falls back to the ME3000SP's estimate, shown as "% est" |
| Grid | Importing (red) or exporting (green) | ME3000SP `grid_w` |

The footer shows the update time, any battery fault, or "No data" with the last good time if the logger
can't be reached (the last figures stay on screen).

## Fitting the screen

**This setup's screen is the plug-on "2.4/2.8inch RPi Display" HAT** (blue board, 26-pin socket, XPT2046 touch
controller, K1–K3 buttons on the side, panel marked HSD028009). It needs no wiring: push it fully onto the Pi's
GPIO header with the socket's 3.3 V/5 V corner on the Pi's pins 1 and 2 (the end nearest the SD card). On a
40-pin Pi the last 14 pins stay uncovered. A half-seated board gives a white screen and can crash the Pi.

Its pins, found by testing, are the script's defaults: LCD chip select CE0 (GPIO8), **DC on P3 (GPIO22)**,
**RESET on P2 (GPIO27)**, backlight always on. The board's P0–P7 labels are WiringPi numbers. GPIO18, 23 and 24
go to the K1–K3 buttons and the touch controller uses CE1 and GPIO17; none of those are used.

A white screen with a faint ripple means the backlight is on but the screen got no commands: check the
board is fully seated, then the DC/RESET pins.

## Wiring (if you use a loose 14-pin 2.8" ILI9341 module instead)

| Screen pin | Pi pin (physical) | GPIO (BCM) |
|---|---|---|
| VCC | 1 (3.3 V) | — |
| GND | 6 | — |
| CS | 24 | GPIO8 (CE0) |
| RESET | 22 | GPIO25 |
| DC (or D/C, RS) | 18 | GPIO24 |
| SDI (MOSI) | 19 | GPIO10 |
| SCK | 23 | GPIO11 |
| LED (backlight) | 17 (3.3 V) | — (or a GPIO, then pass `--bl <gpio>`) |
| SDO (MISO) | not needed | — |
| T_* (touch) | not needed | — |

If your screen is a plug-on HAT rather than a loose module, check its DC/RESET/backlight GPIOs and pass them
with `--dc`, `--rst` (`-1` if none) and `--bl`.

## Set up the Pi

1. Flash **Raspberry Pi OS Lite** with Raspberry Pi Imager. In its settings, set the hostname, a user,
   Wi-Fi and enable SSH. For example hostname `solmonitor` and user
   `monitor`; the service file's `User=` must match your user.
2. Enable SPI and install the packages:

   ```
   sudo raspi-config nonint do_spi 0
   sudo apt update
   sudo apt install -y python3-pil python3-spidev python3-libgpiod fonts-dejavu-core
   ```

3. Copy the files and try it:

   ```
   sudo mkdir -p /opt/pi-monitor
   sudo cp monitor.py /opt/pi-monitor/
   python3 /opt/pi-monitor/monitor.py --url http://LOGGER-IP --once
   ```

   The screen should show the figures. If it stays white, check DC/RESET wiring; if the picture is garbled,
   add `--spi-hz 16000000`; if it's upside down, add `--rotate 180`.

4. Start it at boot:

   ```
   sudo cp pi-monitor.service /etc/systemd/system/
   sudo systemctl enable --now pi-monitor
   journalctl -u pi-monitor -f      # watch it
   ```

   Edit `ExecStart` in the service file if you need a different logger address or pin options, then
   `sudo systemctl daemon-reload && sudo systemctl restart pi-monitor`.

## Case

Also on Thingiverse: https://www.thingiverse.com/thing:7433627

`case/pi3-28hat-case.scad` is a two-part printable case for the Pi 3B with this HAT: a base tray (Pi on four
standoffs, openings for power, HDMI, audio, Ethernet, USB and the SD card, floor vents) and a lid with a
window for the screen and holes for K1–K3. The lid's skirt slips over a stepped rim on the base.

The Pi 3B dimensions are official. The HAT values are **estimates from photos**: measure these and edit the
`EST` values at the top of the file before printing.

| Setting | Measure |
|---|---|
| `hat_gap` | Top of the Pi board to the underside of the HAT board |
| `lcd_h` | Top of the HAT board to the top of the screen glass |
| `view_x0`, `view_y0` | From the Pi board's SD-card end and power/HDMI edge to the edges of the lit screen area |
| `btn_x`, `btn_y` | Centres of K1, K2, K3, measured the same way |
| `btn_h` | Height of the buttons above the HAT board |

Render: open the file in OpenSCAD (free, openscad.org), set `part` to `"base"` or `"lid"`, press F6, then
File > Export > STL. `part = "assembled"` shows the lid in place, to check the fit. Print both flat side
down (the lid is already modelled upside down), PLA or PETG, 0.2 mm layers, no supports needed. Fix the Pi
with four M2.5 × 6 mm self-tapping screws into the standoffs.

## Preview without the screen

On any computer with Python and Pillow:

```
python monitor.py --url http://LOGGER-IP --png preview.png --once
```

## Options

| Option | Default | Meaning |
|---|---|---|
| `--url` | `http://LOGGER-IP` (set yours with `--url` or `LOGGER_URL`) | Logger address |
| `--interval` | 15 | Seconds between updates |
| `--dc` / `--rst` / `--bl` | 22 / 27 / -1 | Screen GPIOs (BCM); -1 = not connected |
| `--spi-hz` | 32000000 | SPI speed; lower it if the picture glitches |
| `--rotate` | 180 | 0 or 180 (180 is the right way up for this HAT) |
| `--rotate-button` | 23 | GPIO of the HAT button that flips the picture 180° (K1–K3 are GPIO18, 23, 24); -1 = none. The choice is saved in `rotate.txt` beside the script and overrides `--rotate` |
| `--page-buttons` | 18,24 | GPIOs of the buttons (K1, K3) that switch between the main and battery pages |
| `--page` | 0 | Start page: 0 main, 1 battery |
| `--png FILE` | — | Save the image instead of using the screen |
| `--once` | — | One update, then exit |
