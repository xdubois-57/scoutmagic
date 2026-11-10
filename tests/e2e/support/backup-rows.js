// Shared end-to-end helper: two backup rows of this server, through
// scripts/e2e-support.php seed-backup-rows (#847) — one whole, one whose
// archive left the disk, which no page can produce short of a restore.
import { execFile } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { promisify } from 'node:util';

const execFileAsync = promisify(execFile);
const REPO_ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');

/**
 * @returns {Promise<{ present: number, missing: number }>} the two backup ids
 */
export async function seedBackupRows() {
    const instanceDir = process.env.E2E_INSTANCE_DIR;
    if (!instanceDir) {
        throw new Error(
            'E2E_INSTANCE_DIR is not set. Run the end-to-end tests via `npm run e2e` '
            + '(scripts/e2e.sh), which provisions the instance and exports it.',
        );
    }
    const { stdout } = await execFileAsync(
        'php',
        [path.join(REPO_ROOT, 'scripts/e2e-support.php'), 'seed-backup-rows', instanceDir],
        { cwd: REPO_ROOT },
    );

    return JSON.parse(stdout.trim());
}
