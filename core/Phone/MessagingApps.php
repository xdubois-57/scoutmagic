<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Phone;

/**
 * The two messaging services a phone number can open, named once.
 *
 * Three places have to agree about them, in three languages, as with
 * Core\Geo\MapTiles: the links in JavaScript (public/assets/js/phone-link.js,
 * the only place each link's format is written), and the RGPD page — both
 * its default text (core/View/rgpd_default.html) and the rule the
 * generated policy must keep (Core\View\RgpdContentService). A host that
 * changes in the script and not in the policy tells families the number
 * goes somewhere it no longer goes.
 *
 * Neither service is a sub-processor: the site sends them nothing. A
 * member who taps « WhatsApp » or « Signal » follows a link, and their own
 * phone hands the number to that service, like any link they choose to
 * follow. A call and a text go through no server at all and are not named
 * here. Tests\Core\Phone\MessagingAppsTest checks the three still agree.
 */
final class MessagingApps
{
    /** The address a « WhatsApp » link opens, the number without its « + ». */
    public const WHATSAPP_ORIGIN = 'https://wa.me';

    /** How WhatsApp is named to a reader, in the RGPD page. */
    public const WHATSAPP_NAME = 'WhatsApp';

    /** The address a « Signal » link opens, the number with its « + ». */
    public const SIGNAL_ORIGIN = 'https://signal.me';

    /** How Signal is named to a reader, in the RGPD page. */
    public const SIGNAL_NAME = 'Signal';
}
