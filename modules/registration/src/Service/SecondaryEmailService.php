<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Service;

use Core\Journal\JournalService;
use Core\Mail\MailException;
use Core\Mail\MailService;
use Core\Mail\Template\EmailTemplateRenderer;
use Core\Security\CapabilityToken;
use Modules\Registration\Repository\RegistrationSecondaryEmail;
use Modules\Registration\Repository\RegistrationSecondaryEmailRepository;

/**
 * Secondary emails on a registration request — mirrors Core\Member\
 * MemberEmailService's shape and token technique (see schema.sql for why
 * this is module-local rather than a generalization of member_emails).
 * Unlike a member, a request's origin email is never a row in this table
 * at all (it lives on registration_requests itself), so there is no
 * "Desk-sourced, cannot delete" case to special-case here — every row
 * this class touches really is a removable secondary address.
 *
 * Two bounds, because the tracking link that reaches this class is held by
 * whoever filed a public form (issue #1126): at most MAX_SECONDARY_EMAILS
 * addresses per request, and at most ConfirmationSendLimiter::LIMIT
 * confirmation e-mails per request per hour — the second one because a
 * removed address takes its row with it, so remove-then-add never meets
 * the first. The confirmation token is stored as a SHA-256
 * (Core\Security\CapabilityToken::hash()), not a password_hash().
 */
class SecondaryEmailService
{
    private const CONFIRMATION_EXPIRY_HOURS = 48;
    private const RESEND_COOLDOWN_MINUTES = 5;

    /**
     * Rows of registration_secondary_emails a request may hold, whatever
     * their status — the same number as Core\Member\MemberEmailService::
     * MAX_MANUAL_EMAILS.
     */
    public const MAX_SECONDARY_EMAILS = 5;

    public function __construct(
        private RegistrationSecondaryEmailRepository $repository,
        private MailService $mailService,
        private EmailTemplateRenderer $emailTemplateRenderer,
        private JournalService $journalService,
        private string $baseUrl,
        private string $siteName,
        private ConfirmationSendLimiter $confirmationSendLimiter
    ) {
    }

    /**
     * @return array<RegistrationSecondaryEmail>
     */
    public function listForRequest(int $requestId): array
    {
        return $this->repository->findByRequest($requestId);
    }

    /**
     * Adding an address already on the request returns its row and, when it
     * is still pending and the resend cooldown has elapsed, re-sends its
     * confirmation — unless the hourly limit is spent, in which case the
     * row is returned without a new e-mail, as within the cooldown.
     *
     * @throws RegistrationException on an invalid address, when the request
     *         already holds MAX_SECONDARY_EMAILS addresses, or when its hourly
     *         budget of confirmation e-mails is spent — the last two before
     *         anything is hashed, written or sent
     */
    public function addEmail(int $requestId, string $rawEmail): RegistrationSecondaryEmail
    {
        $normalized = strtolower(trim($rawEmail));
        if (filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
            throw new RegistrationException('Adresse email invalide.');
        }

        // The decision — cap, hourly budget, the row and its token — is taken
        // under a lock on the request's own row, so two concurrent additions
        // cannot both read « one left » and both pass (issue #1126). The
        // e-mail leaves only after the commit: a rolled-back row never mails.
        /** @var array{RegistrationSecondaryEmail, ?string, bool} $outcome */
        $outcome = $this->repository->transactional(function () use ($requestId, $normalized): array {
            $this->repository->lockRequest($requestId);

            $existing = $this->repository->findByRequestAndEmail($requestId, $normalized);
            if ($existing !== null) {
                if (
                    $existing->isPending()
                    && $this->isCooldownElapsed($existing)
                    && $this->confirmationSendLimiter->tryAcquire($requestId)
                ) {
                    return [$existing, $this->issueToken($existing->id), false];
                }

                return [$existing, null, false];
            }

            // Only a genuinely new address reaches here. Every row counts, a
            // pending or an inactive one too: a pending address is one more
            // stranger who received a confirmation e-mail.
            if (count($this->repository->findByRequest($requestId)) >= self::MAX_SECONDARY_EMAILS) {
                throw new RegistrationException(
                    'Vous avez atteint le nombre maximum d\'adresses email secondaires pour cette demande.'
                );
            }
            if (!$this->confirmationSendLimiter->tryAcquire($requestId)) {
                throw new RegistrationException(
                    'Trop d\'emails de confirmation envoyés pour cette demande — réessayez dans une heure.'
                );
            }

            $rawToken = CapabilityToken::generate();
            $expiresAt = new \DateTimeImmutable('+' . self::CONFIRMATION_EXPIRY_HOURS . ' hours');
            $id = $this->repository->create($requestId, $normalized, CapabilityToken::hash($rawToken), $expiresAt);
            $created = $this->repository->findById($id);
            \assert($created !== null);

            return [$created, $rawToken, true];
        });
        [$row, $rawToken, $isNew] = $outcome;

        if ($rawToken !== null) {
            $this->sendConfirmationEmail($row->id, $row->email, $rawToken);
        }
        if ($isNew) {
            $this->journalService->log(
                'registration',
                'registration_secondary_email_added',
                'info',
                'Adresse email secondaire ajoutée',
                ['request_id' => $requestId, 'secondary_email_id' => $row->id]
            );
        }

        return $row;
    }

    /**
     * @throws RegistrationException when the address doesn't belong to this request
     */
    public function removeEmail(int $requestId, int $emailId): void
    {
        $this->requireOwnRow($requestId, $emailId);
        $this->repository->delete($emailId);

        $this->journalService->log(
            'registration',
            'registration_secondary_email_removed',
            'info',
            'Adresse email secondaire supprimée',
            ['request_id' => $requestId, 'secondary_email_id' => $emailId]
        );
    }

    /**
     * True when the token would currently confirm this address, WITHOUT
     * consuming it — the GET confirmation page's check (mail scanners
     * prefetch every link, so the bare GET must never mutate; same shape
     * as MemberEmailService::canConfirmEmail()).
     */
    public function canConfirmEmail(int $emailId, string $rawToken): bool
    {
        return $this->verifiedPendingRow($emailId, $rawToken) !== null;
    }

    /**
     * The confirm page's POST target — same fail-quietly contract as
     * MemberEmailService::confirmEmail() (no exceptions, no enumeration).
     */
    public function confirmEmail(int $emailId, string $rawToken): bool
    {
        $row = $this->verifiedPendingRow($emailId, $rawToken);
        if ($row === null) {
            return false;
        }

        $this->repository->markValid($emailId);

        $this->journalService->log(
            'registration',
            'registration_secondary_email_confirmed',
            'info',
            'Adresse email secondaire confirmée',
            ['request_id' => $row->registrationRequestId, 'secondary_email_id' => $emailId]
        );

        return true;
    }

    /**
     * The shared read-only verification behind canConfirmEmail() and
     * confirmEmail(): the row, iff $rawToken is currently valid for it.
     */
    private function verifiedPendingRow(int $emailId, string $rawToken): ?RegistrationSecondaryEmail
    {
        $row = $this->repository->findById($emailId);
        if ($row === null || !$row->isPending() || $row->confirmationTokenHash === null) {
            return null;
        }
        if ($row->confirmationExpiresAt === null || new \DateTimeImmutable() > $row->confirmationExpiresAt) {
            return null;
        }
        if (!CapabilityToken::verifyAgainstHash($rawToken, $row->confirmationTokenHash)) {
            return null;
        }

        return $row;
    }

    /**
     * A fresh confirmation token for a pending row, written in place of the
     * old one; the caller sends it once its transaction has committed.
     */
    private function issueToken(int $emailId): string
    {
        $rawToken = CapabilityToken::generate();
        $expiresAt = new \DateTimeImmutable('+' . self::CONFIRMATION_EXPIRY_HOURS . ' hours');
        $this->repository->refreshConfirmation($emailId, CapabilityToken::hash($rawToken), $expiresAt);

        return $rawToken;
    }

    private function isCooldownElapsed(RegistrationSecondaryEmail $row): bool
    {
        if ($row->lastConfirmationSentAt === null) {
            return true;
        }
        $cooldownEnd = $row->lastConfirmationSentAt->modify('+' . self::RESEND_COOLDOWN_MINUTES . ' minutes');

        return new \DateTimeImmutable() >= $cooldownEnd;
    }

    private function sendConfirmationEmail(int $emailId, string $to, string $rawToken): void
    {
        $confirmUrl = rtrim($this->baseUrl, '/') . "/inscriptions/suivi/emails/confirm/{$emailId}?token={$rawToken}";
        $context = [
            'site_name' => $this->siteName,
            'confirm_url' => $confirmUrl,
            'expiry_hours' => self::CONFIRMATION_EXPIRY_HOURS,
        ];

        // Declared `editable: false` (ARCHITECTURE.md §8.7bis) — an
        // address confirmation an administrator could reword is a way to
        // lock somebody out of their own request — so this always renders
        // the shipped template. It goes through the renderer anyway so
        // there is one rendering path, exactly as core's own confirmation
        // e-mail does.
        $email = $this->emailTemplateRenderer->render('registration.secondary_email_confirmation', $context);

        try {
            $this->mailService->send(
                to: $to,
                subject: $email->subject,
                bodyHtml: $email->bodyHtml,
                bodyText: $email->bodyText
            );
        } catch (MailException) {
            // Best-effort, same rationale as the module's other emails —
            // the row is kept either way so the parent can be re-sent one.
        }
    }

    private function requireOwnRow(int $requestId, int $emailId): RegistrationSecondaryEmail
    {
        $row = $this->repository->findById($emailId);
        if ($row === null || $row->registrationRequestId !== $requestId) {
            throw new RegistrationException('Adresse introuvable.');
        }

        return $row;
    }
}
