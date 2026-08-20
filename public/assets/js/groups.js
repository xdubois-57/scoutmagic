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
    // Composer media picker: the visible <input type="file"> is a UI-only
    // source of File objects — never submitted itself. Selected files
    // accumulate in selectedFiles[], capped client-side at
    // maxMedia (a convenience: the server enforces the real ceiling and
    // rejects the whole post over it, never truncating —
    // Service\PostMediaService::MAX_MEDIA_PER_POST). Before submit,
    // they're copied onto the one hidden <input name="media[]"> that
    // actually gets posted, via the DataTransfer API (the only way to
    // programmatically set an <input>'s .files).
    (function initMediaPicker() {
        var form = document.getElementById('groups-post-form');
        var previews = document.getElementById('groups-media-previews');
        var hiddenInput = /** @type {HTMLInputElement} */ (document.getElementById('groups-media-hidden'));
        var countLabel = document.getElementById('groups-media-count');
        var limitWarning = document.getElementById('groups-media-limit-warning');
        if (!form || !previews || !hiddenInput) {
            return;
        }

        var maxMedia = parseInt(form.dataset.maxMedia, 10) || 4;
        var selectedFiles = [];

        function render() {
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
                    sync();
                });
                cell.appendChild(remove);

                previews.appendChild(cell);
            });
        }

        function sync() {
            var dataTransfer = new DataTransfer();
            selectedFiles.forEach(function (file) { dataTransfer.items.add(file); });
            hiddenInput.files = dataTransfer.files;
            if (countLabel) countLabel.textContent = selectedFiles.length + ' / ' + maxMedia + ' médias';
            render();
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
            sync();
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
    })();

    // No manual "Lien" field: the first URL typed anywhere in the message
    // is detected automatically and previewed live, exactly as it will
    // look once the post is saved (Controller\PostController::
    // linkPreview(), Service\PostLinkService::livePreview() — the same
    // preview a real submit would produce, just not written anywhere
    // yet). Debounced on typing (never per keystroke — the fetch is
    // throttled server-side, Service\LinkFetchThrottleService, shared
    // with the post's own final fetch on submit) and fired immediately on
    // blur/paste, so leaving the field or pasting a link shows the card
    // without waiting out the debounce.
    (function initLinkPreview() {
        var textarea = /** @type {HTMLTextAreaElement} */ (document.getElementById('post-body'));
        var container = document.getElementById('groups-link-preview');
        if (!textarea || !container) {
            return;
        }

        var previewUrl = container.dataset.previewUrl;
        var debounceTimer = null;
        var lastRequestedBody = null;
        var requestToken = 0;

        function csrfToken() {
            var meta = /** @type {HTMLMetaElement} */ (document.querySelector('meta[name="csrf-token"]'));
            return meta ? meta.content : '';
        }

        function hide() {
            container.classList.add('d-none');
            container.innerHTML = '';
        }

        function showSpinner() {
            container.innerHTML = '';
            var spinner = document.createElement('span');
            spinner.className = 'spinner-border spinner-border-sm text-body-secondary';
            spinner.setAttribute('role', 'status');
            spinner.setAttribute('aria-hidden', 'true');
            container.appendChild(spinner);
            container.classList.remove('d-none');
        }

        // Built with textContent throughout, never innerHTML string
        // concatenation: title/description come straight from a REMOTE
        // page's own og:title/og:description, which this member did not
        // write and this site does not control — the same untrusted-input
        // rule as anywhere else a value crosses a trust boundary.
        function renderCard(data) {
            container.innerHTML = '';
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
            container.appendChild(card);
            container.classList.remove('d-none');
        }

        function fetchPreview() {
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
            var token = ++requestToken;

            // Cheap guard before spending a network round trip (and,
            // server-side, a throttle slot only actually gets consumed
            // once a URL is genuinely found — but there is no reason to
            // ask at all for a message with no "http" in it whatsoever).
            if (body.indexOf('http://') === -1 && body.indexOf('https://') === -1) {
                hide();
                return;
            }

            showSpinner();

            fetch(previewUrl, {
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
                if (token !== requestToken) {
                    return;
                }
                if (data.url) {
                    renderCard(data);
                } else {
                    hide();
                }
            }).catch(function () {
                if (token === requestToken) {
                    hide();
                }
            });
        }

        textarea.addEventListener('input', function () {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(fetchPreview, 800);
        });
        textarea.addEventListener('blur', function () {
            clearTimeout(debounceTimer);
            fetchPreview();
        });
        textarea.addEventListener('paste', function () {
            // The pasted text is not yet in .value while this handler
            // runs (paste is fired before the browser inserts it) — defer
            // to the next tick so fetchPreview() reads the field AFTER
            // the paste has actually landed.
            clearTimeout(debounceTimer);
            setTimeout(fetchPreview, 0);
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

    // A reaction button's form still posts and redirects with no JS at
    // all (partials/reactions.html.twig's own docblock promise) — this
    // only upgrades that same POST to a fetch() so the page never
    // reloads. The `X-Requested-With` header is what tells
    // Controller\ReactionController to answer with the freshly rendered
    // fragment (JSON: {outcome, html}) instead of its usual redirect;
    // any failure — network, non-2xx, a malformed body — falls through
    // to the plain form submit, so a stale CSRF token or a dropped
    // connection degrades to a page reload rather than doing nothing.
    document.addEventListener('submit', function (event) {
        var target = /** @type {HTMLElement} */ (event.target);
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

    // A reaction tally's own click: "who reacted, and with what"
    // (Controller\ReactionController's postReactors()/replyReactors()).
    // A plain <button> with no bootstrap data-* attributes of its own
    // — nothing here breaks if groups.js never loads, the tally just
    // stops being clickable and stays a plain summary.
    async function handleReactionTallyClick(tally) {
        var modalEl = document.getElementById('groups-reactors-modal');
        var modalBody = document.getElementById('groups-reactors-modal-body');
        if (!modalEl || !modalBody || typeof bootstrap === 'undefined') {
            return;
        }
        modalBody.innerHTML = '<div class="text-center py-3"><span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span></div>';
        var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        modal.show();

        var reactorsResponse = await fetch(tally.dataset.reactorsUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        if (reactorsResponse.ok) {
            var reactorsData = await reactorsResponse.json();
            if (typeof reactorsData.html === 'string') {
                modalBody.innerHTML = reactorsData.html;
            }
        } else {
            modalBody.innerHTML = '<p class="text-danger mb-0">Impossible de charger les réactions.</p>';
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

        var tally = /** @type {HTMLElement} */ (target.closest('.groups-reaction-tally'));
        if (tally) {
            await handleReactionTallyClick(tally);
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
