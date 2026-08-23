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
            // A photo on its own is a publishable post, and picking one
            // fires no 'input' event on the form.
            refreshSubmitState();
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
            textarea.addEventListener('blur', function (event) {
                clearTimeout(linkDebounceTimer);
                // Not when the field is being left FOR the publish button,
                // and this is not a nicety: pressing it moves focus, which
                // fires this blur, which reveals the preview container
                // (spinner first) — and that container sits directly above
                // the button. The button therefore slid out from under the
                // pointer between mousedown and mouseup, no `click` was
                // ever produced, and a message containing a link silently
                // did nothing the first time "Publier" was pressed. There
                // is nothing to preview at this instant anyway: the post is
                // being published, and its card will carry the real,
                // server-resolved link a moment later.
                if (submitBtn && event.relatedTarget === submitBtn) {
                    return;
                }
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

        // --- "Publier", offered only when there is something to publish.
        //
        // The same four things the server accepts a post for (Service\
        // PostService::isPostable()): some text, a photo, a link, or a
        // poll with a question and at least two choices. Pressing the
        // button with none of them used to spend a round trip to be told
        // « Un message ne peut pas être vide. », which is a sentence
        // nobody should ever have to read about a form they can see is
        // empty — and which Controller\PostController::create() has
        // always assumed the composer prevented.
        //
        // Client-side convenience only: the server still refuses an empty
        // post on its own, exactly as before, because this button is not
        // a boundary.
        var pollQuestion = /** @type {HTMLInputElement} */ (form.querySelector('[name="poll_question"]'));

        function hasSomethingToPost() {
            if (textarea.value.trim() !== '' || selectedFiles.length > 0) {
                return true;
            }
            // A poll is only a poll with a question AND two real choices,
            // the same rule Service\PollService::normalise() applies — so
            // a half-filled one must not light the button up either.
            if (!pollQuestion || pollQuestion.value.trim() === '') {
                return false;
            }
            var filled = Array.prototype.filter.call(
                form.querySelectorAll('[name="poll_options[]"]'),
                function (option) { return option.value.trim() !== ''; }
            );

            return filled.length >= 2;
        }

        function refreshSubmitState() {
            if (submitBtn && !form.classList.contains('groups-composer-busy')) {
                submitBtn.disabled = !hasSomethingToPost();
            }
        }

        // 'input' on the form itself rather than on each field: it bubbles
        // from every control inside it, so the poll boxes, the message and
        // anything added to the composer later are all covered by one
        // listener. The media picker calls it directly (syncMedia), since
        // choosing a file fires no 'input' on the form.
        form.addEventListener('input', refreshSubmitState);
        refreshSubmitState();

        // --- The poll's own boxes: always exactly one empty one at the
        // end, and never more than the server's own maximum.
        //
        // The rule is the whole control: typing in the last box adds a
        // fresh empty one after it, clearing a box removes the spare
        // empties so only one is left waiting, and removing a row never
        // takes the count below the two a poll needs. With no JavaScript
        // the three boxes the server rendered are simply what you get,
        // and Service\PollService::normalise() drops the blank ones
        // either way — this makes a long poll pleasant to type, it is not
        // where the rules live.
        var pollDetails = /** @type {HTMLElement|null} */ (form.querySelector('#groups-poll-details'));
        var pollOptions = form.querySelector('#groups-poll-options');

        /** @returns {HTMLInputElement[]} */
        function pollInputs() {
            return /** @type {HTMLInputElement[]} */ (
                Array.prototype.slice.call(form.querySelectorAll('[name="poll_options[]"]'))
            );
        }

        function pollMaxOptions() {
            var declared = pollDetails ? parseInt(pollDetails.dataset.maxOptions || '', 10) : NaN;

            return isNaN(declared) ? 10 : declared;
        }

        /** @param {number} index */
        function buildPollRow(index) {
            var row = document.createElement('div');
            row.className = 'd-flex align-items-center gap-2 mb-1 groups-poll-option';
            var label = document.createElement('label');
            label.className = 'visually-hidden';
            label.setAttribute('for', 'poll-option-' + index);
            label.textContent = 'Choix ' + index;
            var input = document.createElement('input');
            input.type = 'text';
            input.className = 'form-control';
            input.id = 'poll-option-' + index;
            input.name = 'poll_options[]';
            input.style.minHeight = '44px';
            input.placeholder = 'Choix ' + index + ' (facultatif)';
            var first = pollInputs()[0];
            if (first && first.maxLength > 0) {
                input.maxLength = first.maxLength;
            }
            var remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'btn btn-outline-secondary d-flex align-items-center groups-poll-option-remove';
            remove.style.minHeight = '44px';
            remove.setAttribute('aria-label', 'Retirer le choix ' + index);
            remove.innerHTML = '<i class="bi bi-x-lg" aria-hidden="true"></i>';
            row.appendChild(label);
            row.appendChild(input);
            row.appendChild(remove);

            return row;
        }

        function syncPollOptions() {
            if (!pollOptions) {
                return;
            }

            var inputs = pollInputs();
            var max = pollMaxOptions();

            // Trailing empties, collapsed to one. Never touches a box
            // somebody is typing in: only the ones AFTER the last filled
            // one, and only past the first of them.
            var lastFilled = -1;
            inputs.forEach(function (input, index) {
                if (input.value.trim() !== '') {
                    lastFilled = index;
                }
            });

            // Never below the shape the server renders: two answer boxes
            // plus the one waiting at the end. A poll needs two answers,
            // and taking the spare away from an empty form would make the
            // control smaller the moment somebody cleared it.
            for (var i = inputs.length - 1; i > lastFilled + 1; i--) {
                if (inputs[i] !== document.activeElement && inputs.length > 3) {
                    var row = inputs[i].closest('.groups-poll-option');
                    if (row) {
                        row.remove();
                    }
                    inputs = pollInputs();
                }
            }

            // ...and one waiting at the end, unless the poll is already
            // as long as the server will accept.
            var current = pollInputs();
            var lastValue = current.length > 0 ? current[current.length - 1].value.trim() : '';
            if (lastValue !== '' && current.length < max) {
                pollOptions.appendChild(buildPollRow(current.length + 1));
            }

            // The remove buttons only make sense while there is something
            // to remove down to: three boxes — two answers and the spare —
            // is the floor.
            var rows = form.querySelectorAll('.groups-poll-option');
            rows.forEach(function (row) {
                var button = row.querySelector('.groups-poll-option-remove');
                if (button) {
                    /** @type {HTMLButtonElement} */ (button).disabled = rows.length <= 3;
                }
            });
        }

        if (pollOptions) {
            pollOptions.addEventListener('input', syncPollOptions);
            pollOptions.addEventListener('click', function (event) {
                var button = /** @type {HTMLElement} */ (event.target).closest('.groups-poll-option-remove');
                if (!button) {
                    return;
                }
                var row = button.closest('.groups-poll-option');
                if (row && form.querySelectorAll('.groups-poll-option').length > 3) {
                    row.remove();
                }
                syncPollOptions();
                refreshSubmitState();
            });
            syncPollOptions();
        }

        // --- Dynamic submit: greys out the composer while the post
        // publishes in the background (module spec: "no reload to
        // publish a post"), instead of the plain form POST + redirect
        // this degrades to on any failure below.
        function setBusy(busy) {
            textarea.disabled = busy;
            if (submitBtn) submitBtn.disabled = busy;
            form.classList.toggle('groups-composer-busy', busy);
            if (!busy) {
                // Back to whatever the composer's own contents justify —
                // never unconditionally enabled, or a failed submit would
                // leave an empty composer offering to publish nothing.
                refreshSubmitState();
            }
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
            // A stale suggestion goes with the stale error: the next
            // refusal brings its own, or none.
            clearModerationSuggestion(form);
        }

        function resetComposer() {
            form.reset();
            // The poll's extra boxes are DOM this script added, so
            // form.reset() empties them without removing them: a second
            // poll would open on however many rows the first one grew to.
            // Back to the three the server renders, then the usual sync.
            var extraRows = Array.prototype.slice.call(form.querySelectorAll('.groups-poll-option')).slice(3);
            extraRows.forEach(function (row) { row.remove(); });
            // form.reset() restores every CONTROL's default value, and a
            // <details> is not one: without this the poll section stayed
            // open after publishing, showing a set of freshly emptied
            // boxes as if a second poll were expected.
            form.querySelectorAll('details[open]').forEach(function (section) {
                /** @type {HTMLDetailsElement} */ (section).open = false;
            });
            resetMedia();
            resetLinkPreview();
            clearComposerError();
            syncPollOptions();
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            clearComposerError();

            // Snapshotted BEFORE setBusy(true), and that order is the
            // whole point: a disabled control is excluded from a form's
            // data set by the HTML standard, so building this after
            // greying the composer out submitted every field EXCEPT the
            // message itself — the server then saw an empty body and
            // refused the post with "Un message ne peut pas être vide."
            // (or, for a post that also carried a photo, published it
            // with the text silently dropped).
            var payload = new FormData(form);

            setBusy(true);

            fetch(form.action, {
                method: 'POST',
                body: payload,
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
                        // …which also means the group is no longer empty.
                        refreshFeedEmptyState();
                        restoreReplyDrafts();
                        // And then bring it into view. On a phone the
                        // composer fills the screen, so a message
                        // published from it lands entirely below the
                        // fold: nothing visibly happens, and the honest
                        // reading of that is "did it send?". Centred
                        // rather than scrolled to the top, so the start
                        // of the message is never left under a sticky
                        // header.
                        scrollToPost(result.data.post_id);
                    }
                    resetComposer();
                    clearDraft();
                } else if (result.data && typeof result.data.error === 'string') {
                    // A refusal the server actually returned (moderation,
                    // rate limit, media ceiling, an empty draft slipping
                    // past the UI) — shown inline, draft left untouched so
                    // the member can revise and resend.
                    showComposerError(result.data.error === 'empty' ? 'Un message ne peut pas être vide.' : result.data.error);
                    // And when the moderation offered a rewording, the
                    // panel the reload path has always had.
                    showModerationSuggestion(form, textarea, result.data.suggestion);
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
            // `isConnected`, not just "have we built one": a menu that has
            // been detached from the document can never be seen, and
            // re-using it would leave the autocomplete answering keystrokes
            // into nothing. Cheap, and it means the cache can never outlive
            // the node it points at.
            if (menu && menu.isConnected) {
                return menu;
            }
            if (menu) {
                document.body.appendChild(menu);

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
                // Two different strings on purpose: the row SHOWS the
                // account and its memberships so two people can be told
                // apart, and INSERTS the account's name alone, which is
                // what MentionService::resolve() reads back out of the
                // stored body. `mention` is absent from nothing the
                // server sends today; the fallback is for a cached page
                // served by an older one.
                item.dataset.label = member.mention || member.label;
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
    restoreReplyDrafts();

    // Tells components.css that the reaction picker may be folded away on
    // a phone, because there is now something able to unfold it again.
    // Set here rather than written into the template: a page whose
    // JavaScript failed to load must keep the picker visible, and the only
    // honest proof that this file is running is this file running.
    document.documentElement.classList.add('groups-js');

    // A collapsed conversation, opened for the two cases where the reader
    // has already said they want it.
    (function initThreads() {
        // 1. A deep link. Every notification about a group points at
        // /groups/{id}/posts/{postId}, which redirects to the feed
        // anchored on #post-{postId} (Controller\GroupController::post()).
        // For a "somebody answered you" notification the answer is inside
        // the fold, so landing on a closed thread would show the reader
        // the message they already knew about and nothing else.
        var anchored = /^#post-\d+$/.test(window.location.hash)
            ? /** @type {HTMLDetailsElement} */ (
                document.getElementById('post-thread-' + window.location.hash.slice('#post-'.length))
            )
            : null;
        if (anchored) {
            anchored.open = true;
            // The anchor was resolved by the browser before this ran, so
            // the page is scrolled to where the card USED to end. Bring it
            // back into view now that it is taller.
            anchored.scrollIntoView({ block: 'nearest' });
        }

        // 2. Opening a thread that has no comments at all. The summary
        // reads "Commenter" in that case, so there is nothing to read and
        // exactly one thing the reader can have meant. A thread that does
        // have comments is left alone: focusing its input would scroll
        // past them and, on a phone, raise the keyboard over what the
        // reader opened it to see.
        document.addEventListener('toggle', function (event) {
            var thread = /** @type {HTMLDetailsElement} */ (event.target);
            if (!thread.classList || !thread.classList.contains('groups-thread') || !thread.open) {
                return;
            }
            var count = /** @type {HTMLElement} */ (thread.querySelector('.groups-thread-count'));
            if (!count || (parseInt(count.dataset.count, 10) || 0) > 0) {
                return;
            }
            /** @type {HTMLInputElement} */ (
                thread.querySelector('.groups-reply-form input[name="body"]')
            )?.focus();
        }, true);
    })();

    // Delete/reply/react all still post and redirect with no JS at all —
    // every branch below only upgrades that same POST to a fetch() so the
    // page never reloads, each falling back to the real form submit on
    // anything its own JSON path does not recognise (a stale CSRF token,
    // an unexpected response).
    // "Supprimer", on a post or on a reply. The confirmation itself is
    // base.html.twig's, site-wide, and has already been answered by the
    // time this runs — see the delegated submit listener below for why
    // that ordering holds. This only decides what a confirmed deletion
    // does: remove the item where it stands instead of reloading.
    // --- A not-yet-sent COMMENT, cached in this browser and nowhere else.
    //
    // The message composer has kept a local draft since the module
    // shipped, for the case that actually happens: a lost connection or a
    // refused submit, after which retyping everything is the real cost. A
    // comment is shorter but exactly as annoying to lose, and its box
    // sits inside a collapsed thread — close it by accident and, without
    // this, the text was gone.
    //
    // Text only, same as the composer's: an attached image cannot
    // round-trip through JSON, and re-picking one is not what makes a
    // retry painful. Stored per (group, post), read from the form's own
    // data-* attributes; the TTL is the composer's own setting, since it
    // is the same "how long is a draft still worth restoring" question
    // (Modules\Groups: groups_draft_ttl_minutes).
    /**
     * The prefix is spelled here rather than kept in a `var` above:
     * restoreReplyDrafts() runs during this file's own evaluation, before
     * any `var` further down has been ASSIGNED (only its declaration is
     * hoisted), so a constant declared below would have been `undefined`
     * at exactly the moment the first restore needs it — which is how
     * every key came out as "undefined1-9" the first time this was
     * written.
     *
     * @param {HTMLFormElement} form
     * @return {string|null} null when the form does not identify itself,
     *         which is the signal to cache nothing rather than to invent a
     *         key two different posts could share.
     */
    function replyDraftKey(form) {
        var groupId = form.dataset.groupId;
        var postId = form.dataset.postId;

        return groupId && postId ? 'groups-reply-draft-' + groupId + '-' + postId : null;
    }

    function replyDraftTtlMinutes() {
        var composer = /** @type {HTMLFormElement} */ (document.getElementById('groups-post-form'));

        return (composer && parseInt(composer.dataset.draftTtlMinutes, 10)) || 60;
    }

    /**
     * @param {HTMLFormElement} form
     */
    function saveReplyDraft(form) {
        var key = replyDraftKey(form);
        var input = /** @type {HTMLInputElement} */ (form.querySelector('input[name="body"]'));
        if (!key || !input) {
            return;
        }
        try {
            if (input.value.trim() === '') {
                localStorage.removeItem(key);
            } else {
                localStorage.setItem(key, JSON.stringify({ body: input.value, savedAt: Date.now() }));
            }
        } catch (e) {
            // Storage full, disabled, or private browsing — the draft is
            // simply not cached; nothing else here depends on it.
        }
    }

    /**
     * @param {HTMLFormElement} form
     */
    function clearReplyDraft(form) {
        var key = replyDraftKey(form);
        if (!key) {
            return;
        }
        try {
            localStorage.removeItem(key);
        } catch (e) {
            // Same as above.
        }
    }

    // Called on load and again after anything inserts reply boxes ("Charger
    // plus", "Voir plus de réponses", a freshly published message). Never
    // overwrites a box that already has something in it: the server's own
    // rejected_draft (partials/post_card.html.twig) is more specific than
    // a merely locally-cached one, exactly as in the message composer.
    function restoreReplyDrafts() {
        document.querySelectorAll('.groups-reply-form').forEach(function (element) {
            var form = /** @type {HTMLFormElement} */ (element);
            var key = replyDraftKey(form);
            var input = /** @type {HTMLInputElement} */ (form.querySelector('input[name="body"]'));
            if (!key || !input || input.value.trim() !== '') {
                return;
            }

            var raw;
            try {
                raw = localStorage.getItem(key);
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
                clearReplyDraft(form);
                return;
            }
            if ((Date.now() - draft.savedAt) / 60000 > replyDraftTtlMinutes()) {
                clearReplyDraft(form);
                return;
            }

            input.value = draft.body;
            // A thread with something waiting to be sent in it is opened,
            // for the same reason one with a new comment is: a draft
            // nobody is shown is a draft nobody finishes.
            var thread = /** @type {HTMLDetailsElement} */ (form.closest('.groups-thread'));
            if (thread) {
                thread.open = true;
            }
        });
    }

    var replyDraftTimers = new WeakMap();

    // Delegated, because reply boxes arrive with content inserted long
    // after load — the same reason every other handler in this file is.
    document.addEventListener('input', function (event) {
        var input = /** @type {HTMLInputElement} */ (
            /** @type {HTMLElement} */ (event.target).closest('.groups-reply-form input[name="body"]')
        );
        if (!input) {
            return;
        }
        var form = /** @type {HTMLFormElement} */ (input.closest('.groups-reply-form'));
        clearTimeout(replyDraftTimers.get(form));
        replyDraftTimers.set(form, setTimeout(function () { saveReplyDraft(form); }, 500));
    });

    // "Aucun message dans ce groupe pour le moment." — shown exactly while
    // the group really is empty, as messages come and go without a reload.
    // The line is rendered by show.html.twig and only toggled here, so the
    // sentence lives in one place; every post card carries an id starting
    // "post-", which is what counts as a message whether it sits in the
    // stream or in the pinned block above it.
    function refreshFeedEmptyState() {
        var line = document.getElementById('groups-feed-empty');
        if (line) {
            line.classList.toggle('d-none', document.querySelectorAll('article[id^="post-"]').length > 0);
        }
    }

    /**
     * Brings a just-published message into view, centred.
     *
     * The card is inserted at the top of the feed, which on a phone is
     * below a composer tall enough to fill the screen — so publishing
     * looked like nothing happening at all. The anchor is the one the
     * card already carries (`#post-{id}`), the same one a notification's
     * deep link uses, so the two can never point at different things.
     *
     * Silent about everything it cannot do: no id (an older server
     * response), no such card, or a browser with no scrollIntoView
     * options support — the message is published either way, and this is
     * only where the eye lands.
     *
     * @param {number|string|undefined} postId
     */
    function scrollToPost(postId) {
        if (!postId) {
            return;
        }
        var card = document.getElementById('post-' + postId);
        if (!card || typeof card.scrollIntoView !== 'function') {
            return;
        }

        // Honouring "less movement" the same way the reaction picker's
        // own animation does (components.css): the same landing place,
        // without the travel.
        var reduced = typeof window.matchMedia === 'function'
            && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        card.scrollIntoView({ behavior: reduced ? 'auto' : 'smooth', block: 'center' });
    }

    // A message's collapsed conversation (partials/post_card.html.twig's
    // <details>): keeping the "3 commentaires" line honest as comments are
    // added and removed without a reload. The number lives in data-count
    // and the sentence is rewritten from it, so the plural can never drift
    // from the figure beside it.
    //
    // The wording is duplicated from the template on purpose, and it is
    // the same trade the media counter already makes: the alternative is a
    // round trip to the server for three words.
    /**
     * @param {HTMLElement|null} anyElementInsideTheThread
     * @param {number} delta
     */
    function adjustThreadCount(anyElementInsideTheThread, delta) {
        var thread = /** @type {HTMLDetailsElement} */ (
            anyElementInsideTheThread ? anyElementInsideTheThread.closest('.groups-thread') : null
        );
        if (!thread) {
            return;
        }
        var label = /** @type {HTMLElement} */ (thread.querySelector('.groups-thread-count'));
        if (!label) {
            return;
        }

        var count = Math.max(0, (parseInt(label.dataset.count, 10) || 0) + delta);
        label.dataset.count = String(count);
        if (count === 0) {
            label.textContent = 'Commenter';
        } else if (count === 1) {
            label.textContent = '1 commentaire';
        } else {
            label.textContent = count + ' commentaires';
        }
    }

    /**
     * A reply's own delete/report/restore form lives inside its
     * `.groups-reply` bubble; a post's does not — so this is "which of
     * the two am I" for all three, in one place rather than resolved
     * (and passed down) by whichever code matched the form in the first
     * place.
     *
     * @param {HTMLFormElement} form
     * @returns {string}
     */
    function replyOrPostSelector(form) {
        return form.closest('.groups-reply') ? '.groups-reply' : 'article';
    }

    /**
     * @param {HTMLFormElement} form
     */
    function submitDeleteInPlace(form) {
        var removeSelector = replyOrPostSelector(form);
        var container = form.closest(removeSelector);
        // Resolved BEFORE the fetch: once container.remove() has run, the
        // form is detached and can no longer find the thread it was in.
        var thread = /** @type {HTMLElement} */ (form.closest('.groups-thread'));
        fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (response) {
            if (response.ok && container) {
                container.remove();
                // Only a reply changes a thread's count — deleting the
                // post takes the whole thread with it, and may have just
                // emptied the group.
                if (removeSelector === '.groups-reply') {
                    adjustThreadCount(thread, -1);
                } else {
                    refreshFeedEmptyState();
                }
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

    // "Signaler", on a message or on a reply — its data-confirm answered
    // by base.html.twig, like every other one. Reporting used to reload the
    // whole group to show one line of reassurance; the line now appears
    // next to the item that was reported, which is also where the reader
    // is looking.
    //
    // The confirmation is whatever the server sends and nothing else, and
    // the entry is taken out of the menu afterwards: a second report by
    // the same member is refused by the UNIQUE index anyway, and offering
    // it again would invite the reporter to read something into the fact
    // that nothing visibly happened.
    /**
     * @param {HTMLFormElement} form
     */
    function submitReportInPlace(form) {
        var item = form.closest(replyOrPostSelector(form));
        var entry = form.closest('li');
        fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (response) {
            return response.json().catch(function () { return null; }).then(function (data) {
                return { ok: response.ok, data: data };
            });
        }).then(function (result) {
            if (!result.ok || !result.data || result.data.reported !== true) {
                form.submit();
                return;
            }
            var note = /** @type {HTMLElement} */ (
                item ? item.querySelector('.groups-report-confirmation') : null
            );
            if (note) {
                note.textContent = result.data.message || '';
                note.classList.remove('d-none');
            }
            if (entry) {
                entry.remove();
            }
        }).catch(function () {
            form.submit();
        });
    }

    // "Rétablir", the moderator's answer to an auto-hidden item. Nothing
    // about the item itself changes — a restored message is the message
    // as it already reads underneath its own warning — so the warning and
    // the dimming simply come off where it stands, with no fragment to
    // fetch and no reload.
    /**
     * @param {HTMLFormElement} form
     */
    function submitRestoreInPlace(form) {
        var item = /** @type {HTMLElement} */ (form.closest(replyOrPostSelector(form)));
        var banner = form.closest('.groups-hidden-banner');
        fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (response) {
            return response.json().catch(function () { return null; }).then(function (data) {
                return { ok: response.ok, data: data };
            });
        }).then(function (result) {
            if (result.ok && result.data && result.data.restored === true) {
                if (banner) {
                    banner.remove();
                }
                if (item) {
                    item.classList.remove('groups-post-hidden');
                    // A reply dims its bubble rather than its own wrapper.
                    item.querySelectorAll('.groups-post-hidden').forEach(function (dimmed) {
                        dimmed.classList.remove('groups-post-hidden');
                    });
                }
            } else if (result.data && typeof result.data.error === 'string') {
                // A moderator trying to restore their own reported content
                // — the one refusal this endpoint has, and one the member
                // has to actually read.
                window.alert(result.data.error);
            } else {
                form.submit();
            }
        }).catch(function () {
            form.submit();
        });
    }

    // The AI moderation's proposed rewording, shown where the refusal
    // happened. The server sends it alongside the refusal itself
    // (PostController::refuse() / ReplyController's JSON shape:
    // {error, type, suggestion}), and the no-JS path already renders it
    // as a panel above the composer (show.html.twig's rejected_draft
    // block, Support\RejectedDraft) — this is the same panel, same
    // classes, built for the fetch() path that never reloads. The
    // suggestion is a remote model's output over a minor's own words:
    // textContent throughout, never innerHTML, exactly like the link
    // preview's og: fields above.
    /**
     * @param {HTMLFormElement} form the composer or reply form the refusal answered
     * @param {HTMLTextAreaElement|HTMLInputElement} field where "Reprendre cette formulation" writes the suggestion
     * @param {*} suggestion the server's `suggestion` value — ignored unless a non-empty string
     */
    function showModerationSuggestion(form, field, suggestion) {
        clearModerationSuggestion(form);
        if (!field || typeof suggestion !== 'string' || suggestion === '') {
            return;
        }

        var panel = document.createElement('div');
        panel.className = 'alert alert-warning mt-2 mb-0 groups-moderation-suggestion';
        panel.setAttribute('role', 'alert');

        var intro = document.createElement('p');
        intro.className = 'mb-1 small';
        intro.textContent = 'Voici une reformulation possible, que vous pouvez reprendre telle quelle :';
        panel.appendChild(intro);

        var text = document.createElement('p');
        text.className = 'mb-2 fst-italic groups-rejected-suggestion';
        text.textContent = suggestion;
        panel.appendChild(text);

        var take = document.createElement('button');
        // type="button": the panel lives inside the form, and this
        // button must never re-submit the refused text it replaces.
        take.type = 'button';
        take.className = 'btn btn-sm btn-outline-secondary groups-suggestion-take';
        take.textContent = 'Reprendre cette formulation';
        take.addEventListener('click', function () {
            field.value = suggestion;
            // The same event typing would fire, so the submit button's
            // enabled state and the draft cache follow the new text.
            field.dispatchEvent(new Event('input', { bubbles: true }));
            panel.remove();
            field.focus();
        });
        panel.appendChild(take);

        // Right under the form's own error line when it has one (the
        // refusal the panel answers is printed there), at the end of the
        // form otherwise.
        var anchor = form.querySelector('#groups-post-error, .groups-reply-error');
        if (anchor) {
            anchor.insertAdjacentElement('afterend', panel);
        } else {
            form.appendChild(panel);
        }
    }

    /**
     * @param {HTMLFormElement} form
     */
    function clearModerationSuggestion(form) {
        var panel = form.querySelector('.groups-moderation-suggestion');
        if (panel) {
            panel.remove();
        }
    }

    // Replying to a post still posts and redirects with no JS at all —
    // this upgrades it to a fetch() exactly like a reaction's own form
    // (see swapFragmentOnSubmit()), appending the new reply under the
    // post instead of reloading. Same X-Requested-With signal, same
    // fallback to a real form submit on anything the JSON path does not
    // recognise.
    /**
     * @param {HTMLFormElement} form
     */
    function submitReplyInPlace(form) {
        var repliesContainer = form.closest('article')?.querySelector('.groups-replies');
        var replyError = form.querySelector('.groups-reply-error');
        var replySubmitBtn = /** @type {HTMLButtonElement} */ (form.querySelector('button[type="submit"]'));
        if (replyError) {
            replyError.textContent = '';
            replyError.classList.add('d-none');
        }
        clearModerationSuggestion(form);
        // Snapshotted before anything in the form is disabled, for the
        // same reason the composer's own submit does it in that order: a
        // disabled control contributes nothing to a form's data set (see
        // initComposer()'s submit handler).
        var replyPayload = new FormData(form);
        if (replySubmitBtn) replySubmitBtn.disabled = true;

        fetch(form.action, {
            method: 'POST',
            body: replyPayload,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (response) {
            return response.json().catch(function () { return null; }).then(function (data) {
                return { ok: response.ok, data: data };
            });
        }).then(function (result) {
            if (result.ok && result.data && typeof result.data.html === 'string') {
                if (repliesContainer) {
                    // At the very end, after the "Voir plus de réponses"
                    // button when there is one — and that is deliberate,
                    // not an oversight. Comments are shown oldest first
                    // and that button loads the ones BETWEEN what is on
                    // screen and this new one, so slipping it in above
                    // the button would place it before comments written
                    // before it. Loading the rest folds it back into
                    // place; scrolling to it is what answers "where did
                    // mine go?" meanwhile.
                    repliesContainer.insertAdjacentHTML('beforeend', result.data.html);
                    var posted = repliesContainer.lastElementChild;
                    if (posted && typeof posted.scrollIntoView === 'function') {
                        posted.scrollIntoView({ block: 'nearest' });
                    }
                    kickOffMediaPolling();
                }
                adjustThreadCount(form, 1);
                form.reset();
                clearReplyDraft(form);
                clearReplyImagePreview(form);
            } else if (result.data && typeof result.data.error === 'string' && replyError) {
                replyError.textContent = result.data.error === 'empty' ? 'Une réponse ne peut pas être vide.' : result.data.error;
                replyError.classList.remove('d-none');
                // Same rewording panel as the composer's, under this
                // reply's own error line.
                showModerationSuggestion(form, /** @type {HTMLInputElement} */ (form.querySelector('input[name="body"]')), result.data.suggestion);
            } else if (!result.data) {
                form.submit();
            }
        }).catch(function () {
            if (replyError) {
                replyError.textContent = 'Connexion perdue : la réponse n\'a pas pu être envoyée. Réessayez.';
                replyError.classList.remove('d-none');
            }
        }).finally(function () {
            if (replySubmitBtn) replySubmitBtn.disabled = false;
        });
    }

    // Pinning: exclusive, and for a chosen length of time — both of which
    // the moderator has to be told before it happens, so the submit waits
    // on a dialog instead of going straight out.
    /**
     * @param {HTMLFormElement} form
     */
    function submitPinForm(form) {
        askPinDuration(form).then(function (chosen) {
            if (chosen) {
                form.submit();
            }
        });
    }

    // A poll option, upgraded the same way a reaction's own form is (see
    // swapFragmentOnSubmit() below): its form posts and redirects
    // perfectly well with no JS (partials/poll.html.twig's own docblock
    // promise). A member-scoped poll asks WHOSE answer this is before
    // recording it — the small dialog below, in place of the select the
    // page renders for a visitor with no JavaScript. One member to choose
    // from means there is nothing to ask.
    /**
     * @param {HTMLFormElement} form
     */
    function submitPollVote(form) {
        askPollVoter(form).then(function (proceed) {
            if (proceed) {
                swapFragmentOnSubmit(form, '.groups-poll');
            }
        });
    }

    document.addEventListener('submit', function (event) {
        var target = /** @type {HTMLElement} */ (event.target);

        // The "êtes-vous sûr ?" behind data-confirm is asked ONCE, by
        // base.html.twig's site-wide handler, and this listener only reads
        // its verdict.
        //
        // The ordering is a guarantee, not a coincidence: base.html.twig's
        // handler is an inline classic script, and this file is loaded
        // with `defer`, which the HTML standard runs after every inline
        // script in the document — whatever order the two tags appear in.
        // So by the time anything below runs, the member has already
        // answered, and a cancelled confirmation has already called
        // preventDefault() for us.
        //
        // This file used to call confirm() itself as well, on the belief
        // that it ran first and could stopImmediatePropagation() the other
        // one away. It never did run first, so deleting a message asked
        // twice — and answering the second prompt with "Annuler" left the
        // deletion already sent.
        if (event.defaultPrevented) {
            return;
        }

        var pinForm = /** @type {HTMLFormElement} */ (target.closest('.groups-pin-form'));
        if (pinForm) {
            event.preventDefault();
            submitPinForm(pinForm);
            return;
        }

        var deleteForm = /** @type {HTMLFormElement} */ (
            target.closest('.groups-post-delete-form, .groups-reply-delete-form')
        );
        if (deleteForm) {
            event.preventDefault();
            submitDeleteInPlace(deleteForm);
            return;
        }

        var reportForm = /** @type {HTMLFormElement} */ (target.closest('.groups-report-form'));
        if (reportForm) {
            event.preventDefault();
            submitReportInPlace(reportForm);
            return;
        }

        var restoreForm = /** @type {HTMLFormElement} */ (target.closest('.groups-restore-form'));
        if (restoreForm) {
            event.preventDefault();
            submitRestoreInPlace(restoreForm);
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

        var replyForm = /** @type {HTMLFormElement} */ (target.closest('.groups-reply-form'));
        if (replyForm) {
            event.preventDefault();
            submitReplyInPlace(replyForm);
            return;
        }

        // A reaction button's form still posts and redirects with no JS
        // at all (partials/reactions.html.twig's own docblock promise) —
        // this only upgrades that same POST to a fetch() so the page
        // never reloads. See swapFragmentOnSubmit() below.
        var pollForm = /** @type {HTMLFormElement} */ (target.closest('.groups-poll-form'));
        if (pollForm) {
            event.preventDefault();
            submitPollVote(pollForm);
            return;
        }

        var form = /** @type {HTMLFormElement} */ (target.closest('.groups-reaction-form'));
        if (!form) {
            return;
        }
        event.preventDefault();
        swapFragmentOnSubmit(form, '.groups-reactions');
    });

    // Shared by a reaction button and a poll option: POST the form, then
    // replace the block it lives in with the server's freshly rendered
    // answer. The `X-Requested-With` header is what tells the controller
    // to return that fragment (JSON: {html}) instead of its usual
    // redirect; ANY failure — network, non-2xx, a malformed body — falls
    // through to the plain form submit, so a stale CSRF token or a
    // dropped connection degrades to a page reload rather than to
    // nothing happening at all.
    function swapFragmentOnSubmit(form, containerSelector) {
        var container = form.closest(containerSelector);
        fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('request failed');
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
    }

    // The shared "who…?" dialog: a reaction tally's "who reacted, and
    // with what" (Controller\ReactionController's postReactors()/
    // replyReactors()) and a post's "vu par" (Controller\PostController::
    // seenBy()) both land here — one dialog per page, filled with
    // whatever the trigger's own URL renders.
    //
    // Every trigger is a plain <button> with no bootstrap data-*
    // attributes of its own, so nothing breaks if groups.js never loads:
    // the line just stops being clickable and stays a plain summary.
    /**
     * "Vous répondez pour…" — asked at the moment of voting in a
     * member-scoped poll, and only when this account really has several
     * members in the group.
     *
     * Resolves to true once the choice is written into the form's own
     * hidden field (or immediately, when there is nothing to ask), and to
     * false when the person closed the dialog — which cancels the vote
     * rather than recording it for whoever happened to be first.
     *
     * The <select> it reads its options from is the no-JavaScript
     * fallback the server rendered; this hides it and asks the same
     * question in a dialog, so the two can never offer different people.
     *
     * @param {HTMLFormElement} form
     * @returns {Promise<boolean>}
     */
    function askPollVoter(form) {
        var poll = /** @type {HTMLElement} */ (form.closest('.groups-poll'));
        var picker = poll ? /** @type {HTMLSelectElement} */ (poll.querySelector('.groups-poll-voter select')) : null;
        var field = /** @type {HTMLInputElement} */ (form.querySelector('.groups-poll-voter-input'));
        var modalEl = document.getElementById('groups-detail-modal');
        var modalBody = document.getElementById('groups-detail-modal-body');

        if (!picker || !field || !modalEl || !modalBody || typeof bootstrap === 'undefined') {
            return Promise.resolve(true);
        }

        var label = document.getElementById('groups-detail-modal-title');
        if (label) {
            label.textContent = 'Vous répondez pour';
        }

        modalBody.innerHTML = '';
        var list = document.createElement('div');
        list.className = 'list-group';
        Array.prototype.forEach.call(picker.options, function (option) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'list-group-item list-group-item-action d-flex align-items-center';
            button.style.minHeight = '44px';
            button.dataset.memberId = option.value;
            button.textContent = option.textContent || '';
            list.appendChild(button);
        });
        modalBody.appendChild(list);

        var modal = bootstrap.Modal.getOrCreateInstance(modalEl);

        return new Promise(function (resolve) {
            var chosen = false;

            function onPick(event) {
                var button = /** @type {HTMLElement|null} */ (
                    /** @type {HTMLElement} */ (event.target).closest('[data-member-id]')
                );
                if (!button) {
                    return;
                }
                chosen = true;
                field.value = button.dataset.memberId || '';
                picker.value = field.value;
                modal.hide();
            }

            function onHidden() {
                list.removeEventListener('click', onPick);
                modalEl.removeEventListener('hidden.bs.modal', onHidden);
                resolve(chosen);
            }

            list.addEventListener('click', onPick);
            modalEl.addEventListener('hidden.bs.modal', onHidden);
            modal.show();
        });
    }

    /**
     * "Épingler ce message ?" — the two things a moderator has to know
     * before it happens: which message stops being pinned (the group
     * keeps exactly one), and for how long this one stays up.
     *
     * Resolves to true once a duration has been written into the form's
     * own hidden field, and to false when the dialog was closed — which
     * cancels the pin rather than applying the default behind the
     * moderator's back. With no dialog available at all (no Bootstrap,
     * no modal on the page) it resolves true immediately and the form
     * posts with the default the server already rendered: the control is
     * never a dead end.
     *
     * @param {HTMLFormElement} form
     * @returns {Promise<boolean>}
     */
    function askPinDuration(form) {
        var field = /** @type {HTMLInputElement} */ (form.querySelector('input[name="duration"]'));
        var modalEl = document.getElementById('groups-detail-modal');
        var modalBody = document.getElementById('groups-detail-modal-body');

        if (!field || !modalEl || !modalBody || typeof bootstrap === 'undefined') {
            return Promise.resolve(true);
        }

        var label = document.getElementById('groups-detail-modal-title');
        if (label) {
            label.textContent = 'Épingler ce message';
        }

        modalBody.innerHTML = '';

        // Named, never quoted as markup: the label is another member's
        // own words, so it goes in as text (textContent) exactly like
        // every other body this file inserts.
        var replaced = form.dataset.pinnedLabel || '';
        if (replaced) {
            var warning = document.createElement('p');
            warning.className = 'small text-body-secondary';
            warning.textContent = 'Un seul message peut être épinglé : « ' + replaced + ' » sera désépinglé.';
            modalBody.appendChild(warning);
        }

        var question = document.createElement('p');
        question.className = 'small text-body-secondary';
        question.textContent = 'Pendant combien de temps ?';
        modalBody.appendChild(question);

        var list = document.createElement('div');
        list.className = 'list-group';
        [
            { value: 'day', text: '1 jour' },
            { value: 'week', text: '1 semaine' },
            { value: 'month', text: '1 mois' },
            { value: 'forever', text: "Jusqu'à ce qu'un modérateur le retire" }
        ].forEach(function (choice) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'list-group-item list-group-item-action d-flex align-items-center';
            button.style.minHeight = '44px';
            button.dataset.duration = choice.value;
            button.textContent = choice.text;
            list.appendChild(button);
        });
        modalBody.appendChild(list);

        var modal = bootstrap.Modal.getOrCreateInstance(modalEl);

        return new Promise(function (resolve) {
            var chosen = false;

            function onPick(event) {
                var button = /** @type {HTMLElement|null} */ (
                    /** @type {HTMLElement} */ (event.target).closest('[data-duration]')
                );
                if (!button) {
                    return;
                }
                chosen = true;
                field.value = button.dataset.duration || '';
                modal.hide();
            }

            function onHidden() {
                list.removeEventListener('click', onPick);
                modalEl.removeEventListener('hidden.bs.modal', onHidden);
                resolve(chosen);
            }

            list.addEventListener('click', onPick);
            modalEl.addEventListener('hidden.bs.modal', onHidden);
            modal.show();
        });
    }

    async function openDetailDialog(url, title, errorText) {
        var modalEl = document.getElementById('groups-detail-modal');
        var modalBody = document.getElementById('groups-detail-modal-body');
        // No URL means nothing to open — never fetch('') , which is a
        // request for the CURRENT page and would answer HTML the JSON
        // parse below then chokes on, leaving the dialog spinning.
        if (!url || !modalEl || !modalBody || typeof bootstrap === 'undefined') {
            return;
        }
        var label = document.getElementById('groups-detail-modal-title');
        if (label && title) {
            label.textContent = title;
        }
        modalBody.innerHTML = '<div class="text-center py-3"><span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span></div>';
        var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        modal.show();

        // Every failure lands on the same message rather than on an
        // unhandled rejection: a dropped connection, a non-2xx, or a body
        // that is not the JSON this expects all used to leave the dialog
        // showing its spinner for good, with nothing to close it but the
        // × in the corner.
        try {
            var response = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            if (!response.ok) {
                throw new Error('request failed');
            }
            var data = await response.json();
            if (typeof data.html !== 'string') {
                throw new Error('unexpected payload');
            }
            modalBody.innerHTML = data.html;
        } catch (e) {
            modalBody.textContent = errorText;
        }
    }

    // The member picker a member-scoped poll renders is the no-JavaScript
    // fallback: with this script running, the same question is asked in a
    // dialog at the moment of voting (askPollVoter()), so the inline
    // <select> would be a second control asking it twice. Hidden rather
    // than removed — it is where the dialog reads its options from, and
    // where the chosen value is written back.
    //
    // Each step below is its own top-level function, rather than nested
    // inline like the rest of this file's small handlers, purely to keep
    // the MutationObserver callback shallow — inlined, it nested five
    // functions deep (observer callback > mutations.forEach > a mutation's
    // addedNodes.forEach > the added-node check > its own querySelectorAll
    // .forEach), past what a reader can hold in their head at once.

    /**
     * @param {Document|HTMLElement} root
     */
    function hidePollVotersIn(root) {
        root.querySelectorAll('.groups-poll-voter').forEach(function (block) {
            block.classList.add('d-none');
        });
    }

    /**
     * @param {Node} node
     */
    function hidePollVotersInAddedNode(node) {
        if (node instanceof HTMLElement) {
            hidePollVotersIn(node);
        }
    }

    /**
     * @param {MutationRecord} mutation
     */
    function hidePollVotersInMutation(mutation) {
        mutation.addedNodes.forEach(hidePollVotersInAddedNode);
    }

    hidePollVotersIn(document);

    // The fragment the server returns after a vote carries its own picker,
    // so the same rule has to hold for it. One delegated observer rather
    // than a call at every swap site: the feed, the search page and
    // "Charger plus" all insert cards this way.
    var pollVoterObserver = new MutationObserver(function (mutations) {
        mutations.forEach(hidePollVotersInMutation);
    });
    pollVoterObserver.observe(document.body, { childList: true, subtree: true });

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
            restoreReplyDrafts();
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

        // "Réagir" on a phone: reveals the six emoji in place of itself.
        // One-way on purpose — the next thing the member does is pick one,
        // and reacting re-renders the whole block closed again anyway
        // (Controller\ReactionController answers with a fresh fragment).
        var reactionToggle = /** @type {HTMLElement} */ (target.closest('.groups-reaction-toggle'));
        if (reactionToggle) {
            reactionToggle.setAttribute('aria-expanded', 'true');
            reactionToggle.closest('.groups-reactions')?.classList.add('groups-reactions-open');
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

        // "Voir les commentaires précédents" — same in-place pagination as
        // "Charger plus" above, degrading the same way if this script
        // never runs. It needs no special casing for its direction: the
        // partial renders its button ABOVE the cards it returns
        // (partials/reply_page.html.twig), and the wrapper being replaced
        // already sits above the thread, so older comments land above the
        // ones already on screen and the next button ends up at the top.
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

    // The comment composer's photo: shown before the comment is sent, not
    // merely named.
    //
    // It used to print the filename and nothing else, which answers "did
    // the picker work?" but not "is this the right photo?" — the question
    // people actually have, and the one that otherwise costs a comment
    // sent, looked at, and deleted. The thumbnail is drawn from the File
    // itself through an object URL: nothing is uploaded until the comment
    // is, and abandoning the composer uploads nothing at all.
    //
    // One image per comment (the input has no `multiple`, and the server
    // refuses a second), so this is a single thumbnail rather than the
    // message composer's grid.
    //
    // Every element it fills is in partials/post_card.html.twig and starts
    // empty and hidden, so a browser that never runs this file keeps the
    // plain file input working exactly as before.

    /**
     * The object URL currently drawn in each composer, so it can be
     * revoked when it is replaced or cleared — an object URL keeps its
     * File alive until it is, and a member picking photo after photo
     * would otherwise hold every one of them for the life of the page.
     *
     * @type {WeakMap<HTMLElement, string>}
     */
    var replyImageUrls = new WeakMap();

    /**
     * @param {HTMLFormElement} form
     */
    function clearReplyImagePreview(form) {
        var preview = /** @type {HTMLElement} */ (form.querySelector('.groups-reply-image-preview'));
        if (!preview) {
            return;
        }

        var previous = replyImageUrls.get(preview);
        if (previous) {
            URL.revokeObjectURL(previous);
            replyImageUrls.delete(preview);
        }

        var image = /** @type {HTMLImageElement} */ (preview.querySelector('.groups-reply-image-thumb-img'));
        if (image) {
            image.removeAttribute('src');
        }
        var name = preview.querySelector('.groups-reply-image-name');
        if (name) {
            name.textContent = '';
        }
        preview.classList.add('d-none');
        preview.classList.remove('d-flex');
    }

    /**
     * @param {HTMLFormElement} form
     */
    function renderReplyImagePreview(form) {
        var input = /** @type {HTMLInputElement} */ (form.querySelector('.groups-reply-image'));
        var preview = /** @type {HTMLElement} */ (form.querySelector('.groups-reply-image-preview'));
        if (!input || !preview) {
            return;
        }

        clearReplyImagePreview(form);

        var file = input.files && input.files.length > 0 ? input.files[0] : null;
        if (!file) {
            return;
        }

        var image = /** @type {HTMLImageElement} */ (preview.querySelector('.groups-reply-image-thumb-img'));
        if (image) {
            var url = URL.createObjectURL(file);
            replyImageUrls.set(preview, url);
            image.src = url;
        }
        var name = preview.querySelector('.groups-reply-image-name');
        if (name) {
            name.textContent = file.name;
        }
        preview.classList.remove('d-none');
        preview.classList.add('d-flex');
    }

    document.addEventListener('change', function (event) {
        var input = /** @type {HTMLInputElement} */ (
            /** @type {HTMLElement} */ (event.target).closest('.groups-reply-image')
        );
        var form = /** @type {HTMLFormElement} */ (input ? input.closest('form') : null);
        if (form) {
            renderReplyImagePreview(form);
        }
    });

    // Changing your mind before sending. Emptying the input's own .files
    // is what actually detaches the photo — the preview is only what the
    // member sees, and clearing one without the other would send a photo
    // nothing on screen still showed.
    document.addEventListener('click', function (event) {
        var remove = /** @type {HTMLElement} */ (
            /** @type {HTMLElement} */ (event.target).closest('.groups-reply-image-remove')
        );
        var form = /** @type {HTMLFormElement} */ (remove ? remove.closest('form') : null);
        if (!form) {
            return;
        }

        var input = /** @type {HTMLInputElement} */ (form.querySelector('.groups-reply-image'));
        if (input) {
            input.value = '';
        }
        clearReplyImagePreview(form);
    });
})();
