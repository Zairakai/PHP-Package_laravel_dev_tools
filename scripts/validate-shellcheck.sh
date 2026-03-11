#!/usr/bin/env bash
# scripts/validate-shellcheck.sh
# Runs ShellCheck validation on all shell scripts (100% compliance required)
#
# Usage:
#   bash scripts/validate-shellcheck.sh
#
# Environment Variables:
#   SHELLCHECK_SEVERITY - Severity level (default: warning)
#   SHELLCHECK_FORMAT   - Output format (default: gcc)

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "${SCRIPT_DIR}/config.sh"

SEVERITY="${SHELLCHECK_SEVERITY:-warning}"
FORMAT="${SHELLCHECK_FORMAT:-gcc}"

log_header "Running ShellCheck Validation"
log_info "Severity: ${SEVERITY}"
log_info "Format: ${FORMAT}"

if ! command_exists "shellcheck"; then
    log_error "ShellCheck not found. Please install shellcheck."
    log_info "  Alpine: apk add shellcheck"
    log_info "  Debian/Ubuntu: apt-get install shellcheck"
    log_info "  macOS: brew install shellcheck"
    exit 1
fi

SHELLCHECK_VERSION="$(shellcheck --version | grep "version:" | awk '{print $2}')"
log_info "ShellCheck version: ${SHELLCHECK_VERSION}"

log_info "→ Finding shell scripts…"

mapfile -t SHELL_SCRIPTS < <(find . \( -name "*.sh" -o -name "*.bats" -o -path "./.git-hooks/*" \) -type f ! -path "*/vendor/*" ! -path "*/node_modules/*")

SCRIPT_COUNT=${#SHELL_SCRIPTS[@]}
log_info "Found ${SCRIPT_COUNT} shell scripts"

if [[ ${SCRIPT_COUNT} -eq 0 ]]; then
    log_warning "No shell scripts found"
    exit 0
fi

log_info "→ Running ShellCheck validation…"

FAILED=0
TOTAL=0

for script in "${SHELL_SCRIPTS[@]}"; do
    TOTAL=$((TOTAL + 1))

    if shellcheck --severity="${SEVERITY}" --format="${FORMAT}" "${script}"; then
        :
    else
        log_error "  ✗ ${script} — ShellCheck failed"
        FAILED=$((FAILED + 1))
    fi
done

log_header "ShellCheck Validation Summary"

if [[ ${FAILED} -eq 0 ]]; then
    log_success "All ${TOTAL} scripts passed ShellCheck validation"
    exit 0
else
    log_error "${FAILED}/${TOTAL} scripts failed ShellCheck validation"
    exit 1
fi
