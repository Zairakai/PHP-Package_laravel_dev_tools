#!/usr/bin/env bash
set -euo pipefail

# Load central configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# shellcheck disable=SC1091
source "$SCRIPT_DIR/config.sh"

log_header "Code Style Check (Laravel Pint)"

# Check required dependencies
require_package "laravel/pint" "${PROJECT_ROOT}/${PINT_BIN}" "code style scripts (cs-check.sh, cs-fix.sh)" || exit 1

# Build Pint command with config if available
PINT_ARGS=("--test")

if [[ -n "$PINT_CONFIG" ]]; then
    log_step "Using configuration: ${PINT_CONFIG#"$PROJECT_ROOT"/}"
    PINT_ARGS+=("--config=$PINT_CONFIG")
else
    log_step "Using default Pint configuration"
fi

# Determine what to check
if [[ "$HAS_GIT" == "true" ]] && [[ "$IS_CI" != "true" ]]; then
    # Local development: check dirty files only
    DIRTY_FILES=$(get_dirty_php_files)

    if [[ -n "$DIRTY_FILES" ]]; then
        FILE_COUNT=$(echo "$DIRTY_FILES" | wc -l | tr -d ' ')
        log_step "Checking $FILE_COUNT dirty file(s)"

        # Use --dirty flag (Pint handles git detection)
        PINT_ARGS+=("--dirty")
    else
        log_warning "No dirty PHP files found"
        log_step "Checking all files"
    fi
else
    # CI mode or no git: check everything
    log_step "Checking all files (CI mode)"
fi

# Run Pint
"$PINT_BIN" "${PINT_ARGS[@]}"

log_success "Code style check passed"
