#!/usr/bin/env bash
#
# BATS Test Helpers
# Common utilities for BATS tests
#

# Setup test environment
setup_test_env() {
    # Create temporary test directory
    export TEST_TEMP_DIR="${BATS_TEST_TMPDIR}/zairakai-test-$$"
    mkdir -p "$TEST_TEMP_DIR"

    # Export project root (tests are now in tests/bats/{unit,integration}/)
    export PROJECT_ROOT
    PROJECT_ROOT="$(cd "${BATS_TEST_DIRNAME}/../../.." && pwd)"

    # Export scripts directory
    export SCRIPT_DIR="${PROJECT_ROOT}/scripts"

    # Source config.sh for helpers
    # shellcheck source=../../scripts/config.sh
    source "${SCRIPT_DIR}/config.sh"
}

# Teardown test environment
teardown_test_env() {
    if [[ -n "${TEST_TEMP_DIR:-}" ]] && [[ -d "$TEST_TEMP_DIR" ]]; then
        rm -rf "$TEST_TEMP_DIR"
    fi
}

# Assert file exists
assert_file_exists() {
    local file="$1"

    if [[ ! -f "$file" ]]; then
        echo "ASSERTION FAILED: File does not exist: $file" >&2
        return 1
    fi
}

# Assert file does not exist
assert_file_not_exists() {
    local file="$1"

    if [[ -f "$file" ]]; then
        echo "ASSERTION FAILED: File exists but shouldn't: $file" >&2
        return 1
    fi
}

# Assert directory exists
assert_dir_exists() {
    local dir="$1"

    if [[ ! -d "$dir" ]]; then
        echo "ASSERTION FAILED: Directory does not exist: $dir" >&2
        return 1
    fi
}

# Assert string contains
assert_output_contains() {
    local needle="$1"

    if [[ ! "$output" =~ $needle ]]; then
        echo "ASSERTION FAILED: Output does not contain: $needle" >&2
        echo "Actual output: $output" >&2
        return 1
    fi
}

# Assert string equals
assert_output_equals() {
    local expected="$1"

    if [[ "$output" != "$expected" ]]; then
        echo "ASSERTION FAILED: Output mismatch" >&2
        echo "Expected: $expected" >&2
        echo "Actual:   $output" >&2
        return 1
    fi
}

# Create test file
create_test_file() {
    local filepath="$1"
    local content="${2:-test content}"

    mkdir -p "$(dirname "$filepath")"
    echo "$content" > "$filepath"
}

# Mock command
mock_command() {
    local command_name="$1"
    local mock_output="${2:-}"
    local mock_exit_code="${3:-0}"

    # Create mock in PATH
    local mock_dir="${TEST_TEMP_DIR}/bin"
    mkdir -p "$mock_dir"

    cat > "${mock_dir}/${command_name}" <<EOF
#!/usr/bin/env bash
echo "${mock_output}"
exit ${mock_exit_code}
EOF

    chmod +x "${mock_dir}/${command_name}"
    export PATH="${mock_dir}:${PATH}"
}
