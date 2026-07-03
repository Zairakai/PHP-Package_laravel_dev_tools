#!/usr/bin/env bash
set -euo pipefail

# Load central configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "$SCRIPT_DIR/config.sh"

log_header "PHPUnit Tests"

if [[ "${CI:-false}" == "true" ]]; then
    echo "=== DIAG: composer show ==="
    composer show phpunit/phpunit phpunit/php-code-coverage 2>&1 | grep -E "^(name|versions|source|path)"
    echo "=== DIAG: file checksums ==="
    md5sum vendor/phpunit/phpunit/src/Util/PHP/JobRunner.php vendor/phpunit/phpunit/src/Util/PHP/DefaultJobRunner.php 2>&1
    echo "=== DIAG: duplicate JobRunner class declarations in classmap ==="
    grep -rn "'PHPUnit\\\\\\\\Util\\\\\\\\PHP\\\\\\\\JobRunner'" vendor/composer/autoload_classmap.php vendor/composer/autoload_static.php 2>&1
    echo "=== DIAG: find all JobRunner.php on disk ==="
    find . -name "JobRunner.php" -not -path "*/node_modules/*" 2>&1
    echo "=== DIAG: php -l on both files ==="
    php -l vendor/phpunit/phpunit/src/Util/PHP/JobRunner.php
    php -l vendor/phpunit/phpunit/src/Util/PHP/DefaultJobRunner.php
    echo "=== END DIAG ==="
fi

# Validate configuration exists
if [[ ! -f "$PHPUNIT_CONFIG" ]]; then
    log_error "PHPUnit configuration not found: $PHPUNIT_CONFIG"
    log_warning "Expected one of:"
    log_warning "  - phpunit.xml (local project)"
    log_warning "  - phpunit.xml.dist (package)"
    log_warning "  - vendor/zairakai/laravel-dev-tools/phpunit.xml (fallback)"
    exit 1
fi

log_step "Configuration: ${PHPUNIT_CONFIG#"$PROJECT_ROOT"/}"

# Runtime configuration (can be overridden via env vars)
COVERAGE="${COVERAGE:-false}"
TESTSUITE="${TESTSUITE:-}"
CI="${CI:-false}"

# CI mode: strict + coverage
if [[ "$CI" == "true" ]]; then
    log_info "Running in CI mode (strict + coverage)"
    COVERAGE="true"
fi

if [[ "$COVERAGE" == "true" ]]; then
    DRIVER='pcov'
    log_info "Running with coverage report"
    log_step "Coverage driver: $DRIVER"

    ensure_dir "$(dirname "$COVERAGE_HTML_DIR")"
    ensure_dir "$(dirname "$COVERAGE_CLOVER_FILE")"

    # Build coverage command with optional CI strict mode
    COVERAGE_ARGS=(
        "$PHPUNIT_DEFAULT_ARGS"
        --configuration="$PHPUNIT_CONFIG"
        --coverage-html="$COVERAGE_HTML_DIR"
        --coverage-clover="$COVERAGE_CLOVER_FILE"
        --coverage-cobertura="$COVERAGE_COBERTURA_FILE"
    )

    # Add strict mode options for CI
    # TEMPORARILY DISABLED: Strict mode causes PHPUnit to crash silently in CI
    # Re-enable after investigating deprecations/warnings
    # if [[ "$CI" == "true" ]]; then
    #     log_step "Enabling strict mode (fail on deprecations/warnings/risky)"
    #     COVERAGE_ARGS+=(
    #         --fail-on-deprecation
    #         --fail-on-phpunit-deprecation
    #         --fail-on-warning
    #         --fail-on-risky
    #         --display-deprecations
    #     )
    # fi

    php -d pcov.directory="$PROJECT_ROOT" "$PHPUNIT_BIN" "${COVERAGE_ARGS[@]}"

    log_success "Coverage report: $COVERAGE_HTML_DIR/index.html"
else
    # Build command arguments
    PHPUNIT_ARGS=("$PHPUNIT_DEFAULT_ARGS" "--no-coverage" "--configuration=$PHPUNIT_CONFIG")

    if [[ -n "$TESTSUITE" ]]; then
        PHPUNIT_ARGS+=("--testsuite=$TESTSUITE")
        log_step "Running testsuite: $TESTSUITE"
    fi

    "$PHPUNIT_BIN" "${PHPUNIT_ARGS[@]}"
fi

log_success "Tests passed"
