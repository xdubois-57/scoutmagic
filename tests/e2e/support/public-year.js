// Shared end-to-end helper: put the site's public scout year on a given
// label, through scripts/e2e-support.php set-public-year.
//
// The one piece of state a scenario cannot drive through a page: the site
// moves its public year FORWARDS only, by the « Année scoute » switch, and
// that switch is guarded (staff year on the target, a successful import,
// no open registration). A scenario that needs the public year somewhere
// else — specs/desk-import-years.spec.js, whose rule is anchored on it —
// asks the harness, which writes the same setting
// Core\ScoutYear\ScoutYearAdminService writes, and puts it back after.
import { execFile } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { promisify } from 'node:util';

const execFileAsync = promisify(execFile);
const REPO_ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');

/**
 * Makes $label the public year (creating the year when it does not exist)
 * and resolves with its id.
 *
 * @param {string} label « 2025-2026 »
 * @returns {Promise<number>}
 */
export async function setPublicYear(label) {
    if (!process.env.E2E_DB_NAME) {
        throw new Error(
            'E2E_DB_NAME is not set. Run the end-to-end tests via `npm run e2e` '
            + '(scripts/e2e.sh), which provisions the database and exports it.',
        );
    }

    try {
        const { stdout } = await execFileAsync(
            'php',
            [path.join(REPO_ROOT, 'scripts/e2e-support.php'), 'set-public-year', label],
            { timeout: 30_000 },
        );
        const id = Number.parseInt(stdout.trim(), 10);
        if (!Number.isInteger(id) || id <= 0) {
            throw new Error(`set-public-year printed no year id: « ${stdout.trim()} »`);
        }

        return id;
    } catch (error) {
        const details = [error.stdout, error.stderr].filter(Boolean).join('\n').trim();
        throw new Error(`setting the public year to ${label} failed:\n${details || error.message}`);
    }
}

/**
 * The label of the year before $label: « 2026-2027 » → « 2025-2026 ».
 *
 * @param {string} label
 * @returns {string}
 */
export function previousYearLabel(label) {
    const start = Number.parseInt(label.slice(0, 4), 10);

    return `${start - 1}-${start}`;
}
