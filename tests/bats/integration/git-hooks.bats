#!/usr/bin/env bats
#
# Integration Tests for Git Hooks
#
# Tests the actual git hooks behavior including:
# - commit-msg: Conventional Commits validation
# - prepare-commit-msg: Ticket extraction from branch
# - pre-commit: Quality checks trigger
# - pre-push: Full quality gate
#

# Load test helpers
load '../helpers/test_helper'

setup() {
    setup_test_env

    # Create test git repository
    export TEST_REPO="${TEST_TEMP_DIR}/test-repo"
    mkdir -p "${TEST_REPO}"
    cd "${TEST_REPO}" || return
    git init >/dev/null 2>&1
    git config user.name "Test User"
    git config user.email "test@example.com"
}

teardown() {
    teardown_test_env
}

# ============================================================================
# commit-msg Hook Tests
# ============================================================================

@test "commit-msg accepts valid conventional commit" {
    local hook="${PROJECT_ROOT}/stubs/githooks/commit-msg"
    local msg_file="${TEST_TEMP_DIR}/commit-msg.txt"

    echo "feat(auth): add user authentication" > "$msg_file"

    run bash "$hook" "$msg_file"

    [ "$status" -eq 0 ]
    [[ "$output" =~ "Commit message OK" ]]
}

@test "commit-msg accepts commit with ticket number" {
    local hook="${PROJECT_ROOT}/stubs/githooks/commit-msg"
    local msg_file="${TEST_TEMP_DIR}/commit-msg.txt"

    echo "fix(validation): #456 correct email validation" > "$msg_file"

    run bash "$hook" "$msg_file"

    [ "$status" -eq 0 ]
}

@test "commit-msg rejects invalid format" {
    local hook="${PROJECT_ROOT}/stubs/githooks/commit-msg"
    local msg_file="${TEST_TEMP_DIR}/commit-msg.txt"

    echo "just a random commit message" > "$msg_file"

    run bash "$hook" "$msg_file"

    [ "$status" -eq 1 ]
    [[ "$output" =~ "Invalid commit message format" ]]
}

@test "commit-msg rejects unknown type" {
    local hook="${PROJECT_ROOT}/stubs/githooks/commit-msg"
    local msg_file="${TEST_TEMP_DIR}/commit-msg.txt"

    echo "unknown(scope): some message" > "$msg_file"

    run bash "$hook" "$msg_file"

    [ "$status" -eq 1 ]
}

@test "commit-msg enforces lowercase subject" {
    local hook="${PROJECT_ROOT}/stubs/githooks/commit-msg"
    local msg_file="${TEST_TEMP_DIR}/commit-msg.txt"

    # Uppercase first letter should fail
    echo "feat(auth): Add user authentication" > "$msg_file"

    run bash "$hook" "$msg_file"

    [ "$status" -eq 1 ]
}

@test "commit-msg enforces minimum subject length" {
    local hook="${PROJECT_ROOT}/stubs/githooks/commit-msg"
    local msg_file="${TEST_TEMP_DIR}/commit-msg.txt"

    # Too short (less than 10 chars)
    echo "feat: short" > "$msg_file"

    run bash "$hook" "$msg_file"

    [ "$status" -eq 1 ]
}

@test "commit-msg enforces line length limit" {
    local hook="${PROJECT_ROOT}/stubs/githooks/commit-msg"
    local msg_file="${TEST_TEMP_DIR}/commit-msg.txt"

    # Create a very long subject line (> 72 chars)
    echo "feat(auth): this is a very long commit message that exceeds the maximum allowed length of 72 characters" > "$msg_file"

    run bash "$hook" "$msg_file"

    [ "$status" -eq 1 ]
    [[ "$output" =~ "too long" ]]
}

@test "commit-msg accepts all valid types" {
    local hook="${PROJECT_ROOT}/stubs/githooks/commit-msg"
    local msg_file="${TEST_TEMP_DIR}/commit-msg.txt"

    # Test each valid type
    local types=("feat" "fix" "docs" "style" "refactor" "test" "chore" "perf" "ci" "build")

    for type in "${types[@]}"; do
        echo "${type}: valid commit message here" > "$msg_file"

        run bash "$hook" "$msg_file"

        [ "$status" -eq 0 ]
    done
}

# ============================================================================
# prepare-commit-msg Hook Tests
# ============================================================================

@test "prepare-commit-msg extracts ticket from feature branch" {
    local hook="${PROJECT_ROOT}/stubs/githooks/prepare-commit-msg"
    local msg_file="${TEST_TEMP_DIR}/commit-msg.txt"

    # Create branch with ticket number
    git checkout -b feature/#123-add-login >/dev/null 2>&1

    echo "add login functionality" > "$msg_file"

    bash "$hook" "$msg_file" "" >/dev/null 2>&1

    # Message should now have #123 prefix
    local content
    content=$(cat "$msg_file")
    [[ "$content" =~ "#123" ]]
}

@test "prepare-commit-msg extracts ticket from fix branch" {
    local hook="${PROJECT_ROOT}/stubs/githooks/prepare-commit-msg"
    local msg_file="${TEST_TEMP_DIR}/commit-msg.txt"

    git checkout -b fix/#456-bug-fix >/dev/null 2>&1

    echo "fix critical bug" > "$msg_file"

    bash "$hook" "$msg_file" "" >/dev/null 2>&1

    local content
    content=$(cat "$msg_file")
    [[ "$content" =~ "#456" ]]
}

@test "prepare-commit-msg extracts ticket from hotfix branch" {
    local hook="${PROJECT_ROOT}/stubs/githooks/prepare-commit-msg"
    local msg_file="${TEST_TEMP_DIR}/commit-msg.txt"

    git checkout -b hotfix/#789-critical >/dev/null 2>&1

    echo "critical hotfix" > "$msg_file"

    bash "$hook" "$msg_file" "" >/dev/null 2>&1

    local content
    content=$(cat "$msg_file")
    [[ "$content" =~ "#789" ]]
}

@test "prepare-commit-msg skips if ticket already present" {
    local hook="${PROJECT_ROOT}/stubs/githooks/prepare-commit-msg"
    local msg_file="${TEST_TEMP_DIR}/commit-msg.txt"

    git checkout -b feature/#123-test >/dev/null 2>&1

    echo "#123 already has ticket" > "$msg_file"

    bash "$hook" "$msg_file" "" >/dev/null 2>&1

    # Should not duplicate ticket
    local content
    content=$(cat "$msg_file")
    local count
    count=$(echo "$content" | grep -o "#123" | wc -l)
    [ "$count" -eq 1 ]
}

@test "prepare-commit-msg skips merge commits" {
    local hook="${PROJECT_ROOT}/stubs/githooks/prepare-commit-msg"
    local msg_file="${TEST_TEMP_DIR}/commit-msg.txt"

    git checkout -b feature/#123-test >/dev/null 2>&1

    echo "merge commit" > "$msg_file"

    # Simulate merge commit (commit_source is set)
    bash "$hook" "$msg_file" "merge" >/dev/null 2>&1

    # Should not add ticket to merge commits
    local content
    content=$(cat "$msg_file")
    [[ ! "$content" =~ "#123" ]]
}

@test "prepare-commit-msg ignores branches without ticket pattern" {
    local hook="${PROJECT_ROOT}/stubs/githooks/prepare-commit-msg"
    local msg_file="${TEST_TEMP_DIR}/commit-msg.txt"

    # Branch without ticket number
    git checkout -b my-branch >/dev/null 2>&1

    echo "commit without ticket" > "$msg_file"
    local original_content
    original_content=$(cat "$msg_file")

    bash "$hook" "$msg_file" "" >/dev/null 2>&1

    # Message should remain unchanged
    local new_content
    new_content=$(cat "$msg_file")
    [ "$original_content" = "$new_content" ]
}

# ============================================================================
# pre-commit Hook Tests
# ============================================================================

@test "pre-commit hook exists and is executable" {
    local hook="${PROJECT_ROOT}/stubs/githooks/pre-commit"

    [ -f "$hook" ]
    [ -x "$hook" ]
}

@test "pre-commit hook has correct shebang" {
    local hook="${PROJECT_ROOT}/stubs/githooks/pre-commit"

    run head -n1 "$hook"

    [[ "$output" =~ "#!/usr/bin/env bash" ]]
}

@test "pre-commit hook calls make quality-fast" {
    local hook="${PROJECT_ROOT}/stubs/githooks/pre-commit"

    run grep -q "make quality-fast" "$hook"

    [ "$status" -eq 0 ]
}

# ============================================================================
# pre-push Hook Tests
# ============================================================================

@test "pre-push hook exists and is executable" {
    local hook="${PROJECT_ROOT}/stubs/githooks/pre-push"

    [ -f "$hook" ]
    [ -x "$hook" ]
}

@test "pre-push hook has correct shebang" {
    local hook="${PROJECT_ROOT}/stubs/githooks/pre-push"

    run head -n1 "$hook"

    [[ "$output" =~ "#!/usr/bin/env bash" ]]
}

@test "pre-push hook runs quality gate" {
    local hook="${PROJECT_ROOT}/stubs/githooks/pre-push"

    run grep -q "make quality" "$hook"

    [ "$status" -eq 0 ]
}
