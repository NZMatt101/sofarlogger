#!/bin/sh
# Install the poller with per-device poll intervals (battery BMS read every 10 s).
# Run from the staging folder: sudo sh install-fastbms.sh
set -eu
[ "$(id -u)" -eq 0 ] || { echo "run with sudo"; exit 1; }
cd "$(dirname "$0")"
stamp=$(date +%Y%m%d-%H%M)
cp -p /opt/solar-logger/collector/poller.py "/opt/solar-logger/collector/poller.py.bak-$stamp"
install -m 755 poller.py /opt/solar-logger/collector/poller.py
systemctl restart solar-poller
sleep 3
systemctl --no-pager --lines=3 status solar-poller || true
echo "Installed. Backup: /opt/solar-logger/collector/poller.py.bak-$stamp"
