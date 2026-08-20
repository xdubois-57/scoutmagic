// Isolated JavaScript unit test — jsdom DOM only, no PHP/DB/network.
//
// The receipts and mass-mail list pages build table rows in an inline
// <script> and interpolate escapeHtml(...) output into alt=/title=/data-*
// attributes. A textContent->innerHTML escaper handles & < > but NOT quotes,
// so a filename or description containing a double quote used to break out of
// the attribute (audit M16). This test reads the REAL escapeHtml definition
// out of each template source and proves a quote can no longer break out.
import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));

/**
 * Pull the `function escapeHtml(...) { ... }` definition out of a template
 * file and return a live function, so the test exercises the shipped source
 * rather than a re-implementation.
 */
function loadEscapeHtml(relativePath) {
    const source = readFileSync(resolve(here, '../../', relativePath), 'utf8');
    const match = source.match(/function escapeHtml\s*\([^)]*\)\s*\{[\s\S]*?\n\}/);
    if (match === null) {
        throw new Error(`No escapeHtml() found in ${relativePath}`);
    }
    // eslint-disable-next-line no-new-func
    return new Function(`${match[0]}\nreturn escapeHtml;`)();
}

const templates = {
    'finance receipts list': 'modules/finance/views/receipts/list.html.twig',
    'mass_mail list': 'modules/mass_mail/views/list.html.twig',
};

describe('inline escapeHtml() in attribute-building templates', () => {
    for (const [label, path] of Object.entries(templates)) {
        describe(label, () => {
            const escapeHtml = loadEscapeHtml(path);

            it('escapes double and single quotes', () => {
                expect(escapeHtml('a"b')).not.toContain('"');
                expect(escapeHtml("a'b")).not.toContain("'");
                expect(escapeHtml('a"b')).toContain('&quot;');
                expect(escapeHtml("a'b")).toContain('&#39;');
            });

            it('still escapes the angle brackets and ampersand', () => {
                expect(escapeHtml('<b>&</b>')).toBe('&lt;b&gt;&amp;&lt;/b&gt;');
            });

            it('a quote in the value cannot break out of a double-quoted attribute', () => {
                const payload = '" onmouseover="alert(1)';
                const span = document.createElement('span');
                span.innerHTML = `<i title="${escapeHtml(payload)}"></i>`;
                const i = span.querySelector('i');

                expect(i.hasAttribute('onmouseover')).toBe(false);
                expect(i.attributes.length).toBe(1);
                expect(i.getAttribute('title')).toBe(payload);
            });

            it('handles null/undefined without throwing', () => {
                expect(escapeHtml(null)).toBe('');
                expect(escapeHtml(undefined)).toBe('');
            });
        });
    }
});
