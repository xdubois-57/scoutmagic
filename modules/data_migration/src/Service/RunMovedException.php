<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\DataMigration\Service;

/**
 * Internal to {@see ImportEngine}: the run left `running` (paused,
 * abandoned) while a slice was writing an item, so that item's transaction
 * is rolled back and the slice stops. Never reaches a visitor.
 */
final class RunMovedException extends \RuntimeException
{
}
