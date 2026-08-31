<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Support\Ticket;

/**
 * The categories this version of the site ships with (roadmap IT-25).
 *
 * **The receiver owns the list**, and publishes it in every answer of the
 * ticket endpoint; this is only what an installation offers before it has
 * ever heard one — the first ticket anybody opens, or an installation
 * whose stored copy is unreadable. Shipping a copy rather than starting
 * with an empty picker is the whole point: a form that cannot be filled
 * until a previous ticket has been sent cannot be used to send the first
 * one.
 *
 * Kept deliberately in step with `Modules\SupportDashboard\TicketCategory`,
 * which is the authority. A drift costs nothing at worst — the receiver
 * refuses an unknown value with a 200 and publishes the real list, which
 * this installation then remembers.
 */
final class TicketCategories
{
    /**
     * @return list<array{value: string, label: string}>
     */
    public static function shipped(): array
    {
        return [
            ['value' => 'installation', 'label' => 'Installation'],
            ['value' => 'update', 'label' => 'Mise à jour'],
            ['value' => 'email', 'label' => 'E-mail'],
            ['value' => 'desk_import', 'label' => 'Import Desk'],
            ['value' => 'performance', 'label' => 'Performance'],
            ['value' => 'other', 'label' => 'Autre'],
        ];
    }
}
