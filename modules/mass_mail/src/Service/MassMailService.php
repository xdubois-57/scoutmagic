<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\MassMail\Service;

use Core\Config\ScoutYearService;
use Core\File\AttachedFileRemover;
use Core\File\FileRepository;
use Core\Import\ImportJournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailException;
use Core\Mail\MailIdentity;
use Core\Mail\MailService;
use Core\Member\MemberEmailService;
use Core\Member\MemberService;
use Core\Member\SectionService;
use Core\Scheduler\SchedulerService;
use Core\Security\HtmlSanitizer;
use Modules\MassMail\Api\MassMailException;
use Modules\MassMail\Repository\AudienceRepository;
use Modules\MassMail\Repository\AudienceRow;
use Modules\MassMail\Repository\Delivery;
use Modules\MassMail\Repository\DeliveryRepository;
use Modules\MassMail\Repository\Email;
use Modules\MassMail\Repository\EmailAttachment;
use Modules\MassMail\Repository\EmailAttachmentRepository;
use Modules\MassMail\Repository\EmailRepository;
use Modules\MassMail\Repository\MemberResolutionRepository;
use Modules\MassMail\Repository\SuppressedAddressRepository;
use Modules\MassMail\Repository\Target;
use Modules\MassMail\Repository\TargetRepository;

/**
 * Owns the whole email lifecycle (module spec's "Workflow des statuts") —
 * draft → test → sending → sent, plus the one permitted backward
 * transition (test → draft). Every other status combination is rejected.
 */
class MassMailService
{
    private const SCHEDULER_TASK_KEY = 'send_batch';
    private const SCHEDULER_MODULE_ID = 'mass_mail';

    public function __construct(
        private EmailRepository $emailRepository,
        /** The messages, one address each (issue #999). */
        private DeliveryRepository $deliveryRepository,
        /** Who each communication is meant for — the history and the notification. */
        private TargetRepository $targetRepository,
        private EmailAttachmentRepository $attachmentRepository,
        private FileRepository $fileRepository,
        private MailingListService $mailingListService,
        private MemberService $memberService,
        private MemberEmailService $memberEmailService,
        private SectionService $sectionService,
        private MailService $mailService,
        private SchedulerService $schedulerService,
        private JournalService $journalService,
        private HtmlSanitizer $htmlSanitizer,
        private ScoutYearService $scoutYearService,
        private ImportJournalRepository $importJournalRepository,
        private string $storagePath,
        private AudienceRepository $audienceRepository,
        private MemberResolutionRepository $memberResolutionRepository,
        private SuppressedAddressRepository $suppressedAddressRepository,
        private MergeRenderer $mergeRenderer,
        /**
         * Bounce state (roadmap IT-05), in the allowed module → core
         * direction. Nullable because the core table is always readable
         * but nothing fills it without `inbound_mail`: null then means
         * « rien n'a jamais rebondi », which is true rather than
         * degraded.
         */
        private ?\Core\Mail\Feedback\Bounce\BounceService $bounces = null
    ) {
    }

    /**
     * The email's "From" — the sender section's own configured
     * address/name (module addendum: never the site's global mail
     * configuration) **when the site can make DMARC pass for it**, and the
     * site's own address with the section named in the display name when it
     * cannot. Falls back to the site's default (null override) when the
     * section has no configured email at all.
     *
     * **Why a section address is not always usable as a `From:`** (issue
     * #418). The mailing keeps the site's envelope and the site's DKIM key
     * whatever the `From:` says, so a section address on somebody else's
     * domain aligns with neither SPF nor DKIM: the message fails DMARC
     * outright. Measured on 2026-09-24, that means REFUSED at telenet.be
     * and yahoo.*, and quarantined at skynet.be, proximus.be, voo.be,
     * icloud.com and proton.me. And nothing here would ever learn it — an
     * aggregate report goes to the `rua=` of the From domain, so a message
     * sent as `baladins@telenet.be` is reported to Telenet. The failure has
     * to be avoided at the send and announced in the configuration, which
     * is what {@see \Core\Mail\MailIdentity::canAlignFrom()} and the two
     * warning screens do.
     *
     * **The section is still named, and replies still reach it.** Issue #418
     * chose a substitution over a refusal: the display name becomes
     * « Baladins (Unité X) » so the recipient still reads who is writing,
     * and `Reply-To:` carries the section's address so « Répondre » arrives
     * where it always did. Nothing about the mailing is blocked.
     *
     * **The site's own identity is read through `getDefaultSender()`**, not
     * from a `MailIdentity` of our own: that method is already the one
     * source {@see self::resolveDisplayedSender()} trusts for « who is this
     * message from », and two sources for one question is how the screen and
     * the send come to disagree. `canAlignFrom()` reads the From address and
     * nothing else, so this is the whole of what it needs.
     *
     * @return array{address: ?string, name: ?string, reply_to: ?string, contact: string}
     */
    public function resolveSenderIdentity(int $sectionId): array
    {
        $section = $this->sectionService->getSection($sectionId);
        $sectionAddress = trim((string) ($section['email'] ?? ''));
        $sectionName = $section['name'] ?? null;
        $site = $this->mailService->getDefaultSender();

        if ($sectionAddress === '') {
            // No address to align or to substitute: the site's own, exactly
            // as before this rule existed.
            return [
                'address' => null,
                'name' => $sectionName,
                'reply_to' => null,
                'contact' => $site['address'],
            ];
        }

        $identity = new MailIdentity($site['address'], $site['name']);

        if ($identity->canAlignFrom($sectionAddress)) {
            return [
                'address' => $sectionAddress,
                'name' => $sectionName,
                // No `Reply-To:` — the `From:` is the section already, and a
                // header repeating it would be one more thing to keep in
                // step for no reader's benefit.
                'reply_to' => null,
                'contact' => $sectionAddress,
            ];
        }

        return [
            // Null, so `MailService::send()` uses the site's own address —
            // the one it can sign for.
            'address' => null,
            'name' => $identity->substitutedFromName($sectionName),
            'reply_to' => $sectionAddress,
            'contact' => $sectionAddress,
        ];
    }

    /**
     * The « De : » a test-mode screen shows — the same address and name
     * the recipient will read in their mailbox, fallback included.
     *
     * `resolveSenderIdentity()` above deliberately returns a null address
     * when the section has none, because that null is what tells
     * `Core\Mail\MailService::send()` to use the site's own
     * configuration. That is right for sending and useless for showing:
     * a screen cannot print « null ». So the fallback is resolved once,
     * here, against the same values `send()` would fall back to — never
     * re-derived by a template, which would be a second answer to the
     * question of who this e-mail is from.
     *
     * @return array{address: string, name: string}
     */
    public function resolveDisplayedSender(int $sectionId): array
    {
        $sender = $this->resolveSenderIdentity($sectionId);
        $default = $this->mailService->getDefaultSender();

        return [
            'address' => $sender['address'] ?? $default['address'],
            'name' => $sender['name'] ?? $default['name'],
        ];
    }

    public function findById(int $id): ?Email
    {
        return $this->emailRepository->findById($id);
    }

    /**
     * One journal entry for a message that LEFT.
     *
     * The methods below exist because « Lot d'emails de masse envoyé — 6
     * envoyés, 1 erreur » is not a trace of anything: it says that
     * something went wrong to somebody, and there is no search that turns
     * it into *which* message, *why*, or even *when*, since a batch covers
     * several emails at once. A mailing of seven messages leaves seven
     * lines in /admin/journal, one per message, and the one that did not
     * go is a line of its own.
     *
     * **No address, ever** (SECURITY.md §11). What identifies a line is the
     * email id, the message (delivery) id and — when the message concerns
     * members — their member ids, the only personal reference the journal
     * is allowed to carry. Those are the keys the tracking page
     * (`/mass-mail/{id}/tracking`) is built on, so the two read together:
     * the journal says what happened and when, the tracking page says to
     * whom.
     *
     * The email id also travels in the DESCRIPTION and not only in the
     * context, and that is the point of the wording rather than a
     * decoration: `/admin/journal`'s search box matches `description`
     * alone (`Core\Journal\JournalRepository::buildFilters()`), so
     * searching « #12 » has to be enough to pull the whole story of email
     * 12 — start, every message, end — out of a journal holding everything
     * else the site did that week.
     *
     * @param int[] $memberIds the members the message concerns, none for a contact's
     */
    public function journalDeliverySent(int $emailId, int $deliveryId, array $memberIds): void
    {
        $this->logDeliveryEvent(
            'delivery_sent',
            'info',
            self::emailPrefix($emailId) . ' : message #' . $deliveryId . ' envoyé',
            $emailId,
            $deliveryId,
            $memberIds
        );
    }

    /**
     * One journal entry for a message the transport REFUSED — it was
     * attempted, and it bounced back. `error`, like the message's own
     * status and like the red on the tracking page: a journal filing the
     * same fact under « info » would hide it from the reader who came to
     * /admin/journal having filtered on « Erreur » to find it.
     *
     * @param int[] $memberIds
     * @param array<string, mixed> $extra technical detail (the transport's own message)
     */
    public function journalDeliverySendFailed(
        int $emailId,
        int $deliveryId,
        array $memberIds,
        array $extra = []
    ): void {
        $this->logDeliveryEvent(
            'delivery_send_failed',
            'error',
            self::emailPrefix($emailId) . ' : échec d\'envoi du message #' . $deliveryId,
            $emailId,
            $deliveryId,
            $memberIds,
            $extra
        );
    }

    /**
     * One journal entry for a message that was never even attempted —
     * refused when the mailing was frozen (a contact whose address is
     * unusable, unsubscribed or suspended after bounces), or found
     * unrenderable by the batch (a purged publipostage row). Such a message
     * is written straight in `Delivery::STATUS_ERROR` and never goes through
     * the transport, so without this line it would exist on one screen and
     * nowhere in the journal.
     *
     * $reason is the site's own French sentence (« Adresse invalide »,
     * « Adresse désinscrite des emails groupés »), which is what the message
     * row already stores — never anything derived from the address itself.
     *
     * @param int[] $memberIds
     */
    public function journalDeliveryNotSendable(
        int $emailId,
        int $deliveryId,
        array $memberIds,
        string $reason
    ): void {
        $this->logDeliveryEvent(
            'delivery_not_sendable',
            'error',
            self::emailPrefix($emailId) . ' : rien n\'est parti pour le message #' . $deliveryId . ' — ' . $reason,
            $emailId,
            $deliveryId,
            $memberIds,
            ['reason' => $reason]
        );
    }

    /**
     * One journal entry for a member the communication is meant for and to
     * whom no message at all could be written — no eligible address. The
     * target exists (it reads « Non envoyée » on the member page and in the
     * tracking), and this is the line that says why.
     */
    private function journalTargetWithoutMessage(int $emailId, int $targetId, int $memberId, string $reason): void
    {
        $this->journalService->log(
            'mass_mail',
            'target_not_sent',
            'error',
            self::emailPrefix($emailId) . ' : aucun message pour l\'animé ciblé #' . $targetId . ' — ' . $reason,
            ['email_id' => $emailId, 'target_id' => $targetId, 'member_id' => $memberId, 'reason' => $reason],
            null
        );
    }

    /**
     * The one place the per-message entries are actually written, so the
     * shape of the context is decided once.
     *
     * `$userId` is deliberately null. A batch runs in the scheduler with
     * nobody logged in, and attributing a message to the chief who pressed
     * « Lancer l'envoi » minutes or hours earlier would be a guess dressed
     * up as a record — `email_sending_started` already carries that person,
     * once, which is where it belongs.
     *
     * @param int[] $memberIds
     * @param array<string, mixed> $extra
     */
    private function logDeliveryEvent(
        string $type,
        string $level,
        string $description,
        int $emailId,
        int $deliveryId,
        array $memberIds,
        array $extra = []
    ): void {
        $this->journalService->log(
            'mass_mail',
            $type,
            $level,
            $description,
            array_merge([
                'email_id' => $emailId,
                'delivery_id' => $deliveryId,
                'member_ids' => array_values($memberIds),
            ], $extra),
            null
        );
    }

    /**
     * « Email groupé #12 » — the searchable half of every description
     * above, written once so the next event type cannot invent its own
     * spelling and fall out of the same search.
     */
    private static function emailPrefix(int $emailId): string
    {
        return 'Email groupé #' . $emailId;
    }

    /**
     * @param int[]|null $visibleSectionIds null = every section (a chef d'unité and above)
     * @return array{emails: Email[], total: int, per_page: int}
     */
    public function findFiltered(
        string $search,
        ?string $status,
        ?int $sectionId,
        int $page,
        ?array $visibleSectionIds = null,
        ?int $ownAccountId = null
    ): array {
        // The default lists have no row anywhere, so their labels are not
        // a column the search can reach — they are matched here, in PHP,
        // and handed over as the list types they stand for.
        $matchedDefaultListTypes = [];
        if ($search !== '') {
            $defaultListLabels = [
                Email::LIST_TYPE_DEFAULT_ACTIVE_MEMBERS => MailingListService::ACTIVE_MEMBERS_LABEL,
                Email::LIST_TYPE_DEFAULT_CHIEFS => MailingListService::CHIEFS_LABEL,
                Email::LIST_TYPE_DEFAULT_FORMER_MEMBERS => MailingListService::FORMER_MEMBERS_LABEL,
            ];
            foreach ($defaultListLabels as $listType => $label) {
                if (mb_stripos($label, $search) !== false) {
                    $matchedDefaultListTypes[] = $listType;
                }
            }
        }

        $result = $this->emailRepository->findFiltered(
            $search,
            $status,
            $sectionId,
            $matchedDefaultListTypes,
            $page,
            $visibleSectionIds,
            $ownAccountId
        );

        return ['emails' => $result['emails'], 'total' => $result['total'], 'per_page' => EmailRepository::perPage()];
    }

    /**
     * Which email an attachment belongs to, and which email a message
     * belongs to — the two ids the routes carry that are not an email's
     * own, and that a caller therefore has to resolve before it can ask
     * whether this session may touch that email at all.
     */
    public function findEmailIdForAttachment(int $attachmentId): ?int
    {
        return $this->attachmentRepository->findById($attachmentId)?->emailId;
    }

    /** @see self::findEmailIdForAttachment() */
    public function findEmailIdForDelivery(int $deliveryId): ?int
    {
        return $this->deliveryRepository->findById($deliveryId)?->emailId;
    }

    /**
     * How many messages the list page's row counts (0 for a draft/test
     * email — nothing is frozen before sending starts).
     */
    public function getRecipientCount(int $emailId): int
    {
        return $this->deliveryRepository->countGroupedByStatus($emailId)['total'];
    }

    /**
     * Messages by status — one per address, never people.
     *
     * @return array{pending: int, sent: int, error: int, total: int}
     */
    public function getStatusCounts(int $emailId): array
    {
        return $this->deliveryRepository->countGroupedByStatus($emailId);
    }

    /** How many members a started email was meant for — its targets. */
    public function getTargetCount(int $emailId): int
    {
        return count($this->targetRepository->findByEmailId($emailId));
    }

    /**
     * @param int[] $scoutYearIds At least one — module addendum: an email may target several scout years at once.
     *                            Ignored (stored empty) for a mail-merge email, whose audience file defines the
     *                            recipients on its own.
     * @throws MassMailException on invalid input, an unauthorized sender section/list/audience, or an unimported scout
     *     year
     */
    public function createDraft(
        string $subject,
        string $bodyHtml,
        int $sectionId,
        string $listType,
        ?int $listId,
        ?int $listSectionId,
        array $scoutYearIds,
        ?int $createdBy,
        SenderAuthorization $authorization,
        ?int $audienceId = null
    ): Email {
        [
            $subject,
            $bodyHtml
        ] = $this->validateAndSanitize($subject, $bodyHtml, $listType, $listId, $listSectionId, $audienceId);
        $this->assertSenderSectionAllowed($sectionId, $authorization);
        $this->assertListSelectionAllowed($listType, $listSectionId, $authorization);
        if ($listType === Email::LIST_TYPE_MAIL_MERGE) {
            \assert($audienceId !== null); // validateAndSanitize() enforced it
            $this->assertAudienceUsable($audienceId, $createdBy, $authorization);
            $scoutYearIds = [];
        } else {
            $audienceId = null;
            $this->assertScoutYearsSelectable($scoutYearIds);
        }

        $id = $this->emailRepository->create(
            $subject,
            $bodyHtml,
            $sectionId,
            $listType,
            $listId,
            $listSectionId,
            $scoutYearIds,
            $createdBy,
            $audienceId
        );

        $this->journalService->log(
            'mass_mail',
            'email_created',
            'info',
            'Nouvel email de masse créé (brouillon)',
            ['email_id' => $id],
            $createdBy
        );

        $email = $this->emailRepository->findById($id);
        \assert($email !== null);
        return $email;
    }

    /**
     * Only re-validates the sender section / list / scout years against
     * $authorization when the submitted value actually differs from what's
     * already stored — editing a draft's subject/body must never be
     * blocked just because it targets a section/list/year(s) outside the
     * current editor's own scope (e.g. a chef d'unité's draft reopened by
     * a plain section chief).
     *
     * @param int[] $scoutYearIds At least one (ignored for mail-merge, as in createDraft()).
     * @throws MassMailException when the email doesn't exist, isn't a draft, input is invalid, or a changed
     *                            sender section/list/scout year/audience isn't authorized
     */
    public function updateDraft(
        int $id,
        string $subject,
        string $bodyHtml,
        int $sectionId,
        string $listType,
        ?int $listId,
        ?int $listSectionId,
        array $scoutYearIds,
        SenderAuthorization $authorization,
        ?int $audienceId = null,
        ?int $actorId = null
    ): Email {
        $email = $this->requireEmail($id);
        if (!$email->isEditable()) {
            throw new MassMailException('Cet email ne peut plus être modifié.');
        }

        [
            $subject,
            $bodyHtml
        ] = $this->validateAndSanitize($subject, $bodyHtml, $listType, $listId, $listSectionId, $audienceId);

        if ($sectionId !== $email->sectionId) {
            $this->assertSenderSectionAllowed($sectionId, $authorization);
        }
        if ($listType !== $email->listType || $listId !== $email->listId || $listSectionId !== $email->listSectionId) {
            $this->assertListSelectionAllowed($listType, $listSectionId, $authorization);
        }
        if ($listType === Email::LIST_TYPE_MAIL_MERGE) {
            \assert($audienceId !== null); // validateAndSanitize() enforced it
            if ($audienceId !== $email->audienceId) {
                $this->assertAudienceUsable($audienceId, $actorId, $authorization);
            }
            $scoutYearIds = [];
        } else {
            $audienceId = null;
            $normalizedNew = $scoutYearIds;
            sort($normalizedNew);
            $normalizedOld = $email->scoutYearIds;
            sort($normalizedOld);
            if ($normalizedNew !== $normalizedOld) {
                $this->assertScoutYearsSelectable($scoutYearIds);
            }
        }

        $this->emailRepository->update(
            $id,
            $subject,
            $bodyHtml,
            $sectionId,
            $listType,
            $listId,
            $listSectionId,
            $scoutYearIds,
            $audienceId
        );

        $updated = $this->emailRepository->findById($id);
        \assert($updated !== null);
        return $updated;
    }

    /**
     * draft → test.
     *
     * @throws MassMailException when the email doesn't exist or isn't a draft
     */
    public function moveToTest(int $id, ?int $actorId): Email
    {
        $email = $this->requireEmail($id);
        if ($email->status !== Email::STATUS_DRAFT) {
            throw new MassMailException('Seul un brouillon peut passer en mode test.');
        }

        $this->emailRepository->updateStatus($id, Email::STATUS_TEST);

        $this->journalService->log(
            'mass_mail',
            'email_status_changed',
            'info',
            'Email de masse passé en mode test',
            ['email_id' => $id, 'from' => Email::STATUS_DRAFT, 'to' => Email::STATUS_TEST],
            $actorId
        );

        return $this->requireEmail($id);
    }

    /**
     * Deletes an email whose real sending has not started (issue #755):
     * a draft, or one in test. Its attachments go with it — the link
     * rows, and each file nothing else attaches (Core\File\
     * AttachedFileRemover: row first, bytes second).
     *
     * The status is checked again by the DELETE itself, so a start that
     * won the race leaves the email whole and this refuses.
     *
     * @throws MassMailException when the email doesn't exist or its sending has started
     */
    public function deleteUnsent(int $id, ?int $actorId): void
    {
        $email = $this->requireEmail($id);
        $attachments = $this->attachmentRepository->findByEmailId($id);

        if (!in_array($email->status, [Email::STATUS_DRAFT, Email::STATUS_TEST], true)
            || !$this->emailRepository->deleteIfNotStarted($id)
        ) {
            throw new MassMailException(
                'Cet e-mail est déjà en cours d\'envoi ou a été envoyé et ne peut plus être supprimé.'
            );
        }

        $remover = new AttachedFileRemover($this->fileRepository, $this->storagePath);
        foreach ($attachments as $attachment) {
            $remover->remove($this->attachmentRepository, $attachment->id, $attachment->fileId, true);
        }

        // Which email and what became of it — never a recipient, never the
        // subject (a subject can name somebody).
        $this->journalService->log(
            'mass_mail',
            'email_deleted',
            'info',
            self::emailPrefix($id) . ' : supprimé avant envoi',
            ['email_id' => $id, 'status' => $email->status, 'attachment_count' => count($attachments)],
            $actorId
        );
    }

    /**
     * test → draft — the only permitted backward transition.
     *
     * @throws MassMailException when the email doesn't exist or isn't in test
     */
    public function backToDraft(int $id, ?int $actorId): Email
    {
        $email = $this->requireEmail($id);
        if ($email->status !== Email::STATUS_TEST) {
            throw new MassMailException('Seul un email en mode test peut revenir en brouillon.');
        }

        $this->emailRepository->updateStatus($id, Email::STATUS_DRAFT);

        $this->journalService->log(
            'mass_mail',
            'email_status_changed',
            'info',
            'Email de masse revenu en brouillon',
            ['email_id' => $id, 'from' => Email::STATUS_TEST, 'to' => Email::STATUS_DRAFT],
            $actorId
        );

        return $this->requireEmail($id);
    }

    /**
     * Sends a one-off test copy to $toAddress immediately (not through the
     * scheduler/batch mechanism — module spec: this is a manual check, not
     * a real send). Only available while in test mode.
     *
     * @throws MassMailException when the email doesn't exist or isn't in test
     * @throws MailException on send failure — the caller surfaces it as-is
     */
    public function sendTestEmail(int $id, string $toAddress, int $mergeOffset = 0): void
    {
        $email = $this->requireEmail($id);
        if ($email->status !== Email::STATUS_TEST) {
            throw new MassMailException('L\'envoi de test n\'est disponible qu\'en mode test.');
        }
        if (filter_var($toAddress, FILTER_VALIDATE_EMAIL) === false) {
            throw new MassMailException('Adresse email invalide.');
        }

        $subject = $email->subject;
        $bodyHtml = $email->bodyHtml;
        if ($email->listType === Email::LIST_TYPE_MAIL_MERGE) {
            // The test goes out with the previewed row's real values —
            // exactly what that row's recipient would receive.
            $row = $this->requireMergeRow($email, $mergeOffset);
            $subject = $this->mergeRenderer->renderText($subject, $row->data);
            $bodyHtml = $this->mergeRenderer->renderHtml($bodyHtml, $row->data);
        }

        $sender = $this->resolveSenderIdentity($email->sectionId);

        $this->mailService->send(
            $toAddress,
            '[TEST] ' . $subject,
            $bodyHtml,
            strip_tags($bodyHtml),
            // **The same `Reply-To:` the batch sets**, and it was a hardcoded
            // null (found in review on #573). For a section whose address
            // this site cannot sign for, `resolveSenderIdentity()` answers a
            // null address and the section in `reply_to`; passing null here
            // let `MailService::send()` apply its own fallback — the SITE's
            // reply address — so the test message showed the substituted
            // `From:` naming the section and sent « Répondre » to the unit.
            // This message is the one thing a chief inspects to know what
            // recipients get, so a divergence here is worse than elsewhere.
            $sender['reply_to'],
            $this->buildAttachmentPayload($id),
            $sender['address'],
            $sender['name']
        );
    }

    /**
     * The compose dialog's per-recipient preview (mail-merge test mode):
     * the Nth audience row's rendered subject/body plus what the chief
     * should double-check — unknown {{tokens}} and columns this row has
     * no value for.
     *
     * @return array{
     *     offset: int,
     *     total: int,
     *     row_index: int,
     *     recipient_label: string,
     *     subject: string,
     *     body_html: string,
     *     unknown_tokens: string[],
     *     missing_values: string[]
     * }
     * @param int|null $offset which audience row to show, or null for
     *                          « somebody at random » — see below.
     * @throws MassMailException when the email doesn't exist, isn't a mail-merge, or the offset is out of range
     */
    public function getMergePreview(int $emailId, ?int $offset): array
    {
        $email = $this->requireEmail($emailId);
        if ($email->listType !== Email::LIST_TYPE_MAIL_MERGE) {
            throw new MassMailException('Cet email n\'est pas un publipostage.');
        }
        $audience = $email->audienceId !== null ? $this->audienceRepository->findById($email->audienceId) : null;
        if ($audience === null) {
            throw new MassMailException('L\'audience de publipostage a été purgée — réimportez le fichier Excel.');
        }

        // A null offset means « pick somebody ». The first line of the
        // file is the one line that proves nothing: it is the row whose
        // values the author already had in front of them while writing,
        // so it is the row their variables were unconsciously fitted to.
        // A random one is what turns the preview from a rehearsal of a
        // known case into a real sample — a missing « Prénom » on line
        // 148 shows up on opening the page rather than never.
        //
        // max(0, …) rather than rowCount - 1 straight: an audience that
        // has been emptied would otherwise ask random_int() for a range
        // ending at -1, and the honest error is the one requireMergeRow()
        // raises just below, naming the file to re-import.
        $offset = $offset === null
            ? random_int(0, max(0, $audience->rowCount - 1))
            : max(0, min($offset, $audience->rowCount - 1));
        $row = $this->requireMergeRow($email, $offset);

        return [
            'offset' => $offset,
            'total' => $audience->rowCount,
            'row_index' => $row->rowIndex,
            'recipient_label' => $this->buildMergeRecipientLabel($row),
            'subject' => $this->mergeRenderer->renderText($email->subject, $row->data),
            'body_html' => $this->mergeRenderer->renderHtml($email->bodyHtml, $row->data),
            'unknown_tokens' => array_values(array_unique(array_merge(
                $this->mergeRenderer->findUnknownTokens($email->subject, $audience->columns),
                $this->mergeRenderer->findUnknownTokens($email->bodyHtml, $audience->columns)
            ))),
            'missing_values' => array_values(array_unique(array_merge(
                $this->mergeRenderer->findMissingValues($email->subject, $row->data),
                $this->mergeRenderer->findMissingValues($email->bodyHtml, $row->data)
            ))),
        ];
    }

    /**
     * @throws MassMailException
     */
    private function requireMergeRow(Email $email, int $offset): AudienceRow
    {
        $row = $email->audienceId !== null
            ? $this->audienceRepository->findRowByOffset($email->audienceId, max(0, $offset))
            : null;
        if ($row === null) {
            throw new MassMailException('Ligne de publipostage introuvable — réimportez le fichier Excel.');
        }
        return $row;
    }

    /**
     * Who the previewed row goes to, without resolving (and lazily
     * creating) member_email rows during a read-only preview: a member
     * row shows the member's display name, an external row its own
     * address(es).
     */
    private function buildMergeRecipientLabel(AudienceRow $row): string
    {
        if ($row->memberId === null) {
            return $row->email ?? '';
        }

        $resolved = $this->memberResolutionRepository->resolveMergeMembers([$row->memberId]);
        $profileYear = $resolved[$row->memberId]['scout_year_id'] ?? null;
        $profile = $profileYear !== null
            ? $this->memberService->findProfileByMemberAndYear($row->memberId, $profileYear)
            : null;

        return $profile !== null
            ? $profile->getDisplayName() . ' (toutes ses adresses connues)'
            : 'Membre (toutes ses adresses connues)';
    }

    /**
     * How many people this email would reach if it were sent right now.
     *
     * Asked just before the confirmation dialog, because « Lancer
     * l'envoi ? » on its own is a question about an unknown quantity. The
     * difference between forty-two and four hundred is the difference
     * between a section and the whole unit, and a wrong list selection
     * looks exactly like a right one until the mail is out — at which
     * point nothing can be recalled.
     *
     * Counts people, not addresses. A member with three valid addresses
     * receives one email at each, so the frozen row count is larger; but
     * the question a manager is answering is "who is this going to?", and
     * inflating the number with the mechanics of secondary addresses would
     * make it harder to recognise a wrong list, not easier.
     *
     * Deliberately re-resolved rather than cached with the email: the list
     * behind it is live, and a count from when the dialog's page was
     * opened is a count from before somebody else edited that list.
     *
     * @return array{count: int, kind: string} kind: 'members' | 'rows'
     * @throws MassMailException when the email doesn't exist
     * @throws MailingListException when the list behind it can no longer be
     *         resolved — an unknown custom list id, or the external list
     *         after the module publishing it was disabled. Declared because
     *         callers have to handle it: it was missing here, and
     *         Controller\MassMailController caught only MassMailException
     *         around this call as a result, so that case reached a 500.
     */
    public function estimateRecipientCount(int $id): array
    {
        $email = $this->requireEmail($id);

        if ($email->listType === Email::LIST_TYPE_MAIL_MERGE) {
            return [
                'count' => $email->audienceId !== null
                    ? count($this->audienceRepository->findRowsByAudience($email->audienceId))
                    : 0,
                'kind' => 'rows',
            ];
        }

        return [
            'count' => count($this->mailingListService->resolveMembersForYears(
                $email->listType,
                $email->listId,
                $email->listSectionId,
                $this->orderYearsMostRecentFirst($email->scoutYearIds)
            )),
            'kind' => 'members',
        ];
    }

    /**
     * The addresses of a file row without a Tiers that a send writes to:
     * its Email cell split on `;` and `,`, deduplicated within the row,
     * less the malformed, unsubscribed and suspended ones.
     *
     * @return string[]
     */
    private function previewFileRowAddresses(AudienceRow $row): array
    {
        return array_values(array_filter(
            $this->withoutSuppressed($this->splitRowAddresses($row)),
            fn(string $address): bool => filter_var($address, FILTER_VALIDATE_EMAIL) !== false
                && $this->bounces?->isBlocked($address) !== true
        ));
    }

    /**
     * @param string[] $addresses
     * @return string[] the same, in order, less every unsubscribed one
     */
    private function withoutSuppressed(array $addresses): array
    {
        if ($addresses === []) {
            return [];
        }
        $suppressed = array_flip(array_map(
            static fn(string $a): string => self::addressKey($a),
            $this->suppressedAddressRepository->filterSuppressed($addresses)
        ));

        return array_values(array_filter(
            $addresses,
            static fn(string $a): bool => !isset($suppressed[self::addressKey($a)])
        ));
    }

    /** The form two addresses are compared under: one message per distinct key. */
    private static function addressKey(string $address): string
    {
        return mb_strtolower(trim($address));
    }

    /**
     * test → sending. Freezes the mailing right now (module spec: « le
     * système fige la liste des destinataires ») into its TARGETS — the
     * members it is meant for — and its MESSAGES — one per address, each to
     * exactly one address (issue #999, §2.1 to §2.3 of
     * docs/chantiers/CHANTIER-envois-groupes.md). Both are written in the
     * same transaction as the status change, which is what makes a second
     * freeze of the same email impossible. The actual sending is left to
     * Task\SendBatchHandler, kicked off here with an immediate first run.
     *
     * @throws MassMailException when the email doesn't exist or isn't in test
     * @throws MailingListException when the list can no longer be resolved — the
     *         freeze re-resolves it, so the same case that breaks the
     *         estimate refuses the send here.
     */
    public function startSending(int $id, ?int $actorId): Email
    {
        $email = $this->requireEmail($id);
        if ($email->status !== Email::STATUS_TEST) {
            throw new MassMailException('Seul un email en mode test peut être envoyé.');
        }

        // One transaction (issue #755): the claim — a compare-and-set, so an
        // email deleted or started by another request since it was read
        // above does not start here — and everything it freezes. A batch
        // never sees half of it, a deletion waits and then finds it
        // started, and a failed freeze leaves the email in test.
        $frozen = $this->emailRepository->atomically(function () use ($id, $email): array {
            if (!$this->emailRepository->transitionStatus($id, Email::STATUS_TEST, Email::STATUS_SENDING)) {
                throw new MassMailException(
                    "Cet e-mail a été modifié ou supprimé entre-temps : l'envoi n'a pas été lancé."
                );
            }

            return $email->listType === Email::LIST_TYPE_MAIL_MERGE
                ? $this->freezeMergeRecipients($email)
                : $this->freezeListRecipients($email);
        });

        $this->ensureBatchTaskScheduled(true);

        $this->journalService->log(
            'mass_mail',
            'email_sending_started',
            'info',
            self::emailPrefix($id) . ' : envoi démarré',
            [
                'email_id' => $id,
                'message_count' => $frozen['pending'],
                'refused_message_count' => $frozen['refused'],
                'target_count' => $frozen['targets'],
                'targets_without_message' => $frozen['targets_without_message'],
            ],
            $actorId
        );

        // Nothing to send — a custom list without a block, a list of
        // members without an address: no batch will ever touch this email,
        // so it is finished now rather than left « en cours d'envoi » for
        // good.
        if ($frozen['pending'] === 0) {
            $this->checkAndMarkSentIfComplete($id, $actorId);
        }

        return $this->requireEmail($id);
    }

    /**
     * The classic list freeze (§2.2).
     *
     * - **Targets**: every member the list designates for the chosen years,
     *   once each (Service\MailingListService::resolveMembersForYears() —
     *   a member brought by two criteria, groups or years is one target,
     *   with the most recent year). Nobody is dropped for sharing an
     *   address with another target, and nobody is added for sharing one
     *   with a target: an address is never a reason to create or remove a
     *   target.
     * - **A member's eligible addresses**: Core\Member\MemberEmailService::
     *   resolveValidAddressesForMassMail() (the valid Desk address, the
     *   confirmed personal ones, less those suspended after bounces), less
     *   the unsubscribed addresses of mass_mail_suppressed_addresses — read
     *   here, since the core's own guard cannot read a module table.
     * - **Messages**: ONE per distinct address (compared lowercased) across
     *   every target and every contact of the list, linked to every target
     *   whose address it is. A message whose address only a contact group
     *   brings concerns no target.
     * - A target left with no eligible address has no message: it reads
     *   « Non envoyée » on the member page and in the tracking.
     *
     * A contact's address that is malformed, unsubscribed or suspended
     * after bounces is written as a message in error rather than dropped in
     * silence, so the tracking page says why somebody was not written to —
     * as it always did for contacts.
     *
     * @return array{pending: int, refused: int, targets: int, targets_without_message: int}
     */
    private function freezeListRecipients(Email $email): array
    {
        $designated = $this->mailingListService->resolveMembersForYears(
            $email->listType,
            $email->listId,
            $email->listSectionId,
            $this->orderYearsMostRecentFirst($email->scoutYearIds)
        );

        /** @var array<string, array{email: string, member_email_id: ?int, target_ids: int[]}> $messages */
        $messages = [];
        $contacts = [];
        $targetCount = 0;
        $withoutMessage = 0;

        foreach ($designated as $member) {
            if ($member['member_id'] === null) {
                $contacts[] = (string) $member['email'];
                continue;
            }

            $targetId = $this->targetRepository->create($email->id, $member['member_id'], $member['scout_year_id']);
            $targetCount++;

            $deskEmail = $member['email'] !== null && filter_var($member['email'], FILTER_VALIDATE_EMAIL) !== false
                ? $member['email']
                : null;
            $addresses = $this->eligibleAddressesOfMember($member['member_id'], $deskEmail);

            if ($addresses === []) {
                $this->journalTargetWithoutMessage(
                    $email->id,
                    $targetId,
                    $member['member_id'],
                    $this->reasonForNoAddress($member['member_id'], $deskEmail)
                );
                $withoutMessage++;
                continue;
            }

            foreach ($addresses as $memberEmail) {
                $key = self::addressKey($memberEmail->email);
                $messages[$key] ??= [
                    'email' => $memberEmail->email,
                    'member_email_id' => $memberEmail->id,
                    'target_ids' => [],
                ];
                $messages[$key]['target_ids'][] = $targetId;
            }
        }

        $refused = 0;
        $refusedKeys = [];
        foreach ($contacts as $contact) {
            $key = self::addressKey($contact);
            if (isset($messages[$key]) || isset($refusedKeys[$key])) {
                // Already a message at this very address, concerning the
                // members it belongs to — the contact is that message, once.
                continue;
            }

            $refusal = $this->contactRefusal($contact);
            if ($refusal !== null) {
                $deliveryId = $this->deliveryRepository->create($email->id, $contact, Delivery::STATUS_ERROR, $refusal);
                $this->journalDeliveryNotSendable($email->id, $deliveryId, [], $refusal);
                $refused++;
                $refusedKeys[$key] = true;
                continue;
            }
            $messages[$key] = ['email' => $contact, 'member_email_id' => null, 'target_ids' => []];
        }

        $pending = 0;
        foreach ($messages as $message) {
            $deliveryId = $this->deliveryRepository->create(
                $email->id,
                $message['email'],
                Delivery::STATUS_PENDING,
                null,
                $message['member_email_id']
            );
            $this->deliveryRepository->linkTargets($deliveryId, $message['target_ids']);
            $pending++;
        }

        return [
            'pending' => $pending,
            'refused' => $refused,
            'targets' => $targetCount,
            'targets_without_message' => $withoutMessage,
        ];
    }

    /**
     * Why a contact's address may not be written to, or null when it may —
     * malformed, unsubscribed (the module's suppression list: a mail-merge
     * unsubscribe and a contact's unsubscribe are the same request from the
     * same person), or suspended after bounces (roadmap IT-05: a bounce
     * block applies to the ADDRESS, and a contact never goes through
     * MemberEmailService, which filters members').
     */
    private function contactRefusal(string $address): ?string
    {
        if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            return 'Adresse invalide';
        }
        if ($this->suppressedAddressRepository->isSuppressed($address)) {
            return 'Adresse désinscrite des emails groupés';
        }
        if ($this->bounces?->isBlocked($address) === true) {
            return 'Adresse suspendue après des refus répétés';
        }

        return null;
    }

    /**
     * A member's eligible addresses at freeze time (§2.2): the core's
     * resolution — which lazily creates the Desk override row the
     * unsubscribe will flip — less the unsubscribed ones, one per distinct
     * address.
     *
     * @return \Core\Member\MemberEmail[]
     */
    private function eligibleAddressesOfMember(int $memberId, ?string $deskEmail): array
    {
        $rows = [];
        foreach ($this->memberEmailService->resolveValidAddressesForMassMail($memberId, $deskEmail) as $row) {
            $rows[self::addressKey($row->email)] ??= $row;
        }
        if ($rows === []) {
            return [];
        }

        $suppressed = array_flip(array_map(
            static fn(string $a): string => self::addressKey($a),
            $this->suppressedAddressRepository->filterSuppressed(array_map(
                static fn(\Core\Member\MemberEmail $r): string => $r->email,
                array_values($rows)
            ))
        ));

        return array_values(array_filter(
            $rows,
            static fn(\Core\Member\MemberEmail $r): bool => !isset($suppressed[self::addressKey($r->email)])
        ));
    }

    /**
     * **Empty has three causes, and they call for different things.** An
     * address merely suspended is well formed and worked until it stopped:
     * reporting it as « Adresse invalide » sends a chef d'unité hunting for
     * a typo that is not there, when what they need is the Rebonds page.
     * One the person unsubscribed is their request, not a fault.
     */
    private function reasonForNoAddress(int $memberId, ?string $deskEmail): string
    {
        if ($this->memberEmailService->everyAddressIsBlockedForMassMail($memberId, $deskEmail)) {
            return 'Adresse suspendue après des refus répétés';
        }
        if ($this->memberEmailService->resolveValidAddressesForMassMail($memberId, $deskEmail) !== []) {
            return 'Adresse désinscrite des emails groupés';
        }

        return 'Adresse invalide';
    }

    /**
     * The mail-merge freeze (§2.3) — ONE communication PER FILE ROW, never
     * re-resolved from the member tables, and never deduplicated between
     * rows: two rows to the same address are two messages, each with its own
     * values.
     *
     * - A row with a Tiers is one target, for that member (two rows for the
     *   same Tiers, two targets), and one message per distinct eligible
     *   address of the member (§2.2), each linked to that target. The
     *   row's Email column is not read for sending.
     * - A row without a Tiers has no target — no history, no notification,
     *   whoever the addresses belong to — and one message per distinct
     *   address of its Email cell, split on `;` and `,` as at the import,
     *   less the malformed, unsubscribed and suspended ones.
     * - A row left with no address has no message (a message always has
     *   one): with a Tiers its target reads « Non envoyée »; with or without,
     *   the tracking page shows the row « Aucune adresse valide ».
     *
     * Every message keeps its audience_row_id so Task\SendBatchHandler can
     * render this row's own variables.
     *
     * @return array{pending: int, refused: int, targets: int, targets_without_message: int}
     */
    private function freezeMergeRecipients(Email $email): array
    {
        if ($email->audienceId === null) {
            throw new MassMailException('Cet email de publipostage n\'a plus d\'audience — réimportez le fichier '
                . 'Excel.');
        }
        $rows = $this->audienceRepository->findRowsByAudience($email->audienceId);
        if ($rows === []) {
            throw new MassMailException('L\'audience de publipostage est vide ou a été purgée — réimportez le fichier '
                . 'Excel.');
        }

        $mergeMembers = $this->memberResolutionRepository->resolveMergeMembers(
            array_values(array_filter(array_map(fn(AudienceRow $r) => $r->memberId, $rows)))
        );

        $pending = 0;
        $targetCount = 0;
        $withoutMessage = 0;
        foreach ($rows as $row) {
            if ($row->memberId !== null) {
                $profile = $mergeMembers[$row->memberId] ?? null;
                $targetId = $this->targetRepository->create(
                    $email->id,
                    $row->memberId,
                    $profile['scout_year_id'] ?? null,
                    $row->id
                );
                $targetCount++;

                $deskEmail = $profile !== null && $profile['email'] !== null
                    && filter_var($profile['email'], FILTER_VALIDATE_EMAIL) !== false
                    ? $profile['email']
                    : null;
                $addresses = $this->eligibleAddressesOfMember($row->memberId, $deskEmail);

                if ($addresses === []) {
                    $this->journalTargetWithoutMessage(
                        $email->id,
                        $targetId,
                        $row->memberId,
                        $this->reasonForNoAddress($row->memberId, $deskEmail)
                    );
                    $withoutMessage++;
                    continue;
                }

                foreach ($addresses as $memberEmail) {
                    $deliveryId = $this->deliveryRepository->create(
                        $email->id,
                        $memberEmail->email,
                        Delivery::STATUS_PENDING,
                        null,
                        $memberEmail->id,
                        $row->id
                    );
                    $this->deliveryRepository->linkTargets($deliveryId, [$targetId]);
                    $pending++;
                }
                continue;
            }

            $addresses = $this->previewFileRowAddresses($row);
            if ($addresses === []) {
                $this->journalService->log(
                    'mass_mail',
                    'merge_row_not_sent',
                    'error',
                    self::emailPrefix($email->id) . ' : aucun message pour la ligne ' . $row->rowIndex
                        . ' du fichier — Aucune adresse valide',
                    ['email_id' => $email->id, 'audience_row_id' => $row->id, 'row_index' => $row->rowIndex],
                    null
                );
                continue;
            }
            foreach ($addresses as $address) {
                $this->deliveryRepository->create(
                    $email->id,
                    $address,
                    Delivery::STATUS_PENDING,
                    null,
                    null,
                    $row->id
                );
                $pending++;
            }
        }

        return [
            'pending' => $pending,
            'refused' => 0,
            'targets' => $targetCount,
            'targets_without_message' => $withoutMessage,
        ];
    }

    /**
     * A file row's Email cell, split on `;` and `,` — the two separators
     * the import accepts — trimmed, deduplicated within the row (compared
     * lowercased): an address written twice on one line gets one message
     * for that line.
     *
     * @return string[]
     */
    private function splitRowAddresses(AudienceRow $row): array
    {
        if ($row->email === null) {
            return [];
        }

        $addresses = [];
        foreach (preg_split('/[;,]/', $row->email) ?: [] as $part) {
            $address = trim($part);
            if ($address !== '') {
                $addresses[self::addressKey($address)] ??= $address;
            }
        }

        return array_values($addresses);
    }

    /**
     * Called once per email actually touched by a processed batch (Task\
     * SendBatchHandler) — flips 'sending' → 'sent' the moment no message
     * of it is 'pending' any more.
     */
    public function checkAndMarkSentIfComplete(int $emailId, ?int $actorId = null): void
    {
        $email = $this->emailRepository->findById($emailId);
        if ($email === null || $email->status !== Email::STATUS_SENDING) {
            return;
        }
        if ($this->deliveryRepository->hasPending($emailId)) {
            return;
        }

        $this->emailRepository->updateStatus($emailId, Email::STATUS_SENT, true);

        $this->journalService->log(
            'mass_mail',
            'email_sent',
            'info',
            self::emailPrefix($emailId) . ' : envoi terminé',
            ['email_id' => $emailId],
            $actorId
        );
    }

    /**
     * « Renvoyer » — one MESSAGE, to its one address, available for an
     * 'error' and an already-'sent' message alike (module spec). Back to
     * 'pending' so the next batch picks it up; an email that had already
     * reached 'sent' is put back to 'sending' so its own status honestly
     * reflects that work remains. Nobody is notified again: the accounts
     * already notified for this communication are recorded
     * (mass_mail_notified_accounts), and a resend finds them there.
     *
     * @throws MassMailException when the message doesn't exist
     */
    public function resendDelivery(int $deliveryId, ?int $actorId): void
    {
        $delivery = $this->deliveryRepository->findById($deliveryId);
        if ($delivery === null) {
            throw new MassMailException('Message introuvable.');
        }

        $this->deliveryRepository->resend($deliveryId);

        $email = $this->emailRepository->findById($delivery->emailId);
        if ($email !== null && $email->status === Email::STATUS_SENT) {
            $this->emailRepository->updateStatus($delivery->emailId, Email::STATUS_SENDING);
        }

        $this->ensureBatchTaskScheduled(true);

        $this->journalService->log(
            'mass_mail',
            'delivery_resent',
            'info',
            self::emailPrefix($delivery->emailId) . ' : renvoi demandé pour le message #' . $deliveryId,
            ['email_id' => $delivery->emailId, 'delivery_id' => $deliveryId],
            $actorId
        );
    }

    /**
     * The tracking page (§2.4, screen 3 of the mock-up): one line per
     * MESSAGE — its address, the members it concerns, and for a mail merge
     * the line of the file — then one line per target left without any
     * message, and for a mail merge one per file row left without any
     * message. The counts count messages; `target_count` names how many
     * members the communication was meant for.
     *
     * @return array{
     *     email: Email,
     *     counts: array{pending: int, sent: int, error: int, total: int},
     *     target_count: int,
     *     targets_without_message: int,
     *     messages: list<array{delivery: Delivery, members: string, row_index: ?int}>,
     *     unsent: list<array{label: string, row_index: ?int}>
     * }
     * @throws MassMailException when the email doesn't exist
     */
    public function getTrackingData(int $id): array
    {
        $email = $this->requireEmail($id);
        $counts = $this->deliveryRepository->countGroupedByStatus($id);
        $deliveries = $this->deliveryRepository->findByEmailId($id);
        $targets = $this->targetRepository->findByEmailId($id);
        $targetIdsByDelivery = $this->deliveryRepository->findTargetIdsByEmail($id);

        $names = $this->targetNames($targets);
        $rowIndexes = $this->audienceRowIndexes($email);

        $targetsWithMessage = [];
        $messages = [];
        foreach ($deliveries as $delivery) {
            $concerned = [];
            foreach ($targetIdsByDelivery[$delivery->id] ?? [] as $targetId) {
                $targetsWithMessage[$targetId] = true;
                $concerned[] = $names[$targetId] ?? 'Membre inconnu';
            }
            $concerned = array_values(array_unique($concerned));
            sort($concerned, SORT_FLAG_CASE | SORT_STRING);

            $messages[] = [
                'delivery' => $delivery,
                'members' => implode(', ', $concerned),
                'row_index' => $delivery->audienceRowId !== null
                    ? ($rowIndexes[$delivery->audienceRowId] ?? null)
                    : null,
            ];
        }

        $unsent = [];
        $rowsWithSomething = [];
        foreach ($deliveries as $delivery) {
            if ($delivery->audienceRowId !== null) {
                $rowsWithSomething[$delivery->audienceRowId] = true;
            }
        }
        foreach ($targets as $target) {
            if ($target->audienceRowId !== null) {
                $rowsWithSomething[$target->audienceRowId] = true;
            }
            if (isset($targetsWithMessage[$target->id])) {
                continue;
            }
            $unsent[] = [
                'label' => $names[$target->id] ?? 'Membre inconnu',
                'row_index' => $target->audienceRowId !== null ? ($rowIndexes[$target->audienceRowId] ?? null) : null,
            ];
        }
        // A file row without a Tiers that kept no address at all leaves
        // neither a message nor a target — only the file still knows it.
        foreach ($rowIndexes as $rowId => $rowIndex) {
            if (!isset($rowsWithSomething[$rowId])) {
                $unsent[] = ['label' => 'Adresse du fichier', 'row_index' => $rowIndex];
            }
        }

        return [
            'email' => $email,
            'counts' => $counts,
            'target_count' => count($targets),
            'targets_without_message' => count($targets) - count($targetsWithMessage),
            'messages' => $messages,
            'unsent' => $unsent,
        ];
    }

    /**
     * Each target's display name, in a fixed number of queries rather than
     * five per target — grouped by each target's OWN year, since a member
     * pulled in via the "previous year" list only has a valid profile for
     * that year.
     *
     * @param Target[] $targets
     * @return array<int, string> keyed by target id
     */
    private function targetNames(array $targets): array
    {
        $memberIdsByYear = [];
        foreach ($targets as $target) {
            if ($target->scoutYearId !== null) {
                $memberIdsByYear[$target->scoutYearId][] = $target->memberId;
            }
        }

        $memberYearIdByPair = [];
        foreach ($memberIdsByYear as $scoutYearId => $memberIds) {
            foreach ($this->memberService->findMemberYearIdsByMembersAndYear(
                $memberIds,
                $scoutYearId
            ) as $memberId => $memberYearId) {
                $memberYearIdByPair[$scoutYearId . ':' . $memberId] = $memberYearId;
            }
        }
        $profiles = $this->sectionService->hydrateMemberProfiles(array_values($memberYearIdByPair));

        $names = [];
        foreach ($targets as $target) {
            $memberYearId = $target->scoutYearId !== null
                ? ($memberYearIdByPair[$target->scoutYearId . ':' . $target->memberId] ?? null)
                : null;
            $profile = $memberYearId !== null ? ($profiles[$memberYearId] ?? null) : null;
            if ($profile !== null) {
                $names[$target->id] = $profile->getDisplayName();
            }
        }

        return $names;
    }

    /**
     * The file line of every row of a mail merge's audience, while it
     * exists — empty for a classic list and after the purge.
     *
     * @return array<int, int> row_index keyed by audience row id
     */
    private function audienceRowIndexes(Email $email): array
    {
        if ($email->listType !== Email::LIST_TYPE_MAIL_MERGE || $email->audienceId === null) {
            return [];
        }

        $indexes = [];
        foreach ($this->audienceRepository->findRowsByAudience($email->audienceId) as $row) {
            $indexes[$row->id] = $row->rowIndex;
        }

        return $indexes;
    }

    /**
     * @return EmailAttachment[]
     */
    public function getAttachments(int $emailId): array
    {
        return $this->attachmentRepository->findByEmailId($emailId);
    }

    /**
     * Refuses an email that can no longer take an attachment — asked BEFORE
     * the file is uploaded (issue #578). Checked only after, the refusal
     * came once the bytes were stored and the `files` row inserted, and
     * left behind an upload that no screen would ever show nor any email
     * deletion ever reach.
     *
     * @throws MassMailException when the email doesn't exist or isn't a draft
     */
    public function assertAcceptsAttachments(int $emailId): void
    {
        if (!$this->requireEmail($emailId)->isEditable()) {
            throw new MassMailException('Des pièces jointes ne peuvent être ajoutées qu\'à un brouillon.');
        }
    }

    /**
     * @throws MassMailException when the email doesn't exist or isn't a draft
     */
    public function addAttachment(int $emailId, int $fileId): void
    {
        $this->assertAcceptsAttachments($emailId);

        $this->attachmentRepository->create($emailId, $fileId);
    }

    /**
     * @throws MassMailException when the attachment doesn't exist or its email isn't a draft
     */
    public function removeAttachment(int $attachmentId): void
    {
        $attachment = $this->attachmentRepository->findById($attachmentId);
        if ($attachment === null) {
            throw new MassMailException('Pièce jointe introuvable.');
        }
        $email = $this->emailRepository->findById($attachment->emailId);
        if ($email === null || !$email->isEditable()) {
            throw new MassMailException('Des pièces jointes ne peuvent être retirées que d\'un brouillon.');
        }

        $this->attachmentRepository->delete($attachmentId);
    }

    /**
     * @return array<int, array{path: string, name: string}>
     */
    public function buildAttachmentPayload(int $emailId): array
    {
        $payload = [];
        foreach ($this->attachmentRepository->findByEmailId($emailId) as $attachment) {
            $file = $this->fileRepository->findById($attachment->fileId);
            if ($file === null) {
                continue;
            }
            $payload[] = ['path' => $this->storagePath . '/' . $file->relativePath, 'name' => $file->originalName];
        }
        return $payload;
    }

    /**
     * Ensures exactly one 'send_batch' scheduled action is pending
     * site-wide (module spec: never one job per recipient) — a no-op when
     * one is already pending, since it will pick up every currently-
     * pending recipient (across every email) on its own next run anyway.
     */
    private function ensureBatchTaskScheduled(bool $runImmediately): void
    {
        if ($this->schedulerService->find(self::SCHEDULER_MODULE_ID, self::SCHEDULER_TASK_KEY) !== null) {
            return;
        }

        $this->schedulerService->scheduleAfter(
            self::SCHEDULER_MODULE_ID,
            self::SCHEDULER_TASK_KEY,
            $runImmediately ? 0 : 60
        );
    }

    /**
     * @return array{0: string, 1: string} [subject, sanitized body]
     * @throws MassMailException
     */
    private function validateAndSanitize(
        string $subject,
        string $bodyHtml,
        string $listType,
        ?int $listId,
        ?int $listSectionId,
        ?int $audienceId = null
    ): array
    {
        $subject = trim($subject);
        if ($subject === '') {
            throw new MassMailException('Le sujet est obligatoire.');
        }
        if (!in_array($listType, [
            Email::LIST_TYPE_DEFAULT_SECTION, Email::LIST_TYPE_DEFAULT_ACTIVE_MEMBERS,
            Email::LIST_TYPE_DEFAULT_CHIEFS, Email::LIST_TYPE_CUSTOM, Email::LIST_TYPE_EXTERNAL,
            Email::LIST_TYPE_MAIL_MERGE, Email::LIST_TYPE_DEFAULT_FORMER_MEMBERS,
        ], true)) {
            throw new MassMailException('Type de liste invalide.');
        }
        if ($listType === Email::LIST_TYPE_DEFAULT_SECTION && $listSectionId === null) {
            throw new MassMailException('Section de liste manquante.');
        }
        if ($listType === Email::LIST_TYPE_CUSTOM && $listId === null) {
            throw new MassMailException('Liste personnalisée manquante.');
        }
        if ($listType === Email::LIST_TYPE_MAIL_MERGE && $audienceId === null) {
            throw new MassMailException('Importez d\'abord un fichier Excel pour le publipostage.');
        }

        return [$subject, $this->htmlSanitizer->sanitize($bodyHtml)];
    }

    /**
     * The imported audience's rows, in file order — what the
     * « Destinataires » page lists for a mail merge.
     *
     * Deliberately the stored rows and not a re-render of the message:
     * the question this screen answers is "who is in the file, and with
     * which values", which is the one a chief checks BEFORE writing.
     * `Controller\MassMailController::mergePreview()` is the other half,
     * and answers "what will THIS person actually receive".
     *
     * @return AudienceRow[]
     */
    public function getAudienceRows(int $audienceId): array
    {
        return $this->audienceRepository->findRowsByAudience($audienceId);
    }

    /**
     * The compose dialog's audience summary (columns for the variable
     * dropdown, row count, first row as sample values) — same access rule
     * as attaching the audience to an email.
     *
     * @return array{audience: \Modules\MassMail\Repository\Audience, sample: array<string, string>}
     * @throws MassMailException when the audience doesn't exist or belongs to someone else
     */
    public function getAudienceSummary(int $audienceId, ?int $actorId, SenderAuthorization $authorization): array
    {
        $this->assertAudienceUsable($audienceId, $actorId, $authorization);
        $audience = $this->audienceRepository->findById($audienceId);
        \assert($audience !== null);

        $firstRow = $this->audienceRepository->findRowByOffset($audienceId, 0);

        return ['audience' => $audience, 'sample' => $firstRow !== null ? $firstRow->data : []];
    }

    /**
     * A mail-merge audience holds imported personal data — it may only be
     * attached to an email (and previewed) by the account that imported
     * it, or by a chef d'unité (or above).
     *
     * @throws MassMailException
     */
    private function assertAudienceUsable(int $audienceId, ?int $actorId, SenderAuthorization $authorization): void
    {
        $audience = $this->audienceRepository->findById($audienceId);
        if ($audience === null) {
            throw new MassMailException('Audience de publipostage introuvable — réimportez le fichier Excel.');
        }
        if (!$authorization->isChefDUniteOrAbove && ($actorId === null || $audience->createdBy !== $actorId)) {
            throw new MassMailException('Cette audience de publipostage a été importée par quelqu\'un d\'autre — '
                . 'réimportez votre propre fichier.');
        }
    }

    /**
     * @throws MassMailException
     */
    private function assertSenderSectionAllowed(int $sectionId, SenderAuthorization $authorization): void
    {
        if ($authorization->isChefDUniteOrAbove) {
            return;
        }
        if ($authorization->forcedSenderSectionId === null || $sectionId !== $authorization->forcedSenderSectionId) {
            throw new MassMailException("Vous ne pouvez envoyer que depuis votre propre section — seul un chef "
                . "d'unité peut choisir une autre section expéditrice.");
        }
    }

    /**
     * @throws MassMailException
     */
    private function assertListSelectionAllowed(
        string $listType,
        ?int $listSectionId,
        SenderAuthorization $authorization
    ): void
    {
        if (!MassMailAccessService::canUseList(
            $authorization->isChefDUniteOrAbove,
            $authorization->allowedListSectionIds,
            $listType,
            $listSectionId
        )) {
            throw new MassMailException("Vous ne pouvez envoyer qu'à la liste de votre section ou à la liste des "
                . "chefs — seul un chef d'unité peut cibler une autre liste.");
        }
    }

    /**
     * At least one year must be selected; a scout year later than the
     * current one may only be selected once Desk has actually been
     * imported for it — module addendum: "il n'est possible de
     * sélectionner l'année suivante que si Desk a déjà été importé pour
     * cette année".
     *
     * @param int[] $scoutYearIds
     * @throws MassMailException
     */
    private function assertScoutYearsSelectable(array $scoutYearIds): void
    {
        if ($scoutYearIds === []) {
            throw new MassMailException('Au moins une année scoute doit être sélectionnée.');
        }

        $current = $this->scoutYearService->getCurrentYear();
        foreach (array_unique($scoutYearIds) as $scoutYearId) {
            $year = $this->scoutYearService->findById($scoutYearId);
            if ($year === null) {
                throw new MassMailException('Année scoute inconnue.');
            }
            if (
                $year['start_date'] > $current['start_date']
                && $this->importJournalRepository->findByYear($scoutYearId) === []
            ) {
                throw new MassMailException("Desk n'a pas encore été importé pour cette année scoute.");
            }
        }
    }

    /**
     * Real chronological order (by scout_years.start_date), most recent
     * first — NOT numeric scout_year_id order, which isn't reliable: a
     * "previous" year's row can be created (via ScoutYearService::
     * ensureYear(), the first time anyone needs it) well after "current"'s,
     * giving it a higher id despite being calendar-earlier.
     *
     * @param int[] $scoutYearIds
     * @return int[]
     */
    private function orderYearsMostRecentFirst(array $scoutYearIds): array
    {
        $years = array_filter(array_map(fn(int $id) => $this->scoutYearService->findById($id), $scoutYearIds));
        usort($years, fn(array $a, array $b) => $b['start_date'] <=> $a['start_date']);

        return array_map(fn(array $year) => $year['id'], $years);
    }

    /**
     * @throws MassMailException when the email doesn't exist
     */
    private function requireEmail(int $id): Email
    {
        $email = $this->emailRepository->findById($id);
        if ($email === null) {
            throw new MassMailException('Email introuvable.');
        }
        return $email;
    }
}
