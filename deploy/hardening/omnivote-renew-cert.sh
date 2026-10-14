#!/usr/bin/env bash
# OmniVote TLS certificate renewal (installed at
# /usr/local/sbin/omnivote-renew-cert.sh by deploy/harden-server.sh).
#
# Issues/renews the Tailscale-issued Let's Encrypt cert for the node's
# MagicDNS name, installs it where nginx expects it, and reloads nginx.
# Run monthly by omnivote-cert-renew.timer. `tailscale cert` returns the
# existing cert until it is close to expiry, so running it often is cheap.
set -euo pipefail

DOMAIN="${1:-__PRIMARY_HOST__}"
CERT="/etc/ssl/private/${DOMAIN}.crt"
KEY="/etc/ssl/private/${DOMAIN}.key"

/usr/bin/tailscale cert --cert-file="$CERT" --key-file="$KEY" "$DOMAIN"

chmod 644 "$CERT"
chmod 600 "$KEY"
chown root:root "$CERT" "$KEY"

/usr/bin/openssl x509 -in "$CERT" -noout -subject -enddate

/usr/sbin/nginx -t
/usr/bin/systemctl reload nginx
