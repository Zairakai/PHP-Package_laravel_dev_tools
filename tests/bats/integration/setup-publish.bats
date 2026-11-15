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

  for path in scripts stubs config tools bin src .editorconfig; do
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

@test "--publish=style publishes the markdownlint files in config/dev-tools, with a thin file and a copy at the root" {
  run_setup --publish=style

  [ "$status" -eq 0 ]
  [ -f "${PROJECT}/config/dev-tools/.markdownlint.json" ]
  [ -f "${PROJECT}/config/dev-tools/.markdownlintignore" ]

  run grep -F "./config/dev-tools/.markdownlint.json" "${PROJECT}/.markdownlint.json"
  [ "$status" -eq 0 ]

  run cmp "${PROJECT}/.markdownlintignore" "${PROJECT}/config/dev-tools/.markdownlintignore"
  [ "$status" -eq 0 ]
}

@test "--publish=quality publishes the configuration of PHPStan, Rector and PHP Insights in config/dev-tools" {
  run_setup --publish=quality

  [ "$status" -eq 0 ]
  [ -f "${PROJECT}/config/dev-tools/phpstan.neon" ]
  [ -f "${PROJECT}/config/dev-tools/rector.php" ]
  [ -f "${PROJECT}/config/dev-tools/phpinsights.php" ]
  [ -f "${PROJECT}/config/dev-tools/baseline.neon" ]
}

@test "--publish=quality leaves no Rector or PHP Insights file at the root: no editor reads them" {
  run_setup --publish=quality

  [ "$status" -eq 0 ]
  [ ! -e "${PROJECT}/rector.php" ]
  [ ! -e "${PROJECT}/phpinsights.php" ]
}

@test "--publish=quality creates the thin phpstan.neon at the root, which includes the one of config/dev-tools" {
  run_setup --publish=quality

  [ "$status" -eq 0 ]
  run grep -F "config/dev-tools/phpstan.neon" "${PROJECT}/phpstan.neon"
  [ "$status" -eq 0 ]
  run grep -F "../../vendor/zairakai/laravel-dev-tools/config/library.neon" "${PROJECT}/config/dev-tools/phpstan.neon"
  [ "$status" -eq 0 ]
}

@test "the files of config/dev-tools reach the package from where they are, not from the root" {
  run_setup --publish=quality

  run grep -F "dirname(__DIR__, 2)" "${PROJECT}/config/dev-tools/rector.php"
  [ "$status" -eq 0 ]
  run grep -F "dirname(__DIR__, 2)" "${PROJECT}/config/dev-tools/phpinsights.php"
  [ "$status" -eq 0 ]
}

@test "--publish=testing publishes phpunit.xml in config/dev-tools, and its copy at the root" {
  run_setup --publish=testing

  [ "$status" -eq 0 ]
  [ -f "${PROJECT}/config/dev-tools/phpunit.xml" ]
  [ -f "${PROJECT}/phpunit.xml" ]

  run grep -F 'bootstrap="../../vendor/autoload.php"' "${PROJECT}/config/dev-tools/phpunit.xml"
  [ "$status" -eq 0 ]
}

@test "--publish=phpunit publishes the configuration of a package when the project has no artisan" {
  run_setup --publish=phpunit

  [ "$status" -eq 0 ]
  run cmp "${PROJECT}/phpunit.xml" "${PACKAGE}/config/phpunit.xml"
  [ "$status" -eq 0 ]
}

@test "--publish=phpunit publishes the configuration of an application when the project has artisan" {
  touch "${PROJECT}/artisan"

  run_setup --publish=phpunit

  [ "$status" -eq 0 ]
  run cmp "${PROJECT}/phpunit.xml" "${PACKAGE}/config/phpunit-app.xml"
  [ "$status" -eq 0 ]
}

@test "--publish=phpunit publishes the configuration of an application when the project has app/ and no src/" {
  rmdir "${PROJECT}/src"
  mkdir "${PROJECT}/app"

  run_setup --publish=phpunit

  [ "$status" -eq 0 ]
  run cmp "${PROJECT}/phpunit.xml" "${PACKAGE}/config/phpunit-app.xml"
  [ "$status" -eq 0 ]
}

@test "a root file that the project already has is kept, and written again with --force" {
  printf 'includes:\n  - vendor/old.neon\n' > "${PROJECT}/phpstan.neon"

  run_setup --publish=phpstan
  [ "$status" -eq 0 ]
  run grep -F "vendor/old.neon" "${PROJECT}/phpstan.neon"
  [ "$status" -eq 0 ]

  run_setup --publish=phpstan --force
  [ "$status" -eq 0 ]
  run grep -F "config/dev-tools/phpstan.neon" "${PROJECT}/phpstan.neon"
  [ "$status" -eq 0 ]
}

# The root and config/dev-tools
# ================

@test "check-config-copies.sh passes after a publication of everything" {
  mkdir -p "${PROJECT}/config/dev-tools"
  printf '{"preset": "laravel"}\n' > "${PROJECT}/config/dev-tools/pint.json"

  run_setup --publish=quality
  run_setup --publish=style
  run_setup --publish=testing

  run bash "${PACKAGE}/scripts/check-config-copies.sh"
  [ "$status" -eq 0 ]
}

@test "check-config-copies.sh fails when a copy of the root differs from config/dev-tools" {
  mkdir -p "${PROJECT}/config/dev-tools"
  printf '{"preset": "laravel"}\n' > "${PROJECT}/config/dev-tools/pint.json"
  run_setup --publish=style
  run_setup --publish=testing
  printf 'build/\n' > "${PROJECT}/.markdownlintignore"

  run bash "${PACKAGE}/scripts/check-config-copies.sh"
  [ "$status" -eq 1 ]
  [[ "$output" == *".markdownlintignore at the root differs"* ]]
}

@test "check-config-copies.sh fails when a thin file of the root does not point at config/dev-tools" {
  mkdir -p "${PROJECT}/config/dev-tools"
  printf '{"preset": "laravel"}\n' > "${PROJECT}/config/dev-tools/pint.json"

  run_setup --publish=quality
  run_setup --publish=style
  run_setup --publish=testing
  printf 'includes:\n  - vendor/old.neon\n' > "${PROJECT}/phpstan.neon"

  run bash "${PACKAGE}/scripts/check-config-copies.sh"
  [ "$status" -eq 1 ]
  [[ "$output" == *"phpstan.neon at the root does not point at config/dev-tools/phpstan.neon"* ]]
}

@test "check-config-copies.sh tells a rector.php that the project keeps at the root, where no editor reads it" {
  mkdir -p "${PROJECT}/config/dev-tools"
  printf '{"preset": "laravel"}\n' > "${PROJECT}/config/dev-tools/pint.json"

  run_setup --publish=quality
  run_setup --publish=style
  run_setup --publish=testing
  printf '<?php return [];\n' > "${PROJECT}/rector.php"

  run bash "${PACKAGE}/scripts/check-config-copies.sh"
  [ "$status" -eq 1 ]
  [[ "$output" == *"rector.php at the root is not read"* ]]
}

@test "check-config-copies.sh with SYNC=true writes the copies again from config/dev-tools" {
  mkdir -p "${PROJECT}/config/dev-tools"
  printf '{"preset": "laravel"}\n' > "${PROJECT}/config/dev-tools/pint.json"
  run_setup --publish=quality
  run_setup --publish=style
  run_setup --publish=testing
  printf 'build/\n' > "${PROJECT}/.markdownlintignore"

  SYNC=true run bash "${PACKAGE}/scripts/check-config-copies.sh"
  [ "$status" -eq 0 ]
  run cmp "${PROJECT}/.markdownlintignore" "${PROJECT}/config/dev-tools/.markdownlintignore"
  [ "$status" -eq 0 ]
}

@test "a forced publication keeps a copy of the file that it writes over" {
  mkdir -p "${PROJECT}/config/dev-tools"
  printf '<?php // mine\n' > "${PROJECT}/config/dev-tools/rector.php"

  run_setup --publish=rector --force

  [ "$status" -eq 0 ]
  run bash -c "cat ${PROJECT}/.dev-tools-backup/*/config/dev-tools/rector.php"
  [ "$status" -eq 0 ]
  [[ "$output" == *"// mine"* ]]
}

@test "a publication without force keeps no copy" {
  mkdir -p "${PROJECT}/config/dev-tools"
  printf '<?php // mine\n' > "${PROJECT}/config/dev-tools/rector.php"

  run_setup --publish=rector

  [ "$status" -eq 0 ]
  [ ! -d "${PROJECT}/.dev-tools-backup" ]
}

@test "SYNC=true keeps a copy of the file of the root that it writes over" {
  mkdir -p "${PROJECT}/config/dev-tools"
  printf '{"preset": "laravel"}\n' > "${PROJECT}/config/dev-tools/pint.json"
  run_setup --publish=quality
  run_setup --publish=style
  run_setup --publish=testing
  printf 'build/\n' > "${PROJECT}/.markdownlintignore"

  SYNC=true run bash "${PACKAGE}/scripts/check-config-copies.sh"

  [ "$status" -eq 0 ]
  run bash -c "cat ${PROJECT}/.dev-tools-backup/*/.markdownlintignore"
  [ "$status" -eq 0 ]
  [ "$output" = "build/" ]
}

@test "check-config-copies.sh has nothing to compare in a project without config/dev-tools" {
  run bash "${PACKAGE}/scripts/check-config-copies.sh"
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
