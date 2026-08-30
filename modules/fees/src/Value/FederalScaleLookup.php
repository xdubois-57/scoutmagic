<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Fees\Value;

/**
 * What one « Chercher les montants » attempt came to.
 *
 * Same shape as {@see InvoiceImportOutcome}: an outcome object rather than
 * an exception, because every failure here is ordinary — a page that moved,
 * a model that answered something else, a federation page that has not been
 * updated for the targeted scout year yet — and each needs a different
 * sentence rather than a stack trace.
 *
 * **The message is always written here, never taken from anywhere else.**
 * The fetched page and the model's answer are untrusted text; the only
 * value from that side of the wire that ever reaches a sentence is
 * `$foundYear`, and only after it has been normalised to `YYYY-YYYY` by a
 * regex (`Service\FederalScaleLookupService::normalizeYear()`). Nothing
 * else — not the model's prose, not a snippet of the page, not the
 * connector's own error text — is ever quoted back to the screen.
 *
 * **Nothing here is ever written to the database.** The amounts travel to
 * the form, are shown to a chef d'unité, and are stored only if they click
 * « Enregistrer le barème » themselves.
 */
final class FederalScaleLookup
{
    public const FOUND = 'found';
    public const UNAVAILABLE = 'unavailable';
    public const FETCH_FAILED = 'fetch_failed';
    public const AI_FAILED = 'ai_failed';
    public const UNREADABLE = 'unreadable';
    public const YEAR_MISSING = 'year_missing';
    public const YEAR_MISMATCH = 'year_mismatch';

    /**
     * @param array<string, int> $amountCents keyed by
     *        Core\Member\HouseholdFeeCategory::value — always the three
     *        keys on FOUND, always empty otherwise
     */
    private function __construct(
        public readonly string $status,
        public readonly string $url,
        public readonly ?string $year,
        public readonly array $amountCents,
        public readonly string $message
    ) {
    }

    /** @param array<string, int> $amountCents */
    public static function found(string $url, string $year, array $amountCents): self
    {
        return new self(self::FOUND, $url, $year, $amountCents, 'Montants trouvés. Vérifiez-les, puis enregistrez le barème.');
    }

    public static function unavailable(string $url): self
    {
        return new self(
            self::UNAVAILABLE,
            $url,
            null,
            [],
            "Aucun connecteur IA n'est configuré : les montants ne peuvent pas être cherchés."
        );
    }

    public static function fetchFailed(string $url): self
    {
        return new self(
            self::FETCH_FAILED,
            $url,
            null,
            [],
            "La page de la fédération n'a pas pu être consultée. Vérifiez son adresse dans les réglages, puis réessayez."
        );
    }

    public static function aiFailed(string $url): self
    {
        return new self(
            self::AI_FAILED,
            $url,
            null,
            [],
            "Le connecteur IA n'a pas pu répondre. Réessayez dans quelques instants, ou saisissez les montants à la main."
        );
    }

    public static function unreadable(string $url): self
    {
        return new self(
            self::UNREADABLE,
            $url,
            null,
            [],
            "Les trois montants n'ont pas pu être lus sur cette page. Saisissez-les à la main."
        );
    }

    public static function yearMissing(string $url): self
    {
        return new self(
            self::YEAR_MISSING,
            $url,
            null,
            [],
            "Aucune année scoute n'a pu être identifiée sur cette page : rien n'a été pré-rempli."
        );
    }

    public static function yearMismatch(string $url, string $foundYear, string $expectedYear): self
    {
        return new self(
            self::YEAR_MISMATCH,
            $url,
            $foundYear,
            [],
            "Cette page annonce les montants de l'année {$foundYear}, pas {$expectedYear} : rien n'a été pré-rempli."
        );
    }

    public function isFound(): bool
    {
        return $this->status === self::FOUND;
    }
}
