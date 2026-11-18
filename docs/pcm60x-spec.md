# PCM60X solar charge controller — specification (not built)

Status, 2026-11-18: **monitoring is built** and staged on the Pi, not yet installed. That covers the `pcm60x` driver
(read-only), "Add PV charge controller" in Admin, and its settings shown on its device page, read hourly. Not built
yet: comparing settings with the profile, settings SMS alerts, and writes (phase 2).

## Purpose

An MPP Solar PCM60X (MPPT, 60 A, 48 V) charges the battery directly from a separate PV array. It doesn't feed the
grid. The ME3000SP delivers that energy to the house as ordinary battery discharge. sofarlogger should:

1. **Log** the charger: PV volts and watts, charge current, battery volts, temperature, charging or idle, warnings.
2. **Watch its settings** and send an SMS if any of them drift from a lithium-safe profile. Above all, it must
   alert if equalisation is turned on, because an equalise run would push a LiFePO4 pack to over 58 V.
3. **Optionally correct** a drifted setting. This is off by default and limited to a short list of commands.

The PCM60X doesn't take part in Pi battery control. The BMS remains the final protection.

## Hardware

- Planned array: 2S2P of 460 W bifacial N-type panels (1762 × 1134 mm), about 1.84 kWp. **Electrical figures
  not yet confirmed**; the retailer's page couldn't be read. Similar 460 W panels this size give Voc 35–40 V,
  Vmp about 33 V, Isc 14.6–16 A. Then 2S is about 79 V open-circuit (about 85 V cold) and about 66 V at maximum
  power, falling to about 58 V on a hot day: only a few volts above the 55.2 V absorption, so check the PCM60X's
  minimum PV-above-battery margin. 3S is about 128 V cold, which is under the PCM's 145 V but over 120 V DC
  (no longer extra-low voltage). The charge current peaks at about 33 A.
- The PCM connects to the battery or busbar through its own DC breaker or fuse, not through the ME3000SP's breaker.
- **Comms:** the PCM's RJ45 RS232 port, using the RJ45-to-DB9 cable supplied with the PCM60X, into a **USB-RS232**
  adapter (true RS232 levels, e.g. PL2303 or FTDI). The existing CH340 adapters are RS485 and won't work.
  Reference the adapter by its USB port position (`/dev/serial/by-path/...`), as for the BMS.
- **2400 baud, 8N1.**

## Protocol

Source: the MPP Solar document "MPPT-3000 Standard RS232 Communication Protocol" (protocol ID PI34), as included
in github.com/solarsnoop/PCM60X-Monitor. That repo is Unlicensed (public domain), but we will write our own
code in the existing driver style. It needs stdlib termios only, no pyserial.

### Framing

- Request: `ASCII command` + `CRC (2 bytes, big-endian)` + `\r`.
- CRC: CRC-16/XModem (polynomial 0x1021, initial value 0) over the command bytes. **If either CRC byte is 0x0A,
  0x0D or 0x28, add 1 to it**, because those values would clash with the line feed, carriage return and `(`
  start byte.
- Reply: `(` + ASCII fields separated by spaces + CRC + `\r`. Setting commands reply `(ACK` or `(NAK`.
- Check the reply CRC using the same adjustment rule. On a bad CRC or a short reply, retry once, as the BMS
  driver does.
- At 2400 baud a 70-byte reply takes about 0.3 s. Use a 2 s timeout.
- Test values, checked against the frames in that repo: `QPIGS` → `B7 A9`, `QPIRI` → `F8 54`,
  `PBFV13.50` → `B4 93`, `PBT02` → `07 4C`.

### Voltages are per 12 V unit

Every voltage setting and every QPIRI voltage is given **per 12 V unit**. On a 48 V system, multiply by 4.
For example, 13.80 means 55.2 V. QPIGS battery volts are real pack volts.

### Read commands used

| Command | When | Gives |
|---|---|---|
| `QPI`, `QID`, `QVFW` | once per connect | protocol ID (expect `PI34`), serial number, firmware version |
| `QPIGS` | every poll | PV V, battery V, charge A (total, phase 1, phase 2), charge W, unit °C, remote battery V/°C, status bits (b7 = parameters changed from defaults, b6 = charging) |
| `QPIWS` | every poll | warning and fault bits (a1–a7 faults, a20–a23, a30 battery low) |
| `QPIRI` | at start, then hourly | rated W, nominal battery V, max charge A, absorption and float voltages (per unit), battery type, remote sensing, temperature compensation, rated-voltage setting, low warning V, low-shutdown flag |
| `QBEQI` | at start, then hourly | equalisation **(0 = off, 1 = on, tested 2026-11-18; the document wrongly says 0 = enabled)**, EQ time, interval, current, days to next EQ, EQ voltage, CV (absorption) time, EQ timeout |

Sample replies from that repo, from a 24 V system:
`(102.1 27.86 01.53 01.33 00.20 0042 +033 27.96 -030 0000 01000000` (QPIGS) and
`(3000 24 60.0 14.60 13.80 02 01 +00.0 00 00 2 11.50 0` (QPIRI). Both parsers are tested against these.

### Night-time

The PCM60X may stop answering when there's no PV; that repo says its monitor only works while the display is on.
No reply between sunset and sunrise is **"asleep"**, not a fault. Use the same daylight rule as the 4000TLM:
the notifier alerts only after 15 minutes of no reply in daylight.

## Target settings profile (16S LiFePO4, 280 Ah)

These are stored per device as `pcm_profile` in config.json and can be edited in Admin. All values are 48 V
figures; the driver divides by 4 when sending.

| Setting | Target | Command | Why |
|---|---|---|---|
| Rated battery voltage | 48 V (fixed, not auto-sense) | `PBRV04` | Auto-sensing can misread a lithium pack |
| Battery type | Customized | `PBT02` | AGM is 56.4 V; Flooded is 58.4 V, which is the battery's maximum |
| Absorption (bulk) | **55.2 V** (13.80) | `PBAV13.80` | 3.45 V per cell; under the ME3000SP's 56.0 V max charge, so the two don't fight |
| Float | **53.6 V** (13.40) | `PBFV13.40` | Keeps the pack topped up without holding it at 100% |
| Equalisation | **Disabled** | `PBEQE0` | A LiFePO4 pack must never be equalised |
| EQ interval | 0 (manual only) | `PBEQP000` | Second guard against a scheduled equalise |
| CV / absorption time | 30 min | `PBCVT030` | Lithium needs only a short absorption |
| Max charge current | 60 A | `MCHGC060` | The array only gives about 33 A; total with the ME3000SP's 65 A stays under about 100 A |
| Temperature compensation | 0.0 mV | (check only) | Lithium must not be temperature-compensated. The command's character is unclear in the PDF (`PTSO±`?), so don't write it |
| Remote battery V / temperature sensing | off unless the cables are fitted | (check only) | |
| Low-warning V / low-shutdown | leave as found and record | (check only) | It isn't clear what these do on a charger with no load output |

The Admin page rejects profile values outside safe limits: absorption 54.0–56.0 V, float 53.0–55.0 V, and float
at least 0.4 V below absorption.

## Settings check (phase 1)

- Read `QPIRI` and `QBEQI` at start and every hour, and compare them with the profile. Allow ±0.02 per unit for
  voltages.
- A mismatch counts only if it shows on **two reads in a row**, to filter out one bad frame.
- Store the last good settings read in `device_snapshot.settings`, like the ME3000SP. The device page shows
  current and target side by side, with mismatches highlighted.
- **SMS** through the existing notifier: "Solar Logger: PCM60X setting changed: equalisation ON (expected OFF)".
  - Equalisation enabled, battery type Flooded or AGM, or absorption above 56.0 V: alert straight away.
  - Any other mismatch: alert once a day.
  - Send a "back to normal" message when everything matches again.
- **Watch for an equalise in progress:** battery V above 56.5 V while charging, or days-to-next-EQ = 0 while EQ
  is enabled. Either one sends an urgent SMS.

## Correction (phase 2, off by default)

- **Allowed writes:** `PBRV04`, `PBT02`, `PBAV`, `PBFV`, `PBEQE0`, `PBEQP000`, `PBCVT`, `MCHGC`. The command
  builder refuses everything else.
- **Never sent:** `PF` (factory reset: AGM, auto-sense, 14.10/13.50), `ID`, `PBEQE1`, `PBEQV`, `PTSO`, `PBLV`,
  `PBLSE`, `PRBD`.
- **Two ways in:**
  1. **"Apply profile now"** button on the device page. Admin password, shows a diff, needs confirmation. This is
     how the profile is first set up, with the user watching the PCM display.
  2. **"Keep settings correct"** switch, off by default. It only acts on a mismatch confirmed by two reads.
- **Order:** `PBRV04` then `PBT02` first, because PBAV and PBFV only take effect on the Customized type. Then
  absorption, then float, then the EQ settings, then the current.
- Each write must get `(ACK`, followed by a fresh `QPIRI` or `QBEQI` read-back. On `(NAK`, a wrong read-back or
  no reply: stop, send an SMS, and don't retry that setting for 24 hours.
- **Limits on writes:** at most one write per setting per 24 hours, and 10 writes per day in total. This avoids
  wearing out the settings memory, and a fault can't cause a rewrite loop.
- Log every write, with its reply and read-back, in a `pcm_actions` table.

## Logged fields

| Key | Label | Unit |
|---|---|---|
| `pv_w` | Solar power (charge W) | W |
| `pv_v` | PV voltage | V |
| `charge_a` | Charge current | A |
| `charge_a1`, `charge_a2` | Charge current, phase 1 / phase 2 | A |
| `batt_v` | Battery voltage (at PCM) | V |
| `temp_c` | Charger temperature | °C |
| `charging` | Charging (status bit 6) | Yes/No |
| `params_modified` | Settings differ from factory (status bit 7) | Yes/No |
| `warnings` | Active warnings and faults (QPIWS) | text |
| `energy_today_kwh` | Charge energy today (integrated by the Pi; the PCM has no energy counter) | kWh |

Device: `driver: "pcm60x"`, `type: "charger"` (new type), id prefix `chg-`. It gets an Admin "Add PV charger" form
like the BMS one, with a by-path port picker. It shows as an overview card ("DC solar") on the overview.

## Energy accounting

- DC solar is **generation**. Add `chg-*` energy to the "solar generated" figures, on the Going Green page and in
  savings.
- **House consumption doesn't change.** That energy reaches the house as ME3000SP battery discharge, which is
  already counted. Don't count it twice.
- The Going Green public endpoint gets a separate DC solar figure; nothing new is exposed.

## Pi battery control

No changes in phase 1. Pi control already reads the BMS, which sees the PCM's charging, so the floor, ceiling and
cell limits still apply. The ceiling hold won't stop the PCM. The PCM simply reaches 55.2 V and then floats,
which is within the battery's limits.

**Not planned:** using `MCHGC` to slow the PCM as the battery fills. That writes to the settings memory every
time, so it's unsuitable for frequent use.

## Tests (offline, before staging)

- CRC: the four test values above, plus the 0x0A/0x0D/0x28 adjustment (find a command whose CRC hits one of them).
- Parsers: the repo's QPIGS and QPIRI samples, a 48 V QPIRI, QBEQI with both enable values, and QPIWS bit mapping.
- Settings comparison: unit conversion, tolerance, two-reads-in-a-row rule, urgent versus daily alerts.
- Write guard: refused commands, write order, NAK handling, per-day limits, read-back mismatch.
- Night: no reply in darkness gives "asleep" and no alert.

## Open questions

1. Does the PCM60X answer at night when it's powered from the battery? This decides whether "asleep" is a
   normal state. 2026-11-18: with a PL2303 adapter it answers when switched on with no PV connected (earlier
   silence was before it was switched on). The night behaviour is still untested.
   First read: protocol PI34, firmware 00003.01. QPIRI gave 48 V, 60 A, absorption 14.10 (56.4 V),
   float 13.50 (54.0 V), type 00 AGM, rated voltage 00 (auto-sense), low warning 11.50 (46.0 V), low-shutdown 0.
   QBEQI gave `0 060 030 015 030 14.60 150 120`. 16:31 the same day: the profile was applied with read-back
   (PBRV04, PBT02, PBAV13.80, PBFV13.40, PBEQE0, PBCVT030), all ACKed and matching. After PBEQE0 the flag still
   read 0, so **0 = off** and equalisation was never on. Still to check: whether the settings survive a power-off.
   QPIWS shows only a20 (PV low loss), which is the "20" on the display.
2. Pinout of the supplied RJ45 cable. Check with a multimeter, or use the cable as supplied.
3. Are the settings stored permanently, and kept through a power-off? Test once by switching off the PCM's
   battery breaker and reading back.
4. What `PBLSE` (low-shutdown detect) and `PBLV` actually do on the PCM60X.
5. Does the panel ever start an equalise by itself when `PBEQP000` is set? The document says "only by key".
6. Use the `QPIGS` "parameters modified" bit for anything, or just display it?
