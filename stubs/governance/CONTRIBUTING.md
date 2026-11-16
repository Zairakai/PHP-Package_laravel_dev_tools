# Contributing

> This project follows the [Zairakai Global Contributing Guide][handbook-contributing].  
> Please read it before contributing. The sections below document project-specific workflow.
> Questions and bugs: [open an issue][issues].

---

## Development Workflow

| Step | Command / Action | Description |
| :--- | :--- | :--- |
| **1. Install** | `composer install` | Install dependencies and set up git hooks. |
| **2. Branch** | `git checkout -b feature/#TICKET-name` | Create a feature branch from `develop`. |
| **3. Code** | *(your IDE)* | Implement your changes following quality standards. |
| **4. Quality** | `make quality` | Run the full quality gate. |
| **5. Test** | `make test` | Ensure all tests are passing. |
| **6. Commit** | `git commit -m "type(scope): #TICKET subject"` | Use [Conventional Commits][git-rules] format. |
| **7. Push** | `git push origin feature/#TICKET-name` | Push and open a Merge Request to `develop`. |

---

## Quality Targets

| Command | Tool | Description |
| :--- | :--- | :--- |
| `make quality` | All | Full static analysis and formatting gate. |
| `make phpstan` | PHPStan | PHP static analysis (Level Max). |
| `make pint` | Pint | Check code style. |
| `make pint-fix` | Pint | Fix code style automatically. |
| `make test` | PHPUnit | Run unit tests with coverage. |
| `make markdownlint` | Markdownlint | Validate Markdown documentation. |

---

[handbook-contributing]: https://gitlab.com/zairakai/handbook/-/blob/main/CONTRIBUTING.md
[git-rules]: https://gitlab.com/zairakai/handbook/-/blob/main/policies/git-rules.md
[issues]: PACKAGE_GITLAB_ISSUES
