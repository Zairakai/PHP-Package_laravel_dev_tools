#!/usr/bin/env bats
#
# Unit Tests for install-hooks.sh
#
# The script activates the hooks of stanislas-poisson/php-dev-tools through core.hooksPath:
# - Activation in a git repository
# - Errors (not a git repository, hooks not installed)
# - Deactivation (only of the hooks of php-dev-tools)
#

# Load test helpers
load '../helpers/test_helper'

HOOKS_PATH="vendor/stanislas-poisson/php-dev-tools/hooks"

setup() {
  setup_test_env

  # A project: a git repository with the scripts of this package and the hooks of php-dev-tools in vendor/
  export TEST_VENDOR_DIR="${TEST_TEMP_DIR}/vendor/zairakai/laravel-dev-tools"
  mkdir -p "${TEST_VENDOR_DIR}/scripts" "${TEST_TEMP_DIR}/${HOOKS_PATH}"

  cp "${PROJECT_ROOT}/scripts/config.sh" "${TEST_VENDOR_DIR}/scripts/"
  cp "${PROJECT_ROOT}/scripts/install-hooks.sh" "${TEST_VENDOR_DIR}/scripts/"

  for hook in commit-msg prepare-commit-msg pre-commit pre-push; do
    printf '#!/usr/bin/env bash\nexit 0\n' > "${TEST_TEMP_DIR}/${HOOKS_PATH}/${hook}"
    chmod +x "${TEST_TEMP_DIR}/${HOOKS_PATH}/${hook}"
  done

  cd "${TEST_TEMP_DIR}" || exit 1
  git init -q .
}

teardown() {
  teardown_test_env
}

# ============================================================================
# Activation Tests
# ============================================================================

@test "install-hooks detects non-git repository" {
  rm -rf "${TEST_TEMP_DIR:?}/.git"

  run bash "${TEST_VENDOR_DIR}/scripts/install-hooks.sh"

  [ "$status" -eq 1 ]
  [[ "$output" =~ "Not a git repository" ]]
}

@test "install-hooks fails when the hooks of php-dev-tools are not installed" {
  rm -rf "${TEST_TEMP_DIR:?}/${HOOKS_PATH}"

  run bash "${TEST_VENDOR_DIR}/scripts/install-hooks.sh"

  [ "$status" -eq 1 ]
  [[ "$output" =~ "Hooks not found" ]]
}

@test "install-hooks points core.hooksPath at the hooks of php-dev-tools" {
  run bash "${TEST_VENDOR_DIR}/scripts/install-hooks.sh"

  [ "$status" -eq 0 ]
  [ "$(git config --get core.hooksPath)" = "${HOOKS_PATH}" ]
}

@test "install-hooks copies nothing into the project" {
  run bash "${TEST_VENDOR_DIR}/scripts/install-hooks.sh"

  [ "$status" -eq 0 ]
  [ ! -e "${TEST_TEMP_DIR}/.githooks" ]
  [ -z "$(find "${TEST_TEMP_DIR}/.git/hooks" -type f ! -name '*.sample')" ]
}

@test "install-hooks lists the four hooks" {
  run bash "${TEST_VENDOR_DIR}/scripts/install-hooks.sh"

  [ "$status" -eq 0 ]
  [[ "$output" =~ "commit-msg" ]]
  [[ "$output" =~ "prepare-commit-msg" ]]
  [[ "$output" =~ "pre-commit" ]]
  [[ "$output" =~ "pre-push" ]]
}

# ============================================================================
# Deactivation Tests
# ============================================================================

@test "install-hooks --remove unsets core.hooksPath when it is the one of php-dev-tools" {
  git config core.hooksPath "${HOOKS_PATH}"

  run bash "${TEST_VENDOR_DIR}/scripts/install-hooks.sh" --remove

  [ "$status" -eq 0 ]
  [ -z "$(git config --get core.hooksPath || true)" ]
}

@test "install-hooks --remove leaves another core.hooksPath alone" {
  git config core.hooksPath ".githooks"

  run bash "${TEST_VENDOR_DIR}/scripts/install-hooks.sh" --remove

  [ "$status" -eq 0 ]
  [ "$(git config --get core.hooksPath)" = ".githooks" ]
}
