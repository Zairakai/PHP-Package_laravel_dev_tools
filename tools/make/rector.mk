# Rector Code Modernization Targets
# Delegates to scripts/rector-*.sh

## —— 🔧 Rector ——
.PHONY: rector
rector: ## Check code modernization opportunities (Rector)
	@bash $(LARAVEL_DIRECTORY_TOOLS_SCRIPTS_DIR)/rector-check.sh

.PHONY: rector-fix
rector-fix: ## Apply automatic code modernization
	@bash $(LARAVEL_DIRECTORY_TOOLS_SCRIPTS_DIR)/rector-fix.sh
