#!/usr/bin/env bash
set -euo pipefail

# Load central configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "$SCRIPT_DIR/config.sh"

log_header "Fast Quality Checks (Pint + PHPStan + Markdownlint)"

# Initialize error counter (Pattern 1)
init_error_counter

# Run checks with automatic error tracking
run_check "Code Style (Pint)" "bash '$SCRIPT_DIR/cs-check.sh'" || true
echo ""

run_check "Static Analysis (PHPStan)" "bash '$SCRIPT_DIR/phpstan.sh'" || true
echo ""

run_check "Documentation Linting (Markdownlint)" "bash '$SCRIPT_DIR/markdownlint.sh'" || true
echo ""

# Exit with final result
if exit_with_error_count "Quality Checks"; then
    exit 0
else
    exit 1
fi
