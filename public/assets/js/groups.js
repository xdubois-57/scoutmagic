/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Groups module front-end: composer media picker, the live link-preview
// card, dynamic reactions, polling for a still-processing photo/video,
// "Charger plus"/"Voir plus de réponses" in-place pagination, inline
// edit toggles, reply image filename display (show.html.twig), and the
// invite-member search box (members.html.twig). Pure JS, no external
// library — same IIFE/var/fetch style as gallery.js/retro-board.js.
// Every block below guards on its own DOM elements first, so the same
// script loads on both pages (and any future one) with no effect from
// whichever page's markup is actually absent.
//
// Server-supplied config comes from #groups-post-form's own data-*
// attributes (max_media_per_post), the same convention retro-board.js
// uses on its own container — an external file has no Twig
// interpolation to lean on.
(function () {
    // The composer as a whole: the media picker, the live link-preview
    // card, the localStorage draft cache, and the dynamic submit itself.
    // One IIFE rather than several, because all four have to coordinate
    // on the same reset (a published post clears the picker's own
    // selectedFiles[], the link-preview card, AND the cached draft
    // together) — splitting them the way earlier prompts did would need
    // some cross-closure signalling (a custom event, or state hung off
    // the form element) for no benefit, since none of the four is reused
    // outside this one composer.
    (function initComposer() {
        var form = /** @type {HTMLFormElement} */ (document.getElementById('groups-post-form'));
        var previews = document.getElementById('groups-media-previews');
        var hiddenInput = /** @type {HTMLInputElement} */ (document.getElementById('groups-media-hidden'));
        var countLabel = document.getElementById('groups-media-count');
        var limitWarning = document.getElementById('groups-media-limit-warning');
        var textarea = /** @type {HTMLTextAreaElement} */ (document.getElementById('post-body'));
        var linkPreviewContainer = document.getElementById('groups-link-preview');
        var errorBox = document.getElementById('groups-post-error');
        var submitBtn = /** @type {HTMLButtonElement} */ (form ? form.querySelector('button[type="submit"]') : null);
        if (!form || !previews || !hiddenInput || !textarea) {
            return;
        }

        var maxMedia = parseInt(form.dataset.maxMedia, 10) || 4;
        var selectedFiles = [];

        // --- Media picker: the visible <input type="file"> is a UI-only
        // source of File objects — never submitted itself. Selected files
        // accumulate in selectedFiles[], capped client-side at maxMedia (a
        // convenience: the server enforces the real ceiling and rejects
        // the whole post over it, never truncating —
        // Service\PostMediaService::MAX_MEDIA_PER_POST). Before submit,
        // they're copied onto the one hidden <input name="media[]"> that
        // actually gets posted, via the DataTransfer API (the only way to
        // programmatically set an <input>'s .files).
        function renderMediaPreviews() {
            previews.innerHTML = '';
            selectedFiles.forEach(function (file, index) {
                var cell = document.createElement('div');
                cell.className = 'position-relative';
                cell.style.cssText = 'width:72px;height:72px;border-radius:.375rem;overflow:hidden;background:#eee;';

                if (file.type.indexOf('image/') === 0) {
                    var img = document.createElement('img');
                    img.src = URL.createObjectURL(file);
                    img.className = 'w-100 h-100';
                    img.style.objectFit = 'cover';
                    cell.appendChild(img);
                } else {
                    var icon = document.createElement('div');
                    icon.className = 'd-flex align-items-center justify-content-center h-100 text-body-secondary';
                    icon.innerHTML = '<i class="bi bi-camera-video fs-4"></i>';
                    cell.appendChild(icon);
                }

                var remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'btn btn-sm btn-dark position-absolute top-0 end-0 p-0 d-flex align-items-center justify-content-center';
                remove.style.cssText = 'width:22px;height:22px;border-radius:50%;opacity:.85;';
                remove.setAttribute('aria-label', 'Retirer ce média');
                remove.innerHTML = '<i class="bi bi-x" style="font-size:.9rem;"></i>';
                remove.addEventListener('click', function () {
                    selectedFiles.splice(index, 1);
                    syncMedia();
                });
                cell.appendChild(remove);

                previews.appendChild(cell);
            });
        }

        function syncMedia() {
            var dataTransfer = new DataTransfer();
            selectedFiles.forEach(function (file) { dataTransfer.items.add(file); });
            hiddenInput.files = dataTransfer.files;
            if (countLabel) countLabel.textContent = selectedFiles.length + ' / ' + maxMedia + ' médias';
            renderMediaPreviews();
        }

        function addFiles(fileList) {
            var overflowed = false;
            Array.from(fileList).forEach(function (file) {
                if (selectedFiles.length >= maxMedia) {
                    overflowed = true;
                    return;
                }
                selectedFiles.push(file);
            });
            if (limitWarning) limitWarning.classList.toggle('d-none', !overflowed);
            syncMedia();
        }

        function resetMedia() {
            selectedFiles = [];
            syncMedia();
            if (limitWarning) limitWarning.classList.add('d-none');
        }

        // One picker only: every mobile OS already offers "take a photo"
        // inside its own file chooser, so a separate camera button was a
        // second door onto the same room.
        var mediaInput = /** @type {HTMLInputElement} */ (document.getElementById('groups-media-input'));
        if (mediaInput) {
            mediaInput.addEventListener('change', function () {
                if (mediaInput.files) addFiles(mediaInput.files);
                mediaInput.value = '';
            });
        }

        // --- Live link preview: no manual "Lien" field — the first URL
        // typed anywhere in the message is detected automatically and
        // previewed live, exactly as it will look once the post is saved
        // (Controller\PostController::linkPreview(),
        // Service\PostLinkService::livePreview() — the same preview a
        // real submit would produce, just not written anywhere yet).
        // Debounced on typing (never per keystroke — the fetch is
        // throttled server-side, Service\LinkFetchThrottleService, shared
        // with the post's own final fetch on submit) and fired
        // immediately on blur/paste, so leaving the field or pasting a
        // link shows the card without waiting out the debounce.
        var linkDebounceTimer = null;
        var lastRequestedBody = null;
        var linkRequestToken = 0;

        function csrfToken() {
            var meta = /** @type {HTMLMetaElement} */ (document.querySelector('meta[name="csrf-token"]'));
            return meta ? meta.content : '';
        }

        function hideLinkPreview() {
            if (linkPreviewContainer) {
                linkPreviewContainer.classList.add('d-none');
                linkPreviewContainer.innerHTML = '';
            }
        }

        function showLinkPreviewSpinner() {
            if (!linkPreviewContainer) {
                return;
            }
            linkPreviewContainer.innerHTML = '';
            var spinner = document.createElement('span');
            spinner.className = 'spinner-border spinner-border-sm text-body-secondary';
            spinner.setAttribute('role', 'status');
            spinner.setAttribute('aria-hidden', 'true');
            linkPreviewContainer.appendChild(spinner);
            linkPreviewContainer.classList.remove('d-none');
        }

        // Built with textContent throughout, never innerHTML string
        // concatenation: title/description come straight from a REMOTE
        // page's own og:title/og:description, which this member did not
        // write and this site does not control — the same untrusted-input
        // rule as anywhere else a value crosses a trust boundary.
        function renderLinkPreviewCard(data) {
            if (!linkPreviewContainer) {
                return;
            }
            linkPreviewContainer.innerHTML = '';
            var card = document.createElement('a');
            card.href = data.url;
            card.target = '_blank';
            card.rel = 'noopener noreferrer nofollow';
            card.className = 'card text-decoration-none groups-link-preview';

            if (data.image_data_uri) {
                var img = document.createElement('img');
                img.src = data.image_data_uri;
                img.alt = '';
                img.className = 'groups-link-preview-image';
                card.appendChild(img);
            }

            var body = document.createElement('div');
            body.className = 'card-body py-2 px-3';

            var host = document.createElement('p');
            host.className = 'text-body-tertiary text-uppercase mb-1';
            host.style.fontSize = '0.7rem';
            try {
                host.textContent = new URL(data.url).hostname;
            } catch (e) {
                host.textContent = data.url;
            }
            body.appendChild(host);

            if (data.title) {
                var title = document.createElement('p');
                title.className = 'fw-semibold mb-1 text-body';
                title.textContent = data.title;
                body.appendChild(title);
            }
            if (data.description) {
                var description = document.createElement('p');
                description.className = 'text-body-secondary mb-0 groups-link-preview-description';
                description.textContent = data.description;
                body.appendChild(description);
            }
            if (!data.title && !data.description) {
                var plain = document.createElement('p');
                plain.className = 'text-body mb-0 text-truncate';
                plain.textContent = data.url;
                body.appendChild(plain);
            }

            card.appendChild(body);
            linkPreviewContainer.appendChild(card);
            linkPreviewContainer.classList.remove('d-none');
        }

        function fetchLinkPreview() {
            if (!linkPreviewContainer) {
                return;
            }
            var body = textarea.value;
            if (body === lastRequestedBody) {
                return;
            }
            lastRequestedBody = body;
            // Bumped unconditionally, even on the no-fetch path below: an
            // earlier call's fetch() may still be in flight, and this
            // call's own decision (nothing to show) has to win once it is
            // made — not get silently overwritten a moment later when that
            // older request finally resolves.
            var token = ++linkRequestToken;

            // Cheap guard before spending a network round trip (and,
            // server-side, a throttle slot only actually gets consumed
            // once a URL is genuinely found — but there is no reason to
            // ask at all for a message with no "http" in it whatsoever).
            if (body.indexOf('http://') === -1 && body.indexOf('https://') === -1) {
                hideLinkPreview();
                return;
            }

            showLinkPreviewSpinner();

            fetch(linkPreviewContainer.dataset.previewUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-CSRF-Token': csrfToken(),
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: 'body=' + encodeURIComponent(body)
            }).then(function (response) {
                return response.ok ? response.json() : { url: null };
            }).then(function (data) {
                // A later request may have already resolved while this
                // one was in flight — discard a stale answer rather than
                // flashing an outdated card back over a newer one.
                if (token !== linkRequestToken) {
                    return;
                }
                if (data.url) {
                    renderLinkPreviewCard(data);
                } else {
                    hideLinkPreview();
                }
            }).catch(function () {
                if (token === linkRequestToken) {
                    hideLinkPreview();
                }
            });
        }

        function resetLinkPreview() {
            hideLinkPreview();
            lastRequestedBody = null;
            linkRequestToken += 1;
        }

        if (linkPreviewContainer) {
            textarea.addEventListener('input', function () {
                clearTimeout(linkDebounceTimer);
                linkDebounceTimer = setTimeout(fetchLinkPreview, 800);
            });
            textarea.addEventListener('blur', function () {
                clearTimeout(linkDebounceTimer);
                fetchLinkPreview();
            });
            textarea.addEventListener('paste', function () {
                // The pasted text is not yet in .value while this handler
                // runs (paste is fired before the browser inserts it) —
                // defer to the next tick so fetchLinkPreview() reads the
                // field AFTER the paste has actually landed.
                clearTimeout(linkDebounceTimer);
                setTimeout(fetchLinkPreview, 0);
            });
        }

        // --- Draft cache: a not-yet-posted message survives a lost
        // connection or a failed submit in the member's OWN browser
        // (never the server), so a retry does not mean retyping
        // everything (module spec). Text only — attached files/the link
        // preview cannot round-trip through JSON/localStorage, and are
        // not worth the complexity for what is, at worst, re-picking a
        // photo. Cleared the moment a post actually publishes; never
        // touched by the network failure path, so the draft is exactly
        // as recoverable after a failed retry as before the first one.
        var draftTtlMinutes = parseInt(form.dataset.draftTtlMinutes, 10) || 60;
        var draftKey = 'groups-draft-' + (form.dataset.groupId || '0');
        var draftSaveTimer = null;

        function saveDraft() {
            var body = textarea.value;
            if (body.trim() === '') {
                clearDraft();
                return;
            }
            try {
                localStorage.setItem(draftKey, JSON.stringify({ body: body, savedAt: Date.now() }));
            } catch (e) {
                // Storage full, disabled, or private browsing — the draft
                // is simply not cached; nothing else here depends on it.
            }
        }

        function clearDraft() {
            try {
                localStorage.removeItem(draftKey);
            } catch (e) {
                // Same as above.
            }
        }

        function restoreDraft() {
            var raw;
            try {
                raw = localStorage.getItem(draftKey);
            } catch (e) {
                return;
            }
            if (!raw) {
                return;
            }
            var draft;
            try {
                draft = JSON.parse(raw);
            } catch (e) {
                clearDraft();
                return;
            }
            if ((Date.now() - draft.savedAt) / 60000 > draftTtlMinutes) {
                clearDraft();
                return;
            }
            // Never overrides text the AI moderation just handed back for
            // THIS composer (rejected_draft, already filled in server-side
            // — show.html.twig) — that one is more specific than a merely
            // locally-cached draft.
            if (textarea.value.trim() === '') {
                textarea.value = draft.body;
                fetchLinkPreview();
            }
        }

        textarea.addEventListener('input', function () {
            clearTimeout(draftSaveTimer);
            draftSaveTimer = setTimeout(saveDraft, 500);
        });

        restoreDraft();

        // --- Dynamic submit: greys out the composer while the post
        // publishes in the background (module spec: "no reload to
        // publish a post"), instead of the plain form POST + redirect
        // this degrades to on any failure below.
        function setBusy(busy) {
            textarea.disabled = busy;
            if (submitBtn) submitBtn.disabled = busy;
            form.classList.toggle('groups-composer-busy', busy);
        }

        function showComposerError(message) {
            if (!errorBox) {
                return;
            }
            errorBox.textContent = message;
            errorBox.classList.remove('d-none');
        }

        function clearComposerError() {
            if (errorBox) {
                errorBox.textContent = '';
                errorBox.classList.add('d-none');
            }
        }

        function resetComposer() {
            form.reset();
            resetMedia();
            resetLinkPreview();
            clearComposerError();
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            clearComposerError();
            setBusy(true);

            fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (response) {
                return response.json().catch(function () { return null; }).then(function (data) {
                    return { ok: response.ok, data: data };
                });
            }).then(function (result) {
                if (result.ok && result.data && typeof result.data.html === 'string') {
                    var feed = document.getElementById('groups-feed');
                    if (feed) {
                        // The post just published is the newest thing in
                        // the group — goes at the very top of the
                        // (non-pinned) stream, exactly where a refreshed
                        // page would put it.
                        feed.insertAdjacentHTML('afterbegin', result.data.html);
                        kickOffMediaPolling();
                    }
                    resetComposer();
                    clearDraft();
                } else if (result.data && typeof result.data.error === 'string') {
                    // A refusal the server actually returned (moderation,
                    // rate limit, media ceiling, an empty draft slipping
                    // past the UI) — shown inline, draft left untouched so
                    // the member can revise and resend.
                    showComposerError(result.data.error === 'empty' ? 'Un message ne peut pas être vide.' : result.data.error);
                } else {
                    // Not JSON at all (a stale CSRF token's plain-text
                    // 403, or anything else unexpected) — fall back to
                    // the real form submit exactly like the reaction form
                    // does, rather than leaving the member stuck.
                    form.submit();
                }
            }).catch(function () {
                // A genuine network failure or lost connection: the
                // draft is already cached (the debounced save above), so
                // this only has to tell the member to retry, never to
                // reload — a reload here would be the one thing that
                // could actually lose what they typed if they are
                // offline.
                showComposerError('Connexion perdue : le message n\'a pas pu être envoyé. Il reste dans le formulaire, réessayez.');
            }).finally(function () {
                setBusy(false);
            });
        });
    })();

    // members.html.twig's invite form: a search-as-you-type box in place
    // of the giant <select>'s dropdown. The <select> itself stays the
    // real form control throughout — this only ever sets its .value when
    // a search result is clicked — so the form still POSTs member_id
    // exactly as before, and nothing here changes what the server
    // validates. Without JS, or if it fails to load, the box stays
    // hidden and the plain dropdown (with its own "— Choisir —"
    // placeholder) works exactly as it always did.
    (function initInviteMemberSearch() {
        var input = /** @type {HTMLInputElement} */ (document.getElementById('invite-member-search'));
        var results = document.getElementById('invite-member-results');
        var select = /** @type {HTMLSelectElement} */ (document.getElementById('invite-member'));
        if (!input || !results || !select) {
            return;
        }

        input.classList.remove('d-none');
        results.classList.remove('d-none');
        select.classList.add('d-none');

        var searchTimer = null;

        function showResults(members) {
            results.innerHTML = '';
            members.forEach(function (member) {
                var item = document.createElement('li');
                item.className = 'list-group-item list-group-item-action';
                item.style.cursor = 'pointer';
                item.textContent = member.label;
                item.addEventListener('click', function () {
                    select.value = member.id;
                    input.value = member.label;
                    results.innerHTML = '';
                    results.classList.add('d-none');
                });
                results.appendChild(item);
            });
            results.classList.toggle('d-none', members.length === 0);
        }

        input.addEventListener('input', function () {
            clearTimeout(searchTimer);
            // Typing again after picking someone means that choice may no
            // longer match what's visible — clear it so a stray click on
            // "Inviter" cannot submit a stale member_id the text box no
            // longer names.
            select.value = '';

            var query = input.value.trim();
            if (query.length < 2) {
                results.innerHTML = '';
                results.classList.add('d-none');
                return;
            }

            searchTimer = setTimeout(function () {
                fetch(input.dataset.searchUrl + '?q=' + encodeURIComponent(query), {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                }).then(function (response) {
                    return response.ok ? response.json() : [];
                }).then(showResults).catch(function () {
                    results.innerHTML = '';
                    results.classList.add('d-none');
                });
            }, 250);
        });

        // Closes the results list on an outside click without discarding
        // whatever choice was already made.
        document.addEventListener('click', function (event) {
            var target = /** @type {Node} */ (event.target);
            if (target !== input && !results.contains(target)) {
                results.classList.add('d-none');
            }
        });
    })();

    // The "@" autocomplete, shared by the composer and every reply box —
    // any field carrying data-mention-url (show.html.twig, partials/
    // post_card.html.twig).
    //
    // It only ever inserts PLAIN TEXT: picking a name types "@Marie
    // Dupont" into the field and nothing else. No hidden id travels with
    // the message, because the server resolves the names back out of the
    // stored body itself (Service\MentionService) — which is also why a
    // member with no JavaScript can mention somebody by simply typing
    // their name, and why this whole block is optional.
    (function () {
        // One menu for the whole page, positioned under whichever field
        // is being typed into. Appended to <body> rather than next to the
        // field: the composer and a reply box sit in very different
        // boxes, and an absolutely-positioned child would need each of
        // them to be a positioning context.
        var menu = null;
        var activeField = null;
        var activeStart = -1;
        var searchTimer = null;

        function closeMenu() {
            if (menu) {
                menu.classList.add('d-none');
            }
            activeField = null;
            activeStart = -1;
        }

        function ensureMenu() {
            if (menu) {
                return menu;
            }
            menu = document.createElement('ul');
            menu.id = 'groups-mention-menu';
            menu.className = 'list-group shadow-sm d-none';
            menu.style.position = 'absolute';
            menu.style.zIndex = '1080';
            menu.style.maxHeight = '15rem';
            menu.style.overflowY = 'auto';
            menu.setAttribute('role', 'listbox');
            document.body.appendChild(menu);

            return menu;
        }

        function placeMenu(field) {
            var rect = field.getBoundingClientRect();
            menu.style.left = (rect.left + window.scrollX) + 'px';
            menu.style.top = (rect.bottom + window.scrollY + 2) + 'px';
            menu.style.minWidth = rect.width + 'px';
        }

        // The "@…" being typed right before the caret, or null. Bounded to
        // two words so a message that merely contains an "@" somewhere
        // does not keep querying for the rest of the sentence.
        function tokenBeforeCaret(field) {
            var caret = field.selectionStart;
            if (typeof caret !== 'number') {
                return null;
            }
            var match = /@([\p{L}][\p{L}\-']*(?:\s[\p{L}][\p{L}\-']*)?)?$/u.exec(field.value.slice(0, caret));
            if (!match) {
                return null;
            }

            return { start: caret - match[0].length, query: match[1] || '' };
        }

        function insertName(label) {
            if (!activeField || activeStart < 0) {
                return;
            }
            var caret = activeField.selectionStart;
            var before = activeField.value.slice(0, activeStart);
            var after = activeField.value.slice(caret);
            activeField.value = before + '@' + label + ' ' + after;
            var newCaret = (before + '@' + label + ' ').length;
            activeField.setSelectionRange(newCaret, newCaret);
            activeField.focus();
            // The composer caches drafts on its own input listener, so the
            // inserted name has to look like typing to it.
            activeField.dispatchEvent(new Event('input', { bubbles: true }));
            closeMenu();
        }

        function render(members) {
            if (members.length === 0) {
                closeMenu();
                return;
            }
            menu.innerHTML = '';
            members.forEach(function (member, index) {
                var item = document.createElement('li');
                item.className = 'list-group-item list-group-item-action groups-mention-option';
                item.style.cursor = 'pointer';
                item.style.minHeight = '44px';
                item.setAttribute('role', 'option');
                item.dataset.label = member.label;
                item.textContent = member.label;
                if (index === 0) {
                    item.classList.add('active');
                }
                menu.appendChild(item);
            });
            menu.classList.remove('d-none');
        }

        function highlighted() {
            return menu && !menu.classList.contains('d-none')
                ? /** @type {HTMLElement} */ (menu.querySelector('.groups-mention-option.active'))
                : null;
        }

        function move(step) {
            var current = highlighted();
            if (!current) {
                return;
            }
            var options = Array.prototype.slice.call(menu.querySelectorAll('.groups-mention-option'));
            var next = options[(options.indexOf(current) + step + options.length) % options.length];
            current.classList.remove('active');
            next.classList.add('active');
        }

        document.addEventListener('input', function (event) {
            var field = /** @type {HTMLInputElement|HTMLTextAreaElement} */ (
                /** @type {HTMLElement} */ (event.target).closest('[data-mention-url]')
            );
            if (!field) {
                return;
            }

            clearTimeout(searchTimer);
            var token = tokenBeforeCaret(field);
            // Two letters before querying, same floor as the invite box:
            // "@" alone in a group of forty is not a search.
            if (!token || token.query.length < 2) {
                closeMenu();
                return;
            }

            activeField = field;
            activeStart = token.start;
            searchTimer = setTimeout(function () {
                fetch(field.dataset.mentionUrl + '?q=' + encodeURIComponent(token.query), {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                }).then(function (response) {
                    return response.ok ? response.json() : [];
                }).then(function (members) {
                    if (activeField !== field) {
                        return;
                    }
                    ensureMenu();
                    placeMenu(field);
                    render(members);
                }).catch(closeMenu);
            }, 250);
        });

        // Enter would submit a reply box and Tab would leave the field, so
        // both are intercepted while the menu is open — and only then.
        document.addEventListener('keydown', function (event) {
            var option = highlighted();
            if (!option || !activeField) {
                return;
            }

            if (event.key === 'Escape') {
                closeMenu();
                return;
            }
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                move(event.key === 'ArrowDown' ? 1 : -1);
                return;
            }
            if (event.key === 'Enter' || event.key === 'Tab') {
                event.preventDefault();
                insertName(option.dataset.label);
            }
        });

        document.addEventListener('click', function (event) {
            var target = /** @type {HTMLElement} */ (event.target);
            var option = /** @type {HTMLElement} */ (target.closest('.groups-mention-option'));
            if (option) {
                event.preventDefault();
                insertName(option.dataset.label);
                return;
            }
            if (menu && !menu.contains(target)) {
                closeMenu();
            }
        });
    })();

    // Polls for a photo/video still being resized in the background so
    // the real thumbnail appears in place the moment it is ready, with no
    // page reload. Every 'pending'/'processing' media cell already
    // carries data-media-id/data-status (partials/post_media_grid.html.twig,
    // partials/reply_card.html.twig); without this script they stay
    // spinners until the next full page load, exactly as before this
    // existed. A plain function (not its own IIFE, unlike the blocks
    // above) so kickOffMediaPolling() can be called again after "Charger
    // plus"/"Voir plus de réponses" insert content that might itself
    // carry a still-pending photo.
    var mediaPollTimer = null;
    var mediaPollAttempt = 0;
    var MEDIA_POLL_DELAYS_MS = [2000, 2000, 3000, 5000, 5000, 10000, 10000, 10000, 10000, 10000, 10000, 10000];

    function pendingMediaIds() {
        return Array.from(document.querySelectorAll(
            '[data-media-id][data-status="pending"], [data-media-id][data-status="processing"]'
        )).map(function (el) { return /** @type {HTMLElement} */ (el).dataset.mediaId; });
    }

    function pollMediaStatus() {
        var feed = document.getElementById('groups-feed');
        var ids = pendingMediaIds();
        if (!feed || ids.length === 0) {
            mediaPollTimer = null;
            return;
        }

        fetch('/groups/' + feed.dataset.groupId + '/media-status?ids=' + ids.join(','), {
            headers: { 'X-Requested-With': 'fetch' }
        }).then(function (response) {
            return response.ok ? response.json() : [];
        }).then(function (items) {
            items.forEach(function (item) {
                if (item.status === 'pending' || item.status === 'processing' || typeof item.html !== 'string') {
                    return;
                }
                var cell = /** @type {HTMLElement} */ (document.querySelector('[data-media-id="' + item.id + '"]'));
                if (cell) {
                    cell.dataset.status = item.status;
                    cell.innerHTML = item.html;
                }
            });
        }).catch(function () {
            // A dropped request just gets retried on the next backoff
            // step below — the spinner it left behind is still accurate.
        }).finally(function () {
            scheduleNextMediaPoll();
        });
    }

    function scheduleNextMediaPoll() {
        if (pendingMediaIds().length === 0 || mediaPollAttempt >= MEDIA_POLL_DELAYS_MS.length) {
            mediaPollTimer = null;
            return;
        }
        var delay = MEDIA_POLL_DELAYS_MS[mediaPollAttempt];
        mediaPollAttempt += 1;
        mediaPollTimer = setTimeout(pollMediaStatus, delay);
    }

    function kickOffMediaPolling() {
        if (mediaPollTimer !== null || pendingMediaIds().length === 0) {
            return;
        }
        mediaPollAttempt = 0;
        scheduleNextMediaPoll();
    }

    kickOffMediaPolling();

    // Delete/reply/react all still post and redirect with no JS at all —
    // every branch below only upgrades that same POST to a fetch() so the
    // page never reloads, each falling back to the real form submit on
    // anything its own JSON path does not recognise (a stale CSRF token,
    // an unexpected response).
    // "Supprimer", on a post or on a reply: the base.html.twig confirm()
    // dialog is answered HERE rather than left to that later, separately
    // registered listener — this one runs first (it is registered by the
    // time base.html.twig's own script tag runs, later in the page) and
    // would otherwise fire off the delete fetch() before the member had
    // even answered the prompt. stopImmediatePropagation() is what stops
    // base.html.twig's listener from then asking a second, redundant time.
    //
    // The caller has already called preventDefault/stopImmediatePropagation
    // — this only decides what the confirmed deletion does.
    function submitDeleteInPlace(form, removeSelector) {
        var container = form.closest(removeSelector);
        fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (response) {
            if (response.ok && container) {
                container.remove();
            } else {
                form.submit();
            }
        }).catch(function () {
            form.submit();
        });
    }

    // An inline edit: the server answers with the re-rendered fragment,
    // which replaces `target`. What `target` is differs by item on
    // purpose — a post's is only its own <p> body, so the reply thread
    // expanded underneath survives the edit; a reply's is its whole card,
    // which has no thread of its own to preserve (and carries the edit
    // form itself, so closing it is implicit in the swap).
    function submitInlineEdit(form, target, closeFormAfter) {
        if (!target) {
            form.submit();
            return;
        }

        fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (response) {
            return response.json().catch(function () { return null; }).then(function (data) {
                return { ok: response.ok, data: data };
            });
        }).then(function (result) {
            if (result.ok && result.data && typeof result.data.html === 'string') {
                target.outerHTML = result.data.html;
                if (closeFormAfter) {
                    form.classList.add('d-none');
                }
                kickOffMediaPolling();
            } else if (result.data && typeof result.data.error === 'string') {
                // A refusal the server actually returned (the moderation
                // layer, most often) — the edit form stays open with the
                // member's text in it so they can revise and resend.
                window.alert(result.data.error);
            } else {
                form.submit();
            }
        }).catch(function () {
            form.submit();
        });
    }

    document.addEventListener('submit', function (event) {
        var target = /** @type {HTMLElement} */ (event.target);

        var postDeleteForm = /** @type {HTMLFormElement} */ (target.closest('.groups-post-delete-form'));
        var replyDeleteForm = /** @type {HTMLFormElement} */ (target.closest('.groups-reply-delete-form'));
        var deleteForm = postDeleteForm || replyDeleteForm;
        if (deleteForm) {
            event.preventDefault();
            event.stopImmediatePropagation();
            if (deleteForm.dataset.confirm && !confirm(deleteForm.dataset.confirm)) {
                return;
            }
            submitDeleteInPlace(deleteForm, postDeleteForm ? 'article' : '.groups-reply');
            return;
        }

        // Editing a post: only its own <p> body is swapped (see
        // submitInlineEdit) so the replies underneath are left alone.
        var postEditForm = /** @type {HTMLFormElement} */ (target.closest('.groups-edit-form'));
        if (postEditForm) {
            event.preventDefault();
            submitInlineEdit(
                postEditForm,
                document.getElementById('post-body-' + postEditForm.id.replace('post-edit-', '')),
                true
            );
            return;
        }

        // Editing a reply: the whole card comes back, since a reply has no
        // thread of its own to preserve underneath it.
        var replyEditForm = /** @type {HTMLFormElement} */ (target.closest('.groups-reply-edit-form'));
        if (replyEditForm) {
            event.preventDefault();
            submitInlineEdit(replyEditForm, replyEditForm.closest('.groups-reply'), false);
            return;
        }

        // Replying to a post still posts and redirects with no JS at all
        // — this upgrades it to a fetch() exactly like a reaction's own
        // form just below, appending the new reply under the post instead
        // of reloading. Same X-Requested-With signal, same fallback to a
        // real form submit on anything the JSON path does not recognise.
        var replyForm = /** @type {HTMLFormElement} */ (target.closest('.groups-reply-form'));
        if (replyForm) {
            event.preventDefault();

            var repliesContainer = replyForm.closest('article')?.querySelector('.groups-replies');
            var replyError = replyForm.querySelector('.groups-reply-error');
            var replySubmitBtn = /** @type {HTMLButtonElement} */ (replyForm.querySelector('button[type="submit"]'));
            if (replyError) {
                replyError.textContent = '';
                replyError.classList.add('d-none');
            }
            if (replySubmitBtn) replySubmitBtn.disabled = true;

            fetch(replyForm.action, {
                method: 'POST',
                body: new FormData(replyForm),
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (response) {
                return response.json().catch(function () { return null; }).then(function (data) {
                    return { ok: response.ok, data: data };
                });
            }).then(function (result) {
                if (result.ok && result.data && typeof result.data.html === 'string') {
                    if (repliesContainer) {
                        repliesContainer.insertAdjacentHTML('beforeend', result.data.html);
                        kickOffMediaPolling();
                    }
                    replyForm.reset();
                    var replyImageName = replyForm.querySelector('.groups-reply-image-name');
                    if (replyImageName) {
                        replyImageName.textContent = '';
                        replyImageName.classList.add('d-none');
                    }
                } else if (result.data && typeof result.data.error === 'string' && replyError) {
                    replyError.textContent = result.data.error === 'empty' ? 'Une réponse ne peut pas être vide.' : result.data.error;
                    replyError.classList.remove('d-none');
                } else if (!result.data) {
                    replyForm.submit();
                }
            }).catch(function () {
                if (replyError) {
                    replyError.textContent = 'Connexion perdue : la réponse n\'a pas pu être envoyée. Réessayez.';
                    replyError.classList.remove('d-none');
                }
            }).finally(function () {
                if (replySubmitBtn) replySubmitBtn.disabled = false;
            });
            return;
        }

        // A reaction button's form still posts and redirects with no JS at
        // all (partials/reactions.html.twig's own docblock promise) — this
        // only upgrades that same POST to a fetch() so the page never
        // reloads. The `X-Requested-With` header is what tells
        // Controller\ReactionController to answer with the freshly rendered
        // fragment (JSON: {outcome, html}) instead of its usual redirect;
        // any failure — network, non-2xx, a malformed body — falls through
        // to the plain form submit, so a stale CSRF token or a dropped
        // connection degrades to a page reload rather than doing nothing.
        var form = /** @type {HTMLFormElement} */ (target.closest('.groups-reaction-form'));
        if (!form) {
            return;
        }
        event.preventDefault();

        var container = form.closest('.groups-reactions');
        fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('reaction request failed');
            }
            return response.json();
        }).then(function (data) {
            if (container && typeof data.html === 'string') {
                container.outerHTML = data.html;
            } else {
                form.submit();
            }
        }).catch(function () {
            form.submit();
        });
    });

    // The shared "who…?" dialog: a reaction tally's "who reacted, and
    // with what" (Controller\ReactionController's postReactors()/
    // replyReactors()) and a post's "vu par" (Controller\PostController::
    // seenBy()) both land here — one dialog per page, filled with
    // whatever the trigger's own URL renders.
    //
    // Every trigger is a plain <button> with no bootstrap data-*
    // attributes of its own, so nothing breaks if groups.js never loads:
    // the line just stops being clickable and stays a plain summary.
    async function openDetailDialog(url, title, errorText) {
        var modalEl = document.getElementById('groups-detail-modal');
        var modalBody = document.getElementById('groups-detail-modal-body');
        if (!modalEl || !modalBody || typeof bootstrap === 'undefined') {
            return;
        }
        var label = document.getElementById('groups-detail-modal-label');
        if (label && title) {
            label.textContent = title;
        }
        modalBody.innerHTML = '<div class="text-center py-3"><span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span></div>';
        var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        modal.show();

        var response = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        if (response.ok) {
            var data = await response.json();
            if (typeof data.html === 'string') {
                modalBody.innerHTML = data.html;
            }
        } else {
            modalBody.textContent = errorText;
        }
    }

    // Shared by "Charger plus" (the feed) and "Voir plus de réponses" (a
    // post's replies) — both append the next keyset page in place and
    // remove their own "more" button/wrapper the same way.
    async function loadMoreInPlace(button, wrapperSelector) {
        button.disabled = true;
        var response = await fetch(button.dataset.url, { headers: { 'X-Requested-With': 'fetch' } });
        if (response.ok) {
            var wrapper = /** @type {HTMLElement} */ (button.closest(wrapperSelector));
            wrapper.insertAdjacentHTML('beforebegin', await response.text());
            wrapper.remove();
            kickOffMediaPolling();
        } else {
            button.disabled = false;
        }
    }

    // "Copier le lien" on a post. The absolute URL is built here from the
    // relative one the template renders, so the copied link works when
    // pasted anywhere — a bare "/groups/3/posts/12" would not.
    //
    // navigator.clipboard needs a secure context (https, or localhost) and
    // is simply absent otherwise; window.prompt with the URL pre-selected
    // is the honest fallback — the member can still copy it by hand,
    // rather than the entry silently doing nothing.
    async function copyMessageLink(button) {
        var url = new URL(button.dataset.url, window.location.origin).href;
        var original = button.textContent;

        try {
            if (!navigator.clipboard) {
                throw new Error('clipboard unavailable');
            }
            await navigator.clipboard.writeText(url);
            button.textContent = 'Lien copié';
            setTimeout(function () { button.textContent = original; }, 2000);
        } catch (e) {
            window.prompt('Copiez le lien de ce message :', url);
        }
    }

    function toggleEditForm(prefix, id, showEdit) {
        document.getElementById(prefix + '-edit-' + id)?.classList.toggle('d-none', !showEdit);
        document.getElementById(prefix + '-body-' + id)?.classList.toggle('d-none', showEdit);
    }

    // "Charger plus" appends the next keyset page in place, and the inline
    // edit form toggles without leaving the feed. Both degrade to a plain
    // page reload if this script never runs: the button is a real link target
    // server-side, and every action is a normal form POST.
    document.addEventListener('click', async function (event) {
        var target = /** @type {HTMLElement} */ (event.target);

        // A media cell is a REAL <a> to the media's large rendition, so it
        // works with no JS at all. gallery.js's lightbox binds its own
        // click on the same element without preventing that navigation —
        // so suppressing it is this file's job, and only when the viewer
        // is genuinely on the page AND the cell has a rendition to show.
        // A still-processing or failed media keeps the plain link, which
        // is exactly what its missing data-medium-url already signals.
        var mediaCell = /** @type {HTMLElement} */ (target.closest('.groups-media-cell.gallery-lightbox-trigger'));
        if (mediaCell && mediaCell.dataset.mediumUrl && document.getElementById('gallery-lightbox')) {
            event.preventDefault();
            return;
        }

        var copyLink = /** @type {HTMLElement} */ (target.closest('.groups-copy-link'));
        if (copyLink) {
            await copyMessageLink(copyLink);
            return;
        }

        var tally = /** @type {HTMLElement} */ (target.closest('.groups-reaction-tally'));
        if (tally) {
            await openDetailDialog(tally.dataset.reactorsUrl, 'Réactions', 'Impossible de charger les réactions.');
            return;
        }

        var seenBy = /** @type {HTMLElement} */ (target.closest('.groups-seen-by'));
        if (seenBy) {
            await openDetailDialog(seenBy.dataset.url, seenBy.dataset.dialogTitle, 'Impossible de charger la liste.');
            return;
        }

        var loadMore = /** @type {HTMLButtonElement} */ (target.closest('.groups-load-more'));
        if (loadMore) {
            await loadMoreInPlace(loadMore, '.groups-load-more-wrapper');
            return;
        }

        var editToggle = /** @type {HTMLElement} */ (target.closest('.groups-edit-toggle'));
        if (editToggle) {
            toggleEditForm('post', editToggle.dataset.post, true);
            return;
        }

        var editCancel = /** @type {HTMLElement} */ (target.closest('.groups-edit-cancel'));
        if (editCancel) {
            toggleEditForm('post', editCancel.dataset.post, false);
            return;
        }

        // "Voir plus de réponses" — same in-place pagination as "Charger
        // plus" above, degrading the same way if this script never runs.
        var repliesMore = /** @type {HTMLButtonElement} */ (target.closest('.groups-replies-more'));
        if (repliesMore) {
            await loadMoreInPlace(repliesMore, '.groups-replies-more-wrapper');
            return;
        }

        var replyEditToggle = /** @type {HTMLElement} */ (target.closest('.groups-reply-edit-toggle'));
        if (replyEditToggle) {
            toggleEditForm('reply', replyEditToggle.dataset.reply, true);
            return;
        }

        var replyEditCancel = /** @type {HTMLElement} */ (target.closest('.groups-reply-edit-cancel'));
        if (replyEditCancel) {
            toggleEditForm('reply', replyEditCancel.dataset.reply, false);
        }
    });

    // The reply composer's image picker is a bare <input type="file"> with no
    // preview grid (one image, not four) — this only surfaces the chosen
    // filename so the member can tell something is attached before sending.
    document.addEventListener('change', function (event) {
        var eventTarget = /** @type {HTMLElement} */ (event.target);
        var input = /** @type {HTMLInputElement} */ (eventTarget.closest('.groups-reply-image'));
        if (!input) {
            return;
        }

        var label = input.closest('form')?.querySelector('.groups-reply-image-name');
        if (!label) {
            return;
        }

        var name = input.files && input.files.length > 0 ? input.files[0].name : '';
        label.textContent = name;
        label.classList.toggle('d-none', name === '');
    });
})();
