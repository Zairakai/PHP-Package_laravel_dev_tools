#!/usr/bin/env bash
#
# Zairakai Laravel Dev Tools - Package Setup Script
#
# Usage:
#   bash setup-package.sh                        # Normal setup (Makefile + .editorconfig + baseline)
#   bash setup-package.sh --publish              # Publish all configs to config/dev-tools/
#   bash setup-package.sh --publish=quality      # Publish quality group
#   bash setup-package.sh --publish=pint         # Publish specific config
#   bash setup-package.sh --with-hooks           # Also activate the git hooks of laravel-dev-tools
#   bash setup-package.sh --fullstack            # Force full-stack Makefile + CI stub (PHP + JS)
#   bash setup-package.sh --force                # Force overwrite existing files
#   bash setup-package.sh --silent               # Suppress output (errors only)
#   bash setup-package.sh --help                 # Show this help
#

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=config.sh
source "${SCRIPT_DIR}/config.sh"

# Arguments Parsing
# ================

FORCE_OVERWRITE=false
SILENT_MODE=false
PUBLISH_TARGET=""
WITH_HOOKS=false
FORCE_FULLSTACK=false

if [[ "${CI:-false}" == "true" ]] || \
   [[ "${GITLAB_CI:-false}" == "true" ]] || \
   [[ "${GITHUB_ACTIONS:-false}" == "true" ]]; then
  CI_MODE=true
else
  CI_MODE=false
fi

for arg in "$@"; do
  case "$arg" in
    --force|-f)     FORCE_OVERWRITE=true ;;
    --silent|-s)    SILENT_MODE=true ;;
    --with-hooks)   WITH_HOOKS=true ;;
    --fullstack)    FORCE_FULLSTACK=true ;;
    --publish)      PUBLISH_TARGET="all" ;;
    --publish=*)    PUBLISH_TARGET="${arg#--publish=}" ;;
    --help|-h)
      echo ""
      echo "Usage: bash setup-package.sh [options]"
      echo ""
      echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
      echo "  Options"
      echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
      echo "  (no options)         Normal setup"
      echo "                       Installs: Makefile, .editorconfig, baseline.neon"
      echo "                       Uses full-stack Makefile when package.json is present"
      echo ""
      echo "  --publish            Publish ALL configs to config/dev-tools/ (and the files that the editor reads, at the root)"
      echo "  --publish=<group>    Publish a config group"
      echo "  --publish=<key>      Publish a specific config"
      echo "  --with-hooks         Also activate the git hooks of laravel-dev-tools"
      echo "  --fullstack          Force full-stack Makefile + CI stub (PHP + JS)"
      echo "  --force, -f          Overwrite existing files (creates backups)"
      echo "  --silent, -s         Suppress output (errors only)"
      echo "  --help, -h           Show this help"
      echo ""
      echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
      echo "  Groups (--publish=<group>)"
      echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
      echo "  quality    phpstan, phpstan-baseline, rector, insights"
      echo "  style      pint, markdownlint, markdownlintignore"
      echo "  testing    phpunit"
      echo "  hooks      git hooks of laravel-dev-tools (core.hooksPath)"
      echo "  gitlab-ci  .gitlab-ci.yml (opt-in, not included in 'all')"
      echo "  all        everything except gitlab-ci"
      echo ""
      echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
      echo "  Specific configs (--publish=<key>)"
      echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
      echo "  phpstan           → config/dev-tools/phpstan.neon (+ phpstan.neon at the root, thin)"
      echo "  phpstan-baseline  → config/dev-tools/baseline.neon"
      echo "  rector            → config/dev-tools/rector.php"
      echo "  insights          → config/dev-tools/phpinsights.php"
      echo "  pint              → config/dev-tools/pint.json (+ pint.json at the root, a copy)"
      echo "  markdownlint      → config/dev-tools/.markdownlint.json (+ .markdownlint.json at the root, thin)"
      echo "  markdownlintignore → config/dev-tools/.markdownlintignore (+ .markdownlintignore at the root, a copy)"
      echo "  phpunit           → config/dev-tools/phpunit.xml (+ phpunit.xml at the root, a copy)"
      echo "  hooks             → core.hooksPath"
      echo "  gitlab-ci         → .gitlab-ci.yml"
      echo "  governance        → SECURITY.md, CONTRIBUTING.md, CODE_OF_CONDUCT.md"
      echo ""
      exit 0
      ;;
    *)
      echo "ERROR: Unknown option: $arg" >&2
      echo "Use --help for usage information" >&2
      exit 1
      ;;
  esac
done

# Silent Mode Override (keep log_error active)
# ================

if [[ "$SILENT_MODE" == "true" ]]; then
  log_header()  { :; }
  log_step()    { :; }
  log_info()    { :; }
  log_success() { :; }
  log_warning() { :; }
fi

# Global Variables
# ================

VENDOR_PATH="${PROJECT_ROOT}/vendor/zairakai/laravel-dev-tools"

if [[ ! -d "${VENDOR_PATH}" ]]; then
  echo "ERROR: zairakai/laravel-dev-tools not found in vendor/" >&2
  echo "Run: composer require --dev zairakai/laravel-dev-tools" >&2
  exit 1
fi

# Result Tracking
# ================

declare -a CREATED_FILES=()
declare -a SKIPPED_FILES=()
declare -a BACKED_UP_FILES=()
declare -a PUBLISHED_FILES=()

track_created()   { CREATED_FILES+=("$1"); }
track_skipped()   { SKIPPED_FILES+=("$1"); }
track_backed_up() { BACKED_UP_FILES+=("$1"); }
track_published() { PUBLISHED_FILES+=("$1"); }

# File Helpers
# ================

# Copy file for standard setup (skips if exists unless force/CI)
setup_file() {
  local source="$1"
  local target="$2"
  local name="$3"

  if [[ "$CI_MODE" == "true" ]] || [[ "$FORCE_OVERWRITE" == "true" ]]; then
    if [[ -f "$target" ]] && [[ ! -L "$target" ]] && [[ "$CI_MODE" != "true" ]]; then
      if backup_file "$target" 2>/dev/null; then
        track_backed_up "$name"
      fi
    fi
    rm -f "$target" 2>/dev/null || true
    mkdir -p "$(dirname "$target")"
    if cp "$source" "$target" 2>/dev/null; then
      track_created "$name"
    fi
  else
    if [[ -f "$target" ]] || [[ -L "$target" ]]; then
      track_skipped "$name"
    else
      mkdir -p "$(dirname "$target")"
      if cp "$source" "$target" 2>/dev/null; then
        track_created "$name"
      fi
    fi
  fi
}

# Publication
# ================
# The work is done by scripts/publish.php (ConfigStubPublisher): the same code as `php artisan dev-tools:publish`, so the
# two entries give the same files. Groups and keys are listed there (--help of this script names them).

run_publish() {
  local target="$1"
  local -a args=("${PROJECT_ROOT}" "${VENDOR_PATH}" "$target")

  [[ "$FORCE_OVERWRITE" == "true" ]] && args+=(--force)
  [[ "$FORCE_FULLSTACK" == "true" ]] && args+=(--fullstack)

  php "${SCRIPT_DIR}/publish.php" "${args[@]}"
}

do_publish() {
  log_header "Publishing Dev Tools Configs"

  run_publish "$PUBLISH_TARGET" || exit 1
}

# Gitignore Updater
# ================

update_gitignore() {
  local gitignore="${PROJECT_ROOT}/.gitignore"
  [[ ! -f "$gitignore" ]] && return 0

  local changed=false

  # A project that already has the block, with this title or with the one of the older versions, is left as it is.
  if ! grep -qF -e "# Dev Tools temporary files" -e "# Dev Tools - Generated temporary files" "$gitignore" 2>/dev/null; then
    cat >> "$gitignore" << 'EOF'

# Dev Tools temporary files
# ----------------
.*.tmp.*
.dev-tools-backup/
EOF
    changed=true
  fi

  if [[ "$changed" == "true" ]]; then
    log_success "Updated .gitignore with dev-tools patterns"
  fi
}

# Main Setup
# ================

setup_package() {
  [[ "$SILENT_MODE" != "true" ]] && {
    echo ""
    echo -e "${MAGENTA}📦 Dev Tools Setup${NC}"
    echo ""
    if [[ "$CI_MODE" == "true" ]]; then
      echo -e "${CYAN}Mode: CI${NC}"
    elif [[ -n "$PUBLISH_TARGET" ]]; then
      echo -e "${CYAN}Mode: Publish (${PUBLISH_TARGET})${NC}"
    elif [[ "$FORCE_OVERWRITE" == "true" ]]; then
      echo -e "${YELLOW}Mode: Force (backup + overwrite)${NC}"
    else
      echo -e "${CYAN}Mode: Normal (skip existing)${NC}"
    fi
    echo ""
  }

  # Publish mode - handle and exit
  # ================

  if [[ -n "$PUBLISH_TARGET" ]]; then
    do_publish

    # Install hooks separately if also requested
    if [[ "$WITH_HOOKS" == "true" ]] && [[ "$PUBLISH_TARGET" != "hooks" ]] && [[ "$PUBLISH_TARGET" != "all" ]]; then
      run_publish hooks
    fi

    update_gitignore

    echo ""
    echo -e "${MAGENTA}┌────────────────────────────────────────┐${NC}"
    echo -e "${MAGENTA}│${NC}         ${GREEN}Publish Complete${NC}              ${MAGENTA}│${NC}"
    echo -e "${MAGENTA}├────────────────────────────────────────┤${NC}"

    for f in "${PUBLISHED_FILES[@]}"; do
      echo -e "${MAGENTA}│${NC} ${GREEN}✓${NC} ${f}"
    done

    if [[ ${#SKIPPED_FILES[@]} -gt 0 ]]; then
      echo -e "${MAGENTA}│${NC} ${YELLOW}○ Already exist (skipped):${NC} ${SKIPPED_FILES[*]}"
      echo -e "${MAGENTA}│${NC}   ${YELLOW}→ Use --force to overwrite${NC}"
    fi

    if [[ ${#BACKED_UP_FILES[@]} -gt 0 ]]; then
      echo -e "${MAGENTA}│${NC} ${CYAN}↩ Backed up:${NC} ${BACKED_UP_FILES[*]}"
      echo -e "${MAGENTA}│${NC}   ${CYAN}→ ${BACKUP_DIR}${NC}"
    fi

    echo -e "${MAGENTA}├────────────────────────────────────────┤${NC}"
    echo -e "${MAGENTA}│${NC} ${CYAN}Edit configs in:${NC} config/dev-tools/"
    echo -e "${MAGENTA}│${NC} ${CYAN}Help:${NC} bash setup-package.sh --help"
    echo -e "${MAGENTA}└────────────────────────────────────────┘${NC}"
    echo ""
    exit 0
  fi

  # Normal Setup
  # ================

  # Makefile (full-stack if @zairakai/js-dev-tools is installed or --fullstack forced)
  if [[ "$FORCE_FULLSTACK" == "true" ]] || [[ -d "${PROJECT_ROOT}/node_modules/@zairakai/js-dev-tools" ]]; then
    setup_file "${VENDOR_PATH}/stubs/Makefile.fullstack" "${PROJECT_ROOT}/Makefile" "Makefile"
  else
    setup_file "${VENDOR_PATH}/stubs/Makefile" "${PROJECT_ROOT}/Makefile" "Makefile"
  fi

  # .editorconfig (always copied - needed by IDEs)
  setup_file "${VENDOR_PATH}/.editorconfig" "${PROJECT_ROOT}/.editorconfig" ".editorconfig"

  # PHPStan baseline (created empty if missing - ensures phpstan configs can include it), then phpstan.neon adapted to the
  # type of the project
  run_publish baseline
  run_publish phpstan

  # Update .gitignore
  update_gitignore

  # Git Hooks (only if --with-hooks is explicitly requested)
  # ================

  local hooks_installed=0

  if [[ "$WITH_HOOKS" == "true" ]]; then
    run_publish hooks
    # The hooks are active when git reads the ones of laravel-dev-tools
    if [[ "$(cd "${PROJECT_ROOT}" && git config --get core.hooksPath 2>/dev/null || true)" == "$HOOKS_PATH" ]]; then
      hooks_installed=1
    fi
  fi

  # Composer Scripts merge
  # ================

  local force_flag=""
  [[ "$FORCE_OVERWRITE" == "true" ]] && force_flag="--force"
  php "${VENDOR_PATH}/scripts/merge-composer-scripts.php" \
    "${PROJECT_ROOT}" "${VENDOR_PATH}" ${force_flag} 2>/dev/null || true

  # Summary
  # ================

  local project_type
  project_type="$(detect_project_type)"

  if [[ "$SILENT_MODE" == "true" ]]; then
    if [[ ${#CREATED_FILES[@]} -eq 0 ]] && \
      [[ ${#BACKED_UP_FILES[@]} -eq 0 ]] && \
      [[ $hooks_installed -eq 0 ]]; then
      exit 0
    fi
  fi

  echo ""
  echo -e "${MAGENTA}┌────────────────────────────────────────┐${NC}"
  echo -e "${MAGENTA}│${NC}            ${GREEN}Setup Complete${NC}             ${MAGENTA}│${NC}"
  echo -e "${MAGENTA}├────────────────────────────────────────┤${NC}"

  if [[ ${#CREATED_FILES[@]} -gt 0 ]]; then
    echo -e "${MAGENTA}│${NC} ${GREEN}✓ Created:${NC} ${CREATED_FILES[*]}"
  fi

  if [[ ${#SKIPPED_FILES[@]} -gt 0 ]] && [[ "$SILENT_MODE" != "true" ]]; then
    echo -e "${MAGENTA}│${NC} ${YELLOW}○ Skipped:${NC} ${SKIPPED_FILES[*]}"
  fi

  if [[ ${#BACKED_UP_FILES[@]} -gt 0 ]]; then
    echo -e "${MAGENTA}│${NC} ${CYAN}↩ Backed:${NC}  ${BACKED_UP_FILES[*]}"
    echo -e "${MAGENTA}│${NC}   ${CYAN}→ ${BACKUP_DIR}${NC}"
  fi

  if [[ $hooks_installed -gt 0 ]]; then
    echo -e "${MAGENTA}│${NC} ${GREEN}✓ Hooks:${NC}   laravel-dev-tools (core.hooksPath)"
  fi

  echo -e "${MAGENTA}├────────────────────────────────────────┤${NC}"
  echo -e "${MAGENTA}│${NC} ${CYAN}Type:${NC} ${project_type}"
  echo -e "${MAGENTA}│${NC} ${CYAN}Publish configs:${NC} bash setup-package.sh --publish"
  echo -e "${MAGENTA}│${NC} ${CYAN}Run:${NC}  make help"
  echo -e "${MAGENTA}└────────────────────────────────────────┘${NC}"
  echo ""
}

# Execute
# ================

setup_package
