// scripts/e2e-summary-reporter.mjs (issue #912): a red browser
// scenario must be readable through the GitHub API, as an annotation, and
// by a person, as a line of the job summary — and nothing is written
// outside GitHub Actions or when everything passed.
import { describe, expect, it } from 'vitest';
import SummaryReporter, { escapeCommand, firstErrorLine, markdownCell } from '../../scripts/e2e-summary-reporter.mjs';

function fakeTest(file, line, titles, outcome = undefined) {
    const test = { location: { file, line }, titlePath: () => titles };
    if (outcome !== undefined) {
        test.outcome = () => outcome;
    }
    return test;
}

function run(env, results) {
    const out = [];
    const appended = [];
    const reporter = new SummaryReporter({
        env,
        rootDir: '/repo',
        write: (s) => out.push(s),
        append: (path, s) => appended.push([path, s]),
    });
    for (const [test, result] of results) {
        reporter.onTestEnd(test, result);
    }
    reporter.onEnd();
    return { out, appended };
}

const red = fakeTest('/repo/tests/e2e/specs/rental-management.spec.js', 84, ['', 'chromium', 'rental-management.spec.js', 'Rentals — the park', 'a chief adds an asset']);
const green = fakeTest('/repo/tests/e2e/specs/login-page.spec.js', 14, ['', 'chromium', 'login-page.spec.js', 'the login page boots']);
const failed = { status: 'failed', errors: [{ message: '\u001b[31mError: expect(page).toHaveURL(expected) failed\u001b[39m\n\nExpected pattern: /x/' }] };

describe('SummaryReporter', () => {
    it('annotates and summarises each failed scenario on GitHub Actions', () => {
        const { out, appended } = run(
            { GITHUB_ACTIONS: 'true', GITHUB_STEP_SUMMARY: '/tmp/summary', GITHUB_JOB: 'dast-passive' },
            [[green, { status: 'passed', errors: [] }], [red, failed]],
        );
        expect(out).toHaveLength(1);
        expect(out[0]).toContain('::error file=tests/e2e/specs/rental-management.spec.js,line=84,');
        expect(out[0]).toContain('Rentals — the park › a chief adds an asset — Error: expect(page).toHaveURL(expected) failed');
        expect(appended).toHaveLength(1);
        expect(appended[0][0]).toBe('/tmp/summary');
        expect(appended[0][1]).toContain('### Failed E2E scenarios (dast-passive)');
        expect(appended[0][1]).toContain('`tests/e2e/specs/rental-management.spec.js:84`');
        expect(appended[0][1]).not.toContain('login-page');
    });

    it('escapes a title so it cannot turn into Markdown', () => {
        const odd = fakeTest('/repo/tests/e2e/specs/y.spec.js', 9, ['', 'chromium', 'y.spec.js', 'a `code` and *stars*']);
        const { appended } = run({ GITHUB_ACTIONS: 'true', GITHUB_STEP_SUMMARY: '/tmp/summary' }, [[odd, failed]]);
        expect(appended[0][1]).toContain('a \\`code\\` and \\*stars\\*');
    });

        it('counts a timeout as a failure', () => {
        const { out } = run({ GITHUB_ACTIONS: 'true' }, [[red, { status: 'timedOut', errors: [{ message: 'Test timeout of 240000ms exceeded.' }] }]]);
        expect(out[0]).toContain('Test timeout of 240000ms exceeded.');
    });

    it('writes nothing when everything passed', () => {
        const { out, appended } = run({ GITHUB_ACTIONS: 'true', GITHUB_STEP_SUMMARY: '/tmp/summary' }, [[green, { status: 'passed', errors: [] }]]);
        expect(out).toEqual([]);
        expect(appended).toEqual([]);
    });

    it('writes nothing outside GitHub Actions', () => {
        const { out, appended } = run({ GITHUB_STEP_SUMMARY: '/tmp/summary' }, [[red, failed]]);
        expect(out).toEqual([]);
        expect(appended).toEqual([]);
    });

    it('keeps one line per scenario when an attempt is repeated', () => {
        const { out } = run({ GITHUB_ACTIONS: 'true' }, [[red, failed], [red, failed]]);
        expect(out).toHaveLength(1);
    });

    it('reports nothing for a test that failed then passed on retry', () => {
        const flaky = fakeTest('/repo/tests/e2e/specs/x.spec.js', 3, ['', 'chromium', 'x.spec.js', 'flaky'], 'flaky');
        const { out } = run({ GITHUB_ACTIONS: 'true' }, [[flaky, failed], [flaky, { status: 'passed', errors: [] }]]);
        expect(out).toEqual([]);
    });

    it('reports nothing for a test expected to fail', () => {
        const expected = fakeTest('/repo/tests/e2e/specs/x.spec.js', 3, ['', 'chromium', 'x.spec.js', 'fails on purpose'], 'expected');
        const { out } = run({ GITHUB_ACTIONS: 'true' }, [[expected, failed]]);
        expect(out).toEqual([]);
    });

    it('trusts the final outcome when Playwright gives one', () => {
        const unexpected = fakeTest('/repo/tests/e2e/specs/x.spec.js', 3, ['', 'chromium', 'x.spec.js', 'broken'], 'unexpected');
        const { out } = run({ GITHUB_ACTIONS: 'true' }, [[unexpected, failed]]);
        expect(out).toHaveLength(1);
    });
});

describe('helpers', () => {
    it('keeps the first non-empty line, without colour codes, capped', () => {
        expect(firstErrorLine({ errors: [{ message: '\n\u001b[2m  TimeoutError: waited\u001b[22m\nmore' }] })).toBe('TimeoutError: waited');
        expect(firstErrorLine({ errors: [] })).toBe('');
        expect(firstErrorLine({ errors: [{ value: 'a thrown string' }] })).toBe('a thrown string');
        expect(firstErrorLine({ errors: [{ message: 'x'.repeat(500) }] })).toHaveLength(200);
    });

    it('escapes Markdown, backslashes first', () => {
        expect(markdownCell('a\\b|c`d*e_f')).toBe('a\\\\b\\|c\\`d\\*e\\_f');
    });

    it('escapes workflow command characters', () => {
        expect(escapeCommand('a%b\nc')).toBe('a%25b%0Ac');
        expect(escapeCommand('a:b,c', true)).toBe('a%3Ab%2Cc');
    });
});
