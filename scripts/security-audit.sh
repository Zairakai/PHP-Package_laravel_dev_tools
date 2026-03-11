#!/usr/bin/env bash
set -euo pipefail

# Load central configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "$SCRIPT_DIR/config.sh"

log_header "Security Audit"

# Run composer audit and output JSON report
AUDIT_REPORT="${BUILD_DIR}/logs/audit-report.json"
ensure_dir "$(dirname "$AUDIT_REPORT")"

log_step "Running composer audit..."

composer audit --format=json | tee "$AUDIT_REPORT" || true

VULNERABILITIES=$(jq '.advisories | length' "$AUDIT_REPORT" 2>/dev/null || echo "0")

if [ "$VULNERABILITIES" -gt 0 ]; then
    log_warning "Found $VULNERABILITIES security advisories"
    cat "$AUDIT_REPORT"
    exit 1
else
    log_success "No security vulnerabilities found"
fi
