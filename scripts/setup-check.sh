#!/usr/bin/env bash
set -euo pipefail

# Load central configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "$SCRIPT_DIR/config.sh"
maybe_dockerize "$0" "$@"

log_header "Setup check (files published by the setup)"

# Read only: the files that the setup would publish are generated in a temporary folder and compared with the project's.
# A file that differs is a change of the project or a file late on the package: the check shows the difference.
problems="$(php "${SCRIPT_DIR}/setup-check.php" "$PROJECT_ROOT" "$(dirname "$SCRIPT_DIR")" || true)"

if [[ -n "$problems" ]]; then
  while IFS= read -r line; do
    log_warning "$line"
  done <<< "$problems"
  log_info "To publish again what is missing or late (the old file is kept in .dev-tools-backup/): make setup-update"
  exit 1
fi

log_success "Every file of the setup is published and the same as the package's"
