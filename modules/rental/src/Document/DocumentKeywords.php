<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Document;

use Core\Pdf\LinkedText;
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
 * catalogue, and the one value printed as a link (`substitute()`), whose
 * escaping `Core\Pdf\LinkedText` does in the engine's place.
 */
final class DocumentKeywords
{
    /**
     * Every keyword, with the French explanation shown beside the editor.
     *
     * @var array<string, string>
     */
    private const CATALOGUE = [
        'reference' => 'La référence du dossier, par exemple LOC-K7Q2M4',
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
        'nom_bailleur' => "Le nom du bailleur : celui des réglages du module s'il est rempli, sinon l'unité",
        'adresse_bailleur' => "L'adresse du bailleur, ou celle de l'unité si aucun bailleur n'est configuré",
        'bce_bailleur' => "Le numéro d'entreprise (BCE) du bailleur, ou « — »",
        'unite' => "Le nom de l'unité, qui n'est pas forcément le bailleur",
        'date_du_jour' => "La date du jour de génération",
        'mention_tva' => 'La mention d\'exonération de TVA configurée pour ce bien',
        'conditions_acceptees' => 'La version des conditions de location acceptée avec la demande, et son adresse, '
            . 'cliquable dans le PDF',
        // Issue #977 — what annex 8.1 of the Walloon tourism code requires
        // a camp-site rental contract to state.
        'bien_adresse' => "L'adresse du bien loué, réglée dans la section « Contrat » des réglages du bien",
        'duree_nuits' => 'La durée du séjour, accordée : « 1 nuit », « 3 nuits »',
        'nombre_participants' => 'Le nombre de participants annoncé, accordé : « 25 participants », ou « — »',
        'capacite_personnes' => "La capacité maximale d'accueil, accordée : « 60 personnes », ou « — »",
        'responsable_nom' => 'Le responsable du groupe sur place, ou « — »',
        'responsable_telephone' => 'Le téléphone du responsable du groupe sur place, ou « — »',
        'iban' => "Le numéro de compte (IBAN) du compte Finances du bien, ou « à communiquer par écrit "
            . "par le bailleur » sans compte configuré",
        'titulaire_compte' => 'Le titulaire de ce compte, ou « le bailleur » sans compte configuré',
        'echeance_acompte' => "Quand verser l'acompte : « au plus tard le 15/03/2027 », ou combien de jours "
            . "après l'envoi du contrat",
        'echeance_solde' => 'Quand verser le solde : « au plus tard le 12/06/2027 », ou « avant le début du séjour »',
        'date_limite_desistement' => 'La date limite de désistement sans indemnité, au format 31/12/2027, '
            . 'ou « — » quand elle était passée à la génération du contrat',
        'desistement_sans_indemnite' => 'La phrase sur le désistement sans indemnité : sa date limite, ou que '
            . 'cette période ne s\'applique pas à la réservation',
        'bareme_desistement' => "L'indemnité due après cette date, avec ses dates : cinquante pour cent "
            . "jusqu'à un mois avant le séjour, soixante-quinze pour cent ensuite",
        'mention_bce_bailleur' => "« , n° d'entreprise 0123.456.789 », à placer après le nom du bailleur ; "
            . "rien quand il n'en a pas",
        'contact_donnees' => "Où écrire pour les données personnelles : l'adresse de contact du site, "
            . "ou celle du bailleur",
        'duree_conservation' => 'Combien de temps les données de la location sont conservées',
        // Issue #980 — the supplementary invoice; « — » anywhere else.
        'numero_facture_complementaire' => 'Facture complémentaire : son numéro, la référence suivie de C1, C2…',
        'facture_origine' => "Facture complémentaire : la facture qu'elle complète, son numéro et sa date, "
            . 'par exemple « LOC-K7Q2M4-v1 du 12/07/2027 »',
        'date_decompte' => 'Facture complémentaire : la date de validation du décompte final qu\'elle facture',
        'detail_complement' => 'Facture complémentaire : les lignes du décompte ajoutées ou retirées depuis '
            . 'ce qui a déjà été facturé',
        'montant_deja_facture' => 'Facture complémentaire : le montant que la facture envoyée, et les '
            . 'factures complémentaires avant celle-ci, ont déjà facturé',
        'montant_decompte' => 'Facture complémentaire : le montant conservé du décompte final validé',
        'montant_complement' => 'Facture complémentaire : la différence, en faveur du locataire quand '
            . 'elle est négative',
        'reglement_complement' => 'Facture complémentaire : le restant dû avec son échéance, le compte et la '
            . 'communication, ou le montant que le bailleur rembourse',
    ];

    /**
     * The keywords whose empty value prints NOTHING rather than « — ».
     *
     * Each is a mention that only exists when its value does — « , n°
     * d'entreprise … » after the landlord's name: a dash there would print
     * « N° d'entreprise : — » on the contract of every landlord without
     * one, which is most of them (issue #977).
     */
    private const OMITTED_WHEN_EMPTY = ['mention_bce_bailleur'];

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
     * Removes inline markup a rich-text surface left INSIDE a keyword —
     * `{{ pri<b>x</b>_total }}` back to `{{ prix_total }}`.
     *
     * Runs **after sanitizing and before substitution** (§22.6): the
     * sanitizer is what decides which markup exists at all, and after
     * substitution there is no keyword left to rescue.
     *
     * A region is only rewritten once what it would become is a real
     * keyword, so a contract whose prose happens to sit between braces is
     * left alone and the « mots-clés non reconnus » warning stays the net
     * it was meant to be — see Core\Template\TokenEngine.
     */
    public static function repairSplitKeywords(string $sanitizedHtml): string
    {
        return self::engine()->repairTokensSplitByMarkup($sanitizedHtml);
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
     * **A keyword in `$links` becomes a link** (issue #977): its value is
     * printed as before, and the web address it contains is wrapped in an
     * `<a href>` dompdf turns into a clickable annotation — still printed,
     * so a paper copy reads the same (`Core\Pdf\LinkedText`).
     *
     * @param array<string, string|null> $values Raw, unescaped. A null renders as an em dash.
     * @param array<string, string> $links keyword => the address its value contains
     */
    public static function substitute(string $sanitizedHtml, array $values, array $links = []): string
    {
        // Escaping is the whole safety of this class: dompdf renders HTML,
        // and these values come from a form an anonymous visitor filled in.
        // Done here rather than by the engine so a link can be built around
        // an address that was escaped like everything else — LinkedText
        // escapes the whole line, the address included.
        return self::engine()->substitute(
            $sanitizedHtml,
            static function (string $keyword) use ($values, $links): ?string {
                if (!self::isKnown($keyword) || !array_key_exists($keyword, $values)) {
                    return null;
                }

                $value = $values[$keyword];
                if ($value === null || trim($value) === '') {
                    return in_array($keyword, self::OMITTED_WHEN_EMPTY, true) ? '' : '—';
                }

                return (new LinkedText($value, $links[$keyword] ?? null))->toHtml();
            },
            false
        );
    }
}
