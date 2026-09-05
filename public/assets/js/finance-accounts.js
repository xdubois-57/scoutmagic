/*!
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

// Finance module accounts configuration page
// (modules/finance/views/config/accounts.html.twig): the create/edit
// dialog (with the two availability rules a cash account and a default
// account impose on the form) and the activate/deactivate toggle.
// Extracted from the template's inline <script> so the Vitest suite can
// exercise the production code directly (tests/js/finance-accounts.test.js)
// — the page hands every server-side value over through the row buttons'
// data-* attributes, so there is no data global to read here.
//
// Both calls ride the site-wide ScoutMagicApi envelope ({ok, status,
// data}), which also carries the CSRF token the file-local csrf() reader
// used to fetch. HTTP success and business success stay strictly apart:
// `data.success` is the only thing that means "the account was saved", and
// a 500 error page — which is not JSON at all, so postJson folds it into
// data:null — now says so instead of throwing inside res.json() (see
// api.js's header for the bug that convention exists to prevent).
//
// The toggle's failure feedback was an alert(); it is a toast now
// (design.md §7.5). The toggle itself asks nothing before acting, exactly
// like the categories page's own activate/deactivate button: deactivating
// an account destroys nothing and the same button puts it back.
(function () {
    var accountModalEl = document.getElementById('account-modal');
    var accountFormEl = /** @type {HTMLFormElement|null} */ (document.getElementById('account-form'));
    // Every handler below reaches into the dialog. On any page that loads
    // this file without that markup the script is a no-op, instead of a
    // TypeError on the first getElementById().value.
    if (!accountModalEl || !accountFormEl) {
        return;
    }

    var api = window.ScoutMagicApi;

    /**
     * @param {string} id
     * @returns {HTMLInputElement}
     */
    function inputEl(id) { return /** @type {HTMLInputElement} */ (document.getElementById(id)); }
    /**
     * @param {string} id
     * @returns {HTMLSelectElement}
     */
    function selectEl(id) { return /** @type {HTMLSelectElement} */ (document.getElementById(id)); }

    /**
     * The failure line for one envelope: the server's own message when it
     * answered JSON, a distinct one when it did not answer JSON at all
     * (postJson folds a network error or an HTML error page into
     * data:null) — the finance-config.js precedent.
     *
     * @param {{ok: boolean, status: number, data: any}} res
     * @returns {string}
     */
    function errorMessage(res) {
        if (res.data) {
            return res.data.error || 'Une erreur est survenue.';
        }
        return 'Erreur : réponse serveur invalide.';
    }

    var accountModal = window.bootstrap ? new window.bootstrap.Modal(accountModalEl) : null;
    var errorBox = document.getElementById('account-form-error');

    /** @param {string} title */
    function showModal(title) {
        document.getElementById('account-modal-title').textContent = title;
        errorBox.classList.add('d-none');
        accountModal ? accountModal.show() : accountModalEl.classList.add('show');
    }

    var accountTypeSelect = selectEl('account-type');
    var accountIbanInput = inputEl('account-iban');
    var accountHolderInput = inputEl('account-holder');
    var accountNameInput = inputEl('account-name');
    var accountSectionSelect = selectEl('account-section');

    function updateBankFieldsAvailability() {
        var isCash = accountTypeSelect.value === 'cash';
        accountIbanInput.disabled = isCash;
        accountHolderInput.disabled = isCash;
        if (isCash) {
            accountIbanInput.value = '';
            accountHolderInput.value = '';
        }
    }

    /** @param {boolean} isDefault */
    function updateIdentityFieldsAvailability(isDefault) {
        // A default (one-per-section) account's name/type/section are fixed
        // — Service\FinanceService::updateAccount() ignores any change to
        // them for such an account anyway; disabling here just keeps the
        // form honest about what will actually happen.
        accountNameInput.disabled = isDefault;
        accountTypeSelect.disabled = isDefault;
        accountSectionSelect.disabled = isDefault;
    }

    accountTypeSelect.addEventListener('change', updateBankFieldsAvailability);

    document.getElementById('new-account-btn').addEventListener('click', () => {
        accountFormEl.reset();
        inputEl('account-id').value = '';
        updateBankFieldsAvailability();
        updateIdentityFieldsAvailability(false);
        showModal('Nouveau compte');
    });

    /** @type {NodeListOf<HTMLElement>} */ (document.querySelectorAll('.edit-account-btn')).forEach(btn => {
        btn.addEventListener('click', () => {
            inputEl('account-id').value = btn.dataset.id;
            accountNameInput.value = btn.dataset.name;
            accountTypeSelect.value = btn.dataset.accountType;
            accountSectionSelect.value = btn.dataset.sectionId || '';
            selectEl('account-role-min-view').value = btn.dataset.roleMinView;
            accountIbanInput.value = btn.dataset.iban || '';
            accountHolderInput.value = btn.dataset.holder || '';
            updateBankFieldsAvailability();
            updateIdentityFieldsAvailability(btn.dataset.isDefault === '1');
            showModal('Modifier le compte');
        });
    });

    accountFormEl.addEventListener('submit', async (e) => {
        e.preventDefault();
        var id = inputEl('account-id').value;
        /** @type {{[key: string]: any}} */
        var payload = {
            action: id ? 'update' : 'create',
            name: accountNameInput.value,
            account_type: accountTypeSelect.value,
            section_id: accountSectionSelect.value,
            iban: accountIbanInput.value,
            holder_name: accountHolderInput.value,
            role_min_view: selectEl('account-role-min-view').value
        };
        if (id) {
            payload.id = Number.parseInt(id, 10);
        }

        // The submit button lives in the modal footer, tied to the form by
        // form="account-form" — withDisabled() owns its disabled state for
        // the whole request (including the failure path), so a second click
        // cannot create the same account twice.
        var submitBtn = /** @type {HTMLButtonElement|null} */ (
            document.querySelector('button[type="submit"][form="account-form"]')
        );
        await api.withDisabled(submitBtn, async () => {
            var res = await api.postJson('/config/finance/accounts', payload);
            if (res.data?.success) {
                window.location.reload();
            } else {
                errorBox.textContent = errorMessage(res);
                errorBox.classList.remove('d-none');
            }
        });
    });

    /**
     * @param {string} action
     * @param {number} id
     * @returns {Promise<void>}
     */
    async function postAccountAction(action, id) {
        var res = await api.postJson('/config/finance/accounts', { action: action, id: id });
        if (res.data?.success) {
            window.location.reload();
        } else {
            window.ScoutMagicToast.show(errorMessage(res), { variant: 'error' });
        }
    }

    /** @type {NodeListOf<HTMLElement>} */ (document.querySelectorAll('.toggle-account-btn')).forEach(btn => {
        btn.addEventListener('click', () => {
            postAccountAction(btn.dataset.active === '1' ? 'deactivate' : 'activate', Number.parseInt(btn.dataset.id, 10));
        });
    });
})();
