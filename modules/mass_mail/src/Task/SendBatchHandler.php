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
use Core\Security\CapabilityToken;
use Modules\MassMail\Repository\AudienceRepository;
use Modules\MassMail\Repository\Email;
use Modules\MassMail\Repository\EmailAttachmentRepository;
use Modules\MassMail\Repository\EmailRepository;
use Modules\MassMail\Repository\Recipient;
use Modules\MassMail\Repository\RecipientRepository;
use Modules\MassMail\Repository\SuppressedAddressRepository;
use Modules\MassMail\Service\MassMailService;
use Modules\MassMail\Service\MergeRenderer;
use Modules\MassMail\Service\RecipientEmailLink;

/**
 * The one and only task type mass_mail ever schedules (module spec —
 * explicitly never one job per recipient). Each run pulls the oldest
 * pending recipients across every email combined (FIFO,
 * Repository\RecipientRepository::findOldestPending()), sends each via
 * Core\Mail\MailService, and — as long as any 'pending' row remains
 * anywhere — reschedules itself.
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

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $pdo = $context->connection->getPdo();

        $recipientRepository = new RecipientRepository($pdo, $context->encryption);
        $emailRepository = new EmailRepository($pdo);
        $attachmentRepository = new EmailAttachmentRepository($pdo);
        $fileRepository = new \Core\File\FileRepository($pdo);
        $audienceRepository = new AudienceRepository($pdo, $context->encryption);
        $mergeRenderer = new MergeRenderer();
        $massMailService = $this->buildMassMailService($context);

        $cadence = $this->cadence($context);
        $batchSize = $cadence['batch_size'];

        $batch = $recipientRepository->findOldestPending($batchSize);

        $sentCount = 0;
        $errorCount = 0;
        $touchedEmailIds = [];
        $senderIdentityBySection = [];

        foreach ($batch as $recipient) {
            $touchedEmailIds[$recipient->emailId] = true;

            $email = $emailRepository->findById($recipient->emailId);
            if ($email === null || $recipient->emailAddress === null) {
                // A frozen recipient row is only ever created with a null
                // address alongside status 'error' (never 'pending') — this
                // branch is defensive, not an expected path.
                $recipientRepository->recordSendFailure($recipient->id, 'Adresse invalide');
                $massMailService->journalRecipientNotSendable(
                    $recipient->emailId,
                    $recipient->id,
                    $recipient->memberId,
                    'Adresse invalide'
                );
                $errorCount++;
                continue;
            }

            // Mail-merge: this recipient's subject/body are rendered from
            // their own audience row's values, right before sending. A row
            // already purged by retention can't be rendered — explicit
            // failure, never a mail with raw {{tokens}} in it.
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
                $mergeRow = $recipient->audienceRowId !== null
                    ? $audienceRepository->findRowById($recipient->audienceRowId)
                    : null;
                if ($mergeRow === null) {
                    $recipientRepository->recordSendFailure($recipient->id, 'Données de publipostage purgées');
                    $massMailService->journalRecipientNotSendable(
                        $email->id,
                        $recipient->id,
                        $recipient->memberId,
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

            // Cached per batch — several recipients typically share the
            // same email/sender section.
            if (!isset($senderIdentityBySection[$email->sectionId])) {
                $senderIdentityBySection[
                    $email->sectionId
                ] = $massMailService->resolveSenderIdentity($email->sectionId);
            }
            $sender = $senderIdentityBySection[$email->sectionId];

            // One-click unsubscribe (module addendum, RFC 8058) — a fresh
            // token generated right before this actual send (never at
            // freeze time in MassMailService::startSending(), which only
            // resolves member_email_id; there'd be nowhere safe to hold a
            // raw token between freezing and sending), same generation/
            // hashing convention as Core\Security\AuthService's magic
            // links. Every send gets the footer link + both headers,
            // regardless of whether this recipient's address maps to the
            // Desk-imported email or a secondary one — Modules\MassMail\
            // Controller\UnsubscribeController resolves the right
            // Core\Member\MemberEmail row from recipient->memberEmailId.
            // The token is 32 bytes of entropy, so a fast hash (SHA-256 +
            // hash_equals on verify) is as safe as bcrypt here and avoids a
            // per-request bcrypt on an anonymous endpoint — a needless
            // CPU-burn primitive an attacker could hammer (audit hardening).
            $rawUnsubscribeToken = CapabilityToken::generate();
            $recipientRepository->setUnsubscribeTokenHash(
                $recipient->id,
                CapabilityToken::hash($rawUnsubscribeToken)
            );
            $unsubscribeUrl = rtrim((string) $context->settings->get('base_url'), '/')
                . '/mass-mail/unsubscribe/' . $recipient->id . '?token=' . $rawUnsubscribeToken;

            $bodyHtml = $baseBodyHtml
                . '<hr><p style="font-size:12px;color:#999;">Vous recevez cet email en tant que membre de l\'unité. '
                . '<a href="' . htmlspecialchars($unsubscribeUrl, ENT_QUOTES) . '">Se désinscrire des emails '
                . 'groupés</a>.</p>';
            $bodyText = strip_tags($baseBodyHtml)
                . "\n\n---\nVous recevez cet email en tant que membre de l'unité.\nSe désinscrire des emails groupés : "
                . $unsubscribeUrl;

            try {
                $context->mailService->send(
                    $recipient->emailAddress,
                    $subject,
                    $bodyHtml,
                    $bodyText,
                    null,
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
                            'List-Unsubscribe' => '<mailto:' . $sender['address'] . '?subject=unsubscribe>',
                        ],
                        // The subject travels with the body or not at
                        // all: on a merge it is rendered from the same
                        // row, so copying the message's own would leak
                        // through the one line every mailbox shows in
                        // its list.
                        $seedSubject
                    )
                );
                $recipientRepository->recordSendSuccess($recipient->id);
                // **The module vouches for its own list addresses**
                // (roadmap IT-05). `Core\Mail\MailService::send()` stamps
                // a bounce receipt by itself, but only for an address the
                // CORE already holds — a confirmed `member_emails` row or
                // a `user_accounts` row — because an address the site was
                // merely handed must not vouch for itself.
                //
                // A custom mailing-list address is neither, and it lives
                // in a table this module owns, which core cannot read
                // (§7.5). It is also not something a visitor typed: a
                // staff member entered it. So the module says so here,
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
                // escape the loop entirely. `rescheduleIfPendingRemain()`
                // never runs then, and nothing else reschedules a failed
                // `send_batch`: every remaining recipient stays `pending`
                // until somebody notices and restarts the mailing by
                // hand. A receipt is not worth a mailing.
                try {
                    (new \Core\Mail\Feedback\Bounce\BounceStateRepository($pdo, $context->encryption))
                        ->recordSend($recipient->emailAddress, new \DateTimeImmutable(), true);
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
                $massMailService->journalRecipientSent($email->id, $recipient->id, $recipient->memberId);
                $sentCount++;
                $this->dispatchEmailReceivedNotification(
                    $context,
                    $recipient,
                    $email,
                    $subject,
                    $email->listType === Email::LIST_TYPE_MAIL_MERGE
                        && $mergeRenderer->containsToken($email->subject)
                );
            } catch (\Core\Mail\SuppressedRecipientException) {
                // Caught ahead of the general case so the tracking page
                // keeps one vocabulary: the freeze writes this same
                // sentence for an address already blocked when the mailing
                // was queued, and this is the same situation noticed a few
                // batches later. The exception's own message is written
                // for the MEMBER — it names their address page — and this
                // column is read by staff.
                $recipientRepository->recordSendFailure(
                    $recipient->id,
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
                $recipientRepository->recordSendFailure(
                    $recipient->id,
                    UserFacingMessage::from($e, "Échec de l'envoi — voir le journal pour le détail technique.")
                );
                // The real transport error still has to reach someone, and
                // the journal is where it belongs. Written through the
                // service so this entry carries the same identifying keys
                // and the same searchable wording as the copies that did
                // leave — a failure nobody can line up against its own
                // mailing is most of the way back to no trace at all.
                $massMailService->journalRecipientSendFailed(
                    $email->id,
                    $recipient->id,
                    $recipient->memberId,
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

        $this->rescheduleIfPendingRemain($context, $recipientRepository);
    }

    /**
     * Notification centre + push (module.json's "mass_mail.email_received"
     * — push default-on, email channel forced off since a redundant
     * "you got an email" email would defeat the point). Only fires when
     * the recipient's plaintext address matches an existing login
     * account — most recipients are scouts with no account of their own,
     * so this is a targeted single-recipient dispatch, not a broadcast
     * (unlike the calendar/gallery modules' "every identified member"
     * notifications, which have no such natural single-recipient
     * resolution). The deep link resolves the member_year row for this
     * recipient's own snapshot scout year, matching MemberEmailController's
     * `/members/{member_year_id}/emails/{recipient_id}` route.
     *
     * $body is the subject that was actually sent, personalised or not,
     * and $personalised says which — a merge whose SUBJECT carried a
     * variable, the only case where this row ends up holding a value that
     * has to stop existing one day.
     *
     * That value used to be scrubbed before reaching here, and the reason
     * was not squeamishness: `notifications.body` is written once, at
     * dispatch, and the core retention purge only ever deletes rows
     * somebody has READ (`NotificationRepository::deleteReadOlderThan()`).
     * A notification nobody opens was kept for good, so « Camp de Kaa »
     * stored there would have outlived the 18-month merge retention this
     * whole flow is built to respect. Encrypted at rest, yes; but the
     * guarantee at stake is erasure, not confidentiality — so the
     * notification said only that an email had arrived.
     *
     * These notifications now have a purge path of their own (issue #292):
     * Task\PurgeMergeAudiencesHandler rebuilds this same `$url` for every
     * recipient of an audience it erases and deletes the matching rows,
     * read or not — the half the core purge cannot do. The value can be
     * written because it now stops existing on schedule.
     *
     * **Which is exactly why a personalised subject is not written when
     * there is no url.** The url IS the correlation key; without one the
     * purge can never find the row again, and a value nothing can erase is
     * the situation this change exists to end. That happens when the
     * recipient's `member_years` row for its own snapshot year is gone by
     * the time the batch reaches it — a Desk re-import or a year
     * transition between queueing and sending — so it is rare, not
     * impossible. The neutral sentence is what the reader gets instead,
     * and they lose nothing they could have opened: there is no link
     * either.
     *
     * The link, when there is one, goes to the member page's detail view,
     * which re-renders the merge at READ time and therefore shows nothing
     * once the audience is gone (Service\MassMailQueryService,
     * ARCHITECTURE.md §8.61).
     */
    private function dispatchEmailReceivedNotification(
        TaskContext $context,
        Recipient $recipient,
        Email $email,
        string $body,
        bool $personalised
    ): void {
        if ($context->notifications === null || $recipient->emailAddress === null) {
            return;
        }
        // An external mail-merge recipient is nobody in the members table
        // — no member page, no deep link, no notification to dispatch.
        if ($recipient->memberId === null || $recipient->scoutYearId === null) {
            return;
        }

        $account = $context->userAccounts->findByEmail($recipient->emailAddress);
        if ($account === null) {
            return;
        }

        $pdo = $context->connection->getPdo();
        $memberYear = (new MemberYearRepository($pdo))->findByMemberAndYear(
            $recipient->memberId,
            $recipient->scoutYearId
        );
        $url = $memberYear !== null
            ? RecipientEmailLink::url((int) $memberYear['id'], $recipient->id)
            : null;

        $context->notifications->dispatch('mass_mail.email_received', [
            ['userAccountId' => $account->id, 'memberId' => $recipient->memberId],
        ], [
            'title' => 'Nouvel email',
            'body' => $url === null && $personalised ? 'Un email personnalisé vous a été envoyé.' : $body,
            'url' => $url,
        ], $email->createdBy);
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

    private function rescheduleIfPendingRemain(TaskContext $context, RecipientRepository $recipientRepository): void
    {
        if ($recipientRepository->findOldestPending(1) === []) {
            return;
        }

        // Re-read rather than carried from the top of the run: the lane
        // may have fallen back to the next provider mid-batch — a quota
        // spent, a relay that stopped answering — and D7 says the lane
        // adopts THAT provider's cadence, not the one it started on.
        $intervalMinutes = $this->cadence($context)['interval_minutes'];

        $schedulerService = new SchedulerService(
            new \Core\Scheduler\SchedulerRepository($context->connection->getPdo())
        );
        $schedulerService->scheduleAfter('mass_mail', 'send_batch', $intervalMinutes * 60);
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
            new RecipientRepository($pdo, $context->encryption),
            new EmailAttachmentRepository($pdo),
            new \Core\File\FileRepository($pdo),
            new \Modules\MassMail\Service\MailingListService(
                new \Modules\MassMail\Repository\MailingListRepository($pdo),
                new \Modules\MassMail\Repository\MemberResolutionRepository($pdo, $context->encryption),
                $sectionService,
                new \Core\Import\FunctionRepository($pdo),
                // No BadgeService here: this instance only ever RESOLVES a
                // list, which reads mass_mail_list_badges straight through
                // the repository. The service needs one solely to offer
                // the badge vocabulary to the criteria form, and there is
                // no form in a scheduled task. The address repository IS
                // passed: it is half of what a custom list resolves to.
                null,
                new \Modules\MassMail\Repository\ListAddressRepository($pdo, $context->encryption)
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
