# ScoutMagic — agent instructions

The rules for this repository live in **[`AGENTS.md`](AGENTS.md)**, which
applies to every contributor, human or AI. **Read it before making any
change**, along with [`ARCHITECTURE.md`](ARCHITECTURE.md) (layering, the
`Api\` module contract, the schema model) and [`SECURITY.md`](SECURITY.md).
They are not summarised here — a summary would drift from them.

[`CONTRIBUTING.md`](CONTRIBUTING.md) has the submission steps, including
which checks to run for which kind of change.

`.claude/skills/steward/SKILL.md` covers pull request events specifically:
review comments, a red CI job, which local command reproduces which check.

## The three that catch people out

- **English code, French interface.** A French variable name or an English
  UI label is a bug, never a detail.
- **Tests are mandatory**, written alongside the code. A new PHP test
  directory must be registered in `phpunit.xml` in the same change, or
  nothing runs it.
- **`vendor/bin/phpstan analyse` before every commit touching PHP**, and
  `npm run typecheck` before every commit touching `public/assets/js/`.
  Passing tests is a different guarantee. Never silence a finding of your
  own by adding it to a baseline.

## This container

In a Claude Code **remote** session, the `SessionStart` hook has already
installed dependencies and started **MariaDB** with `TEST_DB_*` exported —
so `vendor/bin/phpunit` runs against the production engine, while CI's
`test` job runs MySQL 8. In a local checkout that hook exits immediately and
you manage your own dependencies and database; the database-backed tests
then *skip* rather than fail, so a green run there proves less than it
looks. See the steward skill for both traps.
