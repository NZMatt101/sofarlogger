#!/usr/bin/env bash
# Install or update sofarlogger on a Raspberry Pi running Raspberry Pi OS Lite.
#
#     git clone https://github.com/NZMatt101/sofarlogger.git
#     cd sofarlogger && sudo ./install.sh
#
# Update later with:  git pull && sudo ./install.sh
# Safe to re-run: it never overwrites an existing config, database or admin login.
# A new install starts with no equipment and with battery control left to the inverter.
set -euo pipefail
SRC=$(cd "$(dirname "$0")" && pwd)
export SRC
[ "$(id -u)" -eq 0 ] || { echo "Run with sudo: sudo ./install.sh" >&2; exit 1; }
[ -f "$SRC/collector/poller.py" ] || { echo "Run this from the sofarlogger folder." >&2; exit 1; }

echo "== 1/4 Packages (Apache, PHP, SQLite) - several minutes on a Pi 3"
export DEBIAN_FRONTEND=noninteractive
apt-get update -q
# Show progress lines only; with pipefail an apt failure still stops the script.
apt-get install -y -q -o Dpkg::Progress-Fancy=0 apache2 php libapache2-mod-php php-sqlite3 sqlite3 openssl \
    | { grep --line-buffered -E '^(Get:|Setting up|Unpacking)' | sed -u 's/^/   /' || true; }

echo "== 2/4 Time zone"
# The dashboard and daily totals currently use New Zealand time (Pacific/Auckland) throughout.
timedatectl set-timezone Pacific/Auckland
timedatectl set-ntp true

echo "== 3/4 Logger app, web UI and admin page"
bash "$SRC/deploy/install-app.sh"

echo "== 4/4 Reliability: persistent logs, watchdogs, Wi-Fi power save off"
bash "$SRC/deploy/hardening.sh"
CONN=$(nmcli -t -f NAME,TYPE connection show --active 2>/dev/null | grep wireless | cut -d: -f1 | head -1 || true)
if [ -n "$CONN" ]; then
    nmcli connection modify "$CONN" 802-11-wireless.powersave 2
    echo "   Wi-Fi power save off for: $CONN (applies after next reboot)"
fi

IP=$(hostname -I | awk '{print $1}')
cat <<EOF

=====================================================================
 sofarlogger installed.
   Dashboard:  http://$IP/
   Admin:      http://$IP/admin/admin.php   (login printed above as NEW ADMIN LOGIN;
                                             if not shown, one already existed)
 Next, in Admin:
   1. Site: name, latitude/longitude (for sunrise/sunset)
   2. Equipment: add your inverter(s), ME3000SP and battery BMS
   3. Electricity prices, Going Green panel details
   4. Admin login: change the password
 Then reserve fixed IPs for this Pi ($IP) and any Wi-Fi inverter loggers in your router.
 See docs/SETUP.md for the details.
=====================================================================
EOF
