<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Geo;

/**
 * The links that open a route to a place, one per map application the
 * reader may choose (issues #703, #1345).
 *
 * **The reader chooses, the site never guesses.** A carpool page offers
 * three applications — Plans (Apple), Google Maps and Waze — on every
 * device: the browser cannot list the applications a phone has installed,
 * and an iPhone owner may well prefer Waze. There is no « other
 * application » entry (a `geo:` link) and no remembered choice. Each link is
 * the provider's own https:// one, which hands the route to the installed
 * application where there is one and falls back on the provider's web page
 * where there is not.
 *
 * **One destination for all three**: the point when there is one, the
 * address otherwise — a link that only worked once geocoding had run would
 * be useless on exactly the outings whose address a gazetteer does not
 * know. The displayed address never replaces a precise point in the link.
 * Nothing leaves the server: the destination travels only when the reader
 * picks an application, and then only from their own browser.
 *
 * **The hosts are named here once**, as the messaging apps are in
 * Core\Phone\MessagingApps and the tiles in Core\Geo\MapTiles: the default
 * RGPD page (core/View/rgpd_default.html) and the rule the generated policy
 * must keep (Core\View\RgpdContentService) say which services a click
 * reaches, and Tests\Core\Geo\MapsLinkTest checks they still agree. None of
 * the three is a sub-processor.
 */
final class MapsLink
{
    public const APPLE = 'apple';
    public const GOOGLE = 'google';
    public const WAZE = 'waze';

    /** Where a Plans link goes. */
    public const APPLE_HOST = 'maps.apple.com';

    /** Where a Google Maps link goes. */
    public const GOOGLE_HOST = 'www.google.com';

    /** Where a Waze link goes. */
    public const WAZE_HOST = 'waze.com';

    /**
     * The providers, in the order of the sheet, and how each is named to the
     * reader.
     *
     * @return array<string, string> provider key => label
     */
    public static function providers(): array
    {
        return [
            self::APPLE => 'Plans',
            self::GOOGLE => 'Google Maps',
            self::WAZE => 'Waze',
        ];
    }

    /**
     * The route link of one provider.
     *
     * @throws \InvalidArgumentException for a provider the site does not offer
     */
    public static function directionsFor(string $provider, ?GeoPoint $point, string $address): string
    {
        $destination = self::destination($point, $address);

        return match ($provider) {
            self::APPLE => 'https://' . self::APPLE_HOST . '/?daddr=' . rawurlencode($destination) . '&dirflg=d',
            self::GOOGLE => 'https://' . self::GOOGLE_HOST . '/maps/dir/?api=1&destination='
                . rawurlencode($destination),
            // Waze takes coordinates as `ll` and a free-text place as `q`:
            // both with `navigate=yes`, so the route starts at once.
            self::WAZE => 'https://' . self::WAZE_HOST . '/ul?'
                . ($point !== null ? 'll=' . rawurlencode($destination) : 'q=' . rawurlencode($destination))
                . '&navigate=yes',
            default => throw new \InvalidArgumentException('Unknown map provider « ' . $provider . ' ».'),
        };
    }

    /**
     * Every provider with its label and its link, in the order of the sheet:
     * what a page renders on the server, so the choice works without
     * JavaScript.
     *
     * @return list<array{key: string, label: string, url: string}>
     */
    public static function choices(?GeoPoint $point, string $address): array
    {
        $choices = [];
        foreach (self::providers() as $key => $label) {
            $choices[] = [
                'key' => $key,
                'label' => $label,
                'url' => self::directionsFor($key, $point, $address),
            ];
        }

        return $choices;
    }

    /**
     * The link of the « Itinéraire » button itself, for a browser where the
     * sheet does not open: Google Maps in the browser, which works on every
     * device. With JavaScript the button opens the sheet instead.
     */
    public static function fallback(?GeoPoint $point, string $address): string
    {
        return self::directionsFor(self::GOOGLE, $point, $address);
    }

    private static function destination(?GeoPoint $point, string $address): string
    {
        return $point !== null
            ? number_format($point->latitude, 6, '.', '') . ',' . number_format($point->longitude, 6, '.', '')
            : trim($address);
    }
}
