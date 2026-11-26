<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance\Health\Security;

/**
 * A reference source could not answer — network, timeout, an HTTP status,
 * a document whose shape changed. Never shown to a visitor: the check
 * turns it into « source indisponible » and keeps the last usable answer.
 */
final class ReferenceSourceUnavailable extends \RuntimeException
{
}
