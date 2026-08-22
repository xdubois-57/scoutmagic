/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Retro module public board (retro/board.html.twig): polling, comment
// submission with AI-shorten, vote controls, hide/unhide, QR toggle, copy
// link. Pure JS, no external library — same IIFE/var/fetch style as
// gallery.js.
(function () {
    var container = document.getElementById('retro-board');
    if (!container) return;

    var token = container.dataset.token;
    var pollInterval = Math.max(3, parseInt(container.dataset.pollInterval, 10) || 8) * 1000;
    var status = container.dataset.status;
    var canVote = container.dataset.canVote === '1';
    var voteMode = container.dataset.voteMode;
    var isUnitChief = container.dataset.isUnitChief === '1';
    var maxLength = parseInt(container.dataset.maxLength, 10) || 140;
    var aiAvailable = container.dataset.aiAvailable === '1';
    var remainingBudget = container.dataset.remainingBudget !== '' ? parseInt(container.dataset.remainingBudget, 10) : null;
    var budgetEl = document.getElementById('retro-budget-remaining');

    function csrf() {
        var meta = /** @type {HTMLMetaElement} */ (document.querySelector('meta[name="csrf-token"]'));
        return meta ? meta.content : '';
    }

    /**
     * @param {string} url
     * @param {Object} body
     * @returns {Promise<Object>}
     */
    function postJson(url, body) {
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(Object.assign({}, body, { _csrf_token: csrf() }))
        }).then(function (res) {
            return res.json().catch(function () { return {}; }).then(function (data) {
                return { httpStatus: res.status, data: data };
            });
        });
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.textContent = str === null || str === undefined ? '' : String(str);
        return div.innerHTML;
    }

    function updateBudgetDisplay(value) {
        remainingBudget = value;
        if (budgetEl) budgetEl.textContent = value;
    }

    // ------------------------------------------------------------------
    // Comment card rendering — mirrors retro/_comment.html.twig exactly,
    // since both the initial page load (server-rendered) and every
    // subsequent poll/vote/comment response (client-rendered) must look
    // identical. A poll response only ever carries {id, column, body,
    // votes, hidden} — vote-mode/is-unit-chief/can-vote/board-status come from
    // this container's own data-* attributes, not from the comment itself.
    // ------------------------------------------------------------------
    // Extracted out of commentHtml() (rather than inline `if`/ternary blocks
    // there) purely to keep its own cognitive complexity down — same
    // generated markup either way.
    function buildVoteButtonsHtml(comment) {
        if (!(status === 'open' && canVote)) {
            return '';
        }
        if (voteMode === 'unlimited') {
            var likeClass = comment.youVoted ? 'btn-danger' : 'btn-outline-danger';
            var heartIcon = comment.youVoted ? 'heart-fill' : 'heart';
            return '<button type="button" class="btn btn-sm py-0 px-2 retro-vote-like ' + likeClass + '" data-comment-id="' + comment.id + '" title="J’aime"><i class="bi bi-' + heartIcon + '"></i></button>';
        }
        return '<button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 retro-vote-remove" data-comment-id="' + comment.id + '" title="Retirer un point">−</button>'
            + '<button type="button" class="btn btn-sm btn-outline-success py-0 px-2 retro-vote-add" data-comment-id="' + comment.id + '" title="Ajouter un point">+</button>';
    }

    // Same reasoning as buildVoteButtonsHtml() above.
    function buildHideButtonHtml(comment) {
        if (!isUnitChief) {
            return '';
        }
        var hideClass = comment.hidden ? 'btn-outline-success retro-unhide' : 'btn-outline-warning retro-hide';
        var hideIcon = comment.hidden ? 'eye' : 'eye-slash';
        var hideLabel = comment.hidden ? 'Réafficher' : 'Masquer';
        return '<button type="button" class="btn btn-sm ' + hideClass + '" data-comment-id="' + comment.id + '"><i class="bi bi-' + hideIcon + '"></i> ' + hideLabel + '</button>';
    }

    function commentHtml(comment) {
        if (comment.body === null) {
            return '<div class="border rounded p-2 retro-comment" data-comment-id="' + comment.id + '" data-hidden="1">'
                + '<p class="mb-0 small fst-italic text-body-secondary" data-comment-body>Mot masqué.</p></div>';
        }

        var hiddenBadge = comment.hidden ? '<span class="badge text-bg-warning mb-1">Masqué — visible par le chef d\'unité uniquement</span>' : '';
        var bodyClass = comment.hidden ? 'mb-1 small text-decoration-line-through text-body-secondary' : 'mb-1 small';
        var votesLabel = comment.votes === null || comment.votes === undefined ? '—' : comment.votes;
        var voteButtons = buildVoteButtonsHtml(comment);
        var hideButton = buildHideButtonHtml(comment);

        return '<div class="border rounded p-2 retro-comment" data-comment-id="' + comment.id + '" data-hidden="' + (comment.hidden ? '1' : '0') + '">'
            + hiddenBadge
            + '<p class="' + bodyClass + '" data-comment-body>' + escapeHtml(comment.body) + '</p>'
            + '<div class="d-flex justify-content-between align-items-center gap-2">'
            + '<div class="d-flex align-items-center gap-1">' + voteButtons + '<span class="small text-body-secondary" data-comment-votes>' + votesLabel + '</span></div>'
            + hideButton
            + '</div></div>';
    }

    function renderColumns(comments) {
        var byColumn = { good: [], improve: [], suggestion: [] };
        comments.forEach(function (c) {
            if (byColumn[c.column]) byColumn[c.column].push(c);
        });

        Object.keys(byColumn).forEach(function (col) {
            var list = container.querySelector('[data-comment-list="' + col + '"]');
            var badge = container.querySelector('[data-count-for="' + col + '"]');
            if (badge) badge.textContent = byColumn[col].length;
            if (!list) return;

            list.innerHTML = byColumn[col].length === 0
                ? '<p class="text-body-secondary small fst-italic mb-0">Aucun mot pour l’instant.</p>'
                : byColumn[col].map(commentHtml).join('');
        });
    }

    // ------------------------------------------------------------------
    // Polling
    // ------------------------------------------------------------------
    var pollTimer = null;
    var pollInFlight = false;

    function poll() {
        if (pollInFlight) return;
        pollInFlight = true;
        fetch('/r/' + token + '/poll')
            .then(function (res) { return res.json(); })
            .then(function (data) {
                pollInFlight = false;
                if (data.error) return;
                renderColumns(data.comments || []);
            })
            .catch(function () { pollInFlight = false; });
    }

    if (status === 'open') {
        pollTimer = setInterval(poll, pollInterval);
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible') poll();
        });
    }

    // ------------------------------------------------------------------
    // Comment submission (one form per column) + AI-shorten
    // ------------------------------------------------------------------
    container.querySelectorAll('.retro-comment-form').forEach(
        /** @param {HTMLElement} form */
        function (form) {
        var column = form.dataset.column;
        var input = /** @type {HTMLTextAreaElement} */ (form.querySelector('[data-draft-input]'));
        var counter = form.querySelector('[data-draft-counter]');
        var errorEl = form.querySelector('[data-draft-error]');
        var shortenBtn = /** @type {HTMLButtonElement} */ (form.querySelector('[data-shorten-btn]'));
        var submitBtn = /** @type {HTMLButtonElement} */ (form.querySelector('button[type="submit"]'));
        var moderationEl = form.querySelector('[data-moderation-alert]');

        function updateCounter() {
            var len = input.value.length;
            counter.textContent = len + '/' + maxLength;
            counter.classList.toggle('text-danger', len > maxLength);
            if (shortenBtn) shortenBtn.classList.toggle('d-none', !(aiAvailable && len > maxLength));
        }

        function showError(message) {
            if (!errorEl) return;
            if (message) {
                errorEl.textContent = message;
                errorEl.classList.remove('d-none');
            } else {
                errorEl.textContent = '';
                errorEl.classList.add('d-none');
            }
        }

        function hideModeration() {
            if (!moderationEl) return;
            moderationEl.innerHTML = '';
            moderationEl.classList.add('d-none');
        }

        // moderationMode: 'warning' offers "publish anyway"; 'enforced' never does.
        /**
         * @param {string} reason
         * @param {string} suggestion
         * @param {string} moderationMode
         */
        function showModeration(reason, suggestion, moderationMode) {
            if (!moderationEl) return;

            var html = '<div class="alert alert-warning small mb-0">'
                + '<p class="mb-2">' + escapeHtml(reason || 'Ce message peut être perçu comme irrespectueux.') + '</p>';

            if (suggestion) {
                html += '<p class="mb-2 fst-italic">Suggestion : « ' + escapeHtml(suggestion) + ' »</p>'
                    + '<button type="button" class="btn btn-sm btn-outline-primary me-2" data-moderation-accept>'
                    + '<i class="bi bi-magic"></i> Utiliser la suggestion</button>';
            } else if (moderationMode === 'enforced') {
                html += '<p class="mb-0">Merci de reformuler ton message avant de l\'envoyer.</p>';
            }

            if (moderationMode === 'warning') {
                html += '<button type="button" class="btn btn-sm btn-outline-secondary" data-moderation-proceed>'
                    + 'Publier quand même</button>';
            }

            html += '</div>';
            moderationEl.innerHTML = html;
            moderationEl.classList.remove('d-none');

            var acceptBtn = moderationEl.querySelector('[data-moderation-accept]');
            if (acceptBtn) {
                acceptBtn.addEventListener('click', function () {
                    input.value = suggestion;
                    updateCounter();
                    hideModeration();
                    submitComment(false);
                });
            }
            var proceedBtn = moderationEl.querySelector('[data-moderation-proceed]');
            if (proceedBtn) {
                proceedBtn.addEventListener('click', function () {
                    hideModeration();
                    submitComment(true);
                });
            }
        }

        if (input) {
            input.addEventListener('input', function () { updateCounter(); showError(''); hideModeration(); });
            updateCounter();
        }

        if (shortenBtn) {
            shortenBtn.addEventListener('click', function () {
                shortenBtn.disabled = true;
                postJson('/r/' + token + '/shorten', { body: input.value }).then(function (result) {
                    shortenBtn.disabled = false;
                    if (result.data.success) {
                        input.value = result.data.body;
                        updateCounter();
                    } else {
                        showError(result.data.error || 'Échec du raccourcissement.');
                    }
                });
            });
        }

        function submitComment(acceptedWarning) {
            var body = input.value.trim();
            if (body === '') {
                showError('Le mot ne peut pas être vide.');
                return;
            }
            if (body.length > maxLength) {
                showError('Message trop long de ' + (body.length - maxLength) + ' caractère(s).');
                return;
            }

            submitBtn.disabled = true;
            postJson('/r/' + token + '/comments', { column: column, body: body, accepted_warning: !!acceptedWarning }).then(function (result) {
                submitBtn.disabled = false;
                if (result.data.success) {
                    input.value = '';
                    updateCounter();
                    showError('');
                    hideModeration();
                    poll();
                } else if (result.data.type === 'offensive') {
                    showError('');
                    showModeration(result.data.error, result.data.suggestion, result.data.moderation_mode);
                } else {
                    showError(result.data.error || 'Échec de l’envoi.');
                }
            });
        }

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            submitComment(false);
        });
    });

    // ------------------------------------------------------------------
    // Vote / hide / unhide — event delegation, since comment cards are
    // re-rendered wholesale on every poll.
    // ------------------------------------------------------------------
    container.addEventListener('click', function (e) {
        var target = /** @type {HTMLElement} */ (e.target);
        var likeBtn = /** @type {HTMLButtonElement} */ (target.closest('.retro-vote-like'));
        var addBtn = /** @type {HTMLButtonElement} */ (target.closest('.retro-vote-add'));
        var removeBtn = /** @type {HTMLButtonElement} */ (target.closest('.retro-vote-remove'));
        var hideBtn = /** @type {HTMLButtonElement} */ (target.closest('.retro-hide'));
        var unhideBtn = /** @type {HTMLButtonElement} */ (target.closest('.retro-unhide'));

        if (likeBtn) {
            var id1 = likeBtn.dataset.commentId;
            likeBtn.disabled = true;
            postJson('/r/' + token + '/comments/' + id1 + '/vote', {}).then(function (result) {
                likeBtn.disabled = false;
                if (result.data.success) {
                    likeBtn.classList.toggle('btn-danger', result.data.liked);
                    likeBtn.classList.toggle('btn-outline-danger', !result.data.liked);
                    var heartEl = likeBtn.querySelector('i');
                    if (heartEl) heartEl.className = result.data.liked ? 'bi bi-heart-fill' : 'bi bi-heart';
                    var votesEl = likeBtn.closest('.retro-comment').querySelector('[data-comment-votes]');
                    if (votesEl && result.data.votes !== null && result.data.votes !== undefined) votesEl.textContent = result.data.votes;
                } else if (result.data.error) {
                    alert(result.data.error);
                }
            });
            return;
        }

        if (addBtn || removeBtn) {
            var btn = addBtn || removeBtn;
            var id2 = btn.dataset.commentId;
            var action = addBtn ? 'add' : 'remove';
            btn.disabled = true;
            postJson('/r/' + token + '/comments/' + id2 + '/vote/' + action, {}).then(function (result) {
                btn.disabled = false;
                if (result.data.success) {
                    updateBudgetDisplay(result.data.remaining);
                    var votesEl2 = btn.closest('.retro-comment').querySelector('[data-comment-votes]');
                    if (votesEl2 && result.data.votes !== null && result.data.votes !== undefined) votesEl2.textContent = result.data.votes;
                } else if (result.data.error) {
                    alert(result.data.error);
                }
            });
            return;
        }

        if (hideBtn || unhideBtn) {
            var btn2 = hideBtn || unhideBtn;
            var id3 = btn2.dataset.commentId;
            var url = '/r/' + token + '/comments/' + id3 + '/' + (hideBtn ? 'hide' : 'unhide');
            btn2.disabled = true;
            postJson(url, {}).then(function (result) {
                btn2.disabled = false;
                if (result.data.success) {
                    poll();
                } else if (result.data.error) {
                    alert(result.data.error);
                }
            });
        }
    });

    // ------------------------------------------------------------------
    // QR toggle + copy link
    // ------------------------------------------------------------------
    var qrToggle = document.getElementById('retro-qr-toggle');
    var qrContainer = document.getElementById('retro-qr-container');
    if (qrToggle && qrContainer) {
        qrToggle.addEventListener('click', function () {
            qrContainer.classList.toggle('d-none');
        });
    }

    var copyBtn = document.getElementById('retro-copy-link');
    if (copyBtn) {
        copyBtn.addEventListener('click', function () {
            var url = copyBtn.dataset.url;
            var restoreLabel = copyBtn.innerHTML;
            var restore = function () { copyBtn.innerHTML = restoreLabel; };

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(url).then(function () {
                    copyBtn.innerHTML = '<i class="bi bi-check-lg"></i> Copié';
                    setTimeout(restore, 2000);
                });
            } else {
                var input = document.createElement('input');
                input.value = url;
                document.body.appendChild(input);
                input.select();
                document.execCommand('copy');
                document.body.removeChild(input);
                copyBtn.innerHTML = '<i class="bi bi-check-lg"></i> Copié';
                setTimeout(restore, 2000);
            }
        });
    }

    // --- Test seam (no behavioural effect) ---------------------------------
    // Everything above lives inside this IIFE and is therefore module-private
    // — unlike public/sw.js,
    // whose own globalThis lines are true no-ops. This adds ONE namespaced
    // global so tests/js/retro-board.test.js can reach the HTML-assembly
    // functions directly and exercise the real implementation, rather than
    // reimplementing their logic in a test-only copy — same precedent as
    // window.ChipPicker, window.ScoutMagicNav and
    // news-form-builder.js's own ScoutMagicNewsFormBuilderInternals.
    //
    // Test-only: nothing in production reads this. voteMode/isUnitChief/
    // canVote/status are captured once from container.dataset above and
    // have no setter, so a test that needs a different combination
    // re-imports this module (vi.resetModules()) against a freshly built
    // container instead of mutating these through the seam.
    globalThis.ScoutMagicRetroBoardInternals = {
        escapeHtml: escapeHtml,
        commentHtml: commentHtml,
        buildVoteButtonsHtml: buildVoteButtonsHtml,
        buildHideButtonHtml: buildHideButtonHtml,
        renderColumns: renderColumns,
        updateBudgetDisplay: updateBudgetDisplay,
    };
})();
