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
      echo "                       Installs: Makefile, .editorconfig, baseline.neon, phpstan.neon (and its root file),"
      echo "                       the Composer scripts of the package, the block of .gitignore"
      echo "                       Uses the full-stack Makefile when @zairakai/js-dev-tools is installed"
      echo ""
      echo "  --publish            Publish ALL configs to config/dev-tools/ (and the files that the editor reads, at the root)"
      echo "  --publish=<group>    Publish a config group"
      echo "  --publish=<key>      Publish a specific config"
      echo "  --with-hooks         Also activate the git hooks of laravel-dev-tools"
      echo "  --fullstack          Force full-stack Makefile + CI stub (PHP + JS)"
      echo "  --force, -f          Overwrite existing files (copies them in .dev-tools-backup/ first)"
      echo "  --silent, -s         Suppress output (errors only)"
      echo "  --help, -h           Show this help"
      echo ""
      echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
      echo "  Groups (--publish=<group>)"
      echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
      echo "  setup      makefile, editorconfig, baseline, phpstan, gitignore, composer-scripts (the normal setup)"
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
      echo "  makefile          → Makefile (the one for PHP and JS with js-dev-tools or --fullstack)"
      echo "  editorconfig      → .editorconfig"
      echo "  composer-scripts  → the Composer scripts of the package, in composer.json"
      echo "  gitignore         → the block of the dev-tools, in .gitignore"
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

# Publication
# ================
# The work is done by scripts/publish.php (ConfigStubPublisher): the same code as `php artisan dev-tools:publish`, so the
# two entries give the same files. Groups and keys are listed there (--help of this script names them).

run_publish() {
  local target="$1"
  local -a args=("${PROJECT_ROOT}" "${VENDOR_PATH}" "$target")

  [[ "$FORCE_OVERWRITE" == "true" ]] && args+=(--force)
  [[ "$FORCE_FULLSTACK" == "true" ]] && args+=(--fullstack)

  if [[ "$SILENT_MODE" == "true" ]]; then
    php "${SCRIPT_DIR}/publish.php" "${args[@]}" > /dev/null
  else
    php "${SCRIPT_DIR}/publish.php" "${args[@]}"
  fi
}

do_publish() {
  log_header "Publishing Dev Tools Configs"

  run_publish "$PUBLISH_TARGET" || exit 1
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

    run_publish gitignore

    echo ""
    echo -e "${MAGENTA}┌────────────────────────────────────────┐${NC}"
    echo -e "${MAGENTA}│${NC}         ${GREEN}Publish Complete${NC}              ${MAGENTA}│${NC}"
    echo -e "${MAGENTA}├────────────────────────────────────────┤${NC}"

    echo -e "${MAGENTA}├────────────────────────────────────────┤${NC}"
    echo -e "${MAGENTA}│${NC} ${CYAN}Edit configs in:${NC} config/dev-tools/"
    echo -e "${MAGENTA}│${NC} ${CYAN}Help:${NC} bash setup-package.sh --help"
    echo -e "${MAGENTA}└────────────────────────────────────────┘${NC}"
    echo ""
    exit 0
  fi

  # Normal Setup
  # ================

  # What every project has: the Makefile (full-stack if @zairakai/js-dev-tools is installed or --fullstack is given),
  # .editorconfig, the baseline and phpstan.neon adapted to the type of the project, the block of .gitignore and the
  # Composer scripts. The work is done by the group "setup" of ConfigStubPublisher.
  run_publish setup

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

  # Summary
  # ================

  local project_type
  project_type="$(detect_project_type)"

  if [[ "$SILENT_MODE" == "true" ]] && [[ $hooks_installed -eq 0 ]]; then
    exit 0
  fi

  echo ""
  echo -e "${MAGENTA}┌────────────────────────────────────────┐${NC}"
  echo -e "${MAGENTA}│${NC}            ${GREEN}Setup Complete${NC}             ${MAGENTA}│${NC}"
  echo -e "${MAGENTA}├────────────────────────────────────────┤${NC}"

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
