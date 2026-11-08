#!/usr/bin/env bash
#
# Zairakai Laravel Dev Tools - Git Hooks Installer
# Activates the hooks of stanislas-poisson/php-dev-tools: git reads them in place (core.hooksPath), nothing is copied.
#
# Usage:
#   bash scripts/install-hooks.sh           # Activate the hooks
#   bash scripts/install-hooks.sh --remove  # Deactivate them
#

set -euo pipefail

# Load central configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=config.sh
source "${SCRIPT_DIR}/config.sh"

# ============================================================================
# Configuration
# ============================================================================

# The hooks of php-dev-tools: the same for every PHP project of zairakai. They run the Composer scripts of the project
# (quality:fast before a commit, quality before a push).
HOOKS_PATH="vendor/stanislas-poisson/php-dev-tools/hooks"

# ============================================================================
# Functions
# ============================================================================

install_hooks() {
    log_header "📦 Installing Git Hooks"

    if [[ ! -d "${PROJECT_ROOT}/.git" ]] && [[ ! -f "${PROJECT_ROOT}/.git" ]]; then
        log_error "Not a git repository"
        log_info "Git hooks can only be installed in a git repository"
        exit 1
    fi

    if [[ ! -d "${PROJECT_ROOT}/${HOOKS_PATH}" ]]; then
        log_error "Hooks not found: ${HOOKS_PATH}"
        log_info "Install the dependencies first: composer install"
        exit 1
    fi

    git -C "$PROJECT_ROOT" config core.hooksPath "$HOOKS_PATH"
    log_success "Configured: git config core.hooksPath ${HOOKS_PATH}"

    echo ""
    log_info "Active hooks:"
    echo -e "  ${GREEN}✓${NC} commit-msg ${YELLOW}→${NC} Validate the commit message format"
    echo -e "  ${GREEN}✓${NC} prepare-commit-msg ${YELLOW}→${NC} Auto-prefix the ticket number from the branch"
    echo -e "  ${GREEN}✓${NC} pre-commit ${YELLOW}→${NC} composer quality:fast"
    echo -e "  ${GREEN}✓${NC} pre-push ${YELLOW}→${NC} composer quality"

    echo ""
    log_info "To bypass hooks (emergency only):"
    echo -e "  ${CYAN}git commit --no-verify${NC}"
    echo ""
}

remove_hooks() {
    log_header "🗑️  Removing Git Hooks"

    if [[ ! -d "${PROJECT_ROOT}/.git" ]] && [[ ! -f "${PROJECT_ROOT}/.git" ]]; then
        log_error "Not a git repository"
        exit 1
    fi

    local current
    current="$(git -C "$PROJECT_ROOT" config --get core.hooksPath || true)"

    if [[ "$current" != "$HOOKS_PATH" ]]; then
        log_info "The hooks of php-dev-tools are not active (core.hooksPath: ${current:-not set})"
        exit 0
    fi

    git -C "$PROJECT_ROOT" config --unset core.hooksPath
    log_success "Removed: git config core.hooksPath"
    echo ""
}

# ============================================================================
# Main
# ============================================================================

case "${1:-install}" in
    --remove|-r)
        remove_hooks
        ;;
    --install|-i|install|*)
        install_hooks
        ;;
esac
