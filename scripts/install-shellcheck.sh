#!/usr/bin/env bash
#
# Zairakai Laravel Dev Tools - ShellCheck Installer
# Installs ShellCheck for shell script static analysis
#
# Platform: Linux/WSL only
#
# Features:
#   - Reads version from .tool-versions
#   - Supports apt/dnf/pacman/apk
#   - CI-aware
#
# Usage:
#   bash scripts/install-shellcheck.sh
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

# Get ShellCheck version from .tool-versions or use default
get_shellcheck_version() {
    local version=""

    if [[ -f "${TOOL_VERSIONS_FILE}" ]]; then
        version=$(grep "^shellcheck=" "${TOOL_VERSIONS_FILE}" | awk -F "="  '{print $2}' || echo "")

        if [[ -n "${version}" ]]; then
            log_info "Found .tool-versions: ${version}"
        fi
    fi

    # Fallback to default if not found
    if [[ -z "${version}" ]]; then
        version="0.10.0"
        log_info "Using default version: ${version}"
    fi

    echo "${version}"
}

# Compare versions (returns 0 if ver1 >= ver2)
version_ge() {
    local ver1="$1"
    local ver2="$2"

    if [[ "${ver1}" == "${ver2}" ]]; then
        return 0
    fi

    local IFS=.
    local i ver1_arr=("$ver1") ver2_arr=("$ver2")

    # Fill empty positions with zeros
    for ((i=${#ver1_arr[@]}; i<${#ver2_arr[@]}; i++)); do
        ver1_arr[i]=0
    done

    for ((i=0; i<${#ver1_arr[@]}; i++)); do
        if [[ -z ${ver2_arr[i]} ]]; then
            ver2_arr[i]=0
        fi

        if ((10#${ver1_arr[i]} > 10#${ver2_arr[i]})); then
            return 0
        fi

        if ((10#${ver1_arr[i]} < 10#${ver2_arr[i]})); then
            return 1
        fi
    done

    return 0
}

# Check if ShellCheck is already installed with sufficient version
check_shellcheck_installed() {
    local required_version="$1"

    if ! command_exists shellcheck; then
        return 1
    fi

    local current_version
    current_version=$(shellcheck --version | grep "version:" | awk '{print $2}' || echo "")

    if [[ -z "${current_version}" ]]; then
        log_warning "Could not determine ShellCheck version"
        return 1
    fi

    log_info "Current version: ${current_version}"

    if version_ge "${current_version}" "${required_version}"; then
        log_success "ShellCheck ${current_version} is sufficient (need >=${required_version})"
        return 0
    fi

    log_warning "Version ${current_version} is outdated (need >=${required_version})"

    # Ask for upgrade confirmation if not in CI
    if [[ "${CI_MODE}" != "true" ]]; then
        echo ""
        read -rp "Upgrade to ${required_version}? (y/N) " -n 1
        echo ""

        if [[ ! $REPLY =~ ^[Yy]$ ]]; then
            log_info "Keeping existing installation"
            return 0
        fi
    else
        log_info "CI mode: upgrading automatically"
    fi

    return 1
}

# Detect package manager and install ShellCheck
install_shellcheck() {
    local version="$1"

    log_header "🐚 Installing ShellCheck ${version}"
    echo ""

    local use_sudo=true

    # Don't use sudo in CI or if running as root
    if [[ "${CI_MODE}" == "true" ]] || [[ "$(id -u)" -eq 0 ]]; then
        use_sudo=false
    fi

    # Detect and use package manager
    if command_exists apt-get; then
        log_step "Installing via apt-get..."

        if [[ "${use_sudo}" == "true" ]]; then
            sudo apt-get update -qq
            sudo apt-get install -y -qq shellcheck
        else
            apt-get update -qq
            apt-get install -y -qq shellcheck
        fi

    elif command_exists dnf; then
        log_step "Installing via dnf..."

        if [[ "${use_sudo}" == "true" ]]; then
            sudo dnf install -y -q ShellCheck
        else
            dnf install -y -q ShellCheck
        fi

    elif command_exists pacman; then
        log_step "Installing via pacman..."

        if [[ "${use_sudo}" == "true" ]]; then
            sudo pacman -S --noconfirm --quiet shellcheck
        else
            pacman -S --noconfirm --quiet shellcheck
        fi

    elif command_exists apk; then
        log_step "Installing via apk..."
        apk add --no-cache -q shellcheck

    else
        log_error "No supported package manager found (apt-get, dnf, pacman, apk)"
        echo ""
        log_info "Install manually from: https://github.com/koalaman/shellcheck"
        return 1
    fi

    log_success "ShellCheck installed successfully"

    echo ""
    log_header "✅ ShellCheck Installation Complete"
    echo ""

    # Verify installation
    if command_exists shellcheck; then
        shellcheck --version
        echo ""
        log_info "Run linter with:"
        echo -e "  ${CYAN}make shellcheck${NC}"
        echo -e "  ${CYAN}shellcheck scripts/*.sh${NC}"
        echo ""
    else
        log_error "ShellCheck installation verification failed"
        return 1
    fi
}

# ============
# Main
# ============

main() {
    local required_version
    required_version=$(get_shellcheck_version)

    echo ""

    # Check if already installed with sufficient version
    if check_shellcheck_installed "${required_version}"; then
        echo ""
        log_info "No action needed - ShellCheck is already available"
        echo ""
        exit 0
    fi

    echo ""
    log_warning "ShellCheck not found or outdated - installing..."
    echo ""

    install_shellcheck "${required_version}"
}

main "$@"
