#!/usr/bin/env bash
#
# Zairakai Laravel Dev Tools - Interactive Package Installer
# This script helps install optional dev dependencies with user choices
#
# Usage:
#   bash install-packages.sh           # Interactive mode (ask for each package)
#   bash install-packages.sh --all     # Install all packages without asking
#   bash install-packages.sh --skip    # Skip interactive prompts, install only required
#

set -euo pipefail

# ============================================================================
# Bootstrap
# ============================================================================

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "${SCRIPT_DIR}/config.sh"

# ============================================================================
# Arguments Parsing
# ============================================================================

INSTALL_ALL=false
SKIP_OPTIONAL=false

for arg in "$@"; do
    case "$arg" in
        --all|-a)
            INSTALL_ALL=true
            ;;
        --skip|-s)
            SKIP_OPTIONAL=true
            ;;
        --help|-h)
            echo "Usage: $0 [--all|--skip]"
            echo ""
            echo "Interactive installer for dev-tools dependencies"
            echo ""
            echo "Options:"
            echo "  --all, -a     Install all packages without asking"
            echo "  --skip, -s    Skip optional packages, install only required"
            echo "  --help, -h    Show this help message"
            exit 0
            ;;
        *)
            log_error "Unknown option: $arg"
            echo "Use --help for usage information"
            exit 1
            ;;
    esac
done

# ============================================================================
# Helpers
# ============================================================================

# Ask yes/no question
# Returns 0 for yes, 1 for no
ask_yes_no() {
    local question="$1"
    local default="${2:-y}"  # Default to yes

    local prompt
    if [[ "$default" == "y" ]]; then
        prompt="[Y/n]"
    else
        prompt="[y/N]"
    fi

    echo -en "${CYAN}?${NC} ${question} ${prompt} "
    read -r answer

    # Use default if empty
    if [[ -z "$answer" ]]; then
        answer="$default"
    fi

    case "${answer,,}" in
        y|yes|oui|o)
            return 0
            ;;
        *)
            return 1
            ;;
    esac
}

# Check if package is already installed
is_installed() {
    local package="$1"
    [[ -d "${PROJECT_ROOT}/vendor/${package}" ]]
}

# Install a single package
install_package() {
    local package="$1"

    if is_installed "$package"; then
        echo -e "  ${GREEN}✓${NC} ${package} (already installed)"
        return 0
    fi

    echo -e "  ${BLUE}→${NC} Installing ${package}..."
    if composer require --dev "$package" --no-interaction 2>/dev/null; then
        echo -e "  ${GREEN}✓${NC} ${package} installed"
        return 0
    else
        echo -e "  ${RED}✗${NC} Failed to install ${package}"
        return 1
    fi
}

# ============================================================================
# Package Definitions
# ============================================================================

# Core packages (included with dev-tools, always installed)
CORE_PACKAGES=(
    "laravel/pint"
    "phpstan/phpstan"
    "larastan/larastan"
    "phpmetrics/phpmetrics"
    "rector/rector"
    "driftingly/rector-laravel"
    "nunomaduro/phpinsights"
)

# Optional packages with descriptions (none - all quality tools are now included)
declare -A OPTIONAL_PACKAGES

# Laravel-only packages (requires full Laravel app, not just packages)
declare -A LARAVEL_PACKAGES
LARAVEL_PACKAGES["enlightn/enlightn"]="Security and performance analysis (Laravel apps only)"

# ============================================================================
# Main
# ============================================================================

install_packages() {
    log_header "Dev Tools Package Status"

    local project_type
    project_type="$(detect_project_type)"
    log_info "Detected project type: ${project_type}"
    echo ""

    # Check if running inside composer context
    if [[ -n "${COMPOSER_BINARY:-}" ]]; then
        log_error "Cannot run interactive installer inside a Composer script"
        log_info "Run this command directly:"
        echo -e "  ${CYAN}bash vendor/zairakai/laravel-dev-tools/scripts/install-packages.sh${NC}"
        exit 1
    fi

    # ============================================================================
    # Core Packages (included with dev-tools)
    # ============================================================================

    log_step "Core packages (included with dev-tools):"
    echo ""

    for package in "${CORE_PACKAGES[@]}"; do
        if is_installed "$package"; then
            echo -e "  ${GREEN}✓${NC} ${package}"
        else
            echo -e "  ${RED}✗${NC} ${package} (missing - reinstall dev-tools)"
        fi
    done

    echo ""

    # ============================================================================
    # Laravel-specific packages (auto-installed for Laravel apps)
    # ============================================================================

    local packages_to_install=()

    if [[ "$project_type" == "laravel" ]]; then
        log_step "Laravel-specific packages (auto-installed for Laravel apps):"
        echo ""

        for package in "${!LARAVEL_PACKAGES[@]}"; do
            local description="${LARAVEL_PACKAGES[$package]}"

            if is_installed "$package"; then
                echo -e "  ${GREEN}✓${NC} ${package}"
                echo -e "    ${CYAN}${description}${NC}"
            else
                if [[ "$INSTALL_ALL" == "true" ]] || [[ "$SKIP_OPTIONAL" != "true" ]]; then
                    if [[ "$INSTALL_ALL" == "true" ]]; then
                        packages_to_install+=("$package")
                        echo -e "  ${BLUE}○${NC} ${package} (will install)"
                        echo -e "    ${CYAN}${description}${NC}"
                    else
                        echo ""
                        echo -e "  ${YELLOW}○${NC} ${MAGENTA}${package}${NC}"
                        echo -e "    ${CYAN}${description}${NC}"

                        if ask_yes_no "  Install ${package}?" "y"; then
                            packages_to_install+=("$package")
                        fi
                    fi
                else
                    echo -e "  ${YELLOW}○${NC} ${package} (not installed)"
                    echo -e "    ${CYAN}${description}${NC}"
                fi
            fi
        done

        echo ""
    fi

    # Install selected packages
    if [[ ${#packages_to_install[@]} -gt 0 ]]; then
        log_step "Installing selected packages..."
        echo ""

        for package in "${packages_to_install[@]}"; do
            install_package "$package"
        done

        echo ""
    fi

    # ============================================================================
    # Summary
    # ============================================================================

    log_header "Installation Complete"

    echo -e "${GREEN}Core packages (included with dev-tools):${NC}"
    echo ""

    for package in "${CORE_PACKAGES[@]}"; do
        if is_installed "$package"; then
            echo -e "  ${GREEN}✓${NC} ${package}"
        fi
    done

    echo ""
    echo -e "${GREEN}Optional packages:${NC}"
    echo ""

    for package in "${!OPTIONAL_PACKAGES[@]}"; do
        if is_installed "$package"; then
            echo -e "  ${GREEN}✓${NC} ${package}"
        fi
    done

    if [[ "$project_type" == "laravel" ]]; then
        for package in "${!LARAVEL_PACKAGES[@]}"; do
            if is_installed "$package"; then
                echo -e "  ${GREEN}✓${NC} ${package}"
            fi
        done
    fi

    echo ""

    # Check for missing optional packages
    local missing_optional=()

    for package in "${!OPTIONAL_PACKAGES[@]}"; do
        if ! is_installed "$package"; then
            missing_optional+=("$package")
        fi
    done

    if [[ "$project_type" == "laravel" ]]; then
        for package in "${!LARAVEL_PACKAGES[@]}"; do
            if ! is_installed "$package"; then
                missing_optional+=("$package")
            fi
        done
    fi

    if [[ ${#missing_optional[@]} -gt 0 ]]; then
        echo -e "${YELLOW}Not installed (optional):${NC}"
        echo ""
        for package in "${missing_optional[@]}"; do
            echo -e "  ${YELLOW}○${NC} ${package}"
        done
        echo ""
        echo -e "${CYAN}You can install them later with:${NC}"
        echo -e "  ${GREEN}bash vendor/zairakai/laravel-dev-tools/scripts/install-packages.sh${NC}"
    fi

    echo ""
}

# ============================================================================
# Execute
# ============================================================================

install_packages
