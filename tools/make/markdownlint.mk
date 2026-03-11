# Markdownlint Targets
# Delegates to scripts/markdownlint.sh

## —— 📝 Markdownlint ——
.PHONY: markdownlint
markdownlint:: ## Validate Markdown documentation style and formatting
	@bash $(LARAVEL_DIRECTORY_TOOLS_SCRIPTS_DIR)/markdownlint.sh

.PHONY: markdownlint-fix
markdownlint-fix:: ## Fix Markdown documentation issues automatically
	@bash $(LARAVEL_DIRECTORY_TOOLS_SCRIPTS_DIR)/markdownlint.sh --fix

##
.PHONY: install-markdownlint
install-markdownlint:: ## Install Markdownlint
	@bash $(LARAVEL_DIRECTORY_TOOLS_SCRIPTS_DIR)/install-markdownlint.sh
