Follow the "Fix the backlog" procedure in AGENTS.md.

This is a scheduled wake-up, not an instruction to create another scheduled task.
Read the current ScoutMagic GitHub Project #1 Ready queue with
`bash scripts/backlog-ready-queue.sh`, respect the native blocked-by
dependencies and priority order, and atomically claim only one executable
issue at a time. Continue through the selected issue's tests and PR as
AGENTS.md requires. When no issue is claimable, return an idle summary
without canceling the hourly recurrence or running a shell sleep.
Only the maintainer can cancel the schedule.

A session-scoped Claude Code /loop cannot survive its seven-day expiry or
a terminated session. Report that limitation; an externally configured
persistent Routine or desktop schedule is needed for indefinite operation.
