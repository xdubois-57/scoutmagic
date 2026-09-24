<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Api;

/**
 * The tabs of a mail triage screen (issue #462, D9), shared by every
 * consumer that renders `@inbound_mail/partials/triage.html.twig`. The
 * values are the `?statut=` of the screen's URL.
 */
enum TriageFilter: string
{
    case UNLINKED = 'non_rattaches';
    case LINKED = 'rattaches';
    case ALL = 'tous';
    case DISMISSED = 'ecartes';

    /**
     * The filter a request asked for, « à trier » by default: what somebody
     * comes to this screen to do is decide about the mail nothing could
     * attribute.
     */
    public static function fromQuery(string $raw): self
    {
        return self::tryFrom($raw) ?? self::UNLINKED;
    }

    /**
     * Whether one row of the list belongs under this tab. « Rattachés » and
     * « à trier » are read off the row's links, which are the consumer's
     * own and nobody else's (`InboundMailInterface::triageRows()`).
     *
     * @param array<string, mixed> $row
     */
    public function keeps(array $row): bool
    {
        return match ($this) {
            self::ALL, self::DISMISSED => true,
            self::LINKED => ($row['links'] ?? []) !== [],
            self::UNLINKED => ($row['links'] ?? []) === [],
        };
    }
}
