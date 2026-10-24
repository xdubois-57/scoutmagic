// Shared end-to-end helper: seed a household whose children share an
// address, through scripts/e2e-support.php seed-shared-address-siblings.
//
// Two siblings in a section of their own, the family address as the Desk
// address of both and as the login of an account, and a confirmed address
// of each child's own — the shape issue #999 is about. No page builds it:
// a Desk import does, and the import scenario runs on its own files. The
// harness writes it into the running instance and hands back what the
// scenario needs to find it again.
import { execFile } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { promisify } from 'node:util';

const execFileAsync = promisify(execFile);
const REPO_ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');

/**
 * @typedef {{ first_name: string, desk_id: string, own_email: string, member_year_id: number }} Sibling
 * @typedef {{ section_name: string, family_email: string, family_password: string, siblings: Sibling[] }} Household
 */

/**
 * Seeds the household and resolves with it.
 *
 * @param {string} stamp [0-9a-z], at most 16 characters — makes every name
 *        and address of this call unique, so a scenario replayed against
 *        the same instance (`--repeat-each`) seeds a second household
 *        rather than colliding with the first
 * @returns {Promise<Household>}
 */
export async function seedSharedAddressSiblings(stamp) {
    const instanceDir = process.env.E2E_INSTANCE_DIR;
    if (!instanceDir) {
        throw new Error(
            'E2E_INSTANCE_DIR is not set. Run the end-to-end tests via `npm run e2e` '
            + '(scripts/e2e.sh), which provisions the instance and exports it.',
        );
    }

    try {
        const { stdout } = await execFileAsync(
            'php',
            [path.join(REPO_ROOT, 'scripts/e2e-support.php'), 'seed-shared-address-siblings', instanceDir, stamp],
            { timeout: 30_000 },
        );

        return JSON.parse(stdout);
    } catch (error) {
        const details = [error.stdout, error.stderr].filter(Boolean).join('\n').trim();
        throw new Error(`seeding the shared-address household failed:\n${details || error.message}`);
    }
}
