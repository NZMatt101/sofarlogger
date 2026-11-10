# sofarlogger

A Raspberry Pi logger and battery controller for SOFAR solar inverters and the **SOFAR ME3000SP** battery
inverter. It reads your equipment every minute, keeps the readings on the Pi and shows a dashboard on your
home network. Optionally the Pi can manage the ME3000SP's charging and discharging from the battery's own
BMS readings, instead of the inverter's built-in Auto mode.

Nothing is sent to the internet unless you turn it on.

## What it does

- **Dashboard:** solar power now, today, daily and monthly charts per inverter, house use, grid import and
  export, battery charge and power.
- **Savings and Going Green pages:** what the system has saved at your electricity prices, and the carbon
  avoided.
- **ME3000SP over RS485:** live readings, battery settings, and manual commands (standby, auto, timed charge
  or discharge) with safety limits.
- **Battery BMS over RS485 (JBD / "Smart BMS"):** true charge %, all cell voltages, temperatures, balancing
  and faults. The inverter's own % can be badly wrong when it isn't talking to the BMS.
- **Pi battery control (optional, off by default):** keeps the battery between a floor and a ceiling you set
  (for example 20% and 95%), with a full charge every few days to rebalance the cells. See
  [docs/battery-control.md](docs/battery-control.md).
- **Admin page** (password protected): equipment, prices, history import, backups to Dropbox.
- **Reliability:** watchdogs and network recovery for unattended running.
- **Extras:** [a small always-on screen](extras/pi-monitor/) on a second Pi, with a printable case.

## Supported equipment

| Equipment | Connection | Notes |
|---|---|---|
| SOFAR TL/TLM solar inverters | Wi-Fi logger stick (IGEN / "omnik" style) | Read over the home network by IP and serial |
| SOFAR ME3000SP | USB to RS485 adapter on the Pi | Tested on software V300 |
| Battery BMS (JBD protocol) | Second USB to RS485 adapter | Tested on a 16-cell 280 Ah LiFePO4 pack |

Other equipment needs a new driver in `collector/poller.py`. Wiring and adapter notes:
[docs/hardware.md](docs/hardware.md).

## Install

You need a Raspberry Pi 3B or newer with **Raspberry Pi OS Lite** and an 8 GB or larger high-endurance SD
card. On the Pi:

```
sudo apt install -y git
git clone https://github.com/NZMatt101/sofarlogger.git
cd sofarlogger && sudo ./install.sh
```

Note the **NEW ADMIN LOGIN** it prints, then open `http://<pi-address>/admin/admin.php` and add your
equipment. Full steps: [docs/SETUP.md](docs/SETUP.md).

**Update:** `cd sofarlogger && git pull && sudo ./install.sh`. Your settings, readings and login are kept.

Or download a fixed version from the [Releases](../../releases) page and run `sudo ./install.sh` from the
unpacked folder.

## Safety

This software can send commands to a battery inverter. **You use it at your own risk.**

- Monitoring is read-only. Battery commands are only sent when you press a button on the battery page or
  turn on Pi control.
- Pi control is off in a new install. Turn it on only after reading
  [docs/battery-control.md](docs/battery-control.md), and test it at low power while you watch the inverter.
- The inverter's own voltage limits stay in force as the backstop, and the BMS still protects the cells.
- Keep the admin page on your home network. Never expose it to the internet.

## Limitations

- Times and daily totals use New Zealand time (Pacific/Auckland).
- Tested on one site. Your inverter firmware or BMS may differ: check the readings against the inverter's
  screen before relying on them.

## Licence

MIT, see [LICENSE](LICENSE). No warranty.

The ME3000SP protocol was worked out from SOFAR's published Modbus documents; no third-party code is
included apart from [Chart.js](https://www.chartjs.org/) (MIT) in `web/chart.umd.min.js`.
