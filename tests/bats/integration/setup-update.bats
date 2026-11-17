#!/usr/bin/env bats
#
# Integration Tests for scripts/setup-update.sh
#
# The update publishes again what setup-check reports. The tests build a project with the layout of an installed package.
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

  bash "${PACKAGE}/scripts/setup-package.sh" > /dev/null 2>&1
  bash "${PACKAGE}/scripts/setup-package.sh" --publish > /dev/null 2>&1
}

teardown() {
  teardown_test_env
}

update_the_setup() {
  cd "${PROJECT}" || return 1
  run bash "${PACKAGE}/scripts/setup-update.sh"
}

check_the_setup() {
  cd "${PROJECT}" || return 1
  run bash "${PACKAGE}/scripts/setup-check.sh"
}

@test "setup-update.sh writes nothing when every file is the same" {
  update_the_setup

  [ "$status" -eq 0 ]
  [[ "$output" == *"Nothing to update"* ]]
  [ ! -d "${PROJECT}/.dev-tools-backup" ]
}

@test "setup-update.sh publishes a file that is missing, with no copy to keep" {
  rm "${PROJECT}/config/dev-tools/rector.php"

  update_the_setup

  [ "$status" -eq 0 ]
  [ -f "${PROJECT}/config/dev-tools/rector.php" ]
  [ ! -d "${PROJECT}/.dev-tools-backup" ]
}

@test "setup-update.sh replaces a different file after a copy of it in .dev-tools-backup" {
  printf '<?php // mine\n' > "${PROJECT}/config/dev-tools/rector.php"

  update_the_setup

  [ "$status" -eq 0 ]
  run grep -c '// mine' "${PROJECT}/config/dev-tools/rector.php"
  [ "$output" = "0" ]
  run grep -rl '// mine' "${PROJECT}/.dev-tools-backup"
  [[ "$output" == *"config/dev-tools/rector.php" ]]
}

@test "setup-update.sh leaves a file that is the same out of the copies" {
  printf '<?php // mine\n' > "${PROJECT}/config/dev-tools/rector.php"

  update_the_setup

  [ ! -e "$(find "${PROJECT}/.dev-tools-backup" -name 'phpinsights.php' | head -n 1)" ]
}

@test "setup-check.sh ends with 0 after setup-update.sh" {
  rm "${PROJECT}/config/dev-tools/rector.php"
  printf '// mine\n' >> "${PROJECT}/config/dev-tools/phpinsights.php"

  update_the_setup
  [ "$status" -eq 0 ]

  check_the_setup
  [ "$status" -eq 0 ]
}

@test "setup-update.sh with DRY_RUN=true lists the files and writes nothing" {
  printf '<?php // mine\n' > "${PROJECT}/config/dev-tools/rector.php"
  export DRY_RUN=true

  update_the_setup

  [ "$status" -eq 0 ]
  [[ "$output" == *"would replace: config/dev-tools/rector.php"* ]]
  run grep -c '// mine' "${PROJECT}/config/dev-tools/rector.php"
  [ "$output" = "1" ]
  [ ! -d "${PROJECT}/.dev-tools-backup" ]
}

@test "setup-update.sh leaves a symbolic link alone and names it" {
  printf '<?php // shared\n' > "${TEST_TEMP_DIR}/shared-insights.php"
  rm "${PROJECT}/config/dev-tools/phpinsights.php"
  ln -s "${TEST_TEMP_DIR}/shared-insights.php" "${PROJECT}/config/dev-tools/phpinsights.php"

  update_the_setup

  [ "$status" -eq 0 ]
  [[ "$output" == *"keep: config/dev-tools/phpinsights.php (symbolic link)"* ]]
  [ -L "${PROJECT}/config/dev-tools/phpinsights.php" ]
  [ "$(cat "${TEST_TEMP_DIR}/shared-insights.php")" = "<?php // shared" ]
}

