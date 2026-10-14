#!/usr/bin/env bash
# ============================================================================
# OmniVote — server hardening (idempotent). Run as root on the app server:
#
#   sudo PRIMARY_HOST=debian.tail7e9e1e.ts.net bash deploy/harden-server.sh
#
# Applies, on top of setup-server.sh:
#   * SSH: key-only auth (no passwords / keyboard-interactive), no X11
#   * sysctl: rp_filter, no redirects/source-route, kernel pointer + dmesg
#     restrictions, panic auto-reboot
#   * MariaDB: loopback-only listener, local-infile disabled
#   * firewall: 22/80/443 reachable only over the tailnet interface
#   * unattended-upgrades: security updates only, never auto-reboot
#   * systemd runtime hardware watchdog
#   * monthly Tailscale TLS certificate renewal (script + service + timer)
#
# Re-running is safe. Validate before reload where the tool supports it.
# ============================================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=lib/host.sh
source "$SCRIPT_DIR/lib/host.sh"
PRIMARY_HOST="${PRIMARY_HOST:-$(omnivote_detect_primary_host)}"

log() { printf '\n\033[1;32m==> %s\033[0m\n' "$*"; }

if [[ $EUID -ne 0 ]]; then
    echo "Run as root: sudo bash $0" >&2
    exit 1
fi

# ---------------------------------------------------------------------------
log "SSH: key-only auth, no X11 forwarding"
install -m 0644 "$SCRIPT_DIR/hardening/sshd-99-hardening.conf" \
    /etc/ssh/sshd_config.d/99-hardening.conf
sshd -t
systemctl reload ssh

# ---------------------------------------------------------------------------
log "sysctl: network + kernel hardening"
install -m 0644 "$SCRIPT_DIR/hardening/sysctl-99-omnivote-hardening.conf" \
    /etc/sysctl.d/99-omnivote-hardening.conf
sysctl --system >/dev/null

# ---------------------------------------------------------------------------
log "MariaDB: loopback-only + local-infile disabled"
MARIADB_CONF_DIR=/etc/mysql/mariadb.conf.d
[[ -d "$MARIADB_CONF_DIR" ]] || MARIADB_CONF_DIR=/etc/mysql/conf.d
install -m 0644 "$SCRIPT_DIR/hardening/mariadb-99-omnivote-hardening.cnf" \
    "$MARIADB_CONF_DIR/99-omnivote-hardening.cnf"
systemctl restart mariadb
if mariadb -N -e "SELECT user,host FROM mysql.user;" 2>/dev/null | grep -q "^dev_admin"; then
    mariadb -e "DROP USER IF EXISTS 'dev_admin'@'%';"
    echo "  dropped legacy wildcard superuser dev_admin@'%'"
fi

# ---------------------------------------------------------------------------
log "systemd: runtime hardware watchdog"
install -d /etc/systemd/system.conf.d
install -m 0644 "$SCRIPT_DIR/hardening/systemd-watchdog.conf" \
    /etc/systemd/system.conf.d/watchdog.conf
systemctl daemon-reexec

# ---------------------------------------------------------------------------
log "Firewall: 22/80/443 only over the tailnet"
command -v ufw >/dev/null 2>&1 || apt-get install -y ufw
if ip link show tailscale0 >/dev/null 2>&1; then
    ufw --force delete allow 80/tcp 2>/dev/null || true
    ufw --force delete allow 443/tcp 2>/dev/null || true
    ufw --force delete allow 'Nginx Full' 2>/dev/null || true
    ufw allow in on tailscale0 to any port 22 proto tcp comment 'SSH via tailnet'
    ufw allow in on tailscale0 to any port 80,443 proto tcp comment 'OmniVote HTTP/HTTPS via tailnet'
    ufw --force enable
    ufw status numbered
else
    echo "  (no tailscale0 interface; leaving firewall rules untouched)"
fi

# ---------------------------------------------------------------------------
log "Automatic security updates (security-only, no auto-reboot)"
export DEBIAN_FRONTEND=noninteractive
apt-get install -y unattended-upgrades
install -m 0644 "$SCRIPT_DIR/hardening/apt-20auto-upgrades" \
    /etc/apt/apt.conf.d/20auto-upgrades
install -m 0644 "$SCRIPT_DIR/hardening/apt-52-omnivote-unattended-upgrades" \
    /etc/apt/apt.conf.d/52-omnivote-unattended-upgrades
# apt appends Origin lists across files, so comment the general origin out of
# the distro conffile to keep the scope to security updates only.
UU_CONFFILE=/etc/apt/apt.conf.d/50unattended-upgrades
if [[ -f "$UU_CONFFILE" ]]; then
    sed -i '/label=Debian";/s/^/\/\//' "$UU_CONFFILE"
fi
systemctl enable --now unattended-upgrades.service

# ---------------------------------------------------------------------------
log "TLS certificate auto-renewal for $PRIMARY_HOST"
tmp="$(mktemp)"
omnivote_render_vhost "$SCRIPT_DIR/hardening/omnivote-renew-cert.sh" "$tmp" "$PRIMARY_HOST"
install -m 0755 "$tmp" /usr/local/sbin/omnivote-renew-cert.sh
rm -f "$tmp"
omnivote_render_vhost "$SCRIPT_DIR/hardening/omnivote-cert-renew.service" \
    /etc/systemd/system/omnivote-cert-renew.service "$PRIMARY_HOST"
install -m 0644 "$SCRIPT_DIR/hardening/omnivote-cert-renew.timer" \
    /etc/systemd/system/omnivote-cert-renew.timer
systemctl daemon-reload
systemctl enable --now omnivote-cert-renew.timer
# Validate the whole chain now (cert issue/install + nginx -t + reload).
# Non-fatal on a brand-new host where Funnel/HTTPS may not be enabled yet —
# the monthly timer will pick it up.
systemctl start omnivote-cert-renew.service \
    || echo "  (initial cert issuance failed — confirm 'tailscale funnel' is enabled; the timer will retry)"

# ---------------------------------------------------------------------------
cat <<EOF

================================================================================
 Server hardening applied for $PRIMARY_HOST

 Verify:
   sshd -T | grep -E 'passwordauthentication|x11forwarding'
   ss -tlnp | grep 3306                 # must be 127.0.0.1 only
   ufw status numbered                  # 22/80/443 only on tailscale0
   systemctl show -p RuntimeWatchdogUSec
   systemctl list-timers omnivote-cert-renew.timer
   unattended-upgrade --dry-run -v | grep 'Allowed origins'

 A kernel update from unattended-upgrades applies on the next reboot.
 Reboot when convenient:  sudo systemctl reboot
================================================================================
EOF
