<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Service;

/**
 * The single place a receipt's suggested date is turned into a storable
 * "YYYY-MM-DD", shared by the two paths that can set one: Task\
 * ExtractReceiptDataHandler (an AI-extracted date, where a model's
 * compliance with a requested string format is never guaranteed — observed
 * in practice returning "27/10/2026" or an ISO datetime despite the
 * instruction) and Controller\ReceiptController::update() (an admin
 * correcting it by hand, where the value arrives straight from a JSON
 * body).
 *
 * Both need exactly the same guarantee, for the same reason: the value
 * ends up in a DATE column AND in new \DateTimeImmutable() inside
 * Service\ReceiptMatchingService::referenceDate()/daysBetween() and
 * Controller\MovementController::findSuggestedReceipt(), which throw on
 * anything unparseable and would take a whole page — or the post-import
 * receipt matching pass — down with them.
 *
 * Tolerates an ISO date or datetime and the European DD/MM/YYYY-style
 * format (the convention on the receipts this module reads); returns null
 * when nothing recognizable, or not a real calendar date, is found.
 */
final class ReceiptDateNormalizer
{
    public static function normalize(string $rawDate): ?string
    {
        $value = trim($rawDate);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m) === 1) {
            return self::validDateOrNull((int) $m[1], (int) $m[2], (int) $m[3]);
        }

        if (preg_match('#^(\d{1,2})[./-](\d{1,2})[./-](\d{4})$#', $value, $m) === 1) {
            return self::validDateOrNull((int) $m[3], (int) $m[2], (int) $m[1]);
        }

        return null;
    }

    private static function validDateOrNull(int $year, int $month, int $day): ?string
    {
        if (!checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }
}
