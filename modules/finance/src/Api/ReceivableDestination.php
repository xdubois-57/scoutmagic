<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Api;

/**
 * Where a receivable is actually managed — the screen of the module that
 * raised it (issue #836): « Ouvrir la réservation », and its address.
 *
 * A NAVIGATION, never an action: « Contrôle des créances » only shows what
 * finance believes it is owed, and the module that raised a receivable is
 * the one interface that knows how to change it.
 *
 * **The address is a path on this site, and nothing else.** A module
 * answers it, finance prints it as a link in front of a treasurer: a value
 * that could carry `https://…` or `javascript:` would make every source a
 * way to put an arbitrary link on a finance page.
 */
final class ReceivableDestination
{
    public function __construct(
        public readonly string $label,
        public readonly string $url
    ) {
        if (trim($label) === '') {
            throw new \InvalidArgumentException('A destination needs a label.');
        }

        // One leading slash, not two: `//host` is a scheme-relative URL,
        // which a browser follows off the site. No control character or
        // space either: a browser strips tab, CR and LF from a URL before
        // parsing it, so `/<TAB>/evil.com` would reach the same `//host`
        // while slipping past the check before it. A path this site builds
        // never needs one — a module percent-encodes what it interpolates.
        if (!str_starts_with($url, '/')
            || str_starts_with($url, '//')
            || str_contains($url, '\\')
            || preg_match('/[\x00-\x20\x7f]/', $url) === 1
        ) {
            throw new \InvalidArgumentException('A destination is a path on this site.');
        }
    }
}
