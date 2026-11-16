#!/usr/bin/env bats
#
# Integration Tests for tools/scripts/git-cleanup.sh, git-update.sh and git-rebase.sh
#
# Each test builds a remote (bare) and a clone, then runs the script in the clone.
#

# Load test helpers
load '../helpers/test_helper'

setup() {
  setup_test_env

  export REMOTE="${TEST_TEMP_DIR}/remote.git"
  export CLONE="${TEST_TEMP_DIR}/clone"
  export OTHER="${TEST_TEMP_DIR}/other"

  git init --bare --initial-branch=main "$REMOTE" > /dev/null 2>&1
  git clone "$REMOTE" "$CLONE" > /dev/null 2>&1
  git clone "$REMOTE" "$OTHER" > /dev/null 2>&1

  for repo in "$CLONE" "$OTHER"; do
    git -C "$repo" config user.name "Test User"
    git -C "$repo" config user.email "test@example.com"
    git -C "$repo" config commit.gpgsign false
  done

  git -C "$CLONE" checkout -q -b main 2> /dev/null || true
  commit "$CLONE" first
  git -C "$CLONE" push -q -u origin main 2> /dev/null
  git -C "$OTHER" pull -q origin main 2> /dev/null
}

teardown() {
  teardown_test_env
}

# Commits a file named after the message
commit() {
  local repo="$1" name="$2"

  echo "$name" > "${repo}/${name}.txt"
  git -C "$repo" add "${name}.txt"
  git -C "$repo" commit -q -m "$name"
}

# A branch pushed, then its remote branch deleted from the other clone
branch_with_gone_remote() {
  local name="$1"

  git -C "$CLONE" checkout -q -b "$name"
  commit "$CLONE" "$name"
  git -C "$CLONE" push -q -u origin "$name" 2> /dev/null
  git -C "$CLONE" checkout -q main
}

run_script() {
  local script="$1" input="${2:-}"
  shift 2 || shift $#

  cd "$CLONE" || return 1
  run bash -c "printf '%s' \"\$1\" | bash \"\$2\"" _ "$input" "${PROJECT_ROOT}/tools/scripts/${script}"
}

# git-cleanup.sh
# ================

@test "git-cleanup.sh says that there is nothing to clean" {
  run_script git-cleanup.sh

  [ "$status" -eq 0 ]
  [[ "$output" == *"No branches with deleted remotes found"* ]]
}

@test "git-cleanup.sh deletes a merged branch whose remote is gone" {
  branch_with_gone_remote done-branch
  git -C "$CLONE" merge -q --no-ff done-branch -m merge
  git -C "$CLONE" push -q origin main 2> /dev/null
  git -C "$CLONE" push -q origin --delete done-branch 2> /dev/null
  git -C "$CLONE" fetch -q --prune

  run_script git-cleanup.sh y

  [ "$status" -eq 0 ]
  [[ "$output" == *"Deleted: done-branch"* ]]
  [ -z "$(git -C "$CLONE" branch --list done-branch)" ]
}

@test "git-cleanup.sh keeps a branch that is not merged and names the command to force" {
  branch_with_gone_remote lost-work
  git -C "$CLONE" push -q origin --delete lost-work 2> /dev/null
  git -C "$CLONE" fetch -q --prune

  run_script git-cleanup.sh y

  [ "$status" -eq 0 ]
  [[ "$output" == *"Kept (not merged): lost-work"* ]]
  [[ "$output" == *"git branch -D lost-work"* ]]
  [ -n "$(git -C "$CLONE" branch --list lost-work)" ]
}

@test "git-cleanup.sh deletes nothing when the answer is not y" {
  branch_with_gone_remote done-branch
  git -C "$CLONE" push -q origin --delete done-branch 2> /dev/null
  git -C "$CLONE" fetch -q --prune

  run_script git-cleanup.sh n

  [ "$status" -eq 0 ]
  [[ "$output" == *"Aborted. No branches deleted."* ]]
  [ -n "$(git -C "$CLONE" branch --list done-branch)" ]
}

@test "git-cleanup.sh does not delete the branch that is checked out" {
  branch_with_gone_remote current-one
  git -C "$CLONE" checkout -q current-one
  git -C "$CLONE" push -q origin --delete current-one 2> /dev/null
  git -C "$CLONE" fetch -q --prune

  run_script git-cleanup.sh y

  [ "$status" -eq 0 ]
  [[ "$output" == *"Skipping current-one (currently checked out)"* ]]
  [ -n "$(git -C "$CLONE" branch --list current-one)" ]
}

# git-update.sh
# ================

@test "git-update.sh fast-forwards the current branch and the others" {
  branch_with_gone_remote feature-a
  git -C "$OTHER" pull -q origin 2> /dev/null || true
  git -C "$OTHER" fetch -q
  git -C "$OTHER" checkout -q feature-a
  commit "$OTHER" on-feature
  git -C "$OTHER" push -q origin feature-a 2> /dev/null
  git -C "$OTHER" checkout -q main
  commit "$OTHER" on-main
  git -C "$OTHER" push -q origin main 2> /dev/null

  run_script git-update.sh

  [ "$status" -eq 0 ]
  [[ "$output" == *"Updated main"* ]]
  [[ "$output" == *"Updated feature-a"* ]]
  [ -f "${CLONE}/on-main.txt" ]
  [ "$(git -C "$CLONE" branch --show-current)" = "main" ]
  [ "$(git -C "$CLONE" rev-parse feature-a)" = "$(git -C "$CLONE" rev-parse origin/feature-a)" ]
}

@test "git-update.sh skips a branch that diverged and goes on" {
  branch_with_gone_remote feature-a
  git -C "$CLONE" checkout -q feature-a
  commit "$CLONE" local-only
  git -C "$OTHER" fetch -q
  git -C "$OTHER" checkout -q feature-a
  commit "$OTHER" remote-only
  git -C "$OTHER" push -q origin feature-a 2> /dev/null
  git -C "$CLONE" checkout -q main

  run_script git-update.sh

  [ "$status" -eq 0 ]
  [[ "$output" == *"Cannot fast-forward feature-a"* ]]
  [[ "$output" == *"Update completed"* ]]
  [ "$(git -C "$CLONE" branch --show-current)" = "main" ]
}

@test "git-update.sh refuses a dirty working tree" {
  echo changed >> "${CLONE}/first.txt"

  run_script git-update.sh

  [ "$status" -eq 1 ]
  [[ "$output" == *"Working tree dirty"* ]]
}

# git-rebase.sh
# ================

@test "git-rebase.sh refuses a detached HEAD" {
  git -C "$CLONE" checkout -q --detach

  run_script git-rebase.sh 1

  [ "$status" -eq 1 ]
  [[ "$output" == *"Detached HEAD"* ]]
}

@test "git-rebase.sh refuses a dirty working tree" {
  echo changed >> "${CLONE}/first.txt"

  run_script git-rebase.sh 1

  [ "$status" -eq 1 ]
  [[ "$output" == *"Working tree dirty"* ]]
}

@test "git-rebase.sh refuses an input that is not a number" {
  run_script git-rebase.sh $'abc\n'

  [ "$status" -eq 1 ]
  [[ "$output" == *"Invalid input."* ]]
}

@test "git-rebase.sh refuses a number out of the list" {
  run_script git-rebase.sh $'99\n'

  [ "$status" -eq 1 ]
  [[ "$output" == *"Invalid choice: enter a number from 1 to"* ]]
}

@test "git-rebase.sh refuses zero" {
  run_script git-rebase.sh $'0\n'

  [ "$status" -eq 1 ]
  [[ "$output" == *"Invalid choice"* ]]
}

@test "git-rebase.sh refuses to rebase a branch onto itself" {
  run_script git-rebase.sh $'1\n'

  [ "$status" -eq 1 ]
  [[ "$output" == *"Cannot rebase onto the same branch."* ]]
}

@test "git-rebase.sh rebases onto the chosen branch and skips the push when the answer is not y" {
  git -C "$CLONE" checkout -q -b topic
  commit "$CLONE" topic-work
  git -C "$CLONE" checkout -q main
  commit "$CLONE" main-work
  git -C "$CLONE" checkout -q topic

  # Local branches are listed first: main is 1, topic is 2
  run_script git-rebase.sh $'1\nn'

  [ "$status" -eq 0 ]
  [[ "$output" == *"Rebase OK"* ]]
  [[ "$output" == *"Push skipped."* ]]
  [ -f "${CLONE}/main-work.txt" ]
  [ -f "${CLONE}/topic-work.txt" ]
}

@test "git-rebase.sh stops on a conflict and tells how to go on" {
  git -C "$CLONE" checkout -q -b topic
  echo topic > "${CLONE}/clash.txt"
  git -C "$CLONE" add clash.txt && git -C "$CLONE" commit -q -m topic-clash
  git -C "$CLONE" checkout -q main
  echo main > "${CLONE}/clash.txt"
  git -C "$CLONE" add clash.txt && git -C "$CLONE" commit -q -m main-clash
  git -C "$CLONE" checkout -q topic

  run_script git-rebase.sh $'1\n'

  [ "$status" -eq 1 ]
  [[ "$output" == *"Conflict"* ]]
  [[ "$output" == *"git rebase --abort"* ]]
}
