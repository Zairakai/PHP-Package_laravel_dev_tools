#!/usr/bin/env bats
#
# Unit Tests for config.sh Helper Functions
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
# Pattern 1: Error Counter Tests
# ============================================================================

@test "init_error_counter initializes counter to 0" {
    init_error_counter

    result=$(get_error_count)

    [ "$result" -eq 0 ]
}

@test "increment_error_counter increases count" {
    init_error_counter
    increment_error_counter
    increment_error_counter

    result=$(get_error_count)

    [ "$result" -eq 2 ]
}

@test "exit_with_error_count returns 0 when no errors" {
    init_error_counter

    run exit_with_error_count "Test Checks"

    [ "$status" -eq 0 ]
    [[ "$output" =~ "All Test Checks Passed" ]]
}

@test "exit_with_error_count returns 1 when errors exist" {
    init_error_counter
    increment_error_counter
    increment_error_counter

    run exit_with_error_count "Test Checks"

    [ "$status" -eq 1 ]
    [[ "$output" =~ "2 Test Checks Failed" ]]
}

@test "run_check increments counter on failure" {
    init_error_counter

    run_check "Failing Check" "false" || true

    result=$(get_error_count)
    [ "$result" -eq 1 ]
}

@test "run_check does not increment counter on success" {
    init_error_counter

    run_check "Passing Check" "true"

    result=$(get_error_count)
    [ "$result" -eq 0 ]
}

# ============================================================================
# Pattern 2: file_hash Tests
# ============================================================================

@test "file_hash returns non-empty hash for existing file" {
    local file="${TEST_TEMP_DIR}/hashme.txt"
    create_test_file "$file" "some content"

    result=$(file_hash "$file")

    [ -n "$result" ]
}

@test "file_hash returns same hash for identical content" {
    local file1="${TEST_TEMP_DIR}/a.txt"
    local file2="${TEST_TEMP_DIR}/b.txt"
    create_test_file "$file1" "identical content"
    create_test_file "$file2" "identical content"

    hash1=$(file_hash "$file1")
    hash2=$(file_hash "$file2")

    [ "$hash1" = "$hash2" ]
}

@test "file_hash returns different hash for different content" {
    local file1="${TEST_TEMP_DIR}/a.txt"
    local file2="${TEST_TEMP_DIR}/b.txt"
    create_test_file "$file1" "content A"
    create_test_file "$file2" "content B"

    hash1=$(file_hash "$file1")
    hash2=$(file_hash "$file2")

    [ "$hash1" != "$hash2" ]
}

# ============================================================================
# Helper Function Tests
# ============================================================================

@test "command_exists returns 0 for existing command" {
    run command_exists "bash"

    [ "$status" -eq 0 ]
}

@test "command_exists returns 1 for non-existing command" {
    run command_exists "nonexistent_command_xyz"

    [ "$status" -eq 1 ]
}

@test "ensure_dir creates directory if not exists" {
    local test_dir="${TEST_TEMP_DIR}/new_dir"

    ensure_dir "$test_dir"

    [ -d "$test_dir" ]
}

@test "ensure_dir does not fail if directory exists" {
    local test_dir="${TEST_TEMP_DIR}/existing_dir"
    mkdir -p "$test_dir"

    run ensure_dir "$test_dir"

    [ "$status" -eq 0 ]
    [ -d "$test_dir" ]
}

@test "get_dirty_php_files returns empty when not a git repo" {
    cd "$TEST_TEMP_DIR"
    export HAS_GIT=false

    run get_dirty_php_files

    [ "$status" -eq 0 ]
    [ -z "$output" ]
}

# ============================================================================
# Config Resolution Tests (4-level cascade)
# ============================================================================

@test "resolve_config returns project root override (level 1)" {
    local OLD_PROJECT_ROOT="$PROJECT_ROOT"
    export PROJECT_ROOT="$TEST_TEMP_DIR"

    create_test_file "${TEST_TEMP_DIR}/tool.json" "{}"
    mkdir -p "${TEST_TEMP_DIR}/config/dev-tools"
    create_test_file "${TEST_TEMP_DIR}/config/dev-tools/tool.json" "{}"

    result=$(resolve_config "tool.json")

    export PROJECT_ROOT="$OLD_PROJECT_ROOT"

    [ "$result" = "${TEST_TEMP_DIR}/tool.json" ]
}

@test "resolve_config returns config/dev-tools/ override (level 2)" {
    local OLD_PROJECT_ROOT="$PROJECT_ROOT"
    export PROJECT_ROOT="$TEST_TEMP_DIR"

    mkdir -p "${TEST_TEMP_DIR}/config/dev-tools"
    create_test_file "${TEST_TEMP_DIR}/config/dev-tools/tool.json" "{}"

    result=$(resolve_config "tool.json")

    export PROJECT_ROOT="$OLD_PROJECT_ROOT"

    [ "$result" = "${TEST_TEMP_DIR}/config/dev-tools/tool.json" ]
}

@test "resolve_config returns config/ fallback (level 3)" {
    local OLD_PROJECT_ROOT="$PROJECT_ROOT"
    export PROJECT_ROOT="$TEST_TEMP_DIR"

    mkdir -p "${TEST_TEMP_DIR}/config"
    create_test_file "${TEST_TEMP_DIR}/config/tool.json" "{}"

    result=$(resolve_config "tool.json")

    export PROJECT_ROOT="$OLD_PROJECT_ROOT"

    [ "$result" = "${TEST_TEMP_DIR}/config/tool.json" ]
}

@test "resolve_config returns vendor fallback (level 4)" {
    local OLD_PROJECT_ROOT="$PROJECT_ROOT"
    export PROJECT_ROOT="$TEST_TEMP_DIR"

    create_test_file "${TEST_TEMP_DIR}/vendor/default.json" "{}"

    result=$(resolve_config "tool.json" "vendor/default.json")

    export PROJECT_ROOT="$OLD_PROJECT_ROOT"

    [ "$result" = "${TEST_TEMP_DIR}/vendor/default.json" ]
}

@test "resolve_config returns empty when no files exist" {
    local OLD_PROJECT_ROOT="$PROJECT_ROOT"
    export PROJECT_ROOT="$TEST_TEMP_DIR"

    result=$(resolve_config "nonexistent.json")

    export PROJECT_ROOT="$OLD_PROJECT_ROOT"

    [ -z "$result" ]
}
