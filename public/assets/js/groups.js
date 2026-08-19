/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Groups module front-end (show.html.twig): composer media picker,
// "Charger plus"/"Voir plus de réponses" in-place pagination, inline
// edit toggles, reply image filename display. Pure JS, no external
// library — same IIFE/var/fetch style as gallery.js/retro-board.js.
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

    // "Charger plus" appends the next keyset page in place, and the inline
    // edit form toggles without leaving the feed. Both degrade to a plain
    // page reload if this script never runs: the button is a real link target
    // server-side, and every action is a normal form POST.
    document.addEventListener('click', async function (event) {
        var loadMore = event.target.closest('.groups-load-more');
        if (loadMore) {
            loadMore.disabled = true;
            var response = await fetch(loadMore.dataset.url, { headers: { 'X-Requested-With': 'fetch' } });
            if (response.ok) {
                var wrapper = loadMore.closest('.groups-load-more-wrapper');
                wrapper.insertAdjacentHTML('beforebegin', await response.text());
                wrapper.remove();
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
