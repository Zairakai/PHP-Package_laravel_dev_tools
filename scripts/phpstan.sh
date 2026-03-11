#!/usr/bin/env bash
set -euo pipefail

# Load central configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "$SCRIPT_DIR/config.sh"

log_header "PHPStan Static Analysis"

# Check required dependencies
require_package "phpstan/phpstan" \
    "${PROJECT_ROOT}/${PHPSTAN_BIN}" \
    "static analysis scripts (phpstan.sh)" || exit 1

# Check Larastan only if the resolved config requires it
if grep -q "larastan" "${PHPSTAN_CONFIG:-}" 2>/dev/null; then
    require_package "larastan/larastan" \
        "${PROJECT_ROOT}/vendor/larastan/larastan/extension.neon" \
        "PHPStan Laravel support" || exit 1
fi

# Resolve or generate configuration
TMP_CONFIG_GENERATED=false

if [[ -z "$PHPSTAN_CONFIG" ]]; then
    # No config found anywhere - generate a temporary one based on project type
    TMP_CONFIG="${PROJECT_ROOT}/.phpstan.tmp.neon"
    PROJECT_TYPE="$(detect_project_type)"

    log_info "No PHPStan config found, generating temporary config for project type: ${PROJECT_TYPE}"

    # Determine source paths based on project type
    if [[ "$PROJECT_TYPE" == "laravel" ]]; then
        ANALYSE_PATHS="app/"
        # Add optional Laravel paths if they exist
        [[ -d "${PROJECT_ROOT}/database" ]] && ANALYSE_PATHS="${ANALYSE_PATHS}\n        - database/"
        [[ -d "${PROJECT_ROOT}/routes" ]]   && ANALYSE_PATHS="${ANALYSE_PATHS}\n        - routes/"
    else
        ANALYSE_PATHS="src/"
    fi

    # Generate baseline include if baseline file exists
    BASELINE_INCLUDE=""
    if [[ -f "${PROJECT_ROOT}/${PHPSTAN_BASELINE}" ]]; then
        BASELINE_INCLUDE="\n    baseline: ${PHPSTAN_BASELINE}"
    fi

    # Write temporary config
    printf "includes:\n    - vendor/zairakai/laravel-dev-tools/config/base.neon\n\nparameters:${BASELINE_INCLUDE}\n    paths:\n        - %s\n" \
        "${ANALYSE_PATHS}" > "$TMP_CONFIG"

    PHPSTAN_CONFIG="$TMP_CONFIG"
    TMP_CONFIG_GENERATED=true
    log_step "Temporary config: .phpstan.tmp.neon"
else
    log_step "Configuration: ${PHPSTAN_CONFIG#"$PROJECT_ROOT"/}"
fi

log_step "Memory limit: $PHPSTAN_MEMORY"

# Cleanup temporary config on exit (if generated)
cleanup_phpstan_tmp() {
    if [[ "$TMP_CONFIG_GENERATED" == "true" ]] && [[ -f "${PROJECT_ROOT}/.phpstan.tmp.neon" ]]; then
        rm -f "${PROJECT_ROOT}/.phpstan.tmp.neon"
    fi
}
trap cleanup_phpstan_tmp EXIT INT TERM

# Ensure tmp directory exists
ensure_dir "$PHPSTAN_TMP_DIR"

# Generate baseline if requested
if [[ "${GENERATE_BASELINE:-false}" == "true" ]]; then
    log_info "Generating PHPStan baseline"

    ensure_dir "$(dirname "$PHPSTAN_BASELINE")"

    "$PHPSTAN_BIN" analyse \
        --memory-limit="$PHPSTAN_MEMORY" \
        --configuration="$PHPSTAN_CONFIG" \
        --generate-baseline="$PHPSTAN_BASELINE" \
        --allow-empty-baseline

    log_success "Baseline generated: $PHPSTAN_BASELINE"
    exit 0
fi

# Run analysis
"$PHPSTAN_BIN" analyse --memory-limit="$PHPSTAN_MEMORY" --configuration="$PHPSTAN_CONFIG"

log_success "Static analysis passed"
