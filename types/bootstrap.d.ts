/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Ambient declaration for Bootstrap 5's global `bootstrap` object. It is
// exposed at runtime by public/assets/vendor/bootstrap/js/bootstrap.bundle.min.js,
// a plain vendored <script> tag (no npm package, no bundler — see AGENTS.md
// § CSS / frontend), so TypeScript has no other way to know it exists.
// Declares only the members actually called from public/assets/js/*.js;
// extend narrowly if a new call site starts using another Bootstrap
// component/method.
interface BootstrapComponentInstance {
    show(): void;
    hide(): void;
}

declare const bootstrap: {
    Modal: {
        new (element: Element): BootstrapComponentInstance;
        getOrCreateInstance(element: Element): BootstrapComponentInstance;
    };
    Collapse: {
        getOrCreateInstance(element: Element, options?: { toggle?: boolean }): BootstrapComponentInstance;
    };
    Offcanvas: {
        getOrCreateInstance(element: Element): BootstrapComponentInstance;
    };
};
