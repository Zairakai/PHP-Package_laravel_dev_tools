#!/usr/bin/env bash
set -euo pipefail

# Load central configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "$SCRIPT_DIR/config.sh"
maybe_dockerize "$0" "$@"

log_header "Configuration of the editor (root and config/dev-tools)"

# The root holds what the editor reads: thin files that point at config/dev-tools/, and the ignore file of markdownlint
# (root only). phpunit.xml, pint.json and rector.php stay in config/dev-tools/ only. The scripts read config/dev-tools/.
if [[ ! -d "${PROJECT_ROOT}/config/dev-tools" ]]; then
  log_info "No config/dev-tools/ in this project, nothing to compare"
  exit 0
fi

problems="$(php "${SCRIPT_DIR}/config-copies.php" check "$PROJECT_ROOT" || true)"

if [[ -n "$problems" ]]; then
  while IFS= read -r line; do
    log_error "$line"
  done <<< "$problems"
  exit 1
fi

log_success "The files of the root agree with config/dev-tools/"
