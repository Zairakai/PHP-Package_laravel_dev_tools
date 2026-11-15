# Contributing

> This project follows the [Zairakai Global Contributing Guide][handbook-contributing].  
> Please read it before contributing. The sections below document project-specific workflow.

---

## Development Workflow

| Step | Command / Action | Description |
| :--- | :--- | :--- |
| **1. Issue** | Open or pick an issue | One branch and one merge request per issue. |
| **2. Install** | `composer install` | Install the dependencies. `make install-hooks` activates the Git hooks. |
| **3. Branch** | `git checkout -b feature/#TICKET-name develop` | Create a branch from `develop`. |
| **4. Code** | *(your IDE)* | Keep the change small, and write its test. |
| **5. Fix** | `make quality-fix` | Apply the fixes: markdownlint, Rector, the layout (ternaries, arrays, calls), Pint and `composer.json`. |
| **6. Check** | `make quality` and `make test-all` | Run the whole quality gate, then PHPUnit and BATS. |
| **7. Commit** | `git commit -m "type(scope): #TICKET subject"` | Use the format of the [zairakai handbook][git-rules], in English, 50 characters at most for the subject (after the type, the scope and the ticket). The `commit-msg` hook checks it. |
| **8. Push** | `git push origin feature/#TICKET-name` | Push and open a merge request to `develop`. |

A merge request needs a review and is merged with a merge commit.

---

## Types of Contributions

| Type | Guidelines |
| :--- | :--- |
| **Bug reports** | Include minimal reproduction steps, expected versus actual behavior, and environment details. |
| **Feature requests** | Describe the use case and the problem solved, give an example of usage, and check for existing issues. |
| **Artisan commands** | For `src/Console/Commands/`. Follow Laravel conventions and use the Components API for output. |
| **Make targets** | For `.mk` files in `tools/make/`. Follow the naming patterns and include help comments. |
| **Configuration** | For `config/` and `stubs/`. Keep the names of the tools, and extend the rules of `php-dev-tools` instead of copying them. |
| **Shell scripts** | For `scripts/`. Ensure 100% ShellCheck compliance and use `config.sh` for logging. |

---

## Checks

| Command | Tool | Description |
| :--- | :--- | :--- |
| `make quality` | All | The whole gate: markdownlint, ShellCheck, Rector, Pint, PHPStan and PHP Insights. `make quality-fast` runs Pint, PHPStan and markdownlint. |
| `make quality-fix` | All | The fixes, in order: markdownlint, Rector, the layout, Pint, `composer.json`. |
| `make spread-fix` | phpcbf and CodeSpreader | Writes the ternaries, the arrays and the calls on several lines, as the handbook asks. Pint then aligns them. |
| `make pint` | Pint | Check the code style. `make pint-fix` fixes it (`composer cs`, `composer cs:fix`). |
| `make phpstan` | PHPStan | Static analysis at the maximum level with the strict rules (`composer analyse`). |
| `make rector` | Rector | Check what Rector would change. `make rector-fix` applies it (`composer rector`, `composer rector:fix`). |
| `make insights` | PHP Insights | Architecture and code quality. It reports only: its sniffs are fixed by hand, following the handbook. |
| `make test` | PHPUnit | Run the unit tests. `make bats` runs the shell script tests. |
| `make markdownlint` | markdownlint | Validate the Markdown files. |

A file must not be excluded to hide an error, and an exclusion is explained where it is written. The pipeline runs the same checks and must pass before a change reaches `develop` or `main`.

---

## Dependencies held back

Some dependencies stay on an older version because another package does not accept the new one yet. They are listed in one place, the [dependency policy](https://gitlab.com/zairakai/dependency-policy) (`php.json`), with the reason and the condition that lifts the block. Nothing is written here by hand: the job `policy-check` fails a merge request that updates a package of the policy, in `composer.json` or `composer.lock`.

---

## Releasing

Reserved to the maintainer. Tags are plain `X.Y.Z`, signed and annotated, and made on `main` only.

1. Merge `develop` into `main` with a merge request, and wait for the pipeline of `main`.
2. Tag the merge commit and push the tag:

   ```bash
   git checkout main && git pull
   git tag -s -a 1.0.0 -m "1.0.0"
   git push origin 1.0.0
   ```

3. The pipeline of the tag creates the release and publishes the version on Packagist.
4. The update of `php-dev-tools` starts the weekly update of this package by itself (see "Automatic updates" in the README).

---

## Language

Code, comments, commits, issues and merge requests are in English.

---

[handbook-contributing]: https://gitlab.com/zairakai/handbook/-/blob/main/CONTRIBUTING.md
[git-rules]: https://gitlab.com/zairakai/handbook/-/blob/main/policies/git-rules.md
