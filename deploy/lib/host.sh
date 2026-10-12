#!/usr/bin/env bash
# ============================================================================
# Shared host-detection + vhost-rendering helpers for the deploy scripts.
# ----------------------------------------------------------------------------
# Sourced (never executed) by setup-server.sh and setup-staging.sh so the
# hostname logic lives in exactly one place, and exercised by
# deploy/tests/render-vhost_test.sh.
# ============================================================================

# Echo the hostname the nginx vhosts should serve. On a Tailscale node the
# MagicDNS name (e.g. debian.tail7e9e1e.ts.net) is the right value; `hostname -f`
# frequently returns the bare short name instead, so prefer `tailscale status
# --json` when tailscale + python3 are available. Callers may still override
# with an explicit PRIMARY_HOST / STAGING_HOST env var.
omnivote_detect_primary_host() {
    local dns
    if command -v tailscale >/dev/null 2>&1 && command -v python3 >/dev/null 2>&1; then
        dns="$(tailscale status --json 2>/dev/null \
            | python3 -c 'import sys,json; print(json.load(sys.stdin).get("Self",{}).get("DNSName","").rstrip("."))' 2>/dev/null || true)"
        if [[ -n "$dns" ]]; then printf '%s' "$dns"; return; fi
    fi
    hostname -f
}

# Render a vhost template's __PRIMARY_HOST__ token into an output file.
#   omnivote_render_vhost <template> <output> <host>
omnivote_render_vhost() {
    sed "s|__PRIMARY_HOST__|$3|g" "$1" > "$2"
}
