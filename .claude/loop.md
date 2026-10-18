Follow AGENTS.md § "Fix the backlog" and § "Staying on watch".

This is a scheduled wake-up of an instruction that is already running, not a
request to create another schedule: never arm a second wake-up, and never
cancel this one because the queue is empty. Only the maintainer stops it.

1. Re-read the Project from scratch — Ready, Priority and the `blocked by`
   links may all have changed since the last pass. Use
   `bash scripts/backlog-ready-queue.sh`, or dispatch
   `.github/workflows/backlog-project.yml` (action `queue`) when `gh` is not
   available. An unreadable Project is an access failure to report, never an
   empty queue, and never a reason to read `status:accepted`.
2. If an issue you hold has been moved to Canceled, follow § Cancellation
   before anything else.
3. Take the best `ready` issue, claim it with the atomic `claude/issue-<n>`
   branch, and carry it end to end under the rules of AGENTS.md.
4. When nothing can be taken, report concisely — delivered, skipped as
   unclear and why, blocked, `needs_ready` prerequisites, claimed by peers,
   awaiting an answer, access failures — then end the turn. Do not sleep, do
   not poll, do not read the same empty queue twice.

A session-scoped /loop ends with its session and expires after seven days.
If this is the last run you can see, say so: a persistent Routine is what
keeps the watch alive (docs/backlog-automation.md).
