// @vitest-environment node
//
// The unhandled-promise check of `npm run typecheck` (scripts/js-floating-
// promises.mjs, issue #944) run on a throwaway project, so what it accepts
// and what it refuses are pinned by example rather than by the state of
// public/assets/js/ on any given day.
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { findFloatingPromises } from '../../scripts/js-floating-promises.mjs';

const SOURCE = `
/** @returns {Promise<number>} */
function load() { return Promise.resolve(1); }
/** @returns {number} */
function plain() { return 1; }

load();                                         // 6: floating
load().then(function (n) { return n; });        // 7: floating, no rejection handler
fetch('/x');                                    // 8: floating
(async function () {})();                       // 9: floating IIFE
navigator.clipboard?.writeText('x');            // 10: floating, optional chain
load().finally(function () {});                 // 11: floating, finally handles nothing

void load();                                    // accepted: deliberately ignored
load().catch(function () {});                   // accepted: catch
load().then(function () {}, function () {});    // accepted: rejection handler
plain();                                        // not a promise
var kept = load();                              // assigned: someone else's
async function run() { await load(); return load(); }
`;

let dir;
let found;

beforeAll(() => {
    dir = mkdtempSync(path.join(tmpdir(), 'floating-promises-'));
    writeFileSync(path.join(dir, 'tsconfig.json'), JSON.stringify({
        compilerOptions: { allowJs: true, checkJs: true, noEmit: true, lib: ['ES2022', 'DOM'], target: 'ES2020' },
        include: ['*.js'],
    }));
    writeFileSync(path.join(dir, 'sample.js'), SOURCE.replace(/^\n/, ''));
    found = findFloatingPromises(path.join(dir, 'tsconfig.json'), () => true);
}, 60000);

afterAll(() => {
    rmSync(dir, { recursive: true, force: true });
});

describe('findFloatingPromises', () => {
    it('reports every promise statement nobody handles, and only those', () => {
        expect(found.map((f) => f.line)).toEqual([6, 7, 8, 9, 10, 11]);
    });

    it('names the statement it reports', () => {
        expect(found[2].text).toContain("fetch('/x')");
        expect(path.basename(found[0].file)).toBe('sample.js');
    });
});
