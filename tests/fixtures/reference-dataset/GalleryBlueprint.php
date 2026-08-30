<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Fixtures\ReferenceDataset;

/**
 * The photo gallery, as data — one **external** album and nothing else.
 *
 * The gallery module handles two kinds of album: `local`, whose photos it
 * stores and re-encodes itself, and `external`, which is a link to a third
 * party plus the Open Graph metadata scraped from it (its `og:title`,
 * `og:description` and a cached copy of its `og:image`). Only the second is
 * seeded here, and deliberately:
 *
 *  - **A local album would mean photographs of minors.** The dataset's own
 *    photo lot is synthetic, but an album is exactly the surface where a
 *    reader stops reading the fixture and starts believing the pictures. The
 *    unit's synthetic portraits belong to the trombinoscope, which is what
 *    they were produced for.
 *  - **The external type is the one nothing else exercises.** Its whole
 *    behaviour — normalise the URL, scrape the Open Graph tags, cache the
 *    image, degrade gracefully when the link answers nothing — happens at
 *    creation and nowhere else.
 *
 * The link is a **stable, public, personal-data-free demonstration page**:
 * Wikimedia Commons' own "Commons:Featured pictures" page, which carries
 * Open Graph tags, is not going to move, and shows nobody's children.
 *
 * **The scrape may well fail on the machine that builds this**, and that is
 * fine by design: `AlbumService::create()` treats the Open Graph fetch as
 * best-effort (`fetchOgTagsBestEffort()`), so an album whose target could not
 * be reached is created anyway, with the title declared below instead of the
 * scraped one. A build behind a restricted network therefore produces an
 * album that works and has no thumbnail, which is one of the states the site
 * has to be seen handling.
 */
final class GalleryBlueprint
{
    /**
     * @var list<array{title: string, subtitle: ?string, day: int, year: string, section: ?string, url: string}>
     */
    public const EXTERNAL_ALBUMS = [
        [
            'title' => "Photos du camp — album partagé",
            'subtitle' => "Hébergé chez un tiers : la galerie ne stocke que le lien et sa vignette.",
            'year' => '2025-2026',
            'day' => 330,
            'section' => null,
            'url' => 'https://commons.wikimedia.org/wiki/Commons:Featured_pictures',
        ],
    ];
}
