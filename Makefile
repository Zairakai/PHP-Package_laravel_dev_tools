# Default Makefile delegating to the shared core tooling.

LARAVEL_DIRECTORY_TOOLS_PROJECT_NAME := "Laravel-Dev-Tools"
LARAVEL_DIRECTORY_TOOLS_PROJECT_ROOT := $(shell pwd)

include tools/make/core.mk
