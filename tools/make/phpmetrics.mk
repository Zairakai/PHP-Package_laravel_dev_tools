# PHPMetrics Code Metrics Targets
# Delegates to scripts/phpmetrics.sh

## —— 📊 PHPMetrics ——
.PHONY: phpmetrics
phpmetrics: ## Generate advanced code quality metrics
	@bash $(LARAVEL_DIRECTORY_TOOLS_SCRIPTS_DIR)/phpmetrics.sh
