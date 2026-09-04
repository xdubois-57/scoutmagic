/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Password-reset page (core/View/templates/auth/password_reset.html.twig):
// decides which of the page's three panels to show. Extracted from the
// template's inline <script> so the Vitest suite can exercise the
// production code directly (tests/js/password-reset.test.js) — this is
// the file that decides whether someone can set a new password, and it
// had no test at all.
//
// Why any of this runs in the browser: the reset token is in the URL
// FRAGMENT, which browsers never transmit. That is the point — it keeps
// the token out of server logs and out of Referer headers — but it also
// means the server rendering this page genuinely cannot know whether the
// link is still good. So the page renders in a neutral « checking »
// state and this file posts the fragment to /password-reset/{id}/check,
// which answers `{valid}` without consuming the token (a reload, or a
// browser prefetching the link, must not burn it).
//
// The request is form-encoded, not JSON: check() reads its token through
// Request::getBody(), which is $_POST, so ScoutMagicApi.postJson() —
// JSON in the raw body — would arrive as an empty body and every valid
// link would be rejected.
(function () {
    var form = /** @type {HTMLFormElement|null} */ (document.getElementById('reset-form'));
    var checking = document.getElementById('reset-checking');
    var invalid = document.getElementById('reset-invalid');
    var panel = document.getElementById('reset-panel');
    var tokenField = /** @type {HTMLInputElement|null} */ (document.getElementById('reset-token'));

    // A no-op on every other page of the site.
    if (!form || !checking || !invalid || !panel || !tokenField) {
        return;
    }

    /** @param {HTMLElement} el */
    function show(el) {
        checking.classList.add('d-none');
        invalid.classList.add('d-none');
        panel.classList.add('d-none');
        el.classList.remove('d-none');
    }

    // The fragment never left the browser — read it, then strip it from
    // the address bar so it doesn't linger in history or get shared by
    // accident (someone sending « look at this page » sends the token).
    var token = window.location.hash.replace(/^#/, '');
    try {
        token = decodeURIComponent(token);
    } catch (e) { /* not percent-encoded — use as-is */ }

    if (window.history?.replaceState) {
        window.history.replaceState(null, '', window.location.pathname + window.location.search);
    }

    if (!token) {
        show(invalid);
        return;
    }

    // The form already posts to /password-reset/{id}; the check endpoint
    // is that same path with /check — no need for the id separately.
    var checkUrl = form.getAttribute('action') + '/check';
    var csrf = window.ScoutMagicApi.csrfToken();

    fetch(checkUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'token=' + encodeURIComponent(token)
            + '&_csrf_token=' + encodeURIComponent(csrf)
    })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data?.valid) {
                show(invalid);
                return;
            }
            tokenField.value = token;
            show(panel);
        })
        .catch(function () {
            // A network failure is indistinguishable from a dead link
            // here, and the recovery is the same either way: ask for a
            // new link rather than offer a form that cannot succeed.
            show(invalid);
        });
})();
