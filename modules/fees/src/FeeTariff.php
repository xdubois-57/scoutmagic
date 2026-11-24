<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Fees;

/** An applicable tariff, independent of the core's three household sizes. */
enum FeeTariff: string
{
    case NORMAL = 'normal';
    case COUPLE = 'couple';
    case FAMILY = 'family';
    case IAMA = 'iama';
    case GUEST = 'guest';
}
