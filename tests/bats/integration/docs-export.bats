#!/usr/bin/env bats
#
# Integration Tests for docs/scripts/export-tree.sh
#
# The site is built from the files of a tag. `git archive` leaves out what export-ignore marks, and the docs are marked:
# export-tree.sh writes them anyway.
#

# Load test helpers
load '../helpers/test_helper'

setup() {
    setup_test_env

    export REPOSITORY="${TEST_TEMP_DIR}/repository"

    mkdir -p "${REPOSITORY}/docs/scripts" "${REPOSITORY}/src" "${REPOSITORY}/tests"
    printf '{}\n' > "${REPOSITORY}/docs/package.json"
    printf '#!/usr/bin/env bash\n' > "${REPOSITORY}/docs/scripts/build.sh"
    chmod +x "${REPOSITORY}/docs/scripts/build.sh"
    printf '# Readme\n' > "${REPOSITORY}/README.md"
    printf '<?php\n' > "${REPOSITORY}/src/A.php"
    printf '<?php\n' > "${REPOSITORY}/tests/ATest.php"
    printf '/docs export-ignore\n/tests export-ignore\n' > "${REPOSITORY}/.gitattributes"

    git -C "$REPOSITORY" init -q .
    git -C "$REPOSITORY" add -A
    git -C "$REPOSITORY" -c user.name=test -c user.email=test@example.com -c commit.gpgsign=false commit -q -m "initial"
    # The global configuration of git may sign the tags: an annotated tag without signature does not depend on it
    git -C "$REPOSITORY" -c user.name=test -c user.email=test@example.com -c tag.gpgsign=false tag -a -m "1.0.0" 1.0.0
}

teardown() {
    teardown_test_env
}

@test "git archive leaves the docs out when they are marked export-ignore" {
    run bash -c "git -C '${REPOSITORY}' archive 1.0.0 docs README.md src | tar -t"

    [ "$status" -eq 0 ]
    [[ "$output" != *"docs/package.json"* ]]
}

@test "export-tree.sh writes the docs although they are marked export-ignore" {
    run bash -c "cd '${REPOSITORY}' && bash '${PROJECT_ROOT}/docs/scripts/export-tree.sh' 1.0.0 '${TEST_TEMP_DIR}/out' docs README.md src"

    [ "$status" -eq 0 ]
    [ -f "${TEST_TEMP_DIR}/out/docs/package.json" ]
    [ -f "${TEST_TEMP_DIR}/out/README.md" ]
    [ -f "${TEST_TEMP_DIR}/out/src/A.php" ]
}

@test "export-tree.sh writes only the paths asked for" {
    run bash -c "cd '${REPOSITORY}' && bash '${PROJECT_ROOT}/docs/scripts/export-tree.sh' 1.0.0 '${TEST_TEMP_DIR}/out' docs README.md src"

    [ "$status" -eq 0 ]
    [ ! -e "${TEST_TEMP_DIR}/out/tests" ]
    [ ! -e "${TEST_TEMP_DIR}/out/.gitattributes" ]
}

@test "export-tree.sh keeps the executable bit" {
    run bash -c "cd '${REPOSITORY}' && bash '${PROJECT_ROOT}/docs/scripts/export-tree.sh' 1.0.0 '${TEST_TEMP_DIR}/out' docs"

    [ "$status" -eq 0 ]
    [ -x "${TEST_TEMP_DIR}/out/docs/scripts/build.sh" ]
}

@test "export-tree.sh reads the tag, not the working tree" {
    printf '{"changed": true}\n' > "${REPOSITORY}/docs/package.json"

    run bash -c "cd '${REPOSITORY}' && bash '${PROJECT_ROOT}/docs/scripts/export-tree.sh' 1.0.0 '${TEST_TEMP_DIR}/out' docs"

    [ "$status" -eq 0 ]
    run grep -c "changed" "${TEST_TEMP_DIR}/out/docs/package.json"
    [ "$output" = "0" ]
}

@test "export-tree.sh fails when the tag holds nothing of what is asked" {
    run bash -c "cd '${REPOSITORY}' && bash '${PROJECT_ROOT}/docs/scripts/export-tree.sh' 1.0.0 '${TEST_TEMP_DIR}/out' missing"

    [ "$status" -eq 1 ]
}

@test "publish.sh builds the versions with export-tree.sh, not with git archive" {
    run grep -q 'export-tree.sh' "${PROJECT_ROOT}/docs/scripts/publish.sh"

    [ "$status" -eq 0 ]
    run grep -q '^[^#]*git archive' "${PROJECT_ROOT}/docs/scripts/publish.sh"

    [ "$status" -eq 1 ]
}
