/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Ambient declaration for `navigator.standalone` — iOS Safari's
// non-standard flag for "launched from the home-screen icon, running as
// an installed PWA" (there is no `display-mode: standalone` media query
// support on iOS Safari, so this is the only way to detect it there).
// Not part of TypeScript's DOM lib since it's Safari-only.
interface Navigator {
    readonly standalone?: boolean;
}
