#!/usr/bin/env bash
# ============================================================================
# Verifies both host-agnostic nginx vhost templates render __PRIMARY_HOST__ to a
# supplied hostname, and that no token survives in the output.
#
#   bash deploy/tests/render-vhost_test.sh
# ============================================================================
set -Eeuo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
deploy_dir="$(cd "$here/.." && pwd)"
# shellcheck source=../lib/host.sh
source "$deploy_dir/lib/host.sh"

tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

host="vote.example.edu"
status=0

for tpl in nginx-omnivote.conf nginx-funnel-api.conf; do
    src="$deploy_dir/$tpl"
    out="$tmp/$tpl"

    if ! grep -q '__PRIMARY_HOST__' "$src"; then
        echo "FAIL: $tpl has no __PRIMARY_HOST__ token to render"
        status=1
        continue
    fi

    omnivote_render_vhost "$src" "$out" "$host"

    if grep -q '__PRIMARY_HOST__' "$out"; then
        echo "FAIL: $tpl still contains __PRIMARY_HOST__ after render"
        status=1
    elif ! grep -q "server_name  *$host" "$out"; then
        echo "FAIL: $tpl did not render server_name $host"
        status=1
    else
        echo "ok: $tpl renders server_name $host"
    fi
done

exit "$status"
