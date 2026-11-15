<p align="center">
  <img src="https://gitlab.com/zairakai/php-packages/laravel-dev-tools/-/raw/main/assets/icon.png" alt="Laravel Dev Tools" width="160">
</p>

# Laravel Dev Tools

[![Main][pipeline-main-badge]][pipeline-main-link]
[![Coverage][coverage-badge]][coverage-link]

[![GitLab Release][gitlab-release-badge]][gitlab-release]
[![Packagist][packagist-badge]][packagist]
[![Docs][docs-badge]][docs]
[![Downloads][downloads-badge]][packagist]
[![License][license-badge]][license]

[![PHP][php-badge]][php]
[![Laravel][laravel-badge]][laravel]
[![Static Analysis][phpstan-badge]][phpstan]
[![Code Style][pint-badge]][pint]

One toolkit for the quality of the Laravel projects of Zairakai: Pint, PHPStan, Rector, PHP Insights, markdownlint, Git hooks, `make` targets and GitLab CI templates. It adapts to standalone packages and to full-stack applications, and it extends the rules of [`stanislas-poisson/php-dev-tools`](https://packagist.org/packages/stanislas-poisson/php-dev-tools).

**Documentation: [laravel-dev-tools-8745c7.gitlab.io][docs]** (this guide, and the reference of every class, read from the source).

## The principle

A project does not copy the rules of the tools. It has small files at its root that **extend** the rules of the installed version, so the rules are always the ones of this package and the project only writes what is specific to it.

| File of the project | What it does |
| :--- | :--- |
| `phpstan.neon` | `includes` `config/library.neon` (packages) or `config/app.neon` (applications). |
| `rector.php` | Calls `RectorBaseConfig::configure()`. |
| `phpinsights.php` | Starts from `config/phpinsights.php`, then adds the overrides of the project. |
| `pint.json` | A **copy**: Pint cannot extend a file. It is generated from the rules of `php-dev-tools`, the Laravel layer of this package and the changes of the project (`config/dev-tools/pint.json`). Never edit it by hand. |
| `.markdownlint.json` | Extends `config/.markdownlint.json`, which extends the one of `php-dev-tools`. |
| `.markdownlintignore` | The one of `php-dev-tools`, copied (an ignore file cannot extend another). |

## Requirements

PHP 8.4 or higher and Laravel 12 or 13.

## Install

```bash
composer require --dev zairakai/laravel-dev-tools
```

For full-stack Laravel and Vue projects, add the JS tools, then publish the full-stack setup:

```bash
npm install --save-dev @zairakai/js-dev-tools
php artisan dev-tools:publish --fullstack
```

<details>
<summary>What the composer plugin does on install</summary>

The plugin adds a `setup-dev-tools` script to `composer.json` and runs it after each `composer install` and `composer update` (`scripts/setup-package.sh`). It also synchronizes the `ref` tags of the GitLab CI includes with the installed version. The setup:

- creates the `Makefile` (PHP only, or full-stack when `package.json` is found), which delegates to `vendor/zairakai/laravel-dev-tools/tools/make/core.mk`,
- creates `.editorconfig` and `config/dev-tools/baseline.neon` (the PHPStan baseline),
- merges the Composer scripts of the package (`composer cs`, `composer analyse`, `composer quality`, `composer spread:fix`...) into `composer.json`, without replacing the ones the project has.

</details>

## Usage

```bash
make quality        # markdownlint + shellcheck + rector + pint + phpstan + insights
make quality-fast   # pint + phpstan + markdownlint (fast CI check)
make quality-fix    # markdownlint + rector + layout + pint + composer.json
make spread-fix     # ternaries, arrays and calls on several lines (part of quality-fix)
make test           # phpunit
make test-all       # phpunit + bats
make ci             # full pipeline simulation
make doctor         # environment diagnostics
```

PHP Insights only reports: its sniffs are fixed by hand, following the [handbook](https://gitlab.com/zairakai/handbook/-/blob/main/standards/php.md).

<details>
<summary>What is inside</summary>

| Tool | Responsibility |
| :--- | :--- |
| **Pint** | Laravel code style, on the rules of `php-dev-tools`. |
| **PHPStan** | Static analysis at max level with Laravel-aware rules. |
| **Rector** | Automated refactoring and modernization. |
| **PHP Insights** | Architecture and code quality metrics. |
| **PHPMetrics** | Code metrics report. |
| **markdownlint** | Consistent documentation. |
| **ShellCheck** | Validated shell scripts. |
| **Git hooks** | `commit-msg`, `prepare-commit-msg`, `pre-commit` and `pre-push`, read in place by `git config core.hooksPath` (`make install-hooks`). |
| **GitLab CI** | Reusable pipeline templates for Laravel apps and packages. |
| **Makefile** | The same `make` targets for a package and for an application. |
| **Full-stack** | Works with `@zairakai/js-dev-tools` for the JS and TS tools in one workflow. |

</details>

<details>
<summary>Configuration examples for each tool</summary>

### PHPStan

```neon
includes:
    - vendor/zairakai/laravel-dev-tools/config/library.neon

parameters:
    paths:
        - src
        - tests
```

### Rector

```php
<?php

declare(strict_types=1);

use Zairakai\LaravelDevTools\Rector\RectorBaseConfig;

require __DIR__ . '/vendor/autoload.php';

return RectorBaseConfig::configure(projectRoot: __DIR__);
```

### PHP Insights

```php
<?php

declare(strict_types=1);

$baseConfig = require __DIR__ . '/vendor/zairakai/laravel-dev-tools/config/phpinsights.php';

// What is specific to the project goes here. Use the spread operator for the lists (remove, add, exclude).

return $baseConfig;
```

</details>

<details>
<summary>Publishing the thin files</summary>

`dev-tools:publish` copies the thin files to the project root. A file is never overwritten unless `--force` is given. `pint.json` is generated, and `baseline.neon` and `phpunit.xml` go to `config/dev-tools/`.

```bash
php artisan dev-tools:publish --publish                 # everything
php artisan dev-tools:publish --publish=quality         # a group: quality, style, testing, hooks, governance
php artisan dev-tools:publish --publish=pint            # a single file: phpstan, baseline, rector, insights,
                                                        # pint, markdownlint, markdownlintignore, phpunit, gitlab-ci
php artisan dev-tools:publish --fullstack               # Makefile for PHP and JS
```

For a package without Artisan:

```bash
bash vendor/zairakai/laravel-dev-tools/scripts/setup-package.sh --publish=gitlab-ci
bash vendor/zairakai/laravel-dev-tools/scripts/setup-package.sh --fullstack
```

</details>

<details>
<summary>GitLab CI pipeline templates</summary>

```yaml
include:
  - project: 'zairakai/php-packages/laravel-dev-tools'
    ref: 1.0.0          # pin to a release tag for reproducible builds
    file: '.gitlab/ci/pipeline-php-package.yml'

variables:
  CACHE_KEY: "my-package-v1"
  PACKAGIST_PACKAGE: "vendor/my-package"
```

Change `file` for the other templates:

- `pipeline-php-package.yml`: security, install, validate, quality, test, publish, release, metrics.
- `pipeline-laravel-app.yml`: security, install, validate, quality, test, deploy, metrics.
- `pipeline-laravel-fullstack.yml`: the PHP and JS pipelines in a single include.

</details>

<details>
<summary>Automatic updates</summary>

`vendor/bin/update-dependencies` keeps the requirements of a package up to date. The CI template runs it from the jobs `deps:update`, `deps:audit`, `deps:release`, `deps:tag` and `deps:cascade`:

- `update`: `composer update -W`, `composer bump`, `composer normalize`, then a signed commit on a branch and a merge request into `develop`, merged when its pipeline succeeds.
- `audit`: looks for security advisories in the lock file, one issue per advisory (an alert through ntfy when `NTFY_URL` is set), closed when the advisory is gone. With the group variable `AUTO_FIX=true`, an advisory that is high, critical or of unknown severity starts an update.
- `release`: brings `develop` into `main` after an update.
- `tag`: tags `main` (signed) only when a published range (`require`) changed.
- `cascade`: after the publication on Packagist, starts the update of the projects that use the package.
- `policy`: in the repository `dependency-policy`, lifts the entries of `php.json` whose condition is met, with a merge request into `main`.
- `policy-check <ref>`: fails when the change since `<ref>` updates a package of the dependency policy. It only reads, so it runs on GitLab and on GitHub.
- `dependabot-sync`: rewrites the ignore rules of `.github/dependabot.yml`, between `# BEGIN dependency-policy (composer)` and `# END dependency-policy (composer)`.

It is started by a weekly schedule or by a pipeline with `UPDATE_DEPENDENCIES=true` (with `AUDIT_DEPENDENCIES=true` for a daily audit), and reads `.dependency-update.json` at the root of the project (`tag.when`: `runtime`, `always` or `never`; `tag.level`; `cascade.groups`). The group variables `GITLAB_TOKEN`, `GPG_PRIVATE_KEY_B64`, `RELEASE_SIGNER_NAME` and `RELEASE_SIGNER_EMAIL` are required.

</details>

<details>
<summary>Versioning</summary>

The package follows [Semantic Versioning](https://semver.org/).

**A major version** changes what a project writes or calls:

- the names of the thin files of `stubs/`, and where `dev-tools:publish` and `setup-package.sh` write them,
- the paths of the base files in `vendor/zairakai/laravel-dev-tools/` that a project includes or extends,
- the Composer scripts and the `make` targets that the Git hooks and the pipelines run,
- the lowest version of PHP or of Laravel.

**A minor version** can add an option, and can add or tighten a rule, or raise the version of a tool: a project that was green can turn red. **A patch version** fixes a defect without changing what the tools ask for.

</details>

## Development

See [CONTRIBUTING.md][contributing] for the workflow and the quality standards. `composer install` and `make install-hooks` set the package up.

## Statistics

![Statistics of laravel-dev-tools][stats-card]

## Getting Help

[![License][license-badge]][license]
[![Security Policy][security-badge]][security]
[![Issues][issues-badge]][issues]

**Made with ❤️ by [Zairakai][ecosystem]**

<!-- Reference Links -->
[pipeline-main-badge]: https://gitlab.com/zairakai/php-packages/laravel-dev-tools/badges/main/pipeline.svg?ignore_skipped=true&key_text=Main
[pipeline-main-link]: https://gitlab.com/zairakai/php-packages/laravel-dev-tools/commits/main
[coverage-badge]: https://gitlab.com/zairakai/php-packages/laravel-dev-tools/badges/main/coverage.svg
[coverage-link]: https://gitlab.com/zairakai/php-packages/laravel-dev-tools/-/jobs/artifacts/main/browse
[gitlab-release-badge]: https://img.shields.io/gitlab/v/release/zairakai/php-packages/laravel-dev-tools?logo=gitlab
[gitlab-release]: https://gitlab.com/zairakai/php-packages/laravel-dev-tools/-/releases
[packagist-badge]: https://img.shields.io/packagist/v/zairakai/laravel-dev-tools
[packagist]: https://packagist.org/packages/zairakai/laravel-dev-tools
[downloads-badge]: https://img.shields.io/packagist/dt/zairakai/laravel-dev-tools
[license-badge]: https://img.shields.io/badge/license-MIT-blue.svg
[license]: ./LICENSE
[security-badge]: https://img.shields.io/badge/security-scanned-green.svg
[security]: ./SECURITY.md
[issues-badge]: https://img.shields.io/gitlab/issues/open-raw/zairakai%2Fphp-packages%2Flaravel-dev-tools?logo=gitlab&label=Issues
[issues]: https://gitlab.com/zairakai/php-packages/laravel-dev-tools/-/issues
[php-badge]: https://img.shields.io/badge/php-8.4-blue?logo=php
[php]: https://www.php.net
[laravel-badge]: https://img.shields.io/badge/Laravel-12%20%7C%2013-red?logo=laravel
[laravel]: https://laravel.com
[phpstan-badge]: https://img.shields.io/badge/static%20analysis-phpstan-5B2C6F.svg?logo=php
[phpstan]: https://phpstan.org
[pint-badge]: https://img.shields.io/badge/code%20style-pint-22C55E.svg
[pint]: https://laravel.com/docs/pint
[ecosystem]: https://gitlab.com/zairakai
[contributing]: ./CONTRIBUTING.md
[docs]: https://laravel-dev-tools-8745c7.gitlab.io
[docs-badge]: https://img.shields.io/badge/docs-online-blue
[stats-card]: https://gitlab.com/zairakai/gitlab-profile/-/raw/main/assets/projects/laravel-dev-tools.svg
