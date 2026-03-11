# Laravel Pint Code Style Targets
# Delegates to scripts/cs-*.sh

## —— 🎨 Code Style ——
.PHONY: pint
pint: ## Check code style (Pint)
	@bash $(LARAVEL_DIRECTORY_TOOLS_SCRIPTS_DIR)/cs-check.sh

.PHONY: pint-fix
pint-fix: ## Fix code style automatically
	@bash $(LARAVEL_DIRECTORY_TOOLS_SCRIPTS_DIR)/cs-fix.sh
