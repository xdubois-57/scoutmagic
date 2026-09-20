<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Document;

/**
 * A ready-to-use contract, invoice and set of rental conditions for a
 * Belgian scout unit letting out its own premises, offered as a starting
 * point on the template and settings pages.
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
 *
 * **The conditions are the third text and the odd one out**: they are not a
 * DocumentType, they carry no placeholder at all, and they are read by a
 * visitor who has no booking yet — so there is nothing to substitute into
 * them. They ship here anyway, for the reason the other two do: an asset
 * whose managers never wrote any used to show a visitor an empty
 * « Conditions de location » block above a mandatory « J'accepte les
 * conditions de location » tick-box, and the acceptance hash then attested
 * to nothing at all (Document\AssetConditions).
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

    /**
     * The conditions a renter accepts on the public request form.
     *
     * Not legal advice, and the page that shows it says so — same standing
     * as the contract above, and for the same reason: a unit lets under its
     * own conditions, and the whole point of the editor is that this text is
     * edited.
     */
    public static function conditions(): string
    {
        return <<<'HTML'
            <p>Ces conditions s'appliquent à toute demande introduite auprès de l'unité.
            Elles sont acceptées au moment de l'envoi du formulaire, et le texte accepté
            est conservé tel qu'il était affiché ce jour-là.</p>

            <h3>1. Ce qu'une demande engage</h3>
            <p>Une demande n'est pas une réservation. Elle ouvre une conversation : l'unité
            la confirme ou la refuse, et rien n'est réservé tant qu'elle ne l'a pas
            confirmée. Les dates sont toutefois bloquées le temps que l'unité réponde, afin
            que deux demandes ne se croisent pas.</p>

            <h3>2. Prix, acompte et solde</h3>
            <p>Le prix annoncé lors de la demande est une estimation calculée sur les
            informations fournies. Le prix convenu est celui qui figure sur la convention de
            location ; c'est lui qui fait foi.</p>
            <p>Un acompte peut être demandé pour confirmer la réservation. Le solde est dû
            avant l'arrivée, sauf accord écrit différent. Les paiements se font par virement,
            avec la communication indiquée — jamais en espèces sans reçu.</p>

            <h3>3. Caution</h3>
            <p>Une caution peut être demandée avant l'arrivée. Elle n'est pas un acompte :
            elle ne s'impute pas sur le prix de la location et est restituée après le séjour,
            déduction faite des dégâts constatés, du nettoyage non fait et des consommations
            non réglées. Le décompte est communiqué par écrit.</p>

            <h3>4. Annulation</h3>
            <p>Une annulation se signale sans délai, par écrit. Ce qui reste dû après une
            annulation dépend du moment où elle intervient et des frais déjà engagés ;
            l'unité en informe le locataire par écrit. L'acompte peut rester acquis à
            l'unité.</p>
            <p>L'unité peut annuler une réservation en cas de force majeure, d'occupation
            prioritaire liée à ses propres activités annoncée suffisamment tôt, ou de
            non-paiement. Les sommes déjà versées sont alors remboursées.</p>

            <h3>5. Occupation des lieux</h3>
            <p>Le bien est occupé par le groupe annoncé, pour l'objet annoncé et pour la
            durée convenue. La capacité maximale d'accueil ne peut être dépassée, encadrants
            et intendance compris. La sous-location et la cession de la réservation sont
            interdites.</p>
            <p>Le locataire désigne une personne majeure responsable, présente pendant toute
            la durée du séjour et joignable par l'unité.</p>

            <h3>6. État des lieux, nettoyage et clés</h3>
            <p>Un état des lieux est établi à l'arrivée et au départ, contradictoirement
            lorsque c'est possible. Les relevés de compteurs sont pris aux mêmes moments et
            les consommations sont facturées après le séjour.</p>
            <p>Le bien est rendu propre, rangé et vidé de ses déchets, dans l'état où il a
            été reçu. Les clés sont restituées selon les modalités convenues ; une clé perdue
            est facturée au prix du remplacement de la serrure lorsque celui-ci s'impose.</p>

            <h3>7. Sécurité et voisinage</h3>
            <p>Les feux ouverts ne sont autorisés qu'aux emplacements prévus et en respectant
            les interdictions communales en vigueur. Il est interdit de fumer à l'intérieur
            des bâtiments. Les issues de secours, les extincteurs et les chemins d'accès
            restent dégagés en permanence.</p>
            <p>Le calme est respecté, en particulier la nuit. Un trouble répété du voisinage
            peut mettre fin au séjour sans remboursement.</p>

            <h3>8. Responsabilité et assurance</h3>
            <p>Le locataire est responsable des dommages causés au bien, à son équipement et
            à ses abords pendant toute la durée du séjour, quel qu'en soit l'auteur. Il est
            tenu d'être couvert par une assurance en responsabilité civile et, le cas
            échéant, par l'assurance de son mouvement de jeunesse.</p>
            <p>L'unité n'est pas responsable des vols, des pertes ni des dommages aux biens
            personnels des occupants.</p>

            <h3>9. Données personnelles</h3>
            <p>Les informations transmises servent à traiter la demande, établir les
            documents et joindre le locataire. Elles ne sont communiquées à personne d'autre
            et sont conservées le temps prévu par la politique de confidentialité du site.</p>

            <h3>10. Droit applicable</h3>
            <p>La présente location est soumise au droit belge. En cas de différend, les
            parties cherchent d'abord une solution amiable.</p>
            HTML;
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
