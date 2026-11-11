<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\DataMigration\Importer;

/**
 * A source refused before anything was written: a malformed form, an
 * address that does not answer. Every message is a French sentence written
 * by the import for the person filling the form.
 */
final class SourceRejectedException extends \RuntimeException implements \Core\Exception\UserFacingException
{
}
