#!/usr/bin/env bats
#
# Integration Tests for scripts/install-bats.sh, install-shellcheck.sh and install-markdownlint.sh
#
# The downloads are not tested: the tests load the scripts as libraries and check how they choose a version.
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

# Runs a function of an install script, loaded without running its main
call() {
  local script="$1"
  shift

  cd "${PROJECT}" || return 1
  run bash -c 'source "$1"; shift; "$@"' _ "${PROJECT}/scripts/${script}" "$@"
}

versions() {
  printf '%s\n' "$@" > "${PROJECT}/.tool-versions"
}

@test "install-bats.sh reads the version of .tool-versions and adds the v prefix" {
  versions "bats=1.10.0"

  call install-bats.sh get_bats_version

  [ "$status" -eq 0 ]
  [[ "$output" == *"v1.10.0" ]]
}

@test "install-bats.sh uses its default version when .tool-versions has none" {
  versions "shellcheck=0.9.0"

  call install-bats.sh get_bats_version

  [ "$status" -eq 0 ]
  [[ "$output" == *"v1.11.0" ]]
}

@test "install-bats.sh does not run its main when it is loaded" {
  call install-bats.sh true

  [ "$status" -eq 0 ]
  [[ "$output" != *"Installing"* ]]
}

@test "install-shellcheck.sh reads the version of .tool-versions" {
  versions "shellcheck=0.8.0"

  call install-shellcheck.sh get_shellcheck_version

  [ "$status" -eq 0 ]
  [[ "$output" == *"0.8.0" ]]
}

@test "install-shellcheck.sh uses its default version when .tool-versions has none" {
  versions "bats=1.11.0"

  call install-shellcheck.sh get_shellcheck_version

  [ "$status" -eq 0 ]
  [[ "$output" == *"0.10.0" ]]
}

@test "install-markdownlint.sh reads the version of .tool-versions" {
  versions "markdownlint-cli2=0.13.0"

  call install-markdownlint.sh get_markdownlint_version

  [ "$status" -eq 0 ]
  [ "$output" = "0.13.0" ]
}

@test "install-markdownlint.sh asks for the latest version when .tool-versions has none" {
  versions "bats=1.11.0"

  call install-markdownlint.sh get_markdownlint_version

  [ "$status" -eq 0 ]
  [ "$output" = "latest" ]
}

@test "version_ge compares the numbers, not the text" {
  for script in install-bats.sh install-shellcheck.sh; do
    call "$script" version_ge 1.11.0 1.9.0
    [ "$status" -eq 0 ]

    call "$script" version_ge 1.9.0 1.11.0
    [ "$status" -eq 1 ]
  done
}

@test "version_ge accepts the same version, the v prefix and a missing part" {
  for script in install-bats.sh install-shellcheck.sh; do
    call "$script" version_ge v1.11.0 1.11.0
    [ "$status" -eq 0 ]

    call "$script" version_ge 1.11 1.11.0
    [ "$status" -eq 0 ]

    call "$script" version_ge 1.11 1.11.1
    [ "$status" -eq 1 ]
  done
}
