# Hardware and wiring

## Solar inverters (Wi-Fi logger stick)

SOFAR TL/TLM inverters with an IGEN / "omnik"-style Wi-Fi logger stick. The Pi polls the stick on TCP port
8899 using its serial number, so the stick must be on the same network and should have a fixed IP address
(reserve it in your router). `tools/omnik_probe.py` reads a stick once from the command line, for testing.

## RS485 basics

- RS485 is **not** crossed: A goes to A (also called D+, 485+), B goes to B (D-, 485-).
- A plain USB to RS485 adapter (CH340 or FTDI) is fine. Many have no GND terminal; two wires work over the
  short runs here.
- 9600 baud, 8 data bits, no parity, 1 stop bit, for both the ME3000SP and the BMS.
- Use one adapter per device. Two identical CH340 adapters share one `/dev/serial/by-id/` name, so pick
  the port in Admin by its `/dev/serial/by-path/...` name, which is fixed by the USB socket it's plugged
  into. Don't move the adapters between sockets afterwards.

## SOFAR ME3000SP

- RS485 terminals on the inverter's communication port (see the ME3000SP manual for your model's pin-out);
  Modbus RTU, slave address 1.
- Reads use Modbus functions 3 and 4 only. Writes use SOFAR's passive-mode function `0x42` (standby, auto,
  charge, discharge) and are only sent from the battery page or by Pi control.
- **Passive commands need the inverter in Passive mode:** on the inverter's screen, Work Mode Set → Set
  Passive Mode. In any other mode the inverter rejects them with "wrong work mode".
- Scaling found on software V300: battery voltage reads in 0.01 V, not 0.1 V as SOFAR's document says.
  Check other readings against the inverter's screen.
- The `state` register (`0x0200`) is the running state (Standby, Charging...), not the work mode.
- Alert register `0x022B` bit `0x0080` is the battery low-voltage alarm (ID85). While it's set, Auto mode
  charges from surplus but won't discharge. It can stay set long after the battery recovers.

## Battery BMS (JBD protocol)

Tested with a 16-cell 280 Ah LiFePO4 rack battery whose BMS speaks the JBD ("Smart BMS", `DD A5 ... 77`)
protocol on its RS485 port.

- On that battery's RS485 socket (RJ45): **pin 2 = A** to the adapter's D+, **pin 1 = B** to D-. Check your
  battery's manual: pin-outs differ between brands.
- The BMS answers JBD basic info (`DD A5 03 00 FF FD 77`) and cell voltages (`DD A5 04 00 FF FC 77`).
  sofarlogger only reads; it never writes to the BMS.
- Readings: charge %, remaining and full Ah, pack voltage and current, each cell's voltage, temperatures,
  charge/discharge switch states, balancing, faults and cycle count.

## Inverter to battery CAN link

Not used by sofarlogger. On our test system the ME3000SP and the battery never agreed over CAN, which is why
the Pi reads the BMS itself. If yours do talk over CAN, you can still use sofarlogger for monitoring and leave
battery control to the inverter.
