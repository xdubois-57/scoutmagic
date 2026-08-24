/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// "Afficher plus" for partials/audit_timeline.html.twig — appends the next
// page of GET /api/audit/{type}/{id} in place. See ARCHITECTURE.md §8.65.
//
// This file builds the same <li> the server renders in
// partials/audit_timeline_entry.html.twig. That duplication is the price of
// the JSON endpoint the component exposes, and it is deliberate rather than
// accidental: the two are kept in step by tests/js/audit-timeline.test.js,
// which asserts the classes and structure this produces. Change one, change
// the other.
//
// Nothing here is lazy loading — the first page is always in the HTML, so a
// visitor without JS still reads the history, just not past page one.
(function () {
    var SOURCE_LABELS = {
        human: 'Modifié dans le site',
        email: 'Détecté dans un message reçu',
        ai: 'Proposé automatiquement',
        system: 'Modification automatique'
    };

    /** Values come from the server already formatted; they are inserted as
     *  text, never as HTML — a camp note is free text a chief typed. */
    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text !== undefined && text !== null) {
            node.textContent = text;
        }
        return node;
    }

    function icon(className) {
        var i = el('i', 'bi ' + className);
        i.setAttribute('aria-hidden', 'true');
        return i;
    }

    function frenchDateTime(value) {
        // The endpoint sends "Y-m-d H:i:s"; Safari refuses that with a
        // space, so it is split by hand rather than handed to Date().
        var parts = String(value || '').split(' ');
        var date = (parts[0] || '').split('-');
        var time = (parts[1] || '').split(':');
        if (date.length !== 3) {
            return String(value || '');
        }
        return date[2] + '/' + date[1] + '/' + date[0] + ' à ' + (time[0] || '00') + ':' + (time[1] || '00');
    }

    function buildEntry(entry, labels) {
        var li = el('li', 'mb-3 position-relative audit-entry');
        li.dataset.source = entry.source;

        var marker = el(
            'span',
            'audit-entry-marker position-absolute translate-middle-x rounded-circle '
                + (entry.is_automatic ? 'bg-warning' : 'bg-primary')
        );
        marker.setAttribute('aria-hidden', 'true');
        li.appendChild(marker);

        var meta = el('div', 'small text-body-secondary d-flex align-items-center gap-1 flex-wrap');
        meta.appendChild(icon(entry.is_automatic ? 'bi-robot' : 'bi-person'));
        meta.appendChild(el('span', null, entry.actor_name || SOURCE_LABELS[entry.source] || SOURCE_LABELS.system));
        meta.appendChild(el('span', null, '·'));
        meta.appendChild(el('span', null, frenchDateTime(entry.created_at)));
        li.appendChild(meta);

        var change = el('div', 'mt-1');
        change.appendChild(el('span', 'fw-medium', labels[entry.field_key] || entry.field_key));
        if (entry.from_value !== null && entry.from_value !== undefined) {
            change.appendChild(el('span', 'text-body-secondary', '·'));
            change.appendChild(el('span', 'text-body-secondary text-decoration-line-through', entry.from_value));
        }
        if (entry.to_value !== null && entry.to_value !== undefined) {
            change.appendChild(icon('bi-arrow-right mx-1 small'));
            change.appendChild(el('span', null, entry.to_value));
        }
        li.appendChild(change);

        if (entry.summary) {
            li.appendChild(el('div', 'small text-body-secondary', entry.summary));
        }

        return li;
    }

    /** The labels the server used, handed to the JS through the container so
     *  a field reads the same before and after "Afficher plus". */
    function labelsFor(container) {
        try {
            return JSON.parse(container.dataset.labels || '{}');
        } catch (e) {
            return {};
        }
    }

    async function loadMore(button) {
        var container = button.closest('.audit-timeline');
        var list = container.querySelector('.audit-timeline-list');
        var wrapper = button.closest('.audit-timeline-more-wrapper');
        var page = parseInt(button.dataset.nextPage, 10) || 2;

        button.disabled = true;
        var url = '/api/audit/' + encodeURIComponent(container.dataset.entityType)
            + '/' + encodeURIComponent(container.dataset.entityId) + '?page=' + page;

        var response = await fetch(url, { headers: { 'X-Requested-With': 'fetch' } });
        if (!response.ok) {
            // Left enabled on purpose: a failed page is worth retrying, and
            // silently disabling the only control would read as "that was
            // the end of the history".
            button.disabled = false;
            return;
        }

        var data = await response.json();
        var labels = labelsFor(container);
        (data.entries || []).forEach(function (entry) {
            list.appendChild(buildEntry(entry, labels));
        });

        if (data.has_more) {
            button.dataset.nextPage = String(page + 1);
            button.textContent = 'Afficher plus (' + (data.total - list.children.length) + ')';
            button.disabled = false;
        } else {
            wrapper.remove();
        }
    }

    document.addEventListener('click', function (event) {
        var target = /** @type {HTMLElement} */ (event.target);
        var button = /** @type {HTMLButtonElement} */ (target.closest('.audit-timeline-more'));
        if (button) {
            loadMore(button);
        }
    });
})();
