<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Document;

/**
 * A ready-to-use contract and invoice for a Belgian scout unit letting out
 * its own premises, offered as a starting point on the template page.
 *
 * **Why ship one at all.** An empty editor asks a volunteer to write a
 * rental contract from nothing, which in practice means either no contract
 * or one copied from whatever the previous chief had on a USB stick. The
 * terms below are the ones a unit letting a hall actually needs to state —
 * who is renting, for what, at what price, what a security deposit covers,
 * who cleans, what happens on cancellation — in the wording Belgian scout
 * federations use for exactly this (the model Atout Camp circulates for
 * "location de local" is the closest reference).
 *
 * **What it is NOT.** It is a starting point, not legal advice, and the
 * page says so. Every unit lets under its own conditions; the whole point
 * of the editor is that this text is edited. Nothing here is ever applied
 * behind anybody's back: a template is inserted only when a manager asks
 * for it, and never over text they have already written.
 *
 * **No VAT anywhere.** A unit letting a hall is not a VAT-registered
 * business in the general case, and the invoice carries the asset's own
 * exemption sentence instead (`{{ mention_tva }}`, §6.27).
 *
 * Every placeholder used below is on DocumentKeywords' closed list — which
 * StandardTemplatesTest asserts, so a keyword renamed there can never leave
 * these two shipping with braces in them.
 */
final class StandardTemplates
{
    /**
     * The standard body for $type, or null when that type has none.
     *
     * Null rather than an empty string: "there is no standard for this" and
     * "the standard is blank" are different answers, and only the first is
     * true of the uploaded document types.
     */
    public static function forType(DocumentType $type): ?string
    {
        return match ($type) {
            DocumentType::CONTRACT => self::contract(),
            DocumentType::INVOICE => self::invoice(),
            default => null,
        };
    }

    public static function contract(): string
    {
        return <<<'HTML'
            <h2>Convention de location</h2>

            <p><strong>Entre le bailleur</strong><br>
            {{ unite }}<br>
            {{ adresse_bailleur }}</p>

            <p><strong>Et le locataire</strong><br>
            {{ locataire_nom }}<br>
            {{ locataire_organisation }}<br>
            {{ locataire_adresse }}<br>
            {{ locataire_email }} — {{ locataire_telephone }}</p>

            <p>Dossier <strong>{{ reference }}</strong>, établi le {{ date_du_jour }}.</p>

            <h3>1. Objet</h3>
            <p>Le bailleur met à disposition du locataire&nbsp;: <strong>{{ bien }}</strong>
            ({{ bien_type }}), pour un séjour de {{ nuits }} nuit(s) et
            {{ participants }} participant(s).</p>
            <p>La capacité maximale d'accueil du bien est de {{ capacite }} personne(s),
            animateurs et intendance compris. Elle ne peut être dépassée.</p>

            <h3>2. Durée</h3>
            <p>Du <strong>{{ date_arrivee }}</strong> à partir de {{ heure_arrivee }},
            au <strong>{{ date_depart }}</strong> avant {{ heure_depart }}.</p>
            <p>Les lieux sont rendus libres à l'heure convenue. Toute occupation au-delà de
            cette heure peut être facturée.</p>

            <h3>3. Prix et paiement</h3>
            <p>Le montant total de la location s'élève à <strong>{{ prix_total }}</strong>.</p>
            <p>Un acompte de {{ acompte }} est demandé pour confirmer la réservation. Le solde
            est payable avant le début du séjour. Tout versement se fait sur le compte du
            bailleur avec la communication <strong>{{ communication }}</strong>.</p>
            <p>La réservation n'est définitive qu'après réception de l'acompte.</p>

            <h3>4. Garantie</h3>
            <p>Une garantie locative de {{ caution }} est versée avant le séjour. Elle est
            restituée dans le mois qui suit le départ, déduction faite des éventuels dégâts,
            du matériel manquant, des consommations non réglées ou des frais de nettoyage.</p>
            <p>La garantie n'est pas un plafond&nbsp;: si les dégâts la dépassent, le
            locataire reste redevable de la différence.</p>

            <h3>5. Charges et consommations</h3>
            <p>Sauf mention contraire dans le décompte annoncé, les consommations (électricité,
            eau, gaz, mazout) ne sont pas comprises dans le prix. Les compteurs sont relevés
            contradictoirement à l'arrivée et au départ, et les consommations sont facturées
            au prix coûtant sur le décompte final, avec les taxes éventuelles (taxe de
            séjour, déchets) annoncées lors de la demande.</p>

            <h3>6. État des lieux</h3>
            <p>Un état des lieux contradictoire est établi à l'arrivée et au départ, en
            présence des deux parties, et fait foi entre elles. Les compteurs sont relevés au
            même moment. Les dégâts éventuels sont constatés au plus tard le jour du départ.
            À défaut d'état des lieux d'entrée, les lieux sont réputés avoir été reçus en bon
            état.</p>

            <h3>7. Obligations du locataire</h3>
            <ul>
                <li>Occuper les lieux en bon père de famille et respecter le voisinage,
                    en particulier le calme après 22&nbsp;h.</li>
                <li>Rendre les lieux nettoyés&nbsp;: sols balayés et lavés, sanitaires et
                    cuisine propres, déchets triés et évacués.</li>
                <li>Ne pas dépasser le nombre de participants annoncé sans accord écrit.</li>
                <li>Ne pas sous-louer ni céder la présente convention.</li>
                <li>Signaler immédiatement au bailleur tout dégât ou dysfonctionnement.</li>
                <li>Respecter l'interdiction de fumer à l'intérieur des bâtiments et les
                    consignes relatives aux feux ouverts.</li>
            </ul>

            <h3>8. Assurance et responsabilité</h3>
            <p>Le locataire déclare être couvert par une assurance en responsabilité civile
            pour l'ensemble de son groupe et pour la durée du séjour, et en fournit la preuve
            sur simple demande. Le bailleur décline toute responsabilité en cas de vol, de
            perte ou de dommage aux biens personnels des occupants, ainsi qu'en cas
            d'accident lié à l'usage des lieux.</p>

            <h3>9. Annulation</h3>
            <p>Toute annulation est notifiée par écrit. L'acompte reste acquis au bailleur.
            En cas d'annulation moins de trente jours avant le début du séjour, le solde
            reste dû, sauf accord contraire des deux parties.</p>
            <p>Si le bailleur se trouve dans l'impossibilité de fournir les lieux, il
            rembourse l'intégralité des sommes versées, sans autre indemnité.</p>

            <h3>10. Traitement des données</h3>
            <p>Les données du locataire sont traitées uniquement pour la gestion de cette
            location et conservées le temps nécessaire à son suivi comptable et légal.</p>

            <h3>11. Litiges</h3>
            <p>La présente convention est régie par le droit belge. En cas de litige relatif
            à son exécution, les tribunaux de l'arrondissement où se situe le bien loué sont
            seuls compétents.</p>

            <h3>12. Acceptation</h3>
            <p>Fait en deux exemplaires. La signature de la présente convention vaut
            acceptation de l'ensemble de ses conditions.</p>

            <p>Pour le bailleur&nbsp;: ..............................................<br>
            Pour le locataire&nbsp;: ..............................................</p>
            HTML;
    }

    public static function invoice(): string
    {
        return <<<'HTML'
            <h2>Facture</h2>

            <p><strong>{{ unite }}</strong><br>
            {{ adresse_bailleur }}</p>

            <p><strong>Facturé à</strong><br>
            {{ locataire_nom }}<br>
            {{ locataire_organisation }}<br>
            {{ locataire_adresse }}<br>
            N° TVA / BCE&nbsp;: {{ locataire_tva }}</p>

            <p>Date&nbsp;: {{ date_du_jour }}<br>
            Référence&nbsp;: <strong>{{ reference }}</strong></p>

            <h3>Objet</h3>
            <p>Location de <strong>{{ bien }}</strong> ({{ bien_type }}), du
            {{ date_arrivee }} au {{ date_depart }} — {{ nuits }} nuit(s),
            {{ participants }} participant(s).</p>

            <h3>Montant</h3>
            <p>Total à payer&nbsp;: <strong>{{ prix_total }}</strong></p>
            <p>Le détail des lignes figure en annexe de la présente facture.</p>

            <h3>Paiement</h3>
            <p>À verser sur le compte du bailleur avec la communication
            <strong>{{ communication }}</strong>, dans les trente jours.</p>
            <p>Garantie locative&nbsp;: {{ caution }}. Elle ne fait pas partie du prix de la
            location et est restituée après le séjour.</p>

            <p><em>{{ mention_tva }}</em></p>
            HTML;
    }
}
