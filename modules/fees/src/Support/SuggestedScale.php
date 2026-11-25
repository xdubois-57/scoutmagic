<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Fees\Support;

use Core\Security\SessionStore;
use Modules\Fees\FeeTariff;
use Modules\Fees\Service\FederalScaleLookupService;
use Modules\Fees\Service\TariffConfigurationService;
use Modules\Fees\Value\FederalScaleLookup;

/**
 * A proposal and its unsaved form draft, held in the requester's session.
 * Applying a proposal merges into the CURRENT posted fields, never the draft
 * from before the lookup. Tokens reject a proposal replaced in another tab;
 * the canonical year rejects a form kept across a scout-year switch.
 * Nothing here reaches a repository or saves the scale.
 *
 * @phpstan-type ScaleProposal array{
 *   url: string, year: string, amount_cents: array<string, int>,
 *   guest_from: ?string, guest_until: ?string, missing_fields: list<string>, token: string
 * }
 */
final class SuggestedScale
{
    private const SESSION_KEY = '_fees_suggested_scale';
    private const DECLINED_KEY = '_fees_suggested_scale_declined';
    private const DRAFT_KEY = '_fees_scale_draft';

    public static function decline(): void
    {
        SessionStore::remove(self::SESSION_KEY);
        SessionStore::set(self::DECLINED_KEY, true);
    }

    public static function takeDeclined(): bool
    {
        $declined = SessionStore::get(self::DECLINED_KEY) === true;
        SessionStore::remove(self::DECLINED_KEY);

        return $declined;
    }

    public static function set(FederalScaleLookup $lookup): void
    {
        if (!$lookup->isFound()) {
            self::decline();
            return;
        }
        SessionStore::remove(self::DECLINED_KEY);
        SessionStore::set(self::SESSION_KEY, [
            'url' => $lookup->url,
            'year' => $lookup->year,
            'amount_cents' => $lookup->amountCents,
            'guest_from' => $lookup->guestFromDayMonth,
            'guest_until' => $lookup->guestUntilDayMonth,
            'token' => bin2hex(random_bytes(16)),
        ]);
    }

    /** @return ScaleProposal|null */
    public static function peek(string $year): ?array
    {
        $stored = SessionStore::get(self::SESSION_KEY);
        $year = FederalScaleLookupService::normalizeYear($year);
        if (!is_array($stored) || $year === null || ($stored['year'] ?? null) !== $year) {
            return null;
        }
        $url = $stored['url'] ?? null;
        $token = $stored['token'] ?? null;
        if (!is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false
            || parse_url($url, PHP_URL_SCHEME) !== 'https'
            || !is_string($token) || preg_match('/^[a-f0-9]{32}$/D', $token) !== 1) {
            return null;
        }
        $amounts = self::amounts($stored['amount_cents'] ?? null);
        $period = self::period($stored, $year);
        if ($amounts === null || $period === null
            || ($amounts === [] && $period['from'] === null && $period['until'] === null)) {
            return null;
        }
        $lookup = FederalScaleLookup::found($url, $year, $amounts, $period['from'], $period['until']);

        return [
            'url' => $url, 'year' => $year, 'amount_cents' => $amounts,
            'guest_from' => $period['from'], 'guest_until' => $period['until'],
            'missing_fields' => $lookup->missingFields, 'token' => $token,
        ];
    }

    /**
     * @param array<string, mixed> $current
     * @return array<string, string>|null
     */
    public static function apply(string $year, mixed $token, array $current, bool $includePeriod): ?array
    {
        $year = TariffConfigurationService::requireFormYear($current['scale_year'] ?? null, $year);
        $proposal = self::peek($year);
        if ($proposal === null || !is_string($token)) {
            return null;
        }
        if (!hash_equals($proposal['token'], $token)) {
            // Another tab replaced the proposal. Keep this tab's current
            // inputs without consuming the replacement. A consumed proposal
            // takes the branch above, preserving the already applied draft.
            self::rememberDraft($year, $current);

            return null;
        }
        $draft = self::draftValues($current);
        foreach ($proposal['amount_cents'] as $key => $cents) {
            $draft['amount_' . $key] = sprintf('%d,%02d', intdiv($cents, 100), $cents % 100);
        }
        if ($includePeriod) {
            if ($proposal['guest_from'] !== null) {
                $draft['guest_from'] = $proposal['guest_from'];
            }
            if ($proposal['guest_until'] !== null) {
                $draft['guest_until'] = $proposal['guest_until'];
            }
        }
        self::rememberDraft($year, $draft);
        SessionStore::remove(self::SESSION_KEY);

        return $draft;
    }

    /** @param array<string, mixed> $input */
    public static function rememberDraft(string $year, array $input): void
    {
        $year = FederalScaleLookupService::normalizeYear($year);
        if ($year !== null && ($input['scale_year'] ?? null) === $year) {
            SessionStore::set(self::DRAFT_KEY, ['year' => $year, 'fields' => self::draftValues($input)]);
        }
    }

    /** @return array<string, string>|null */
    public static function draftFor(string $year): ?array
    {
        $draft = SessionStore::get(self::DRAFT_KEY);
        $year = FederalScaleLookupService::normalizeYear($year);
        if (!is_array($draft) || $year === null || ($draft['year'] ?? null) !== $year
            || !is_array($draft['fields'] ?? null)) {
            return null;
        }

        return self::draftValues($draft['fields']);
    }

    public static function clear(): void
    {
        SessionStore::remove(self::SESSION_KEY);
        SessionStore::remove(self::DRAFT_KEY);
        SessionStore::remove(self::DECLINED_KEY);
    }

    /** @return array<string, int>|null */
    private static function amounts(mixed $raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }
        $clean = [];
        foreach ($raw as $key => $cents) {
            if (!is_string($key) || FeeTariff::tryFrom($key) === null || !is_int($cents)
                || $cents < FederalScaleLookupService::MIN_AMOUNT_CENTS
                || $cents > FederalScaleLookupService::MAX_AMOUNT_CENTS) {
                return null;
            }
            $clean[$key] = $cents;
        }

        return $clean;
    }

    /**
     * @param array<mixed> $stored
     * @return array{from: ?string, until: ?string}|null
     */
    private static function period(array $stored, string $year): ?array
    {
        $from = $stored['guest_from'] ?? null;
        $until = $stored['guest_until'] ?? null;
        if (($from !== null && !is_string($from)) || ($until !== null && !is_string($until))) {
            return null;
        }
        if (($from !== null && TariffConfigurationService::dayMonthOrNull($from, $year) !== $from)
            || ($until !== null && TariffConfigurationService::dayMonthOrNull($until, $year) !== $until)) {
            return null;
        }
        if ($from !== null && $until !== null
            && TariffConfigurationService::periodFromFields($year, $from, $until) === null) {
            return null;
        }

        return ['from' => $from, 'until' => $until];
    }

    /**
     * @param array<mixed> $input
     * @return array<string, string>
     */
    private static function draftValues(array $input): array
    {
        $keys = ['scale_year', 'guest_from', 'guest_until'];
        foreach (FeeTariff::cases() as $tariff) {
            $keys[] = 'amount_' . $tariff->value;
            $keys[] = 'fee_category_' . $tariff->value;
        }
        $draft = [];
        foreach ($keys as $key) {
            if (is_string($input[$key] ?? null)) {
                $draft[$key] = $input[$key];
            }
        }

        return $draft;
    }
}
