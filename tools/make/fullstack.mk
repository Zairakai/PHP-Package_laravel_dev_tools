# Full-Stack Makefile - Laravel (PHP) + NPM (JS)
# Aggregates both toolchains in a single ordered workflow.
#
# Usage (project Makefile):
#   LARAVEL_DIRECTORY_TOOLS_PROJECT_NAME := "My-App"
#   include vendor/zairakai/laravel-dev-tools/tools/make/fullstack.mk
#
# Updates:
#   composer update  → fullstack.mk refreshed automatically (no re-generation)
#   yarn upgrade     → npm toolchain refreshed automatically (no re-generation)

# Default goal (only if not already set by the project Makefile)
ifeq ($(origin .DEFAULT_GOAL), undefined)
.DEFAULT_GOAL := help
endif

SHELL := /bin/bash

# ---- Laravel Variables ----
LARAVEL_DIRECTORY_TOOLS_MAKE_DIR := $(dir $(lastword $(MAKEFILE_LIST)))
include $(LARAVEL_DIRECTORY_TOOLS_MAKE_DIR)variables.mk

# ---- NPM Detection (@zairakai/js-dev-tools) ----
NPM_DIRECTORY_TOOLS_PROJECT_ROOT ?= $(shell pwd)
NPM_DIRECTORY_TOOLS_MAKE_DIR := $(NPM_DIRECTORY_TOOLS_PROJECT_ROOT)/node_modules/@zairakai/js-dev-tools/tools/make/

ifneq ($(wildcard $(NPM_DIRECTORY_TOOLS_MAKE_DIR)variables.mk),)
include $(NPM_DIRECTORY_TOOLS_MAKE_DIR)variables.mk
NPM_DIRECTORY_TOOLS_AVAILABLE := true
else
NPM_DIRECTORY_TOOLS_AVAILABLE := false
endif

# ---- Help (single — laravel help.mk greps full MAKEFILE_LIST automatically) ----
# One help target is enough: it greps MAKEFILE_LIST which contains every
# included .mk file from both toolchains.
include $(LARAVEL_DIRECTORY_TOOLS_MAKE_DIR)help.mk

# ---- Documentation & Shell (shared, only once) ----
include $(LARAVEL_DIRECTORY_TOOLS_MAKE_DIR)markdownlint.mk
include $(LARAVEL_DIRECTORY_TOOLS_MAKE_DIR)shellcheck.mk

# ---- Code Modernization (PHP) ----
include $(LARAVEL_DIRECTORY_TOOLS_MAKE_DIR)rector.mk

# ---- Code Style — PHP then JS, grouped under one "Code Style" section ----
# laravel/code-style.mk prints the "🎨 Code Style" section header (pint).
# npm/code-style.mk has the same header → deduped by help awk → eslint/prettier/
# stylelint appear immediately after pint in the same section. Intentional.
include $(LARAVEL_DIRECTORY_TOOLS_MAKE_DIR)code-style.mk
ifneq ($(NPM_DIRECTORY_TOOLS_AVAILABLE),false)
include $(NPM_DIRECTORY_TOOLS_MAKE_DIR)code-style.mk
include $(NPM_DIRECTORY_TOOLS_MAKE_DIR)stylelint.mk
endif

# ---- Static Analysis (PHP) ----
include $(LARAVEL_DIRECTORY_TOOLS_MAKE_DIR)phpstan.mk

# ---- Code Quality & Security (PHP) ----
include $(LARAVEL_DIRECTORY_TOOLS_MAKE_DIR)insights.mk
include $(LARAVEL_DIRECTORY_TOOLS_MAKE_DIR)enlightn.mk

# ---- Metrics (PHP) ----
include $(LARAVEL_DIRECTORY_TOOLS_MAKE_DIR)phpmetrics.mk

# ---- Dead Code Detection + TypeScript (JS) ----
ifneq ($(NPM_DIRECTORY_TOOLS_AVAILABLE),false)
include $(NPM_DIRECTORY_TOOLS_MAKE_DIR)knip.mk
include $(NPM_DIRECTORY_TOOLS_MAKE_DIR)typescript.mk
endif

# ---- Quality/Test Aggregators ----
include $(LARAVEL_DIRECTORY_TOOLS_MAKE_DIR)quality.mk
include $(LARAVEL_DIRECTORY_TOOLS_MAKE_DIR)test.mk
include $(LARAVEL_DIRECTORY_TOOLS_MAKE_DIR)bats.mk
include $(LARAVEL_DIRECTORY_TOOLS_MAKE_DIR)utils.mk

# ---- JS Utilities (package-install, package-update) ----
ifneq ($(NPM_DIRECTORY_TOOLS_AVAILABLE),false)
include $(NPM_DIRECTORY_TOOLS_MAKE_DIR)utils.mk
endif

# ---- JS extensions: quality/test aggregator append + JS-specific targets ----
# Included LAST so "JS Testing" appears at the end of `make help`.
# fullstack-js.mk only enters MAKEFILE_LIST when npm is present — the grep
# in help.mk only sees its ## headers when it is actually included.
ifneq ($(NPM_DIRECTORY_TOOLS_AVAILABLE),false)
include $(LARAVEL_DIRECTORY_TOOLS_MAKE_DIR)fullstack-js.mk
endif
