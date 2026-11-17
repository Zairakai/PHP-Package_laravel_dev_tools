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
# Double colon: in a full-stack project, the audit of js-dev-tools runs after this one
.PHONY: security-audit
security-audit:: ## Run Composer security audit
	@bash $(LARAVEL_DIRECTORY_TOOLS_SCRIPTS_DIR)/security-audit.sh

.PHONY: install-packages
install-packages: ## Install optional PHP tools interactively
	@bash $(LARAVEL_DIRECTORY_TOOLS_SCRIPTS_DIR)/install-packages.sh

.PHONY: setup
setup: ## Run the dev-tools setup
	@bash $(LARAVEL_DIRECTORY_TOOLS_SCRIPTS_DIR)/setup-package.sh

# Double colon: in a full-stack project, the targets of js-dev-tools run after these ones
.PHONY: setup-check
setup-check:: ## Tell which files of the setup are missing or differ from the package (writes nothing)
	@bash $(LARAVEL_DIRECTORY_TOOLS_SCRIPTS_DIR)/setup-check.sh

.PHONY: setup-update
setup-update:: ## Replace the files that setup-check reports
	@bash $(LARAVEL_DIRECTORY_TOOLS_SCRIPTS_DIR)/setup-update.sh

.PHONY: uninstall
uninstall:: ## Remove the setup files (copy kept in .dev-tools-backup/)
	@bash $(LARAVEL_DIRECTORY_TOOLS_SCRIPTS_DIR)/uninstall-package.sh

