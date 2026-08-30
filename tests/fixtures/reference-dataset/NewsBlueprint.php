<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Fixtures\ReferenceDataset;

use Modules\News\Repository\FormField;
use Modules\News\Repository\NewsForm;

/**
 * The unit's news page, declared as data.
 *
 * Five articles, chosen so that the four things a reader of this module can
 * observe are all present at once:
 *
 *  - **the visibility ladder**, `public` / `identified` / `chief`, plus one
 *    `direct_link` article — which is not a rung of the ladder but "listed
 *    nowhere", and is the case a dataset almost always forgets;
 *  - **a form, with responses already filed**. An empty form shows neither
 *    the responses page, nor the export, nor the digest: the page exists and
 *    says "aucune réponse", which looks exactly like a broken feature. Two of
 *    the five articles carry one, and both arrive with answers in them;
 *  - **an image inside the body**, stored as `/files/{id}` in the rich text
 *    under `news_body_{id}` — the same key the module uses, so the body goes
 *    through Core\View\EditableContentService and its sanitizer rather than
 *    into a column of its own;
 *  - **a capacity that is nearly, but not quite, used up**, so the
 *    "il reste N places" path is exercised rather than assumed.
 *
 * **Every article has a cover image, and that is not an oversight.**
 * Modules\News\Service\ArticleService::create() refuses a null
 * `image_file_id` outright ("Une image est obligatoire pour l'article."):
 * the column is nullable only so rows written before it existed stay
 * migratable. An article without a cover is therefore not something this
 * dataset can produce without bypassing the service, which is the one thing
 * it never does. See README.md §8.3.
 */
final class NewsBlueprint
{
    /**
     * The placeholder a body uses to say "the in-body image goes here". The
     * seeder replaces it with an `<img>` pointing at the file it uploaded,
     * because the id is only known once the upload has happened.
     */
    public const BODY_IMAGE_PLACEHOLDER = '{{image}}';

    /**
     * @var list<array{
     *   title: string,
     *   summary: string,
     *   visibility: string,
     *   isIndexed: bool,
     *   seoKeywords: ?string,
     *   cover: string,
     *   bodyImage: ?string,
     *   body: string,
     *   form: ?array{
     *     access: string,
     *     responseLimit: string,
     *     responseRoleMin: string,
     *     dailyDigest: bool,
     *     fields: list<array{field_type: string, label: ?string, is_required: bool, options_source: ?string, options_manual: ?string, capacity_max: ?int, price_per_unit: ?float, confirmation_text: ?string}>,
     *     responses: list<array{email: string, answers: array<int, string>}>
     *   }
     * }>
     */
    public const ARTICLES = [
        [
            'title' => 'Les inscriptions sont ouvertes',
            'summary' => "Le formulaire d'inscription pour la prochaine année scoute est en ligne.",
            'visibility' => 'public',
            'isIndexed' => true,
            'seoKeywords' => 'inscription, scouts, unité',
            'cover' => 'unite_group_001.jpg',
            'bodyImage' => null,
            'body' => "<p>Les inscriptions pour la prochaine année scoute sont ouvertes.</p>"
                . "<p>Rendez-vous sur la page <a href=\"/inscriptions\">Inscriptions</a> : remplissez une demande par enfant, "
                . "et vous recevrez un lien de suivi par email.</p>"
                . "<p>Les places sont limitées par tranche d'âge. Une demande n'est pas encore une acceptation.</p>",
            'form' => null,
        ],
        [
            'title' => "Fête d'unité : le programme",
            'summary' => "Jeux, bar, barbecue : le programme de la journée et le formulaire de participation.",
            'visibility' => 'public',
            'isIndexed' => true,
            'seoKeywords' => "fête d'unité, barbecue, familles",
            'cover' => 'baladin_group_001.jpg',
            'bodyImage' => 'louveteau_group_001.jpg',
            'body' => "<p>La fête d'unité se tiendra au Terrain du Sart, de 10h à 18h.</p>"
                . NewsBlueprint::BODY_IMAGE_PLACEHOLDER
                . "<p>Le bar et le barbecue sont tenus par le staff d'unité. Inscrivez-vous ci-dessous pour que "
                . "l'intendance sache combien de saucisses acheter.</p>",
            'form' => [
                'access' => NewsForm::ACCESS_PUBLIC,
                'responseLimit' => NewsForm::RESPONSE_LIMIT_UNLIMITED,
                'responseRoleMin' => 'chief',
                'dailyDigest' => true,
                'fields' => [
                    ['field_type' => FormField::TYPE_SHORT_TEXT, 'label' => 'Nom de famille', 'is_required' => true, 'options_source' => null, 'options_manual' => null, 'capacity_max' => null, 'price_per_unit' => null, 'confirmation_text' => null],
                    ['field_type' => FormField::TYPE_NUMBER, 'label' => 'Nombre de personnes', 'is_required' => true, 'options_source' => null, 'options_manual' => null, 'capacity_max' => null, 'price_per_unit' => null, 'confirmation_text' => null],
                    ['field_type' => FormField::TYPE_DROPDOWN, 'label' => 'Repas', 'is_required' => true, 'options_source' => 'manual', 'options_manual' => "Barbecue\nVégétarien\nPas de repas", 'capacity_max' => null, 'price_per_unit' => null, 'confirmation_text' => null],
                    ['field_type' => FormField::TYPE_LONG_TEXT, 'label' => 'Allergies ou remarques', 'is_required' => false, 'options_source' => null, 'options_manual' => null, 'capacity_max' => null, 'price_per_unit' => null, 'confirmation_text' => null],
                    ['field_type' => FormField::TYPE_CONFIRMATION, 'label' => null, 'is_required' => false, 'options_source' => null, 'options_manual' => null, 'capacity_max' => null, 'price_per_unit' => null, 'confirmation_text' => "Je confirme ma participation et je préviendrai en cas d'empêchement."],
                ],
                'responses' => [
                    ['email' => 'famille.bastin@example.org', 'answers' => [0 => 'Bastin', 1 => '4', 2 => 'Barbecue', 3 => '']],
                    ['email' => 'famille.collard@example.org', 'answers' => [0 => 'Collard', 1 => '2', 2 => 'Végétarien', 3 => 'Une allergie aux fruits à coque.']],
                    ['email' => 'famille.dumont@example.org', 'answers' => [0 => 'Dumont', 1 => '5', 2 => 'Barbecue', 3 => '']],
                    ['email' => 'famille.hallet@example.org', 'answers' => [0 => 'Hallet', 1 => '3', 2 => 'Pas de repas', 3 => 'Nous passerons seulement l\'après-midi.']],
                    ['email' => 'famille.lejeune@example.org', 'answers' => [0 => 'Lejeune', 1 => '2', 2 => 'Barbecue', 3 => '']],
                    ['email' => 'famille.renard@example.org', 'answers' => [0 => 'Renard', 1 => '6', 2 => 'Barbecue', 3 => 'Nous amenons une tarte.']],
                ],
            ],
        ],
        [
            'title' => "Weekend de staff : inscrivez-vous",
            'summary' => "Le weekend de staff se prépare — vingt places, et il en reste peu.",
            'visibility' => 'chief',
            'isIndexed' => false,
            'seoKeywords' => null,
            'cover' => 'unite_group_002.jpg',
            'bodyImage' => null,
            'body' => "<p>Le weekend de staff se tiendra au Gîte de la Sapinière.</p>"
                . "<p>Vingt couchages, pas un de plus : inscrivez-vous vite. Le formulaire ferme dès que "
                . "les places sont prises.</p>",
            'form' => [
                'access' => NewsForm::ACCESS_IDENTIFIED,
                'responseLimit' => NewsForm::RESPONSE_LIMIT_ONE_PER_ACCOUNT,
                'responseRoleMin' => 'admin',
                'dailyDigest' => false,
                'fields' => [
                    ['field_type' => FormField::TYPE_SHORT_TEXT, 'label' => 'Totem ou prénom', 'is_required' => true, 'options_source' => null, 'options_manual' => null, 'capacity_max' => null, 'price_per_unit' => null, 'confirmation_text' => null],
                    // Nearly full on purpose: seventeen of the twenty places
                    // are taken by the responses below, so the "il reste 3
                    // places" path is what the page actually renders.
                    ['field_type' => FormField::TYPE_NUMBER, 'label' => 'Nombre de couchages', 'is_required' => true, 'options_source' => null, 'options_manual' => null, 'capacity_max' => 20, 'price_per_unit' => 25.0, 'confirmation_text' => null],
                    ['field_type' => FormField::TYPE_RADIO, 'label' => 'Arrivée', 'is_required' => true, 'options_source' => 'manual', 'options_manual' => "Vendredi soir\nSamedi matin", 'capacity_max' => null, 'price_per_unit' => null, 'confirmation_text' => null],
                ],
                'responses' => [
                    ['email' => 'staff.seeonee@example.org', 'answers' => [0 => 'Chamois', 1 => '5', 2 => 'Vendredi soir']],
                    ['email' => 'staff.waingunga@example.org', 'answers' => [0 => 'Ibis', 1 => '3', 2 => 'Vendredi soir']],
                    ['email' => 'staff.faucon@example.org', 'answers' => [0 => 'Okapi', 1 => '6', 2 => 'Samedi matin']],
                    ['email' => 'staff.escaut@example.org', 'answers' => [0 => 'Fennec', 1 => '3', 2 => 'Vendredi soir']],
                ],
            ],
        ],
        [
            'title' => 'Retour sur le camp de la Meute',
            'summary' => "Dix jours à Bomal : le récit du camp et quelques photos.",
            'visibility' => 'identified',
            'isIndexed' => false,
            'seoKeywords' => null,
            'cover' => 'louveteau_group_002.jpg',
            'bodyImage' => 'louveteau_group_003.jpg',
            'body' => "<p>Dix jours de camp, une grande construction, un hike sous la pluie et un feu de camp "
                . "que personne n'oubliera.</p>"
                . NewsBlueprint::BODY_IMAGE_PLACEHOLDER
                . "<p>Merci aux parents qui ont conduit, à l'intendance qui a tenu, et aux Louveteaux qui "
                . "ont tout mangé.</p>",
            'form' => null,
        ],
        [
            'title' => 'Vente de calendriers — mode d\'emploi',
            'summary' => "La page à envoyer aux familles qui demandent comment payer leur calendrier.",
            'visibility' => 'direct_link',
            'isIndexed' => false,
            'seoKeywords' => null,
            'cover' => 'pionnier_group_001.jpg',
            'bodyImage' => null,
            'body' => "<p>Chaque famille reçoit une communication structurée qui lui est propre : "
                . "utilisez-la telle quelle, sans rien y ajouter.</p>"
                . "<p>Un virement sans communication structurée n'est rattaché à personne, et c'est "
                . "l'intendant qui doit alors deviner.</p>",
            'form' => null,
        ],
    ];
}
