#!/usr/bin/env bash
#
# Zairakai Laravel Dev Tools - BATS Installer
# Installs BATS (Bash Automated Testing System) for shell script testing
#
# Platform: Linux/WSL only
#
# Features:
#   - Reads version from .tool-versions
#   - Installs BATS core + support libraries
#   - CI-aware
#
# Usage:
#   bash scripts/install-bats.sh
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

# Installation paths
INSTALL_DIR="${HOME}/.local/share/bats"
BIN_DIR="${HOME}/.local/bin"

# ============
# Functions
# ============

# Get BATS version from .tool-versions or use default
get_bats_version() {
    local version=""

    if [[ -f "${TOOL_VERSIONS_FILE}" ]]; then
        version=$(grep "^bats=" "${TOOL_VERSIONS_FILE}" | awk -F "="  '{print $2}' || echo "")

        if [[ -n "${version}" ]]; then
            log_info "Found .tool-versions: ${version}"
        fi
    fi

    # Fallback to default if not found
    if [[ -z "${version}" ]]; then
        version="1.11.0"
        log_info "Using default version: ${version}"
    fi

    # Ensure version has 'v' prefix
    if [[ ! "${version}" =~ ^v ]]; then
        version="v${version}"
    fi

    echo "${version}"
}

# Compare versions (returns 0 if ver1 >= ver2)
version_ge() {
    local ver1="${1#v}"
    local ver2="${2#v}"

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

# Check if BATS is already installed with sufficient version
check_bats_installed() {
    local required_version="$1"

    if ! command_exists bats; then
        return 1
    fi

    local current_version
    current_version=$(bats --version 2>/dev/null | awk '{print $2}' || echo "")

    if [[ -z "${current_version}" ]]; then
        log_warning "Could not determine BATS version"
        return 1
    fi

    log_info "Current version: ${current_version}"

    if version_ge "${current_version}" "${required_version}"; then
        log_success "BATS ${current_version} is sufficient (need >=${required_version})"
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

# Install BATS from source
install_bats_core() {
    local version="$1"

    log_step "Installing BATS ${version} from source..."

    # Create directories
    mkdir -p "${INSTALL_DIR}"
    mkdir -p "${BIN_DIR}"

    # Clone or update BATS repository
    if [[ -d "${INSTALL_DIR}/bats-core" ]]; then
        log_info "BATS repository exists, updating..."
        cd "${INSTALL_DIR}/bats-core"
        git fetch --tags --quiet
        git checkout "${version}" --quiet
    else
        log_info "Cloning BATS repository..."
        git clone --quiet --depth 1 --branch "${version}" \
            https://github.com/bats-core/bats-core.git "${INSTALL_DIR}/bats-core"
        cd "${INSTALL_DIR}/bats-core"
    fi

    # Install BATS
    log_step "Running installer..."
    ./install.sh "${HOME}/.local"

    log_success "BATS core installed to: ${BIN_DIR}/bats"

    # Check if bin directory is in PATH
    if [[ ":$PATH:" != *":${BIN_DIR}:"* ]]; then
        echo ""
        log_warning "⚠️  ${BIN_DIR} is not in your PATH"
        echo ""
        echo "Add this to your ~/.bashrc or ~/.zshrc:"
        echo ""
        echo -e "  ${CYAN}export PATH=\"\${HOME}/.local/bin:\${PATH}\"${NC}"
        echo ""
    fi
}

# Install BATS support libraries
install_bats_libraries() {
    log_step "Installing BATS support libraries..."

    local libraries=(
        "bats-support:https://github.com/bats-core/bats-support.git"
        "bats-assert:https://github.com/bats-core/bats-assert.git"
        "bats-file:https://github.com/bats-core/bats-file.git"
    )

    for lib_info in "${libraries[@]}"; do
        local lib_name="${lib_info%%:*}"
        local lib_url="${lib_info#*:}"
        local lib_path="${INSTALL_DIR}/${lib_name}"

        if [[ -d "${lib_path}" ]]; then
            log_info "${lib_name} already installed"
        else
            log_info "Installing ${lib_name}..."
            git clone --quiet --depth 1 "${lib_url}" "${lib_path}"
            log_success "${lib_name} installed"
        fi
    done
}

# Main installation flow
install_bats() {
    local version="$1"

    log_header "📦 Installing BATS ${version}"
    echo ""

    install_bats_core "${version}"
    install_bats_libraries

    echo ""
    log_header "✅ BATS Installation Complete"
    echo ""

    # Verify installation
    if command_exists bats; then
        bats --version
        echo ""
        log_info "Libraries installed in: ${INSTALL_DIR}"
        echo ""
        log_info "Run tests with:"
        echo -e "  ${CYAN}make bats${NC}"
        echo -e "  ${CYAN}bats tests/bats/${NC}"
        echo ""
    else
        log_error "BATS installation verification failed"
        return 1
    fi
}

# ============
# Main
# ============

main() {
    local required_version
    required_version=$(get_bats_version)

    echo ""

    # Check if already installed with sufficient version
    if check_bats_installed "${required_version}"; then
        echo ""
        log_info "No action needed - BATS is already available"
        echo ""
        exit 0
    fi

    echo ""
    log_warning "BATS not found or outdated - installing..."
    echo ""

    install_bats "${required_version}"
}

main "$@"
