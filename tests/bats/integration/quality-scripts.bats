#!/usr/bin/env bats
#
# Integration Tests for Quality Scripts
#
# Tests the quality check scripts behavior including:
# - doctor.sh: Environment diagnostics
# - phpstan.sh: Static analysis
# - cs-check.sh / cs-fix.sh: Code style
# - test.sh: PHPUnit execution
#
# These tests focus on script structure, validation, and error handling
# rather than the actual tools they wrap.
#

# Load test helpers
load '../helpers/test_helper'

setup() {
  setup_test_env
}

teardown() {
  teardown_test_env
}

# doctor.sh Tests
# ================

@test "doctor.sh sources config.sh" {
  run grep -q "source.*config.sh" "${SCRIPT_DIR}/doctor.sh"

  [ "$status" -eq 0 ]
}

@test "doctor.sh displays environment information" {
  # doctor.sh should be safe to run without dependencies
  run bash "${SCRIPT_DIR}/doctor.sh"

  # Should display headers and info (even if tools missing)
  [[ "$output" =~ "PHP Version" ]] || [[ "$output" =~ "Environment" ]]
}

@test "doctor.sh shows available tools" {
  run bash "${SCRIPT_DIR}/doctor.sh"

  # Should check for common tools
  [[ "$output" =~ "PHPUnit" ]] || [[ "$output" =~ "Tools" ]]
  [[ "$output" =~ "PHPStan" ]] || [[ "$output" =~ "Tools" ]]
}

@test "doctor.sh detects git repository" {
  cd "${TEST_TEMP_DIR}"

  run bash "${SCRIPT_DIR}/doctor.sh"

  [[ "$output" =~ "Git" ]] || [[ "$output" =~ "repository" ]]
}

@test "doctor.sh shows configuration resolution" {
  run bash "${SCRIPT_DIR}/doctor.sh"

  # Should display config file detection
  [[ "$output" =~ "Configuration" ]] || [[ "$output" =~ "PHPUnit" ]]
}

# phpstan.sh Tests
# ================

@test "phpstan.sh sources config.sh" {
  run grep -q "source.*config.sh" "${SCRIPT_DIR}/phpstan.sh"

  [ "$status" -eq 0 ]
}

@test "phpstan.sh uses require_package for validation" {
  run grep -q "require_package" "${SCRIPT_DIR}/phpstan.sh"

  [ "$status" -eq 0 ]
}

@test "phpstan.sh validates required dependencies" {
  # Should check for both phpstan and larastan
  run grep -E "(phpstan/phpstan|larastan/larastan)" "${SCRIPT_DIR}/phpstan.sh"

  [ "$status" -eq 0 ]
}

@test "phpstan.sh checks for config file" {
  run grep -q "PHPSTAN_CONFIG" "${SCRIPT_DIR}/phpstan.sh"

  [ "$status" -eq 0 ]
}

@test "phpstan.sh supports baseline generation" {
  run grep -q "GENERATE_BASELINE" "${SCRIPT_DIR}/phpstan.sh"

  [ "$status" -eq 0 ]
}

@test "phpstan.sh uses memory limit from config" {
  run grep -q "PHPSTAN_MEMORY" "${SCRIPT_DIR}/phpstan.sh"

  [ "$status" -eq 0 ]
}

# cs-check.sh Tests
# ================

@test "cs-check.sh sources config.sh (standardization)" {
  run grep -q "source.*config.sh" "${SCRIPT_DIR}/cs-check.sh"

  [ "$status" -eq 0 ]
}

@test "cs-check.sh uses require_package for validation" {
  run grep -q "require_package" "${SCRIPT_DIR}/cs-check.sh"

  [ "$status" -eq 0 ]
}

@test "cs-check.sh checks for Pint config" {
  run grep -q "PINT_CONFIG" "${SCRIPT_DIR}/cs-check.sh"

  [ "$status" -eq 0 ]
}

@test "cs-check.sh detects dirty files when git available" {
  run grep -q "get_dirty_php_files" "${SCRIPT_DIR}/cs-check.sh"

  [ "$status" -eq 0 ]
}

# insights.sh Tests
# ================

@test "insights.sh refuses --fix: PHPInsights reports, it does not rewrite" {
  run grep -q 'not supported' "${SCRIPT_DIR}/insights.sh"

  [ "$status" -eq 0 ]
}

@test "quality-fix runs the fixers in order: markdownlint, rector, layout, pint, composer.json" {
  run grep -E '^quality-fix:: markdownlint-fix rector-fix spread-fix pint-fix composer-normalize-fix' "${PROJECT_ROOT}/tools/make/quality.mk"

  [ "$status" -eq 0 ]
}

@test "spread-fix.sh exists and is executable" {
  [ -x "${SCRIPT_DIR}/spread-fix.sh" ]
}

@test "spread-fix.sh uses the phpcbf rule and CodeSpreader" {
  run grep -q 'phpcs.xml' "${SCRIPT_DIR}/spread-fix.sh"

  [ "$status" -eq 0 ]
  run grep -q 'spread-code.php' "${SCRIPT_DIR}/spread-fix.sh"

  [ "$status" -eq 0 ]
}

@test "there is no insights-fix target: PHPInsights reports, it does not rewrite" {
  run grep -rq 'insights-fix' "${PROJECT_ROOT}/tools/make"

  [ "$status" -eq 1 ]
}

# cs-fix.sh Tests
# ================

@test "cs-fix.sh sources config.sh (standardization)" {
  run grep -q "source.*config.sh" "${SCRIPT_DIR}/cs-fix.sh"

  [ "$status" -eq 0 ]
}

@test "cs-fix.sh uses require_package for validation" {
  run grep -q "require_package" "${SCRIPT_DIR}/cs-fix.sh"

  [ "$status" -eq 0 ]
}

@test "cs-fix.sh uses --dirty flag for git repos" {
  run grep -q "\--dirty" "${SCRIPT_DIR}/cs-fix.sh"

  [ "$status" -eq 0 ]
}

# test.sh Tests
# ================

@test "test.sh sources config.sh" {
  run grep -q "source.*config.sh" "${SCRIPT_DIR}/test.sh"

  [ "$status" -eq 0 ]
}

@test "test.sh validates PHPUnit config exists" {
  run grep -q "PHPUNIT_CONFIG" "${SCRIPT_DIR}/test.sh"

  [ "$status" -eq 0 ]
}

@test "test.sh supports coverage mode" {
  run grep -q "COVERAGE" "${SCRIPT_DIR}/test.sh"

  [ "$status" -eq 0 ]
}

@test "test.sh checks for coverage driver" {
  run grep -q "DRIVER" "${SCRIPT_DIR}/test.sh"

  [ "$status" -eq 0 ]
}

@test "test.sh uses CI mode detection" {
  run grep -q "CI" "${SCRIPT_DIR}/test.sh"

  [ "$status" -eq 0 ]
}

# markdownlint.sh Tests
# ================

@test "markdownlint.sh sources config.sh" {
  run grep -q "source.*config.sh" "${SCRIPT_DIR}/markdownlint.sh"

  [ "$status" -eq 0 ]
}

@test "markdownlint.sh has proper shebang" {
  run head -n1 "${SCRIPT_DIR}/markdownlint.sh"

  [[ "$output" =~ "#!/usr/bin/env bash" ]]
}

# Standardization Tests (All Quality Scripts)
# ================

@test "cs-check.sh has correct shebang" {
  run head -n1 "${SCRIPT_DIR}/cs-check.sh"
  [[ "$output" =~ "#!/usr/bin/env bash" ]]
}

@test "cs-fix.sh has correct shebang" {
  run head -n1 "${SCRIPT_DIR}/cs-fix.sh"
  [[ "$output" =~ "#!/usr/bin/env bash" ]]
}

@test "phpstan.sh has correct shebang" {
  run head -n1 "${SCRIPT_DIR}/phpstan.sh"
  [[ "$output" =~ "#!/usr/bin/env bash" ]]
}

@test "test.sh has correct shebang" {
  run head -n1 "${SCRIPT_DIR}/test.sh"
  [[ "$output" =~ "#!/usr/bin/env bash" ]]
}

@test "doctor.sh has correct shebang" {
  run head -n1 "${SCRIPT_DIR}/doctor.sh"
  [[ "$output" =~ "#!/usr/bin/env bash" ]]
}

@test "cs-check.sh uses set -euo pipefail" {
  run grep -q "set -euo pipefail" "${SCRIPT_DIR}/cs-check.sh"
  [ "$status" -eq 0 ]
}

@test "cs-fix.sh uses set -euo pipefail" {
  run grep -q "set -euo pipefail" "${SCRIPT_DIR}/cs-fix.sh"
  [ "$status" -eq 0 ]
}

@test "phpstan.sh uses set -euo pipefail" {
  run grep -q "set -euo pipefail" "${SCRIPT_DIR}/phpstan.sh"
  [ "$status" -eq 0 ]
}

@test "test.sh uses set -euo pipefail" {
  run grep -q "set -euo pipefail" "${SCRIPT_DIR}/test.sh"
  [ "$status" -eq 0 ]
}

@test "doctor.sh uses set -euo pipefail" {
  run grep -q "set -euo pipefail" "${SCRIPT_DIR}/doctor.sh"
  [ "$status" -eq 0 ]
}

@test "cs-check.sh sources config.sh" {
  run grep -q "source.*config.sh" "${SCRIPT_DIR}/cs-check.sh"
  [ "$status" -eq 0 ]
}

@test "cs-fix.sh sources config.sh" {
  run grep -q "source.*config.sh" "${SCRIPT_DIR}/cs-fix.sh"
  [ "$status" -eq 0 ]
}

@test "phpstan.sh sources config.sh (standardization)" {
  run grep -q "source.*config.sh" "${SCRIPT_DIR}/phpstan.sh"
  [ "$status" -eq 0 ]
}

@test "test.sh sources config.sh (standardization)" {
  run grep -q "source.*config.sh" "${SCRIPT_DIR}/test.sh"
  [ "$status" -eq 0 ]
}

@test "doctor.sh sources config.sh (standardization)" {
  run grep -q "source.*config.sh" "${SCRIPT_DIR}/doctor.sh"
  [ "$status" -eq 0 ]
}

@test "markdownlint.sh sources config.sh (standardization)" {
  run grep -q "source.*config.sh" "${SCRIPT_DIR}/markdownlint.sh"
  [ "$status" -eq 0 ]
}

# composer-normalize.sh Tests
# ================

@test "composer-normalize.sh --if-installed leaves composer.json alone when composer-normalize is not installed" {
  run grep -q -- '--if-installed' "${PROJECT_ROOT}/tools/scripts/composer-normalize.sh"

  [ "$status" -eq 0 ]
  run grep -q "is not installed: composer.json is left as it is" "${PROJECT_ROOT}/tools/scripts/composer-normalize.sh"

  [ "$status" -eq 0 ]
}

@test "composer-normalize.sh still fails without the option when composer-normalize is not installed" {
  run grep -q 'exit 1' "${PROJECT_ROOT}/tools/scripts/composer-normalize.sh"

  [ "$status" -eq 0 ]
}

@test "the quality-fix target normalizes composer.json only when composer-normalize is installed" {
  run grep -q -- 'composer-normalize.sh --if-installed' "${PROJECT_ROOT}/tools/make/utils.mk"

  [ "$status" -eq 0 ]
}

# spread-code.php Tests
# ================

@test "spread-code.php spreads an array and a call, and prints the number of files rewritten" {
  mkdir -p "${TEST_TEMP_DIR}/src"
  printf '<?php\n\n$a = [1];\nfoo($a, $b, $c);\n' > "${TEST_TEMP_DIR}/src/Sample.php"

  run php "${SCRIPT_DIR}/spread-code.php" "$PROJECT_ROOT" "${TEST_TEMP_DIR}/src"

  [ "$status" -eq 0 ]
  [ "$output" = "1" ]
  run grep -c "^    1,$" "${TEST_TEMP_DIR}/src/Sample.php"
  [ "$output" = "1" ]
  run grep -c '^    \$a,$' "${TEST_TEMP_DIR}/src/Sample.php"
  [ "$output" = "1" ]
}

@test "spread-code.php rewrites nothing the second time" {
  mkdir -p "${TEST_TEMP_DIR}/src"
  printf '<?php\n\n$a = [1, 2];\n' > "${TEST_TEMP_DIR}/src/Sample.php"

  php "${SCRIPT_DIR}/spread-code.php" "$PROJECT_ROOT" "${TEST_TEMP_DIR}/src" > /dev/null
  run php "${SCRIPT_DIR}/spread-code.php" "$PROJECT_ROOT" "${TEST_TEMP_DIR}/src"

  [ "$status" -eq 0 ]
  [ "$output" = "0" ]
}

@test "spread-code.php leaves the Fixtures as they are" {
  mkdir -p "${TEST_TEMP_DIR}/tests/Fixtures"
  printf '<?php\n\n$a = [1, 2, 3];\n' > "${TEST_TEMP_DIR}/tests/Fixtures/Sample.php"

  run php "${SCRIPT_DIR}/spread-code.php" "$PROJECT_ROOT" "${TEST_TEMP_DIR}/tests"

  [ "$output" = "0" ]
  run grep -c '\[1, 2, 3\]' "${TEST_TEMP_DIR}/tests/Fixtures/Sample.php"
  [ "$output" = "1" ]
}

@test "spread-code.php ignores a directory that does not exist" {
  run php "${SCRIPT_DIR}/spread-code.php" "$PROJECT_ROOT" "${TEST_TEMP_DIR}/missing"

  [ "$status" -eq 0 ]
  [ "$output" = "0" ]
}

# phpcs.xml Tests
# ================

@test "phpcs.xml makes phpcbf spread a ternary" {
  [ -x "${PROJECT_ROOT}/vendor/bin/phpcbf" ] || skip "phpcbf is not installed"

  printf '<?php\n\n$a = $x ? 1 : 2;\n' > "${TEST_TEMP_DIR}/Sample.php"

  run "${PROJECT_ROOT}/vendor/bin/phpcbf" --standard="${PROJECT_ROOT}/config/phpcs.xml" "${TEST_TEMP_DIR}/Sample.php"

  run grep -c '^    ? 1$' "${TEST_TEMP_DIR}/Sample.php"
  [ "$output" = "1" ]
  run grep -c '^    : 2;$' "${TEST_TEMP_DIR}/Sample.php"
  [ "$output" = "1" ]
}

@test "phpcs.xml leaves a ternary that is already on several lines" {
  [ -x "${PROJECT_ROOT}/vendor/bin/phpcbf" ] || skip "phpcbf is not installed"

  printf '<?php\n\n$a = $x\n    ? 1\n    : 2;\n' > "${TEST_TEMP_DIR}/Sample.php"
  cp "${TEST_TEMP_DIR}/Sample.php" "${TEST_TEMP_DIR}/Before.php"

  run "${PROJECT_ROOT}/vendor/bin/phpcbf" --standard="${PROJECT_ROOT}/config/phpcs.xml" "${TEST_TEMP_DIR}/Sample.php"

  run diff "${TEST_TEMP_DIR}/Before.php" "${TEST_TEMP_DIR}/Sample.php"
  [ "$status" -eq 0 ]
}

# spread-fix.sh Tests
# ================

@test "spread-fix.sh runs only on the app, src and tests directories" {
  run grep -q 'for directory in app src tests' "${SCRIPT_DIR}/spread-fix.sh"

  [ "$status" -eq 0 ]
}

@test "spread-fix.sh does not fail when phpcbf fixed something (exit 1) or left something (exit 2)" {
  run grep -q 'status -gt 2' "${SCRIPT_DIR}/spread-fix.sh"

  [ "$status" -eq 0 ]
}
