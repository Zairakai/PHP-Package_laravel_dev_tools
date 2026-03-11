#!/usr/bin/env bash
set -euo pipefail

# Load central configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "$SCRIPT_DIR/config.sh"

log_header "Enlightn Security & Performance Analysis"

# Check if Enlightn is installed (optional package - exit gracefully if not)
# Enlightn doesn't have a binary, check composer.json instead
if ! grep -q '"enlightn/enlightn"' "$PROJECT_ROOT/composer.json" 2>/dev/null; then
    log_info "Package 'enlightn/enlightn' is not installed"
    echo ""
    echo -e "${YELLOW}Enlightn security & performance analysis requires this package.${NC}"
    echo -e "${CYAN}Install it with:${NC}"
    echo -e "  ${GREEN}composer require --dev enlightn/enlightn${NC}"
    echo ""
    log_warning "Skipping Enlightn (package not installed)"
    exit 0
fi

# Detect context: Laravel app vs Package
# Enlightn requires a full Laravel application environment
if [[ ! -f "$PROJECT_ROOT/artisan" ]] || ! grep -q '"laravel/framework"' "$PROJECT_ROOT/composer.json" 2>/dev/null; then
    log_step "Detected package context (non-Laravel app)"
    log_warning "Enlightn requires a full Laravel application environment"
    log_warning "This tool is designed for Laravel apps, not isolated packages"
    log_warning ""
    log_warning "The Enlightn integration in this package allows applications that"
    log_warning "install this package to use 'make enlightn' and benefit from the"
    log_warning "published configuration (config/enlightn.php)."
    log_warning ""
    log_warning "To use Enlightn, install this package in a Laravel application and"
    log_warning "run 'php artisan dev-tools:publish' followed by 'make enlightn'."
    exit 0
fi

log_step "Detected Laravel application context"

# Parse arguments
CI_MODE=false
EXTRA_ARGS=()

for arg in "$@"; do
    case $arg in
        --ci)
            CI_MODE=true
            ;;
        *)
            EXTRA_ARGS+=("$arg")
            ;;
    esac
done

# Build Enlightn command
ENLIGHTN_ARGS=()

if [[ "$CI_MODE" == "true" ]]; then
    log_step "Running Enlightn in CI mode (strict)..."
    ENLIGHTN_ARGS+=("--ci")
else
    log_step "Running Enlightn security and performance analysis..."
fi

# Add extra arguments
if [[ ${#EXTRA_ARGS[@]} -gt 0 ]]; then
    ENLIGHTN_ARGS+=("${EXTRA_ARGS[@]}")
fi

# Run Enlightn
cd "$PROJECT_ROOT"

# Laravel app: use artisan command
if php artisan enlightn "${ENLIGHTN_ARGS[@]}"; then
    log_success "Enlightn analysis passed"
    exit 0
else
    log_error "Enlightn found security or performance issues"
    echo ""
    echo "Run 'php artisan enlightn:baseline' to ignore specific issues"
    exit 1
fi
