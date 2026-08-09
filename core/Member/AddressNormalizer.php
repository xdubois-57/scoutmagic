<?php

declare(strict_types=1);

namespace Core\Member;

use Core\Member\Service\MemberSearchService;

/**
 * Reduces an address to a comparable-only form — never displayed, never
 * meant to be readable. Its sole purpose (ARCHITECTURE.md §8): two
 * different encodings of the same real-world address must normalize to
 * the same string, so Core\Member\FeeEstimationService can find them via
 * an exact-match blind index (member_addresses.address_normalized_blind_
 * index) without ever decrypting the whole table.
 *
 * Concatenates street/number/box/postal code, lowercases, strips accents
 * (reuses MemberSearchService::fold(), same accent-insensitive-search
 * technique already used elsewhere — nothing new to invent there),
 * tokenizes on any run of non-alphanumeric characters, then drops tokens
 * that are a street type/abbreviation or a French article.
 *
 * Tokenizing BEFORE filtering — rather than a substring replace of "rue",
 * "du", etc. against the concatenated blob — is the whole point: a
 * substring replace corrupts words that merely CONTAIN a stop word
 * ("Rue Duquesnoy" losing its "du", "Bois du Duc" losing two
 * occurrences — a real production bug this reimplementation fixes).
 * Word-boundary tokenization only ever removes a token that IS one of
 * these words, never a substring inside a longer one.
 *
 * The postal code is never filtered (it's purely numeric, so it can never
 * match an alphabetic stop word anyway) — it carries most of the
 * discriminating power between two genuinely different addresses.
 */
final class AddressNormalizer
{
    /**
     * Street types (and their common Belgian French abbreviations) plus
     * the French articles the module spec calls out — every entry already
     * in its accent-stripped, lowercase form, since filtering happens
     * after MemberSearchService::fold().
     *
     * @var array<int, string>
     */
    private const STOP_WORDS = [
        'rue', 'r',
        'avenue', 'av', 'ave',
        'chaussee', 'ch', 'chee',
        'boulevard', 'bd', 'blvd', 'boul',
        'place', 'pl',
        'clos',
        'dreve',
        'de', 'du', 'des', 'la',
    ];

    public static function normalize(?string $street, ?string $number, ?string $box, ?string $postalCode): string
    {
        $parts = array_filter(
            [$street, $number, $box, $postalCode],
            static fn(?string $v): bool => $v !== null && trim($v) !== ''
        );
        if ($parts === []) {
            return '';
        }

        $folded = MemberSearchService::fold(implode(' ', $parts));
        $tokens = preg_split('/[^a-z0-9]+/', $folded, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $kept = array_filter($tokens, static fn(string $token): bool => !in_array($token, self::STOP_WORDS, true));

        return implode('', $kept);
    }
}
