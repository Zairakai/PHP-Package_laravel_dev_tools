# PHPStan Static Analysis Targets
# Delegates to scripts/phpstan.sh

## —— 🔍 Static Analysis ——
.PHONY: phpstan
phpstan: ## Run PHPStan static analysis
	@bash $(LARAVEL_DIRECTORY_TOOLS_SCRIPTS_DIR)/phpstan.sh

.PHONY: phpstan-baseline
phpstan-baseline: ## Generate PHPStan baseline
	@GENERATE_BASELINE=true bash $(LARAVEL_DIRECTORY_TOOLS_SCRIPTS_DIR)/phpstan.sh
