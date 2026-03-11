#!/usr/bin/env bash
set -euo pipefail

# Load central configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "$SCRIPT_DIR/config.sh"

# Parse arguments
FIX_MODE=false
EXTRA_IGNORES=()
while [[ $# -gt 0 ]]; do
    case "$1" in
        --fix)        FIX_MODE=true; shift ;;
        --ignore)     EXTRA_IGNORES+=("$2"); shift 2 ;;
        --ignore=*)   EXTRA_IGNORES+=("${1#--ignore=}"); shift ;;
        *)            shift ;;
    esac
done

if [[ "$FIX_MODE" == "true" ]]; then
    log_header "Markdownlint Documentation Auto-Fix"
else
    log_header "Markdownlint Documentation Validation"
fi

# Check if markdownlint-cli2 is installed
if ! command_exists "markdownlint-cli2"; then
    log_error "markdownlint-cli2 not found. Install with: npm install -g markdownlint-cli2"
    exit 1
fi

# Validate markdownlint config is available
if [[ -z "$MARKDOWNLINT_CONFIG" ]]; then
    log_error "No markdownlint configuration found"
    log_warning "Expected one of:"
    log_warning "  .markdownlint.json (project root)"
    log_warning "  config/dev-tools/.markdownlint.json (published config)"
    log_warning "  vendor/zairakai/laravel-dev-tools/.markdownlint.json (default)"
    exit 1
fi

log_step "Configuration: ${MARKDOWNLINT_CONFIG#"$PROJECT_ROOT"/}"

# Parse a gitignore-format file and inject each entry as !pattern
_add_ignore_file() {
    local file="$1"
    [[ ! -f "$file" ]] && return
    while IFS= read -r _line || [[ -n "$_line" ]]; do
        [[ -z "$_line" || "$_line" =~ ^[[:space:]]*# || "$_line" =~ ^! ]] && continue
        MARKDOWNLINT_ARGS+=("!${_line}")
    done < "$file"
}

# Build markdownlint command
MARKDOWNLINT_ARGS=("--config" "$MARKDOWNLINT_CONFIG" "**/*.md" "!.git" "!node_modules" "!vendor")

# Parse .gitignore entries as exclusion patterns
_add_ignore_file "${PROJECT_ROOT}/.gitignore"

# Parse project-specific markdownlint ignore file
if [[ -n "$MARKDOWNLINT_IGNORE" ]]; then
    _add_ignore_file "$MARKDOWNLINT_IGNORE"
fi

# Extra ignores passed via --ignore
if [[ ${#EXTRA_IGNORES[@]} -gt 0 ]]; then
    for _ignore in "${EXTRA_IGNORES[@]}"; do
        MARKDOWNLINT_ARGS+=("!${_ignore}")
    done
fi

if [[ "$FIX_MODE" == "true" ]]; then
    MARKDOWNLINT_ARGS=("--fix" "${MARKDOWNLINT_ARGS[@]}")
    log_step "Fixing Markdown documentation..."
else
    log_step "Validating Markdown documentation..."
fi

# Run markdownlint
if markdownlint-cli2 "${MARKDOWNLINT_ARGS[@]}"; then
    if [[ "$FIX_MODE" == "true" ]]; then
        log_success "Markdown documentation fixed"
    else
        log_success "Markdown validation passed"
    fi
    exit 0
else
    log_error "Markdownlint found documentation issues"
    echo ""
    echo "Fix automatically with: make markdownlint-fix"
    exit 1
fi
