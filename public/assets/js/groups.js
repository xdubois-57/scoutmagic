/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Groups module front-end: composer media picker, dynamic reactions,
// polling for a still-processing photo/video, "Charger plus"/"Voir plus
// de réponses" in-place pagination, inline edit toggles, reply image
// filename display (show.html.twig), and the invite-member search box
// (members.html.twig). Pure JS, no external library — same IIFE/var/
// fetch style as gallery.js/retro-board.js. Every block below guards on
// its own DOM elements first, so the same script loads on both pages
// (and any future one) with no effect from whichever page's markup is
// actually absent.
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
        var hiddenInput = document.getElementById('groups-media-hidden');
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
        var mediaInput = document.getElementById('groups-media-input');
        if (mediaInput) {
            mediaInput.addEventListener('change', function () {
                if (mediaInput.files) addFiles(mediaInput.files);
                mediaInput.value = '';
            });
        }
    })();

    // "Lien" reveals a plain URL input rather than fetching a preview
    // client-side (module spec: no browser-side fetch) — the server
    // resolves title/description/image synchronously on submit
    // (Service\PostLinkService), same as every other post field.
    (function initLinkToggle() {
        var linkToggle = document.getElementById('groups-link-toggle');
        var linkField = document.getElementById('groups-link-field');
        var linkInput = document.getElementById('post-link');
        if (!linkToggle || !linkField || !linkInput) {
            return;
        }

        linkToggle.addEventListener('click', function () {
            var hidden = linkField.classList.toggle('d-none');
            if (hidden) {
                linkInput.value = '';
            } else {
                linkInput.focus();
            }
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
        var input = document.getElementById('invite-member-search');
        var results = document.getElementById('invite-member-results');
        var select = document.getElementById('invite-member');
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
            if (event.target !== input && !results.contains(event.target)) {
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
        )).map(function (el) { return el.dataset.mediaId; });
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
                var cell = document.querySelector('[data-media-id="' + item.id + '"]');
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
        var form = event.target.closest('.groups-reaction-form');
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

    // "Charger plus" appends the next keyset page in place, and the inline
    // edit form toggles without leaving the feed. Both degrade to a plain
    // page reload if this script never runs: the button is a real link target
    // server-side, and every action is a normal form POST.
    document.addEventListener('click', async function (event) {
        // A reaction tally's own click: "who reacted, and with what"
        // (Controller\ReactionController's postReactors()/replyReactors()).
        // A plain <button> with no bootstrap data-* attributes of its own
        // — nothing here breaks if groups.js never loads, the tally just
        // stops being clickable and stays a plain summary.
        var tally = event.target.closest('.groups-reaction-tally');
        if (tally) {
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
            return;
        }

        var loadMore = event.target.closest('.groups-load-more');
        if (loadMore) {
            loadMore.disabled = true;
            var response = await fetch(loadMore.dataset.url, { headers: { 'X-Requested-With': 'fetch' } });
            if (response.ok) {
                var wrapper = loadMore.closest('.groups-load-more-wrapper');
                wrapper.insertAdjacentHTML('beforebegin', await response.text());
                wrapper.remove();
                kickOffMediaPolling();
            } else {
                loadMore.disabled = false;
            }
            return;
        }

        var editToggle = event.target.closest('.groups-edit-toggle');
        if (editToggle) {
            document.getElementById('post-edit-' + editToggle.dataset.post)?.classList.remove('d-none');
            document.getElementById('post-body-' + editToggle.dataset.post)?.classList.add('d-none');
            return;
        }

        var editCancel = event.target.closest('.groups-edit-cancel');
        if (editCancel) {
            document.getElementById('post-edit-' + editCancel.dataset.post)?.classList.add('d-none');
            document.getElementById('post-body-' + editCancel.dataset.post)?.classList.remove('d-none');
            return;
        }

        // "Voir plus de réponses" — appends the next page of replies in place,
        // exactly like "Charger plus" does for the feed itself. Degrades to
        // nothing if this script never runs: the replies already rendered
        // server-side stay visible, and every action below them is a plain
        // form POST.
        var repliesMore = event.target.closest('.groups-replies-more');
        if (repliesMore) {
            repliesMore.disabled = true;
            var repliesResponse = await fetch(repliesMore.dataset.url, { headers: { 'X-Requested-With': 'fetch' } });
            if (repliesResponse.ok) {
                var repliesWrapper = repliesMore.closest('.groups-replies-more-wrapper');
                repliesWrapper.insertAdjacentHTML('beforebegin', await repliesResponse.text());
                repliesWrapper.remove();
                kickOffMediaPolling();
            } else {
                repliesMore.disabled = false;
            }
            return;
        }

        var replyEditToggle = event.target.closest('.groups-reply-edit-toggle');
        if (replyEditToggle) {
            document.getElementById('reply-edit-' + replyEditToggle.dataset.reply)?.classList.remove('d-none');
            document.getElementById('reply-body-' + replyEditToggle.dataset.reply)?.classList.add('d-none');
            return;
        }

        var replyEditCancel = event.target.closest('.groups-reply-edit-cancel');
        if (replyEditCancel) {
            document.getElementById('reply-edit-' + replyEditCancel.dataset.reply)?.classList.add('d-none');
            document.getElementById('reply-body-' + replyEditCancel.dataset.reply)?.classList.remove('d-none');
        }
    });

    // The reply composer's image picker is a bare <input type="file"> with no
    // preview grid (one image, not four) — this only surfaces the chosen
    // filename so the member can tell something is attached before sending.
    document.addEventListener('change', function (event) {
        var input = event.target.closest('.groups-reply-image');
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
