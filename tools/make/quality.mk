# Quality Targets

## —— ✅ Quality Gates ——
.PHONY: quality
quality:: markdownlint shellcheck rector pint phpstan insights ## Run all quality checks
	@echo "✅ All quality checks passed"

.PHONY: quality-fast
quality-fast:: ## Run fast quality checks only (Pint + PHPStan + Markdownlint)
	@bash $(LARAVEL_DIRECTORY_TOOLS_SCRIPTS_DIR)/ci-quality.sh

.PHONY: quality-fix
quality-fix:: markdownlint-fix rector-fix spread-fix pint-fix composer-normalize-fix ## Auto-fix all fixable issues (markdownlint, rector, layout, pint, composer.json)
	@echo "✅ All auto-fixes applied"

.PHONY: ci
ci:: quality test bats ## Full CI validation (quality + tests + bats)
	@echo ""
	@echo "✅ CI validation passed"
