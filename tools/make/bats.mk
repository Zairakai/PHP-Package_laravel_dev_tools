# BATS Testing Targets
# Shell script testing with Bash Automated Testing System

## —— 🧪 Shell Script Testing ——
.PHONY: bats
bats:: ## Run shell script tests (BATS)
	@if [ ! -d tests/bats/unit ] && [ ! -d tests/bats/integration ]; then \
		echo "ℹ️  No BATS tests found — skipping"; \
		exit 0; \
	fi
	@if ! command -v bats &>/dev/null; then \
		echo "❌ BATS not installed - run: make install-bats"; \
		exit 1; \
	fi
	@bats tests/bats/unit/*.bats tests/bats/integration/*.bats

.PHONY: bats-unit
bats-unit:: ## Run BATS unit tests
	@if [ ! -d tests/bats/unit ]; then \
		echo "ℹ️  No BATS unit tests found — skipping"; \
		exit 0; \
	fi
	@if ! command -v bats &>/dev/null; then \
		echo "❌ BATS not installed - run: make install-bats"; \
		exit 1; \
	fi
	@bats tests/bats/unit/*.bats

.PHONY: bats-integration
bats-integration:: ## Run BATS integration tests
	@if [ ! -d tests/bats/integration ]; then \
		echo "ℹ️  No BATS integration tests found — skipping"; \
		exit 0; \
	fi
	@if ! command -v bats &>/dev/null; then \
		echo "❌ BATS not installed - run: make install-bats"; \
		exit 1; \
	fi
	@bats tests/bats/integration/*.bats

.PHONY: test-all
test-all:: test bats ## Run all tests (PHP + Shell)
	@echo ""
	@echo "✅ All tests passed"

##
.PHONY: install-bats
install-bats:: ## Install BATS framework
	@bash $(LARAVEL_DIRECTORY_TOOLS_SCRIPTS_DIR)/install-bats.sh
