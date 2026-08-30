<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Security;

use Core\Mail\MailException;
use Core\Mail\MailService;
use Twig\Environment;

/**
 * The three emails a change to a super-admin access owes the person it
 * concerns.
 *
 * All of them go through MailService::send(), which owns the subject
 * prefix, the DKIM signature, the multipart body and the delivery mode
 * (AGENTS.md § Email) — nothing here sends anything itself.
 *
 * None of them is a request: the promotion mail is a notification, not
 * an invitation to accept. There is no acceptance step anywhere in this
 * feature, because clicking the magic link at the first connection is
 * already the proof that the address belongs to the person, and a second
 * ceremony would prove nothing more.
 *
 * A send failure never undoes the change, which has already happened by
 * the time this runs: the site refusing to record a withdrawal because a
 * mail server was down would be worse than a withdrawal nobody was
 * emailed about. Failures are swallowed here and left to MailService's
 * own journaling.
 *
 * Why a deactivation mail exists at all (the chantier left the call
 * open): a deactivation cuts the person off mid-session, at the very
 * next click. Somebody who is not told reads that as the site being
 * broken and goes looking for help. A withdrawal of the right, by
 * contrast, is silent from their side until they try to open a
 * configuration page — and it still gets its own mail for the same
 * reason. Reactivation gets none: the access simply works again, which
 * needs no warning.
 */
class SuperAdminMailer
{
    public function __construct(
        private MailService $mailService,
        private Environment $twig,
        private string $siteName,
        private string $baseUrl
    ) {
    }

    /**
     * $grantedByLabel names who did it — an address, or a generic phrase
     * when the change had no identified author behind it. It is the one
     * question the recipient actually has ("who gave me this?"), so the
     * mail answers it rather than arriving anonymously.
     */
    public function sendGranted(string $to, ?string $grantedByLabel): void
    {
        $this->send($to, 'Vous êtes administrateur du site', 'super_admin_granted', [
            'granted_by' => $grantedByLabel,
        ]);
    }

    public function sendRevoked(string $to): void
    {
        $this->send($to, "Votre accès administrateur a été retiré", 'super_admin_revoked', []);
    }

    public function sendDeactivated(string $to): void
    {
        $this->send($to, 'Votre accès au site a été suspendu', 'super_admin_deactivated', []);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function send(string $to, string $subject, string $template, array $context): void
    {
        $context += [
            'site_name' => $this->siteName,
            'login_url' => rtrim($this->baseUrl, '/') . '/login',
        ];

        try {
            $this->mailService->send(
                to: $to,
                subject: $subject,
                bodyHtml: $this->twig->render("email/{$template}.html.twig", $context),
                bodyText: $this->twig->render("email/{$template}.text.twig", $context)
            );
        } catch (MailException) {
            // Deliberately swallowed — see the class docblock. The change
            // itself has already happened, and MailService has already
            // journaled the technical reason.
        }
    }
}
