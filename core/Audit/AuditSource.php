<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Audit;

/**
 * What produced a change (schema/core.sql: entity_changes.source).
 *
 * Distinct from "is there an actor": an account id answers WHO, this
 * answers HOW, and the timeline needs both. A change carrying no account
 * id is always automatic, but 'system' and 'email' are not the same fact
 * to a reader trying to understand why a price moved on its own.
 */
enum AuditSource: string
{
    /** Someone acting in the interface. */
    case Human = 'human';

    /** Read out of an inbound message. */
    case Email = 'email';

    /** A model's suggestion a human accepted. */
    case Ai = 'ai';

    /** The application on its own — a scheduled task, a cascade. */
    case System = 'system';
}
