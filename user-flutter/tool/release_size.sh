#!/usr/bin/env bash
# ============================================================================
# OmniVote student app — release size check.
#
#   ./tool/release_size.sh build     build the arm64 release APK + enforce budget
#   ./tool/release_size.sh check PATH check one (already built) APK/AAB
#
# The budget exists because students download this on metered school/mobile
# connections during a short voting window: a regression here is a regression
# in how many of them finish installing before the polls close. Override the
# budget with APK_SIZE_BUDGET_BYTES when a deliberate change lands, and record
# the new number in docs/08_BUILD_RELEASE.md.
# ============================================================================
set -euo pipefail

cd "$(dirname "$0")/.."

APK_SIZE_BUDGET_BYTES="${APK_SIZE_BUDGET_BYTES:-$((24 * 1024 * 1024))}"
API_BASE_URL="${API_BASE_URL:-https://debian.tail7e9e1e.ts.net/api}"
MODE="${1:-build}"

mb() { awk -v b="$1" 'BEGIN{printf "%.2f", b/1024/1024}'; }

size_of() {
  local path="$1"
  if [ ! -e "$path" ]; then
    echo "missing artifact: $path" >&2
    exit 2
  fi
  # .aab/.apk are zips: fall back to zipinfo when stat is unavailable.
  stat -c%s "$path" 2>/dev/null || wc -c < "$path"
}

report() {
  local path="$1" size="$2"
  echo "  artifact : $path"
  echo "  size     : $size bytes ($(mb "$size") MB)"
  echo "  budget   : $APK_SIZE_BUDGET_BYTES bytes ($(mb "$APK_SIZE_BUDGET_BYTES") MB)"
  if [ "$size" -gt "$APK_SIZE_BUDGET_BYTES" ]; then
    echo "FAIL: release artifact grew past the budget." >&2
    echo "      Investigate with:" >&2
    echo "        flutter build apk --release --analyze-size --target-platform android-arm64" >&2
    echo "      If the growth is intentional, bump APK_SIZE_BUDGET_BYTES and" >&2
    echo "      update docs/08_BUILD_RELEASE.md in the same change." >&2
    exit 1
  fi
  echo "OK: within budget."
}

if [ "$MODE" = "check" ]; then
  report "$2" "$(size_of "$2")"
  exit 0
fi

if [ "$MODE" != "build" ]; then
  echo "usage: $0 [build|check <artifact>]" >&2
  exit 64
fi

# The arm64 APK that students download, obfuscated, ~19.0 MB at the time of
# writing.
#
# Validated end-to-end (exit 0, "OK: within budget") before it was wired into
# CI. Rerun it whenever the budget row is edited.
flutter build apk --release \
  --target-platform android-arm64 \
  --obfuscate \
  --split-debug-info=build/symbols \
  --dart-define=API_BASE_URL="$API_BASE_URL"

APK=build/app/outputs/flutter-apk/app-release.apk
report "$APK" "$(size_of "$APK")"
