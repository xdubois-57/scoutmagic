<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\MassMail\Task;

use Core\Import\MemberYearRepository;
use Core\Member\MemberService;
use Core\Scheduler\TaskContext;
use Modules\MassMail\Repository\Delivery;
use Modules\MassMail\Repository\Email;
use Modules\MassMail\Repository\NotifiedAccountRepository;
use Modules\MassMail\Repository\Target;
use Modules\MassMail\Service\MassMailService;
use Modules\MassMail\Service\TargetEmailLink;

/**
 * What follows a message of a mailing that has LEFT — its contact's bounce
 * receipt, its `delivery_sent` line, its account's notification — written
 * once for the two moments a message can leave (issue #1454): in the batch
 * (Task\SendBatchHandler), or a day later when the core's deferred queue
 * finally sends it (Task\DeferredDeliveryListener).
 *
 * **Only after the message left, never at its deferral.** The batch used to
 * run all three for a message the core had merely queued, so the journal
 * said « envoyé » and the account was told about an e-mail that was not in
 * its mailbox — and might never be.
 *
 * The status write is NOT here: the batch counts a first attempt
 * (`recordSendSuccess()`), the drain settles one already counted
 * (`settleDeferredAsSent()`), and that difference is the caller's to make.
 * Everything here is best-effort, for the reason each comment gives: the
 * copy has left, and nothing that follows may make it look as if it had
 * not.
 */
final class SentMessageFollowUp
{
    /**
     * @param Target[] $targets the targets this message concerns
     * @param int[] $memberIds their members, what the journal line names
     * @param string $subject the subject that was actually sent, personalised or not
     * @param bool $personalised whether that subject was rendered from a merge row
     */
    public function record(
        TaskContext $context,
        MassMailService $massMailService,
        Delivery $delivery,
        Email $email,
        array $targets,
        array $memberIds,
        string $subject,
        bool $personalised
    ): void {
        // **The module vouches for its own contacts**
        // (roadmap IT-05). `Core\Mail\MailService::send()` stamps
        // a bounce receipt by itself, but only for an address the
        // CORE already holds — a confirmed `member_emails` row or
        // a `user_accounts` row — because an address the site was
        // merely handed must not vouch for itself.
        //
        // A contact of a custom list's contact group is neither,
        // and it lives in a table this module owns, which core
        // cannot read (§7.5). It is also not something a visitor
        // typed: a staff member's file brought it. So the module says so here,
        // for its own recipients, and nothing else has to know
        // the rule.
        //
        // Harmless for a member recipient, whose receipt core
        // stamped a moment ago: one address is one receipt, and
        // the settling clock is set by the first send after a
        // bounce and left alone by the rest.
        //
        // **Guarded exactly like its twin** in
        // `Core\Mail\MailService::send()`, and here it matters
        // more. The copy has already left and
        // its status is committed; the only handler around the
        // batch's loop catches `MailException`, so anything
        // else — a `DecryptionException` on a row encrypted under
        // a rotated key, a `\ValueError` from a stored category
        // that is no longer a case, a `\PDOException` — would
        // end the batch here, with the rest of its recipients
        // unsent until the next one. handle() re-arms the chain
        // in its `finally` whatever escapes (issue #1443), so the
        // mailing is delayed rather than stranded; still, a
        // receipt is not worth even that.
        try {
            (new \Core\Mail\Feedback\Bounce\BounceStateRepository(
                $context->connection->getPdo(),
                $context->encryption
            ))->recordSend($delivery->emailAddress, new \DateTimeImmutable(), true);
        } catch (\Throwable) {
            // Deliberately silent, for the same reason the twin
            // gives: there is nobody to tell who could act on it,
            // and the journal is reached through the same database
            // that just refused.
        }
        // One line per copy that actually left — see
        // Service\MassMailService::journalRecipientSent(). The
        // batch summary below stays, but it answers a different
        // question ("did the scheduler run, and how big was the
        // lot?") and answers nothing about any one recipient.
        // Best-effort too (#1557 review): the copy has left and its
        // row says so. A journal that throws here would end the
        // batch before the completion check below, and a mailing
        // whose last copy this was would stay « Envoi en cours »
        // for good — no later batch selects it again.
        try {
            $massMailService->journalDeliverySent($email->id, $delivery->id, $memberIds);
        } catch (\Throwable) {
            // Nobody to tell: the journal is the one that refused.
        }
        // Best-effort, like the receipt above: the copy has left
        // and its row says so. A notification that throws — a
        // \PDOException from the claim, an error from dispatch(),
        // or a MailException, which the batch's handler would turn
        // into an `error` on a message that was in fact sent —
        // must not cost the rest of the mailing. Journaled without
        // the address or any text of the message.
        try {
            $this->notifyOnce(
                $context,
                $delivery,
                $targets,
                $email,
                $subject,
                $personalised
            );
        } catch (\Throwable $e) {
            try {
                $context->journal->log(
                    'mass_mail',
                    'notification_failed',
                    'error',
                    'Notification non envoyée pour un message de l\'envoi groupé',
                    ['email_id' => $email->id, 'delivery_id' => $delivery->id, 'exception' => $e::class],
                    null
                );
            } catch (\Throwable) {
                // Nobody left to tell: the journal goes through the
                // same database that may just have refused.
            }
        }
    }

    /**
     * Notification centre + push (module.json's "mass_mail.email_received"
     * — push default-on, email channel forced off since a redundant "you
     * got an email" email would defeat the point), **at most once per
     * account and per communication** (a classic list) **or per file row**
     * (a mail merge) — issue #999, §2.6.
     *
     * The account concerned is the one whose login is this message's
     * address, and only when the message concerns at least one target: a
     * contact's message, or a file row without a Tiers, notifies nobody,
     * whoever the address belongs to. A second message of the same
     * communication (or row) to another address of the same account, and
     * every « Renvoyer », find the account already in
     * mass_mail_notified_accounts and notify nobody — the row and the
     * notification are written in ONE transaction, so neither exists
     * without the other.
     *
     * The deep link is the entry (TargetEmailLink) of a target this message
     * concerns that the account may see (`MemberService::canAccess()`) —
     * the first by name; the others are reached from that entry's
     * « concerne aussi ». A target with no member_years row for its year
     * has no entry to link to.
     *
     * $body is the subject that was actually sent, personalised or not, and
     * $personalised says which — a merge whose SUBJECT carried a variable,
     * the only case where this row ends up holding a value that has to stop
     * existing one day. `notifications.body` is written once, at dispatch,
     * and the core retention purge only ever deletes rows somebody has
     * READ; Task\PurgeMergeAudiencesHandler rebuilds this same `$url` for
     * every target of an audience it erases and deletes the matching rows,
     * read or not (issue #292). **Which is exactly why a personalised
     * subject is not written when there is no url**: the url IS the
     * correlation key, and a value nothing can erase is the situation that
     * purge exists to end. The neutral sentence is what the reader gets
     * instead, and they lose nothing they could have opened.
     *
     * @param Target[] $targets the targets this message concerns
     */
    private function notifyOnce(
        TaskContext $context,
        Delivery $delivery,
        array $targets,
        Email $email,
        string $body,
        bool $personalised
    ): void {
        if ($context->notifications === null || $targets === []) {
            return;
        }

        $account = $context->userAccounts->findByEmail($delivery->emailAddress);
        if ($account === null) {
            return;
        }

        $pdo = $context->connection->getPdo();
        $link = $this->linkFor($context, $delivery->emailAddress, $targets);
        $url = $link !== null ? TargetEmailLink::url($link['member_year_id'], $link['target_id']) : null;
        $memberId = $link['member_id'] ?? $targets[0]->memberId;

        $pdo->beginTransaction();
        try {
            if (!(new NotifiedAccountRepository($pdo))->claim($email->id, $delivery->audienceRowId, $account->id)) {
                $pdo->commit();
                return;
            }

            $context->notifications->dispatch('mass_mail.email_received', [
                ['userAccountId' => $account->id, 'memberId' => $memberId],
            ], [
                'title' => 'Nouvel email',
                'body' => $url === null && $personalised ? 'Un email personnalisé vous a été envoyé.' : $body,
                'url' => $url,
            ], $email->createdBy);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * The entry a notification about these targets leads to: among those
     * with a member_years row for their year that the account may see, the
     * first by display name.
     *
     * @param Target[] $targets
     * @return array{member_year_id: int, target_id: int, member_id: int}|null
     */
    private function linkFor(TaskContext $context, string $accountEmail, array $targets): ?array
    {
        $pdo = $context->connection->getPdo();
        $memberYears = new MemberYearRepository($pdo);
        // With the member e-mail repository, canAccess() also answers for an
        // account on a member's confirmed secondary address — the very
        // addresses this module now sends to — and not only the Desk one.
        $memberService = new MemberService(
            $memberYears,
            new \Core\Member\Repository\MemberProfileRepository($context->connection, $context->encryption),
            null,
            new \Core\Member\MemberEmailRepository($pdo, $context->encryption)
        );

        $candidates = [];
        foreach ($targets as $target) {
            if ($target->scoutYearId === null) {
                continue;
            }
            $row = $memberYears->findByMemberAndYear($target->memberId, $target->scoutYearId);
            if ($row === null || !$memberService->canAccess($accountEmail, (int) $row['id'], 'identified')) {
                continue;
            }
            $names = $memberService->findDisplayNamesByMemberIds([$target->memberId], $target->scoutYearId);
            $candidates[] = [
                'name' => mb_strtolower($names[$target->memberId] ?? ''),
                'member_year_id' => (int) $row['id'],
                'target_id' => $target->id,
                'member_id' => $target->memberId,
            ];
        }
        if ($candidates === []) {
            return null;
        }

        usort(
            $candidates,
            static fn(array $a, array $b): int => [$a['name'], $a['target_id']] <=> [$b['name'], $b['target_id']]
        );

        return [
            'member_year_id' => $candidates[0]['member_year_id'],
            'target_id' => $candidates[0]['target_id'],
            'member_id' => $candidates[0]['member_id'],
        ];
    }
}
