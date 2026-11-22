#!/bin/sh
# Install the multi-array Going Green update (panel arrays in Admin, shown on the Going Green page).
# Also includes the latest api.php, admin.php and green.html. Run from the staging folder: sudo sh install-green.sh
set -eu
[ "$(id -u)" -eq 0 ] || { echo "run with sudo"; exit 1; }
cd "$(dirname "$0")"
stamp=$(date +%Y%m%d-%H%M)
for pair in "common.php:/var/www/html/inc/common.php" "api.php:/var/www/html/api.php" \
            "green.html:/var/www/html/green.html" "admin.php:/var/www/html/admin/admin.php"; do
    src=${pair%%:*}; dst=${pair#*:}
    cp -p "$dst" "$dst.bak-$stamp"
    install -m 644 "$src" "$dst"
done
echo "Installed. Backups: *.bak-$stamp. Admin -> Going Green page: enter the panel arrays and save."
