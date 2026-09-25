/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// « Vérifier que cet hébergement laisse passer la synchronisation », on
// « Synchroniser mes contacts » (core/View/templates/account/devices.html.twig).
//
// The question it answers cannot be answered from the other end. A fair
// number of Apache configurations on shared hosting refuse PROPFIND and
// REPORT outright, or hand them to their own WebDAV module before PHP is
// ever reached; the symptom on a phone is « impossible de se connecter »
// and nothing else, which is indistinguishable from a wrong password.
//
// So the browser sends the two unusual methods at /api/carddav/probe —
// an endpoint that reads nothing, writes nothing and names nobody — and
// reports which of them arrived. A reply means PHP was reached by that
// method. A 403 or 405 means the web server answered first, and that is
// the sentence a chef d'unité needs in order to ask their host the right
// question.
(function () {
    var button = /** @type {HTMLButtonElement|null} */ (document.getElementById('carddav-probe-btn'));
    var result = document.getElementById('carddav-probe-result');
    if (!button || !result) {
        return;
    }

    var METHODS = ['PROPFIND', 'REPORT'];
    var ENDPOINT = '/api/carddav/probe';

    /**
     * Did this method reach PHP?
     *
     * Read from the status rather than from the body: what matters is
     * that the application answered at all. A network failure resolves
     * to false rather than rejecting, so one blocked method never hides
     * the verdict on the other.
     *
     * @param {string} method
     * @returns {Promise<boolean>}
     */
    function reaches(method) {
        return fetch(ENDPOINT, { method: method, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (res) { return res.ok; })
            .catch(function () { return false; });
    }

    /**
     * @param {string} className
     * @param {string} message
     * @returns {void}
     */
    function show(className, message) {
        if (!result) {
            return;
        }
        result.className = 'd-block small mt-2 ' + className;
        result.textContent = message;
    }

    button.addEventListener('click', function () {
        button.disabled = true;
        show('text-body-secondary', 'Vérification en cours…');

        Promise.all(METHODS.map(reaches)).then(function (outcomes) {
            button.disabled = false;

            var blocked = METHODS.filter(function (_method, index) { return !outcomes[index]; });

            if (blocked.length === 0) {
                show(
                    'text-success',
                    'Cet hébergement laisse passer la synchronisation : les requêtes PROPFIND et '
                    + 'REPORT arrivent bien jusqu\'au site.'
                );
                return;
            }

            show(
                'text-danger',
                'Cet hébergement bloque ' + blocked.join(' et ') + '. La synchronisation ne pourra pas '
                + 'fonctionner tant que votre hébergeur n\'autorisera pas ces méthodes — c\'est une '
                + 'question à lui poser en ces termes, le site lui-même est correctement configuré.'
            );
        });
    });
})();
