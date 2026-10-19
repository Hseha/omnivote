#!/usr/bin/env bash
# ============================================================================
# Verifies the tailnet-IP resolver used by the release build's API pre-flight.
#
#   bash .github/tests/resolve-tailnet-ip_test.sh
#
# The resolver reads `tailscale status --json`, so the test puts a fake
# `tailscale` on PATH that serves a fixture. That keeps the test hermetic:
# it needs neither a tailnet nor a runner.
# ============================================================================
set -Eeuo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
script="$here/../scripts/resolve-tailnet-ip.sh"

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

mkdir -p "$tmp/bin"

cat > "$tmp/bin/tailscale" <<'STUB'
#!/usr/bin/env bash
# Fake `tailscale status --json`: serves the fixture written by the test.
cat "$TAILSCALE_STATUS_FIXTURE"
STUB
chmod +x "$tmp/bin/tailscale"

status=0

expect() {
    local label="$1" want="$2" got="$3"
    if [[ "$want" == "$got" ]]; then
        echo "ok: $label"
    else
        echo "FAIL: $label"
        echo "      want: $want"
        echo "      got:  $got"
        status=1
    fi
}

# --- fixture: one peer, one self entry, one funnel ingress node (IPv6 only) --
cat > "$tmp/status.json" <<'JSON'
{
  "Self": {
    "HostName": "runner",
    "DNSName": "runner.tail7e9e1e.ts.net.",
    "TailscaleIPs": ["100.100.224.74", "fd7a:115c:1e0::c136:e04b"]
  },
  "Peer": {
    "key1": {
      "HostName": "debian",
      "DNSName": "debian.tail7e9e1e.ts.net.",
      "TailscaleIPs": ["100.84.115.25", "fd7a:115c:1e0::d101:73b8"]
    },
    "key2": {
      "HostName": "funnel-ingress-node",
      "DNSName": "",
      "TailscaleIPs": ["fd7a:115c:1e0::a801:26ab"]
    }
  }
}
JSON
export TAILSCALE_STATUS_FIXTURE="$tmp/status.json"
export PATH="$tmp/bin:$PATH"

# 1. A peer resolves to its tailnet IPv4 address.
got="$(bash "$script" debian.tail7e9e1e.ts.net || true)"
expect "peer resolves to its tailnet IPv4" "100.84.115.25" "$got"

# 2. The trailing-dot MagicDNS form and a bare host name both work.
got="$(bash "$script" debian.tail7e9e1e.ts.net. || true)"
expect "trailing-dot FQDN resolves" "100.84.115.25" "$got"

got="$(bash "$script" debian || true)"
expect "bare host name resolves" "100.84.115.25" "$got"

# 3. A host:port suffix is stripped before the lookup.
got="$(bash "$script" debian.tail7e9e1e.ts.net:443 || true)"
expect "host:port suffix is stripped" "100.84.115.25" "$got"

# 4. The runner's own node resolves too (Self is a candidate).
got="$(bash "$script" runner.tail7e9e1e.ts.net || true)"
expect "self node resolves" "100.100.224.74" "$got"

# 5. An unknown host fails loudly instead of printing an empty address —
#    an empty result would make the caller fall back to the public path.
got="$(bash "$script" not-a-real-host.tail7e9e1e.ts.net 2>/dev/null || true)"
expect "unknown host yields no address" "" "$got"

if bash "$script" not-a-real-host.tail7e9e1e.ts.net >/dev/null 2>&1; then
    echo "FAIL: unknown host should exit non-zero"
    status=1
else
    echo "ok: unknown host exits non-zero"
fi

# 6. A missing argument is a usage error, not an empty address.
if bash "$script" >/dev/null 2>&1; then
    echo "FAIL: missing argument should exit non-zero"
    status=1
else
    echo "ok: missing argument exits non-zero"
fi

exit "$status"
