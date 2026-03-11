# Enlightn Security & Performance Analysis Targets
# Delegates to scripts/enlightn.sh

## —— 🔒 Enlightn ——
.PHONY: enlightn
enlightn: ## Run security and performance analysis (Enlightn)
	@bash $(LARAVEL_DIRECTORY_TOOLS_SCRIPTS_DIR)/enlightn.sh

.PHONY: enlightn-ci
enlightn-ci: ## Run Enlightn in CI mode (strict)
	@bash $(LARAVEL_DIRECTORY_TOOLS_SCRIPTS_DIR)/enlightn.sh --ci
