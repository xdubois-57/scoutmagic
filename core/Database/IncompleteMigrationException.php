<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Database;

use Core\Exception\UserFacingException;

/**
 * A migration that had to finish before the caller could go on, and did
 * not (issue #909): the installation wizard creates the administrator
 * account straight after the schema, and on a partial schema that is a
 * `Table doesn't exist` the operator cannot do anything with.
 *
 * Marked {@see UserFacingException}: the one message it carries is the
 * constant below, which says what happened and what to do, and names
 * nothing internal.
 */
final class IncompleteMigrationException extends \RuntimeException implements UserFacingException
{
    public const MESSAGE = 'La création des tables de la base de données n\'a pas pu être terminée à temps. '
        . 'Relancez l\'installation : elle reprendra là où elle s\'est arrêtée.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
