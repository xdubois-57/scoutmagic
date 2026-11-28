<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\MassMail\Task;

use Core\Exception\UserFacingMessage;
use Core\Import\MemberYearRepository;
use Core\Mail\MailException;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Core\Member\MemberService;
use Modules\MassMail\Repository\AudienceRepository;
use Modules\MassMail\Repository\Delivery;
use Modules\MassMail\Repository\DeliveryRepository;
use Modules\MassMail\Repository\Email;
use Modules\MassMail\Repository\EmailAttachmentRepository;
use Modules\MassMail\Repository\EmailRepository;
use Modules\MassMail\Repository\NotifiedAccountRepository;
use Modules\MassMail\Repository\SuppressedAddressRepository;
use Modules\MassMail\Repository\Target;
use Modules\MassMail\Repository\TargetRepository;
use Modules\MassMail\Service\MassMailService;
use Modules\MassMail\Service\MergeRenderer;
use Modules\MassMail\Service\TargetEmailLink;

/**
 * The one and only task type mass_mail ever schedules (module spec —
 * explicitly never one job per message). Each run pulls the oldest pending
 * MESSAGES across every email combined (FIFO,
 * Repository\DeliveryRepository::findOldestPending()) — one address each
 * (issue #999) — sends each via Core\Mail\MailService exactly as before,
 * and — as long as any 'pending' message remains anywhere — reschedules
 * itself. An email is 'sent' once none of its messages is pending.
 *
 * **How big a lot is, and how long to wait after it, is no longer this
 * module's to decide.** Those were two of its own settings; they are now
 * read off whichever provider the mailing lane would actually use
 * (`Core\Mail\Transport\BulkCadence`, ARCHITECTURE.md §8.106). The
 * reason is D6: a cadence describes what a RELAY accepts, so it belongs
 * to the relay — and when the lane falls back to the next provider it
 * adopts that one's cadence, which a module-level setting could never
 * express. Nothing was migrated (D14): the old values were a global
 * number nobody had tuned, and a carried-over number nobody chose is
 * worse than a default whose reasoning is written down.
 *
 * Every copy is sent under `MailPurpose::Bulk`, which is what puts it on
 * the mailing lane in the first place. It is stated here and nowhere
 * else: a transport cannot tell a mailing from a notification by looking
 * at the message.
 */
class SendBatchHandler implements TaskHandlerInterface
{

    public const MODULE_ID = 'mass_mail';
    public const TASK_KEY = 'send_batch';

    /**
     * The chain's fixed reference (issue #1443). There is one queue for the
     * whole site, so one chain, and a reference is what lets its own re-arm
     * go through the guarded `rearmAfter()` — whose guard sees only a
     * QUEUED row, so this run's own `processing` row does not stop it —
     * while every other caller asks the task-wide
     * `scheduleAfterUnlessLive()`, which sees the running batch too.
     *
     * Rows queued before the reference existed carry none. They are not
     * lost: each runs once, its re-arm finds the referenced row already
     * queued and stands down, and the chain is back to one row.
     */
    public const REFERENCE = 'queue';

    /**
     * How many batches in a row may fail before the chain stops re-arming
     * itself. A batch that fails for a reason that does not go away by
     * itself — a table missing, a key that no longer decrypts — would
     * otherwise fail once per interval for ever, a journal line each time
     * and the mailing still shown « Envoi en cours ». Five lets a passing
     * outage (a restarted database, a relay down for a quarter of an hour)
     * heal on its own.
     */
    public const MAX_CONSECUTIVE_FAILURES = 5;

    /**
     * How long a chain stopped by that cap is left alone before the
     * composition root starts it again ({@see bootstrap()}). Meanwhile the
     * mailing is on the attention page (Service\MassMailAttentionProvider),
     * which is how a person hears about it; afterwards one more series of
     * attempts is made, so a cause somebody fixed is picked up without
     * anybody having to find a button.
     */
    public const HALT_HOURS = 1;

    /** The payload key carrying how many batches in a row have failed. */
    private const FAILURES_KEY = 'consecutive_failures';

    /**
     * One batch, then the chain's next link — **in a `finally`**, so that
     * whatever the batch threw, the remaining recipients still have a
     * successor to send them (issue #1443). Only `MailException` is caught
     * inside the loop; anything else — a `\PDOException`, a
     * `DecryptionException` — used to escape before the re-arm and leave
     * every remaining message `pending` with nothing scheduled to send it.
     *
     * The exception is still rethrown: the scheduler marks the run failed
     * and journals it, and that failed row is also what tells
     * {@see bootstrap()} that a chain stopped on its cap.
     *
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $failure = null;
        try {
            $this->sendBatch($context);
        } catch (\Throwable $e) {
            $failure = $e;
            throw $e;
        } finally {
            if ($failure === null) {
                $this->rearmAfterSuccess($context);
            } else {
                $this->afterFailedBatch($payload, $context, $failure);
            }
        }
    }

    /**
     * Queue the chain again when it stopped while messages still wait —
     * from the composition roots (`public/scheduler-bootstrap.php`), on
     * every request and every cron pass (issue #1443). Returns whether it
     * queued a run.
     *
     * The chain normally re-arms itself, so this only ever acts when that
     * did not happen: a run whose re-arm failed with the batch (the same
     * database refusing both), a row cancelled by hand, a queue left by a
     * version that did not re-arm a failed batch at all. A process killed
     * mid-batch is not one of them — its row stays `processing`, which is
     * alive, and the scheduler hands it back after
     * `SchedulerRepository::RECLAIM_AFTER_HOURS`.
     *
     * Ordered for the page view, which pays for it: one indexed lookup on
     * the messages, which answers « nothing waits » on nearly every
     * request, before anything is asked of the scheduler. Queued through
     * `scheduleAfterUnlessLive()`, which sees a `processing` row and is
     * atomic, so a page view landing during a batch never starts a second
     * chain.
     *
     * A chain stopped by {@see MAX_CONSECUTIVE_FAILURES} is left alone for
     * {@see HALT_HOURS}; restarting it at once would make the cap a delay
     * of one minute.
     */
    public static function bootstrap(
        SchedulerService $scheduler,
        DeliveryRepository $deliveries,
        \Core\Journal\JournalService $journal,
        ?\DateTimeImmutable $now = null
    ): bool {
        if (!$deliveries->hasAnyPending() || $scheduler->hasLiveForTask(self::MODULE_ID, self::TASK_KEY)) {
            return false;
        }

        $now ??= new \DateTimeImmutable();
        if (self::isHaltedOnItsCap($scheduler, $now)) {
            return false;
        }

        $payload = self::failuresCarriedOver($scheduler);
        $queued = $scheduler->scheduleAfterUnlessLive(self::MODULE_ID, self::TASK_KEY, 0, $payload, self::REFERENCE);
        if ($queued === null) {
            return false;
        }

        $journal->log(
            self::MODULE_ID,
            'batch_chain_recovered',
            'warning',
            'Envoi groupé relancé : des messages attendaient sans qu\'aucun lot ne soit planifié',
            ['pending' => $deliveries->countAllPending()],
            null
        );

        return true;
    }

    /**
     * Whether the chain's latest run is the one that hit the cap, less
     * than {@see HALT_HOURS} ago. Read off the scheduler's own row — its
     * status, the payload this handler gave it, and when it failed
     * (`executed_at`, which SchedulerRepository::markFailed() writes; not
     * `run_at`, which says when it was due, #1557 review) — so the cap
     * needs no state of its own. A run that succeeded is a `done` row,
     * never read as a capped failure, whatever its payload carried.
     */
    public static function isHaltedOnItsCap(SchedulerService $scheduler, \DateTimeImmutable $now): bool
    {
        $latest = self::latestFailedRun($scheduler);
        if ($latest === null || self::failuresOf($latest) < self::MAX_CONSECUTIVE_FAILURES) {
            return false;
        }

        $endedAt = $latest['executed_at'] ?? $latest['run_at'] ?? null;
        $failedAt = \Core\Service\DateInput::fromStorage(is_string($endedAt) ? $endedAt : null);

        return $failedAt !== null && $failedAt > $now->sub(new \DateInterval('PT' . self::HALT_HOURS . 'H'));
    }

    /**
     * The payload {@see bootstrap()} queues the chain with: the count of
     * failures in a row the latest run brought it to, when that run failed
     * below the cap (#1557 review). A failed batch whose own re-arm could
     * not be written would otherwise start again from zero each time, and
     * the cap would never be reached. A run that succeeded, or a series
     * whose halt has run out, starts afresh.
     *
     * @return array<string, int>
     */
    private static function failuresCarriedOver(SchedulerService $scheduler): array
    {
        $latest = self::latestFailedRun($scheduler);
        if ($latest === null) {
            return [];
        }
        $failures = self::failuresOf($latest);

        return $failures < self::MAX_CONSECUTIVE_FAILURES ? [self::FAILURES_KEY => $failures] : [];
    }

    /**
     * The chain's latest row when it is a failed run, else null.
     *
     * @return array<string, mixed>|null
     */
    private static function latestFailedRun(SchedulerService $scheduler): ?array
    {
        $latest = $scheduler->findAllForTask(self::MODULE_ID, self::TASK_KEY, 1)[0] ?? null;

        return $latest !== null && ($latest['status'] ?? null) === 'failed' ? $latest : null;
    }

    /**
     * How many failures in a row a failed run brought the chain to: the
     * count its payload carried, plus itself.
     *
     * @param array<string, mixed> $row
     */
    private static function failuresOf(array $row): int
    {
        $payload = is_string($row['payload'] ?? null) ? json_decode($row['payload'], true) : null;

        return self::consecutiveFailures(is_array($payload) ? $payload : []) + 1;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function consecutiveFailures(array $payload): int
    {
        $value = $payload[self::FAILURES_KEY] ?? 0;

        return is_int($value) ? max(0, $value) : 0;
    }

    /**
     * The next link after a batch that went through — **never throwing**
     * (#1557 review). A successor that could not be written must not turn
     * this run into a failure: its row would then read as the last
     * failure of a capped series, and {@see bootstrap()} would hold the
     * chain for {@see HALT_HOURS} although the batch succeeded. Left as a
     * `done` row, the chain is simply queued again by {@see bootstrap()}
     * on the next request or cron pass, from a count of zero.
     */
    private function rearmAfterSuccess(TaskContext $context): void
    {
        try {
            $this->rearmIfPendingRemain($context, []);
        } catch (\Throwable) {
            // bootstrap() picks the chain up.
        }
    }

    /**
     * After a batch that threw: journal it, and re-arm unless this was the
     * last failure the cap allows.
     *
     * **Nothing here may throw.** It runs in the `finally` while the
     * batch's own exception is on its way out, and the usual reason for
     * that exception — the database — is the reason this would fail too.
     * A re-arm that could not be written is not lost: {@see bootstrap()}
     * queues the chain again on the next request or cron pass.
     *
     * @param array<string, mixed> $payload
     */
    private function afterFailedBatch(array $payload, TaskContext $context, \Throwable $failure): void
    {
        $failures = self::consecutiveFailures($payload) + 1;
        $pending = null;
        try {
            $deliveries = new DeliveryRepository($context->connection->getPdo(), $context->encryption);
            $pending = $deliveries->countAllPending();
            if ($pending > 0 && $failures < self::MAX_CONSECUTIVE_FAILURES) {
                $this->rearmIfPendingRemain($context, [self::FAILURES_KEY => $failures]);
            }
        } catch (\Throwable) {
            // See above: bootstrap() picks the chain up.
        }

        $halted = $pending !== null && $pending > 0 && $failures >= self::MAX_CONSECUTIVE_FAILURES;
        try {
            $context->journal->log(
                self::MODULE_ID,
                'batch_failed',
                'error',
                $halted
                    ? 'Lot d\'emails de masse en échec ' . $failures . ' fois de suite : envoi suspendu pendant '
                        . self::HALT_HOURS . ' h'
                    : 'Lot d\'emails de masse en échec : le lot suivant reprendra les messages restants',
                [
                    'task_id' => isset($payload['scheduled_action_id']) && is_int($payload['scheduled_action_id'])
                        ? $payload['scheduled_action_id']
                        : null,
                    'pending' => $pending,
                    'error_class' => $failure::class,
                    'consecutive_failures' => $failures,
                ],
                null
            );
        } catch (\Throwable) {
            // Nobody left to tell: the journal goes through the same
            // database that may just have refused.
        }
    }

    private function sendBatch(TaskContext $context): void
    {
        $pdo = $context->connection->getPdo();

        $deliveryRepository = new DeliveryRepository($pdo, $context->encryption);
        $targetRepository = new TargetRepository($pdo);
        $emailRepository = new EmailRepository($pdo);
        $attachmentRepository = new EmailAttachmentRepository($pdo);
        $fileRepository = new \Core\File\FileRepository($pdo);
        $audienceRepository = new AudienceRepository($pdo, $context->encryption);
        $mergeRenderer = new MergeRenderer();
        $massMailService = $this->buildMassMailService($context);
        $suppressedAddresses = new SuppressedAddressRepository($pdo);

        $cadence = $this->cadence($context);
        $batchSize = $cadence['batch_size'];

        $batch = $deliveryRepository->findOldestPending($batchSize);

        $sentCount = 0;
        $errorCount = 0;
        $touchedEmailIds = [];
        $senderIdentityBySection = [];

        foreach ($batch as $delivery) {
            $touchedEmailIds[$delivery->emailId] = true;
            // The members this message concerns: what its journal lines
            // name, and who its notification is about. None for a
            // contact's message or a file row without a Tiers.
            $targets = $targetRepository->findByDelivery($delivery->id);
            $memberIds = array_values(array_unique(array_map(static fn(Target $t): int => $t->memberId, $targets)));

            $email = $emailRepository->findById($delivery->emailId);
            if ($email === null) {
                // Defensive: the email cascades its messages away.
                $deliveryRepository->recordSendFailure($delivery->id, 'Email introuvable');
                $massMailService->journalDeliveryNotSendable(
                    $delivery->emailId,
                    $delivery->id,
                    $memberIds,
                    'Email introuvable'
                );
                $errorCount++;
                continue;
            }

            // An email imported from a former site is never sent from here
            // (D11): its import writes no 'pending' message and « Renvoyer »
            // is refused, so a pending one can only be a fault. Failing it
            // explicitly keeps it from leaving AND from blocking the queue.
            if ($email->isImported()) {
                $deliveryRepository->recordSendFailure($delivery->id, 'Courriel importé, jamais envoyé');
                $massMailService->journalDeliveryNotSendable(
                    $email->id,
                    $delivery->id,
                    $memberIds,
                    'Courriel importé, jamais envoyé'
                );
                $errorCount++;
                continue;
            }

            // **Unsubscribed since the freeze** (issue #1437). The freeze
            // reads the suppression list once, and a mailing drains over a
            // cadence that spans hours: a recipient who clicked « Se
            // désinscrire » in an earlier mailing — or in an earlier batch
            // of this one — kept receiving every message already queued
            // for them, and « Renvoyer » re-queued a message to them weeks
            // later. Read again for THIS message, right before it would
            // leave, with the reason the freeze gives. A reactivated
            // address (#1032) is off the list and goes out as usual.
            if ($suppressedAddresses->isSuppressed($delivery->emailAddress)) {
                $deliveryRepository->recordSendFailure($delivery->id, MassMailService::REASON_UNSUBSCRIBED);
                $massMailService->journalDeliverySkippedUnsubscribed($email->id, $delivery->id);
                $errorCount++;
                continue;
            }

            // Mail-merge: this message's subject/body are rendered from its
            // own file row's values, right before sending. A row already
            // purged by retention can't be rendered — explicit failure,
            // never a mail with raw {{tokens}} in it.
            $subject = $email->subject;
            $baseBodyHtml = $email->bodyHtml;
            // **What a seed copy may carry, decided here rather than
            // after the merge** — see the `bulkCopy` argument below. On an
            // ordinary mailing it is the same text; on a merge it is
            // deliberately NOT, and that is the whole reason for the two
            // extra variables.
            $seedSubject = $email->subject;
            $seedBodyHtml = $email->bodyHtml;
            if ($email->listType === Email::LIST_TYPE_MAIL_MERGE) {
                $mergeRow = $delivery->audienceRowId !== null
                    ? $audienceRepository->findRowById($delivery->audienceRowId)
                    : null;
                if ($mergeRow === null) {
                    $deliveryRepository->recordSendFailure($delivery->id, 'Données de publipostage purgées');
                    $massMailService->journalDeliveryNotSendable(
                        $email->id,
                        $delivery->id,
                        $memberIds,
                        'Données de publipostage purgées'
                    );
                    $errorCount++;
                    continue;
                }
                $subject = $mergeRenderer->renderText($email->subject, $mergeRow->data);
                $baseBodyHtml = $mergeRenderer->renderHtml($email->bodyHtml, $mergeRow->data);

                // **The seed copy is rendered too, but from nobody's
                // row.** Each column is substituted by its own HEADER —
                // « Bonjour Prénom, » — so the copy has the shape and
                // the length of the message that went out, and none of
                // its values. A column header describes a field; a cell
                // describes a person.
                //
                // Rendered rather than left as `{{Prénom}}`, because the
                // raw template is not the thing being measured: curly
                // braces in a subject line are exactly the sort of
                // oddity a filter weighs, and a copy scored on them
                // would make every figure on the results page quietly
                // wrong — the same reason the stamp rides in a header
                // rather than in the subject.
                $headers = array_keys($mergeRow->data);
                $asColumnNames = array_combine($headers, $headers);
                $seedSubject = $mergeRenderer->renderText($email->subject, $asColumnNames);
                $seedBodyHtml = $mergeRenderer->renderHtml($email->bodyHtml, $asColumnNames);
            }

            $attachments = [];
            foreach ($attachmentRepository->findByEmailId($email->id) as $attachment) {
                $file = $fileRepository->findById($attachment->fileId);
                if ($file !== null) {
                    $attachments[] = [
                        'path' => $context->storagePath . '/' . $file->relativePath,
                        'name' => $file->originalName
                    ];
                }
            }

            // Cached per batch — several messages typically share the
            // same email/sender section.
            if (!isset($senderIdentityBySection[$email->sectionId])) {
                $senderIdentityBySection[
                    $email->sectionId
                ] = $massMailService->resolveSenderIdentity($email->sectionId);
            }
            $sender = $senderIdentityBySection[$email->sectionId];

            // One-click unsubscribe (module addendum, RFC 8058) — the token
            // of THIS message, the same on every send of it, so the link in
            // a first copy still works after « Renvoyer » (issue #1514;
            // derived, never stored: DeliveryRepository::
            // unsubscribeTokenFor()). Every message gets the footer link +
            // both headers, whatever its address is — Modules\MassMail\
            // Controller\UnsubscribeController resolves the right
            // Core\Member\MemberEmail row from delivery->memberEmailId.
            $rawUnsubscribeToken = $deliveryRepository->unsubscribeTokenFor($delivery);
            $unsubscribeUrl = rtrim((string) $context->settings->get('base_url'), '/')
                . '/mass-mail/unsubscribe/' . $delivery->id . '?token=' . $rawUnsubscribeToken;

            $bodyHtml = $baseBodyHtml
                . '<hr><p style="font-size:12px;color:#999;">Vous recevez cet email en tant que membre de l\'unité. '
                . '<a href="' . htmlspecialchars($unsubscribeUrl, ENT_QUOTES) . '">Se désinscrire des emails '
                . 'groupés</a>.</p>';
            $bodyText = strip_tags($baseBodyHtml)
                . "\n\n---\nVous recevez cet email en tant que membre de l'unité.\nSe désinscrire des emails groupés : "
                . $unsubscribeUrl;

            try {
                $context->mailService->send(
                    $delivery->emailAddress,
                    $subject,
                    $bodyHtml,
                    $bodyText,
                    // **`Reply-To:` only when the `From:` had to be
                    // substituted** (issue #418): a section address the site
                    // cannot sign for is moved here so « Répondre » still
                    // reaches the section, while the `From:` becomes the
                    // site's own address, which DKIM and SPF do align with.
                    // Null for a section whose address the site CAN sign
                    // for — the `From:` is already the section there.
                    $sender['reply_to'],
                    $attachments,
                    $sender['address'],
                    $sender['name'],
                    [
                        'List-Unsubscribe' => '<' . $unsubscribeUrl . '>',
                        'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
                    ],
                    \Core\Mail\MailPurpose::Bulk,
                    // **The mailing vouches, and the docblock of this
                    // parameter always said it should** — « une
                    // notification à un membre, un document envoyé à la
                    // personne qu'il concerne, un publipostage ». The
                    // other two passed `true`; this one, the mailing
                    // itself, did not, so the live suppression gate never
                    // fired for mass mail at all.
                    //
                    // The freeze filters blocked addresses once, when the
                    // mailing is queued. But a batch drains over a cadence
                    // that spans hours, and an address that gets blocked
                    // DURING that run — typically by bouncing an earlier
                    // batch of this very mailing — kept receiving every
                    // later batch, because the only remaining check was
                    // that stale snapshot. Precisely the addresses the
                    // site had just decided to stop writing to.
                    vouchesForRecipient: true,
                    // **What this module calls its own run** (roadmap
                    // IT-07). The transport is handed one message per
                    // recipient and cannot see a campaign, so without this
                    // a mailing of five hundred would emit five hundred
                    // sets of seed copies.
                    //
                    // That is the whole of what this module says about the
                    // matter: it names its run and knows nothing about
                    // seed boxes, which is why any later sender of the
                    // same shape gets the measurement by passing its own
                    // reference and no code at all.
                    //
                    // Prefixed because the column is shared: « 42 » from
                    // here and « 42 » from a future sender must not be one
                    // run.
                    bulkRunReference: 'mass_mail:' . $email->id,
                    // **What a seed copy may carry, and it is not this
                    // message** (roadmap IT-07).
                    //
                    // The body above is personalised twice over. It ends
                    // with a one-click unsubscribe link holding a
                    // capability token minted for THIS recipient a few
                    // lines up — copying it to a seed box, an ordinary
                    // mailbox at Gmail or Outlook, would put a working
                    // link to act on one real member's behalf into a
                    // third party's hands, on every campaign. And on a
                    // merge, `$baseBodyHtml` and `$subject` were
                    // rendered from THIS recipient's audience row: their
                    // name, their amount, whatever the unit's
                    // spreadsheet holds.
                    //
                    // **`$baseBodyHtml` was the first version's answer to
                    // the first problem, and it is not an answer to the
                    // second.** Its name says « base » only relative to
                    // the unsubscribe link appended after it. A copy is
                    // emitted once per run, so the first recipient
                    // processed was the member whose merged data went to
                    // every seed box.
                    //
                    // So the copy carries `$seedSubject`/`$seedBodyHtml`:
                    // the campaign with its variables filled by the names
                    // of their own columns. Same shape, same length,
                    // nobody's values — and identical for every box,
                    // which is also what makes it a measurement rather
                    // than a sample.
                    bulkCopy: new \Core\Mail\Feedback\Seed\SeedCopyContent(
                        $seedBodyHtml,
                        strip_tags($seedBodyHtml),
                        [
                            // **A `mailto:`, not the one-click URL.** The
                            // header has to be there — the large
                            // providers weigh one-click support as a
                            // bulk-sender signal, so a copy without it is
                            // likelier to be filed as spam than the
                            // campaign it measures, which would bias the
                            // reading in exactly the direction that
                            // triggers a reroute. But the campaign's own
                            // URL is a member's capability and may not
                            // travel, so the copy gets the unit's address
                            // instead: same signal, nothing to act with.
                            //
                            // No `List-Unsubscribe-Post`: that header
                            // promises one-click, and a `mailto:` is not
                            // one. Claiming it would be a second lie to a
                            // provider that checks.
                            // `contact` and not `address`: the latter is
                            // NULL whenever the site sends under its own
                            // address — a section with no e-mail, and since
                            // issue #418 a section whose address the site
                            // cannot sign for — which would have written
                            // `<mailto:?subject=unsubscribe>`. A malformed
                            // header is exactly the bulk-sender signal this
                            // copy exists to measure, so it would have
                            // biased the reading it is here to take.
                            'List-Unsubscribe' => '<mailto:' . $sender['contact'] . '?subject=unsubscribe>',
                        ],
                        // The subject travels with the body or not at
                        // all: on a merge it is rendered from the same
                        // row, so copying the message's own would leak
                        // through the one line every mailbox shows in
                        // its list.
                        $seedSubject
                    )
                );
                $deliveryRepository->recordSendSuccess($delivery->id);
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
                // `recordSendSuccess()` is committed; the only handler
                // around this loop catches `MailException`, so anything
                // else — a `DecryptionException` on a row encrypted under
                // a rotated key, a `\ValueError` from a stored category
                // that is no longer a case, a `\PDOException` — would
                // end the batch here, with the rest of its recipients
                // unsent until the next one. handle() re-arms the chain
                // in its `finally` whatever escapes (issue #1443), so the
                // mailing is delayed rather than stranded; still, a
                // receipt is not worth even that.
                try {
                    (new \Core\Mail\Feedback\Bounce\BounceStateRepository($pdo, $context->encryption))
                        ->recordSend($delivery->emailAddress, new \DateTimeImmutable(), true);
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
                $sentCount++;
                // Best-effort, like the receipt above: the copy has left
                // and its row says so. A notification that throws — a
                // \PDOException from the claim, an error from dispatch(),
                // or a MailException, which the handler below would turn
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
                        $email->listType === Email::LIST_TYPE_MAIL_MERGE
                            && $mergeRenderer->containsToken($email->subject)
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
            } catch (\Core\Mail\SuppressedRecipientException) {
                // Caught ahead of the general case so the tracking page
                // keeps one vocabulary: the freeze writes this same
                // sentence for an address already blocked when the mailing
                // was queued, and this is the same situation noticed a few
                // batches later. The exception's own message is written
                // for the MEMBER — it names their address page — and this
                // column is read by staff.
                $deliveryRepository->recordSendFailure(
                    $delivery->id,
                    'Adresse suspendue après des refus répétés'
                );
                $errorCount++;
                continue;
            } catch (MailException $e) {
                // $e->getMessage() is a transport-level error (SMTP
                // response, connection failure) built from PHPMailer's
                // ErrorInfo — raw English, and views/tracking.html.twig
                // renders this column verbatim to whoever opens the
                // tracking page. The sanitising happens HERE, at the write:
                // the value is stored now and rendered much later, with no
                // catch block in between to route it through.
                $deliveryRepository->recordSendFailure(
                    $delivery->id,
                    UserFacingMessage::from($e, "Échec de l'envoi — voir le journal pour le détail technique.")
                );
                // The real transport error still has to reach someone, and
                // the journal is where it belongs. Written through the
                // service so this entry carries the same identifying keys
                // and the same searchable wording as the copies that did
                // leave — a failure nobody can line up against its own
                // mailing is most of the way back to no trace at all.
                $massMailService->journalDeliverySendFailed(
                    $email->id,
                    $delivery->id,
                    $memberIds,
                    ['mail_error' => $e->getMessage()]
                );
                $errorCount++;
            }
        }

        foreach (array_keys($touchedEmailIds) as $emailId) {
            $massMailService->checkAndMarkSentIfComplete($emailId);
        }

        if ($batch !== []) {
            $context->journal->log(
                'mass_mail',
                'batch_sent',
                'info',
                'Lot d\'emails de masse envoyé',
                ['sent' => $sentCount, 'errors' => $errorCount, 'batch_size' => $batchSize],
                null
            );
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

    /**
     * How fast the mailing lane may go right now — read off the provider
     * it would actually use (D6, D7).
     *
     * It comes from the TaskContext rather than being rebuilt here,
     * because resolving a provider means reading `secrets.enc` and a task
     * handler is auto-resolved with `new $class()`: the shared scheduler
     * bootstrap is the one place that can compose it, exactly as it
     * composes `MailService`. A context without one — a narrow test
     * double — falls back to the local send's prudent default rather
     * than stopping the mailing.
     *
     * @return array{batch_size: int, interval_minutes: int}
     */
    private function cadence(TaskContext $context): array
    {
        return $context->bulkCadence?->current() ?? [
            'batch_size' => \Core\Mail\Transport\MailProviderDirectory::DEFAULT_LOCAL_BATCH_SIZE,
            'interval_minutes' => \Core\Mail\Transport\MailProviderDirectory::DEFAULT_LOCAL_BATCH_INTERVAL,
        ];
    }

    /**
     * The chain's next link, when anything is left to send.
     *
     * `rearmAfter()` and not `scheduleAfter()` (issue #1443): the guard
     * finds a row already queued — by « Lancer l'envoi » or « Renvoyer »
     * while this batch ran, or by a second copy of the chain — and stands
     * down instead of keeping two chains alive, each sending a full batch
     * per interval. This run's own row is `processing`, which that guard
     * deliberately does not see.
     *
     * @param array<string, mixed> $payload
     */
    private function rearmIfPendingRemain(TaskContext $context, array $payload): void
    {
        $deliveryRepository = new DeliveryRepository($context->connection->getPdo(), $context->encryption);
        if (!$deliveryRepository->hasAnyPending()) {
            return;
        }

        // Re-read rather than carried from the top of the run: the lane
        // may have fallen back to the next provider mid-batch — a quota
        // spent, a relay that stopped answering — and D7 says the lane
        // adopts THAT provider's cadence, not the one it started on.
        $intervalMinutes = $this->cadence($context)['interval_minutes'];

        SchedulerService::forPdo($context->connection->getPdo())->rearmAfter(
            self::MODULE_ID,
            self::TASK_KEY,
            self::REFERENCE,
            $intervalMinutes * 60,
            $payload
        );
    }

    private function buildMassMailService(TaskContext $context): MassMailService
    {
        $pdo = $context->connection->getPdo();
        $sectionService = new \Core\Member\SectionService(
            new \Core\Member\Repository\SectionRepository($context->connection),
            new \Core\Member\Repository\MemberProfileRepository(
                $context->connection,
                $context->encryption,
                new \Core\Badge\MemberBadgeRepository($pdo)
            )
        );

        $memberService = new \Core\Member\MemberService(
            new \Core\Import\MemberYearRepository($pdo),
            new \Core\Member\Repository\MemberProfileRepository($context->connection, $context->encryption)
        );
        $scoutYearService = new \Core\Config\ScoutYearService($pdo);

        // No module namespace needed — Core\Member\MemberEmailService only
        // ever renders core's own email/member_email_confirmation.html.twig
        // from this reconstruction path (via resolveValidAddressesForMassMail()
        // → findOrCreateDeskOverride(), which never actually sends mail —
        // $sectionService/$memberService/$scoutYearService are only here
        // to satisfy the constructor, unsubscribe() is never reached from
        // this particular call path). Same TwigFactory::create()
        // task-context pattern as Modules\News\Task\SendResponseDigestHandler.
        $twig = \Core\View\TwigFactory::create(dirname(__DIR__, 4) . '/core/View/templates');
        // Core templates only, like the notification-e-mail handler: the
        // unsubscribe e-mails this service sends are core's, so there is
        // no module manifest to aggregate on this path. A customisation of
        // them is still honoured — that lives in the database.
        $emailTemplateRenderer = new \Core\Mail\Template\EmailTemplateRenderer(
            $twig,
            new \Core\Mail\Template\EmailTemplateRegistry(),
            new \Core\Mail\Template\EmailTemplateOverrideRepository($pdo),
            $context->journal
        );
        $memberEmailService = new \Core\Member\MemberEmailService(
            new \Core\Member\MemberEmailRepository($pdo, $context->encryption),
            $context->mailService,
            $emailTemplateRenderer,
            $context->journal,
            $sectionService,
            $memberService,
            $scoutYearService,
            (string) $context->settings->get('base_url'),
            (string) ($context->settings->get('site_name') ?: 'Unité scoute'),
            new \Core\Member\EmailDomainValidator(),
            // **Wired here as well as in `public/index.php`**, so the two
            // composition roots of this class answer « cette adresse
            // est-elle suspendue » the same way. Nothing on THIS path
            // resolves addresses today — the freeze happens in the web
            // request — but a null here would be a silent « jamais
            // rebondi » the day something does, and that is exactly the
            // shape of hole this iteration kept finding.
            new \Core\Mail\Feedback\Bounce\BounceService(
                new \Core\Mail\Feedback\Bounce\BounceStateRepository($pdo, $context->encryption)
            )
        );

        return new MassMailService(
            new EmailRepository($pdo),
            new DeliveryRepository($pdo, $context->encryption),
            new TargetRepository($pdo),
            new EmailAttachmentRepository($pdo),
            new \Core\File\FileRepository($pdo),
            new \Modules\MassMail\Service\MailingListService(
                new \Modules\MassMail\Repository\MailingListRepository($pdo),
                new \Modules\MassMail\Repository\MemberResolutionRepository($pdo, $context->encryption),
                $sectionService,
                new \Core\Import\FunctionRepository($pdo),
                // No BadgeService here: this instance only ever RESOLVES a
                // list, which reads mass_mail_block_badges straight through
                // the repository. The service needs one solely to offer
                // the badge vocabulary to the criteria form, and there is
                // no form in a scheduled task. The block and contact
                // repositories ARE passed: they are what a custom list
                // resolves to.
                null,
                new \Modules\MassMail\Repository\ListBlockRepository($pdo),
                new \Modules\MassMail\Repository\ContactRepository($pdo, $context->encryption)
            ),
            $memberService,
            $memberEmailService,
            $sectionService,
            $context->mailService,
            new SchedulerService(new \Core\Scheduler\SchedulerRepository($pdo)),
            $context->journal,
            new \Core\Security\HtmlSanitizer(),
            $scoutYearService,
            new \Core\Import\ImportJournalRepository($pdo),
            $context->storagePath,
            new AudienceRepository($pdo, $context->encryption),
            new \Modules\MassMail\Repository\MemberResolutionRepository($pdo, $context->encryption),
            new SuppressedAddressRepository($pdo),
            new MergeRenderer(),
            // Bounce state (roadmap IT-05): a blocked address must not be
            // written to through a custom list either.
            new \Core\Mail\Feedback\Bounce\BounceService(
                new \Core\Mail\Feedback\Bounce\BounceStateRepository($pdo, $context->encryption)
            )
        );
    }
}
