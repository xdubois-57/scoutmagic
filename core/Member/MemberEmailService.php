<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member;

use Core\Config\ScoutYearService;
use Core\Journal\JournalService;
use Core\Mail\MailException;
use Core\Mail\MailService;
use Core\Mail\Template\EmailTemplateRenderer;
use Core\Service\TextNormalizerService;

/**
 * Multi-email support per member (login + mass-mail unsubscribe) — see
 * schema/core.sql's member_emails comment for the 'manual'/'desk' source
 * split. Every mutating method takes an explicit $actorId rather than
 * reading Core\Security\AuthSession itself (AGENTS.md: services never
 * touch $_SESSION) — the controller resolves it.
 */
class MemberEmailService
{
    private const CONFIRMATION_EXPIRY_HOURS = 48;
    private const RESEND_COOLDOWN_MINUTES = 5;

    // A member may hold at most this many self-added (non-Desk) addresses.
    // Without a cap, each brand-new address bypasses the per-address cooldown
    // and fires one confirmation email, so a loop over unique addresses is a
    // mail-bomb primitive (audit M15).
    private const MAX_MANUAL_EMAILS = 5;

    public function __construct(
        private MemberEmailRepository $repository,
        private MailService $mailService,
        private EmailTemplateRenderer $emailTemplateRenderer,
        private JournalService $journalService,
        private SectionService $sectionService,
        private MemberService $memberService,
        private ScoutYearService $scoutYearService,
        private string $baseUrl,
        private string $siteName,
        private EmailDomainValidator $emailDomainValidator = new EmailDomainValidator()
    ) {
    }

    /**
     * Member page listing: the Desk address first (synthesized as a
     * virtual, always-'valid' row — id 0 — when it has no status-override
     * row of its own yet, i.e. it was never unsubscribed), then every
     * 'manual' secondary address, most recent first. A stale 'desk'-
     * sourced row left over from an address Desk no longer imports for
     * this member (rare — see schema/core.sql) is deliberately never
     * shown here, since it matches neither $deskEmail's blind index nor
     * source 'manual'.
     *
     * @return MemberEmail[]
     */
    public function listForMember(int $memberId, ?string $deskEmail): array
    {
        $rows = [];

        if ($deskEmail !== null && $deskEmail !== '') {
            $deskOverride = $this->repository->findByMemberAndEmail($memberId, $deskEmail);
            $rows[] = $deskOverride ?? $this->virtualDeskRow($memberId, $deskEmail);
        }

        foreach ($this->repository->findByMember($memberId) as $row) {
            if ($row->source === MemberEmail::SOURCE_MANUAL) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @throws MemberEmailException on an invalid address (format or —
     *         module addendum, "limit typos" — an unresolvable domain)
     * @throws MailException on a confirmation-send failure (the row is
     *         still created/kept either way — "Renvoyer" lets the member
     *         retry)
     */
    public function addEmail(int $memberId, string $rawEmail, ?int $actorId): MemberEmail
    {
        $normalized = strtolower(trim($rawEmail));
        if (filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
            throw new MemberEmailException('Adresse email invalide.');
        }

        $existing = $this->repository->findByMemberAndEmail($memberId, $normalized);
        if ($existing !== null) {
            if ($existing->source === MemberEmail::SOURCE_DESK) {
                throw new MemberEmailException("C'est déjà votre adresse importée depuis Desk.");
            }
            if ($existing->isPending() && $this->isCooldownElapsed($existing)) {
                $this->regenerateAndSendConfirmation($existing, $actorId);
            }
            return $existing;
        }

        // Only genuinely new addresses reach here (the $existing branch above
        // already handled a repeat of the same one), so cap the number a
        // member may accumulate before another confirmation email is sent
        // (audit M15).
        $manualCount = count(array_filter(
            $this->repository->findByMember($memberId),
            static fn(MemberEmail $email) => $email->source === MemberEmail::SOURCE_MANUAL
        ));
        if ($manualCount >= self::MAX_MANUAL_EMAILS) {
            throw new MemberEmailException('Vous avez atteint le nombre maximum d\'adresses email secondaires.');
        }

        // DNS check last, only for a genuinely new address that is within the
        // cap — so a capped member (or a repeat of an existing address) never
        // pays the lookup cost (audit M15).
        if (!$this->emailDomainValidator->hasValidDomain($normalized)) {
            throw new MemberEmailException("Le domaine de cette adresse email n'a pas pu être vérifié — vérifiez qu'il n'y a pas de faute de frappe.");
        }

        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = password_hash($rawToken, PASSWORD_DEFAULT);
        $expiresAt = new \DateTimeImmutable('+' . self::CONFIRMATION_EXPIRY_HOURS . ' hours');

        $id = $this->repository->create($memberId, $normalized, MemberEmail::SOURCE_MANUAL, MemberEmail::STATUS_PENDING, $tokenHash, $expiresAt);
        $created = $this->repository->findById($id);
        \assert($created !== null);

        $this->sendConfirmationEmail($created->id, $memberId, $normalized, $rawToken, $actorId);

        // member_email_id (a numeric FK, not personal data) is what lets
        // this entry be traced back to exactly which address changed
        // without ever putting the address itself — personal data — in
        // the journal (SECURITY.md §11: "reference member_id only").
        $this->journalService->log(
            'core', 'member_email_added', 'info', 'Adresse email secondaire ajoutée',
            ['member_id' => $memberId, 'member_email_id' => $created->id], $actorId
        );

        return $created;
    }

    /**
     * @throws MemberEmailException when the address doesn't belong to this
     *         member, isn't pending, or the resend cooldown hasn't elapsed
     * @throws MailException on a send failure
     */
    public function resendConfirmation(int $memberId, int $emailId, ?int $actorId): MemberEmail
    {
        $row = $this->requireOwnRow($memberId, $emailId);
        if (!$row->isPending()) {
            throw new MemberEmailException('Cette adresse ne peut plus être renvoyée.');
        }
        if (!$this->isCooldownElapsed($row)) {
            throw new MemberEmailException('Veuillez patienter avant de renvoyer un nouvel email de confirmation.');
        }

        $this->regenerateAndSendConfirmation($row, $actorId);

        $this->journalService->log(
            'core', 'member_email_confirmation_resent', 'info', "Renvoi de la confirmation d'une adresse email secondaire",
            ['member_id' => $memberId, 'member_email_id' => $emailId], $actorId
        );

        $updated = $this->repository->findById($emailId);
        \assert($updated !== null);
        return $updated;
    }

    /**
     * @throws MemberEmailException when the address doesn't belong to this
     *         member or is the Desk-imported one
     */
    public function deleteEmail(int $memberId, int $emailId, ?int $actorId): void
    {
        $row = $this->requireOwnRow($memberId, $emailId);
        if ($row->isDeskSourced()) {
            throw new MemberEmailException("L'adresse importée depuis Desk ne peut pas être supprimée.");
        }

        $this->repository->delete($emailId);

        $this->journalService->log(
            'core', 'member_email_deleted', 'info', 'Adresse email secondaire supprimée',
            ['member_id' => $memberId, 'member_email_id' => $emailId], $actorId
        );
    }

    /**
     * @throws MemberEmailException when the address doesn't belong to this
     *         member or isn't currently inactive
     */
    public function reactivateEmail(int $memberId, int $emailId, ?int $actorId): MemberEmail
    {
        $row = $this->requireOwnRow($memberId, $emailId);
        if (!$row->isInactive()) {
            throw new MemberEmailException("Cette adresse n'est pas désinscrite.");
        }

        $this->repository->reactivate($emailId);

        $this->journalService->log(
            'core', 'member_email_reactivated', 'info', 'Adresse email réactivée',
            ['member_id' => $memberId, 'member_email_id' => $emailId], $actorId
        );

        $updated = $this->repository->findById($emailId);
        \assert($updated !== null);
        return $updated;
    }

    /**
     * True when the token would currently confirm this address, WITHOUT
     * consuming it — the GET confirmation page's check. Mail scanners and
     * link prefetchers follow every GET in an email, so the page a bare
     * GET renders must never mutate; only the POST behind its button does
     * (same shape as Modules\MassMail\Controller\UnsubscribeController).
     */
    public function canConfirmEmail(int $emailId, string $rawToken): bool
    {
        return $this->verifiedPendingRow($emailId, $rawToken) !== null;
    }

    /**
     * The confirm page's POST target — public, unauthenticated, must fail
     * gracefully (no exceptions, no account enumeration), same contract as
     * Core\Security\AuthService::verifyMagicLink() returning null on any
     * failure. False for: unknown id, not pending, expired, wrong token —
     * clearing the hash in MemberEmailRepository::markValid() is what
     * makes a second submit with the same token fail here too (single-use).
     */
    public function confirmEmail(int $emailId, string $rawToken): bool
    {
        $row = $this->verifiedPendingRow($emailId, $rawToken);
        if ($row === null) {
            return false;
        }

        $this->repository->markValid($emailId);

        $this->journalService->log(
            'core', 'member_email_confirmed', 'info', 'Adresse email secondaire confirmée',
            ['member_id' => $row->memberId, 'member_email_id' => $emailId], null
        );

        return true;
    }

    /**
     * The shared read-only verification behind canConfirmEmail() and
     * confirmEmail(): the row, iff $rawToken is currently valid for it.
     */
    private function verifiedPendingRow(int $emailId, string $rawToken): ?MemberEmail
    {
        $row = $this->repository->findById($emailId);
        if ($row === null || !$row->isPending() || $row->confirmationTokenHash === null) {
            return null;
        }
        if ($row->confirmationExpiresAt === null || new \DateTimeImmutable() > $row->confirmationExpiresAt) {
            return null;
        }
        if (!password_verify($rawToken, $row->confirmationTokenHash)) {
            return null;
        }

        return $row;
    }

    /**
     * Modules\MassMail\Service\MassMailService's recipient expansion — the
     * Desk address (via its lazily-created status-override row, see
     * MemberEmailRepository::findOrCreateDeskOverride()) plus every valid
     * secondary address. $deskEmail is null when the member has no usable
     * Desk address at all this year.
     *
     * @return MemberEmail[]
     */
    public function resolveValidAddressesForMassMail(int $memberId, ?string $deskEmail): array
    {
        $addresses = [];

        if ($deskEmail !== null && $deskEmail !== '') {
            $deskRow = $this->repository->findOrCreateDeskOverride($memberId, $deskEmail);
            if ($deskRow->isValid()) {
                $addresses[] = $deskRow;
            }
        }

        foreach ($this->repository->findValidByMember($memberId) as $row) {
            $addresses[] = $row;
        }

        return $addresses;
    }

    /**
     * The unsubscribe action itself (Modules\MassMail's one-click endpoint
     * and its human confirm-page both call this) — idempotent, since a
     * mailbox prefetch, a real click, and a resubmit may all fire it for
     * the same row. Module addendum: unsubscribing applies to the
     * *address*, not just the one member profile a particular mass-mail
     * send happened to be tied to — the same address can legitimately be
     * linked to several members (e.g. a parent's address added to more
     * than one child), so every member_emails row sharing it is marked
     * inactive together. This also revokes the address's ability to log
     * in — Core\Security\RoleResolver checks this same 'inactive' status
     * even for a 'desk'-sourced row, unlike every other Desk-address rule
     * (see schema/core.sql) — being unable to log in AND no longer
     * receiving unit communications is significant enough that it always
     * triggers the two notification emails below, never silently.
     */
    public function unsubscribe(int $memberEmailId): void
    {
        $triggerRow = $this->repository->findById($memberEmailId);
        if ($triggerRow === null) {
            return;
        }

        $validRows = array_filter($this->repository->findAllByEmail($triggerRow->email), fn(MemberEmail $r) => $r->isValid());
        if ($validRows === []) {
            // Already fully unsubscribed everywhere — idempotent no-op,
            // including no duplicate notification emails.
            return;
        }

        $currentYear = $this->scoutYearService->getCurrentYear();
        $affectedMemberNames = [];
        foreach ($validRows as $row) {
            $this->repository->markInactive($row->id);
            $this->journalService->log(
                'core', 'member_email_unsubscribed', 'info', 'Adresse email désinscrite des emails groupés',
                ['member_id' => $row->memberId, 'member_email_id' => $row->id], null
            );

            $profile = $this->memberService->findProfileByMemberAndYear($row->memberId, (int) $currentYear['id']);
            if ($profile !== null) {
                $affectedMemberNames[] = trim(
                    TextNormalizerService::normalizeName($profile->firstName) . ' ' . TextNormalizerService::normalizeName($profile->lastName)
                );
            }
        }

        $this->sendUnsubscribeNotifications($triggerRow->email, $affectedMemberNames);
    }

    private function virtualDeskRow(int $memberId, string $deskEmail): MemberEmail
    {
        $now = new \DateTimeImmutable();
        return new MemberEmail(
            id: 0,
            memberId: $memberId,
            email: $deskEmail,
            source: MemberEmail::SOURCE_DESK,
            status: MemberEmail::STATUS_VALID,
            confirmationTokenHash: null,
            confirmationExpiresAt: null,
            lastConfirmationSentAt: null,
            confirmedAt: null,
            deactivatedAt: null,
            createdAt: $now
        );
    }

    /**
     * Member page display only (the real enforcement is
     * resendConfirmation()'s own isCooldownElapsed() check) — minutes
     * left before "Renvoyer" becomes available again, 0 once it is.
     */
    public function resendCooldownRemainingMinutes(MemberEmail $row): int
    {
        if ($this->isCooldownElapsed($row)) {
            return 0;
        }

        \assert($row->lastConfirmationSentAt !== null);
        $cooldownEnd = $row->lastConfirmationSentAt->modify('+' . self::RESEND_COOLDOWN_MINUTES . ' minutes');
        $remainingSeconds = $cooldownEnd->getTimestamp() - (new \DateTimeImmutable())->getTimestamp();

        return (int) max(1, ceil($remainingSeconds / 60));
    }

    private function isCooldownElapsed(MemberEmail $row): bool
    {
        if ($row->lastConfirmationSentAt === null) {
            return true;
        }

        $cooldownEnd = $row->lastConfirmationSentAt->modify('+' . self::RESEND_COOLDOWN_MINUTES . ' minutes');
        return new \DateTimeImmutable() >= $cooldownEnd;
    }

    private function regenerateAndSendConfirmation(MemberEmail $row, ?int $actorId): void
    {
        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = password_hash($rawToken, PASSWORD_DEFAULT);
        $expiresAt = new \DateTimeImmutable('+' . self::CONFIRMATION_EXPIRY_HOURS . ' hours');

        $this->repository->refreshConfirmation($row->id, $tokenHash, $expiresAt);
        $this->sendConfirmationEmail($row->id, $row->memberId, $row->email, $rawToken, $actorId);
    }

    /**
     * @throws MailException on a send failure — journaled first (with the
     *         technical reason, e.g. "SMTP connect() failed", so a
     *         misconfigured mail transport is actually diagnosable from
     *         the journal instead of just a generic flash message) then
     *         rethrown so the caller's existing "row kept, retry with
     *         Renvoyer" handling is unaffected. $to is stripped out of the
     *         logged reason even though PHPMailer's ErrorInfo rarely
     *         includes it, since SECURITY.md §11 bars personal data from
     *         journal entries unconditionally.
     */
    private function sendConfirmationEmail(int $emailId, int $memberId, string $to, string $rawToken, ?int $actorId): void
    {
        $confirmUrl = rtrim($this->baseUrl, '/') . "/members/emails/confirm/{$emailId}?token={$rawToken}";
        $context = [
            'site_name' => $this->siteName,
            'confirm_url' => $confirmUrl,
            'expiry_hours' => self::CONFIRMATION_EXPIRY_HOURS,
        ];

        // Declared `editable: false` (Core\Mail\Template) — an address
        // confirmation an administrator could reword is a way to lock
        // somebody out — so this always renders the shipped template. It
        // goes through the renderer anyway so there is one rendering path.
        $email = $this->emailTemplateRenderer->render('member_email_confirmation', $context);

        try {
            $this->mailService->send(to: $to, subject: $email->subject, bodyHtml: $email->bodyHtml, bodyText: $email->bodyText);
        } catch (MailException $e) {
            $reason = str_replace($to, '[adresse]', $e->getMessage());
            $this->journalService->log(
                'core', 'member_email_confirmation_send_failed', 'info',
                "Échec de l'envoi de l'email de confirmation d'une adresse email secondaire",
                ['member_id' => $memberId, 'member_email_id' => $emailId, 'error' => $reason], $actorId
            );
            throw $e;
        }
    }

    /**
     * Two best-effort emails (a send failure must never undo the
     * unsubscribe itself, which has already happened by the time this
     * runs): one to the Staff d'U section's configured address, flagging
     * that the affected member(s) can no longer be reached by mass-mail
     * or log in with this address — which may also signal they want to
     * leave the unit — and one to the unsubscribed address itself,
     * confirming what happened and how to undo it if it was a mistake.
     *
     * @param string[] $affectedMemberNames
     */
    private function sendUnsubscribeNotifications(string $unsubscribedEmail, array $affectedMemberNames): void
    {
        $context = [
            'site_name' => $this->siteName,
            'unsubscribed_email' => $unsubscribedEmail,
            'member_names' => $affectedMemberNames,
        ];

        $staffduSection = $this->sectionService->findByDeskCode(UnitStaffSectionService::DESK_CODE);
        $staffduEmail = $staffduSection['email'] ?? null;
        if ($staffduEmail !== null && $staffduEmail !== '') {
            $staffduEmailMessage = $this->emailTemplateRenderer->render('member_email_unsubscribe_staffdu', $context);

            try {
                $this->mailService->send(
                    to: $staffduEmail,
                    subject: $staffduEmailMessage->subject,
                    bodyHtml: $staffduEmailMessage->bodyHtml,
                    bodyText: $staffduEmailMessage->bodyText
                );
            } catch (MailException) {
                // Best-effort — the unsubscribe itself already succeeded.
            }
        }

        $confirmation = $this->emailTemplateRenderer->render(
            'member_email_unsubscribe_confirmation',
            $context + ['staffdu_email' => $staffduEmail]
        );

        try {
            $this->mailService->send(
                to: $unsubscribedEmail,
                subject: $confirmation->subject,
                bodyHtml: $confirmation->bodyHtml,
                bodyText: $confirmation->bodyText
            );
        } catch (MailException) {
            // Best-effort, same reasoning.
        }
    }

    private function requireOwnRow(int $memberId, int $emailId): MemberEmail
    {
        $row = $this->repository->findById($emailId);
        if ($row === null || $row->memberId !== $memberId) {
            throw new MemberEmailException('Adresse introuvable.');
        }
        return $row;
    }
}
