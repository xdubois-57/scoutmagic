/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Ambient declaration for ScoutMagic's own small cross-file globals. Each
// script attaches its namespace defensively (`window.X = window.X || {}`)
// so other scripts loaded on the same page can call into it without a
// module system — see public/assets/js/nav.js and
// public/assets/js/chip-picker.js for the definitions. Declared optional
// since a page may load one script without the other.
interface Window {
    ScoutMagicNav?: {
        showDesktopMenu?: (menuId: string) => void;
    };
    ChipPicker?: {
        setSelected?: (pickerId: string, id: string, selected: boolean) => void;
    };
}
