<?php

declare(strict_types=1);

namespace Modules\MassMail\Service;

use Core\Config\ScoutYearService;
use Core\File\FileRepository;
use Core\Import\ImportJournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailException;
use Core\Mail\MailService;
use Core\Member\MemberService;
use Core\Member\SectionService;
use Core\Scheduler\SchedulerService;
use Core\Security\HtmlSanitizer;
use Modules\MassMail\Repository\Email;
use Modules\MassMail\Repository\EmailAttachment;
use Modules\MassMail\Repository\EmailAttachmentRepository;
use Modules\MassMail\Repository\EmailRepository;
use Modules\MassMail\Repository\Recipient;
use Modules\MassMail\Repository\RecipientRepository;

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
        private SectionService $sectionService,
        private MailService $mailService,
        private SchedulerService $schedulerService,
        private JournalService $journalService,
        private HtmlSanitizer $htmlSanitizer,
        private ScoutYearService $scoutYearService,
        private ImportJournalRepository $importJournalRepository,
        private string $storagePath
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
        $matchesActiveMembers = $search !== '' && mb_stripos(MailingListService::ACTIVE_MEMBERS_LABEL, $search) !== false;
        $matchesChiefs = $search !== '' && mb_stripos(MailingListService::CHIEFS_LABEL, $search) !== false;

        $result = $this->emailRepository->findFiltered($search, $status, $sectionId, $matchesActiveMembers, $matchesChiefs, $page);

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
     * @throws MassMailException on invalid input, an unauthorized sender section/list, or an unimported scout year
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
        SenderAuthorization $authorization
    ): Email {
        [$subject, $bodyHtml] = $this->validateAndSanitize($subject, $bodyHtml, $listType, $listId, $listSectionId);
        $this->assertSenderSectionAllowed($sectionId, $authorization);
        $this->assertListSelectionAllowed($listType, $listSectionId, $authorization);
        $this->assertScoutYearsSelectable($scoutYearIds);

        $id = $this->emailRepository->create($subject, $bodyHtml, $sectionId, $listType, $listId, $listSectionId, $scoutYearIds, $createdBy);

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
     * @param int[] $scoutYearIds At least one.
     * @throws MassMailException when the email doesn't exist, isn't a draft, input is invalid, or a changed
     *                            sender section/list/scout year isn't authorized
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
        SenderAuthorization $authorization
    ): Email {
        $email = $this->requireEmail($id);
        if (!$email->isEditable()) {
            throw new MassMailException('Cet email ne peut plus être modifié.');
        }

        [$subject, $bodyHtml] = $this->validateAndSanitize($subject, $bodyHtml, $listType, $listId, $listSectionId);

        if ($sectionId !== $email->sectionId) {
            $this->assertSenderSectionAllowed($sectionId, $authorization);
        }
        if ($listType !== $email->listType || $listId !== $email->listId || $listSectionId !== $email->listSectionId) {
            $this->assertListSelectionAllowed($listType, $listSectionId, $authorization);
        }
        $normalizedNew = $scoutYearIds;
        sort($normalizedNew);
        $normalizedOld = $email->scoutYearIds;
        sort($normalizedOld);
        if ($normalizedNew !== $normalizedOld) {
            $this->assertScoutYearsSelectable($scoutYearIds);
        }

        $this->emailRepository->update($id, $subject, $bodyHtml, $sectionId, $listType, $listId, $listSectionId, $scoutYearIds);

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
    public function sendTestEmail(int $id, string $toAddress): void
    {
        $email = $this->requireEmail($id);
        if ($email->status !== Email::STATUS_TEST) {
            throw new MassMailException('L\'envoi de test n\'est disponible qu\'en mode test.');
        }
        if (filter_var($toAddress, FILTER_VALIDATE_EMAIL) === false) {
            throw new MassMailException('Adresse email invalide.');
        }

        $sender = $this->resolveSenderIdentity($email->sectionId);

        $this->mailService->send(
            $toAddress,
            '[TEST] ' . $email->subject,
            $email->bodyHtml,
            strip_tags($email->bodyHtml),
            null,
            $this->buildAttachmentPayload($id),
            $sender['address'],
            $sender['name']
        );
    }

    /**
     * test → sending. Freezes the recipient list right now (module spec:
     * "le système fige la liste des destinataires") — every member the
     * list resolves to at this exact instant becomes one
     * mass_mail_recipients row, with their address copied in (encrypted).
     * A member with no usable address is written straight to 'error',
     * never 'pending'. The actual sending is left to Task\
     * SendBatchHandler, kicked off here with an immediate first run.
     *
     * @throws MassMailException when the email doesn't exist or isn't in test
     */
    public function startSending(int $id, ?int $actorId): Email
    {
        $email = $this->requireEmail($id);
        if ($email->status !== Email::STATUS_TEST) {
            throw new MassMailException('Seul un email en mode test peut être envoyé.');
        }

        $members = $this->mailingListService->resolveMembersForYears(
            $email->listType, $email->listId, $email->listSectionId, $this->orderYearsMostRecentFirst($email->scoutYearIds)
        );

        $validCount = 0;
        $invalidCount = 0;
        foreach ($members as $member) {
            $address = $member['email'];
            $isValid = $address !== null && filter_var($address, FILTER_VALIDATE_EMAIL) !== false;
            if ($isValid) {
                $this->recipientRepository->create($id, $member['member_id'], $member['scout_year_id'], $address, Recipient::STATUS_PENDING, null);
                $validCount++;
            } else {
                $this->recipientRepository->create($id, $member['member_id'], $member['scout_year_id'], null, Recipient::STATUS_ERROR, 'Adresse invalide');
                $invalidCount++;
            }
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
     * @return array{email: Email, counts: array{pending: int, sent: int, error: int, total: int}, recipients: array<int, array{recipient: Recipient, display_name: string, section_name: ?string}>}
     * @throws MassMailException when the email doesn't exist
     */
    public function getTrackingData(int $id): array
    {
        $email = $this->requireEmail($id);
        $counts = $this->recipientRepository->countGroupedByStatus($id);

        $recipients = [];
        foreach ($this->recipientRepository->findByEmailId($id) as $recipient) {
            // Each recipient's own resolved year, not the email's set as a
            // whole — a member pulled in via the "previous year" list only
            // has a valid profile for that year, not necessarily the
            // current one.
            $profile = $this->memberService->findProfileByMemberAndYear($recipient->memberId, $recipient->scoutYearId);
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

        $this->schedulerService->scheduleAfter(self::SCHEDULER_MODULE_ID, self::SCHEDULER_TASK_KEY, $runImmediately ? 0 : 60);
    }

    /**
     * @return array{0: string, 1: string} [subject, sanitized body]
     * @throws MassMailException
     */
    private function validateAndSanitize(string $subject, string $bodyHtml, string $listType, ?int $listId, ?int $listSectionId): array
    {
        $subject = trim($subject);
        if ($subject === '') {
            throw new MassMailException('Le sujet est obligatoire.');
        }
        if (!in_array($listType, [
            Email::LIST_TYPE_DEFAULT_SECTION, Email::LIST_TYPE_DEFAULT_ACTIVE_MEMBERS,
            Email::LIST_TYPE_DEFAULT_CHIEFS, Email::LIST_TYPE_CUSTOM,
        ], true)) {
            throw new MassMailException('Type de liste invalide.');
        }
        if ($listType === Email::LIST_TYPE_DEFAULT_SECTION && $listSectionId === null) {
            throw new MassMailException('Section de liste manquante.');
        }
        if ($listType === Email::LIST_TYPE_CUSTOM && $listId === null) {
            throw new MassMailException('Liste personnalisée manquante.');
        }

        return [$subject, $this->htmlSanitizer->sanitize($bodyHtml)];
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
            throw new MassMailException("Vous ne pouvez envoyer que depuis votre propre section — seul un chef d'unité peut choisir une autre section expéditrice.");
        }
    }

    /**
     * @throws MassMailException
     */
    private function assertListSelectionAllowed(string $listType, ?int $listSectionId, SenderAuthorization $authorization): void
    {
        if (!MassMailAccessService::canUseList($authorization->isChefDUniteOrAbove, $authorization->allowedListSectionIds, $listType, $listSectionId)) {
            throw new MassMailException("Vous ne pouvez envoyer qu'à la liste de votre section ou à la liste des chefs — seul un chef d'unité peut cibler une autre liste.");
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
            if ($year['start_date'] > $current['start_date'] && $this->importJournalRepository->findByYear($scoutYearId) === []) {
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
