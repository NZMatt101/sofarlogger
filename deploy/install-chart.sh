#!/bin/sh
# Install the stacked site-power chart fix (common.js) and the pages that load it.
# Run from the staging folder: sudo sh install-chart.sh
set -eu
[ "$(id -u)" -eq 0 ] || { echo "run with sudo"; exit 1; }
cd "$(dirname "$0")"
stamp=$(date +%Y%m%d-%H%M)
for f in common.js index.html device.html green.html savings.html; do
    cp -p "/var/www/html/$f" "/var/www/html/$f.bak-$stamp"
    install -m 644 "$f" "/var/www/html/$f"
done
echo "Installed. Backups: /var/www/html/*.bak-$stamp. Reload the Overview page."
