# Zairakai Laravel Dev Tools - Project Makefile
# This file includes shared targets from vendor/zairakai/laravel-dev-tools

LARAVEL_DIRECTORY_TOOLS_PROJECT_ROOT := $(shell pwd)
LARAVEL_DIRECTORY_TOOLS_PROJECT_NAME := "Laravel Project"

.DEFAULT_GOAL := help

# Include shared tooling from Zairakai Laravel Dev Tools
include vendor/zairakai/laravel-dev-tools/tools/make/core.mk

# Override Docker container name if needed (default: app)
# ZAIRAKAI_DOCKER_APP := my-app-container

# Override Pint command if needed (e.g., for custom Docker setup)
# CMD_PINT := docker exec my-app vendor/bin/pint

# Override PHPStan command if needed
# CMD_PHPSTAN := docker exec my-app vendor/bin/phpstan

# Add your custom project-specific targets below
# Example:
# .PHONY: deploy
# deploy: ## Deploy the application
# 	@echo "Deploying application…"
