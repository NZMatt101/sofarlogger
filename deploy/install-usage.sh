#!/bin/sh
# Install the Savings page "Household use" section (api.php action=usage + savings.html).
# Run from the staging folder: sudo sh install-usage.sh
set -eu
[ "$(id -u)" -eq 0 ] || { echo "run with sudo"; exit 1; }
cd "$(dirname "$0")"
stamp=$(date +%Y%m%d-%H%M)
for f in api.php savings.html; do cp -p "/var/www/html/$f" "/var/www/html/$f.bak-$stamp"; done
install -m 644 api.php /var/www/html/api.php
install -m 644 savings.html /var/www/html/savings.html
echo "Installed. Backups: /var/www/html/*.bak-$stamp. Open the Savings page and refresh."
