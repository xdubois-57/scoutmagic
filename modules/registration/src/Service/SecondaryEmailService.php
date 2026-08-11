<?php

declare(strict_types=1);

namespace Modules\Registration\Service;

use Core\Journal\JournalService;
use Core\Mail\MailException;
use Core\Mail\MailService;
use Modules\Registration\Repository\RegistrationSecondaryEmail;
use Modules\Registration\Repository\RegistrationSecondaryEmailRepository;
use Twig\Environment;

/**
 * Secondary emails on a registration request — mirrors Core\Member\
 * MemberEmailService's shape and token technique (see schema.sql for why
 * this is module-local rather than a generalization of member_emails).
 * Unlike a member, a request's origin email is never a row in this table
 * at all (it lives on registration_requests itself), so there is no
 * "Desk-sourced, cannot delete" case to special-case here — every row
 * this class touches really is a removable secondary address.
 */
class SecondaryEmailService
{
    private const CONFIRMATION_EXPIRY_HOURS = 48;
    private const RESEND_COOLDOWN_MINUTES = 5;

    public function __construct(
        private RegistrationSecondaryEmailRepository $repository,
        private MailService $mailService,
        private Environment $twig,
        private JournalService $journalService,
        private string $baseUrl,
        private string $siteName
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
     * @throws RegistrationException on an invalid or already-present address
     */
    public function addEmail(int $requestId, string $rawEmail): RegistrationSecondaryEmail
    {
        $normalized = strtolower(trim($rawEmail));
        if (filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
            throw new RegistrationException('Adresse email invalide.');
        }

        $existing = $this->repository->findByRequestAndEmail($requestId, $normalized);
        if ($existing !== null) {
            if ($existing->isPending() && $this->isCooldownElapsed($existing)) {
                $this->resendConfirmation($requestId, $existing->id);
            }
            return $existing;
        }

        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = password_hash($rawToken, PASSWORD_DEFAULT);
        $expiresAt = new \DateTimeImmutable('+' . self::CONFIRMATION_EXPIRY_HOURS . ' hours');

        $id = $this->repository->create($requestId, $normalized, $tokenHash, $expiresAt);
        $created = $this->repository->findById($id);
        \assert($created !== null);

        $this->sendConfirmationEmail($created->id, $normalized, $rawToken);

        $this->journalService->log(
            'registration', 'registration_secondary_email_added', 'info', 'Adresse email secondaire ajoutée',
            ['request_id' => $requestId, 'secondary_email_id' => $created->id]
        );

        return $created;
    }

    /**
     * @throws RegistrationException when the address doesn't belong to
     *         this request or isn't currently inactive
     */
    public function reactivateEmail(int $requestId, int $emailId): RegistrationSecondaryEmail
    {
        $row = $this->requireOwnRow($requestId, $emailId);
        if (!$row->isInactive()) {
            throw new RegistrationException("Cette adresse n'est pas désactivée.");
        }

        $this->repository->reactivate($emailId);

        $this->journalService->log(
            'registration', 'registration_secondary_email_reactivated', 'info', 'Adresse email secondaire réactivée',
            ['request_id' => $requestId, 'secondary_email_id' => $emailId]
        );

        $updated = $this->repository->findById($emailId);
        \assert($updated !== null);
        return $updated;
    }

    /**
     * @throws RegistrationException when the address doesn't belong to this request
     */
    public function removeEmail(int $requestId, int $emailId): void
    {
        $this->requireOwnRow($requestId, $emailId);
        $this->repository->delete($emailId);

        $this->journalService->log(
            'registration', 'registration_secondary_email_removed', 'info', 'Adresse email secondaire supprimée',
            ['request_id' => $requestId, 'secondary_email_id' => $emailId]
        );
    }

    /**
     * Public confirmation-link target — same fail-quietly contract as
     * MemberEmailService::confirmEmail() (no exceptions, no enumeration).
     */
    public function confirmEmail(int $emailId, string $rawToken): bool
    {
        $row = $this->repository->findById($emailId);
        if ($row === null || !$row->isPending() || $row->confirmationTokenHash === null) {
            return false;
        }
        if ($row->confirmationExpiresAt === null || new \DateTimeImmutable() > $row->confirmationExpiresAt) {
            return false;
        }
        if (!password_verify($rawToken, $row->confirmationTokenHash)) {
            return false;
        }

        $this->repository->markValid($emailId);

        $this->journalService->log(
            'registration', 'registration_secondary_email_confirmed', 'info', 'Adresse email secondaire confirmée',
            ['request_id' => $row->registrationRequestId, 'secondary_email_id' => $emailId]
        );

        return true;
    }

    private function resendConfirmation(int $requestId, int $emailId): void
    {
        $row = $this->requireOwnRow($requestId, $emailId);
        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = password_hash($rawToken, PASSWORD_DEFAULT);
        $expiresAt = new \DateTimeImmutable('+' . self::CONFIRMATION_EXPIRY_HOURS . ' hours');

        $this->repository->refreshConfirmation($emailId, $tokenHash, $expiresAt);
        $this->sendConfirmationEmail($emailId, $row->email, $rawToken);
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

        $bodyHtml = $this->twig->render('@registration/email/secondary_email_confirmation.html.twig', $context);
        $bodyText = $this->twig->render('@registration/email/secondary_email_confirmation.text.twig', $context);

        try {
            $this->mailService->send(to: $to, subject: 'Confirmez votre adresse email', bodyHtml: $bodyHtml, bodyText: $bodyText);
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
