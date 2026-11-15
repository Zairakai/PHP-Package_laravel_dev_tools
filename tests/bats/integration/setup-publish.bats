#!/usr/bin/env bats
#
# Integration Tests for the publication of setup-package.sh
#
# The script reads its files in vendor/zairakai/laravel-dev-tools of a project: the tests build a project with that layout
# and run the script in it, never in this repository.
#

# Load test helpers
load '../helpers/test_helper'

setup() {
  setup_test_env

  export PROJECT="${TEST_TEMP_DIR}/project"
  export PACKAGE="${PROJECT}/vendor/zairakai/laravel-dev-tools"

  mkdir -p "${PACKAGE}" "${PROJECT}/src"

  for path in scripts stubs config tools bin .editorconfig; do
    cp -R "${PROJECT_ROOT}/${path}" "${PACKAGE}/"
  done

  # Several "name" keys, as in any composer.json: the first one is the name of the package
  printf '{\n  "name": "acme/sample",\n  "authors": [{"name": "Someone"}],\n  "support": {"name": "Support"}\n}\n' > "${PROJECT}/composer.json"
  printf 'vendor/\nbuild/\n' > "${PROJECT}/.gitignore"
}

teardown() {
  teardown_test_env
}

run_setup() {
  run bash "${PACKAGE}/scripts/setup-package.sh" "$@"
}

# The default setup
# ================

@test "the default setup creates .editorconfig, the Makefile and the baseline" {
  run_setup

  [ "$status" -eq 0 ]
  [ -f "${PROJECT}/.editorconfig" ]
  [ -f "${PROJECT}/Makefile" ]
  [ -f "${PROJECT}/config/dev-tools/baseline.neon" ]
}

# The governance files
# ================

@test "--publish=governance creates the three files with the issues URL of the package" {
  run_setup --publish=governance

  [ "$status" -eq 0 ]
  [ -f "${PROJECT}/SECURITY.md" ]
  [ -f "${PROJECT}/CONTRIBUTING.md" ]
  [ -f "${PROJECT}/CODE_OF_CONDUCT.md" ]
  run grep -c "php-packages/sample/-/issues" "${PROJECT}/CONTRIBUTING.md"
  [ "$output" -ge 1 ]
}

@test "--publish=governance does not fail now and then: the name is read without a pipe" {
  for _ in $(seq 1 25); do
    rm -f "${PROJECT}/SECURITY.md" "${PROJECT}/CONTRIBUTING.md" "${PROJECT}/CODE_OF_CONDUCT.md"

    run bash "${PACKAGE}/scripts/setup-package.sh" --publish=governance

    [ "$status" -eq 0 ]
    [ -f "${PROJECT}/SECURITY.md" ]
  done
}

@test "setup-package.sh reads the package name without head, which fails under pipefail" {
  run grep -c "| *head -1" "${PACKAGE}/scripts/setup-package.sh"

  [ "$output" = "0" ]
}

# The thin files
# ================

@test "--publish=style creates the markdownlint files and pint.json at the root" {
  run_setup --publish=style

  [ "$status" -eq 0 ]
  [ -f "${PROJECT}/.markdownlint.json" ]
  [ -f "${PROJECT}/.markdownlintignore" ]
}

@test "--publish=quality creates the Rector and PHPInsights files at the root" {
  run_setup --publish=quality

  [ "$status" -eq 0 ]
  [ -f "${PROJECT}/rector.php" ]
  [ -f "${PROJECT}/phpinsights.php" ]
}

@test "--publish=testing creates phpunit.xml in config/dev-tools" {
  run_setup --publish=testing

  [ "$status" -eq 0 ]
  [ -f "${PROJECT}/config/dev-tools/phpunit.xml" ]
}

@test "--publish=phpunit publishes the configuration of a package when the project has no artisan" {
  run_setup --publish=phpunit

  [ "$status" -eq 0 ]
  run cmp "${PROJECT}/config/dev-tools/phpunit.xml" "${PACKAGE}/config/phpunit.xml"
  [ "$status" -eq 0 ]
}

@test "--publish=phpunit publishes the configuration of an application when the project has artisan" {
  touch "${PROJECT}/artisan"

  run_setup --publish=phpunit

  [ "$status" -eq 0 ]
  run cmp "${PROJECT}/config/dev-tools/phpunit.xml" "${PACKAGE}/config/phpunit-app.xml"
  [ "$status" -eq 0 ]
}

@test "--publish=phpunit publishes the configuration of an application when the project has app/ and no src/" {
  rmdir "${PROJECT}/src"
  mkdir "${PROJECT}/app"

  run_setup --publish=phpunit

  [ "$status" -eq 0 ]
  run cmp "${PROJECT}/config/dev-tools/phpunit.xml" "${PACKAGE}/config/phpunit-app.xml"
  [ "$status" -eq 0 ]
}

@test "--publish=gitlab-ci creates .gitlab-ci.yml with the name of the package" {
  run_setup --publish=gitlab-ci

  [ "$status" -eq 0 ]
  run grep -c "acme/sample" "${PROJECT}/.gitlab-ci.yml"
  [ "$output" -ge 1 ]
}

# The .gitignore of the project
# ================

@test "the .gitignore block has a title underlined by 16 characters, as in the handbook" {
  run_setup --publish=testing

  [ "$status" -eq 0 ]
  run grep -A1 "^# Dev Tools temporary files$" "${PROJECT}/.gitignore"
  [[ "$output" == *"# ----------------"* ]]
  run grep -c "^\.dev-tools-backup/$" "${PROJECT}/.gitignore"
  [ "$output" = "1" ]
}

@test "the .gitignore block does not ask to ignore config/dev-tools, which is committed" {
  run_setup --publish=testing

  run grep -c "config/dev-tools" "${PROJECT}/.gitignore"
  [ "$output" = "0" ]
}

@test "the .gitignore block is written once" {
  run_setup --publish=testing
  run_setup --publish=testing

  run grep -c "^# Dev Tools temporary files$" "${PROJECT}/.gitignore"
  [ "$output" = "1" ]
}

@test "a project with the block of the older versions is left as it is" {
  printf 'vendor/\n\n# Dev Tools - Generated temporary files\n.*.tmp.*\n' > "${PROJECT}/.gitignore"

  run_setup --publish=testing

  run grep -c "^# Dev Tools temporary files$" "${PROJECT}/.gitignore"
  [ "$output" = "0" ]
}
