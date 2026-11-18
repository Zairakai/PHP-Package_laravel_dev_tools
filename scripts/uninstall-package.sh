#!/usr/bin/env bash
#
# Zairakai Laravel Dev Tools - Package Uninstall Script
# Removes the files installed by setup-package.sh, and only the ones that are still what the package publishes.
#
# Usage:
#   bash uninstall-package.sh             # Interactive mode (shows the files, asks before removing)
#   bash uninstall-package.sh --dry-run   # Lists the files, removes nothing
#   bash uninstall-package.sh --force     # Removes without asking, the files that the project changed included
#

set -euo pipefail

# Bootstrap
# ================

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=config.sh
source "${SCRIPT_DIR}/config.sh"
maybe_dockerize "$0" "$@"

# Arguments Parsing
# ================

FORCE_MODE=false
# DRY_RUN=true in the environment does the same as --dry-run (`DRY_RUN=true make uninstall`)
DRY_RUN="${DRY_RUN:-false}"

for arg in "$@"; do
  case "$arg" in
    --force|-f)
      FORCE_MODE=true
      ;;
    --dry-run|-n)
      DRY_RUN=true
      ;;
    --help|-h)
      echo "Usage: $0 [--dry-run] [--force]"
      echo ""
      echo "Removes the files installed by setup-package.sh that are still what the package publishes:"
      echo "  - Makefile, .editorconfig, pint.json, phpunit.xml, .markdownlintignore"
      echo "  - the thin file .markdownlint.json (it points at config/dev-tools/)"
      echo "  - config/dev-tools/ (the files that the project changed only after confirmation)"
      echo "  - the Composer scripts of the package in composer.json (a script that was changed stays)"
      echo "  - the block of the dev-tools in .gitignore"
      echo "  - core.hooksPath, when it is the one of laravel-dev-tools"
      echo ""
      echo "A file that the project changed is kept and named. Everything that is removed is copied first in"
      echo ".dev-tools-backup/<date>/, under its path from the root, and that folder is kept: to put everything"
      echo "back, copy it at the root (cp -R .dev-tools-backup/<date>/. .)."
      echo ""
      echo "Options:"
      echo "  --dry-run, -n  List the files, remove nothing"
      echo "  --force, -f    Remove without confirmation, the files of config/dev-tools/ that were changed included"
      echo "  --help, -h     Show this help message"
      exit 0
      ;;
    *)
      log_error "Unknown option: $arg"
      echo "Use --help for usage information"
      exit 1
      ;;
  esac
done

# Helpers
# ================

# The files that the uninstall would remove or keep, one per line: "remove: <file>", "keep: <file> (<reason>)"
plan() {
  php "${SCRIPT_DIR}/uninstall.php" "$PROJECT_ROOT" "$(dirname "$SCRIPT_DIR")" --dry-run "$@"
}

# The core.hooksPath of the project, when it is the one of laravel-dev-tools (or the older .githooks): it is reset
reset_hooks_path() {
  [[ -d "${PROJECT_ROOT}/.git" ]] || return 0

  local current
  current="$(cd "${PROJECT_ROOT}" && git config core.hooksPath 2> /dev/null || true)"

  if [[ "$current" == "$HOOKS_PATH" ]] || [[ "$current" == ".githooks" ]]; then
    if [[ "$DRY_RUN" == "true" ]]; then
      log_info "  would reset git config core.hooksPath (${current})"
    else
      (cd "${PROJECT_ROOT}" && git config --unset core.hooksPath)
      log_success "  Reset git config core.hooksPath to default"
    fi
  fi
}

# Main
# ================

uninstall_package() {
  log_header "🗑️  Package Development Uninstall"

  local lines changed
  local -a mode=()
  [[ "$FORCE_MODE" == "true" ]] && mode+=(--customized)
  lines="$(plan "${mode[@]}")"

  if [[ -z "$lines" ]]; then
    log_info "Nothing to remove: no file of the setup is left"
    reset_hooks_path
    exit 0
  fi

  # With --force the removal itself lists the files: the plan is shown to ask, or for a dry run
  if [[ "$FORCE_MODE" != "true" ]] || [[ "$DRY_RUN" == "true" ]]; then
    while IFS= read -r line; do
      case "$line" in
        remove:*) log_info "  ${line}" ;;
        keep:*) log_warning "  ${line}" ;;
      esac
    done <<< "$lines"
    echo ""
  fi

  # The files of config/dev-tools/ that the project changed, and the baseline: removing them loses what the project wrote
  changed="$(grep -c '^keep: config/dev-tools/.*(customized)' <<< "$lines" || true)"

  if [[ "$DRY_RUN" == "true" ]]; then
    log_info "Dry run: nothing was removed"
    reset_hooks_path
    exit 0
  fi

  local extra=()

  if [[ "$FORCE_MODE" == "true" ]]; then
    extra+=(--customized)
  else
    read -r -p "Remove these files? [y/N] " response
    [[ "$response" =~ ^[Yy]$ ]] || {
      log_info "Uninstall cancelled"
      exit 0
    }

    if [[ "$changed" -gt 0 ]]; then
      echo ""
      read -r -p "Remove the ${changed} file(s) of config/dev-tools/ that were changed too (a copy is kept)? [y/N] " response
      [[ "$response" =~ ^[Yy]$ ]] && extra+=(--customized)
    fi
  fi

  echo ""
  log_step "Removing…"
  local result backup=""
  result="$(php "${SCRIPT_DIR}/uninstall.php" "$PROJECT_ROOT" "$(dirname "$SCRIPT_DIR")" "${extra[@]}")"

  while IFS= read -r line; do
    case "$line" in
      remove:*) log_success "  ${line}" ;;
      keep:*) log_warning "  ${line}" ;;
      backup:*) backup="${line#backup: }" ;;
    esac
  done <<< "$result"

  reset_hooks_path

  echo ""
  log_header "✅ Uninstall completed"
  echo ""

  if [[ -n "$backup" ]]; then
    log_info "Everything that was removed is copied in ${backup#"${PROJECT_ROOT}"/}/"
    log_info "To put it back: cp -R ${backup#"${PROJECT_ROOT}"/}/. ."
    echo ""
  fi
}

# Execute
# ================

uninstall_package
