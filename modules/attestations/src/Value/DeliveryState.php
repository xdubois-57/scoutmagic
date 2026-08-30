<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Attestations\Value;

/**
 * Where one certificate stands in its journey to the family.
 *
 * **`NoAddress` and `Failed` are two states rather than one**, and that is
 * the reason this enum is not a boolean. They need two different things
 * from a chef d'unité: a family the site has no address for has to be
 * reached another way, while a family whose mail server refused the message
 * has an address that simply did not work this time. « Non envoyé » would
 * say neither, and a chef d'unité reading it would chase the wrong half.
 */
enum DeliveryState: string
{
    /** Published, and nothing has been attempted yet. */
    case Pending = 'pending';

    /** The message left the server with the certificate attached. */
    case Sent = 'sent';

    /** The site holds no e-mail address for this member, in any year. */
    case NoAddress = 'no_address';

    /** The transport refused it. Never retried — see the handler for why. */
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'À envoyer',
            self::Sent => 'Envoyée',
            self::NoAddress => 'Aucune adresse connue',
            self::Failed => 'Envoi refusé',
        };
    }

    /** The semantic key `partials/status_badge.html.twig` colours by. */
    public function badgeKey(): string
    {
        return match ($this) {
            self::Pending => 'pending',
            self::Sent => 'done',
            self::NoAddress => 'neutral',
            self::Failed => 'failed',
        };
    }

    /** True once nothing more will be attempted for this line. */
    public function isSettled(): bool
    {
        return $this !== self::Pending;
    }

    public static function tryFromValue(?string $value): ?self
    {
        return $value === null ? null : self::tryFrom($value);
    }
}
