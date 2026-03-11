#!/usr/bin/env bats
#
# Integration Tests for Quality Check Scripts
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
# CI Quality Script Tests
# ============================================================================

@test "ci-quality.sh uses error counter pattern" {
    # Check that script sources config.sh
    run grep -q "source.*config.sh" "${SCRIPT_DIR}/ci-quality.sh"

    [ "$status" -eq 0 ]
}

@test "ci-quality.sh initializes error counter" {
    run grep -q "init_error_counter" "${SCRIPT_DIR}/ci-quality.sh"

    [ "$status" -eq 0 ]
}

@test "ci-quality.sh uses run_check pattern" {
    run grep -q "run_check" "${SCRIPT_DIR}/ci-quality.sh"

    [ "$status" -eq 0 ]
}

@test "ci-quality.sh exits with error count" {
    run grep -q "exit_with_error_count" "${SCRIPT_DIR}/ci-quality.sh"

    [ "$status" -eq 0 ]
}

# ============================================================================
# Setup Package Script Tests
# ============================================================================

@test "setup-package.sh handles file installation" {
    run grep -qE "(cp |copy)" "${SCRIPT_DIR}/setup-package.sh"

    [ "$status" -eq 0 ]
}

@test "setup-package.sh has correct shebang" {
    run head -n1 "${SCRIPT_DIR}/setup-package.sh"

    [[ "$output" =~ "#!/usr/bin/env bash" ]]
}

# ============================================================================
# Rector Scripts Standardization Tests
# ============================================================================

@test "rector-fix.sh uses require_package" {
    run grep -q "source.*config.sh" "${SCRIPT_DIR}/rector-fix.sh"
    [ "$status" -eq 0 ]
}

@test "rector-check.sh uses require_package" {
    run grep -q "source.*config.sh" "${SCRIPT_DIR}/rector-check.sh"
    [ "$status" -eq 0 ]
}
