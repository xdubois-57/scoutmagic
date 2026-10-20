/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// The two pages of the mailing lists (modules/mass_mail/views/):
//
// - mailing_lists.html.twig, the page of the lists: activating /
//   deactivating and deleting a custom list, and « Nouvelle liste »;
// - mailing_list.html.twig, the page of ONE list: its total, its blocks
//   by kind, the choice of the kind of block to add, the member-group
//   dialog, the removal of a block and « Voir les destinataires ».
//
// The name-and-description dialog is on both (« Nouvelle liste » on the
// first, the pencil on the second).
//
// The contact-group dialog is NOT here: it belongs to
// mass-mail-contact-groups.js, and the two scripts meet through a
// contract of two hooks (CHANTIER-listes-de-diffusion-blocs.md §3). This
// page renders `[data-contact-group-open]` — without `data-block-id` for
// a creation, with it on each contact-group row — and the other script
// opens its dialog from them; once its dialog saved, it fires
// `mass-mail:blocks-changed` on `document`, and this page redraws its
// blocks and its total from the server. Nothing here assumes that dialog
// is on the page: its rows are drawn either way.
//
// Same corrections as config-badges.js and finance-categories.js:
// ScoutMagicApi.postJson carries the CSRF token, HTTP success and
// business success are two separate checks — a 500 error page is not
// JSON, so it surfaces as a failure instead of throwing — and failures
// are toasts, or a line inside the dialog whose fields need correcting.
// Every confirmation is ScoutMagicConfirm (design.md §7.5) and says
// whether an e-mail leaves.
(function () {
    var api = window.ScoutMagicApi;

    var BASE = '/admin/listes-de-diffusion';
    var FUNCTION_PICKER = 'mm-function-picker';
    var SECTION_PICKER = 'mm-section-picker';
    var BADGE_PICKER = 'mm-badge-picker';
    var KIND_MEMBER_GROUP = 'member_group';

    // --- Shared helpers ---

    /**
     * The failure line for one envelope: the server's own message when it
     * answered JSON, the generic « Erreur. » when it answered JSON without
     * one, and a distinct line when it did not answer JSON at all.
     *
     * @param {{ok: boolean, status: number, data: any}} res
     * @returns {string}
     */
    function errorMessage(res) {
        if (res.data) {
            return res.data.error || 'Erreur.';
        }
        return 'Erreur : réponse serveur invalide.';
    }

    /**
     * Business success — never `res.ok`, which only says the server
     * answered properly.
     *
     * @param {{ok: boolean, status: number, data: any}} res
     * @returns {boolean}
     */
    function isSuccess(res) {
        return !!(res.data?.success);
    }

    /** @param {{ok: boolean, status: number, data: any}} res */
    function toastError(res) {
        window.ScoutMagicToast.show(errorMessage(res), { variant: 'error' });
    }

    /**
     * « 1 membre », « 2 membres » — and « 0 membre », as French writes it.
     *
     * @param {number} count
     * @param {string} singular
     * @param {string} plural
     * @returns {string}
     */
    function counted(count, singular, plural) {
        return count + ' ' + (count > 1 ? plural : singular);
    }

    /**
     * « 37 contacts, dont 2 désinscrits »
     *
     * @param {number} total
     * @param {number} unsubscribed
     * @returns {string}
     */
    function contactsLine(total, unsubscribed) {
        return counted(total, 'contact', 'contacts')
            + (unsubscribed > 0 ? ', dont ' + counted(unsubscribed, 'désinscrit', 'désinscrits') : '');
    }

    /**
     * Lower case, without accents — « Hélène » is found by « helene ».
     *
     * @param {string} value
     * @returns {string}
     */
    function fold(value) {
        return (value || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
    }

    /**
     * @param {string} tag
     * @param {string} [className]
     * @param {string} [text]
     * @returns {HTMLElement}
     */
    function node(tag, className, text) {
        var el = document.createElement(tag);
        if (className) {
            el.className = className;
        }
        if (text !== undefined) {
            el.textContent = text;
        }
        return el;
    }

    /**
     * A sentence the server worded as segments, the names in bold. Each
     * segment goes in as text: a label typed into Desk is never parsed as
     * markup.
     *
     * @param {HTMLElement} target
     * @param {Array<{text: string, strong: boolean}>} segments
     */
    function renderSentence(target, segments) {
        target.textContent = '';
        (segments || []).forEach(function (segment) {
            target.appendChild(segment.strong
                ? node('b', '', segment.text)
                : document.createTextNode(segment.text));
        });
    }

    /**
     * @param {Array<{text: string, strong: boolean}>} segments
     * @returns {string}
     */
    function sentenceText(segments) {
        return (segments || []).map(function (segment) { return segment.text; }).join('');
    }

    /** @param {HTMLElement} el */
    function showModal(el) {
        if (window.bootstrap) {
            window.bootstrap.Modal.getOrCreateInstance(el).show();
        } else {
            el.classList.add('show');
        }
    }

    /**
     * Hides a dialog and runs `then` once it is gone — so a dialog opened
     * from another never stacks on top of the one still closing.
     *
     * @param {HTMLElement} el
     * @param {() => void} [then]
     */
    function hideModal(el, then) {
        if (window.bootstrap) {
            if (then) {
                el.addEventListener('hidden.bs.modal', then, { once: true });
            }
            window.bootstrap.Modal.getOrCreateInstance(el).hide();
            return;
        }
        el.classList.remove('show');
        if (then) {
            then();
        }
    }

    // --- The name and the description (both pages) ---

    /** @param {HTMLElement} modalEl */
    function wireIdentityDialog(modalEl) {
        var nameInput = /** @type {HTMLInputElement} */ (document.getElementById('mm-list-name'));
        var descriptionInput = /** @type {HTMLTextAreaElement} */ (document.getElementById('mm-list-description'));
        var errorBox = /** @type {HTMLElement} */ (modalEl.querySelector('[data-list-identity-error]'));
        var saveBtn = /** @type {HTMLButtonElement} */ (modalEl.querySelector('[data-list-identity-save]'));
        var listId = saveBtn.dataset.listId || '';
        // What the fields held when the page was drawn: « Annuler » then
        // reopening must not keep a half-typed rename.
        var savedName = nameInput.value;
        var savedDescription = descriptionInput.value;

        document.querySelectorAll('[data-list-identity-open]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                errorBox.classList.add('d-none');
                nameInput.value = savedName;
                descriptionInput.value = savedDescription;
                showModal(modalEl);
            });
        });

        saveBtn.addEventListener('click', async function () {
            // One request at a time: a double click would create the list
            // twice. The button comes back only when the server refuses —
            // on success the page is left or reloaded.
            if (saveBtn.disabled) {
                return;
            }
            saveBtn.disabled = true;
            var payload = { name: nameInput.value, description: descriptionInput.value };
            var res = listId === ''
                ? await api.postJson(BASE + '/lists', payload)
                : await api.postJson(BASE + '/lists/' + listId, payload, { method: 'PATCH' });
            if (!isSuccess(res)) {
                // An error about the form's own fields belongs inside the
                // form, next to what has to be corrected.
                saveBtn.disabled = false;
                errorBox.textContent = errorMessage(res);
                errorBox.classList.remove('d-none');
                return;
            }
            if (listId === '') {
                // A new list is empty: its page is where it gets its blocks.
                window.location.href = BASE + '/' + res.data.list.id;
                return;
            }
            window.location.reload();
        });
    }

    // --- The page of the lists ---

    function wireListsPage() {
        /** @type {NodeListOf<HTMLElement>} */
        (document.querySelectorAll('.mm-toggle-list-btn')).forEach(function (btn) {
            btn.addEventListener('click', async function () {
                // Deactivating hides the list from the composer; it destroys
                // nothing and is undone by the same button — no confirmation.
                var active = btn.dataset.active === '1';
                var res = await api.postJson(BASE + '/lists/' + btn.dataset.id + '/toggle', { active: !active });
                if (!isSuccess(res)) {
                    toastError(res);
                    return;
                }
                window.location.reload();
            });
        });

        /** @type {NodeListOf<HTMLElement>} */
        (document.querySelectorAll('.mm-delete-list-btn')).forEach(function (btn) {
            btn.addEventListener('click', async function () {
                var confirmed = await window.ScoutMagicConfirm.ask({
                    title: 'Supprimer cette liste ?',
                    message: '« ' + (btn.dataset.name || '') + ' » est supprimée avec tous ses groupes. '
                        + 'Aucun e-mail n\'est envoyé.',
                    confirmLabel: 'Supprimer'
                });
                if (!confirmed) {
                    return;
                }
                var res = await api.postJson(BASE + '/lists/' + btn.dataset.id, {}, { method: 'DELETE' });
                if (!isSuccess(res)) {
                    toastError(res);
                    return;
                }
                window.location.reload();
            });
        });
    }

    // --- The page of one list ---

    /**
     * @typedef {{id: number, kind: string, name: ?string,
     *            sentence: Array<{text: string, strong: boolean}>, count: number,
     *            unsubscribed: number, file_replaced_at: ?string, file_replaced_label: ?string,
     *            function_ids: number[], section_ids: number[], badge_ids: number[]}} ListBlockData
     * @typedef {{scout_year_label: string,
     *            summary: {total: number, members: number, contacts: number, contacts_unsubscribed: number},
     *            blocks: ListBlockData[]}} BlocksPayload
     */

    /**
     * @param {{list_id: number, effective_year_id: number, blocks: BlocksPayload}} data
     */
    function wireListPage(data) {
        var listId = data.list_id;
        /** @type {BlocksPayload} */
        var state = data.blocks;

        var totalEl = /** @type {HTMLElement} */ (document.querySelector('[data-list-total]'));
        var blocksEl = /** @type {HTMLElement} */ (document.querySelector('[data-list-blocks]'));
        var emptyTextEl = /** @type {HTMLElement} */ (document.querySelector('[data-list-empty-text]'));
        var yearNoteEl = /** @type {HTMLElement} */ (document.querySelector('[data-list-year-note]'));

        var chooserEl = /** @type {HTMLElement} */ (document.getElementById('mm-kind-chooser-modal'));
        var memberModalEl = /** @type {HTMLElement} */ (document.getElementById('mm-member-group-modal'));
        var recipientsModalEl = /** @type {HTMLElement} */ (document.getElementById('mm-recipients-modal'));

        // -- Drawing --

        /** @returns {ListBlockData[]} */
        function memberGroups() {
            return state.blocks.filter(function (b) { return b.kind === KIND_MEMBER_GROUP; });
        }

        /** @returns {ListBlockData[]} */
        function contactGroups() {
            return state.blocks.filter(function (b) { return b.kind !== KIND_MEMBER_GROUP; });
        }

        function renderTotal() {
            var year = state.scout_year_label;
            var summary = state.summary;
            totalEl.textContent = '';

            if (state.blocks.length === 0) {
                totalEl.appendChild(node('p', 'h5 mb-1', '0 destinataire'));
                totalEl.appendChild(node('p', 'small text-body-secondary mb-0',
                    'Personne ne reçoit encore les e-mails de cette liste.'));
                return;
            }

            totalEl.appendChild(node('p', 'small text-body-secondary mb-1', 'Reçoivent les e-mails de cette liste'));
            totalEl.appendChild(node('p', 'h5 mb-1',
                counted(summary.total, 'destinataire', 'destinataires') + ' en ' + year));

            var parts = [];
            if (memberGroups().length > 0) {
                parts.push(counted(summary.members, 'membre', 'membres') + ' pour ' + year);
            }
            if (contactGroups().length > 0) {
                parts.push(contactsLine(summary.contacts, summary.contacts_unsubscribed));
            }
            var split = parts.join(' et ') + '.';
            if (state.blocks.length > 1) {
                split += ' Une personne désignée par plusieurs blocs ne reçoit l\'e-mail qu\'une fois.';
            }
            totalEl.appendChild(node('p', 'small mb-1', split));

            var link = /** @type {HTMLButtonElement} */ (
                node('button', 'btn btn-link btn-sm p-0', 'Voir les destinataires'));
            link.type = 'button';
            link.dataset.recipientsOpen = '';
            link.addEventListener('click', openRecipients);
            totalEl.appendChild(link);
        }

        /**
         * One block's line: the part that reopens its dialog, and its
         * trash icon beside it — siblings, so removing never opens.
         *
         * @param {ListBlockData} block
         * @returns {HTMLElement}
         */
        function blockRow(block) {
            var row = node('div', 'list-group-item d-flex align-items-center gap-2 px-2');
            row.dataset.blockRow = String(block.id);

            var open = /** @type {HTMLButtonElement} */ (node('button',
                'btn btn-link text-start text-body text-decoration-none flex-grow-1 p-1'));
            open.type = 'button';
            open.dataset.blockId = String(block.id);
            var title = node('span', 'd-block');
            var detail = node('span', 'd-block small text-body-secondary');

            var remove = /** @type {HTMLButtonElement} */ (
                node('button', 'btn btn-sm btn-outline-danger flex-shrink-0'));
            remove.type = 'button';
            remove.dataset.blockRemove = String(block.id);
            remove.appendChild(node('i', 'bi bi-trash')).setAttribute('aria-hidden', 'true');

            if (block.kind === KIND_MEMBER_GROUP) {
                open.dataset.memberGroupOpen = '';
                renderSentence(title, block.sentence);
                detail.textContent = counted(block.count, 'membre', 'membres') + ' en ' + state.scout_year_label;
                remove.setAttribute('aria-label', 'Retirer ce groupe de membres');
            } else {
                // The contract with mass-mail-contact-groups.js: this
                // attribute and data-block-id are what it opens from.
                open.dataset.contactGroupOpen = '';
                title.textContent = block.name || '';
                detail.textContent = contactsLine(block.count, block.unsubscribed)
                    + (block.file_replaced_label ? ' · fichier remplacé le ' + block.file_replaced_label : '');
                remove.setAttribute('aria-label', 'Retirer le groupe ' + (block.name || ''));
            }

            open.appendChild(title);
            open.appendChild(detail);
            row.appendChild(open);
            row.appendChild(remove);
            return row;
        }

        /**
         * One kind's heading and lines — or nothing at all when the list
         * has no block of that kind.
         *
         * @param {string} heading
         * @param {string} count
         * @param {ListBlockData[]} blocks
         */
        function renderKind(heading, count, blocks) {
            if (blocks.length === 0) {
                return;
            }
            var head = node('div', 'd-flex justify-content-between align-items-baseline mt-3 mb-1');
            head.appendChild(node('h2', 'h6 mb-0', heading));
            head.appendChild(node('span', 'small text-body-secondary', count));
            blocksEl.appendChild(head);

            var group = node('div', 'list-group mb-3');
            blocks.forEach(function (block) { group.appendChild(blockRow(block)); });
            blocksEl.appendChild(group);
        }

        function render() {
            renderTotal();
            blocksEl.textContent = '';
            renderKind('Groupes de membres', counted(state.summary.members, 'membre', 'membres'), memberGroups());
            renderKind('Groupes de contacts', counted(state.summary.contacts, 'contact', 'contacts'), contactGroups());
            emptyTextEl.classList.toggle('d-none', state.blocks.length > 0);
            yearNoteEl.classList.toggle('d-none', memberGroups().length === 0);
        }

        /**
         * Redraws from the server after anything changed a block. Sequenced
         * like the counter below: two saves in a row must leave the later
         * answer on screen, whichever arrives last.
         */
        var reloadToken = 0;

        async function reload() {
            var token = ++reloadToken;
            var res = await api.getJson(BASE + '/lists/' + listId + '/blocks');
            if (token !== reloadToken) {
                return;
            }
            if (!isSuccess(res)) {
                toastError(res);
                return;
            }
            state = res.data;
            render();
        }

        // The contact-group dialog's « Enregistrer » (mass-mail-contact-groups.js).
        document.addEventListener('mass-mail:blocks-changed', function () {
            void reload();
        });

        /**
         * @param {number} id
         * @returns {ListBlockData|undefined}
         */
        function findBlock(id) {
            return state.blocks.find(function (b) { return b.id === id; });
        }

        // -- Removing a block --

        /** @param {ListBlockData} block */
        async function removeBlock(block) {
            var member = block.kind === KIND_MEMBER_GROUP;
            var confirmed = await window.ScoutMagicConfirm.ask({
                title: member ? 'Retirer ce groupe de membres ?' : 'Retirer ce groupe de contacts ?',
                message: member
                    ? sentenceText(block.sentence) + ' — '
                        + counted(block.count, 'membre', 'membres') + ' en ' + state.scout_year_label
                    : (block.name || '') + ' — ' + counted(block.count, 'contact', 'contacts'),
                content: [
                    {
                        text: (member ? 'Ces membres' : 'Ces contacts')
                            + ' ne recevront plus les e-mails de cette liste, sauf ceux qu\'un autre bloc désigne. '
                            + 'Les e-mails déjà envoyés ne changent pas. Aucun e-mail n\'est envoyé.',
                        note: member
                            ? undefined
                            : 'Les désinscriptions sont conservées : elles valent pour toute l\'unité.'
                    }
                ],
                confirmLabel: 'Retirer'
            });
            if (!confirmed) {
                return;
            }
            var res = await api.postJson(BASE + '/blocks/' + block.id, {}, { method: 'DELETE' });
            if (!isSuccess(res)) {
                toastError(res);
                return;
            }
            await reload();
        }

        blocksEl.addEventListener('click', function (e) {
            var target = /** @type {HTMLElement} */ (e.target);
            var removeBtn = /** @type {HTMLElement|null} */ (target.closest('[data-block-remove]'));
            if (removeBtn) {
                var block = findBlock(Number(removeBtn.dataset.blockRemove));
                if (block) {
                    void removeBlock(block);
                }
                return;
            }
            var openBtn = /** @type {HTMLElement|null} */ (target.closest('[data-member-group-open]'));
            if (openBtn) {
                var group = findBlock(Number(openBtn.dataset.blockId));
                if (group) {
                    openMemberGroup(group);
                }
            }
        });

        // -- Choosing the kind of block --

        document.querySelector('[data-kind-chooser-open]')?.addEventListener('click', function () {
            showModal(chooserEl);
        });

        chooserEl.querySelector('[data-kind-choice="member_group"]')?.addEventListener('click', function () {
            hideModal(chooserEl, function () { openMemberGroup(null); });
        });

        // The contact-group choice is mass-mail-contact-groups.js's to open;
        // this page only gets the chooser out of its way.
        chooserEl.querySelector('[data-contact-group-open]')?.addEventListener('click', function () {
            hideModal(chooserEl);
        });

        // -- The member-group dialog --

        var sentenceEl = /** @type {HTMLElement} */ (memberModalEl.querySelector('[data-member-group-sentence]'));
        var countEl = /** @type {HTMLElement} */ (memberModalEl.querySelector('[data-member-group-count]'));
        var memberErrorEl = /** @type {HTMLElement} */ (memberModalEl.querySelector('[data-member-group-error]'));
        var memberSaveBtn = /** @type {HTMLButtonElement} */ (memberModalEl.querySelector('[data-member-group-save]'));
        /** @type {number|null} */
        var editedBlockId = null;

        /**
         * @param {string} pickerId
         * @returns {number[]}
         */
        function selectedIds(pickerId) {
            var container = document.getElementById(pickerId);
            if (!container) return [];
            return Array.from(container.querySelectorAll('.select-bar-item[data-selected="true"]'))
                .map(function (item) { return Number.parseInt(/** @type {HTMLElement} */ (item).dataset.id, 10); })
                .filter(function (id) { return !Number.isNaN(id); });
        }

        /**
         * @returns {{function_ids: number[], section_ids: number[], badge_ids: number[]}}
         */
        function currentCriteria() {
            return {
                function_ids: selectedIds(FUNCTION_PICKER),
                section_ids: selectedIds(SECTION_PICKER),
                badge_ids: selectedIds(BADGE_PICKER)
            };
        }

        /**
         * Sets one select bar's selection to exactly `ids`, through the
         * component's own escape hatch so the row, the check mark and the
         * trigger summary stay in step.
         *
         * @param {string} pickerId
         * @param {number[]} ids
         */
        function setPickerSelection(pickerId, ids) {
            var container = document.getElementById(pickerId);
            if (!container || !window.SelectBar) return;
            var wanted = new Set(ids.map(String));
            container.querySelectorAll('.select-bar-item').forEach(function (item) {
                var id = /** @type {HTMLElement} */ (item).dataset.id || '';
                window.SelectBar.setSelected(pickerId, id, wanted.has(id));
            });
        }

        /**
         * The live count and the sentence, asked of the server after every
         * change.
         *
         * Sequenced rather than debounced: each request carries the token
         * of the call that started it, and an answer that is no longer the
         * latest is dropped. Toggling three rows quickly otherwise leaves
         * whichever response happens to come back last on screen, which is
         * the one case a counter must never get wrong.
         */
        var countToken = 0;

        async function refreshCriteria() {
            var criteria = currentCriteria();
            var token = ++countToken;
            var empty = criteria.function_ids.length === 0
                && criteria.section_ids.length === 0
                && criteria.badge_ids.length === 0;

            // At least one choice, as the server requires: three « Peu
            // importe » would read as everybody and resolve to nobody.
            memberSaveBtn.disabled = empty;
            if (empty) {
                sentenceEl.textContent = 'Choisissez au moins une fonction, une section ou un badge.';
                countEl.textContent = '';
                countEl.className = 'small mb-0';
                return;
            }

            countEl.textContent = 'Comptage…';
            countEl.className = 'small mb-0 text-body-secondary';

            var res = await api.postJson(BASE + '/preview-count', criteria);
            if (token !== countToken) {
                return;
            }
            if (!isSuccess(res)) {
                countEl.textContent = 'Le nombre de destinataires n\'a pas pu être calculé.';
                return;
            }

            renderSentence(sentenceEl, res.data.sentence);
            var count = res.data.count;
            countEl.textContent = counted(count, 'membre', 'membres') + ' en ' + res.data.scout_year_label;
            countEl.className = count === 0 ? 'small mb-0 text-danger' : 'small mb-0 fw-semibold';
            if (count === 0 && criteria.badge_ids.length > 0) {
                // Zero is worth explaining only when there is something to
                // explain: a badge is worn by the Staff d'U and the
                // animateurs and by nobody else, so crossing it with an
                // animés section is empty by construction.
                countEl.appendChild(node('span', 'd-block text-body-secondary',
                    'Un badge n\'est porté que par le Staff d\'U et les animateurs.'));
            }
        }

        [FUNCTION_PICKER, SECTION_PICKER, BADGE_PICKER].forEach(function (pickerId) {
            document.getElementById(pickerId)?.addEventListener('select-bar:change', function () {
                void refreshCriteria();
            });
        });

        /** @param {ListBlockData|null} block null for a new group */
        function openMemberGroup(block) {
            editedBlockId = block ? block.id : null;
            memberErrorEl.classList.add('d-none');
            setPickerSelection(FUNCTION_PICKER, block ? block.function_ids : []);
            setPickerSelection(SECTION_PICKER, block ? block.section_ids : []);
            setPickerSelection(BADGE_PICKER, block ? block.badge_ids : []);
            void refreshCriteria();
            showModal(memberModalEl);
        }

        memberSaveBtn.addEventListener('click', async function () {
            // Same rule: a double click must not create the group twice.
            // On success the dialog closes, and reopening it recomputes
            // the button through refreshCriteria().
            if (memberSaveBtn.disabled) {
                return;
            }
            memberSaveBtn.disabled = true;
            var criteria = currentCriteria();
            var res = editedBlockId === null
                ? await api.postJson(BASE + '/lists/' + listId + '/member-groups', criteria)
                : await api.postJson(BASE + '/member-groups/' + editedBlockId, criteria, { method: 'PATCH' });
            if (!isSuccess(res)) {
                memberSaveBtn.disabled = false;
                memberErrorEl.textContent = errorMessage(res);
                memberErrorEl.classList.remove('d-none');
                return;
            }
            hideModal(memberModalEl);
            await reload();
        });

        // -- « Voir les destinataires » --

        var recipientsTitleEl = /** @type {HTMLElement} */ (document.getElementById('mm-recipients-modal-title'));
        var yearSelect = /** @type {HTMLSelectElement|null} */ (
            recipientsModalEl.querySelector('[data-recipients-year]'));
        var searchInput = /** @type {HTMLInputElement} */ (recipientsModalEl.querySelector('[data-recipients-search]'));
        var rowsEl = /** @type {HTMLElement} */ (recipientsModalEl.querySelector('[data-recipients-rows]'));
        var recipientsEmptyEl = /** @type {HTMLElement} */ (recipientsModalEl.querySelector('[data-recipients-empty]'));
        var recipientsErrorEl = /** @type {HTMLElement} */ (recipientsModalEl.querySelector('[data-recipients-error]'));
        var recipientsToken = 0;

        function applySearch() {
            var query = fold(searchInput.value.trim());
            var shown = 0;
            rowsEl.querySelectorAll('li').forEach(function (li) {
                var match = query === '' || (li.dataset.search || '').includes(query);
                li.classList.toggle('d-none', !match);
                if (match) shown++;
            });
            recipientsEmptyEl.classList.toggle('d-none', shown > 0);
        }

        /**
         * @param {{name: ?string, display_name: ?string, email: string, block_ids: number[]}} recipient
         * @param {Object<string, {kind: string, label: string}>} blocks
         * @returns {HTMLElement}
         */
        function recipientRow(recipient, blocks) {
            var li = node('li', 'list-group-item d-flex justify-content-between align-items-start gap-2 px-0');
            var who = node('div', 'text-break');
            var name = recipient.name || '';
            // A totem says who somebody is in the unit; a first name the
            // full name already holds says nothing more.
            if (recipient.display_name && !name.includes(recipient.display_name)) {
                name += (name ? ' — ' : '') + recipient.display_name;
            }
            if (name) {
                who.appendChild(node('span', 'd-block', name));
            }
            who.appendChild(node('span', 'd-block small text-body-secondary', recipient.email));
            li.appendChild(who);

            var tags = node('div', 'd-flex flex-wrap gap-1 justify-content-end flex-shrink-0');
            var labels = [];
            recipient.block_ids.forEach(function (id) {
                var block = blocks[String(id)];
                if (!block) return;
                labels.push(block.label);
                tags.appendChild(node('span', block.kind === KIND_MEMBER_GROUP
                    ? 'badge text-bg-primary'
                    : 'badge text-bg-secondary', block.label));
            });
            li.appendChild(tags);
            li.dataset.search = fold([name, recipient.email].concat(labels).join(' '));
            return li;
        }

        async function loadRecipients() {
            var token = ++recipientsToken;
            var yearId = yearSelect ? yearSelect.value : String(data.effective_year_id);
            recipientsErrorEl.classList.add('d-none');
            rowsEl.textContent = '';
            recipientsEmptyEl.classList.add('d-none');

            var res = await api.getJson(BASE + '/lists/' + listId + '/recipients?year=' + encodeURIComponent(yearId));
            if (token !== recipientsToken) {
                return;
            }
            if (!isSuccess(res)) {
                recipientsErrorEl.textContent = errorMessage(res);
                recipientsErrorEl.classList.remove('d-none');
                return;
            }

            var recipients = res.data.recipients || [];
            recipientsTitleEl.textContent = counted(recipients.length, 'destinataire', 'destinataires')
                + ' en ' + res.data.scout_year_label;
            recipients.forEach(function (recipient) {
                rowsEl.appendChild(recipientRow(recipient, res.data.blocks || {}));
            });
            applySearch();
        }

        function openRecipients() {
            searchInput.value = '';
            showModal(recipientsModalEl);
            void loadRecipients();
        }

        yearSelect?.addEventListener('change', function () { void loadRecipients(); });
        searchInput.addEventListener('input', applySearch);

        render();
    }

    // --- Entry ---

    var identityModal = document.getElementById('mm-list-identity-modal');
    if (identityModal) {
        wireIdentityDialog(identityModal);
    }
    if (document.getElementById('mm-custom-lists')) {
        wireListsPage();
    }
    var listData = api?.pageData('mm-list-data');
    if (listData) {
        wireListPage(listData);
    }
})();
