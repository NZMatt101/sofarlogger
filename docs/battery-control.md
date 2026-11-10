# Battery control

sofarlogger can control an ME3000SP in two ways, both from **Admin → Equipment → the ME3000SP's battery page**
(`admin/battery.php`, admin password required):

1. **Manual commands:** standby, auto, or a timed charge or discharge.
2. **Pi control:** the Pi decides automatically from the battery's BMS readings.

**Both are off until you use them.** A new install only reads. You use this at your own risk: test at low power
while watching the inverter, and keep the admin page off the internet.

## Why Pi control?

Without a working CAN link to the battery, the ME3000SP guesses the charge level from voltage, which can be
tens of percent out. In Auto mode it then stops discharging too early (or too late), or latches its low-voltage
alarm and stops discharging until it's restarted. Pi control uses the BMS's own charge % and cell voltages
instead.

## How Pi control works

The inverter must be in **Passive mode** (on its screen: Work Mode Set → Set Passive Mode). Every 15 seconds the
Pi reads the inverter and the BMS and chooses one of two passive commands:

- **Auto:** the inverter follows the house load by itself: surplus solar charges the battery, a shortfall is
  supplied from it.
- **Standby:** the battery neither charges nor discharges.

The Pi never sets a charge or discharge power itself.

| Situation | Pi chooses |
|---|---|
| Charge between the floor and the ceiling | Auto |
| At or below the **floor** (default 20%), or any cell at or below 2.90 V | Auto only while there's spare solar (so it can charge); otherwise Standby |
| At or above the **ceiling** (default 95%), or any cell at or above 3.55 V | Auto only while the house needs more than the solar (so it can discharge); otherwise Standby, and spare solar is exported |
| Full charge due | The ceiling becomes 100% until the BMS reports 100% |
| No BMS reading for 3 minutes, a BMS fault, or both BMS switches off | Standby straight away |

- **Full charge:** every 7 days by default, or sooner if the cells drift more than 30 mV apart, so the BMS can
  balance them and recalibrate its %.
- **Hysteresis:** once held at the floor or ceiling, it waits until the charge is 3% back inside before
  returning to normal, so it doesn't flicker.
- **Rate limits:** it switches at most once a minute (except for safety), and repeats the current command every
  5 minutes in case the inverter restarted.
- If the inverter refuses because it isn't in Passive mode, or stops answering, the battery page shows the
  problem.

## Time-of-use schedule

On the battery page, **Time-of-use** (`admin/tou.php`) holds up to 10 slots, so you can charge from the grid when
power is cheap. It only runs under Pi control. Each slot has days, a start and end time (it can run past
midnight; its days are the days it starts on), and one of two actions:

| Action | During the slot |
|---|---|
| **Charge from grid** | Charges at the set power (100–3,000 W), from the grid if there isn't enough solar, until the BMS reaches the slot's *charge to* %. It then holds the battery (no discharging) for the rest of the slot, and tops up again if it falls 3% below. |
| **Keep battery** | No discharging, so the charge is saved for an expensive period later; spare solar can still charge it. |

- Outside the slots, normal Pi control applies. If slots overlap, the lower-numbered one wins.
- Charging stops at once if the BMS reading goes missing or it reports a protection, a cell reaches 3.55 V, the
  pack reaches the battery page's "stop charging" voltage, or the inverter reports a fault.
- The power is the inverter's AC power. The battery gets about 4% less after conversion: tested at 3000 W, the
  ME3000SP drew 3040 W and the battery took 2910 W. Grid import = charge power + house load − solar (the
  ME3000SP is AC-coupled, so it can't tell solar from grid; solar just reduces the import).
- Grid charging adds to the house load on your main fuse: allow for that when choosing the power.
- Slot starts and ends are recorded in the battery page's control events.
- Times are the Pi's local time, shown at the top of the page.

## Turning it on

1. Add the ME3000SP and the battery BMS in Admin → Equipment, and check the readings look right.
2. On the inverter's screen, set Work Mode to **Passive**.
3. On the battery page, under **Who controls the battery**, choose **Pi**, choose the BMS, and check the floor,
   ceiling, full-charge interval and cell spread.
4. Watch the status line and the inverter for a while: it should show what the Pi chose and why.

## Turning it off

Choose **Inverter** under Who controls the battery. The Pi sends a final passive Auto, then stops. **Then set
Work Mode back to Auto on the inverter's screen**, or it stays in Passive mode with nobody controlling it.

## Limits that stay in force

- The inverter's own voltage settings (minimum discharge voltage, maximum charge voltage) still apply and win
  over the Pi.
- The BMS still disconnects the battery on any cell or temperature fault.
- Manual timed runs are limited to 3,000 W and 240 minutes, stop at the page's voltage limits or on any fault,
  and take priority over Pi control while they run.
- Not yet known: exactly what the ME3000SP does if the Pi stops sending commands altogether. SOFAR's passive
  mode guide suggests it goes to standby.
