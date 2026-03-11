# Full-Stack JS Extensions
# Included by fullstack.mk ONLY when @zairakai/js-dev-tools is detected in node_modules.
# DO NOT include this file directly — use fullstack.mk instead.

## —— 🧪 JS Testing ——

.PHONY: js-test
js-test: ## Run JS tests (Vitest)
	@bash $(NPM_DIRECTORY_TOOLS_SCRIPTS_DIR)/test.sh

.PHONY: js-test-coverage
js-test-coverage: ## Run JS tests with coverage (Vitest)
	@COVERAGE=true bash $(NPM_DIRECTORY_TOOLS_SCRIPTS_DIR)/test.sh

.PHONY: js-test-ci
js-test-ci: ## Run JS tests in CI mode (Vitest)
	@CI=true bash $(NPM_DIRECTORY_TOOLS_SCRIPTS_DIR)/test.sh

# Extend quality/test/ci aggregators with JS toolchain (double-colon append)
.PHONY: quality
quality:: eslint prettier stylelint knip

.PHONY: quality-fix
quality-fix:: eslint-fix prettier-fix stylelint-fix

.PHONY: test
test:: js-test

.PHONY: test-coverage
test-coverage:: js-test-coverage

.PHONY: test-ci
test-ci:: js-test-ci

.PHONY: ci
ci:: js-test-ci
