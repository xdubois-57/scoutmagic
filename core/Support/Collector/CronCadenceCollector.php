<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Support\Collector;

use Core\Scheduler\CronHealth;
use Core\Scheduler\CronRunHistory;
use Core\Scheduler\CronStatus;
use Core\Support\SupportCollectorContext;
use Core\Support\SupportCollectorInterface;

/**
 * `cron-cadence.txt` — is a real cron driving this installation, how often,
 * and how late do tasks actually run.
 *
 * The two stamps this reads have existed for a long time and were reported
 * nowhere: `cron_last_run`, written only by `public/cron.php`, and
 * `scheduler_last_run`, written by the poor man's cron in
 * `public/index.php` on every web hit. Together they answer the question
 * that decides how a support conversation goes — is anything driving the
 * queue other than visitors? — and separately neither of them answers
 * *how often*, because both are single stamps overwritten on every pass.
 * Hence the ring buffer (`Core\Scheduler\CronRunHistory`), which is the
 * only source of an interval.
 *
 * **The scheduling latency is the reason this file exists at all.** Six
 * production update failures all read "stuck at *migrating* for more than
 * 15 minutes", and the cause was not the migration: it was unrelated,
 * trivial tasks running six minutes after their due time, against a
 * watchdog set at fifteen. That gap was only visible by reading
 * `scheduled-tasks.xlsx` column by column and subtracting two dates by
 * hand. It is now a column.
 *
 * Raw first, derived second, per the archive's format rule: the buffer and
 * the per-task rows are printed in full, and the median/min/max and the
 * verdict sit beside them — never instead of them. A curated summary is
 * exactly what made the original diagnosis take as long as it did.
 */
class CronCadenceCollector implements SupportCollectorInterface
{
    /** Same window as the event journal and the task volumes, so the three read together. */
    private const WINDOW_HOURS = 48;

    /** Beyond this, printing every row stops informing and starts hiding. */
    private const MAX_LATENCY_ROWS = 200;

    public function name(): string
    {
        return 'cron_cadence';
    }

    public function collect(SupportCollectorContext $context): void
    {
        $lines = [];
        $lines[] = '# Cron et cadence';
        $lines[] = '#';
        $lines[] = '# Données brutes d\'abord, valeurs dérivées ensuite : le verdict et les';
        $lines[] = '# intervalles accompagnent le tampon, ils ne le remplacent pas.';
        $lines[] = '';

        $history = $this->stamps($lines, $context);
        $this->cadence($lines, $history);
        $this->latency($lines, $context);

        $context->addFileFromContent('cron-cadence.txt', implode("\n", $lines) . "\n");
    }

    /**
     * @param array<int, string> $lines
     * @return array<int, int>
     */
    private function stamps(array &$lines, SupportCollectorContext $context): array
    {
        $settings = $context->settings();
        $now = time();

        $cronLastRun = (int) ($settings->get('cron_last_run') ?? 0);
        $schedulerLastRun = (int) ($settings->get('scheduler_last_run') ?? 0);
        $history = CronRunHistory::read($settings);

        // The verdict below is Core\Scheduler\CronHealth's, not this
        // collector's own: the same question is asked by the setup gate
        // and by the maintenance page, and three copies of the same two
        // thresholds is how they would come to disagree.
        $status = (new CronHealth($context->storagePath(), $settings))->status($now);

        $lines[] = '## Horodatages bruts';
        $lines[] = 'cron_last_run : ' . $this->stamp($cronLastRun, $now)
            . '  (écrit uniquement par public/cron.php — un vrai crontab)';
        $lines[] = 'scheduler_last_run : ' . $this->stamp($schedulerLastRun, $now)
            . '  (écrit par le pseudo-cron de public/index.php, à chaque visite)';
        $lines[] = 'battement de cœur (storage/' . CronHealth::HEARTBEAT_FILE . ') : '
            . $this->stamp($status->lastHeartbeatAt ?? 0, $now)
            . '  (écrit tout en haut de public/cron.php, avant même l\'autoloader)';
        $lines[] = '';

        $lines[] = '## Tampon circulaire des ' . CronRunHistory::MAX_ENTRIES . ' derniers passages de public/cron.php';
        if ($history === []) {
            $lines[] = '(vide)';
            $lines[] = 'Un tampon vide avec un cron_last_run récent signifie que le cron tourne mais que';
            $lines[] = 'cette version du code n\'était pas encore installée à son dernier passage.';
        } else {
            foreach ($history as $stamp) {
                $lines[] = $stamp . '  ' . date('Y-m-d H:i:s', $stamp) . '  (il y a ' . $this->duration($now - $stamp) . ')';
            }
        }
        $lines[] = '';

        $lines[] = '## Verdict';
        $sinceLastSeen = $status->secondsSinceLastSeen();
        if ($status->state === CronStatus::STATE_NEVER) {
            $lines[] = 'VRAI CRON : jamais détecté. public/cron.php n\'a jamais tourné sur cette installation.';
            $lines[] = 'La file n\'avance donc que sur les visites (pseudo-cron), et depuis la continuation';
            $lines[] = 'de l\'ordonnanceur, sur les sauts que celle-ci enchaîne.';
        } elseif ($status->isSilentBeyond(CronHealth::STALE_AFTER_SECONDS)) {
            $lines[] = 'VRAI CRON : configuré mais SILENCIEUX depuis ' . $this->duration((int) $sinceLastSeen) . '.';
            $lines[] = 'Il a tourné par le passé et ne tourne plus — panne d\'hébergeur, tâche supprimée,';
            $lines[] = 'ou chemin PHP devenu invalide.';
            $context->addNote('Le cron réel n\'a pas tourné depuis ' . $this->duration((int) $sinceLastSeen) . '.');
        } else {
            $lines[] = 'VRAI CRON : détecté et actif (dernier passage il y a ' . $this->duration((int) $sinceLastSeen) . ').';
        }

        // A heartbeat with no cron_last_run behind it is its own diagnosis,
        // and the one a single stamp could never give: the crontab DOES
        // fire, and the pass dies before it reaches the database.
        if ($status->lastHeartbeatAt !== null && $cronLastRun === 0) {
            $lines[] = 'ATTENTION : le crontab se déclenche (battement de cœur présent) mais aucun passage';
            $lines[] = 'complet n\'a jamais atteint la base de données — le script démarre et échoue ensuite.';
            $context->addNote('Le crontab se déclenche mais aucun passage complet n\'atteint la base.');
        }

        if ($schedulerLastRun === 0) {
            $lines[] = 'PSEUDO-CRON : jamais déclenché.';
        } else {
            $lines[] = 'PSEUDO-CRON : dernier déclenchement il y a ' . $this->duration($now - $schedulerLastRun) . '.';
        }
        $lines[] = '';

        return $history;
    }

    /**
     * @param array<int, string> $lines
     * @param array<int, int> $history
     */
    private function cadence(array &$lines, array $history): void
    {
        $lines[] = '## Intervalles entre passages du cron réel (dérivé du tampon)';

        if (count($history) < 2) {
            $lines[] = 'Moins de deux passages enregistrés : aucun intervalle calculable.';
            $lines[] = '';

            return;
        }

        $gaps = [];
        for ($i = 1, $n = count($history); $i < $n; $i++) {
            $gaps[] = $history[$i] - $history[$i - 1];
        }

        $sorted = $gaps;
        sort($sorted);

        $lines[] = 'Intervalles bruts (s) : ' . implode(', ', $gaps);
        $lines[] = 'Médian : ' . $this->duration($sorted[intdiv(count($sorted), 2)]);
        $lines[] = 'Minimum : ' . $this->duration($sorted[0]);
        $lines[] = 'Maximum : ' . $this->duration($sorted[count($sorted) - 1]);
        $lines[] = '';
    }

    /**
     * Due time versus execution time, per task. The number that mattered.
     *
     * @param array<int, string> $lines
     */
    private function latency(array &$lines, SupportCollectorContext $context): void
    {
        $lines[] = '## Latence d\'ordonnancement (' . self::WINDOW_HOURS . ' dernières heures)';
        $lines[] = '# Écart entre l\'instant prévu (run_at) et l\'instant d\'exécution (executed_at).';
        $lines[] = '# C\'est cette latence qui a expliqué six échecs de mise à jour : des tâches';
        $lines[] = '# triviales exécutées six minutes en retard, face à un chien de garde à quinze.';
        $lines[] = '';

        try {
            $statement = $context->pdo()->prepare(
                'SELECT module_id, task_key, run_at, executed_at, status, attempts
                 FROM scheduled_actions
                 WHERE executed_at IS NOT NULL AND executed_at >= :since
                 ORDER BY executed_at DESC
                 LIMIT ' . (self::MAX_LATENCY_ROWS + 1)
            );
            $statement->execute([
                ':since' => (new \DateTimeImmutable('-' . self::WINDOW_HOURS . ' hours'))->format('Y-m-d H:i:s'),
            ]);
            /** @var array<int, array<string, mixed>> $rows */
            $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            $lines[] = 'non mesurable : ' . $context->redact($e->getMessage());
            $lines[] = '';

            return;
        }

        if ($rows === []) {
            $lines[] = 'Aucune tâche exécutée dans la fenêtre.';
            $lines[] = '';

            return;
        }

        // A reader who does not know the list is truncated concludes
        // wrongly — "no late task" and "no late task among the 200 shown"
        // are different sentences. Declared here and in
        // collection-status.json, per the archive's own rule.
        $truncated = count($rows) > self::MAX_LATENCY_ROWS;
        if ($truncated) {
            $rows = array_slice($rows, 0, self::MAX_LATENCY_ROWS);
            $lines[] = '*** TRONQUÉ : seules les ' . self::MAX_LATENCY_ROWS
                . ' exécutions les plus récentes de la fenêtre sont listées. ***';
            $lines[] = '';
            $context->addNote(
                'cron-cadence.txt : latences tronquées à ' . self::MAX_LATENCY_ROWS . ' lignes.'
            );
        }

        $latencies = [];
        $lines[] = sprintf('%-20s %-28s %-20s %-20s %-10s %s', 'module', 'tâche', 'prévu', 'exécuté', 'retard', 'statut');
        foreach ($rows as $row) {
            $runAt = (int) strtotime((string) $row['run_at']);
            $executedAt = (int) strtotime((string) $row['executed_at']);
            $late = max(0, $executedAt - $runAt);
            $latencies[] = $late;

            $lines[] = sprintf(
                '%-20s %-28s %-20s %-20s %-10s %s',
                (string) $row['module_id'],
                (string) $row['task_key'],
                (string) $row['run_at'],
                (string) $row['executed_at'],
                $this->duration($late),
                (string) $row['status'] . ' (' . (int) $row['attempts'] . ' tentative(s))'
            );
        }

        sort($latencies);
        $lines[] = '';
        $lines[] = 'Retard médian : ' . $this->duration($latencies[intdiv(count($latencies), 2)]);
        $lines[] = 'Retard maximum : ' . $this->duration($latencies[count($latencies) - 1]);
        $lines[] = 'Exécutions considérées : ' . count($latencies);
        $lines[] = '';
    }

    private function stamp(int $timestamp, int $now): string
    {
        if ($timestamp <= 0) {
            return '0 (jamais)';
        }

        return $timestamp . ' — ' . date('Y-m-d H:i:s', $timestamp) . ' (il y a ' . $this->duration($now - $timestamp) . ')';
    }

    private function duration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . ' s';
        }
        if ($seconds < 3600) {
            return intdiv($seconds, 60) . ' min ' . ($seconds % 60) . ' s';
        }
        if ($seconds < 86400) {
            return intdiv($seconds, 3600) . ' h ' . intdiv($seconds % 3600, 60) . ' min';
        }

        return intdiv($seconds, 86400) . ' j ' . intdiv($seconds % 86400, 3600) . ' h';
    }
}
