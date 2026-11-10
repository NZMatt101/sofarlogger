#!/usr/bin/env bash
# Run on the Pi as root: persistent logs, hardware watchdog, and a network watchdog for unattended operation.
set -euo pipefail

# 1. Keep the journal across reboots (capped for the small SD card) so dropouts can be diagnosed.
install -d /etc/systemd/journald.conf.d /var/log/journal
cat > /etc/systemd/journald.conf.d/solar.conf <<'EOF'
[Journal]
Storage=persistent
SystemMaxUse=50M
EOF
systemd-tmpfiles --create --prefix /var/log/journal
systemctl restart systemd-journald

# 2. Hardware watchdog: reboot automatically if the system hangs.
install -d /etc/systemd/system.conf.d
cat > /etc/systemd/system.conf.d/watchdog.conf <<'EOF'
[Manager]
RuntimeWatchdogSec=15
RebootWatchdogSec=2min
EOF
systemctl daemon-reexec

# 3. Network watchdog: reconnect Wi-Fi after 3 failed minutes, reboot after 10.
cat > /usr/local/sbin/solar-netwatch <<'EOF'
#!/bin/sh
STATE=/run/solar-netwatch.fails
GW=$(ip route show default | awk '/default/ {print $3; exit}')
if [ -n "$GW" ] && ping -c 3 -W 2 "$GW" >/dev/null 2>&1; then
    [ -f "$STATE" ] && logger -t solar-netwatch "network OK again after $(cat "$STATE") failed check(s)"
    rm -f "$STATE"; exit 0
fi
FAILS=$(( $(cat "$STATE" 2>/dev/null || echo 0) + 1 )); echo "$FAILS" > "$STATE"
logger -t solar-netwatch "gateway ${GW:-none} unreachable (check $FAILS)"
if [ "$FAILS" -ge 10 ]; then
    logger -t solar-netwatch "rebooting after $FAILS failed checks"; systemctl reboot
elif [ "$FAILS" -eq 3 ] || [ "$FAILS" -eq 6 ]; then
    logger -t solar-netwatch "restarting Wi-Fi"; nmcli radio wifi off; sleep 3; nmcli radio wifi on
fi
EOF
chmod 0755 /usr/local/sbin/solar-netwatch
cat > /etc/systemd/system/solar-netwatch.service <<'EOF'
[Unit]
Description=Solar logger network watchdog check

[Service]
Type=oneshot
ExecStart=/usr/local/sbin/solar-netwatch
EOF
cat > /etc/systemd/system/solar-netwatch.timer <<'EOF'
[Unit]
Description=Run solar logger network watchdog every minute

[Timer]
OnBootSec=3min
OnUnitActiveSec=1min

[Install]
WantedBy=timers.target
EOF
systemctl daemon-reload
systemctl enable --now solar-netwatch.timer

echo "--- journal: $(journalctl --disk-usage)"
echo "--- watchdog: $(systemctl show -p RuntimeWatchdogUSec --value) ; device: $(ls /dev/watchdog* 2>/dev/null | tr '\n' ' ')"
echo "--- netwatch: $(systemctl is-active solar-netwatch.timer)"
