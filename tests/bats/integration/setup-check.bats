#!/usr/bin/env bats
#
# Integration Tests for scripts/setup-check.sh
#
# The check reads the project and the package, and writes nothing: the tests build a project with the layout of an
# installed package and run the script in it.
#

# Load test helpers
load '../helpers/test_helper'

setup() {
  setup_test_env

  export PROJECT="${TEST_TEMP_DIR}/project"
  export PACKAGE="${PROJECT}/vendor/zairakai/laravel-dev-tools"

  mkdir -p "${PACKAGE}" "${PROJECT}/src"

  for path in scripts stubs config tools bin src .editorconfig; do
    cp -R "${PROJECT_ROOT}/${path}" "${PACKAGE}/"
  done

  printf '{\n  "name": "acme/sample"\n}\n' > "${PROJECT}/composer.json"
  printf 'vendor/\n' > "${PROJECT}/.gitignore"

  bash "${PACKAGE}/scripts/setup-package.sh" --publish > /dev/null 2>&1
}

teardown() {
  teardown_test_env
}

check_the_setup() {
  cd "${PROJECT}" || return 1
  run bash "${PACKAGE}/scripts/setup-check.sh"
}

@test "setup-check.sh succeeds when every file is published and the same" {
  check_the_setup

  [ "$status" -eq 0 ]
  [[ "$output" == *"Every file of the setup is published"* ]]
}

@test "setup-check.sh fails and names a file that is missing" {
  rm "${PROJECT}/config/dev-tools/rector.php"

  check_the_setup

  [ "$status" -eq 1 ]
  [[ "$output" == *"config/dev-tools/rector.php: missing"* ]]
  [[ "$output" == *"make setup-update"* ]]
}

@test "setup-check.sh fails and shows what differs in a file" {
  printf '// mine\n' >> "${PROJECT}/config/dev-tools/phpinsights.php"

  check_the_setup

  [ "$status" -eq 1 ]
  [[ "$output" == *"config/dev-tools/phpinsights.php: different"* ]]
}

@test "setup-check.sh writes nothing in the project" {
  rm "${PROJECT}/config/dev-tools/rector.php"

  check_the_setup

  [ ! -e "${PROJECT}/config/dev-tools/rector.php" ]
  [ ! -d "${PROJECT}/.dev-tools-backup" ]
}
