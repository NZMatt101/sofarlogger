#!/bin/sh
# Run on the Pi as root (ssh -t logger@PI "sudo sh ~/setup-funnel.sh").
# Publishes ONLY the Going Green figures (api.php?action=green) at a public https://<name>.ts.net address
# via Tailscale Funnel. Outbound connection only: no router port forwarding.
set -e
HERE=$(dirname "$0")

# 1. Locked-down local listener on 127.0.0.1:8081
install -m 0644 "$HERE/green-public.conf" /etc/apache2/sites-available/green-public.conf
a2ensite -q green-public
apache2ctl configtest
systemctl reload apache2
sleep 1
echo "local test (expect 200 then 403 403):"
curl -s -o /dev/null -w "  green  %{http_code}\n" "http://127.0.0.1:8081/api.php?action=green"
curl -s -o /dev/null -w "  site   %{http_code}\n" "http://127.0.0.1:8081/"
curl -s -o /dev/null -w "  admin  %{http_code}\n" "http://127.0.0.1:8081/admin/admin.php"

# 2. Tailscale (official installer)
if ! command -v tailscale >/dev/null 2>&1; then
    curl -fsSL https://tailscale.com/install.sh | sh
fi

# 3. Join your tailnet: open the login link this prints, sign in, approve the device.
tailscale up --hostname=solar-pi

# 4. Funnel port 8081 to the internet. If Funnel isn't enabled for your tailnet yet,
#    this prints a link to enable it; open it, approve, then re-run this script.
tailscale funnel --bg 8081
tailscale funnel status
echo "SETUP-FUNNEL-DONE"
