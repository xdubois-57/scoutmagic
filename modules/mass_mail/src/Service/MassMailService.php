<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\MassMail\Service;

use Core\Config\ScoutYearService;
use Core\File\FileRepository;
use Core\Import\ImportJournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailException;
use Core\Mail\MailService;
use Core\Member\MemberEmailService;
use Core\Member\MemberService;
use Core\Member\SectionService;
use Core\Scheduler\SchedulerService;
use Core\Security\HtmlSanitizer;
use Modules\MassMail\Api\MassMailException;
use Modules\MassMail\Repository\AudienceRepository;
use Modules\MassMail\Repository\AudienceRow;
use Modules\MassMail\Repository\Email;
use Modules\MassMail\Repository\EmailAttachment;
use Modules\MassMail\Repository\EmailAttachmentRepository;
use Modules\MassMail\Repository\EmailRepository;
use Modules\MassMail\Repository\MemberResolutionRepository;
use Modules\MassMail\Repository\Recipient;
use Modules\MassMail\Repository\RecipientRepository;
use Modules\MassMail\Repository\SuppressedAddressRepository;

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
        private RecipientRepository $recipientRepository,
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
        private MergeRenderer $mergeRenderer
    ) {
    }

    /**
     * The email's "From" — always the sender section's own configured
     * address/name (module addendum: never the site's global mail
     * configuration), for both the real batch send and a test send. Falls
     * back to the site's default (null override) only when the section
     * itself has no configured email.
     *
     * @return array{address: ?string, name: ?string}
     */
    public function resolveSenderIdentity(int $sectionId): array
    {
        $section = $this->sectionService->getSection($sectionId);
        $address = $section['email'] ?? null;

        return [
            'address' => $address !== null && $address !== '' ? $address : null,
            'name' => $section['name'] ?? null,
        ];
    }

    public function findById(int $id): ?Email
    {
        return $this->emailRepository->findById($id);
    }

    /**
     * @return array{emails: Email[], total: int, per_page: int}
     */
    public function findFiltered(string $search, ?string $status, ?int $sectionId, int $page): array
    {
        $matchesActiveMembers = $search !== '' && mb_stripos(
            MailingListService::ACTIVE_MEMBERS_LABEL,
            $search
        ) !== false;
        $matchesChiefs = $search !== '' && mb_stripos(MailingListService::CHIEFS_LABEL, $search) !== false;

        $result = $this->emailRepository->findFiltered($search, $status, $sectionId, $matchesActiveMembers,
            $matchesChiefs, $page);

        return ['emails' => $result['emails'], 'total' => $result['total'], 'per_page' => EmailRepository::perPage()];
    }

    /**
     * Recipient count for the list page's row (0 for a draft/test email —
     * the list is only frozen once sending starts).
     */
    public function getRecipientCount(int $emailId): int
    {
        return $this->recipientRepository->countGroupedByStatus($emailId)['total'];
    }

    /**
     * @return array{pending: int, sent: int, error: int, total: int}
     */
    public function getStatusCounts(int $emailId): array
    {
        return $this->recipientRepository->countGroupedByStatus($emailId);
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

        $id = $this->emailRepository->create($subject, $bodyHtml, $sectionId, $listType, $listId, $listSectionId,
            $scoutYearIds, $createdBy, $audienceId);

        $this->journalService->log(
            'mass_mail', 'email_created', 'info', 'Nouvel email de masse créé (brouillon)',
            ['email_id' => $id], $createdBy
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

        $this->emailRepository->update($id, $subject, $bodyHtml, $sectionId, $listType, $listId, $listSectionId,
            $scoutYearIds, $audienceId);

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
            'mass_mail', 'email_status_changed', 'info', 'Email de masse passé en mode test',
            ['email_id' => $id, 'from' => Email::STATUS_DRAFT, 'to' => Email::STATUS_TEST], $actorId
        );

        return $this->requireEmail($id);
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
            'mass_mail', 'email_status_changed', 'info', 'Email de masse revenu en brouillon',
            ['email_id' => $id, 'from' => Email::STATUS_TEST, 'to' => Email::STATUS_DRAFT], $actorId
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
            null,
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
     * @throws MassMailException when the email doesn't exist, isn't a mail-merge, or the offset is out of range
     */
    public function getMergePreview(int $emailId, int $offset): array
    {
        $email = $this->requireEmail($emailId);
        if ($email->listType !== Email::LIST_TYPE_MAIL_MERGE) {
            throw new MassMailException('Cet email n\'est pas un publipostage.');
        }
        $audience = $email->audienceId !== null ? $this->audienceRepository->findById($email->audienceId) : null;
        if ($audience === null) {
            throw new MassMailException('L\'audience de publipostage a été purgée — réimportez le fichier Excel.');
        }

        $offset = max(0, min($offset, $audience->rowCount - 1));
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
     * test → sending. Freezes the recipient list right now (module spec:
     * "le système fige la liste des destinataires") — every member the
     * list resolves to at this exact instant is expanded into ALL of
     * their currently-valid addresses (module addendum, multi-email
     * support: Desk + secondary, via Core\Member\MemberEmailService), one
     * mass_mail_recipients row per address, each address copied in
     * (encrypted) and tagged with the Core\Member\MemberEmail row it maps
     * to (member_email_id — what the one-click unsubscribe link is built
     * from later, in Task\SendBatchHandler). A member with no usable
     * address at all gets a single row written straight to 'error', never
     * 'pending'. The actual sending is left to Task\SendBatchHandler,
     * kicked off here with an immediate first run.
     *
     * @throws MassMailException when the email doesn't exist or isn't in test
     */
    public function startSending(int $id, ?int $actorId): Email
    {
        $email = $this->requireEmail($id);
        if ($email->status !== Email::STATUS_TEST) {
            throw new MassMailException('Seul un email en mode test peut être envoyé.');
        }

        if ($email->listType === Email::LIST_TYPE_MAIL_MERGE) {
            [$validCount, $invalidCount] = $this->freezeMergeRecipients($email);
        } else {
            [$validCount, $invalidCount] = $this->freezeListRecipients($email);
        }

        $this->emailRepository->updateStatus($id, Email::STATUS_SENDING);
        $this->ensureBatchTaskScheduled(true);

        $this->journalService->log(
            'mass_mail', 'email_sending_started', 'info', 'Envoi d\'un email de masse démarré',
            ['email_id' => $id, 'recipient_count' => $validCount, 'invalid_address_count' => $invalidCount], $actorId
        );

        return $this->requireEmail($id);
    }

    /**
     * The classic list freeze — every member the list resolves to right
     * now, expanded into all their currently-valid addresses.
     *
     * @return array{0: int, 1: int} [valid recipient rows, invalid-address rows]
     */
    private function freezeListRecipients(Email $email): array
    {
        $members = $this->mailingListService->resolveMembersForYears(
            $email->listType, $email->listId, $email->listSectionId, $this->orderYearsMostRecentFirst($email->scoutYearIds)
        );

        $validCount = 0;
        $invalidCount = 0;
        foreach ($members as $member) {
            $deskEmail = $member['email'] !== null && filter_var($member['email'], FILTER_VALIDATE_EMAIL) !== false
                ? $member['email']
                : null;
            $addresses = $this->memberEmailService->resolveValidAddressesForMassMail($member['member_id'], $deskEmail);

            if ($addresses === []) {
                $this->recipientRepository->create($email->id, $member['member_id'], $member['scout_year_id'], null,
                    Recipient::STATUS_ERROR, 'Adresse invalide');
                $invalidCount++;
                continue;
            }

            foreach ($addresses as $memberEmail) {
                $this->recipientRepository->create(
                    $email->id, $member['member_id'], $member['scout_year_id'], $memberEmail->email,
                    Recipient::STATUS_PENDING, null, $memberEmail->id
                );
                $validCount++;
            }
        }

        return [$validCount, $invalidCount];
    }

    /**
     * The mail-merge freeze — one email PER AUDIENCE ROW (module spec),
     * never re-resolved from member tables. A row with a Tiers expands to
     * every currently-valid address of that member (same
     * MemberEmailService resolution as any list send, so an unsubscribed
     * member address is honored here too); a row without one sends to its
     * own "Email" column value(s), each first checked against the
     * external suppression list (Repository\SuppressedAddressRepository).
     * Every recipient row keeps its audience_row_id so Task\
     * SendBatchHandler can render this row's own variables.
     *
     * @return array{0: int, 1: int} [valid recipient rows, error rows]
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

        $validCount = 0;
        $invalidCount = 0;
        foreach ($rows as $row) {
            if ($row->memberId !== null) {
                $profile = $mergeMembers[$row->memberId] ?? null;
                $deskEmail = $profile !== null && $profile['email'] !== null && filter_var($profile['email'],
                    FILTER_VALIDATE_EMAIL) !== false
                    ? $profile['email']
                    : null;
                $addresses = $this->memberEmailService->resolveValidAddressesForMassMail($row->memberId, $deskEmail);

                if ($addresses === []) {
                    $this->recipientRepository->create(
                        $email->id, $row->memberId, $profile['scout_year_id'] ?? null, null,
                        Recipient::STATUS_ERROR, 'Adresse invalide', null, $row->id
                    );
                    $invalidCount++;
                    continue;
                }

                foreach ($addresses as $memberEmail) {
                    $this->recipientRepository->create(
                        $email->id, $row->memberId, $profile['scout_year_id'] ?? null, $memberEmail->email,
                        Recipient::STATUS_PENDING, null, $memberEmail->id, $row->id
                    );
                    $validCount++;
                }
                continue;
            }

            foreach ($this->splitRowAddresses($row) as $address) {
                if ($this->suppressedAddressRepository->isSuppressed($address)) {
                    $this->recipientRepository->create(
                        $email->id, null, null, $address,
                        Recipient::STATUS_ERROR, 'Adresse désinscrite des emails groupés', null, $row->id
                    );
                    $invalidCount++;
                    continue;
                }
                $this->recipientRepository->create(
                    $email->id, null, null, $address,
                    Recipient::STATUS_PENDING, null, null, $row->id
                );
                $validCount++;
            }
        }

        return [$validCount, $invalidCount];
    }

    /**
     * @return string[]
     */
    private function splitRowAddresses(AudienceRow $row): array
    {
        if ($row->email === null) {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(';', $row->email)), fn(string $a) => $a !== ''));
    }

    /**
     * Called once per email actually touched by a processed batch (Task\
     * SendBatchHandler) — flips 'sending' → 'sent' the moment no 'pending'
     * row remains for it.
     */
    public function checkAndMarkSentIfComplete(int $emailId, ?int $actorId = null): void
    {
        $email = $this->emailRepository->findById($emailId);
        if ($email === null || $email->status !== Email::STATUS_SENDING) {
            return;
        }
        if ($this->recipientRepository->hasPending($emailId)) {
            return;
        }

        $this->emailRepository->updateStatus($emailId, Email::STATUS_SENT, true);

        $this->journalService->log(
            'mass_mail', 'email_sent', 'info', 'Envoi d\'un email de masse terminé',
            ['email_id' => $emailId], $actorId
        );
    }

    /**
     * Tracking page's "Renvoyer" action — available for both 'error' and
     * already-'sent' recipient rows (module spec). Resets the recipient to
     * 'pending' so the next batch picks it up; if the parent email had
     * already reached the terminal 'sent' status, it's put back to
     * 'sending' so its own status honestly reflects that work remains.
     *
     * @throws MassMailException when the recipient doesn't exist
     */
    public function resendToRecipient(int $recipientId, ?int $actorId): void
    {
        $recipient = $this->recipientRepository->findById($recipientId);
        if ($recipient === null) {
            throw new MassMailException('Destinataire introuvable.');
        }

        $this->recipientRepository->resend($recipientId);

        $email = $this->emailRepository->findById($recipient->emailId);
        if ($email !== null && $email->status === Email::STATUS_SENT) {
            $this->emailRepository->updateStatus($recipient->emailId, Email::STATUS_SENDING);
        }

        $this->ensureBatchTaskScheduled(true);

        $this->journalService->log(
            'mass_mail', 'recipient_resent', 'info', 'Renvoi demandé pour un destinataire',
            ['email_id' => $recipient->emailId, 'recipient_id' => $recipientId], $actorId
        );
    }

    /**
     * @return array{
     *     email: Email,
     *     counts: array{pending: int, sent: int, error: int, total: int},
     *     recipients: array<int, array{recipient: Recipient, display_name: string, section_name: ?string}>
     * }
     * @throws MassMailException when the email doesn't exist
     */
    public function getTrackingData(int $id): array
    {
        $email = $this->requireEmail($id);
        $counts = $this->recipientRepository->countGroupedByStatus($id);
        $rows = $this->recipientRepository->findByEmailId($id);

        // Resolve every member recipient in a fixed number of queries
        // instead of five per recipient (a 600-recipient campaign used to
        // cost ~3000). Grouped by each recipient's OWN resolved year, not
        // the email's set as a whole — a member pulled in via the
        // "previous year" list only has a valid profile for that year, not
        // necessarily the current one.
        $memberIdsByYear = [];
        foreach ($rows as $recipient) {
            if ($recipient->memberId !== null && $recipient->scoutYearId !== null) {
                $memberIdsByYear[$recipient->scoutYearId][] = $recipient->memberId;
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

        $recipients = [];
        foreach ($rows as $recipient) {
            // An external mail-merge recipient (no member) is shown by
            // their address — the only identity they have here.
            if ($recipient->memberId === null || $recipient->scoutYearId === null) {
                $recipients[] = [
                    'recipient' => $recipient,
                    'display_name' => $recipient->emailAddress ?? 'Destinataire externe',
                    'section_name' => null,
                ];
                continue;
            }

            $memberYearId = $memberYearIdByPair[$recipient->scoutYearId . ':' . $recipient->memberId] ?? null;
            $profile = $memberYearId !== null ? ($profiles[$memberYearId] ?? null) : null;
            $recipients[] = [
                'recipient' => $recipient,
                'display_name' => $profile !== null ? $profile->getDisplayName() : 'Membre inconnu',
                'section_name' => $profile?->getMainFunction()?->sectionName,
            ];
        }

        return ['email' => $email, 'counts' => $counts, 'recipients' => $recipients];
    }

    /**
     * @return EmailAttachment[]
     */
    public function getAttachments(int $emailId): array
    {
        return $this->attachmentRepository->findByEmailId($emailId);
    }

    /**
     * @throws MassMailException when the email doesn't exist or isn't a draft
     */
    public function addAttachment(int $emailId, int $fileId): void
    {
        $email = $this->requireEmail($emailId);
        if (!$email->isEditable()) {
            throw new MassMailException('Des pièces jointes ne peuvent être ajoutées qu\'à un brouillon.');
        }

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

        $this->schedulerService->scheduleAfter(self::SCHEDULER_MODULE_ID, self::SCHEDULER_TASK_KEY,
            $runImmediately ? 0 : 60);
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
            Email::LIST_TYPE_MAIL_MERGE,
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
        if (!MassMailAccessService::canUseList($authorization->isChefDUniteOrAbove,
            $authorization->allowedListSectionIds, $listType, $listSectionId)) {
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
