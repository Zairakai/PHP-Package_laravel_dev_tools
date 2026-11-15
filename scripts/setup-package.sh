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

# Publishable configs registry
# ================
# The configuration of the project is published in config/dev-tools/, where it extends the one of this package, and the
# scripts read it there. The editor reads the root: see publish_root_file.
# Format: [key]="source_relative_to_vendor|target_relative_to_project|group"

declare -A PUBLISHABLE=(
  ["phpstan"]="stubs/phpstan.neon|config/dev-tools/phpstan.neon|quality"
  ["phpstan-baseline"]="stubs/baseline.neon|config/dev-tools/baseline.neon|quality"
  ["rector"]="stubs/rector.php|config/dev-tools/rector.php|quality"
  ["insights"]="stubs/phpinsights.php|config/dev-tools/phpinsights.php|quality"
  ["pint"]="stubs/pint.json|config/dev-tools/pint.json|style"
  ["markdownlint"]="stubs/.markdownlint.json|config/dev-tools/.markdownlint.json|style"
  ["markdownlintignore"]="stubs/.markdownlintignore|config/dev-tools/.markdownlintignore|style"
  ["phpunit"]="config/phpunit.xml|config/dev-tools/phpunit.xml|testing"
)

# The files of the root that an IDE or an extension reads, by key. A thin file points at the one of config/dev-tools/; a
# copy is the same file, because the tool cannot extend another one. Rector and PHP Insights have none: no extension of
# an IDE reads them, the commands do.
declare -A ROOT_THIN=(
  ["phpstan"]="stubs/root/phpstan.neon|phpstan.neon"
  ["markdownlint"]="stubs/root/.markdownlint.json|.markdownlint.json"
)

declare -A ROOT_COPY=(
  ["pint"]="pint.json"
  ["markdownlintignore"]=".markdownlintignore"
  ["phpunit"]="phpunit.xml"
)

declare -A PUBLISH_GROUPS=(
  ["quality"]="phpstan phpstan-baseline rector insights"
  ["style"]="pint markdownlint markdownlintignore"
  ["testing"]="phpunit"
  ["hooks"]="hooks"
  ["gitlab-ci"]="gitlab-ci"
  ["governance"]="governance"
  ["all"]="phpstan phpstan-baseline rector insights pint markdownlint markdownlintignore phpunit hooks"
)

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

# Publish a config to config/dev-tools/ (user-facing, hash-protected)
publish_file() {
  local source="$1"
  local target="$2"
  local name="$3"

  if [[ ! -f "$source" ]]; then
    log_warning "Source not found for ${name}: ${source#"$VENDOR_PATH"/}"
    return 0
  fi

  if [[ -f "$target" ]] && [[ "$FORCE_OVERWRITE" != "true" ]]; then
    local src_hash tgt_hash
    src_hash="$(file_hash "$source")"
    tgt_hash="$(file_hash "$target")"

    if [[ -n "$src_hash" ]] && [[ "$src_hash" == "$tgt_hash" ]]; then
      cp "$source" "$target"
      track_published "${name} → ${target#"$PROJECT_ROOT"/} (refreshed)"
    else
      track_skipped "$name"
      log_info "Skipping modified: ${name} (use --force to overwrite)"
    fi
    return 0
  fi

  if [[ -f "$target" ]] && [[ "$FORCE_OVERWRITE" == "true" ]]; then
    if backup_file "$target" 2>/dev/null; then
      track_backed_up "$name"
    fi
  fi

  mkdir -p "$(dirname "$target")"
  if cp "$source" "$target"; then
    track_published "${name} → ${target#"$PROJECT_ROOT"/}"
    log_success "Published: ${name} → ${target#"$PROJECT_ROOT"/}"
  fi
}

# The file of the root for the editor, once the one of config/dev-tools/ is published. Created when the project has none,
# written again with --force (after a backup), left alone otherwise: scripts/check-config-copies.sh tells when it differs.
publish_root_file() {
  local key="$1"
  local entry source name target

  if [[ -n "${ROOT_THIN[$key]:-}" ]]; then
    entry="${ROOT_THIN[$key]}"
    source="${VENDOR_PATH}/${entry%%|*}"
    name="${entry#*|}"
  elif [[ -n "${ROOT_COPY[$key]:-}" ]]; then
    name="${ROOT_COPY[$key]}"
    source=""
  else
    return 0
  fi

  target="${PROJECT_ROOT}/${name}"

  if [[ -f "$target" ]] && [[ "$FORCE_OVERWRITE" != "true" ]]; then
    return 0
  fi

  if [[ -n "$source" ]] && [[ ! -f "$source" ]]; then
    log_warning "Source not found for ${name}: ${source#"$VENDOR_PATH"/}"
    return 0
  fi

  if [[ -z "$source" ]] && [[ ! -f "${PROJECT_ROOT}/config/dev-tools/${name}" ]]; then
    return 0
  fi

  if [[ -f "$target" ]] && backup_file "$target" 2>/dev/null; then
    track_backed_up "$name"
  fi

  if [[ -n "$source" ]]; then
    cp "$source" "$target"
    track_published "${name} (root, points at config/dev-tools/)"
  else
    php "${SCRIPT_DIR}/config-copies.php" sync "$PROJECT_ROOT" "$name" > /dev/null
    track_published "${name} (root, copy of config/dev-tools/${name})"
  fi

  log_success "Published: ${name} (root)"
}

# The name of the package: the first "name" of composer.json. sed reads the file itself and stops after the first match:
# a pipe to head would fail under pipefail, because head closes it while grep still writes.
package_name() {
  sed -n '/"name"/{s/.*"name":[[:space:]]*"\([^"]*\)".*/\1/p;q;}' "${PROJECT_ROOT}/composer.json" 2>/dev/null || true
}

# Governance Files Publisher
# ================
# Publishes SECURITY.md, CONTRIBUTING.md, CODE_OF_CONDUCT.md to project root.
# Replaces PACKAGE_GITLAB_ISSUES with the actual GitLab issues URL.
# Hash-protected: skips files modified by the user (use --force to overwrite).
# Not included in "all" - requires explicit opt-in (--publish=governance).

publish_governance_file() {
  local source="$1"
  local target="$2"
  local name="$3"
  local issues_url="$4"

  if [[ ! -f "$source" ]]; then
    log_warning "Stub not found: stubs/governance/${name}"
    return 0
  fi

  # Process placeholders into a temp file for hash comparison and copy
  local processed
  processed="$(mktemp)"
  sed "s|PACKAGE_GITLAB_ISSUES|${issues_url}|g" "$source" > "$processed"

  if [[ -f "$target" ]] && [[ "$FORCE_OVERWRITE" != "true" ]]; then
    local src_hash tgt_hash
    src_hash="$(file_hash "$processed")"
    tgt_hash="$(file_hash "$target")"

    if [[ -n "$src_hash" ]] && [[ "$src_hash" == "$tgt_hash" ]]; then
      cp "$processed" "$target"
      track_published "${name} (refreshed)"
    else
      track_skipped "$name"
      log_info "Skipping modified: ${name} (use --force to overwrite)"
    fi
    rm -f "$processed"
    return 0
  fi

  if [[ -f "$target" ]] && [[ "$FORCE_OVERWRITE" == "true" ]]; then
    backup_file "$target" 2>/dev/null || true
    track_backed_up "$name"
  fi

  cp "$processed" "$target"
  rm -f "$processed"
  track_published "$name"
  log_success "Published: ${name}"
}

publish_governance_files() {
  local pkg_name
  pkg_name=$(package_name)

  local pkg_short="${pkg_name##*/}"
  local issues_url="https://gitlab.com/zairakai/php-packages/${pkg_short}/-/issues"

  local -a docs=("SECURITY.md" "CONTRIBUTING.md" "CODE_OF_CONDUCT.md")

  for filename in "${docs[@]}"; do
    publish_governance_file \
      "${VENDOR_PATH}/stubs/governance/${filename}" \
      "${PROJECT_ROOT}/${filename}" \
      "$filename" \
      "$issues_url"
  done
}

# GitLab CI Publisher
# ================
# Copies the stub to .gitlab-ci.yml and replaces placeholders with the
# actual package name read from composer.json.
# Not included in "all" - requires explicit opt-in (--publish=gitlab-ci).

publish_gitlab_ci_file() {
  local source="${VENDOR_PATH}/stubs/gitlab-ci.yml"
  local target="${PROJECT_ROOT}/.gitlab-ci.yml"
  local name="gitlab-ci"

  if [[ ! -f "$source" ]]; then
    log_warning "Stub not found: ${source#"$VENDOR_PATH"/}"
    return 0
  fi

  if [[ -f "$target" ]] && [[ "$FORCE_OVERWRITE" != "true" ]]; then
    track_skipped "$name"
    log_info "Already published: .gitlab-ci.yml (use --force to overwrite)"
    return 0
  fi

  if [[ -f "$target" ]] && [[ "$FORCE_OVERWRITE" == "true" ]]; then
    backup_file "$target" 2>/dev/null || true
    track_backed_up ".gitlab-ci.yml"
  fi

  if [[ "$FORCE_FULLSTACK" == "true" ]] || [[ -d "${PROJECT_ROOT}/node_modules/@zairakai/js-dev-tools" ]]; then
    source="${VENDOR_PATH}/stubs/gitlab-ci.fullstack.yml"
  fi

  cp "$source" "$target"

  # Replace placeholders with actual package name from composer.json
  local pkg_name
  pkg_name=$(package_name)

  if [[ -n "$pkg_name" ]]; then
    local cache_key="${pkg_name##*/}"
    sed -i "s|PACKAGIST_PACKAGE: \"vendor/my-package\"|PACKAGIST_PACKAGE: \"${pkg_name}\"|" "$target"
    sed -i "s|CACHE_KEY: \"my-package\"|CACHE_KEY: \"${cache_key}\"|" "$target"
  fi

  track_published ".gitlab-ci.yml"
  log_success "Published: .gitlab-ci.yml"
  log_info "ref: 0.0.0 will be updated automatically on: composer update zairakai/laravel-dev-tools"
}

# Publish Logic
# ================

resolve_publish_keys() {
  local target="$1"

  # Check if it's a known group
  if [[ -n "${PUBLISH_GROUPS[$target]:-}" ]]; then
    echo "${PUBLISH_GROUPS[$target]}"
    return 0
  fi

  # Check if it's a direct config key
  if [[ -n "${PUBLISHABLE[$target]:-}" ]]; then
    echo "$target"
    return 0
  fi

  # Special case: "hooks" is a group key handled separately
  if [[ "$target" == "hooks" ]]; then
    echo "hooks"
    return 0
  fi

  echo "ERROR: Unknown publish target: '${target}'" >&2
  echo "" >&2
  echo "Valid groups: ${!PUBLISH_GROUPS[*]}" >&2
  echo "Valid configs: ${!PUBLISHABLE[*]}" >&2
  echo "Run --help for details" >&2
  exit 1
}

do_publish() {
  local -a keys
  read -ra keys <<< "$(resolve_publish_keys "$PUBLISH_TARGET")"

  log_header "Publishing Dev Tools Configs"

  for key in "${keys[@]}"; do
    # Git hooks are handled separately
    if [[ "$key" == "hooks" ]]; then
      install_git_hooks
      continue
    fi

    # GitLab CI is handled separately (placeholder replacement + opt-in)
    if [[ "$key" == "gitlab-ci" ]]; then
      publish_gitlab_ci_file
      continue
    fi

    # Governance files are handled separately (placeholder replacement + opt-in)
    if [[ "$key" == "governance" ]]; then
      publish_governance_files
      continue
    fi

    # The pint.json of the root is generated: the rules of php-dev-tools, the Laravel layer, then the project's changes
    if [[ "$key" == "pint" ]]; then
      publish_pint_config
      continue
    fi

    local entry="${PUBLISHABLE[$key]:-}"
    [[ -z "$entry" ]] && continue

    # Parse entry: source|target|group
    local source_rel="${entry%%|*}"
    local rest="${entry#*|}"
    local target_rel="${rest%%|*}"

    # PHPUnit auto-detection: app vs package
    if [[ "$key" == "phpunit" ]]; then
      if [[ -f "${PROJECT_ROOT}/artisan" || (-d "${PROJECT_ROOT}/app" && ! -d "${PROJECT_ROOT}/src") ]]; then
        source_rel="config/phpunit-app.xml"
      fi
    fi

    local source_abs="${VENDOR_PATH}/${source_rel}"
    local converted=""

    # PHPUnit reads the paths of its file from the folder of that file: the one of config/dev-tools/ goes up two folders
    if [[ "$key" == "phpunit" ]] && [[ -f "$source_abs" ]]; then
      converted="$(mktemp)"
      php "${SCRIPT_DIR}/config-copies.php" phpunit-config "$source_abs" "$converted"
      source_abs="$converted"
    fi

    # Baseline is never force-overwritten (accumulates accepted errors)
    local effective_force="$FORCE_OVERWRITE"
    if [[ "$key" == "phpstan-baseline" ]]; then
      effective_force="false"
    fi

    local saved_force="$FORCE_OVERWRITE"
    FORCE_OVERWRITE="$effective_force"
    publish_file \
      "$source_abs" \
      "${PROJECT_ROOT}/${target_rel}" \
      "$key"
    FORCE_OVERWRITE="$saved_force"

    [[ -n "$converted" ]] && rm -f "$converted"

    publish_root_file "$key"
  done
}

# Git Hooks Installer
# ================
# Activates the hooks of laravel-dev-tools (read in place through core.hooksPath, nothing is copied)

install_git_hooks() {
  if [[ ! -d "${PROJECT_ROOT}/.git" ]]; then
    log_warning "Not a git repository - skipping git hooks installation."
    return 0
  fi

  if [[ ! -d "${PROJECT_ROOT}/${HOOKS_PATH}" ]]; then
    log_warning "Hooks of laravel-dev-tools not found (${HOOKS_PATH}) - run: composer install"
    return 0
  fi

  log_step "Activating the git hooks of laravel-dev-tools…"

  if (cd "${PROJECT_ROOT}" && git config core.hooksPath "$HOOKS_PATH"); then
    log_success "Configured: git config core.hooksPath ${HOOKS_PATH}"
  else
    log_warning "Could not set git config core.hooksPath - run manually:"
    echo "  cd ${PROJECT_ROOT} && git config core.hooksPath ${HOOKS_PATH}"
  fi
}

# Pint Config Publisher
# ================
# Pint cannot extend a file. config/dev-tools/pint.json is the complete file that the project edits: generated once from
# the rules of php-dev-tools and the Laravel layer of this package, never written again over the project's changes unless
# --force (after a backup). The pint.json of the root, which the editor reads, is a copy of it.

publish_pint_config() {
  local target="${PROJECT_ROOT}/config/dev-tools/pint.json"

  if [[ -f "$target" ]] && [[ "$FORCE_OVERWRITE" != "true" ]]; then
    track_skipped "pint"
    log_info "Already published: config/dev-tools/pint.json (use --force to generate it again)"
  else
    if [[ ! -f "${PROJECT_ROOT}/vendor/stanislas-poisson/php-dev-tools/config/pint.json" ]]; then
      log_warning "Pint rules of php-dev-tools not found - run: composer install"
      return 0
    fi

    if [[ -f "$target" ]] && backup_file "$target" 2>/dev/null; then
      track_backed_up "pint"
    fi

    if php "${SCRIPT_DIR}/pint-config.php" "$PROJECT_ROOT" "config/dev-tools/pint.json" "${VENDOR_PATH}/config/pint.json" > /dev/null; then
      track_published "pint → config/dev-tools/pint.json (generated)"
      log_success "Generated: config/dev-tools/pint.json"
    else
      log_warning "Could not generate config/dev-tools/pint.json"
      return 0
    fi
  fi

  publish_root_file "pint"
}

# PHPStan Config Publisher
# ================
# Writes config/dev-tools/phpstan.neon, adapted to the project type, and the thin phpstan.neon of the root that includes
# it. Never overwrites an existing file.

publish_phpstan_config() {
  local target="${PROJECT_ROOT}/config/dev-tools/phpstan.neon"

  if [[ -f "$target" ]]; then
    log_info "config/dev-tools/phpstan.neon already exists, skipping"
  else
    local project_type config_include paths
    project_type="$(detect_project_type)"

    if [[ "$project_type" == "laravel" ]]; then
      config_include="app.neon"
      paths="    - ../../app"
      [[ -d "${PROJECT_ROOT}/database" ]] && paths="${paths}"$'\n'"    - ../../database"
      [[ -d "${PROJECT_ROOT}/routes" ]] && paths="${paths}"$'\n'"    - ../../routes"
    else
      config_include="library.neon"
      paths=$'    - ../../src\n    - ../../tests'
    fi

    mkdir -p "${PROJECT_ROOT}/config/dev-tools"
    cat > "$target" << EOF
includes:
  - ../../vendor/zairakai/laravel-dev-tools/config/${config_include}
  - baseline.neon

parameters:
  paths:
${paths}
EOF

    track_created "config/dev-tools/phpstan.neon"
    log_success "Created: config/dev-tools/phpstan.neon (${project_type} → ${config_include})"
  fi

  publish_root_file "phpstan"
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
      install_git_hooks
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

  # PHPStan baseline (created empty if missing - ensures phpstan configs can include it)
  if [[ ! -f "${PROJECT_ROOT}/config/dev-tools/baseline.neon" ]]; then
    mkdir -p "${PROJECT_ROOT}/config/dev-tools"
    cp "${VENDOR_PATH}/stubs/baseline.neon" "${PROJECT_ROOT}/config/dev-tools/baseline.neon"
    track_created "config/dev-tools/baseline.neon"
  fi

  # Generate phpstan.neon adapted to project type (skips if already exists)
  publish_phpstan_config

  # Update .gitignore
  update_gitignore

  # Git Hooks (only if --with-hooks is explicitly requested)
  # ================

  local hooks_installed=0

  if [[ "$WITH_HOOKS" == "true" ]]; then
    install_git_hooks
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
