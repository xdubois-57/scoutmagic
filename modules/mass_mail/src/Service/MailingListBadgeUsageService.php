<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\MassMail\Service;

use Core\Module\BadgeUsageProvider;
use Modules\MassMail\Repository\MailingListRepository;

/**
 * This module's answer to « may this badge be deleted? » (ARCHITECTURE.md
 * §7.4): no, while a custom list still crosses it.
 *
 * A Service\, not an Api\ class: `Api\` is this module's contract for
 * OTHER modules to read (§7.5) and may not reach into its repositories,
 * while the interface implemented here is CORE's, hooked up from the
 * composition root exactly as the trombinoscope's own hooks are.
 *
 * `mass_mail_list_badges.badge_id` cascades on the badge's deletion, and
 * the cascade would not narrow the list to nobody — an axis with no row
 * left stops constraining anything, so « la meute ET le badge X » would
 * quietly become « la meute ». Deactivating a badge is the operation that
 * means "stop using this", and the criteria pickers keep offering a
 * deactivated badge a list still names, greyed, precisely so it can be
 * removed by hand first.
 */
final class MailingListBadgeUsageService implements BadgeUsageProvider
{
    public function __construct(private MailingListRepository $listRepository)
    {
    }

    /**
     * @return int[]
     */
    public function badgeIdsInUse(): array
    {
        return $this->listRepository->findReferencedBadgeIds();
    }

    public function describeBadgeUsage(): string
    {
        return 'utilisé comme critère par une liste de diffusion';
    }
}
