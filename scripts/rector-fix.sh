#!/usr/bin/env bash
set -euo pipefail

# Load central configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "$SCRIPT_DIR/config.sh"

log_header "Rector Code Modernization (Auto-Fix)"

# Check if rector is installed (optional package - exit gracefully if not)
if ! check_optional_package "rector/rector" "$RECTOR_BIN" "Rector code modernization"; then
    exit 0
fi

# Build Rector command with config if available
RECTOR_ARGS=("process" "--no-progress-bar")

if [[ -f "$RECTOR_CONFIG" ]]; then
    log_step "Using configuration: ${RECTOR_CONFIG#"$PROJECT_ROOT"/}"
    RECTOR_ARGS+=("--config=$RECTOR_CONFIG")
else
    log_warning "No rector.php found, using default configuration"
fi

# Run Rector
log_step "Applying automatic code modernization..."

if "$RECTOR_BIN" "${RECTOR_ARGS[@]}"; then
    log_success "Code modernized successfully"
    exit 0
else
    log_error "Rector encountered errors"
    exit 1
fi
