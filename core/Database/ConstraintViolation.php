<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Database;

/**
 * Decides whether a PDOException means "the values this request supplied
 * are not values the schema accepts" or "the database itself is in
 * trouble" — because those deserve opposite answers, and today they get
 * the same one.
 *
 * Every write in this project is a prepared statement, so a value can
 * never change what a query *does*. It can still be a value the column
 * refuses: an id whose row was deleted a second ago, a number wider than
 * `INT UNSIGNED`, a date that is not one. MySQL rejects those, PDO raises,
 * nobody catches, and the visitor gets the 500 page written for "the
 * application has crashed" — which is both alarming and untrue. A dynamic
 * scan collected 542 of them across 13 statements.
 *
 * The boundary sites are being fixed one by one (`Core\Service\DateInput`,
 * `Core\Service\IntegerInput`), and that is the real work — a request
 * refused with a French sentence next to the field beats a generic error
 * page every time. But one class of these cannot be fixed at a boundary
 * at all: between "does this member exist?" and the INSERT that
 * references them, another administrator can delete them. Check first and
 * the race is narrower, never gone. So the safety net is not a substitute
 * for validation, it is the floor underneath it.
 *
 * **Classified by driver code, never by SQLSTATE.** SQLSTATE 23000 covers
 * a foreign key that does not resolve — the caller's problem — and a NOT
 * NULL column the application forgot to populate, which is a bug in this
 * codebase and must stay a loud 500. Only `errorInfo[1]` separates them.
 * The same reasoning, and the same shape, as
 * `Core\Database\Connection::describeConnectionFailure()`, which turns a
 * connection failure into an operator-facing sentence by driver code for
 * exactly this reason.
 *
 * Anything not listed below is deliberately NOT caller fault. A code that
 * cannot be placed is a 500, which is the honest answer for "we do not
 * know what went wrong".
 */
final class ConstraintViolation
{
    /**
     * The row this request referred to is gone, or the row it is trying
     * to create is already there. The request was well formed; the world
     * moved. HTTP calls that 409.
     */
    public const CONFLICT = 'conflict';

    /**
     * A value the column could not hold at all — too wide, too long, not
     * a date. The request was malformed. HTTP calls that 400.
     */
    public const MALFORMED = 'malformed';

    /**
     * MySQL driver codes, and why each one is the caller's doing.
     *
     * @var array<int, string>
     */
    private const CALLER_FAULT = [
        // Cannot delete or update a parent row: a FK constraint fails.
        // Something still references the row being removed.
        1451 => self::CONFLICT,
        // Cannot add or update a child row: a FK constraint fails. The
        // row being referenced does not exist — deleted, or never there.
        1452 => self::CONFLICT,
        // Duplicate entry for a unique key. Usually a form submitted
        // twice, or a name somebody else took in between.
        1062 => self::CONFLICT,

        // Out of range value for a column. A number wider than the
        // column's type — what a long digit string in a form produces.
        1264 => self::MALFORMED,
        // Incorrect <type> value for a column: a date column handed
        // something that is not a date.
        1292 => self::MALFORMED,
        // Incorrect string value: bytes that are not valid in the
        // column's character set.
        1366 => self::MALFORMED,
        // Data too long for a column.
        1406 => self::MALFORMED,
    ];

    /**
     * CONFLICT, MALFORMED, or null when this is not the caller's fault
     * and must remain a 500.
     *
     * Walks the `$previous` chain: a repository that wraps its PDO call
     * in a transaction and rethrows, or a service that adds context,
     * both keep the original as the cause.
     */
    public static function classify(\Throwable $e): ?string
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if (!$current instanceof \PDOException) {
                continue;
            }

            // errorInfo[1] is the driver's own code. It is null for some
            // failures (a connection that never opened, chiefly), and a
            // failure with no driver code is not one of the seven above.
            $driverCode = is_array($current->errorInfo) ? ($current->errorInfo[1] ?? null) : null;
            if (is_int($driverCode) && isset(self::CALLER_FAULT[$driverCode])) {
                return self::CALLER_FAULT[$driverCode];
            }
        }

        return null;
    }

    /**
     * The status code for a verdict.
     */
    public static function statusCode(string $verdict): int
    {
        return $verdict === self::CONFLICT ? 409 : 400;
    }

    /**
     * The French sentence the visitor reads.
     *
     * Written here rather than taken from the driver: MySQL's own text
     * names the table, the column and the constraint, in English, and
     * putting that on a page would be the leak
     * `Core\Exception\UserFacingException` exists to prevent. What the
     * visitor needs is what to do next, and for both verdicts that is
     * "reload and try again" — for a conflict because the page they are
     * looking at is stale, for a malformed value because a field holds
     * something the form will not accept.
     */
    public static function message(string $verdict): string
    {
        return $verdict === self::CONFLICT
            ? 'Cette action n\'a pas pu être enregistrée : les données ont changé depuis l\'affichage de '
                . 'cette page. Rechargez-la et réessayez.'
            : 'Cette action n\'a pas pu être enregistrée : une des valeurs envoyées n\'est pas acceptée. '
                . 'Vérifiez le formulaire et réessayez.';
    }
}
