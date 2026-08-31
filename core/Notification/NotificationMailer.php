<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Notification;

use Core\Mail\MailException;
use Core\Mail\MailService;
use Core\Mail\Template\EmailTemplateRenderer;

/**
 * The email copy of a notification (ARCHITECTURE.md §8.24).
 *
 * Its own class rather than three more constructor parameters on
 * Core\Notification\NotificationService: rendering a template and talking
 * to a mail transport is a different job from resolving who gets what,
 * and keeping it separate is what lets NotificationService stay
 * constructible without Twig or a mail transport at all (the same
 * degradation its $webPush already has — a test, or an entry point with no
 * view layer, passes null and the email channel is simply skipped).
 *
 * Deliberately dumb about eligibility: whether this recipient wanted an
 * email for this type is settled by NotificationService's channel
 * resolution long before anything here runs. This class only ever answers
 * "render it and hand it to the transport".
 */
class NotificationMailer
{
    /**
     * The subject and body a recipient with discretion on gets — the same
     * substitution the push payload makes (NotificationService's
     * DISCRETION_TITLE). Discretion is a statement about screens somebody
     * else can read, and a mail notification lands on the same lock screen
     * a push does; honouring it in one channel and not the other would
     * make the setting mean nothing.
     */
    private const DISCRETION_SUBJECT = 'Nouvelle notification';

    public function __construct(
        private MailService $mailService,
        private EmailTemplateRenderer $emailTemplateRenderer,
        private string $siteName,
        private string $baseUrl
    ) {
    }

    /**
     * Renders and sends one notification as an email.
     *
     * @param string $to the account's own address, already decrypted by
     *        Core\Security\UserAccountRepository — never a member contact
     *        address: this is a notification for the person who signs in,
     *        not correspondence about a member.
     * @return bool whether the transport accepted it. False on a send
     *         failure, which the caller journals; it never throws, because
     *         one unreachable mailbox must not abandon the rest of a batch.
     */
    public function send(NotificationRecord $record, string $to, bool $discretion): bool
    {
        $context = [
            'site_name' => $this->siteName,
            'title' => $discretion ? self::DISCRETION_SUBJECT : $record->title,
            'body' => $discretion ? '' : $record->body,
            'discretion' => $discretion,
            'url' => $this->absoluteUrl($record->url),
            'preferences_url' => $this->absoluteUrl('/notifications/preferences'),
        ];

        try {
            // The subject is the notification's own title (or the
            // discretion stand-in), never the register's default one: it
            // says what happened, and a customised subject would say the
            // same thing about every notification. The BODY still goes
            // through the register, so an administrator can reword the
            // frame around it.
            $email = $this->emailTemplateRenderer->render('notification', $context);

            $this->mailService->send(
                to: $to,
                subject: $discretion ? self::DISCRETION_SUBJECT : $record->title,
                bodyHtml: $email->bodyHtml,
                bodyText: $email->bodyText
            );
        } catch (MailException) {
            return false;
        }

        return true;
    }

    /**
     * A notification's `url` is a same-origin path ("/gallery/42") because
     * that is all an in-app link needs; an email is read outside the site
     * and needs the whole address. Null stays null — plenty of
     * notifications point nowhere, and the templates handle that.
     */
    private function absoluteUrl(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        return rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
    }
}
