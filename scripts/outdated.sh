#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "$SCRIPT_DIR/config.sh"

log_header "Composer Outdated Check"

ensure_dir "build/reports"

# ─── IGNORE LIST ──────────────────────────────────────────────────────────────
# Packages to exclude from the outdated check.
# Populated from two sources (merged):
#   1. OUTDATED_IGNORE env var — comma-separated list
#      e.g. OUTDATED_IGNORE=orchestra/testbench,some-other-pkg
#   2. composer.json "outdatedIgnore" array field (optional, project-level)
#      e.g. "outdatedIgnore": ["orchestra/testbench"]
# ──────────────────────────────────────────────────────────────────────────────
build_jq_filter() {
    local ignore_csv="${OUTDATED_IGNORE:-}"
    local composer_json="${PROJECT_ROOT}/composer.json"
    local from_composer=""

    if command_exists jq && [[ -f "$composer_json" ]]; then
        from_composer="$(jq -r '(.outdatedIgnore // []) | join(",")' "$composer_json" 2>/dev/null || true)"
    fi

    # Merge both sources
    local combined="${ignore_csv}${ignore_csv:+,}${from_composer}"

    if [[ -z "$combined" ]]; then
        echo "."
        return
    fi

    # Build: .installed |= map(select(.name != "pkg1" and .name != "pkg2"))
    local conditions=""
    IFS=',' read -ra packages <<< "$combined"
    for pkg in "${packages[@]}"; do
        pkg="${pkg// /}"  # trim spaces
        [[ -z "$pkg" ]] && continue
        conditions+="${conditions:+ and }.name != \"${pkg}\""
    done

    if [[ -z "$conditions" ]]; then
        echo "."
        return
    fi

    echo ".installed |= map(select(${conditions}))"
}

log_step "Checking direct dependencies for outdated packages..."

composer outdated --direct --format=json > build/reports/outdated.json 2>/dev/null || true

JQ_FILTER="$(build_jq_filter)"

if command_exists jq && [[ "$JQ_FILTER" != "." ]]; then
    log_step "Ignoring: ${OUTDATED_IGNORE:-}$(jq -r '(.outdatedIgnore // []) | join(", ")' "${PROJECT_ROOT}/composer.json" 2>/dev/null || true)"
    filtered="$(jq "${JQ_FILTER}" build/reports/outdated.json 2>/dev/null || cat build/reports/outdated.json)"
    echo "$filtered" > build/reports/outdated.json
fi

count="$(jq '.installed | length' build/reports/outdated.json 2>/dev/null || echo "0")"

if [[ "$count" -eq 0 ]]; then
    log_success "All dependencies are up to date"
    exit 0
fi

log_error "Outdated packages found:"
cat build/reports/outdated.json
echo ""
echo "Run: composer update (or update composer.json manually)"
exit 1
