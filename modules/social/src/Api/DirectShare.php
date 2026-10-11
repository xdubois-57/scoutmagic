<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Social\Api;

/**
 * A share the chief made with their own hands, from the history (issue
 * #706, IT-05): the phone's share sheet, or the link copied to the
 * clipboard.
 *
 * **These are destinations in `social_publications` like any other**, and
 * that is the whole design rather than a shortcut. They take a key beside
 * `facebook`, `instagram` and `group:{id}`, so:
 *
 * - the history shows them without a second table to read;
 * - **the communication freezes**, because `isFrozen()` is « this
 *   communication has at least one publication » — and the chantier asks
 *   for every share to freeze, the link copy included: the applications
 *   re-read the public page at every display, so a card that changed
 *   afterwards would not be the one the recipients were sent;
 * - « the same communication twice to the same destination » keeps
 *   meaning what it says, copying a link twice included.
 *
 * **What the labels do NOT say is « sent ».** The site handed the link to
 * the phone, or to the clipboard; what happened next is between the chief
 * and whoever they chose, and no answer comes back. A history that
 * claimed otherwise would be inventing it.
 */
enum DirectShare: string
{
    case Native = 'native';
    case Copy = 'copy';

    /**
     * The name the history gives it — past tense, because a row exists
     * only once the gesture went through. A share sheet the chief
     * dismissed records nothing.
     */
    public function label(): string
    {
        return match ($this) {
            self::Native => 'Partagé depuis le téléphone',
            self::Copy => 'Lien copié',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Native => 'bi-phone',
            self::Copy => 'bi-link-45deg',
        };
    }
}
