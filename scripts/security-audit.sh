#!/usr/bin/env bash
set -euo pipefail

# Load central configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "$SCRIPT_DIR/config.sh"
maybe_dockerize "$0" "$@"

log_header "Security Audit"

# Run composer audit and output JSON report
AUDIT_REPORT="${BUILD_DIR}/logs/audit-report.json"
ensure_dir "$(dirname "$AUDIT_REPORT")"

log_step "Running composer audit..."

# An abandoned package is not a vulnerability: composer would end with an error for it. The exit code is not enough alone
# (it is also not 0 when the audit could not run): the report decides, and the level is the one of the daily audit
# (deps:audit): an advisory that is high or critical blocks, the others (an unknown severity counts as a medium one) are
# listed. The rule is in bin/update-dependencies (audit-check), in one place.
audit_status=0
composer audit --format=json --abandoned=ignore > "$AUDIT_REPORT" || audit_status=$?

if ! php "$(dirname "$SCRIPT_DIR")/bin/update-dependencies" audit-check "$AUDIT_REPORT"; then
  log_error "Security audit failed (composer audit exit ${audit_status}): see the advisories above"
  exit 1
fi

log_success "No security vulnerabilities found at the high level or above"
