<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Security;

use Core\Config\AppClock;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Mail\Template\EmailTemplateRenderer;
use Core\Notification\NotificationService;

/**
 * Tells an account's holder that a way into their account was just added,
 * changed or removed (issue #1115, audit SEC-018) — a first password, a
 * new password, a passkey added or removed, a contact-synchronisation
 * device credential created.
 *
 * Each of those used to happen in silence, so an attacker holding a
 * stolen session could give themselves a password or a passkey of their
 * own and keep the account after the session was gone, with nothing ever
 * reaching the real holder. The holder now hears about it three ways:
 *
 * - **an e-mail to the account's own address**, through the register
 *   (`account_security_change`, declared `editable: false` like the other
 *   authentication e-mails: a unit that reworded it into silence would
 *   switch the alarm off). It names what happened and when, and says what
 *   to do if it was not them. It never carries an IP address;
 * - **an in-app notification**, through NotificationService::notify()
 *   — the direct, untyped path, so there is no preference row anybody can
 *   switch it off with;
 * - **a `security` journal entry**, holding identifiers only — never the
 *   address, never a label somebody typed (SECURITY.md §11).
 *
 * **An alert never undoes or fails the action it reports.** The change has
 * already happened when this runs: a mail server being down must not turn
 * a password that was set into an error page. A failed e-mail or
 * notification is journaled as a warning, without anything personal, and
 * the caller carries on.
 */
class AccountSecurityAlertService
{
    public const TEMPLATE_ID = 'account_security_change';

    public function __construct(
        private UserAccountRepository $userAccounts,
        private MailService $mailService,
        private EmailTemplateRenderer $renderer,
        private string $siteName,
        private string $baseUrl,
        // Trailing and optional, like every other late collaborator in
        // this codebase: without a journal nothing is recorded, without a
        // notification centre only the e-mail goes out.
        private ?JournalService $journal = null,
        private ?NotificationService $notifications = null
    ) {
    }

    /**
     * Report $change on account $userAccountId. $credentialId is the
     * passkey's or device credential's row id (journaled — an identifier,
     * never personal); $credentialLabel is the name its holder gave it,
     * shown to that holder in the e-mail and nowhere else.
     *
     * Never throws, whatever fails inside: see the class docblock.
     */
    public function report(
        int $userAccountId,
        AccountSecurityChange $change,
        ?int $credentialId = null,
        ?string $credentialLabel = null
    ): void {
        $entry = $change->journalEntry();
        if ($entry !== null) {
            try {
                $this->journal?->log(
                    'core',
                    $entry['action'],
                    'security',
                    $entry['description'],
                    $credentialId !== null ? ['credential_id' => $credentialId] : [],
                    $userAccountId
                );
            } catch (\Throwable) {
                // The change has happened; throwing now would only turn it
                // into an error page — or, for a device credential, lose
                // the one copy of its secret — without filling the gap.
            }
        }

        try {
            $this->sendEmail($userAccountId, $change, $credentialLabel);
        } catch (\Throwable) {
            $this->journalFailure($userAccountId, $change, 'email');
        }

        try {
            $this->notifications?->notify(
                $userAccountId,
                'Alerte de sécurité',
                $change->label() . '. Si ce n\'est pas vous, réinitialisez votre mot de passe et retirez ce que '
                    . 'vous ne reconnaissez pas dans « Mon compte ».',
                '/account'
            );
        } catch (\Throwable) {
            $this->journalFailure($userAccountId, $change, 'notification');
        }
    }

    private function sendEmail(int $userAccountId, AccountSecurityChange $change, ?string $credentialLabel): void
    {
        $account = $this->userAccounts->findById($userAccountId);
        if ($account === null || $account->email === '') {
            return;
        }

        $baseUrl = rtrim($this->baseUrl, '/');
        $email = $this->renderer->render(self::TEMPLATE_ID, [
            'site_name' => $this->siteName,
            'change_label' => $change->label(),
            // The site's own time zone, whatever the server's: the holder
            // compares it with what they were doing at that moment.
            'changed_at' => AppClock::now()->format('d/m/Y à H:i'),
            'credential_label' => trim((string) $credentialLabel),
            'login_url' => $baseUrl . '/login',
            'account_url' => $baseUrl . '/account',
        ]);

        $this->mailService->send(
            to: $account->email,
            subject: $email->subject,
            bodyHtml: $email->bodyHtml,
            bodyText: $email->bodyText
        );
    }

    /**
     * Never more than which alert and which channel: the reason is
     * MailService's to journal (it does), and the address is personal.
     */
    private function journalFailure(int $userAccountId, AccountSecurityChange $change, string $channel): void
    {
        try {
            $this->journal?->log(
                'core',
                'account_security_alert_failed',
                'warning',
                $channel === 'email'
                    ? 'Alerte de sécurité non envoyée par email au titulaire du compte'
                    : 'Alerte de sécurité non notifiée au titulaire du compte',
                ['change' => $change->value, 'channel' => $channel],
                $userAccountId
            );
        } catch (\Throwable) {
            // Nothing left to report it to; the action itself stands.
        }
    }
}
