#!/usr/bin/env bash
set -euo pipefail

# Load central configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "$SCRIPT_DIR/config.sh"

log_header "PHPMetrics Code Quality Metrics"

# Check if PHPMetrics is installed
if ! command -v "$PHPMETRICS_BIN" &>/dev/null; then
    log_error "PHPMetrics not found. Install with: composer require --dev phpmetrics/phpmetrics"
    exit 1
fi

# Create build directory if not exists
METRICS_DIR="$PROJECT_ROOT/build/phpmetrics"
mkdir -p "$METRICS_DIR"

# Detect source directory based on project type
if [[ -f "$PROJECT_ROOT/artisan" ]] && grep -q '"laravel/framework"' "$PROJECT_ROOT/composer.json" 2>/dev/null; then
    METRICS_SOURCE="app/"
    log_step "Detected Laravel application context (analysing app/)"
else
    METRICS_SOURCE="src/"
    log_step "Detected package context (analysing src/)"
fi

# Build PHPMetrics command
PHPMETRICS_ARGS=(
    "--report-html=$METRICS_DIR"
    "--report-json=$METRICS_DIR/metrics.json"
    "$METRICS_SOURCE"
)

# Run PHPMetrics
log_step "Generating code metrics report..."

if "$PHPMETRICS_BIN" "${PHPMETRICS_ARGS[@]}"; then
    log_success "Metrics report generated successfully"
    log_info "Report location: ${METRICS_DIR#"$PROJECT_ROOT"/}"
    echo ""
    echo "Open in browser: file://$METRICS_DIR/index.html"
    exit 0
else
    log_error "PHPMetrics encountered errors"
    exit 1
fi
