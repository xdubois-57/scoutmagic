<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Notification;

use Core\Config\SettingService;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Mail\Template\EmailTemplateOverrideRepository;
use Core\Mail\Template\EmailTemplateRegistry;
use Core\Mail\Template\EmailTemplateRenderer;
use Core\View\TwigFactory;

/**
 * The one place a {@see NotificationMailer} is built.
 *
 * **Why it is a factory rather than a mailer.** The mailer needs Twig, and
 * building Twig means a filesystem loader, a cache directory and a
 * template scan — paid on construction, not on use. Two of the three
 * callers below never send anything on most runs: an ordinary web request
 * raises no alert, and a scheduler pass without an e-mail notification
 * builds nothing. So the cost is deferred to {@see create()}, called at
 * the moment a message actually has to be rendered.
 *
 * **Why it exists at all.** {@see NotificationService}'s class docblock
 * explains that the mailer is an ARGUMENT to
 * `sendEmailsForNotifications()` rather than a constructor dependency,
 * and the reason it gives is sound: threading a fully-built mailer
 * through both composition roots is how `create_backup` once ended up
 * registered in `public/index.php` and not in `public/cron.php`
 * (ARCHITECTURE.md §8.17). What settled that was one construction site —
 * inside `Task\SendNotificationEmailsHandler`, which both entry points
 * reach.
 *
 * A synchronous e-mail (issue #296) needs a mailer where no task handler
 * is running, so that argument had to be answered rather than ignored.
 * This is the answer, and it holds the same property more strongly: ONE
 * construction site for the whole codebase, named, wired identically in
 * both roots, and the handler that used to own it now asks this instead.
 * Web and cron cannot drift, because there is nothing left to drift
 * between.
 *
 * The renderer is deliberately given the CORE registry only: a
 * notification's e-mail body is core's, whatever module declared the
 * type, so there is no module manifest to aggregate here. A
 * customisation of it is still honoured — that lives in the database,
 * not in a manifest.
 *
 * Not `final`, for the reason `Task\InstallUpdateHandler`'s
 * `probeArtifactStatus()` is `protected`: a test subclasses it to make
 * {@see create()} fail, which is the only way to exercise the fallback
 * `NotificationService` has for a mailer it could not build. There is one
 * implementation in production and there is meant to be one.
 */
class NotificationMailerFactory
{
    public function __construct(
        private readonly MailService $mailService,
        private readonly \PDO $pdo,
        private readonly SettingService $settings,
        private readonly JournalService $journal
    ) {
    }

    public function create(): NotificationMailer
    {
        return new NotificationMailer(
            $this->mailService,
            new EmailTemplateRenderer(
                TwigFactory::create(dirname(__DIR__, 2) . '/core/View/templates'),
                new EmailTemplateRegistry(),
                new EmailTemplateOverrideRepository($this->pdo),
                $this->journal
            ),
            (string) ($this->settings->get('site_name') ?: 'Unité scoute'),
            (string) ($this->settings->get('base_url') ?? '')
        );
    }
}
