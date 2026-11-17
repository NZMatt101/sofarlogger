#!/bin/sh
# Install the EPS on/off control (poller + battery page). Run from the staging folder: sudo sh install-eps.sh
set -eu
[ "$(id -u)" -eq 0 ] || { echo "run with sudo"; exit 1; }
cd "$(dirname "$0")"
stamp=$(date +%Y%m%d-%H%M)
cp -p /opt/solar-logger/collector/poller.py "/opt/solar-logger/collector/poller.py.bak-$stamp"
cp -p /var/www/html/admin/battery.php "/var/www/html/admin/battery.php.bak-$stamp"
install -m 755 poller.py /opt/solar-logger/collector/poller.py
install -m 644 battery.php /var/www/html/admin/battery.php
systemctl restart solar-poller
sleep 3
systemctl --no-pager --lines=4 status solar-poller || true
echo
echo "Backups: *.bak-$stamp. Battery page → Backup power (EPS)."
