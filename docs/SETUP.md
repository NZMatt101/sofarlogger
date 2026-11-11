# Setting up sofarlogger

About 30 minutes for the Pi, plus wiring if you have an ME3000SP.

## You need

- Raspberry Pi 3B or newer, a proper 5 V 2.5 A (or better) power supply, and an **8 GB or larger
  high-endurance** microSD card
- Your Wi-Fi name and password, or an Ethernet cable to the router
- For solar inverters: the Wi-Fi logger stick already on your Wi-Fi, and its **IP address** and **serial
  number** (both on the stick's status web page)
- For an ME3000SP or battery BMS: a USB to RS485 adapter for each (see [hardware.md](hardware.md))

## 1. Prepare the SD card

Raspberry Pi Imager → **Raspberry Pi OS Lite (64-bit)** → edit settings:

- Hostname: for example `solar-pi`
- Username and password of your choice (it's also the `sudo` password)
- Wi-Fi, country and time zone
- Services: enable SSH

Boot the Pi and find its address (your router's device list, or `ping solar-pi.local`).

## 2. Install

SSH to the Pi (`ssh USER@solar-pi.local`), then:

```
sudo apt install -y git
git clone https://github.com/NZMatt101/sofarlogger.git
cd sofarlogger && sudo ./install.sh
```

Note the **NEW ADMIN LOGIN** it prints. Re-running the installer is safe: it never overwrites the config, the
readings or the admin login.

## 3. Configure (Admin page: `http://PI-ADDRESS/admin/admin.php`)

1. **Site:** name, latitude and longitude (used for sunrise and sunset).
2. **Equipment:**
   - **Add inverter:** name, model, rated watts, the logger stick's IP and serial. Readings appear within a
     minute or two.
   - **Add battery inverter (SOFAR ME3000SP):** the RS485 adapter's port. Use the `/dev/serial/by-path/...` name from the list: two
     identical adapters share one `by-id` name, and `/dev/ttyUSB0`/`1` can swap after a reboot.
   - **Add battery BMS:** the second adapter's port, the same way.
3. **Logger web login:** inverter logger sticks ship with `admin/admin`; change it here.
4. **Electricity prices** for the Savings page, and panel details for the Going Green page.
5. **Admin login:** change the password.

## 4. Finish

- In your router, **reserve fixed IP addresses** for the Pi and each inverter logger stick (the Pi reads the
  sticks by IP).
- Bookmark `http://PI-ADDRESS/` on your devices.
- Admin → Backup: take a full backup now and then, kept off the Pi, or turn on nightly Dropbox backups.

Show the owner the [user guide](user-guide.md): it explains every screen.

## Optional: public Going Green figures

`deploy/optional/setup-funnel.sh` publishes **only** the Going Green figures (`api.php?action=green`) at a
public `https://<name>.ts.net` address through Tailscale Funnel, with no router port forwarding. The dashboard
and admin page stay private. Read the script before running it.

## Troubleshooting

- `journalctl -u solar-poller -n 50` shows each poll and any errors.
- Admin → System shows the poller status and the last good reading for each device.
- A device shows offline: check its IP (inverter) or port (RS485) in Admin → Equipment.
