# Security Policy

> This project follows the [Zairakai Global Security Policy][handbook-security].  
> Please refer to it for standard protections, response timeline, and contact information.

---

## 🔒 Reporting Vulnerabilities

| Channel | Description | Contact / Link |
| :--- | :--- | :--- |
| **GitLab Issues** | For non-sensitive issues (bugs, public vulnerabilities). | [Open Issue][issues] |
| **Service Desk** | Preferred channel for sensitive reports. | `contact-project+zairakai-php-packages-laravel-dev-tools-80183553-issue-@incoming.gitlab.com` |
| **Email** | Alternative secure contact. | `security@the-white-rabbits.fr` |

Please **do not disclose vulnerabilities publicly** until they have been reviewed.

---

## 🛡️ Security Features

### Protection Layers

| Layer | Security Protection |
| :--- | :--- |
| **Static Analysis** | PHPStan Level Max compliance and Rector modernizations. |
| **Execution** | Controlled shell execution via `config.sh` and `scripts/`. |
| **Filesystem** | Non-destructive defaults for file generation and publishing. |
| **Git Hooks** | Isolated quality enforcement in project scope (`.githooks`). |
| **CI Pipeline** | Automated secret detection and ShellCheck validation. |

---

## 🔍 Security Scope

This package interacts with:

- **Filesystem**: Publishing configuration stubs and managing git hooks.
- **Git Config**: Modifying `core.hooksPath` for local quality enforcement.
- **Shell**: Executing maintenance and quality scripts.

No external network action is performed by git hooks or standard quality targets.

---

[handbook-security]: https://gitlab.com/zairakai/handbook/-/blob/main/SECURITY.md
[issues]: https://gitlab.com/zairakai/php-packages/laravel-dev-tools/-/issues
