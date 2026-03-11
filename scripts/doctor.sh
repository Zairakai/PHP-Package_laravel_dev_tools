#!/usr/bin/env bash
set -euo pipefail

# Load central configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "$SCRIPT_DIR/config.sh"

log_header "Environment Diagnostics"

echo -e "${CYAN}PHP Version:${NC}"
php -v | head -n1

echo ""
echo -e "${CYAN}Composer Version:${NC}"
composer --version

echo ""
echo -e "${CYAN}Available Tools:${NC}"
[[ -f "$PHPUNIT_BIN" ]] && echo "  ✅ PHPUnit: $($PHPUNIT_BIN --version)" || echo "  ❌ PHPUnit not found"
[[ -f "$PHPSTAN_BIN" ]] && echo "  ✅ PHPStan: $($PHPSTAN_BIN --version 2>/dev/null | head -n1)" || echo "  ❌ PHPStan not found"
[[ -f "$PINT_BIN" ]] && echo "  ✅ Pint: installed" || echo "  ❌ Pint not found"
[[ -f "$RECTOR_BIN" ]] && echo "  ✅ Rector: $($RECTOR_BIN --version 2>/dev/null | head -n1)" || echo "  ❌ Rector not found"
[[ -f "$PROJECT_ROOT/vendor/bin/phpinsights" ]] && echo "  ✅ PHPInsights: installed" || echo "  ❌ PHPInsights not found"
[[ -f "$PHPMETRICS_BIN" ]] && echo "  ✅ PHPMetrics: installed" || echo "  ❌ PHPMetrics not found"

echo ""
echo -e "${CYAN}Coverage Drivers:${NC}"
if has_coverage_driver; then
    DRIVER=$(php -m 2>/dev/null | grep -E '(xdebug|pcov)' | head -n1)
    echo "  ✅ $DRIVER available"
else
    echo "  ⚠️  No coverage driver (Xdebug/PCOV)"
fi

echo ""
echo -e "${CYAN}Git Repository:${NC}"
if [[ "$HAS_GIT" == "true" ]]; then
    echo "  ✅ Git repository detected"
    BRANCH=$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo "unknown")
    echo "  → Branch: $BRANCH"
else
    echo "  ⚠️  Not a Git repository"
fi

echo ""
echo -e "${CYAN}CI/CD Detection:${NC}"
if [[ "$IS_CI" == "true" ]]; then
    echo "  ✅ Running in CI environment"
    [[ "$IS_GITLAB_CI" == "true" ]] && echo "  → Platform: GitLab CI"
    [[ "$IS_GITHUB_ACTIONS" == "true" ]] && echo "  → Platform: GitHub Actions"
else
    echo "  ℹ️  Local development environment"
fi

echo ""
echo -e "${CYAN}Configuration Files (resolved):${NC}"

# PHPUnit
if [[ -n "$PHPUNIT_CONFIG" ]] && [[ -f "$PHPUNIT_CONFIG" ]]; then
    RELATIVE_PATH="${PHPUNIT_CONFIG#"$PROJECT_ROOT"/}"
    echo "  ✅ PHPUnit: $RELATIVE_PATH"
else
    echo "  ⚠️  PHPUnit: No configuration found"
fi

# PHPStan
if [[ -n "$PHPSTAN_CONFIG" ]] && [[ -f "$PHPSTAN_CONFIG" ]]; then
    RELATIVE_PATH="${PHPSTAN_CONFIG#"$PROJECT_ROOT"/}"
    echo "  ✅ PHPStan: $RELATIVE_PATH"
else
    echo "  ⚠️  PHPStan: No configuration found"
fi

# Pint
if [[ -n "$PINT_CONFIG" ]] && [[ -f "$PINT_CONFIG" ]]; then
    RELATIVE_PATH="${PINT_CONFIG#"$PROJECT_ROOT"/}"
    echo "  ✅ Pint: $RELATIVE_PATH"
else
    echo "  ℹ️  Pint: Using defaults (no config file)"
fi

# Rector
if [[ -n "$RECTOR_CONFIG" ]] && [[ -f "$RECTOR_CONFIG" ]]; then
    RELATIVE_PATH="${RECTOR_CONFIG#"$PROJECT_ROOT"/}"
    echo "  ✅ Rector: $RELATIVE_PATH"
else
    echo "  ⚠️  Rector: No configuration found"
fi

# PHPMetrics (optional - works without config)
if [[ -n "${PHPMETRICS_CONFIG:-}" ]] && [[ -f "$PHPMETRICS_CONFIG" ]]; then
    RELATIVE_PATH="${PHPMETRICS_CONFIG#"$PROJECT_ROOT"/}"
    echo "  ✅ PHPMetrics: $RELATIVE_PATH"
else
    echo "  ℹ️  PHPMetrics: Using defaults (no config file)"
fi

# PHPInsights
if [[ -n "$INSIGHTS_CONFIG" ]] && [[ -f "$INSIGHTS_CONFIG" ]]; then
    RELATIVE_PATH="${INSIGHTS_CONFIG#"$PROJECT_ROOT"/}"
    echo "  ✅ PHPInsights: $RELATIVE_PATH"
else
    echo "  ℹ️  PHPInsights: Using defaults (no config file)"
fi

# Markdownlint
if [[ -n "$MARKDOWNLINT_CONFIG" ]] && [[ -f "$MARKDOWNLINT_CONFIG" ]]; then
    RELATIVE_PATH="${MARKDOWNLINT_CONFIG#"$PROJECT_ROOT"/}"
    echo "  ✅ Markdownlint: $RELATIVE_PATH"
else
    echo "  ⚠️  Markdownlint: No configuration found"
fi

echo ""
# NOTE: double quotes intentional around the section header variable expansion
echo -e "${CYAN}Configuration Fallback Order:${NC}"
echo -e "  ${BLUE}PHPUnit / Pint / Rector / Markdownlint / PHPInsights:${NC}"
echo "    1. {file}                            (project root override)"
echo "    2. config/dev-tools/{file}           (published — can extend bundled default)"
echo "    3. config/{file}                     (legacy or intermediate)"
echo "    4. vendor/zairakai/laravel-dev-tools/config/{file}  (bundled default)"
echo ""
echo -e "  ${BLUE}PHPStan:${NC}"
echo "    Tries phpstan-local.neon first (gitignored local override),"
echo "    then phpstan.neon (committed config)."
echo "    Each name follows the 4-level cascade above."
echo "    Vendor fallback: config/library.neon or config/app.neon (type-aware)"
echo ""
echo -e "  ${BLUE}PHPMetrics:${NC}"
echo "    No config file required — uses defaults"

echo ""
echo -e "${CYAN}Build Directories:${NC}"
echo "  → Build:        $BUILD_DIR"
echo "  → Coverage:     $COVERAGE_HTML_DIR"
echo "  → PHPStan tmp:  $PHPSTAN_TMP_DIR"

echo ""
log_success "Diagnostics complete"
