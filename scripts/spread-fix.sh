#!/usr/bin/env bash
set -euo pipefail

# Load central configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "$SCRIPT_DIR/config.sh"
maybe_dockerize "$0" "$@"

log_header "Code Layout Fix (ternaries, arrays, calls)"

# The directories of the project that hold PHP code
PATHS=()
for directory in app src tests; do
  [[ -d "${PROJECT_ROOT}/${directory}" ]] && PATHS+=("${directory}")
done

if [[ ${#PATHS[@]} -eq 0 ]]; then
  log_warning "No app, src or tests directory found"
  exit 0
fi

cd "$PROJECT_ROOT"

# Ternaries: phpcbf, with the rule of this package (a phpcs.xml of the project replaces it)
PHPCBF_BIN="${PHPCBF_BIN:-vendor/bin/phpcbf}"

if check_optional_package "squizlabs/php_codesniffer" "$PHPCBF_BIN" "ternary layout (phpcbf)"; then
  PHPCS_CONFIG=$(resolve_config "phpcs.xml" "vendor/zairakai/laravel-dev-tools/config/phpcs.xml")

  log_step "Spreading the ternaries (${PHPCS_CONFIG#"$PROJECT_ROOT"/})"

  # phpcbf exits with 1 when it fixed something, 2 when something it cannot fix remains
  status=0
  "$PHPCBF_BIN" --standard="$PHPCS_CONFIG" "${PATHS[@]}" > /dev/null || status=$?

  if [[ $status -gt 2 ]]; then
    log_error "phpcbf failed (exit ${status})"
    exit 1
  fi
fi

# Arrays and calls: CodeSpreader
log_step "Spreading the arrays and the calls"
changed=$(php "${SCRIPT_DIR}/spread-code.php" "$PROJECT_ROOT" "${PATHS[@]}")
log_success "Code layout fixed (${changed} file(s) rewritten)"
log_info "Run 'make pint-fix' after, for the alignment"
