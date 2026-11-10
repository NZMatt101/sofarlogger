#!/usr/bin/env bash
# Install or update the poller service, Apache web UI and admin page. Run as root; install.sh calls it.
set -euo pipefail
SRC=${SRC:-$(cd "$(dirname "$0")/.." && pwd)}   # the repo (or unpacked release) folder
find "$SRC/deploy" "$SRC/collector" "$SRC/web" -type f \( -name '*.sh' -o -name '*.py' -o -name '*.php' -o -name '*.html' \
    -o -name '*.css' -o -name '*.json' -o -name '*.conf' -o -name '*.service' -o -name '*.timer' -o -name 'common.js' -o -name 'chart*.js' \) \
    -exec sed -i 's/\r$//' {} +

id solarcap >/dev/null 2>&1 || useradd --system --no-create-home --shell /usr/sbin/nologin solarcap

install -d /opt/solar-logger/collector
install -m 0755 "$SRC/collector/poller.py" "$SRC/collector/import_sdcard.py" /opt/solar-logger/collector/
install -m 0644 "$SRC/deploy/solar-poller.service" /etc/systemd/system/solar-poller.service
install -m 0644 "$SRC/deploy/solar-backup.service" "$SRC/deploy/solar-backup.timer" /etc/systemd/system/

# Config is shared: root/www-data (admin page) write it, the poller (group www-data) reads it.
install -d -m 2775 -o root -g www-data /etc/solar-logger
[ -f /etc/solar-logger/config.json ] || install -m 0660 -g www-data "$SRC/config.example.json" /etc/solar-logger/config.json
chgrp www-data /etc/solar-logger/config.json && chmod 0660 /etc/solar-logger/config.json

# Admin login (created once; password printed below)
if [ ! -f /etc/solar-logger/admin.htpasswd ]; then
    PW=$(openssl rand -base64 12 | tr -d '/+=' | cut -c1-12)
    htpasswd -cbB /etc/solar-logger/admin.htpasswd admin "$PW" 2>/dev/null
    echo "NEW ADMIN LOGIN: admin / $PW"
fi
chown root:www-data /etc/solar-logger/admin.htpasswd && chmod 0660 /etc/solar-logger/admin.htpasswd

# Web UI (replaces Apache default page)
install -d /var/www/html/admin /var/www/html/inc
install -m 0644 "$SRC"/web/*.html "$SRC"/web/*.php "$SRC"/web/*.js "$SRC"/web/*.css /var/www/html/
install -m 0644 "$SRC"/web/admin/*.php /var/www/html/admin/
install -m 0644 "$SRC"/web/inc/*.php /var/www/html/inc/
install -m 0644 "$SRC/deploy/solar-admin.conf" /etc/apache2/conf-available/solar-admin.conf
a2enmod -q headers
a2enconf -q solar-admin

systemctl daemon-reload
systemctl enable --now solar-poller
# Admin → History writes to the database: make it group-writable for www-data
chmod 0664 /var/lib/solar-logger/solar.db* 2>/dev/null || true
systemctl enable --now solar-backup.timer   # does nothing until Dropbox backups are turned on in Admin
systemctl restart solar-poller
apache2ctl configtest
systemctl reload apache2
sleep 3
systemctl --no-pager status solar-poller | head -n 5
