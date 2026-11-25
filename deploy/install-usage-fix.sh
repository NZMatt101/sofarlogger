#!/bin/sh
# Install the corrected household-use split (api.php). Run from the staging folder: sudo sh install-usage-fix.sh
set -eu
[ "$(id -u)" -eq 0 ] || { echo "run with sudo"; exit 1; }
cd "$(dirname "$0")"
stamp=$(date +%Y%m%d-%H%M)
cp -p /var/www/html/api.php "/var/www/html/api.php.bak-$stamp"
install -m 644 api.php /var/www/html/api.php
echo "Installed. Backup: /var/www/html/api.php.bak-$stamp. Refresh the Savings page."
