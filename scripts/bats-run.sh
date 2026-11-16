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
trap 'rm -f "$report"' EXIT

status=0
bats --tap "${files[@]}" 2>&1 | tee "$report" || status=$?

planned="$(sed -n 's/^1\.\.\([0-9][0-9]*\)$/\1/p' "$report" | head -n 1)"
reported="$(grep -c -E '^(not )?ok ' "$report" || true)"
failed="$(grep -c -E '^not ok ' "$report" || true)"

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
