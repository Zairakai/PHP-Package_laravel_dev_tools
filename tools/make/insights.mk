# PHPInsights Code Quality Analysis Targets
# Delegates to scripts/insights.sh

## —— 💡 PHPInsights ——
.PHONY: insights
insights: ## Run comprehensive code quality analysis (PHPInsights)
	@bash $(LARAVEL_DIRECTORY_TOOLS_SCRIPTS_DIR)/insights.sh

.PHONY: insights-fix
insights-fix: ## Run PHPInsights with automatic fixes
	@bash $(LARAVEL_DIRECTORY_TOOLS_SCRIPTS_DIR)/insights.sh --fix
