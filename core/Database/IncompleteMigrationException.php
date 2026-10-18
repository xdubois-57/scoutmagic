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
 * Two ways to get here, one constructor each: the wait ran out, or the
 * runner gave up on statements that failed on every pass (a result that
 * is `complete` but not `converged` — MigrationResult says why both exist).
 *
 * Marked {@see UserFacingException}: the only messages it carries are the
 * constants below, which say what happened and what to do, and name
 * nothing internal.
 */
final class IncompleteMigrationException extends \RuntimeException implements UserFacingException
{
    /*
     * No promise to resume where it stopped: a database already holding
     * tables is refused by the wizard's emptiness check before any
     * migration runs, so the operator is told the way the wizard itself
     * offers — the button it shows for exactly that state.
     */
    public const TIMED_OUT = 'La création des tables de la base de données n\'a pas pu être terminée à temps. '
        . 'Relancez l\'installation ; si l\'assistant signale des tables existantes, utilisez « Sauvegarder puis '
        . 'vider la base » avant de réessayer.';

    public const ABANDONED = 'La création des tables de la base de données a échoué : certaines instructions '
        . 'sont refusées par le serveur à chaque essai. Vérifiez la version et les droits de l\'utilisateur '
        . 'de la base de données, puis réessayez.';

    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function timedOut(): self
    {
        return new self(self::TIMED_OUT);
    }

    public static function abandoned(): self
    {
        return new self(self::ABANDONED);
    }
}
