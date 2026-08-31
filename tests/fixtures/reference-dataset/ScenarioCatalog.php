<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Fixtures\ReferenceDataset;

/**
 * The named scenarios, and the Tiers that carry each one.
 *
 * This table is the point of the whole dataset. A scenario that is not named
 * here is a scenario nobody can assert on: the end-to-end import test (IT-03)
 * reads these Tiers by name, so a member who quietly stopped moving between
 * branches fails a test instead of silently becoming an ordinary member.
 *
 * The Tiers are pinned rather than derived. A derived id would shift the day
 * somebody inserts a person above it, and every assertion in the suite would
 * move with it — which is exactly the silent drift §4 of the chantier exists
 * to prevent.
 *
 * `T0001`-`T0099` are reserved for scenarios; the filler population starts at
 * `T0101`.
 */
final class ScenarioCatalog
{
    /**
     * @var array<int, array{name: string, tiers: list<string>, expectation: string}>
     */
    public const SCENARIOS = [
        1 => [
            'name' => 'Continuité simple',
            'tiers' => ['T0001'],
            'expectation' => 'Même section les trois années, année-dans-la-branche +1 à chaque fois.',
        ],
        2 => [
            'name' => 'Passage de branche à chaque frontière',
            'tiers' => ['T0002', 'T0003', 'T0004', 'T0005'],
            'expectation' => 'Baladins→Louveteaux, Louveteaux→Éclaireurs, Éclaireurs→Pionniers, Pionniers→Route, un Tiers par frontière.',
        ],
        3 => [
            'name' => 'Changement de section dans la même branche',
            'tiers' => ['T0006', 'T0007'],
            'expectation' => 'T0006 passe de Ribambelle Bleue à Ribambelle Verte en A2 (toujours Baladins) ; T0007 de Seeonee à Waingunga (toujours Louveteaux).',
        ],
        4 => [
            'name' => 'Départ',
            'tiers' => ['T0008'],
            'expectation' => 'Présent en A1, absent ensuite. La ligne members survit, il n\'existe pas de member_years en A2.',
        ],
        5 => [
            'name' => 'Retour après une année d\'absence',
            'tiers' => ['T0009'],
            'expectation' => 'Présent A1, absent A2, présent A3. Le scout_year_offset posé en A1 (extras, IT-06) doit être hérité en A3, par start_date et non par id.',
        ],
        6 => [
            'name' => 'Arrivées en cours de dataset',
            'tiers' => ['T0010', 'T0011'],
            'expectation' => 'T0010 arrive en A2, T0011 en A3. Aucun member_years avant leur première année.',
        ],
        7 => [
            'name' => 'Totem obtenu entre deux années',
            'tiers' => ['T0012'],
            'expectation' => 'Ni totem ni quali en A1 ; les deux renseignés en A2 et A3.',
        ],
        8 => [
            'name' => 'Pionnier en dernière année devenu animateur',
            'tiers' => ['T0013'],
            'expectation' => 'Animé Pionniers en A1 et A2, Animateur Baladins en A3. Sa photo le suit : son fichier garde un préfixe pionnier_.',
        ],
        9 => [
            'name' => 'Animateur changeant de section',
            'tiers' => ['T0014'],
            'expectation' => 'Animateur de Seeonee en A1, de Waingunga en A2 et A3. Sa photo le suit également.',
        ],
        10 => [
            'name' => 'Animateur devenu chef d\'unité',
            'tiers' => ['T0015'],
            'expectation' => 'Animateur avec section en A1 ; Chef d\'unité sans section en A2 et A3, donc rattaché à Staff d\'U — mais seulement une fois le rôle admin confirmé dans Config Desk.',
        ],
        11 => [
            'name' => 'Intendant',
            'tiers' => ['T0016'],
            'expectation' => 'Intendant d\'unité les trois années, fonction sans branche ni section.',
        ],
        12 => [
            'name' => 'Deux fonctions dont une principale',
            'tiers' => ['T0017'],
            'expectation' => 'Animateur (principale, avec section) + Trésorier d\'unité (secondaire, sans section). Deux lignes par adresse, pas une.',
        ],
        13 => [
            'name' => 'Fonction « candidat »',
            'tiers' => ['T0018'],
            'expectation' => 'Animateur candidat en A2, Animateur en A3. Rien ne consomme le mot aujourd\'hui ; le test vérifie que le libellé traverse l\'import intact.',
        ],
        14 => [
            'name' => 'FONCTION inédite en A3',
            'tiers' => ['T0019'],
            'expectation' => 'Accompagnateur d\'unité n\'existe qu\'en A3 : la fonction doit être créée en role=identified, confirmed=false, et compter dans newFunctionsCount.',
        ],
        15 => [
            'name' => 'Section vidée',
            'tiers' => [],
            'expectation' => 'Iama Horizon n\'a plus aucun membre en A3 : la section doit passer is_active=false, jamais être supprimée.',
        ],
        16 => [
            'name' => 'Section apparaissant en A2',
            'tiers' => [],
            'expectation' => 'Ribambelle Verte n\'existe pas en A1 et apparaît en A2 : elle doit être créée à cet import-là, pas avant.',
        ],
        17 => [
            'name' => 'Fratrie de 2 puis 3',
            'tiers' => ['T0020', 'T0021', 'T0022'],
            'expectation' => 'Même email de parent et même adresse postale ; deux enfants en A1, trois à partir de A2. Le blind index d\'adresse doit être identique pour les trois — c\'est ce qui alimente le comptage de foyer de FeeEstimationService.',
        ],
        18 => [
            'name' => 'Fratrie dont un enfant part',
            'tiers' => ['T0023', 'T0024', 'T0025'],
            'expectation' => 'Trois enfants en A1, T0025 absent ensuite. Le foyer passe de 3 à 2.',
        ],
        19 => [
            'name' => 'Membre à deux adresses',
            'tiers' => ['T0026'],
            'expectation' => 'Domicile + Adresse secondaire. Deux lignes portant la MÊME fonction : deux adresses dédupliquées par type, et UNE seule ligne member_functions — l\'import réduit deux lignes identiques à la fonction qu\'elles décrivent.',
        ],
        20 => [
            'name' => 'Membre sans email, sans date de naissance, sans téléphone fixe',
            'tiers' => ['T0027'],
            'expectation' => 'Aucun compte utilisateur créé pour lui, aucun âge effectif, aucune branche déduite.',
        ],
        21 => [
            'name' => 'Handicap et assurance complémentaire',
            'tiers' => ['T0028'],
            'expectation' => 'Handicap renseigné (donnée de santé, chiffrée au repos) et Assurance complémentaire renseignée.',
        ],
        22 => [
            'name' => 'Noms accentués, apostrophe, particule, trait d\'union',
            'tiers' => ['T0029', 'T0030', 'T0031', 'T0032'],
            'expectation' => 'Étienne, N\'Diaye, Van der Meulen, Dubois-Lefèvre — l\'encodage doit survivre au CSV, au chiffrement et à l\'affichage.',
        ],
        23 => [
            'name' => 'Changement de tarif',
            'tiers' => ['T0033'],
            'expectation' => 'Tarif normal en A1, Tarif réduit ensuite : deux fee_categories, et le member_years pointe vers la bonne chaque année.',
        ],
        24 => [
            'name' => 'Équilibre filles/garçons non trivial',
            'tiers' => [],
            'expectation' => 'La part de F n\'est jamais à moins de 5 points de 50 % (elle tourne autour de 40 %), et elle bouge d\'une année à l\'autre : les graphes de Prévisions et de Statistiques doivent avoir quelque chose à montrer.',
        ],
    ];

    /** First Tiers of the filler population — everything below is a scenario. */
    public const FILLER_FIRST_ID = 101;

    /** @return list<string> every Tiers pinned by a scenario */
    public static function pinnedTiers(): array
    {
        $tiers = [];
        foreach (self::SCENARIOS as $scenario) {
            foreach ($scenario['tiers'] as $id) {
                $tiers[] = $id;
            }
        }

        return $tiers;
    }

    /** The scenario a Tiers belongs to, or null for the filler population. */
    public static function scenarioOf(string $tiers): ?int
    {
        foreach (self::SCENARIOS as $number => $scenario) {
            if (in_array($tiers, $scenario['tiers'], true)) {
                return $number;
            }
        }

        return null;
    }
}
