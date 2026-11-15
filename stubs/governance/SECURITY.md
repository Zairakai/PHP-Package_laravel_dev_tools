# Security Policy

> This project follows the [Zairakai Global Security Policy][handbook-security].  
> Please refer to it for standard protections, response timeline, and contact information.

---

## 🔒 Reporting Vulnerabilities

| Channel | Description | Contact / Link |
| :--- | :--- | :--- |
| **GitLab Issues** | For non-sensitive issues (bugs, public vulnerabilities). | [Open Issue][issues] |
| **Service Desk** | Preferred channel for sensitive reports. | `TODO: add your project service desk email` |
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

<!-- TODO: describe what this package interacts with (filesystem, network, shell, etc.) -->

---

[handbook-security]: https://gitlab.com/zairakai/handbook/-/blob/main/SECURITY.md
[issues]: PACKAGE_GITLAB_ISSUES
