#!/usr/bin/env bash
set -euo pipefail

# Load central configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "$SCRIPT_DIR/config.sh"
maybe_dockerize "$0" "$@"

log_header "Setup update (files published by the setup)"

# Publishes again the files that `make setup-check` reports (missing or different). A file of the project that is
# replaced is copied in .dev-tools-backup/ first: the project puts its own settings back from the copy. DRY_RUN=true
# only lists the files.
args=("$PROJECT_ROOT" "$(dirname "$SCRIPT_DIR")")
[[ "${DRY_RUN:-false}" == "true" ]] && args+=(--dry-run)

result="$(php "${SCRIPT_DIR}/setup-update.php" "${args[@]}")"

if [[ -z "$result" ]]; then
  log_success "Nothing to update: every file of the setup is the same as the package's"
  exit 0
fi

while IFS= read -r line; do
  log_info "$line"
done <<< "$result"

if [[ "${DRY_RUN:-false}" == "true" ]]; then
  log_info "Dry run: nothing was written. Run: make setup-update"
else
  log_success "Updated. Put your own settings back from the copies in .dev-tools-backup/, then run: make setup-check"
fi
