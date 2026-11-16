#!/usr/bin/env bats
#
# Integration Tests for scripts/security-audit.sh, outdated.sh, enlightn.sh and phpmetrics.sh
#
# The tools (composer, php, phpmetrics) are replaced by commands that answer what the test needs.
#

# Load test helpers
load '../helpers/test_helper'

setup() {
  setup_test_env
  make_script_project
}

teardown() {
  teardown_test_env
}

run_in_project() {
  local script="$1"
  shift

  cd "${PROJECT}" || return 1
  run bash "${PROJECT}/scripts/${script}" "$@"
}

# security-audit.sh
# ================

@test "security-audit.sh passes when composer finds no advisory" {
  fake_command composer 'echo "{\"advisories\": []}"'

  run_in_project security-audit.sh

  [ "$status" -eq 0 ]
  [[ "$output" == *"No security vulnerabilities found"* ]]
}

@test "security-audit.sh fails and shows the report when composer finds advisories" {
  fake_command composer 'echo "{\"advisories\": {\"acme/lib\": [{\"title\": \"bad\"}]}}"; exit 1'

  run_in_project security-audit.sh

  [ "$status" -eq 1 ]
  [[ "$output" == *"Found 1 security advisories"* ]]
  [[ "$output" == *"acme/lib"* ]]
}

@test "security-audit.sh fails when composer audit could not run" {
  fake_command composer 'echo "Could not reach packagist" >&2; exit 100'

  run_in_project security-audit.sh

  [ "$status" -eq 1 ]
  [[ "$output" == *"could not run"* ]]
  [[ "$output" != *"No security vulnerabilities found"* ]]
}

@test "security-audit.sh writes the report in the build folder" {
  fake_command composer 'echo "{\"advisories\": []}"'

  run_in_project security-audit.sh

  [ -f "${PROJECT}/build/logs/audit-report.json" ]
}

# outdated.sh
# ================

OUTDATED_TWO='{"installed": [{"name": "acme/lib", "version": "1.0"}, {"name": "acme/other", "version": "2.0"}]}'

@test "outdated.sh passes when nothing is outdated" {
  fake_command composer 'echo "{\"installed\": []}"'

  run_in_project outdated.sh

  [ "$status" -eq 0 ]
  [[ "$output" == *"All dependencies are up to date"* ]]
}

@test "outdated.sh fails and lists the outdated packages" {
  fake_command composer "echo '${OUTDATED_TWO}'"

  run_in_project outdated.sh

  [ "$status" -eq 1 ]
  [[ "$output" == *"Outdated packages found"* ]]
  [[ "$output" == *"acme/lib"* ]]
}

@test "outdated.sh ignores the packages of OUTDATED_IGNORE" {
  fake_command composer "echo '${OUTDATED_TWO}'"
  export OUTDATED_IGNORE="acme/lib, acme/other"

  run_in_project outdated.sh

  [ "$status" -eq 0 ]
  [[ "$output" == *"All dependencies are up to date"* ]]
}

@test "outdated.sh ignores the packages of outdatedIgnore in composer.json" {
  fake_command composer "echo '${OUTDATED_TWO}'"
  printf '{\n  "name": "acme/sample",\n  "outdatedIgnore": ["acme/lib"]\n}\n' > "${PROJECT}/composer.json"

  run_in_project outdated.sh

  [ "$status" -eq 1 ]
  [[ "$output" != *"acme/lib\""* ]]
  [[ "$output" == *"acme/other"* ]]
}

# enlightn.sh
# ================

@test "enlightn.sh skips when the package is not installed" {
  run_in_project enlightn.sh

  [ "$status" -eq 0 ]
  [[ "$output" == *"Skipping Enlightn (package not installed)"* ]]
}

@test "enlightn.sh skips in a package, where there is no Laravel application" {
  printf '{\n  "require-dev": {"enlightn/enlightn": "*"}\n}\n' > "${PROJECT}/composer.json"

  run_in_project enlightn.sh

  [ "$status" -eq 0 ]
  [[ "$output" == *"Detected package context"* ]]
}

@test "enlightn.sh runs the artisan command of an application and passes the --ci option" {
  printf '{\n  "require": {"laravel/framework": "*"},\n  "require-dev": {"enlightn/enlightn": "*"}\n}\n' > "${PROJECT}/composer.json"
  touch "${PROJECT}/artisan"
  fake_command php 'echo "php $*" > "${PROJECT_LOG}"'
  export PROJECT_LOG="${TEST_TEMP_DIR}/php.log"

  run_in_project enlightn.sh --ci

  [ "$status" -eq 0 ]
  [[ "$output" == *"Enlightn analysis passed"* ]]
  [ "$(cat "${PROJECT_LOG}")" = "php artisan enlightn --ci" ]
}

@test "enlightn.sh fails when the analysis finds issues" {
  printf '{\n  "require": {"laravel/framework": "*"},\n  "require-dev": {"enlightn/enlightn": "*"}\n}\n' > "${PROJECT}/composer.json"
  touch "${PROJECT}/artisan"
  fake_command php 'exit 1'

  run_in_project enlightn.sh

  [ "$status" -eq 1 ]
  [[ "$output" == *"Enlightn found security or performance issues"* ]]
}

# phpmetrics.sh
# ================

@test "phpmetrics.sh fails when PHPMetrics is not installed" {
  export PHPMETRICS_BIN="${TEST_TEMP_DIR}/missing/phpmetrics"

  run_in_project phpmetrics.sh

  [ "$status" -eq 1 ]
  [[ "$output" == *"PHPMetrics not found"* ]]
}

@test "phpmetrics.sh analyses src/ in a package" {
  fake_command phpmetrics 'echo "$*" > "${PROJECT_LOG}"'
  export PHPMETRICS_BIN=phpmetrics
  export PROJECT_LOG="${TEST_TEMP_DIR}/phpmetrics.log"

  run_in_project phpmetrics.sh

  [ "$status" -eq 0 ]
  [[ "$output" == *"Detected package context (analysing src/)"* ]]
  [[ "$(cat "${PROJECT_LOG}")" == *" src/" ]]
}

@test "phpmetrics.sh analyses app/ in a Laravel application" {
  printf '{\n  "require": {"laravel/framework": "*"}\n}\n' > "${PROJECT}/composer.json"
  touch "${PROJECT}/artisan"
  fake_command phpmetrics 'echo "$*" > "${PROJECT_LOG}"'
  export PHPMETRICS_BIN=phpmetrics
  export PROJECT_LOG="${TEST_TEMP_DIR}/phpmetrics.log"

  run_in_project phpmetrics.sh

  [ "$status" -eq 0 ]
  [[ "$output" == *"analysing app/"* ]]
  [[ "$(cat "${PROJECT_LOG}")" == *" app/" ]]
}

@test "phpmetrics.sh fails when PHPMetrics fails" {
  fake_command phpmetrics 'exit 2'
  export PHPMETRICS_BIN=phpmetrics

  run_in_project phpmetrics.sh

  [ "$status" -eq 1 ]
  [[ "$output" == *"PHPMetrics encountered errors"* ]]
}
