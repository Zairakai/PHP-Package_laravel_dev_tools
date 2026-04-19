#!/usr/bin/env bash
# Zairakai Laravel Dev Tools - Central Configuration

set -euo pipefail

# PROJECT PATHS
# ================
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if [[ "$SCRIPT_DIR" =~ /vendor/zairakai/laravel-dev-tools/scripts ]]; then
    # Running as vendor dependency
    PROJECT_ROOT="$(cd "$SCRIPT_DIR/../../../.." && pwd)"
else
    # Running in package development
    PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
fi

# PROJECT TYPE DETECTION
# ================
# Returns "laravel" or "package"
detect_project_type() {
    if [[ -f "${PROJECT_ROOT}/artisan" ]] && \
       grep -q '"laravel/framework"' "${PROJECT_ROOT}/composer.json" 2>/dev/null; then
        echo "laravel"
        return 0
    fi

    if [[ -d "${PROJECT_ROOT}/src" ]] || \
       grep -q '"type":\s*"library"' "${PROJECT_ROOT}/composer.json" 2>/dev/null; then
        echo "package"
        return 0
    fi

    # Default fallback
    echo "package"
}

is_laravel_app() { [[ "laravel" == "$(detect_project_type)" ]]; }
is_package()     { [[ "package" == "$(detect_project_type)" ]]; }

export -f detect_project_type is_laravel_app is_package

# COLORS
# ================
export RED='\033[0;31m'
export GREEN='\033[0;32m'
export YELLOW='\033[1;33m'
export BLUE='\033[0;34m'
export CYAN='\033[0;36m'
export MAGENTA='\033[0;35m'
export NC='\033[0m'

# TOOL BINARIES
# ================
# Auto-detect Pest when installed — Pest refuses to run via phpunit directly
if [[ -x "${PROJECT_ROOT}/vendor/bin/pest" ]]; then
    export PHPUNIT_BIN="${PHPUNIT_BIN:-vendor/bin/pest}"
else
    export PHPUNIT_BIN="${PHPUNIT_BIN:-vendor/bin/phpunit}"
fi
export PHPSTAN_BIN="${PHPSTAN_BIN:-vendor/bin/phpstan}"
export PINT_BIN="${PINT_BIN:-vendor/bin/pint}"
export RECTOR_BIN="${RECTOR_BIN:-vendor/bin/rector}"
export PHPMETRICS_BIN="${PHPMETRICS_BIN:-vendor/bin/phpmetrics}"

# CONFIGURATION RESOLUTION
# ================
# Cascade: 1. PROJECT_ROOT/$filename          (user root override)
#          2. config/dev-tools/$filename       (published — can extend bundled default)
#          3. config/$filename                 (legacy or intermediate)
#          4. vendor fallback path             (bundled default)
resolve_config() {
    local filename="$1"
    local vendor_fallback="${2:-}"

    [[ -f "${PROJECT_ROOT}/${filename}" ]]                  && { echo "${PROJECT_ROOT}/${filename}"; return 0; }
    [[ -f "${PROJECT_ROOT}/config/dev-tools/${filename}" ]] && { echo "${PROJECT_ROOT}/config/dev-tools/${filename}"; return 0; }
    [[ -f "${PROJECT_ROOT}/config/${filename}" ]]           && { echo "${PROJECT_ROOT}/config/${filename}"; return 0; }
    [[ -n "$vendor_fallback" && -f "${PROJECT_ROOT}/${vendor_fallback}" ]] && { echo "${PROJECT_ROOT}/${vendor_fallback}"; return 0; }

    echo ""
}
export -f resolve_config

# CONFIGURATION FILES
# ================

# PHPUnit
PHPUNIT_CONFIG=$(resolve_config "phpunit.xml" "vendor/zairakai/laravel-dev-tools/config/phpunit.xml")
export PHPUNIT_CONFIG="${PHPUNIT_CONFIG:-}"

# PHPStan (phpstan-local.neon takes priority over phpstan.neon at root level)
_phpstan_vendor_default="vendor/zairakai/laravel-dev-tools/config/library.neon"
# Use if/then to avoid set -e treating false return from is_laravel_app as an error
if is_laravel_app; then
    _phpstan_vendor_default="vendor/zairakai/laravel-dev-tools/config/app.neon"
fi
PHPSTAN_CONFIG=$(resolve_config "phpstan-local.neon")
[[ -z "$PHPSTAN_CONFIG" ]] && PHPSTAN_CONFIG=$(resolve_config "phpstan.neon" "$_phpstan_vendor_default")
export PHPSTAN_CONFIG="${PHPSTAN_CONFIG:-}"
unset _phpstan_vendor_default

PHPSTAN_BASELINE=$(resolve_config "baseline.neon")
export PHPSTAN_BASELINE="${PHPSTAN_BASELINE:-config/dev-tools/baseline.neon}"

# Pint
PINT_CONFIG=$(resolve_config "pint.json" "vendor/zairakai/laravel-dev-tools/config/pint.json")
export PINT_CONFIG="${PINT_CONFIG:-}"

# Rector
RECTOR_CONFIG=$(resolve_config "rector.php" "vendor/zairakai/laravel-dev-tools/config/rector.php")
export RECTOR_CONFIG="${RECTOR_CONFIG:-}"

# Markdownlint
MARKDOWNLINT_CONFIG=$(resolve_config ".markdownlint.json" "vendor/zairakai/laravel-dev-tools/.markdownlint.json")
export MARKDOWNLINT_CONFIG="${MARKDOWNLINT_CONFIG:-}"

MARKDOWNLINT_IGNORE=$(resolve_config ".markdownlintignore" "vendor/zairakai/laravel-dev-tools/.markdownlintignore")
export MARKDOWNLINT_IGNORE="${MARKDOWNLINT_IGNORE:-}"

# PHPInsights
INSIGHTS_CONFIG=$(resolve_config "insights.php" "vendor/zairakai/laravel-dev-tools/config/insights.php")
export INSIGHTS_CONFIG="${INSIGHTS_CONFIG:-}"

# PHPUNIT & PHPSTAN SETTINGS
# ================
export PHPUNIT_DEFAULT_ARGS="--colors=always"
[[ "${CI:-false}" == "true" ]] && export PHPUNIT_DEFAULT_ARGS="--colors=never"

export COVERAGE_HTML_DIR="${COVERAGE_HTML_DIR:-build/coverage}"
export COVERAGE_CLOVER_FILE="${COVERAGE_CLOVER_FILE:-build/logs/clover.xml}"
export COVERAGE_COBERTURA_FILE="${COVERAGE_COBERTURA_FILE:-build/logs/cobertura.xml}"

export PHPSTAN_MEMORY="${PHPSTAN_MEMORY:-2G}"
export PHPSTAN_TMP_DIR="${PHPSTAN_TMP_DIR:-build/phpstan}"

# BUILD DIRECTORIES
# ================
export BUILD_DIR="${BUILD_DIR:-build}"
export CACHE_DIR="${CACHE_DIR:-.phpunit.cache}"

# ENVIRONMENT DETECTION
# ================
export IS_CI="${CI:-false}"
export IS_GITLAB_CI="${GITLAB_CI:-false}"
export IS_GITHUB_ACTIONS="${GITHUB_ACTIONS:-false}"

# GIT DETECTION
# ================
export HAS_GIT=false
if [[ -d "${PROJECT_ROOT}/.git" ]]; then export HAS_GIT=true; fi

# HELPER FUNCTIONS
# ================
log_info()    { echo -e "${CYAN}ℹ ${NC}$*"; }
log_success() { echo -e "${GREEN}✅ ${NC}$*"; }
log_warning() { echo -e "${YELLOW}⚠️  ${NC}$*"; }
log_error()   { echo -e "${RED}❌ ${NC}$*" >&2; }
log_step()    { echo -e "${BLUE}→${NC} $*"; }
log_header()  { echo -e "\n${MAGENTA}  $*${NC}\n${MAGENTA}════════════════${NC}\n"; }

command_exists()       { command -v "$1" >/dev/null 2>&1; }
has_coverage_driver()  { php -m 2>/dev/null | grep -qE "(xdebug|pcov)"; }

get_dirty_php_files() { [[ "$HAS_GIT" == "true" ]] && git diff --name-only --diff-filter=ACMR HEAD 2>/dev/null | grep '\.php$' || true; }
ensure_dir()          { [[ ! -d "$1" ]] && mkdir -p "$1" && log_step "Created directory: $1" || true; }

require_package() {
    local name="$1"; local path="$2"; local script_name="${3:-this script}"
    [[ -f "$path" ]] || { log_error "Required package '$name' not installed"; echo -e "${YELLOW}Required by $script_name${NC}\n${CYAN}Install with:\n  ${GREEN}composer require --dev $name${NC}\n"; return 1; }
}

check_optional_package() {
    local name="$1"; local path="$2"; local feature="${3:-this feature}"
    [[ -f "$path" ]] || { log_warning "Skipping $feature (package not installed)"; echo -e "${YELLOW}$feature requires $name${NC}\n${CYAN}Install with:\n  ${GREEN}composer require --dev $name${NC}\n"; return 1; }
}

# ERROR COUNTER PATTERN
# ================
ERROR_COUNT=0
init_error_counter()      { ERROR_COUNT=0; }
increment_error_counter() { ERROR_COUNT=$((ERROR_COUNT + 1)); }
get_error_count()         { echo "$ERROR_COUNT"; }

exit_with_error_count() {
    local name="${1:-Checks}"
    if [[ $ERROR_COUNT -eq 0 ]]; then log_header "✅ All ${name} Passed"; return 0; else log_header "❌ ${ERROR_COUNT} ${name} Failed"; return 1; fi
}

run_check() {
    local name="$1"; local cmd="$2"
    log_info "Running: ${name}"
    eval "$cmd" && log_success "${name} passed" || { log_error "${name} failed"; increment_error_counter; return 1; }
}

# BACKUP & FILE HELPERS
# ================
BACKUP_DIR=""
backup_file() {
    local file="$1"; local base="${2:-${PROJECT_ROOT}/.dev-tools-backup}"
    [[ ! -f "$file" || -L "$file" ]] && return 1
    [[ -z "$BACKUP_DIR" ]] && BACKUP_DIR="${base}/$(date +%Y%m%d-%H%M%S)" && mkdir -p "$BACKUP_DIR"
    local rel="${file#"${PROJECT_ROOT}"/}"; local dest="${BACKUP_DIR}/${rel}"
    mkdir -p "$(dirname "$dest")"
    cp "$file" "$dest" 2>/dev/null
}

file_hash() {
    local file="$1"
    if command_exists sha256sum; then
        sha256sum "$file" | cut -d' ' -f1
    elif command_exists shasum; then
        shasum -a 256 "$file" | cut -d' ' -f1
    else
        echo ""
    fi
}

cleanup_temp_files() { true; }
trap cleanup_temp_files EXIT INT TERM

# VALIDATION
# ================
cd "$PROJECT_ROOT"

# Check if vendor directory exists
if [[ ! -d "vendor" ]]; then
    log_error "Dependencies not installed. Run: composer install"
    exit 1
fi
