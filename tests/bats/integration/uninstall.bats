#!/usr/bin/env bats
#
# Integration Tests for scripts/uninstall-package.sh
#
# The tests build a project with the layout of an installed package, set it up, then uninstall it.
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

uninstall() {
  cd "${PROJECT}" || return 1
  run bash "${PACKAGE}/scripts/uninstall-package.sh" "$@"
}

@test "uninstall-package.sh --dry-run lists the files and removes nothing" {
  uninstall --dry-run

  [ "$status" -eq 0 ]
  [[ "$output" == *"remove: Makefile"* ]]
  [[ "$output" == *"remove: .editorconfig"* ]]
  [[ "$output" == *"Dry run: nothing was removed"* ]]
  [ -f "${PROJECT}/Makefile" ]
  [ -d "${PROJECT}/config/dev-tools" ]
  [ ! -d "${PROJECT}/.dev-tools-backup" ]
}

@test "uninstall-package.sh does a dry run with DRY_RUN=true in the environment" {
  export DRY_RUN=true

  uninstall --force

  [ "$status" -eq 0 ]
  [[ "$output" == *"Dry run: nothing was removed"* ]]
  [ -f "${PROJECT}/Makefile" ]
  [ ! -d "${PROJECT}/.dev-tools-backup" ]
}

@test "uninstall-package.sh --force removes what is still what the package publishes" {
  uninstall --force

  [ "$status" -eq 0 ]

  for file in Makefile .editorconfig pint.json phpunit.xml phpstan.neon .markdownlint.json .markdownlintignore; do
    [ ! -e "${PROJECT}/${file}" ]
  done

  [ ! -d "${PROJECT}/config/dev-tools" ]
}

@test "uninstall-package.sh removes the scripts of the package and the block of .gitignore" {
  uninstall --force

  [ "$status" -eq 0 ]
  run grep -c '"scripts"' "${PROJECT}/composer.json"
  [ "$output" = "0" ]
  [ "$(cat "${PROJECT}/.gitignore")" = "vendor/" ]
}

@test "uninstall-package.sh copies everything that it removes, and tells how to put it back" {
  uninstall --force

  [ "$status" -eq 0 ]
  [[ "$output" == *"Everything that was removed is copied in .dev-tools-backup/"* ]]
  [[ "$output" == *"To put it back: cp -R .dev-tools-backup/"* ]]

  local backup
  backup="$(find "${PROJECT}/.dev-tools-backup" -mindepth 1 -maxdepth 1 -type d | head -n 1)"

  [ -f "${backup}/Makefile" ]
  [ -f "${backup}/composer.json" ]
  [ -f "${backup}/config/dev-tools/rector.php" ]
}

@test "what the uninstall copies puts the project back as it was" {
  cp "${PROJECT}/Makefile" "${TEST_TEMP_DIR}/Makefile.before"
  uninstall --force
  [ "$status" -eq 0 ]

  local backup
  backup="$(find "${PROJECT}/.dev-tools-backup" -mindepth 1 -maxdepth 1 -type d | head -n 1)"
  cp -R "${backup}/." "${PROJECT}/"

  run cmp "${PROJECT}/Makefile" "${TEST_TEMP_DIR}/Makefile.before"
  [ "$status" -eq 0 ]
  run bash "${PACKAGE}/scripts/setup-check.sh"
  [ "$status" -eq 0 ]
}

@test "the folder of the copies is ignored by git without a line in .gitignore" {
  git -C "${PROJECT}" init -q
  uninstall --force
  [ "$status" -eq 0 ]

  run git -C "${PROJECT}" status --porcelain --untracked-files=all
  [[ "$output" != *".dev-tools-backup"* ]]
}

@test "uninstall-package.sh keeps a file that the project changed and names it" {
  printf 'mine:\n' > "${PROJECT}/Makefile"

  uninstall --force

  [ "$status" -eq 0 ]
  [[ "$output" == *"keep: Makefile (customized)"* ]]
  [ "$(cat "${PROJECT}/Makefile")" = "mine:" ]
}

@test "uninstall-package.sh --force removes the changed files of config/dev-tools too, after a copy" {
  printf '<?php // mine\n' > "${PROJECT}/config/dev-tools/rector.php"

  uninstall --force

  [ "$status" -eq 0 ]
  [ ! -e "${PROJECT}/config/dev-tools/rector.php" ]

  local backup
  backup="$(find "${PROJECT}/.dev-tools-backup" -mindepth 1 -maxdepth 1 -type d | head -n 1)"
  run grep -c '// mine' "${backup}/config/dev-tools/rector.php"
  [ "$output" = "1" ]
}

@test "uninstall-package.sh asks before it removes, and a no changes nothing" {
  cd "${PROJECT}" || return 1
  run bash -c "printf 'n\n' | bash '${PACKAGE}/scripts/uninstall-package.sh'"

  [ "$status" -eq 0 ]
  [[ "$output" == *"Uninstall cancelled"* ]]
  [ -f "${PROJECT}/Makefile" ]
  [ ! -d "${PROJECT}/.dev-tools-backup" ]
}

@test "uninstall-package.sh keeps the changed files of config/dev-tools when the second question gets a no" {
  printf '<?php // mine\n' > "${PROJECT}/config/dev-tools/rector.php"

  cd "${PROJECT}" || return 1
  run bash -c "printf 'y\nn\n' | bash '${PACKAGE}/scripts/uninstall-package.sh'"

  [ "$status" -eq 0 ]
  [ -f "${PROJECT}/config/dev-tools/rector.php" ]
  [ ! -e "${PROJECT}/Makefile" ]
}

@test "uninstall-package.sh resets core.hooksPath when it is the one of laravel-dev-tools" {
  git -C "${PROJECT}" init -q
  git -C "${PROJECT}" config core.hooksPath "vendor/zairakai/laravel-dev-tools/stubs/githooks"

  uninstall --force

  [ "$status" -eq 0 ]
  run git -C "${PROJECT}" config --get core.hooksPath
  [ -z "$output" ]
}

@test "uninstall-package.sh leaves another core.hooksPath alone" {
  git -C "${PROJECT}" init -q
  git -C "${PROJECT}" config core.hooksPath ".my-hooks"

  uninstall --force

  [ "$status" -eq 0 ]
  run git -C "${PROJECT}" config --get core.hooksPath
  [ "$output" = ".my-hooks" ]
}

@test "uninstall-package.sh has nothing to do after it ran" {
  uninstall --force
  [ "$status" -eq 0 ]

  uninstall --force

  [ "$status" -eq 0 ]
  [[ "$output" == *"Nothing to remove"* ]]
}

@test "uninstall-package.sh refuses an unknown option" {
  uninstall --nope

  [ "$status" -eq 1 ]
  [[ "$output" == *"Unknown option: --nope"* ]]
}

@test "uninstall-package.sh keeps a symbolic link and what it points at" {
  printf '# shared\n' > "${TEST_TEMP_DIR}/shared-editorconfig"
  rm "${PROJECT}/.editorconfig"
  ln -s "${TEST_TEMP_DIR}/shared-editorconfig" "${PROJECT}/.editorconfig"

  uninstall --force

  [ "$status" -eq 0 ]
  [[ "$output" == *"keep: .editorconfig (symbolic link)"* ]]
  [ -L "${PROJECT}/.editorconfig" ]
  [ "$(cat "${TEST_TEMP_DIR}/shared-editorconfig")" = "# shared" ]
}

