#!/bin/sh
# Install or update the CSIP gateway monitor. Run from this folder: sudo ./install.sh
# Keeps an existing /etc/csip-gateway/config.json; installs config.json from this folder only if none exists.
set -eu
[ "$(id -u)" -eq 0 ] || { echo "run with sudo"; exit 1; }
cd "$(dirname "$0")"

id csipgw >/dev/null 2>&1 || useradd --system --no-create-home --shell /usr/sbin/nologin csipgw
usermod -aG dialout csipgw

install -d -m 755 /opt/csip-gateway /opt/csip-gateway/web
install -m 644 csipgw.py /opt/csip-gateway/csipgw.py
install -m 644 web/index.html /opt/csip-gateway/web/index.html

install -d -o root -g csipgw -m 750 /etc/csip-gateway
if [ ! -f /etc/csip-gateway/config.json ]; then
    src=config.json; [ -f "$src" ] || src=config.example.json
    install -o root -g csipgw -m 640 "$src" /etc/csip-gateway/config.json
    echo "installed config from $src"
fi
install -d -o csipgw -g csipgw -m 750 /var/lib/csip-gateway

install -m 644 csip-gateway.service /etc/systemd/system/csip-gateway.service
systemctl daemon-reload
systemctl enable csip-gateway >/dev/null
systemctl restart csip-gateway
sleep 3
systemctl --no-pager --lines=5 status csip-gateway || true
echo "status page: http://$(hostname -I | awk '{print $1}')/"
