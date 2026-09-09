## Description



## Checklist
- [ ] I have read `ARCHITECTURE.md` and my changes conform to it
- [ ] I have read `SECURITY.md` and applied the security checklist
- [ ] All code and comments are in English
- [ ] All UI-facing text is in French
- [ ] This description, and every commit message in this PR, are in French (`AGENTS.md` § Language)
- [ ] Automated tests are written/updated for this change
- [ ] Tests pass locally (`vendor/bin/phpunit`)
- [ ] PHPStan passes (`vendor/bin/phpstan analyse` — covers `core/`, `modules/`, and `public/`)
- [ ] If this PR changes `public/assets/js/` behavior: a Vitest spec was added/updated in `tests/js/` where practical, and `npm test` passes locally
