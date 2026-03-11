# PHPUnit Testing Targets
# Delegates to scripts/test.sh with appropriate environment variables

## —— 🧪 Testing ——
.PHONY: test
test:: ## Run all tests (without coverage)
	@bash $(LARAVEL_DIRECTORY_TOOLS_SCRIPTS_DIR)/test.sh

.PHONY: test-unit
test-unit:: ## Run unit tests only
	@TESTSUITE=Unit bash $(LARAVEL_DIRECTORY_TOOLS_SCRIPTS_DIR)/test.sh

.PHONY: test-feature
test-feature:: ## Run feature tests only
	@TESTSUITE=Feature bash $(LARAVEL_DIRECTORY_TOOLS_SCRIPTS_DIR)/test.sh

.PHONY: test-coverage
test-coverage:: ## Run tests with coverage report
	@COVERAGE=true bash $(LARAVEL_DIRECTORY_TOOLS_SCRIPTS_DIR)/test.sh

.PHONY: test-ci
test-ci:: ## Run tests in CI mode (strict)
	@CI=true bash $(LARAVEL_DIRECTORY_TOOLS_SCRIPTS_DIR)/test.sh
