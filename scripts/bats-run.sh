#!/usr/bin/env bash
set -euo pipefail

# Load central configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "$SCRIPT_DIR/config.sh"

log_header "Shell tests (Bats)"

# Runs every Bats file of tests/bats/ and fails when a test fails or disappears: the number of "ok" and "not ok" lines
# must equal the plan ("1..N"). Used by `make bats` and by the pipeline.

if ! command_exists bats; then
  log_error "Bats is not installed - run: bash scripts/install-bats.sh"
  exit 1
fi

mapfile -t files < <(find "${PROJECT_ROOT}/tests/bats" -name '*.bats' -type f 2> /dev/null | sort)

if [[ ${#files[@]} -eq 0 ]]; then
  log_info "No Bats tests found - skipping"
  exit 0
fi

report="$(mktemp)"
bats_pid=""
trap '[[ -n "$bats_pid" ]] && kill "$bats_pid" 2> /dev/null; rm -f "$report"' EXIT

# A counter on one line, updated while Bats runs: the results done out of the plan of the report
show_progress() {
  local pid="$1" file="$2" total finished

  while kill -0 "$pid" 2> /dev/null; do
    total="$(grep -m 1 -o -E '^1\.\.[0-9]+' "$file" 2> /dev/null | cut -d. -f3 || true)"
    finished="$(grep -c -E '^(not )?ok ' "$file" 2> /dev/null || true)"
    printf '\r  Bats: %s/%s tests' "${finished:-0}" "${total:-?}"
    sleep 0.2
  done

  # Clear the line
  printf '\r\033[K'
}

# The full report is read from a file: it gives the number of tests whatever is shown. VERBOSE=true shows every line.
# The counter is shown on a terminal only: a log of the CI would hold every update.
status=0
if [[ "${VERBOSE:-false}" == "true" ]]; then
  bats --tap "${files[@]}" 2>&1 | tee "$report" || status=$?
else
  bats --tap "${files[@]}" > "$report" 2>&1 &
  bats_pid=$!

  [[ -t 1 ]] && show_progress "$bats_pid" "$report"

  wait "$bats_pid" || status=$?
  bats_pid=""
fi

planned="$(sed -n 's/^1\.\.\([0-9][0-9]*\)$/\1/p' "$report" | head -n 1)"
reported="$(grep -c -E '^(not )?ok ' "$report" || true)"
failed="$(grep -c -E '^not ok ' "$report" || true)"

# Only what went wrong: the failed tests with their details, and the warnings of Bats
if [[ "${VERBOSE:-false}" != "true" ]]; then
  awk '
    /^not ok / { show = 1; print; next }
    /^ok /     { show = 0; next }
    /^# bats warning/ { print; next }
    show && /^#/ { print }
  ' "$report"
fi

if [[ -z "$planned" ]]; then
  log_error "Bats gave no plan (1..N): the run did not start"
  exit 1
fi

if [[ "$reported" -ne "$planned" ]]; then
  log_error "Bats planned ${planned} tests and reported ${reported}: a test disappeared"
  exit 1
fi

if [[ "$failed" -gt 0 ]] || [[ "$status" -ne 0 ]]; then
  log_error "${failed} Bats test(s) failed"
  exit 1
fi

log_success "${reported} Bats tests passed"
