#!/usr/bin/env bash
#
# Zairakai Laravel Dev Tools - markdownlint-cli2 Installer
# Installs markdownlint-cli2 for Markdown linting
#
# Platform: Linux/WSL only
#
# Features:
#   - Reads version from .tool-versions
#   - Requires npm/node.js
#   - CI-aware
#
# Usage:
#   bash scripts/install-markdownlint.sh
#

set -euo pipefail

# Load central configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=config.sh
source "${SCRIPT_DIR}/config.sh"

# ============
# Configuration
# ============

CI_MODE="${CI:-false}"
TOOL_VERSIONS_FILE="${SCRIPT_DIR}/../.tool-versions"

# ============
# Functions
# ============

# Check if npm is available
check_npm() {
    if ! command_exists npm; then
        log_error "npm not found"
        echo ""
        log_info "Install Node.js from: https://nodejs.org"
        echo ""
        log_info "Or use nvm:"
        echo -e "  ${CYAN}curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.39.0/install.sh | bash${NC}"
        echo -e "  ${CYAN}nvm install --lts${NC}"
        echo ""
        return 1
    fi

    local node_version
    node_version=$(node --version 2>/dev/null || echo "unknown")
    log_info "Node.js version: ${node_version}"

    return 0
}

# Get markdownlint version from .tool-versions or use default
get_markdownlint_version() {
    local version=""

    if [[ -f "${TOOL_VERSIONS_FILE}" ]]; then
        version=$(grep "^markdownlint-cli2=" "${TOOL_VERSIONS_FILE}" | awk -F "=" '{print $2}' || echo "")
    fi

    # Fallback to latest if not found
    if [[ -z "${version}" ]]; then
        version="latest"
    fi

    echo "${version}"
}

# Check if markdownlint is already installed with correct version
check_markdownlint_installed() {
    local desired_version="$1"

    if ! command_exists markdownlint-cli2; then
        return 1
    fi

    local current_version
    current_version=$(markdownlint-cli2 --version 2>/dev/null || echo "unknown")

    log_info "Current version: ${current_version}"

    # If desired is 'latest', we can't compare
    if [[ "${desired_version}" == "latest" ]]; then
        log_warning "Desired version is 'latest', cannot verify if update needed"

        if [[ "${CI_MODE}" != "true" ]]; then
            echo ""
            read -rp "Reinstall to ensure latest? (y/N) " -n 1
            echo ""

            if [[ ! $REPLY =~ ^[Yy]$ ]]; then
                log_success "Keeping existing installation"
                return 0
            fi
        fi

        return 1
    fi

    # Check if current version matches desired
    if [[ "${current_version}" == "${desired_version}" ]]; then
        log_success "markdownlint-cli2 ${current_version} already installed"
        return 0
    fi

    log_warning "Version mismatch: ${current_version} (installed) vs ${desired_version} (desired)"

    # Ask for upgrade confirmation if not in CI
    if [[ "${CI_MODE}" != "true" ]]; then
        echo ""
        read -rp "Reinstall to version ${desired_version}? (y/N) " -n 1
        echo ""

        if [[ ! $REPLY =~ ^[Yy]$ ]]; then
            log_info "Keeping existing installation"
            return 0
        fi
    else
        log_info "CI mode: reinstalling automatically"
    fi

    return 1
}

# Install markdownlint-cli2 via npm
install_markdownlint() {
    local version="$1"

    log_header "📝 Installing markdownlint-cli2"
    echo ""

    log_step "Installing via npm..."

    local install_cmd="npm install -g"

    if [[ "${version}" == "latest" ]]; then
        install_cmd="${install_cmd} markdownlint-cli2"
    else
        install_cmd="${install_cmd} markdownlint-cli2@${version}"
    fi

    if ${install_cmd}; then
        log_success "markdownlint-cli2 installed successfully"
    else
        log_error "Installation failed"
        return 1
    fi

    echo ""
    log_header "✅ markdownlint-cli2 Installation Complete"
    echo ""

    # Verify installation
    if command_exists markdownlint-cli2; then
        local installed_version
        installed_version=$(markdownlint-cli2 --version 2>/dev/null || echo "unknown")
        log_info "Installed version: ${installed_version}"
        echo ""
        log_info "Run linter with:"
        echo -e "  ${CYAN}make markdownlint${NC}"
        echo -e "  ${CYAN}markdownlint-cli2 '**/*.md'${NC}"
        echo ""
    else
        log_error "markdownlint-cli2 installation verification failed"
        return 1
    fi
}

# ============
# Main
# ============

main() {
    echo ""

    # Check npm availability
    if ! check_npm; then
        exit 1
    fi

    local desired_version
    desired_version=$(get_markdownlint_version)

    # Check if already installed with correct version
    if check_markdownlint_installed "${desired_version}"; then
        echo ""
        log_info "No action needed - markdownlint-cli2 is already available"
        echo ""
        exit 0
    fi

    echo ""
    log_warning "markdownlint-cli2 not found or version mismatch - installing..."
    echo ""

    install_markdownlint "${desired_version}"
}

main "$@"
