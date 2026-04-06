# Utility Targets
# Miscellaneous commands for development

## —— 🎼  Composer ——
.PHONY: composer-install
composer-install: ## Install Composer dependencies
	@echo "📦 Installing dependencies…"
	@composer install --prefer-dist --no-interaction --optimize-autoloader

.PHONY: composer-update
composer-update: ## Update Composer dependencies
	@echo "⬆️  Updating dependencies…"
	@composer update --prefer-dist --no-interaction --optimize-autoloader

.PHONY: composer-normalize
composer-normalize: ## Check composer.json normalization
	@bash $(LARAVEL_DIRECTORY_TOOLS_TOOLS_SCRIPTS_DIR)/composer-normalize.sh

.PHONY: composer-validate
composer-validate: ## Validate composer.json
	@composer validate --strict

## —— 🧰 Utils ——
.PHONY: security-audit
security-audit: ## Run Composer security audit
	@bash $(LARAVEL_DIRECTORY_TOOLS_SCRIPTS_DIR)/security-audit.sh

.PHONY: install-packages
install-packages: ## Install optional PHP tools interactively
	@bash $(LARAVEL_DIRECTORY_TOOLS_SCRIPTS_DIR)/install-packages.sh

.PHONY: setup
setup: ## Run the dev-tools setup wizard (Makefile, configs, CI stub)
	@bash $(LARAVEL_DIRECTORY_TOOLS_SCRIPTS_DIR)/setup-package.sh

.PHONY: uninstall
uninstall: ## Remove dev-tools configuration files
	@bash $(LARAVEL_DIRECTORY_TOOLS_SCRIPTS_DIR)/uninstall-package.sh

