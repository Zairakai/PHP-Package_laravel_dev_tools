# Code Layout Targets
# Delegates to scripts/spread-fix.sh

## —— 📐 Code Layout ——
.PHONY: spread-fix
spread-fix: ## Spread the ternaries, the arrays and the calls on several lines
	@bash $(LARAVEL_DIRECTORY_TOOLS_SCRIPTS_DIR)/spread-fix.sh
