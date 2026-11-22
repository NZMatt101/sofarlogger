#!/bin/sh
# Install the updated admin page (Dropbox connect wording + port check). Run: sudo sh install-admin-dropbox.sh
set -eu
[ "$(id -u)" -eq 0 ] || { echo "run with sudo"; exit 1; }
cd "$(dirname "$0")"
stamp=$(date +%Y%m%d-%H%M)
cp -p /var/www/html/admin/admin.php "/var/www/html/admin/admin.php.bak-$stamp"
install -m 644 admin.php /var/www/html/admin/admin.php
echo "Installed. Backup: /var/www/html/admin/admin.php.bak-$stamp. Reload Admin -> Backup."
