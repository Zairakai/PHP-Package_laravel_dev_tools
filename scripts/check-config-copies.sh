#!/usr/bin/env bash
set -euo pipefail

# Load central configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "$SCRIPT_DIR/config.sh"
maybe_dockerize "$0" "$@"

log_header "Configuration of the editor (root and config/dev-tools)"

# The root holds what the editor reads: thin files that point at config/dev-tools/, and copies of the files that cannot
# point (pint.json, phpunit.xml, .markdownlintignore). The scripts read config/dev-tools/: the two must say the same.
if [[ ! -d "${PROJECT_ROOT}/config/dev-tools" ]]; then
  log_info "No config/dev-tools/ in this project, nothing to compare"
  exit 0
fi

# SYNC=true writes again the copies of the root from config/dev-tools/ before the check
if [[ "${SYNC:-false}" == "true" ]]; then
  log_step "Writing again the copies of the root…"
  php "${SCRIPT_DIR}/config-copies.php" sync "$PROJECT_ROOT" > /dev/null
fi

problems="$(php "${SCRIPT_DIR}/config-copies.php" check "$PROJECT_ROOT" || true)"

if [[ -n "$problems" ]]; then
  while IFS= read -r line; do
    log_error "$line"
  done <<< "$problems"
  log_info "Run: SYNC=true bash vendor/zairakai/laravel-dev-tools/scripts/check-config-copies.sh"
  exit 1
fi

log_success "The files of the root agree with config/dev-tools/"
