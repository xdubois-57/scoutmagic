/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Ambient declaration for ScoutMagic's own small cross-file globals. Each
// script attaches its namespace defensively (`window.X = window.X || {}`)
// so other scripts loaded on the same page can call into it without a
// module system — see public/assets/js/nav.js and
// public/assets/js/select-bar.js for the definitions. Declared optional
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
    // Bootstrap 5's global, also reachable as the bare `bootstrap` binding
    // (see types/bootstrap.d.ts) — declared here too for the scripts that
    // access it defensively through `window`, since a page may not have
    // loaded the bundle at all.
    bootstrap?: typeof bootstrap;
    // public/assets/js/api.js — the site-wide fetch toolbox, loaded by
    // base.html.twig on every page (see its header for the envelope).
    ScoutMagicApi?: {
        csrfToken: () => string;
        postJson: (url: string, body?: object, options?: { method?: string }) => Promise<{ ok: boolean, status: number, data: any }>;
        getJson: (url: string) => Promise<{ ok: boolean, status: number, data: any }>;
        withDisabled: <T>(control: HTMLButtonElement | HTMLInputElement | null, run: () => Promise<T>) => Promise<T>;
        escapeHtml: (value: unknown) => string;
        debounce: (fn: (...args: any[]) => void, delayMs: number) => (...args: any[]) => void;
        // Reads a `<script type="application/json" id="...">` island;
        // null when it is absent or unparseable.
        pageData: (id: string) => any;
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
    // public/assets/js/drop-zone.js — the shared « drop files here »
    // zone (design.md §7.10), loaded only by the pages that show one.
    ScoutMagicDropZone?: {
        bind: (
            zone: HTMLElement | null,
            onFiles: (files: FileList) => void,
            options?: { input?: HTMLInputElement | null, pickOnClick?: boolean }
        ) => void;
    };
    // public/assets/js/upload-drop-zone.js — a drop_zone whose file rides
    // the surrounding form's own multipart POST; loaded by the pages that
    // show one (design.md §7.10).
    ScoutMagicUploadDropZone?: {
        bind: (root?: ParentNode) => void;
        describe: (zone: HTMLElement, files: FileList) => void;
    };
    // public/assets/js/section-editor.js — the « Modifier » dialogs of a
    // settings screen (design.md §1.9), loaded only by the pages that
    // split their sections into read cards and editing dialogs.
    ScoutMagicSectionEditor?: {
        bind: (root?: ParentNode) => void;
        focusFirstField: (modal: Element | null) => void;
        openFromHash: (hash: string, root?: ParentNode) => boolean;
    };
    // public/assets/js/camps-place-summary.js — the busy state of the
    // « Écrire le résumé » buttons on a camp place, loaded by that page.
    ScoutMagicCampsPlaceSummary?: {
        bind: (root?: ParentNode) => void;
        markBusy: (form: HTMLFormElement) => void;
        release: (form: HTMLFormElement) => void;
    };
    // public/assets/js/sortable.js — drag-and-drop reordering of a list,
    // loaded only by the pages that offer it.
    ScoutMagicSortable?: {
        bind: (
            container: HTMLElement | null,
            options: {
                itemSelector: string;
                axis?: string;
                draggingClass?: string;
                onReorder?: () => void;
            }
        ) => void;
    };
    // public/assets/js/pdf-thumbnail.js — the fallback for a PDF
    // thumbnail the server could not render, loaded only by the pages
    // that show receipts. `error` does not bubble, so every code path
    // that INSERTS such an image calls bind() again for it.
    ScoutMagicPdfThumbnail?: {
        bind: (
            root: ParentNode | null | undefined,
            options?: { height?: string, width?: string, iconClass?: string }
        ) => void;
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
    // public/assets/js/camps-booked-by.js — the « Réservation faite par »
    // field of the camps stay form, present only on that page. Exposed so
    // its matching rule can be exercised without a form (tests/js).
    ScoutMagicCampsBookedBy?: {
        matchMemberId: (members: Array<{ id: number, name: string }>, typed: string) => string;
        bind: (
            input: HTMLInputElement,
            hidden: HTMLInputElement,
            members: Array<{ id: number, name: string }>
        ) => HTMLDataListElement;
    };
    // public/assets/js/leadership-copy-emails.js — « Copier les adresses »
    // on the Encadrement lists, present only on those three pages.
    ScoutMagicLeadershipEmails?: {
        collect: (list: ParentNode) => string[];
        format: (addresses: string[]) => string;
    };
    // public/assets/js/fees-copy.js — « Copier pour Desk » on Cotisations >
    // Justesse des tarifs, present only on that page.
    ScoutMagicFeesCopy?: {
        blockFor: (texts: Record<string, string> | null, key: string) => string;
    };
    // public/assets/js/import-barrier.js — the danger zone of the Desk
    // import barrier, present only when that barrier is on screen. The
    // confirmation word is checked server-side; this only decides whether
    // the submit button looks clickable.
    ScoutMagicImportBarrier?: {
        matchesConfirmation: (typed: string, word: string) => boolean;
    };
    ScoutMagicNav?: {
        showDesktopMenu?: (menuId: string) => void;
        syncSwitchAriaChecked?: (input: HTMLInputElement) => void;
    };
    // public/assets/js/select-bar.js — the escape hatch a mode:multi
    // caller uses to revert its own optimistic toggle after the server
    // rejects it, without re-dispatching select-bar:change.
    SelectBar?: {
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
