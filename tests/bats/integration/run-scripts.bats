#!/usr/bin/env bats
#
# Integration Tests for scripts/bats-run.sh and scripts/validate-shellcheck.sh
#
# Each test builds a small project with its own Bats files or shell scripts and runs the script on it.
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

# A Bats file with the given tests (name:body pairs are written by the caller)
write_bats() {
  mkdir -p "${PROJECT}/tests/bats"
  printf '#!/usr/bin/env bats\n%s\n' "$2" > "${PROJECT}/tests/bats/$1.bats"
}

# bats-run.sh
# ================

@test "bats-run.sh skips when there is no Bats test" {
  run_in_project bats-run.sh

  [ "$status" -eq 0 ]
  [[ "$output" == *"No Bats tests found"* ]]
}

@test "bats-run.sh passes and counts the tests" {
  write_bats one '@test "first" { true; }
@test "second" { true; }'

  run_in_project bats-run.sh

  [ "$status" -eq 0 ]
  [[ "$output" == *"2 Bats tests passed"* ]]
}

@test "bats-run.sh fails and shows only the test that failed" {
  write_bats one '@test "good one" { true; }
@test "bad one" { false; }'

  run_in_project bats-run.sh

  [ "$status" -eq 1 ]
  [[ "$output" == *"not ok 2 bad one"* ]]
  [[ "$output" != *"good one"* ]]
  [[ "$output" == *"1 Bats test(s) failed"* ]]
}

@test "bats-run.sh shows every test with VERBOSE=true" {
  write_bats one '@test "good one" { true; }'

  VERBOSE=true run_in_project bats-run.sh

  [ "$status" -eq 0 ]
  [[ "$output" == *"ok 1 good one"* ]]
}

@test "bats-run.sh fails when Bats is not installed" {
  # PATH with the tools the script needs, but not bats
  local bin="${TEST_TEMP_DIR}/no-bats"
  mkdir -p "$bin"
  for tool in bash dirname cat mktemp rm find sort grep sed head awk cut sleep kill env date mkdir tr uname git; do
    ln -sf "$(command -v "$tool")" "${bin}/${tool}" 2> /dev/null || true
  done

  cd "${PROJECT}" || return 1
  PATH="$bin" run /bin/bash "${PROJECT}/scripts/bats-run.sh"

  [ "$status" -eq 1 ]
  [[ "$output" == *"Bats is not installed"* ]]
}

# validate-shellcheck.sh
# ================

@test "validate-shellcheck.sh passes on a clean script" {
  printf '#!/usr/bin/env bash\necho "ok"\n' > "${PROJECT}/clean.sh"

  run_in_project validate-shellcheck.sh

  [ "$status" -eq 0 ]
  [[ "$output" == *"passed ShellCheck validation"* ]]
}

@test "validate-shellcheck.sh fails and names the script that does not pass" {
  printf '#!/usr/bin/env bash\ncd $1\nrm -rf $2/*\n' > "${PROJECT}/bad.sh"

  run_in_project validate-shellcheck.sh

  [ "$status" -eq 1 ]
  [[ "$output" == *"bad.sh - ShellCheck failed"* ]]
}

@test "validate-shellcheck.sh uses the severity of SHELLCHECK_SEVERITY" {
  printf '#!/usr/bin/env bash\nrm -rf $1/*\n' > "${PROJECT}/loose.sh"

  SHELLCHECK_SEVERITY=error run_in_project validate-shellcheck.sh

  [ "$status" -eq 0 ]
}
