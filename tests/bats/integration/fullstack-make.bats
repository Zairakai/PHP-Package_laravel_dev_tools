#!/usr/bin/env bats
#
# Integration Tests for the setup targets of fullstack.mk
#
# security-audit, setup-check, setup-update and uninstall are double-colon targets: in a full-stack project, the rule of laravel-dev-tools and
# the one of @zairakai/js-dev-tools both run, the Laravel one first. With a single colon, one rule would replace the other.
# The JS package is a stub here: only its make files are read, and its rules print a line.
#

# Load test helpers
load '../helpers/test_helper'

setup() {
  setup_test_env

  export PROJECT="${TEST_TEMP_DIR}/project"
  local js="${PROJECT}/node_modules/@zairakai/js-dev-tools/tools/make"

  mkdir -p "${PROJECT}/vendor/zairakai" "${js}"
  ln -s "${PROJECT_ROOT}" "${PROJECT}/vendor/zairakai/laravel-dev-tools"
  printf '{\n  "name": "acme/app"\n}\n' > "${PROJECT}/composer.json"

  # What fullstack.mk reads of the JS package: the variables, the tools that it includes, and its utils
  printf 'NPM_DIRECTORY_TOOLS_SCRIPTS_DIR := $(dir $(lastword $(MAKEFILE_LIST)))../../scripts/\n' > "${js}/variables.mk"

  local name
  for name in code-style stylelint knip typescript; do
    : > "${js}/${name}.mk"
  done

  cat > "${js}/utils.mk" << 'MAKE'
.PHONY: security-audit setup-check setup-update uninstall
security-audit::
	@echo js-security-audit
setup-check::
	@echo js-setup-check
setup-update::
	@echo js-setup-update
uninstall::
	@echo js-uninstall
MAKE

  printf 'include vendor/zairakai/laravel-dev-tools/tools/make/fullstack.mk\n' > "${PROJECT}/Makefile"
}

teardown() {
  teardown_test_env
}

# The lines of the recipes, in the order that make runs them
recipes() {
  cd "${PROJECT}" || return 1
  run make -n "$1"
}

@test "make security-audit runs the Laravel script, then the JS one" {
  recipes security-audit

  [ "$status" -eq 0 ]
  [[ "$output" == *"laravel-dev-tools/scripts//security-audit.sh"* ]]
  [[ "$output" == *"js-security-audit"* ]]
}

@test "make setup-check runs the Laravel script, then the JS one" {
  recipes setup-check

  [ "$status" -eq 0 ]
  [[ "$output" == *"laravel-dev-tools/scripts//setup-check.sh"* ]]
  [[ "$output" == *"js-setup-check"* ]]

  local before="${output%%js-setup-check*}"
  [[ "$before" == *"setup-check.sh"* ]]
}

@test "make setup-update runs the Laravel script, then the JS one" {
  recipes setup-update

  [ "$status" -eq 0 ]
  [[ "$output" == *"laravel-dev-tools/scripts//setup-update.sh"* ]]
  [[ "$output" == *"js-setup-update"* ]]
}

@test "make uninstall runs the Laravel script, then the JS one" {
  recipes uninstall

  [ "$status" -eq 0 ]
  [[ "$output" == *"laravel-dev-tools/scripts//uninstall-package.sh"* ]]
  [[ "$output" == *"js-uninstall"* ]]
}

@test "make does not warn that a recipe is overridden" {
  cd "${PROJECT}" || return 1
  run make -n security-audit setup-check setup-update uninstall

  [[ "$output" != *"overriding recipe"* ]]
  [[ "$output" != *"ignoring old recipe"* ]]
}
