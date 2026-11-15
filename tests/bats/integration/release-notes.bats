#!/usr/bin/env bats
#
# Integration Tests for .gitlab/scripts/generate-release-notes.sh
#
# The tests build repositories with commits and tags. One of them is a shallow
# clone of a tag, which is what the job
# php:release has: only the tag of the pipeline, so the previous tag is unknown
# unless the script fetches the history.
#

# Load test helpers
load '../helpers/test_helper'

setup() {
  setup_test_env

  export SCRIPT="${PROJECT_ROOT}/.gitlab/scripts/generate-release-notes.sh"
  export REPO="${TEST_TEMP_DIR}/repository"

  mkdir -p "$REPO"
  git -C "$REPO" init -q .
}

teardown() {
  teardown_test_env
}

# Git without the global configuration of the machine: no signature, a fixed
# author
git_in() {
  local directory="$1"
  shift

  git -C "$directory" \
    -c user.name=test -c user.email=test@example.com \
    -c commit.gpgsign=false -c tag.gpgsign=false "$@"
}

# commit <subject> [<paragraph>...]: one commit, each paragraph of the body is a
# -m
commit() {
  local arguments=()

  for message in "$@"; do
    arguments+=(-m "$message")
  done

  echo "$RANDOM" >> "${REPO}/file.txt"
  git_in "$REPO" add -A
  git_in "$REPO" commit -q "${arguments[@]}"
}

tag() {
  git_in "$REPO" tag -a -m "$1" "$1"
}

generate() {
  run bash -c "cd '${REPO}' && bash '${SCRIPT}' $*"
}

notes() {
  cat "${REPO}/release_notes.md"
}

# The range of the notes
# ================

@test "the notes list the commits since the previous tag, by type, with their ticket at the end" {
  commit "feat(core): #1 add the first thing"
  tag 1.0.0
  commit "feat(core): #2 add the second thing"
  commit "fix(core): #3 repair the third thing"
  tag 1.1.0

  generate

  [ "$status" -eq 0 ]
  [[ "$output" == *"From: 1.0.0"* ]]
  run notes
  [[ "$output" == *"### ✨ Features"* ]]
  [[ "$output" == *"- add the second thing (#2)"* ]]
  [[ "$output" == *"### 🐛 Bug Fixes"* ]]
  [[ "$output" == *"- repair the third thing (#3)"* ]]
  [[ "$output" != *"add the first thing"* ]]
  [[ "$output" == *"**Full Changelog**: 1.0.0...1.1.0"* ]]
}

@test "the notes are found in a shallow clone of a tag, which is the case of the CI" {
  commit "feat(core): #1 add the first thing"
  tag 1.0.0
  commit "feat(core): #2 add the second thing"
  tag 1.1.0

  git clone -q --depth 1 --branch 1.1.0 "file://${REPO}" "${TEST_TEMP_DIR}/ci"
  run git -C "${TEST_TEMP_DIR}/ci" tag
  [ "$output" = "1.1.0" ]

  run bash -c "cd '${TEST_TEMP_DIR}/ci' && bash '${SCRIPT}'"

  [ "$status" -eq 0 ]
  [[ "$output" == *"From: 1.0.0"* ]]
  run cat "${TEST_TEMP_DIR}/ci/release_notes.md"
  [[ "$output" == *"- add the second thing (#2)"* ]]
}

@test "the tag before the one with the old v prefix is found" {
  commit "feat: #1 add the first thing"
  tag v0.9.0
  commit "feat: #2 add the second thing"
  tag 1.0.0

  generate

  [[ "$output" == *"From: v0.9.0"* ]]
}

@test "a tag that is not a version is not taken as the previous tag" {
  commit "feat: #1 add the first thing"
  tag 1.0.0
  commit "feat: #2 add the second thing"
  tag nightly
  commit "feat: #3 add the third thing"
  tag 1.1.0

  generate

  [[ "$output" == *"From: 1.0.0"* ]]
  run notes
  [[ "$output" == *"add the second thing"* ]]
}

@test "a pre-release comes before its release" {
  commit "feat: #1 add the first thing"
  tag 0.9.0
  commit "feat: #2 add the second thing"
  tag 1.0.0-rc.1
  commit "fix: #3 repair the third thing"
  tag 1.0.0

  generate "''" 1.0.0

  [[ "$output" == *"From: 1.0.0-rc.1"* ]]

  generate "''" 1.0.0-rc.1

  [[ "$output" == *"From: 0.9.0"* ]]
}

@test "a first release lists all its commits" {
  commit "feat: #1 add the first thing"
  commit "fix: #2 repair the second thing"
  tag 1.0.0

  generate

  [ "$status" -eq 0 ]
  [[ "$output" == *"From: (first release)"* ]]
  run notes
  [[ "$output" == *"- add the first thing (#1)"* ]]
  [[ "$output" == *"- repair the second thing (#2)"* ]]
  [[ "$output" == *"**First release**: 1.0.0"* ]]
}

@test "the notes are never empty, also when the range has no commit" {
  commit "feat: #1 add the first thing"
  tag 1.0.0
  tag 1.0.1

  generate 1.0.0 1.0.1

  [ "$status" -eq 0 ]
  [ -s "${REPO}/release_notes.md" ]
  run notes
  [[ "$output" == *"No commit since 1.0.0."* ]]
}

@test "the script does not write changelog_entry.md" {
  commit "feat: #1 add the first thing"
  tag 1.0.0

  generate

  [ ! -e "${REPO}/changelog_entry.md" ]
}

# The breaking changes
# ================

@test "a breaking change is one bullet, with its migration under it" {
  commit "feat: #1 add the first thing"
  tag 1.0.0
  commit "refactor(config): #2 rename the files" \
    "This text is the body of the commit and has nothing to do with the break." \
    "BREAKING CHANGE: the files have new names and are published
to the root of the project." \
    "Migration: publish them again."
  tag 2.0.0

  generate

  run notes
  [[ "$output" == *"### ⚠️ BREAKING CHANGES"* ]]
  [[ "$output" == *"- the files have new names and are published to the root of the project."* ]]
  [[ "$output" == *"  Migration: publish them again."* ]]
  [[ "$output" != *"has nothing to do with the break"* ]]
}

@test "there is no section of breaking changes when no commit breaks" {
  commit "feat: #1 add the first thing"
  tag 1.0.0
  commit "fix: #2 repair the second thing"
  tag 1.0.1

  generate

  run notes
  [[ "$output" != *"BREAKING CHANGES"* ]]
}

# The issues
# ================

@test "the issues are the tickets of the subjects and the ones that the footers close" {
  commit "feat: #1 add the first thing"
  tag 1.0.0
  commit "feat: #12 add the second thing"
  commit "fix: repair the third thing" "Closes #7, #9"
  tag 1.1.0

  generate

  run notes
  [[ "$output" == *"- #7"* ]]
  [[ "$output" == *"- #9"* ]]
  [[ "$output" == *"- #12"* ]]
}

# The job of the pipeline
# ================

@test "the job php:release fetches the whole history and refuses empty notes" {
  local job
  job=$(awk '/^php:release:/,/^# METRICS/' "${PROJECT_ROOT}/.gitlab/ci/pipeline-php-package.yml")

  [[ "$job" == *"GIT_DEPTH: 0"* ]]
  [[ "$job" == *"[ ! -s release_notes.md ]"* ]]
}
