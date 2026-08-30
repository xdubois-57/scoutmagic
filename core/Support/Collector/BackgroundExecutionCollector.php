<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Support\Collector;

use Core\Scheduler\SchedulerContinuation;
use Core\Support\SupportCollectorContext;
use Core\Support\SupportCollectorInterface;
use Core\System\ShellExecutor;

/**
 * `background-execution.txt` — how background work actually gets executed
 * on this installation, and how often it actually has been.
 *
 * `scheduled-tasks.xlsx` answers "what is queued and what ran"; this
 * answers the question underneath it, which is the one that explains a
 * queue that is not draining: **is anything driving the scheduler at
 * all, and how often?**
 *
 * Nothing here is a setting somebody chose. The cadence is measured from
 * `scheduled_actions.executed_at` — the gaps between distinct moments at
 * which work actually happened — because that is the only honest source:
 * whether a real cron entry exists is invisible from inside PHP, and a
 * site with no cron at all still advances its queue on visits, slowly and
 * irregularly. A median gap of a few minutes says something is driving it
 * steadily; a maximum gap of six hours on a site with a nightly cron says
 * the cron is not firing, whatever the hosting panel claims.
 *
 * The rest is the machinery each mechanism needs, reported as present or
 * absent rather than assumed: `fastcgi_finish_request` (whether the
 * poor-man's cron makes a visitor wait), `stream_socket_client` (whether
 * a self-continuation hop can be emitted at all), and shell execution,
 * demonstrated rather than declared (`ShellExecutor::probe()`).
 *
 * **The continuation secret is reported as present or absent and never
 * printed.** It authenticates a request to this site's own scheduler
 * endpoint; a token in a support package is a token in every mailbox that
 * package passes through (`Core\Security\CapabilityToken`, contract point
 * 2). `base_url` is printed, because it is the site's public address and
 * the archive already carries it in a dozen places.
 */
class BackgroundExecutionCollector implements SupportCollectorInterface
{
    /** Same window as the event journal and the task volumes, so the three read together. */
    private const WINDOW_HOURS = 48;

    /**
     * Two executions closer together than this are one scheduler pass, not
     * two: a pass runs its whole due list in a few hundred milliseconds.
     * The interesting number is the gap BETWEEN passes.
     */
    private const SAME_PASS_SECONDS = 30;

    public function name(): string
    {
        return 'background_execution';
    }

    public function collect(SupportCollectorContext $context): void
    {
        $lines = [];
        $lines[] = '# Exécution en arrière-plan';
        $lines[] = '#';
        $lines[] = '# scheduled-tasks.xlsx dit ce qui est en file et ce qui a tourné.';
        $lines[] = '# Ce fichier-ci dit si quelque chose entraîne l\'ordonnanceur, et à quel rythme.';
        $lines[] = '';

        $this->mechanisms($lines);
        $this->cadence($lines, $context);
        $this->backlog($lines, $context);
        $this->settings($lines, $context);

        $context->addFileFromContent('background-execution.txt', implode("\n", $lines) . "\n");
    }

    /**
     * @param array<int, string> $lines
     */
    private function mechanisms(array &$lines): void
    {
        $shell = ShellExecutor::probe();

        $lines[] = '## Mécanismes disponibles';
        $lines[] = 'fastcgi_finish_request : ' . (function_exists('fastcgi_finish_request') ? 'oui' : 'non')
            . ' — sans elle, l\'ordonnanceur déclenché par une visite fait attendre ce visiteur';
        $lines[] = 'stream_socket_client : ' . (function_exists('stream_socket_client') ? 'oui' : 'non')
            . ' — sans elle, aucun saut de continuation ne peut être émis';
        $lines[] = 'Exécution shell vérifiée : ' . ($shell['works'] ? 'oui' : 'NON')
            . ' (' . ($shell['function'] ?? 'aucune fonction') . ' — ' . $shell['detail'] . ')';
        $lines[] = 'SAPI : ' . PHP_SAPI;
        $lines[] = 'max_execution_time : ' . (string) ini_get('max_execution_time') . ' s';
        $lines[] = '';
        $lines[] = 'Note : l\'existence d\'une vraie tâche cron système n\'est pas observable depuis PHP.';
        $lines[] = 'La cadence mesurée ci-dessous est la seule réponse honnête à « le cron tourne-t-il ? ».';
        $lines[] = '';
    }

    /**
     * The measured answer. Executions are grouped into passes, and it is
     * the gaps between passes that say whether anything is driving the
     * scheduler on a schedule or whether it is coasting on visits.
     *
     * @param array<int, string> $lines
     */
    private function cadence(array &$lines, SupportCollectorContext $context): void
    {
        $lines[] = '## Cadence réelle (' . self::WINDOW_HOURS . ' dernières heures)';

        try {
            $stmt = $context->pdo()->prepare(
                'SELECT executed_at FROM scheduled_actions
                 WHERE executed_at IS NOT NULL AND executed_at >= :since
                 ORDER BY executed_at ASC'
            );
            $stmt->execute([
                ':since' => (new \DateTimeImmutable('-' . self::WINDOW_HOURS . ' hours'))->format('Y-m-d H:i:s'),
            ]);
            /** @var array<int, string> $timestamps */
            $timestamps = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        } catch (\PDOException $e) {
            $lines[] = 'non mesurable : ' . $context->redact($e->getMessage());
            $lines[] = '';

            return;
        }

        if ($timestamps === []) {
            $lines[] = 'Aucune tâche n\'a été exécutée dans la fenêtre.';
            $lines[] = 'C\'est le symptôme d\'un ordonnanceur que rien n\'entraîne — ou d\'une file vide.';
            $lines[] = 'Le nombre de tâches en retard, ci-dessous, tranche entre les deux.';
            $lines[] = '';

            return;
        }

        $passes = $this->passStartTimes($timestamps);
        $lines[] = 'Exécutions : ' . count($timestamps) . ' réparties sur ' . count($passes) . ' passe(s)';
        $lines[] = 'Première : ' . date('Y-m-d H:i:s', $passes[0]);
        $lines[] = 'Dernière : ' . date('Y-m-d H:i:s', $passes[count($passes) - 1]);

        $gaps = [];
        for ($i = 1, $n = count($passes); $i < $n; $i++) {
            $gaps[] = $passes[$i] - $passes[$i - 1];
        }

        if ($gaps === []) {
            $lines[] = 'Une seule passe dans la fenêtre : pas d\'intervalle mesurable.';
            $lines[] = '';

            return;
        }

        sort($gaps);
        $lines[] = 'Intervalle médian entre deux passes : ' . $this->duration($gaps[intdiv(count($gaps), 2)]);
        $lines[] = 'Intervalle le plus long : ' . $this->duration($gaps[count($gaps) - 1]);
        $lines[] = 'Intervalle le plus court : ' . $this->duration($gaps[0]);
        $lines[] = '';
    }

    /**
     * Executions within SAME_PASS_SECONDS of each other are one pass.
     *
     * @param array<int, string> $timestamps ascending
     * @return array<int, int> pass start times, as unix timestamps
     */
    private function passStartTimes(array $timestamps): array
    {
        $passes = [];
        $previous = null;

        foreach ($timestamps as $timestamp) {
            $moment = strtotime($timestamp);
            if ($moment === false) {
                continue;
            }
            if ($previous === null || ($moment - $previous) > self::SAME_PASS_SECONDS) {
                $passes[] = $moment;
            }
            $previous = $moment;
        }

        return $passes;
    }

    /**
     * The backlog is what turns a cadence into a verdict: a slow scheduler
     * with nothing waiting is not a problem, and a fast one with a task
     * three days overdue is.
     *
     * @param array<int, string> $lines
     */
    private function backlog(array &$lines, SupportCollectorContext $context): void
    {
        $lines[] = '## File en retard';

        try {
            $overdue = $context->pdo()->prepare(
                "SELECT COUNT(*) AS n, MIN(run_at) AS oldest
                 FROM scheduled_actions WHERE status = 'pending' AND run_at <= NOW()"
            );
            $overdue->execute();
            /** @var array<string, mixed>|false $row */
            $row = $overdue->fetch(\PDO::FETCH_ASSOC);

            $processing = $context->pdo()->prepare(
                "SELECT COUNT(*) FROM scheduled_actions WHERE status = 'processing'"
            );
            $processing->execute();
            $stuck = (int) $processing->fetchColumn();
        } catch (\PDOException $e) {
            $lines[] = 'non mesurable : ' . $context->redact($e->getMessage());
            $lines[] = '';

            return;
        }

        $count = (int) (is_array($row) ? ($row['n'] ?? 0) : 0);
        $lines[] = 'Tâches dues et non traitées : ' . $count;

        $oldest = is_array($row) ? ($row['oldest'] ?? null) : null;
        if ($count > 0 && is_string($oldest)) {
            $age = time() - (int) strtotime($oldest);
            $lines[] = 'La plus ancienne attend depuis : ' . $this->duration(max(0, $age)) . ' (due le ' . $oldest . ')';
        }

        // 'processing' is a claimed row nothing ever re-claims: a non-zero
        // count here means a pass died mid-task, and those rows will never
        // run again on their own.
        $lines[] = 'Tâches bloquées en « processing » : ' . $stuck
            . ($stuck > 0 ? ' — une passe est morte en cours de route ; rien ne les reprendra seul' : '');
        $lines[] = '';
    }

    /**
     * @param array<int, string> $lines
     */
    private function settings(array &$lines, SupportCollectorContext $context): void
    {
        $settings = $context->settings();

        $baseUrl = trim((string) ($settings->get('base_url') ?? ''));
        $maxHops = (string) ($settings->get(SchedulerContinuation::MAX_HOPS_SETTING) ?? '(non défini)');

        $lines[] = '## Réglages de l\'ordonnanceur';
        $lines[] = 'base_url : ' . ($baseUrl === '' ? '(vide — aucun saut ne peut partir)' : $baseUrl);
        $lines[] = 'Budget d\'une tranche (' . SchedulerContinuation::BUDGET_SETTING . ') : '
            . (string) ($settings->get(SchedulerContinuation::BUDGET_SETTING) ?? '(non défini)') . ' s';
        $lines[] = 'Plafond de sauts (' . SchedulerContinuation::MAX_HOPS_SETTING . ') : ' . $maxHops
            . ($maxHops === '0' ? ' — la continuation est DÉSACTIVÉE sur cette installation' : '');
        $lines[] = 'Compteur de sauts courant (' . SchedulerContinuation::HOPS_SETTING . ') : '
            . (string) ($settings->get(SchedulerContinuation::HOPS_SETTING) ?? '(non défini)');

        // Presence only, never the value: this authenticates a request to
        // the site's own scheduler endpoint, and this archive is emailed.
        $lines[] = 'Secret de continuation : ' . ($this->hasContinuationSecret($context) ? 'présent' : 'ABSENT — aucun saut ne peut être authentifié');
        $lines[] = '';
    }

    private function hasContinuationSecret(SupportCollectorContext $context): bool
    {
        try {
            $secrets = (new \Core\Security\SecretManager(
                $context->storagePath() . '/keys/master.key',
                $context->storagePath() . '/config/secrets.enc'
            ))->readSecrets();

            return trim((string) ($secrets['scheduler_continuation_secret'] ?? '')) !== '';
        } catch (\Throwable) {
            return false;
        }
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
