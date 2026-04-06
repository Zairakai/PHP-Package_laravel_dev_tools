# Full-Stack Shared Utilities

## —— 🧰 Utils ——
.PHONY: doctor
doctor: ## Run environment diagnostics
	@bash $(LARAVEL_DIRECTORY_TOOLS_SCRIPTS_DIR)/doctor.sh
ifneq ($(NPM_DIRECTORY_TOOLS_AVAILABLE),false)
	@bash $(NPM_DIRECTORY_TOOLS_SCRIPTS_DIR)/doctor.sh
endif

## —— 🌿 Git ——
.PHONY: install-hooks
install-hooks: ## Install git hooks into .git/hooks
	@bash $(LARAVEL_DIRECTORY_TOOLS_SCRIPTS_DIR)/install-hooks.sh
ifneq ($(NPM_DIRECTORY_TOOLS_AVAILABLE),false)
	@bash $(NPM_DIRECTORY_TOOLS_SCRIPTS_DIR)/install-hooks.sh
endif

.PHONY: git-update
git-update: ## Fast-forward all local branches that track a remote
	@$(LARAVEL_DIRECTORY_TOOLS_TOOLS_SCRIPTS_DIR)/git-update.sh
ifneq ($(NPM_DIRECTORY_TOOLS_AVAILABLE),false)
	@bash $(NPM_DIRECTORY_TOOLS_PACKAGE_ROOT)/tools/scripts/git-update.sh
endif

.PHONY: git-cleanup
git-cleanup: ## Remove local branches whose remote tracking branch no longer exists
	@$(LARAVEL_DIRECTORY_TOOLS_TOOLS_SCRIPTS_DIR)/git-cleanup.sh
ifneq ($(NPM_DIRECTORY_TOOLS_AVAILABLE),false)
	@bash $(NPM_DIRECTORY_TOOLS_PACKAGE_ROOT)/tools/scripts/git-cleanup.sh
endif

.PHONY: git-rebase
git-rebase: ## Rebase the current branch to the selected one
	@$(LARAVEL_DIRECTORY_TOOLS_TOOLS_SCRIPTS_DIR)/git-rebase.sh
