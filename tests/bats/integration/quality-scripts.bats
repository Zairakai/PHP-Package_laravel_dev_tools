#!/usr/bin/env bats
#
# Integration Tests for Quality Scripts
#
# Tests the quality check scripts behavior including:
# - doctor.sh: Environment diagnostics
# - phpstan.sh: Static analysis
# - cs-check.sh / cs-fix.sh: Code style
# - test.sh: PHPUnit execution
#
# These tests focus on script structure, validation, and error handling
# rather than the actual tools they wrap.
#

# Load test helpers
load '../helpers/test_helper'

setup() {
    setup_test_env
}

teardown() {
    teardown_test_env
}

# ============================================================================
# doctor.sh Tests
# ============================================================================

@test "doctor.sh sources config.sh" {
    run grep -q "source.*config.sh" "${SCRIPT_DIR}/doctor.sh"

    [ "$status" -eq 0 ]
}

@test "doctor.sh displays environment information" {
    # doctor.sh should be safe to run without dependencies
    run bash "${SCRIPT_DIR}/doctor.sh"

    # Should display headers and info (even if tools missing)
    [[ "$output" =~ "PHP Version" ]] || [[ "$output" =~ "Environment" ]]
}

@test "doctor.sh shows available tools" {
    run bash "${SCRIPT_DIR}/doctor.sh"

    # Should check for common tools
    [[ "$output" =~ "PHPUnit" ]] || [[ "$output" =~ "Tools" ]]
    [[ "$output" =~ "PHPStan" ]] || [[ "$output" =~ "Tools" ]]
}

@test "doctor.sh detects git repository" {
    cd "${TEST_TEMP_DIR}"

    run bash "${SCRIPT_DIR}/doctor.sh"

    [[ "$output" =~ "Git" ]] || [[ "$output" =~ "repository" ]]
}

@test "doctor.sh shows configuration resolution" {
    run bash "${SCRIPT_DIR}/doctor.sh"

    # Should display config file detection
    [[ "$output" =~ "Configuration" ]] || [[ "$output" =~ "PHPUnit" ]]
}

# ============================================================================
# phpstan.sh Tests
# ============================================================================

@test "phpstan.sh sources config.sh" {
    run grep -q "source.*config.sh" "${SCRIPT_DIR}/phpstan.sh"

    [ "$status" -eq 0 ]
}

@test "phpstan.sh uses require_package for validation" {
    run grep -q "require_package" "${SCRIPT_DIR}/phpstan.sh"

    [ "$status" -eq 0 ]
}

@test "phpstan.sh validates required dependencies" {
    # Should check for both phpstan and larastan
    run grep -E "(phpstan/phpstan|larastan/larastan)" "${SCRIPT_DIR}/phpstan.sh"

    [ "$status" -eq 0 ]
}

@test "phpstan.sh checks for config file" {
    run grep -q "PHPSTAN_CONFIG" "${SCRIPT_DIR}/phpstan.sh"

    [ "$status" -eq 0 ]
}

@test "phpstan.sh supports baseline generation" {
    run grep -q "GENERATE_BASELINE" "${SCRIPT_DIR}/phpstan.sh"

    [ "$status" -eq 0 ]
}

@test "phpstan.sh uses memory limit from config" {
    run grep -q "PHPSTAN_MEMORY" "${SCRIPT_DIR}/phpstan.sh"

    [ "$status" -eq 0 ]
}

# ============================================================================
# cs-check.sh Tests
# ============================================================================

@test "cs-check.sh sources config.sh (standardization)" {
    run grep -q "source.*config.sh" "${SCRIPT_DIR}/cs-check.sh"

    [ "$status" -eq 0 ]
}

@test "cs-check.sh uses require_package for validation" {
    run grep -q "require_package" "${SCRIPT_DIR}/cs-check.sh"

    [ "$status" -eq 0 ]
}

@test "cs-check.sh checks for Pint config" {
    run grep -q "PINT_CONFIG" "${SCRIPT_DIR}/cs-check.sh"

    [ "$status" -eq 0 ]
}

@test "cs-check.sh detects dirty files when git available" {
    run grep -q "get_dirty_php_files" "${SCRIPT_DIR}/cs-check.sh"

    [ "$status" -eq 0 ]
}

# ============================================================================
# cs-fix.sh Tests
# ============================================================================

@test "cs-fix.sh sources config.sh (standardization)" {
    run grep -q "source.*config.sh" "${SCRIPT_DIR}/cs-fix.sh"

    [ "$status" -eq 0 ]
}

@test "cs-fix.sh uses require_package for validation" {
    run grep -q "require_package" "${SCRIPT_DIR}/cs-fix.sh"

    [ "$status" -eq 0 ]
}

@test "cs-fix.sh uses --dirty flag for git repos" {
    run grep -q "\--dirty" "${SCRIPT_DIR}/cs-fix.sh"

    [ "$status" -eq 0 ]
}

# ============================================================================
# test.sh Tests
# ============================================================================

@test "test.sh sources config.sh" {
    run grep -q "source.*config.sh" "${SCRIPT_DIR}/test.sh"

    [ "$status" -eq 0 ]
}

@test "test.sh validates PHPUnit config exists" {
    run grep -q "PHPUNIT_CONFIG" "${SCRIPT_DIR}/test.sh"

    [ "$status" -eq 0 ]
}

@test "test.sh supports coverage mode" {
    run grep -q "COVERAGE" "${SCRIPT_DIR}/test.sh"

    [ "$status" -eq 0 ]
}

@test "test.sh checks for coverage driver" {
    run grep -q "DRIVER" "${SCRIPT_DIR}/test.sh"

    [ "$status" -eq 0 ]
}

@test "test.sh uses CI mode detection" {
    run grep -q "CI" "${SCRIPT_DIR}/test.sh"

    [ "$status" -eq 0 ]
}

# ============================================================================
# markdownlint.sh Tests
# ============================================================================

@test "markdownlint.sh sources config.sh" {
    run grep -q "source.*config.sh" "${SCRIPT_DIR}/markdownlint.sh"

    [ "$status" -eq 0 ]
}

@test "markdownlint.sh has proper shebang" {
    run head -n1 "${SCRIPT_DIR}/markdownlint.sh"

    [[ "$output" =~ "#!/usr/bin/env bash" ]]
}

# ============================================================================
# Standardization Tests (All Quality Scripts)
# ============================================================================

@test "cs-check.sh has correct shebang" {
    run head -n1 "${SCRIPT_DIR}/cs-check.sh"
    [[ "$output" =~ "#!/usr/bin/env bash" ]]
}

@test "cs-fix.sh has correct shebang" {
    run head -n1 "${SCRIPT_DIR}/cs-fix.sh"
    [[ "$output" =~ "#!/usr/bin/env bash" ]]
}

@test "phpstan.sh has correct shebang" {
    run head -n1 "${SCRIPT_DIR}/phpstan.sh"
    [[ "$output" =~ "#!/usr/bin/env bash" ]]
}

@test "test.sh has correct shebang" {
    run head -n1 "${SCRIPT_DIR}/test.sh"
    [[ "$output" =~ "#!/usr/bin/env bash" ]]
}

@test "doctor.sh has correct shebang" {
    run head -n1 "${SCRIPT_DIR}/doctor.sh"
    [[ "$output" =~ "#!/usr/bin/env bash" ]]
}

@test "cs-check.sh uses set -euo pipefail" {
    run grep -q "set -euo pipefail" "${SCRIPT_DIR}/cs-check.sh"
    [ "$status" -eq 0 ]
}

@test "cs-fix.sh uses set -euo pipefail" {
    run grep -q "set -euo pipefail" "${SCRIPT_DIR}/cs-fix.sh"
    [ "$status" -eq 0 ]
}

@test "phpstan.sh uses set -euo pipefail" {
    run grep -q "set -euo pipefail" "${SCRIPT_DIR}/phpstan.sh"
    [ "$status" -eq 0 ]
}

@test "test.sh uses set -euo pipefail" {
    run grep -q "set -euo pipefail" "${SCRIPT_DIR}/test.sh"
    [ "$status" -eq 0 ]
}

@test "doctor.sh uses set -euo pipefail" {
    run grep -q "set -euo pipefail" "${SCRIPT_DIR}/doctor.sh"
    [ "$status" -eq 0 ]
}

@test "cs-check.sh sources config.sh" {
    run grep -q "source.*config.sh" "${SCRIPT_DIR}/cs-check.sh"
    [ "$status" -eq 0 ]
}

@test "cs-fix.sh sources config.sh" {
    run grep -q "source.*config.sh" "${SCRIPT_DIR}/cs-fix.sh"
    [ "$status" -eq 0 ]
}

@test "phpstan.sh sources config.sh (standardization)" {
    run grep -q "source.*config.sh" "${SCRIPT_DIR}/phpstan.sh"
    [ "$status" -eq 0 ]
}

@test "test.sh sources config.sh (standardization)" {
    run grep -q "source.*config.sh" "${SCRIPT_DIR}/test.sh"
    [ "$status" -eq 0 ]
}

@test "doctor.sh sources config.sh (standardization)" {
    run grep -q "source.*config.sh" "${SCRIPT_DIR}/doctor.sh"
    [ "$status" -eq 0 ]
}

@test "markdownlint.sh sources config.sh (standardization)" {
    run grep -q "source.*config.sh" "${SCRIPT_DIR}/markdownlint.sh"
    [ "$status" -eq 0 ]
}
