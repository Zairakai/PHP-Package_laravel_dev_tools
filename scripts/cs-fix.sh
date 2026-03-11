#!/usr/bin/env bash
set -euo pipefail

# Load central configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "$SCRIPT_DIR/config.sh"

log_header "Code Style Fix (Laravel Pint)"

# Check required dependencies
require_package "laravel/pint" "${PROJECT_ROOT}/${PINT_BIN}" "code style scripts (cs-check.sh, cs-fix.sh)" || exit 1

# Build Pint command with config if available
PINT_ARGS=()

if [[ -n "$PINT_CONFIG" ]]; then
    log_step "Using configuration: ${PINT_CONFIG#"$PROJECT_ROOT"/}"
    PINT_ARGS+=("--config=$PINT_CONFIG")
else
    log_step "Using default Pint configuration"
fi

# Determine what to fix
if [[ "$HAS_GIT" == "true" ]]; then
    # Git available: fix dirty files only
    DIRTY_FILES=$(get_dirty_php_files)

    if [[ -n "$DIRTY_FILES" ]]; then
        FILE_COUNT=$(echo "$DIRTY_FILES" | wc -l | tr -d ' ')
        log_step "Fixing $FILE_COUNT dirty file(s)"

        # Use --dirty flag
        PINT_ARGS+=("--dirty")
    else
        log_warning "No dirty PHP files found"
        log_step "Fixing all files"
    fi
else
    # No git: fix everything
    log_step "Fixing all files"
fi

# Run Pint
"$PINT_BIN" "${PINT_ARGS[@]}"

log_success "Code style fixed"
