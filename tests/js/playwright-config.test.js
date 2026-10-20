// @vitest-environment node
//
// tests/e2e/playwright.config.js: the browser the DAST job launches has to
// accept ZAP's certificate at the TLS layer, not only per request — see
// launchArguments() for the net::ERR_TOO_MANY_RETRIES this prevents. And
// `npm run e2e`, which sets none of the opt-ins, must launch the browser
// exactly as before.
import { afterEach, describe, expect, it, vi } from 'vitest';

const OPT_INS = ['E2E_BASE_URL', 'E2E_CHROMIUM_EXECUTABLE', 'E2E_PROXY_SERVER', 'E2E_IGNORE_HTTPS_ERRORS'];
const saved = Object.fromEntries(OPT_INS.map((name) => [name, process.env[name]]));

/**
 * Evaluates the config afresh under the given environment.
 *
 * @param {Record<string, string>} env
 * @returns {Promise<Record<string, any>>} the projects, by name
 */
async function projectsUnder(env) {
    for (const name of OPT_INS) {
        delete process.env[name];
    }
    Object.assign(process.env, { E2E_BASE_URL: 'https://localhost:8443' }, env);
    vi.resetModules();
    const config = (await import('../e2e/playwright.config.js')).default;
    return Object.fromEntries(config.projects.map((project) => [project.name, project]));
}

afterEach(() => {
    for (const name of OPT_INS) {
        if (saved[name] === undefined) {
            delete process.env[name];
        } else {
            process.env[name] = saved[name];
        }
    }
});

describe('playwright.config.js launch arguments', () => {
    it('makes the scan\'s browser accept certificates during the handshake, in both projects', async () => {
        const projects = await projectsUnder({
            E2E_PROXY_SERVER: 'http://127.0.0.1:8090',
            E2E_IGNORE_HTTPS_ERRORS: '1',
        });

        for (const name of ['chromium', 'service-worker']) {
            expect(projects[name].use.launchOptions.args).toEqual([
                '--proxy-bypass-list=<-loopback>',
                '--ignore-certificate-errors',
            ]);
            expect(projects[name].use.proxy).toEqual({ server: 'http://127.0.0.1:8090' });
        }
        expect((await import('../e2e/playwright.config.js')).default.use.ignoreHTTPSErrors).toBe(true);
    });

    it('keeps certificate errors fatal when the opt-in is absent', async () => {
        const projects = await projectsUnder({ E2E_PROXY_SERVER: 'http://127.0.0.1:8090' });

        expect(projects.chromium.use.launchOptions.args).toEqual(['--proxy-bypass-list=<-loopback>']);
    });

    it('launches the plain end-to-end run with no override at all', async () => {
        const projects = await projectsUnder({});

        expect(projects.chromium.use.launchOptions).toBeUndefined();
        expect(projects.chromium.use.proxy).toBeUndefined();
        expect((await import('../e2e/playwright.config.js')).default.use.ignoreHTTPSErrors).toBe(false);
    });
});
