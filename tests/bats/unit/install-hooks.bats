#!/usr/bin/env bats
#
# Unit Tests for install-hooks.sh
#
# Tests the git hooks installation/removal functionality including:
# - Hook installation (all 4 hooks)
# - Symlink creation
# - Backup of existing hooks
# - Hook removal
# - Error handling
#

# Load test helpers
load '../helpers/test_helper'

setup() {
    setup_test_env

    # Create fake git repository for testing
    export TEST_GIT_DIR="${TEST_TEMP_DIR}/test-repo"
    mkdir -p "${TEST_GIT_DIR}/.git/hooks"

    # Create fake vendor structure
    export TEST_VENDOR_DIR="${TEST_TEMP_DIR}/vendor/zairakai/laravel-dev-tools"
    mkdir -p "${TEST_VENDOR_DIR}/stubs/githooks"
    mkdir -p "${TEST_VENDOR_DIR}/scripts"

    # Copy actual hooks for testing
    cp -r "${PROJECT_ROOT}/stubs/githooks"/* "${TEST_VENDOR_DIR}/stubs/githooks/"
    cp "${PROJECT_ROOT}/scripts/config.sh" "${TEST_VENDOR_DIR}/scripts/"
    cp "${PROJECT_ROOT}/scripts/install-hooks.sh" "${TEST_VENDOR_DIR}/scripts/"
}

teardown() {
    teardown_test_env
}

# ============================================================================
# Hook Installation Tests
# ============================================================================

@test "install-hooks detects non-git repository" {
    cd "${TEST_TEMP_DIR}"
    rm -rf .git

    run bash "${TEST_VENDOR_DIR}/scripts/install-hooks.sh"

    [ "$status" -eq 1 ]
    [[ "$output" =~ "Not a git repository" ]]
}

@test "install-hooks script has proper structure" {
    # Verify the script exists and has required components
    run grep -q "HOOKS=(" "${PROJECT_ROOT}/scripts/install-hooks.sh"

    [ "$status" -eq 0 ]

    # Should list all 4 hooks
    run grep -A 5 "HOOKS=(" "${PROJECT_ROOT}/scripts/install-hooks.sh"

    [[ "$output" =~ "commit-msg" ]]
    [[ "$output" =~ "prepare-commit-msg" ]]
    [[ "$output" =~ "pre-commit" ]]
    [[ "$output" =~ "pre-push" ]]
}

@test "install-hooks uses chmod to make hooks executable" {
    run grep -q "chmod +x" "${PROJECT_ROOT}/scripts/install-hooks.sh"

    [ "$status" -eq 0 ]
}

@test "install-hooks has backup logic for existing hooks" {
    run grep -q "backup" "${PROJECT_ROOT}/scripts/install-hooks.sh"

    [ "$status" -eq 0 ]

    # Should use date for unique backups
    run grep -q "date.*%Y%m%d" "${PROJECT_ROOT}/scripts/install-hooks.sh"

    [ "$status" -eq 0 ]
}

@test "install-hooks checks for symlinks before backup" {
    run grep -q "\-L.*target" "${PROJECT_ROOT}/scripts/install-hooks.sh"

    [ "$status" -eq 0 ]
}

# ============================================================================
# Hook Removal Tests
# ============================================================================

@test "install-hooks supports --remove flag" {
    run grep -q "remove_hooks" "${PROJECT_ROOT}/scripts/install-hooks.sh"

    [ "$status" -eq 0 ]

    run grep -E "\-\-remove|\-r" "${PROJECT_ROOT}/scripts/install-hooks.sh"

    [ "$status" -eq 0 ]
}

@test "install-hooks remove only deletes symlinks" {
    # Verify remove logic checks for symlinks (line 134: [[ -L "$target" ]])
    run grep -A 15 "^remove_hooks()" "${PROJECT_ROOT}/scripts/install-hooks.sh"

    [ "$status" -eq 0 ]
    [[ "$output" =~ "-L" ]]
}

# ============================================================================
# Error Handling Tests
# ============================================================================

@test "install-hooks checks if source hooks exist" {
    # Verify script checks if source file exists before linking/copying
    run grep -q 'if.*\-f.*source' "${PROJECT_ROOT}/scripts/install-hooks.sh"

    [ "$status" -eq 0 ]
}

@test "install-hooks provides descriptive output" {
    # Should describe what each hook does
    run grep -i "conventional commits\|ticket\|quality" "${PROJECT_ROOT}/scripts/install-hooks.sh"

    [ "$status" -eq 0 ]
}
