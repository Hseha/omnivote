#!/usr/bin/env bash
# ============================================================================
# Guards the two deploy-script bugs that have each broken a production
# deploy, so a future edit cannot reintroduce them.
#
#   bash deploy/tests/deploy-script_test.sh
#
# 1. The nginx vhost step used a relative template path while the script was
#    sitting in backend-laravel, so `sed` died with "can't read
#    deploy/nginx-omnivote.conf" and the deploy failed on its last step.
#
# 2. The script resets the checkout it is running from. bash re-reads a script
#    file lazily by byte offset, so a script that rewrites itself mid-run can
#    execute the wrong lines — which is how the "Syncing nginx vhosts" step
#    came to exist in the repository without ever running. The forced command
#    must therefore run a snapshot of the script, not the file in place.
# ============================================================================
set -Eeuo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
deploy_dir="$(cd "$here/.." && pwd)"

script="$deploy_dir/deploy.sh"
wrapper="$deploy_dir/omnivote-deploy"

status=0

fail() {
    echo "FAIL: $1"
    status=1
}

ok() {
    echo "ok: $1"
}

# --- 1. The vhost templates are addressed absolutely -------------------------
# A relative path is only correct while the shell happens to be in the repo
# root, which the script stops being true of part-way through.
# shellcheck disable=SC2016  # literal $APP_DIR is the pattern
if sed 's|"\$APP_DIR/deploy/|"|g' "$script" \
    | grep -vE '^\s*#' \
    | grep -qE 'deploy/nginx-(omnivote|funnel-api)\.conf'; then
    fail "the vhost templates are addressed with a relative path"
    # shellcheck disable=SC2016  # literal $APP_DIR is the pattern
    sed 's|"\$APP_DIR/deploy/|"|g' "$script" \
        | grep -vE '^\s*#' \
        | grep -nE 'deploy/nginx-' | sed 's/^/      /'
else
    ok "the vhost templates are addressed absolutely"
fi

for tpl in nginx-omnivote.conf nginx-funnel-api.conf; do
    if grep -q "\"\$APP_DIR/deploy/$tpl\"" "$script"; then
        ok "the $tpl template is read from \$APP_DIR"
    else
        fail "the $tpl template is not read from \$APP_DIR"
    fi
done

# --- 2. The script returns to the repo root before the vhost step -----------
# Belt and braces: even with absolute paths, a script that keeps cd-ing
# around is one refactor away from the same class of bug.
backend_line="$(grep -n '^cd backend-laravel' "$script" | head -1 | cut -d: -f1)"
vhost_sed_line="$(grep -n 'nginx-omnivote.conf" >' "$script" | head -1 | cut -d: -f1)"
if [[ -z "$backend_line" || -z "$vhost_sed_line" ]]; then
    fail "could not locate the cd backend-laravel / vhost steps"
elif [[ "$backend_line" -gt "$vhost_sed_line" ]]; then
    ok "the script never cd's into backend-laravel before the vhost step"
else
    cd_line="$(awk -v start="$backend_line" \
        'NR > start && /^cd "\$APP_DIR"/ { print NR; exit }' "$script")"
    if [[ -n "$cd_line" && "$cd_line" -lt "$vhost_sed_line" ]]; then
        ok "the script returns to \$APP_DIR before the vhost step (line $cd_line)"
    else
        fail "the script never returns to \$APP_DIR after cd backend-laravel"
    fi
fi

# --- 3. The forced command snapshots the script before running it ------------
if [[ ! -f "$wrapper" ]]; then
    fail "the forced-command wrapper deploy/omnivote-deploy is missing"
else
    ok "the forced-command wrapper exists"

    # shellcheck disable=SC2016  # literal $SCRIPT/$tmp are the patterns
    if grep -qE 'cat "\$SCRIPT" > "\$tmp"' "$wrapper"; then
        ok "the wrapper copies the script to a temporary file before running it"
    else
        fail "the wrapper does not snapshot the script before running it"
    fi

    # shellcheck disable=SC2016  # literal $tmp is the pattern
    if grep -qE '^\s*/bin/bash "\$tmp"' "$wrapper"; then
        ok "the wrapper runs the snapshot, not the file in place"
    else
        fail "the wrapper does not run the snapshot"
    fi
fi

# --- 4. The deploy script fails early and loudly on an ownership drift -------
# "detected dubious ownership" is cryptic: it does not say who to chown the
# repository to. The preflight has to say it.
if grep -q 'detected dubious ownership\|cannot operate on' "$script"; then
    ok "the deploy script explains a git ownership failure"
else
    fail "the deploy script does not handle a git ownership failure"
fi

if grep -q 'safe.directory' "$script"; then
    ok "the deploy script points at the safe.directory fix"
else
    fail "the deploy script does not mention the safe.directory fix"
fi

# --- 5. The provisioning script installs the wrapper and the exemption ------
setup="$deploy_dir/setup-server.sh"
if grep -q 'install -m 755 .*omnivote-deploy' "$setup"; then
    ok "setup-server.sh installs the forced-command wrapper"
else
    fail "setup-server.sh does not install the forced-command wrapper"
fi

if grep -q 'safe.directory' "$setup"; then
    ok "setup-server.sh grants the deploy account the safe.directory exemption"
else
    fail "setup-server.sh does not grant the safe.directory exemption"
fi

# --- 6. The nginx vhosts are installed by a root-owned helper ---------------
# The step that installs them was never exercised until the self-mutation bug
# above was fixed, and when it finally ran it failed: the deploy account has
# no sudo rule for `cp` into /etc/nginx. The helper keeps that out of sudo.
nginx_helper="$deploy_dir/omnivote-nginx-vhosts"

if [[ ! -f "$nginx_helper" ]]; then
    fail "the nginx vhost helper deploy/omnivote-nginx-vhosts is missing"
else
    ok "the nginx vhost helper exists"

    # shellcheck disable=SC2016  # literal $NGINX_VHOST_HELPER is the pattern
    if grep -q 'sudo "\$NGINX_VHOST_HELPER"' "$script"; then
        ok "the deploy script installs the vhosts through the helper"
    else
        fail "the deploy script does not use the nginx vhost helper"
    fi

    if grep -qE '^\s*sudo (cp|nginx|systemctl)' "$script"; then
        fail "the deploy script still runs nginx-related commands through sudo directly"
        grep -nE '^\s*sudo (cp|nginx|systemctl)' "$script" | sed 's/^/      /'
    else
        ok "the deploy script runs no nginx-related command through sudo directly"
    fi

    if grep -q 'nginx -t' "$nginx_helper"; then
        ok "the helper validates the vhosts with nginx -t"
    else
        fail "the helper does not validate the vhosts"
    fi

    if grep -q 'install -m 755 .*omnivote-nginx-vhosts' "$setup"; then
        ok "setup-server.sh installs the nginx vhost helper"
    else
        fail "setup-server.sh does not install the nginx vhost helper"
    fi
fi

exit "$status"
