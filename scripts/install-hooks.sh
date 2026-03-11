#!/usr/bin/env bash
#
# Zairakai Laravel Dev Tools - Git Hooks Installer
# Installs quality check hooks into .git/hooks
#
# Usage:
#   bash scripts/install-hooks.sh           # Install hooks
#   bash scripts/install-hooks.sh --remove  # Remove hooks
#

set -euo pipefail

# Load central configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=config.sh
source "${SCRIPT_DIR}/config.sh"

# ============================================================================
# Configuration
# ============================================================================

HOOKS_SOURCE_DIR="${PROJECT_ROOT}/stubs/githooks"
HOOKS_TARGET_DIR="${PROJECT_ROOT}/.git/hooks"

# Available hooks (auto-discovered from stubs/githooks directory)
# To add a hook: create it in stubs/githooks/
HOOKS=(
    "commit-msg"
    "prepare-commit-msg"
    "pre-commit"
    "pre-push"
)

# ============================================================================
# Functions
# ============================================================================

install_hooks() {
    log_header "📦 Installing Git Hooks"

    if [[ ! -d ".git" ]]; then
        log_error "Not a git repository"
        log_info "Git hooks can only be installed in a git repository"
        exit 1
    fi

    if [[ ! -d "$HOOKS_SOURCE_DIR" ]]; then
        log_error "Hooks source directory not found: $HOOKS_SOURCE_DIR"
        exit 1
    fi

    mkdir -p "$HOOKS_TARGET_DIR"

    local installed=0

    for hook in "${HOOKS[@]}"; do
        local source="${HOOKS_SOURCE_DIR}/${hook}"
        local target="${HOOKS_TARGET_DIR}/${hook}"

        if [[ ! -f "$source" ]]; then
            log_warning "Hook not found: ${hook} (skipping)"
            continue
        fi

        echo -e "  ${BLUE}•${NC} ${hook}"

        # Backup existing hook if present
        if [[ -f "$target" ]] && [[ ! -L "$target" ]]; then
            local backup
            backup="${target}.backup-$(date +%Y%m%d-%H%M%S)"
            mv "$target" "$backup"
            log_warning "  Existing hook backed up to: ${backup}"
        fi

        # Remove existing symlink
        rm -f "$target"

        # Install hook
        ln -s "$source" "$target"
        chmod +x "$source"
        log_success "  ${hook} installed"

        installed=$((installed + 1))
    done

    echo ""

    if [[ $installed -eq 0 ]]; then
        log_warning "No hooks were installed"
        exit 1
    fi

    log_header "✅ ${installed} Hook(s) Installed Successfully"

    echo ""
    log_info "Installed hooks:"
    for hook in "${HOOKS[@]}"; do
        case "$hook" in
            commit-msg)
                echo -e "  ${GREEN}✓${NC} ${hook} ${YELLOW}→${NC} Validate Conventional Commits format"
                ;;
            prepare-commit-msg)
                echo -e "  ${GREEN}✓${NC} ${hook} ${YELLOW}→${NC} Auto-prefix ticket number from branch"
                ;;
            pre-commit)
                echo -e "  ${GREEN}✓${NC} ${hook} ${YELLOW}→${NC} Quality checks (Pint, PHPStan, Markdown)"
                ;;
            pre-push)
                echo -e "  ${GREEN}✓${NC} ${hook} ${YELLOW}→${NC} Full quality gate before push"
                ;;
            *)
                echo -e "  ${GREEN}✓${NC} ${hook}"
                ;;
        esac
    done

    echo ""
    log_info "To bypass hooks (emergency only):"
    echo -e "  ${CYAN}git commit --no-verify${NC}"
    echo ""
}

remove_hooks() {
    log_header "🗑️  Removing Git Hooks"

    if [[ ! -d ".git" ]]; then
        log_error "Not a git repository"
        exit 1
    fi

    local removed=0

    for hook in "${HOOKS[@]}"; do
        local target="${HOOKS_TARGET_DIR}/${hook}"

        if [[ -L "$target" ]]; then
            # Only remove if it's a symlink (managed by us)
            echo -e "  ${BLUE}•${NC} ${hook}"
            rm -f "$target"
            log_success "  ${hook} removed"
            removed=$((removed + 1))
        elif [[ -f "$target" ]]; then
            log_warning "  ${hook} exists but is not a symlink (skipping)"
        fi
    done

    echo ""

    if [[ $removed -eq 0 ]]; then
        log_info "No hooks were removed"
        exit 0
    fi

    log_header "✅ ${removed} Hook(s) Removed"
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
