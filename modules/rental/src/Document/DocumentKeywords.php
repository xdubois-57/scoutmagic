<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Document;

use Core\Template\TokenCatalogue;
use Core\Template\TokenEngine;
use Core\Template\TokenSyntax;

/**
 * The closed list of `{{ mot_cle }}` placeholders a contract or an invoice
 * template may use, and the substitution itself (§6.25).
 *
 * **Two rules make this safe, and both are structural rather than
 * remembered.**
 *
 * First, substitution happens **after** the rich text has been sanitized,
 * never before. Sanitizing after substitution would run the sanitizer over
 * a document containing renter-supplied values, and a sanitizer's job is to
 * decide what markup is allowed — not to decide whether a value should have
 * been markup in the first place.
 *
 * Second, **every substituted value is escaped**. dompdf renders HTML: a
 * renter called `<script>` or, more realistically, an organisation named
 * `Scouts <de> Nulle Part` would otherwise be interpreted as markup and
 * silently mangle — or reshape — the contract. The values come from a form
 * an anonymous visitor filled in, so they are the least trustworthy thing
 * in the whole document.
 *
 * The list is **closed** on purpose. An open substitution mechanism is one
 * where a template can reach any value the renderer happens to have in
 * scope; here a template can say exactly these things and nothing else, and
 * anything it asks for that is not on the list is reported to its author at
 * edit time rather than rendered as literal braces in a signed contract.
 *
 * **Both rules are Core\Template\TokenEngine's now**, along with the
 * pattern, the unknown-keyword report and the rescue of a keyword a
 * rich-text surface broke apart. The publipostage had grown its own copy of
 * the same four rules; what is left here is this module's own: the
 * catalogue, and nothing else.
 */
final class DocumentKeywords
{
    /**
     * Every keyword, with the French explanation shown beside the editor.
     *
     * @var array<string, string>
     */
    private const CATALOGUE = [
        'reference' => 'La référence du dossier, par exemple LOC-2027-0042',
        'bien' => 'Le nom du bien loué',
        'bien_type' => 'Le type du bien (local, terrain, tente…)',
        'date_arrivee' => "La date d'arrivée, au format 31/12/2027",
        'date_depart' => 'La date de départ',
        'heure_arrivee' => "L'heure d'arrivée prévue pour ce bien",
        'heure_depart' => 'L\'heure de départ prévue pour ce bien',
        'nuits' => 'Le nombre de nuits',
        'participants' => 'Le nombre de participants annoncé',
        'capacite' => 'La capacité maximale d\'accueil du bien, ou « — »',
        'quantite' => 'La quantité louée, pour un bien en stock',
        'prix_total' => 'Le total à payer, par exemple 467,50 €',
        'acompte' => "Le montant de l'acompte, ou « — » si le bien n'en demande pas",
        'caution' => 'Le montant de la caution, ou « — »',
        'communication' => 'La communication structurée du virement',
        'locataire_nom' => 'Le nom du locataire',
        'locataire_organisation' => "L'organisation du locataire, ou « — »",
        'locataire_email' => 'L\'adresse email du locataire',
        'locataire_telephone' => 'Le téléphone du locataire, ou « — »',
        'locataire_adresse' => 'L\'adresse de facturation du locataire, ou « — »',
        'locataire_tva' => 'Le numéro de TVA ou de BCE du locataire, ou « — »',
        'adresse_bailleur' => "L'adresse de l'unité, telle que configurée",
        'unite' => "Le nom de l'unité",
        'date_du_jour' => "La date du jour de génération",
        'mention_tva' => 'La mention d\'exonération de TVA configurée pour ce bien',
    ];

    /**
     * The shared engine, on this module's own vocabulary: `{{ mot_cle }}`
     * with any spacing, lower-case letters, digits and underscores, and
     * nothing else.
     *
     * Deliberately narrow. A syntax that accepted anything between the
     * braces would make "unknown keyword" reporting useless, because every
     * stray `{{` in a contract's prose would become a candidate; it is also
     * what makes the repair pass safe, since a region is only rewritten
     * once what it would become spells a name this narrow.
     *
     * Built per call rather than held: it carries no state, every caller
     * here is static, and a shared instance would be a singleton for the
     * sake of two object allocations.
     */
    private static function engine(): TokenEngine
    {
        return new TokenEngine(TokenSyntax::identifiers());
    }

    /**
     * The declared list, as the palette a rich-text field reads.
     */
    public static function tokenCatalogue(): TokenCatalogue
    {
        return TokenCatalogue::of(self::CATALOGUE);
    }

    /**
     * The catalogue, for the palette beside the editor.
     *
     * @return array<int, array{keyword: string, placeholder: string, description: string}>
     */
    public static function catalogue(): array
    {
        return self::tokenCatalogue()->palette();
    }

    public static function isKnown(string $keyword): bool
    {
        return self::tokenCatalogue()->has($keyword);
    }

    /**
     * Keywords a template uses that are not on the list.
     *
     * Reported to the author **while editing** (§6.25): a keyword nobody
     * recognises must never survive into a signed contract as literal
     * braces, and the only moment anybody can still fix it is now.
     *
     * @return string[] Distinct, in the order they first appear.
     */
    public static function unknownIn(string $html): array
    {
        return self::engine()->unknownTokens(
            $html,
            static fn(string $keyword): bool => self::isKnown($keyword)
        );
    }

    /**
     * Replaces every known keyword in **already-sanitized** HTML with its
     * **escaped** value.
     *
     * An unknown keyword is left exactly as it is rather than blanked: a
     * contract with a visible `{{ prix_ttc }}` in it is obviously wrong to
     * whoever reads it, whereas a silently emptied one reads as a clause
     * that simply says nothing.
     *
     * @param array<string, string|null> $values Raw, unescaped. A null renders as an em dash.
     */
    public static function substitute(string $sanitizedHtml, array $values): string
    {
        // Escaping is the engine's, and it is the whole safety of this
        // class: dompdf renders HTML, and these values come from a form an
        // anonymous visitor filled in.
        return self::engine()->substitute(
            $sanitizedHtml,
            static function (string $keyword) use ($values): ?string {
                if (!self::isKnown($keyword) || !array_key_exists($keyword, $values)) {
                    return null;
                }

                $value = $values[$keyword];

                return ($value === null || trim($value) === '') ? '—' : $value;
            },
            true
        );
    }
}
