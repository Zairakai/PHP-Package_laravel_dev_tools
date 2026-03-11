# ShellCheck Targets
# Delegates to scripts/validate-shellcheck.sh

## —— 🐚 ShellCheck ——
.PHONY: shellcheck
shellcheck:: ## Validate shell scripts with ShellCheck (100% compliance required)
	@bash $(LARAVEL_DIRECTORY_TOOLS_SCRIPTS_DIR)/validate-shellcheck.sh


##
.PHONY: install-shellcheck
install-shellcheck:: ## Install shellcheck
	@bash $(LARAVEL_DIRECTORY_TOOLS_SCRIPTS_DIR)/install-shellcheck.sh
