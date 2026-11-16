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
composer-normalize: ## Normalize composer.json (rewrites the file)
	@bash $(LARAVEL_DIRECTORY_TOOLS_TOOLS_SCRIPTS_DIR)/composer-normalize.sh

.PHONY: composer-normalize-fix
composer-normalize-fix: ## Normalize composer.json, skipped when composer-normalize is not installed
	@bash $(LARAVEL_DIRECTORY_TOOLS_TOOLS_SCRIPTS_DIR)/composer-normalize.sh --if-installed

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
setup: ## Run the dev-tools setup (Makefile, .editorconfig, baseline, phpstan, Composer scripts, .gitignore block)
	@bash $(LARAVEL_DIRECTORY_TOOLS_SCRIPTS_DIR)/setup-package.sh

.PHONY: setup-check
setup-check: ## Tell which files of the setup are missing or differ from the package (writes nothing)
	@bash $(LARAVEL_DIRECTORY_TOOLS_SCRIPTS_DIR)/setup-check.sh

.PHONY: setup-update
setup-update: ## Publish again the files that setup-check reports, after a copy in .dev-tools-backup/ (DRY_RUN=true lists them)
	@bash $(LARAVEL_DIRECTORY_TOOLS_SCRIPTS_DIR)/setup-update.sh

.PHONY: uninstall
uninstall: ## Remove dev-tools configuration files
	@bash $(LARAVEL_DIRECTORY_TOOLS_SCRIPTS_DIR)/uninstall-package.sh

