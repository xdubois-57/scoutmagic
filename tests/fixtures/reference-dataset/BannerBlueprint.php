<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Fixtures\ReferenceDataset;

/**
 * The homepage banners, as data.
 *
 * **`role_min` is what these are here for.** The homepage shows one random
 * ACTIVE banner the viewer's role reaches (BannerService::
 * getRandomBannerHtml()), so a set that is all `public` proves nothing: the
 * four below span `public`, `identified` and `chief`, and one is inactive —
 * the state an admin leaves a banner in between two campaigns, and the one a
 * reader is most likely to assume does not exist.
 *
 * The formatted text does NOT live in the `banners` table. It lives in the
 * core `editable_contents` table under `banner_content_{id}`, the same
 * rich-text engine and the same sanitizer as the rest of the site
 * (ARCHITECTURE.md §8.13) — which is why BannerSeeder writes it through
 * Core\View\EditableContentService and never through the banner repository.
 */
final class BannerBlueprint
{
    /**
     * @var list<array{roleMin: string, isActive: bool, html: string}>
     */
    public const BANNERS = [
        [
            'roleMin' => 'public',
            'isActive' => true,
            'html' => '<p><strong>Les inscriptions sont ouvertes !</strong> '
                . 'Une demande par enfant, sur la page <a href="/inscriptions">Inscriptions</a>.</p>',
        ],
        [
            'roleMin' => 'public',
            'isActive' => true,
            'html' => "<p>Fête d'unité le mois prochain au Terrain du Sart — bar, barbecue et grands jeux. "
                . 'Tout le monde est bienvenu.</p>',
        ],
        [
            'roleMin' => 'identified',
            'isActive' => true,
            'html' => "<p>Pensez à vérifier vos coordonnées dans votre espace membre : c'est là que l'unité "
                . 'ira chercher votre numéro le jour où il faudra vous joindre.</p>',
        ],
        [
            'roleMin' => 'chief',
            'isActive' => true,
            'html' => "<p><strong>Staff :</strong> les fiches santé du camp sont à rentrer avant le 15 juin. "
                . "Le formulaire est dans l'espace chefs.</p>",
        ],
        [
            // Désactivée : ce que devient une bannière entre deux campagnes.
            // Elle reste modifiable en configuration et n'apparaît nulle part.
            'roleMin' => 'public',
            'isActive' => false,
            'html' => '<p>Souper de soutien annulé — nous revenons vers vous avec une nouvelle date.</p>',
        ],
    ];
}
