<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Controller;

use Core\Http\Controller\EmailTemplateController;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Mail\Template\EmailTemplateCustomisationService;
use Core\Mail\Template\EmailTemplateOverrideRepository;
use Core\Mail\Template\EmailTemplateRegistry;
use Core\Mail\Template\EmailTemplateRenderer;
use Core\Mail\Template\EmailTestSendThrottler;
use Twig\Environment;

/**
 * « Gabarits », the third sub-page of « Formulaire d'inscription »: the
 * four e-mails of the request flow, as Configuration > E-mails shows them
 * (issue #905).
 *
 * The e-mails are declared in this module's manifest and live in the core
 * registry, so this page IS the core one — the same list, editor, states,
 * reset and test send — narrowed to these four ids and reached from here.
 * What changes is the address and the role: every route is
 * `role_min: admin`, the floor of the whole « Formulaire d'inscription »
 * page, where Configuration > E-mails stays superadmin. An id outside
 * REQUEST_EMAILS is answered here as an unknown one, so this wider door
 * opens onto these four e-mails and nothing else.
 */
final class RegistrationTemplateController extends EmailTemplateController
{
    public const PAGE_URL = '/config/inscriptions/gabarits';

    /** The request-flow e-mails, in the order the page lists them. */
    public const REQUEST_EMAILS = [
        'registration.request_receipt',
        'registration.request_unit_alert',
        'registration.request_accepted',
        'registration.request_refused',
    ];

    public function __construct(
        Environment $twig,
        EmailTemplateRegistry $registry,
        EmailTemplateOverrideRepository $overrides,
        EmailTemplateRenderer $renderer,
        EmailTemplateCustomisationService $customisation,
        JournalService $journalService,
        MailService $mailService,
        EmailTestSendThrottler $throttler
    ) {
        parent::__construct(
            $twig,
            $registry,
            $overrides,
            $renderer,
            $customisation,
            $journalService,
            $mailService,
            $throttler,
            self::PAGE_URL,
            self::REQUEST_EMAILS,
            '@registration/config/templates.html.twig'
        );
    }
}
