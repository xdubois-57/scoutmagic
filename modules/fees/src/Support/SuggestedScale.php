<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Fees\Support;

use Core\Member\HouseholdFeeCategory;
use Core\Security\SessionStore;
use Modules\Fees\Value\FederalScaleLookup;

/**
 * A one-shot carrier for the amounts « Chercher les montants » proposed, so
 * the redirect that follows the POST can pre-fill the barème's three fields
 * without anything having been saved.
 *
 * Same shape and same reason as `Modules\Groups\Support\RejectedDraft`: the
 * POST answers with a redirect (nothing is written, so re-submitting on F5
 * would be a second billable AI call for nothing), and the suggestion has to
 * survive exactly that one redirect. It is kept in the asker's own session,
 * `take()` clears it on read, and it reaches **no** repository — the only
 * path from a model's answer into `fees_household_tariffs` is a chef
 * d'unité reading these figures and clicking « Enregistrer le barème ».
 */
final class SuggestedScale
{
    private const SESSION_KEY = '_fees_suggested_scale';

    public static function set(FederalScaleLookup $lookup): void
    {
        SessionStore::set(self::SESSION_KEY, [
            'url' => $lookup->url,
            'year' => $lookup->year,
            'amount_cents' => $lookup->amountCents,
        ]);
    }

    /**
     * The suggestion, re-validated on the way out: a session value is not
     * hostile input, but it is old input — a page kept open across a scout
     * year switch, or a shape this class wrote in an earlier version.
     *
     * @return array{url: string, year: string, amount_cents: array<string, int>}|null
     */
    public static function take(): ?array
    {
        $stored = SessionStore::get(self::SESSION_KEY);
        SessionStore::remove(self::SESSION_KEY);

        if (!is_array($stored)) {
            return null;
        }

        $url = $stored['url'] ?? null;
        $year = $stored['year'] ?? null;
        $amounts = $stored['amount_cents'] ?? null;
        if (!is_string($url) || !is_string($year) || !is_array($amounts)) {
            return null;
        }

        $clean = [];
        foreach (HouseholdFeeCategory::cases() as $category) {
            $cents = $amounts[$category->value] ?? null;
            if (!is_int($cents) || $cents <= 0) {
                return null;
            }
            $clean[$category->value] = $cents;
        }

        return ['url' => $url, 'year' => $year, 'amount_cents' => $clean];
    }
}
