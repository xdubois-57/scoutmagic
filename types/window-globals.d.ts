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
// Ambient declaration for Chart.js, exposed as a global by the vendored
// public/assets/vendor/chartjs/chart.umd.min.js <script> tag (no npm
// package, no bundler — see AGENTS.md § CSS / frontend). Deliberately
// loose on the config object: mirroring Chart.js's full option surface here
// would be a second, always-stale copy of its type definitions.
interface ChartConstructor {
    new (canvas: HTMLCanvasElement, config: any): unknown;
}

interface Window {
    // public/assets/vendor/chartjs/chart.umd.min.js — present only on the
    // pages that load it.
    Chart?: ChartConstructor;
    // Modules\SupportDashboard — the two current-state chart series,
    // computed server-side and handed over by an inline nonce-tagged script
    // in modules/support_dashboard/views/index.html.twig.
    supportDashboardCharts?: {
        versions: Array<{ label: string, count: number }>;
        autoUpdate: Array<{ label: string, count: number }>;
        history: Array<{ month: string, count: number }>;
    };
    // Bootstrap 5's global, also reachable as the bare `bootstrap` binding
    // (see types/bootstrap.d.ts) — declared here too for the scripts that
    // access it defensively through `window`, since a page may not have
    // loaded the bundle at all.
    bootstrap?: typeof bootstrap;
    // modules/mass_mail/views/list.html.twig — server data handed to
    // public/assets/js/mass-mail-list.js by an inline nonce-tagged script
    // (the window.supportDashboardCharts precedent).
    // Deliberately loose: the exact shape belongs to the page that
    // serializes it, and mirroring it here would be a second, always-
    // stale copy (the window.Chart precedent above).
    massMailListData?: { [key: string]: any };
    // public/assets/js/api.js — the site-wide fetch toolbox, loaded by
    // base.html.twig on every page (see its header for the envelope).
    ScoutMagicApi?: {
        csrfToken: () => string;
        postJson: (url: string, body?: object, options?: { method?: string }) => Promise<{ ok: boolean, status: number, data: any }>;
        getJson: (url: string) => Promise<{ ok: boolean, status: number, data: any }>;
        withDisabled: <T>(control: HTMLButtonElement | HTMLInputElement | null, run: () => Promise<T>) => Promise<T>;
        escapeHtml: (value: unknown) => string;
        debounce: (fn: (...args: any[]) => void, delayMs: number) => (...args: any[]) => void;
        poll: (tick: () => (boolean | void | Promise<boolean | void>), options?: {
            intervalMs?: number;
            delaysMs?: number[];
            maxMs?: number;
            resumeOnVisible?: boolean;
            onExpire?: () => void;
        }) => { stop: () => void };
    };
    // public/assets/js/theme.js — the light/dark/auto color-scheme
    // toolbox, loaded by base.html.twig on every page (design.md §7.8).
    ScoutMagicTheme?: {
        getPreference: () => string;
        setPreference: (pref: string) => void;
        cycle: () => void;
        hasFunctionalConsent: () => boolean;
    };
    // public/assets/js/toast.js — the non-blocking replacement for
    // alert(), loaded by base.html.twig on every page.
    ScoutMagicToast?: {
        show: (message: string, options?: { variant?: 'success' | 'error' | 'warning' | 'info', delayMs?: number }) => HTMLElement;
    };
    ScoutMagicNav?: {
        showDesktopMenu?: (menuId: string) => void;
        syncSwitchAriaChecked?: (input: HTMLInputElement) => void;
    };
    ChipPicker?: {
        setSelected?: (pickerId: string, id: string, selected: boolean) => void;
    };
    // public/assets/js/chunked-upload.js (audit M2) — consumed by
    // public/assets/js/gallery.js and public/assets/js/maintenance.js.
    ScoutMagicChunkedUpload?: {
        uploadInChunks: (file: File, url: string, options?: {
            csrfToken?: string;
            fields?: Record<string, string>;
            lastFields?: Record<string, string>;
            onProgress?: (sentBytes: number, totalBytes: number) => void;
            chunkSize?: number;
            uploadId?: string;
        }) => Promise<{ uploadId: string, data: any }>;
        newUploadId: () => string;
        CHUNK_SIZE: number;
        CHUNK_THRESHOLD: number;
    };
}
