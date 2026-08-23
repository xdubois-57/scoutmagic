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
    // modules/mass_mail/views/config.html.twig — the site-wide sending
    // interval the speed form starts from, handed to
    // public/assets/js/mass-mail-config.js by an inline nonce-tagged script
    // (the window.supportDashboardCharts precedent).
    massMailConfigData?: {
        batchIntervalMinutes?: number | null;
    };
    // modules/llm_connector/views/config/index.html.twig — the per-driver
    // model lists, handed to public/assets/js/llm-config.js by an inline
    // nonce-tagged script (the window.supportDashboardCharts precedent).
    // Deliberately loose on a model row, for the same reason as
    // massMailListData above: the shape belongs to the page that
    // serializes it.
    llmConfigData?: {
        modelsByDriver: { [driver: string]: Array<{ [key: string]: any }> };
    };
    // modules/calendar/views/chief.html.twig — the event defaults the
    // add-event dialog pre-fills, handed to public/assets/js/calendar-chief.js
    // by an inline nonce-tagged script (the window.llmConfigData precedent).
    // defaultCalendarId is null when the unit has no default calendar, in
    // which case the dialog leaves the select on its first option.
    calendarChiefData?: {
        defaultTitle?: string;
        defaultStartTime?: string;
        defaultEndTime?: string;
        defaultLocation?: string;
        defaultCalendarId?: number | null;
    };
    // core/View/templates/chefs/staffs.html.twig — the section-document
    // upload thresholds, handed to public/assets/js/staffs.js by an inline
    // nonce-tagged script (the window.llmConfigData precedent). Present
    // only for a chief who can edit the section; oversizeWarningEnabled is
    // false whenever the server has a PDF compression backend of its own,
    // in which case the advisory warning never shows at all.
    staffsData?: {
        oversizeWarningEnabled?: boolean;
        oversizeWarningMb?: number;
    };
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
    // public/assets/js/confirm.js — the site's one confirmation dialog and
    // the non-native replacement for confirm() (design.md §7.5), loaded by
    // base.html.twig on every page. A bare string is the message.
    ScoutMagicConfirm?: {
        ask: (input: string | {
            message: string;
            title?: string;
            confirmLabel?: string;
            cancelLabel?: string;
            variant?: 'danger' | 'primary';
        }) => Promise<boolean>;
        // The replacement for prompt(): resolves to what was typed, or
        // null when the dialog was dismissed.
        prompt: (input: string | {
            message: string;
            title?: string;
            value?: string;
            placeholder?: string;
            inputType?: string;
            readonly?: boolean;
            selectOnOpen?: boolean;
            // A textarea instead of a one-line input, with its own label
            // above it: the confirm-with-a-note case, where the message
            // states the decision and the label asks for the word to go
            // with it.
            multiline?: boolean;
            label?: string;
            confirmLabel?: string;
            cancelLabel?: string;
            variant?: 'danger' | 'primary';
        }) => Promise<string | null>;
    };
    // public/assets/js/rich-text-link.js — the one "insert a link"
    // implementation behind five rich-text toolbars, loaded by
    // base.html.twig on every page. insertLink resolves true when a link
    // was actually created.
    ScoutMagicRichText?: {
        insertLink: (surface: HTMLElement | null) => Promise<boolean>;
        normalizeUrl: (raw: string | null) => string | null;
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
    // modules/sos_staff/views/admin.html.twig — the displayed month and the
    // duty states it starts from, handed to public/assets/js/sos-admin.js by
    // an inline nonce-tagged script (the window.llmConfigData precedent).
    // states is keyed by ISO date then by member id, each value being one of
    // the two duty states ('oncall' / 'unavailable'); an absent key means
    // nothing is planned. No phone number ever travels in this payload
    // (AGENTS.md § Security checklist).
    sosAdminData?: {
        year?: number;
        month?: number;
        monthParam?: string;
        states?: { [date: string]: { [memberId: string]: string } };
    };
}
