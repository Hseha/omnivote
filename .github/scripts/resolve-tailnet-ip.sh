#!/usr/bin/env bash
# ============================================================================
# Resolve a tailnet host name to its Tailscale IPv4 address.
#
#   usage: resolve-tailnet-ip.sh <host>
#
# Why this exists: the release build's API pre-flight has to reach the
# production server over the tailnet. The public URL in front of that server
# is a Tailscale Funnel edge, which is an EXTERNAL dependency — a broken edge
# must never be able to fail the release build.
#
# MagicDNS is not guaranteed to be configured on a GitHub runner, so the
# address is read from the local Tailscale API rather than from the resolver.
# Callers pass the result to `curl --resolve` so the request keeps the real
# hostname for SNI and the Host header (the certificate is issued for the
# name, not for the 100.x address) while still going over the tailnet.
#
# Prints the IPv4 address on stdout. Exits non-zero with a message on stderr
# when the host cannot be resolved — a caller that ignores that would silently
# fall back to the public path, which is exactly what this script exists to
# prevent.
# ============================================================================
set -Eeuo pipefail

host="${1:-}"
if [[ -z "$host" ]]; then
    echo "usage: $(basename "$0") <host>" >&2
    exit 2
fi

# Accept "host", "host:port" and the trailing-dot FQDN form MagicDNS uses.
host="${host%%:*}"
host="${host%.}"

if ! command -v tailscale >/dev/null 2>&1; then
    echo "tailscale is not installed; the runner is not joined to the tailnet." >&2
    exit 1
fi

ip="$(
    tailscale status --json 2>/dev/null | python3 -c '
import json
import sys

host = sys.argv[1].lower()
status = json.load(sys.stdin)

# The node the runner is on may itself be the target, so Self is a candidate
# alongside the peers.
candidates = []
self_entry = status.get("Self") or {}
if self_entry:
    candidates.append(self_entry)
candidates.extend((status.get("Peer") or {}).values())

for peer in candidates:
    names = [peer.get("HostName") or "", peer.get("DNSName") or ""]
    if not any(name.lower().rstrip(".") == host for name in names):
        continue
    for address in peer.get("TailscaleIPs") or []:
        # Only the CGNAT range is a tailnet address; a public IP here would
        # mean the lookup matched something that is not on the tailnet.
        if address.startswith("100."):
            print(address)
            sys.exit(0)

sys.exit(1)
' "$host" 2>/dev/null || true
)"

if [[ -z "$ip" ]]; then
    echo "Could not resolve $host to a tailnet IPv4 address." >&2
    echo "Is the runner joined to the tailnet (Tailscale OIDC), and is $host on it?" >&2
    exit 1
fi

printf '%s\n' "$ip"
