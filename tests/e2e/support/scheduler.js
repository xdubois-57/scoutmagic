// Shared end-to-end helper: run the instance's own scheduler once.
//
// Some features finish AFTER the response. A gallery upload stores the
// file and queues `gallery`/`process_photo`; until that task runs there
// are no renditions, so the album shows a spinner and there is nothing to
// download. Nothing turns that queue on its own here: public/cron.php is
// the one engine an installation has, and this throwaway instance has no
// crontab pointed at it.
//
// So the scenario turns it deliberately, through the instance's real
// public/cron.php (scripts/e2e-support.php run-scheduler): the same entry
// point a host's crontab calls, the same handlers, the same instance
// config. Nothing here fakes a task or writes a rendition by hand. A
// scenario that needs the queue to have advanced MUST call this — that
// used to be optional, back when the application drove its own queue on
// the tail of every web request.
import { execFile } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { promisify } from 'node:util';

const execFileAsync = promisify(execFile);
const REPO_ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../..');

/**
 * Processes every overdue task once and resolves when they are done.
 *
 * @returns {Promise<string>} whatever the run printed — a failing task
 *          handler's message is the only clue the caller would otherwise
 *          get, so it is returned rather than swallowed.
 */
export async function runScheduler() {
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
            [path.join(REPO_ROOT, 'scripts/e2e-support.php'), 'run-scheduler', instanceDir],
            { timeout: 120_000 },
        );

        return stdout;
    } catch (error) {
        // Whatever the run printed is the only clue about WHY a task
        // handler refused — an exec() rejection alone says "exit code 1".
        const details = [error.stdout, error.stderr].filter(Boolean).join('\n').trim();
        throw new Error(`the scheduler run failed:\n${details || error.message}`);
    }
}
