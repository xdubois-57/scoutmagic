<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Feedback\Bounce;

use Core\Journal\JournalService;

/**
 * What happens when a message comes back (roadmap IT-05).
 *
 * **The policy, kept away from the parsing and away from the storage.**
 * {@see DeliveryStatusReport} reads what a stranger's server wrote;
 * {@see BounceStateRepository} counts; this decides — block or not, tell
 * the member or not. Three decisions that change for different reasons.
 */
class BounceService
{
    /**
     * Permanent failures before an address stops being written to.
     *
     * **Two, not one and not five.** One is too few: a 5.x.x is sometimes
     * a momentary misconfiguration at the far end reported with the wrong
     * class, and blocking on it cuts a family off over a server's bad
     * minute. Five is too many: a unit sends a handful of mailings a
     * month, so five failures is most of a school year during which
     * nobody is told anything — which is the silence this whole chantier
     * exists to end.
     */
    public const FAILURES_BEFORE_BLOCK = 2;

    public function __construct(
        private BounceStateRepository $states,
        private ?JournalService $journal = null,
        private ?BounceNotifier $notifier = null
    ) {
    }

    /**
     * Record one recipient's failure and act on it.
     *
     * Returns the state as it now stands, so a caller that wants to
     * report on a whole report can count what it changed.
     */
    public function record(DeliveryStatusReport $report, ?\DateTimeImmutable $now = null): ?BounceState
    {
        $now ??= new \DateTimeImmutable();

        $state = $this->states->record(
            $report->recipient,
            $report->category,
            $report->severity,
            $report->statusCode,
            $now
        );

        // Refused: this site has no record of ever writing to that
        // address, so the report is somebody's word about a message we
        // cannot show we sent. See `mail_send_receipts`.
        if ($state === null) {
            return null;
        }

        // **No severity test here, deliberately.** It would read well —
        // « only a permanent failure blocks » — and it would be a branch
        // no input can enter: `BounceStateRepository::record()` increments
        // the counter for permanent failures only, so a transient bounce
        // cannot move `failures` at all, let alone over the line. One
        // place enforces the rule, and it is the one that counts.
        $blocking = !$state->isBlocked() && $state->failures >= self::FAILURES_BEFORE_BLOCK;

        if ($blocking) {
            $this->states->block($state->id, $now);
            $this->journalBlocked($state);
        }

        $this->notify($state, $blocking);

        return $this->states->findById($state->id) ?? $state;
    }

    /**
     * A message for this address has just gone to a relay.
     *
     * Called from the send path, and deliberately cheap when there is
     * nothing to settle — the overwhelming majority of sends are to
     * addresses that have never bounced, and those cost one indexed read.
     *
     * The name is `recordSend` and not `recordSuccess` on purpose: handing
     * a message to a relay is not a success, and treating it as one is the
     * mistake that would quietly disable every block on this site. See
     * {@see BounceStateRepository::recordSend()} for what actually settles
     * a send.
     */
    public function recordSend(string $email, ?\DateTimeImmutable $now = null): void
    {
        $this->states->recordSend($email, $now ?? new \DateTimeImmutable());
    }

    /** Is the site still writing to this address? */
    public function isBlocked(string $email): bool
    {
        return $this->states->find($email)?->isBlocked() ?? false;
    }

    /**
     * What is known about this address, or null when it has never
     * bounced — which is what the screens need in order to say nothing at
     * all about the addresses that work.
     */
    public function stateFor(string $email): ?BounceState
    {
        return $this->states->find($email);
    }

    /**
     * Lift a block, whoever asked for it.
     *
     * The two callers are the member on their own address list and the
     * super-admin on the Courrier sortant page, and D19 is what makes
     * both legitimate: the site placed this block, so the site — or the
     * person it inconveniences — may lift it. Neither of them can
     * reactivate an address a parent switched off, which is a different
     * decision belonging to a different person.
     */
    public function unblock(int $stateId, bool $byTheMemberThemselves): bool
    {
        $state = $this->states->findById($stateId);
        if ($state === null) {
            // A stale link, or an id somebody edited. Saying « remise en
            // service » for an address that was never found would be a
            // success message about nothing.
            return false;
        }

        // **And it has to be blocked**, which is not the same as having a
        // row. The button only shows for a blocked address, but the
        // button is not the guard: a direct POST — a stale tab, a
        // double-submit, or somebody trying — would otherwise reach an
        // address sitting at one failure and put the counter back to
        // zero. Repeated after each bounce, that address never reaches
        // the second strike, never blocks, and never appears on the
        // super-admin's list: the unit goes on writing to a dead mailbox
        // with nothing on any screen to say so. It would also clear
        // `notified_code` on an address still failing, undoing « une fois
        // par erreur » along the way.
        if (!$state->isBlocked()) {
            return false;
        }

        $this->states->unblock($stateId);

        try {
            $this->journal?->log(
                'core',
                'mail_bounce_unblocked',
                'info',
                'Blocage sur rebond levé',
                // The category and who lifted it, never the address
                // (SECURITY.md §11) — and no member id either, because
                // one address belongs to as many members as reference it.
                ['category' => $state->category->value, 'by' => $byTheMemberThemselves ? 'member' : 'superadmin']
            );
        } catch (\Throwable) {
            // The block is lifted either way; the journal entry is the
            // record of it, never a condition of it.
        }

        return true;
    }

    /**
     * Tell the member, under the one rule that makes it bearable.
     *
     * A blocking bounce always notifies: the address has just stopped
     * receiving anything, and there is no version of that which is not
     * news. A non-blocking one notifies only the first time for *that*
     * error — a full mailbox bounces at every single mailing, so without
     * the rule a parent would get one notification per send, which is the
     * fastest way to teach somebody to ignore this site.
     */
    private function notify(BounceState $state, bool $blocking): void
    {
        // **A blocked address has nothing more to say.** `$blocking` is
        // the moment the block is placed, so it is false for every bounce
        // after it — and a later failure carrying a DIFFERENT code would
        // otherwise pass the « déjà dit » test below and send the
        // non-blocking message: « un message n'a pas pu être remis …
        // réactivez l'adresse ci-dessous », to somebody whose address is
        // in fact suspended site-wide. It would also overwrite
        // `notified_code`, making the original error news again.
        //
        // The member has already been told the address is suspended, and
        // that is the standing state until they or the super-admin lift
        // it — at which point `unblock()` clears `notified_code` and the
        // next failure is news again, correctly.
        if ($state->isBlocked() && !$blocking) {
            return;
        }

        if (!$blocking && !$state->isNewError($state->statusCode)) {
            return;
        }

        try {
            $this->notifier?->notify($state, $blocking);
            // **Only once the telling succeeded.** Marking it regardless
            // would record an error as « déjà dit » that nobody was ever
            // told, and every later bounce carrying that code would take
            // the early return above. For a full mailbox that is the only
            // notification the member would ever have got.
            $this->states->markNotified($state->id, $state->statusCode);
        } catch (\Throwable) {
            // Best effort, like the journal: a notification that could not
            // be sent must not undo a bounce that was correctly recorded —
            // and must not be recorded as sent either.
        }
    }

    private function journalBlocked(BounceState $state): void
    {
        try {
            $this->journal?->log(
                'core',
                'mail_bounce_blocked',
                'info',
                'Adresse suspendue après rebonds',
                ['category' => $state->category->value, 'failures' => $state->failures]
            );
        } catch (\Throwable) {
            // As above.
        }
    }
}
