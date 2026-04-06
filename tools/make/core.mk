# Core Makefile - Aggregates all modular targets
# This file orchestrates the inclusion of specialized makefiles

# Fusion tip (Laravel + NPM):
#   Set LARAVEL_DIRECTORY_TOOLS_HELP_TARGET=help-php to avoid help target collisions.

# Default goal (only if not already set by the project Makefile)
ifeq ($(origin .DEFAULT_GOAL), undefined)
.DEFAULT_GOAL := help
endif

# Ensure SHELL is bash for script compatibility
SHELL := /bin/bash

# Resolve make files directory from the current MAKEFILE_LIST entry
LARAVEL_DIRECTORY_TOOLS_MAKE_DIR := $(dir $(lastword $(MAKEFILE_LIST)))

# Include variables first (paths, colors, config)
include $(LARAVEL_DIRECTORY_TOOLS_MAKE_DIR)variables.mk

# Include specialized makefiles in logical development workflow order:
# 1. Help system (always first - discover available commands)
include $(LARAVEL_DIRECTORY_TOOLS_MAKE_DIR)help.mk

# 2. Documentation linting (validate docs before code)
include $(LARAVEL_DIRECTORY_TOOLS_MAKE_DIR)markdownlint.mk

# 3. Shell script validation (shellcheck - 100% compliance)
include $(LARAVEL_DIRECTORY_TOOLS_MAKE_DIR)shellcheck.mk

# 4. Code modernization (rector - automated refactoring before formatting)
include $(LARAVEL_DIRECTORY_TOOLS_MAKE_DIR)rector.mk

# 5. Code style (pint - formatting after rector)
include $(LARAVEL_DIRECTORY_TOOLS_MAKE_DIR)code-style.mk

# 6. Static analysis (phpstan - type safety)
include $(LARAVEL_DIRECTORY_TOOLS_MAKE_DIR)phpstan.mk

# 7. Code quality insights (insights - architecture and quality)
include $(LARAVEL_DIRECTORY_TOOLS_MAKE_DIR)insights.mk

# 8. Security and performance (enlightn - production readiness)
include $(LARAVEL_DIRECTORY_TOOLS_MAKE_DIR)enlightn.mk

# 9. Quality aggregation (quality - combines validation checks)
include $(LARAVEL_DIRECTORY_TOOLS_MAKE_DIR)quality.mk

# 10. Testing (test - unit, feature, coverage)
include $(LARAVEL_DIRECTORY_TOOLS_MAKE_DIR)test.mk

# 11. Shell script testing (bats - bash automated testing)
include $(LARAVEL_DIRECTORY_TOOLS_MAKE_DIR)bats.mk

# 12. Code metrics (phpmetrics - reporting and visualization)
include $(LARAVEL_DIRECTORY_TOOLS_MAKE_DIR)phpmetrics.mk

# 13. Utilities (composer, setup, security)
include $(LARAVEL_DIRECTORY_TOOLS_MAKE_DIR)utils.mk

# 14. Shared utilities (git, hooks, doctor)
include $(LARAVEL_DIRECTORY_TOOLS_MAKE_DIR)utils-shared.mk
