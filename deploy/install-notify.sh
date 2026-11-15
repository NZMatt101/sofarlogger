#!/bin/sh
# Install or update SMS alerts and the Admin → Notifications page.
# Run from the staging folder: sudo sh install-notify.sh
# Keeps an existing /etc/solar-logger/notify.json. A new one starts with alerts OFF; switch them on in the admin page.
set -eu
[ "$(id -u)" -eq 0 ] || { echo "run with sudo"; exit 1; }
cd "$(dirname "$0")"
stamp=$(date +%Y%m%d-%H%M)

# back up the live files this replaces
for f in /var/www/html/admin/admin.php /opt/solar-logger/collector/notifier.py; do
    [ -f "$f" ] && cp -p "$f" "$f.bak-$stamp"
done

install -m 755 notifier.py /opt/solar-logger/collector/notifier.py
install -m 644 notifications.php /var/www/html/admin/notifications.php
install -m 644 admin.php /var/www/html/admin/admin.php
install -m 644 solar-notify.service /etc/systemd/system/solar-notify.service

# settings file: written by the admin page (www-data), read by the notifier (solarcap, group www-data)
if [ ! -f /etc/solar-logger/notify.json ]; then
    sed 's/"enabled": true/"enabled": false/' notify.example.json > /etc/solar-logger/notify.json
    echo "created /etc/solar-logger/notify.json with alerts off"
fi
chown www-data:www-data /etc/solar-logger/notify.json
chmod 660 /etc/solar-logger/notify.json

systemctl daemon-reload
systemctl enable solar-notify >/dev/null
systemctl restart solar-notify
sleep 2
systemctl --no-pager --lines=4 status solar-notify || true
echo
echo "Open Admin → Notifications: http://$(hostname -I | awk '{print $1}')/admin/notifications.php"
