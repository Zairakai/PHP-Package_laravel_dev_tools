#!/usr/bin/env bash
set -euo pipefail

# Load central configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "$SCRIPT_DIR/config.sh"

log_header "PHPInsights Code Quality Analysis"

# Check if PHPInsights is installed (optional package - exit gracefully if not)
PHPINSIGHTS_BIN="$PROJECT_ROOT/vendor/bin/phpinsights"
if ! check_optional_package "nunomaduro/phpinsights" "$PHPINSIGHTS_BIN" "PHPInsights code quality analysis"; then
    exit 0
fi

# Detect context: Laravel app vs Package
IS_LARAVEL_APP=false
if [[ -f "$PROJECT_ROOT/artisan" ]] && grep -q '"laravel/framework"' "$PROJECT_ROOT/composer.json" 2>/dev/null; then
    IS_LARAVEL_APP=true
    log_step "Detected Laravel application context"
else
    log_step "Detected package context (non-Laravel app)"
fi

# Parse arguments
# NOTE: --fix is intentionally NOT supported.
# PHP Insights uses PhpCsFixer internally and would overwrite Pint's formatting.
# Pint is the sole code formatter in this project. Run `make pint-fix` instead.
EXTRA_ARGS=()

for arg in "$@"; do
    case $arg in
        --fix)
            log_error "--fix is not supported for PHPInsights in this project."
            echo ""
            echo "  PHP Insights uses PhpCsFixer internally which conflicts with Pint."
            echo "  Use 'make pint-fix' to fix code style."
            echo "  Use 'make rector-fix' to fix code modernization."
            echo ""
            exit 1
            ;;
        *)
            EXTRA_ARGS+=("$arg")
            ;;
    esac
done

# Build PHPInsights command (analysis only — no --fix)
INSIGHTS_ARGS=()

# Run PHPInsights
cd "$PROJECT_ROOT"

# Execute PHPInsights based on context
if [[ "$IS_LARAVEL_APP" == "true" ]]; then
    # Laravel app: use artisan command
    if php artisan insights --no-interaction "${INSIGHTS_ARGS[@]}"; then
        log_success "PHPInsights analysis passed"
        exit 0
    else
        log_error "PHPInsights found quality issues"
        echo ""
        echo "Run 'make insights-fix' to apply automatic fixes"
        exit 1
    fi
else
    # Package: use direct binary with src/ directory
    PACKAGE_ARGS=("analyse" "src/" "--no-interaction" "--config-path=$INSIGHTS_CONFIG")

    # Add extra arguments
    if [[ ${#EXTRA_ARGS[@]} -gt 0 ]]; then
        PACKAGE_ARGS+=("${EXTRA_ARGS[@]}")
    fi

    if "$PHPINSIGHTS_BIN" "${PACKAGE_ARGS[@]}"; then
        log_success "PHPInsights analysis passed"
        exit 0
    else
        log_error "PHPInsights found quality issues"
        echo ""
        echo "Run 'make insights-fix' to apply automatic fixes"
        exit 1
    fi
fi
