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

# The exit code of composer is not enough alone (it is also not 0 when the audit could not run): the report must be a
# JSON, and the advisories are counted in it.
audit_status=0
composer audit --format=json > "$AUDIT_REPORT" || audit_status=$?

if ! command_exists jq; then
  if [ "$audit_status" -eq 0 ]; then
    log_success "No security vulnerabilities found"
    exit 0
  fi

  log_error "composer audit failed (install jq to count the advisories)"
  cat "$AUDIT_REPORT"
  exit 1
fi

if ! jq -e '.advisories' "$AUDIT_REPORT" > /dev/null 2>&1; then
  log_error "composer audit could not run: its report has no list of advisories"
  cat "$AUDIT_REPORT"
  exit 1
fi

VULNERABILITIES=$(jq '.advisories | length' "$AUDIT_REPORT")

if [ "$VULNERABILITIES" -gt 0 ]; then
  log_warning "Found $VULNERABILITIES security advisories"
  cat "$AUDIT_REPORT"
  exit 1
fi

log_success "No security vulnerabilities found"
