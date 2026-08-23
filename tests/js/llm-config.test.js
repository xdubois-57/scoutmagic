// Isolated JavaScript unit test — jsdom-simulated DOM only. No PHP server,
// no MySQL, no network: fetch is mocked throughout. Exercises the REAL
// implementation in public/assets/js/llm-config.js (imported below, never
// reimplemented here), on top of the real fetch toolbox
// public/assets/js/api.js — base.html.twig guarantees that load order in
// production.
//
// The property this file exists to protect is the one the page got wrong:
// **consulting the provider dropdown must not activate a provider.** The
// change handler used to test the highlighted provider and POST it back as
// the active one, so scrolling through the list silently switched the
// site's AI sub-processor (AGENTS.md § RGPD, ARCHITECTURE.md §7.5).
// "Changing the select fires no fetch at all" is therefore the single most
// important assertion here; everything else pins the display refresh and
// the deliberate save-then-test flow that now owns activation.
import { beforeEach, describe, expect, it, vi } from 'vitest';

const MODELS_BY_DRIVER = {
    anthropic: [
        {
            display_name: 'Claude Sonnet',
            model_id: 'claude-sonnet-4',
            is_tier_cheap: false,
            is_tier_capable: true,
            is_tier_ocr: false,
        },
        {
            display_name: 'Claude Haiku',
            model_id: 'claude-haiku-4',
            is_tier_cheap: true,
            is_tier_capable: false,
            is_tier_ocr: true,
        },
    ],
    mistral: [
        {
            display_name: 'Mistral Small',
            model_id: 'mistral-small',
            is_tier_cheap: false,
            is_tier_capable: false,
            is_tier_ocr: false,
        },
    ],
    // scaleway deliberately absent — the empty-state branch.
};

const PAGE_DOM = `
    <div data-summary>
        <span class="badge text-bg-secondary">Non configuré</span>
        <div data-provider><strong>—</strong></div>
        <div data-cheap><strong>—</strong></div>
        <div data-capable><strong>—</strong></div>
        <div data-ocr><strong>—</strong></div>
    </div>
    <form id="provider-form">
        <input type="hidden" id="provider-id" value="">
        <input type="hidden" id="provider-name" value="">
        <input type="hidden" id="provider-endpoint" value="">
        <input type="hidden" id="provider-has-key" value="">
        <select id="provider-driver">
            <option value="anthropic" data-endpoint="https://api.anthropic.com" data-label="Anthropic (Claude)"
                    data-provider-id="7" data-has-key="1" selected>Anthropic (Claude) (✓)</option>
            <option value="mistral" data-endpoint="https://api.mistral.ai" data-label="Mistral AI"
                    data-provider-id="" data-has-key="0">Mistral AI</option>
            <option value="scaleway" data-endpoint="https://api.scaleway.ai" data-label="Scaleway"
                    data-provider-id="9" data-has-key="1">Scaleway (✓)</option>
        </select>
        <div id="driver-help-anthropic">aide Anthropic</div>
        <div id="driver-help-mistral">aide Mistral</div>
        <div id="driver-help-scaleway">aide Scaleway</div>
        <input type="password" id="provider-api-key" placeholder="">
        <button type="submit" class="btn btn-primary text-nowrap">Enregistrer et activer</button>
        <div class="form-text" id="api-key-hint"></div>
    </form>
    <div id="provider-message" role="status" style="display:none;"></div>
    <div id="models-container"></div>`;

/**
 * A fetch double answering each URL from a map of JSON bodies. Anything not
 * listed answers `{success: true}` — an unexpected call still shows up in
 * fetch.mock.calls, which is what the assertions read.
 *
 * @param {Object.<string, any>} bodiesByUrlFragment
 */
function mockFetch(bodiesByUrlFragment) {
    return vi.fn((url) => {
        const match = Object.keys(bodiesByUrlFragment).find((fragment) => String(url).includes(fragment));
        const body = match ? bodiesByUrlFragment[match] : { success: true };
        return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve(body) });
    });
}

/** @param {string} value */
function chooseDriver(value) {
    const select = document.getElementById('provider-driver');
    select.value = value;
    select.dispatchEvent(new Event('change', { bubbles: true }));
}

function submitForm() {
    document.getElementById('provider-form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
}

async function boot() {
    vi.resetModules();
    await import('../../public/assets/js/api.js');
    await import('../../public/assets/js/llm-config.js');
}

describe('llm-config.js', () => {
    beforeEach(() => {
        vi.restoreAllMocks();
        document.head.innerHTML = '<meta name="csrf-token" content="tok-123">';
        document.body.innerHTML = PAGE_DOM;
        global.window.llmConfigData = { modelsByDriver: structuredClone(MODELS_BY_DRIVER) };
        global.fetch = mockFetch({});
        // A successful test reloads the page; jsdom does not implement
        // navigation and would log "Not implemented".
        Object.defineProperty(window, 'location', {
            configurable: true,
            value: { href: '/config/llm', reload: vi.fn() },
        });
    });

    describe('consulting the dropdown is not activating a provider', () => {
        it('fires NO fetch at all when the selected provider changes', async () => {
            await boot();

            // Scaleway is a saved provider WITH a stored key — exactly the
            // case the old handler used to test-and-activate on sight.
            chooseDriver('scaleway');
            chooseDriver('mistral');
            chooseDriver('anthropic');
            await Promise.resolve();

            expect(fetch).not.toHaveBeenCalled();
        });

        it('leaves the summary card showing the provider actually active', async () => {
            await boot();

            chooseDriver('scaleway');
            await Promise.resolve();

            expect(document.querySelector('[data-summary] .badge').textContent).toBe('Non configuré');
            expect(document.querySelector('[data-provider] strong').textContent).toBe('—');
        });
    });

    describe('the change handler refreshes what is displayed', () => {
        it('mirrors the selected option into the hidden fields the form posts', async () => {
            await boot();

            chooseDriver('mistral');

            expect(document.getElementById('provider-id').value).toBe('');
            expect(document.getElementById('provider-name').value).toBe('Mistral AI');
            expect(document.getElementById('provider-endpoint').value).toBe('https://api.mistral.ai');
            expect(document.getElementById('provider-has-key').value).toBe('0');
        });

        it('keeps the stored-key hint and placeholder for a provider that has a key', async () => {
            await boot();

            chooseDriver('scaleway');

            expect(document.getElementById('api-key-hint').textContent)
                .toBe('Laisser vide pour conserver la clé actuelle.');
            expect(document.getElementById('provider-api-key').placeholder).toBe('••••••••');
            expect(document.getElementById('provider-id').value).toBe('9');
        });

        it('clears that hint for a provider with no stored key', async () => {
            await boot();

            chooseDriver('mistral');

            expect(document.getElementById('api-key-hint').textContent).toBe('');
            expect(document.getElementById('provider-api-key').placeholder).toBe('');
        });

        it('shows only the selected driver help block', async () => {
            await boot();

            chooseDriver('mistral');

            expect(document.getElementById('driver-help-mistral').style.display).toBe('');
            expect(document.getElementById('driver-help-anthropic').style.display).toBe('none');
            expect(document.getElementById('driver-help-scaleway').style.display).toBe('none');
        });

        it('rebuilds the models table for the selected driver', async () => {
            await boot();
            expect(document.querySelectorAll('#models-container tbody tr')).toHaveLength(2);

            chooseDriver('mistral');

            const rows = document.querySelectorAll('#models-container tbody tr');
            expect(rows).toHaveLength(1);
            expect(rows[0].textContent).toContain('Mistral Small');
            expect(rows[0].textContent).toContain('mistral-small');
        });

        it('badges each model with the tiers it holds, an em dash with none', async () => {
            await boot();

            const badges = Array.from(document.querySelectorAll('#models-container tbody .badge')).map((b) => b.textContent);
            expect(badges).toEqual(['Performant', 'Économique', 'OCR']);

            chooseDriver('mistral');
            expect(document.querySelectorAll('#models-container tbody .badge')).toHaveLength(0);
            expect(document.querySelector('#models-container tbody td:last-child').textContent).toBe('—');
        });

        it('shows the empty state for a driver with no discovered model', async () => {
            await boot();

            chooseDriver('scaleway');

            expect(document.querySelector('#models-container table')).toBeNull();
            expect(document.getElementById('models-container').textContent).toContain('Aucun modèle découvert');
        });

        it('wraps the table so it scrolls instead of overflowing the page', async () => {
            await boot();

            expect(document.querySelector('#models-container .table-responsive table')).not.toBeNull();
        });
    });

    describe('the models list never parses provider text as markup', () => {
        it('escapes a display name containing HTML', async () => {
            global.window.llmConfigData = {
                modelsByDriver: {
                    anthropic: [{
                        display_name: '<img src=x onerror=alert(1)>Claude',
                        model_id: '<script>alert(2)</script>',
                        is_tier_cheap: true,
                        is_tier_capable: false,
                        is_tier_ocr: false,
                    }],
                },
            };
            await boot();

            const container = document.getElementById('models-container');
            expect(container.querySelector('img')).toBeNull();
            expect(container.querySelector('script')).toBeNull();
            expect(container.textContent).toContain('<img src=x onerror=alert(1)>Claude');
            expect(container.querySelector('code').textContent).toBe('<script>alert(2)</script>');
        });
    });

    describe('the form is the one deliberate act of activation', () => {
        it('POSTs the field values and the CSRF token to /config/llm/providers', async () => {
            await boot();
            document.getElementById('provider-api-key').value = 'sk-secret';

            submitForm();
            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());

            const [url, opts] = fetch.mock.calls[0];
            expect(url).toBe('/config/llm/providers');
            expect(opts.method).toBe('POST');
            expect(JSON.parse(opts.body)).toEqual({
                id: '7',
                name: 'Anthropic (Claude)',
                driver: 'anthropic',
                api_endpoint: 'https://api.anthropic.com',
                api_key: 'sk-secret',
                _csrf_token: 'tok-123',
            });
            expect(opts.headers['X-CSRF-Token']).toBe('tok-123');
        });

        it('posts an empty api_key for a stored-key provider, the server contract for "keep it"', async () => {
            await boot();
            chooseDriver('scaleway');

            submitForm();
            await vi.waitFor(() => expect(fetch).toHaveBeenCalled());

            expect(JSON.parse(fetch.mock.calls[0][1].body).api_key).toBe('');
        });

        it('renders the server error and does NOT go on to test the connection', async () => {
            global.fetch = mockFetch({
                '/config/llm/providers': { success: false, error: 'Fournisseur introuvable.' },
            });
            await boot();

            submitForm();
            await vi.waitFor(() => {
                expect(document.getElementById('provider-message').textContent).toContain('Fournisseur introuvable.');
            });

            expect(fetch).toHaveBeenCalledTimes(1);
            expect(fetch.mock.calls.some(([url]) => String(url).includes('/test'))).toBe(false);
            expect(window.location.reload).not.toHaveBeenCalled();
        });

        it('escapes the server error rather than injecting it', async () => {
            global.fetch = mockFetch({
                '/config/llm/providers': { success: false, error: '<img src=x onerror=alert(1)>' },
            });
            await boot();

            submitForm();
            await vi.waitFor(() => {
                expect(document.getElementById('provider-message').textContent).toContain('<img src=x onerror=alert(1)>');
            });
            expect(document.getElementById('provider-message').querySelector('img')).toBeNull();
        });

        it('goes on to POST the /test endpoint once the save succeeded', async () => {
            global.fetch = mockFetch({
                '/test': { success: true, message: 'Connexion réussie — 4 modèle(s) trouvé(s).', provider_name: 'Anthropic (Claude)' },
                '/config/llm/providers': { success: true, provider_id: 42 },
            });
            await boot();

            submitForm();
            await vi.waitFor(() => expect(fetch).toHaveBeenCalledTimes(2));

            expect(fetch.mock.calls[1][0]).toBe('/config/llm/providers/42/test');
            expect(JSON.parse(fetch.mock.calls[1][1].body)).toEqual({ _csrf_token: 'tok-123' });
            expect(document.getElementById('provider-id').value).toBe('42');
        });

        it('refreshes the summary card from the test result', async () => {
            global.fetch = mockFetch({
                '/test': {
                    success: true,
                    message: 'Connexion réussie — 4 modèle(s) trouvé(s).',
                    provider_name: 'Anthropic (Claude)',
                    cheap_model: 'Claude Haiku',
                    capable_model: 'Claude Sonnet',
                    ocr_model: 'Claude Haiku',
                    ocr_fallback: true,
                },
                '/config/llm/providers': { success: true, provider_id: 42 },
            });
            await boot();

            submitForm();
            await vi.waitFor(() => {
                expect(document.querySelector('[data-provider] strong').textContent).toBe('Anthropic (Claude)');
            });
            expect(document.querySelector('[data-summary] .badge').textContent).toBe('Actif');
            expect(document.querySelector('[data-cheap] strong').textContent).toBe('Claude Haiku');
            expect(document.querySelector('[data-capable] strong').textContent).toBe('Claude Sonnet');
            expect(document.querySelector('[data-ocr] strong').textContent).toBe('Claude Haiku (fallback)');
        });

        it('reports a failed connection test without reloading', async () => {
            global.fetch = mockFetch({
                '/test': { success: false, error: 'Échec de connexion : clé refusée.' },
                '/config/llm/providers': { success: true, provider_id: 42 },
            });
            await boot();

            submitForm();
            await vi.waitFor(() => {
                expect(document.getElementById('provider-message').textContent).toContain('Échec de connexion');
            });
            expect(window.location.reload).not.toHaveBeenCalled();
        });

        it('treats an HTTP error page as a failure instead of a silent success', async () => {
            // postJson folds a non-JSON body into data:null — business
            // success is data.success, never the HTTP-level `ok`.
            global.fetch = vi.fn(() => Promise.resolve({
                ok: false,
                status: 500,
                json: () => Promise.reject(new Error('not json')),
            }));
            await boot();

            submitForm();
            await vi.waitFor(() => {
                expect(document.getElementById('provider-message').textContent).toContain('Erreur.');
            });
            expect(fetch).toHaveBeenCalledTimes(1);
        });
    });
});
