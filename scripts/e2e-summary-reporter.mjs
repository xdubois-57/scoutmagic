// Names the red scenarios where the GitHub API can read them (issue #912).
//
// A failed browser run used to say only « exit code 1 » to anything that
// reads a job through the API: the scenario, its line and its error lived
// in the raw log and the Playwright report, both served from a storage host
// an agent session cannot reach. Diagnosing #912 took a person pasting the
// log by hand. This reporter writes, for every failed or timed-out test:
//
// - one `::error file=…,line=…::` workflow command, which GitHub turns into
//   an annotation on the job's check run (GET /check-runs/{id}/annotations);
// - one line in the job summary ($GITHUB_STEP_SUMMARY), under a heading
//   naming the job, read by a person opening the run.
//
// Outside GitHub Actions it does nothing: a developer's terminal already
// has the 'list' reporter. It runs for both browser jobs, `End-to-end
// (browser)` and the two DAST profiles, because scripts/dast.sh passes the
// environment through to Playwright unchanged.

import { appendFileSync } from 'node:fs';
import { relative } from 'node:path';

const FAILED = new Set(['failed', 'timedOut', 'interrupted']);

/**
 * The first line of the first error, without ANSI colour codes and capped:
 * enough to tell a timeout from an assertion, short enough for one line.
 *
 * @param {{ errors?: Array<{ message?: string, value?: string }> }} result
 * @returns {string}
 */
export function firstErrorLine(result) {
    // A thrown non-Error (a string, an object) carries its text in `value`.
    const error = result.errors?.[0];
    const message = error?.message ?? error?.value ?? '';
    // eslint-disable-next-line no-control-regex
    const line = message.replace(/\u001b\[[0-9;]*m/g, '').split('\n').map((l) => l.trim()).find((l) => l !== '') ?? '';
    return line.length > 200 ? `${line.slice(0, 199)}…` : line;
}

/**
 * Workflow commands treat `%`, CR and LF specially in a message, and also
 * `,` and `:` in a property.
 *
 * @param {string} value
 * @param {boolean} property
 * @returns {string}
 */
export function escapeCommand(value, property = false) {
    let escaped = value.replace(/%/g, '%25').replace(/\r/g, '%0D').replace(/\n/g, '%0A');
    if (property) {
        escaped = escaped.replace(/:/g, '%3A').replace(/,/g, '%2C');
    }
    return escaped;
}

/**
 * Text placed in the Markdown summary: backslashes first, then the
 * characters Markdown would read as markup.
 *
 * @param {string} value
 * @returns {string}
 */
export function markdownCell(value) {
    return value.replace(/\\/g, '\\\\').replace(/([|`*_])/g, '\\$1');
}

export default class SummaryReporter {
    /** @param {{ rootDir?: string, env?: Record<string, string|undefined>, write?: (s: string) => void, append?: (path: string, s: string) => void }} [options] */
    constructor(options = {}) {
        this.env = options.env ?? process.env;
        this.rootDir = options.rootDir ?? process.cwd();
        this.write = options.write ?? ((s) => process.stdout.write(s));
        this.append = options.append ?? ((path, s) => appendFileSync(path, s));
        /** @type {Map<object, { status: string, errors?: Array<{ message?: string, value?: string }> }>} */
        this.lastResults = new Map();
    }

    onTestEnd(test, result) {
        // Keep only the LAST attempt of each test; whether it counts as a
        // failure is decided at the end, from the test's final outcome, so a
        // test that fails then passes on retry (« flaky ») or is expected to
        // fail is never reported.
        this.lastResults.set(test, result);
    }

    /** @returns {Array<{ title: string, file: string, line: number, error: string }>} */
    failures() {
        const failures = [];
        for (const [test, result] of this.lastResults) {
            const failed = typeof test.outcome === 'function' ? test.outcome() === 'unexpected' : FAILED.has(result.status);
            if (!failed) {
                continue;
            }
            failures.push({
                // titlePath() is [root, project, file, …describes, test]: keep the describes and the test.
                title: test.titlePath().filter((t) => t !== '').slice(2).join(' › '),
                file: relative(this.rootDir, test.location.file).split('\\').join('/'),
                line: test.location.line,
                error: firstErrorLine(result),
            });
        }
        return failures;
    }

    onEnd() {
        const failures = this.env.GITHUB_ACTIONS === 'true' ? this.failures() : [];
        if (failures.length === 0) {
            return;
        }
        for (const f of failures) {
            this.write(`::error file=${escapeCommand(f.file, true)},line=${f.line},title=${escapeCommand('E2E scenario failed', true)}::${escapeCommand(`${f.title} — ${f.error}`)}\n`);
        }
        const summaryPath = this.env.GITHUB_STEP_SUMMARY;
        if (summaryPath) {
            const job = this.env.GITHUB_JOB ? ` (${this.env.GITHUB_JOB})` : '';
            const lines = failures.map((f) => `- \`${f.file}:${f.line}\` — ${markdownCell(f.title)}\n  - ${markdownCell(f.error)}`);
            this.append(summaryPath, `### Failed E2E scenarios${job}\n\n${lines.join('\n')}\n\n`);
        }
    }

    printsToStdio() {
        return false;
    }
}
