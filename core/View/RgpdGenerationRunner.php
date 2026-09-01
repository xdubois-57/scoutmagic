<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\View;

use Core\Config\SettingService;
use Core\Journal\JournalService;
use Core\Scheduler\SchedulerService;

/**
 * Generating the RGPD document is a background job, and it has to be one.
 *
 * **The measurement, first.** The system prompt carries the whole
 * reference document — `rgpd_default.html`, 135 KB, « couvre TOUS les
 * modules possibles » — which is around 38 000 tokens of input before a
 * single rule is added, and the model is then asked for a ten-section
 * HTML document that can run to 8 192 output tokens, possibly across
 * three sequential calls. Every other LLM call this site makes is
 * between two hundred and eight thousand input tokens and answers in a
 * second or two. This one is an order of magnitude larger, and on a
 * mid-sized model it takes minutes.
 *
 * It was run inside the HTTP request, with a 90-second provider timeout.
 * That timeout is what an administrator saw: « Échec de génération :
 * Timeout lors de l'appel au fournisseur IA », twice in a row, on a
 * request that had not failed so much as not finished. Raising the number
 * would only move the wall: a shared host's front-end proxy cuts a
 * request long before a model finishes writing ten sections, and a
 * browser holding a spinner for three minutes is not a working feature
 * either.
 *
 * So it goes where the other multi-minute jobs of this site already go —
 * a scheduled task the next cron pass picks up, with the page polling for
 * the outcome. The shape is `Modules\Finance\Service\
 * BulkCategorizationService`'s, deliberately: a timestamped running
 * marker that expires on its own, a stored outcome, and one entry point
 * that refuses to stack a second run.
 */
class RgpdGenerationRunner
{
    public const TASK_KEY = 'generate_rgpd_content';

    /** When the current run was queued, as a UNIX timestamp. '0' = none. */
    private const RUNNING_SETTING_KEY = 'rgpd_generation_started_at';
    /** The last run's outcome, as JSON — what the polling endpoint reads. */
    private const OUTCOME_SETTING_KEY = 'rgpd_generation_last_outcome';

    /**
     * Past this, a marker is reported as stale rather than running.
     *
     * A pass killed mid-generation — the host recycling the process, a
     * fatal on a time limit — never reaches the `finally` that clears the
     * marker, and the expiry is then the only thing that frees the button.
     * Generous, because the job it covers is genuinely minutes long: the
     * cost of being too short is a second run started on top of the first.
     */
    private const RUNNING_STALE_AFTER_SECONDS = 1800;

    public function __construct(
        /**
         * The generator itself, deferred.
         *
         * The web side of this class only queues a run and reports on it,
         * and building an `RgpdContentService` — a module manager, an LLM
         * connector, the sub-processor hooks — for a page that will not
         * generate anything is a graph nobody asked for. The task side
         * calls it exactly once.
         *
         * @var \Closure(): RgpdContentService
         */
        private \Closure $rgpdContentService,
        private SettingService $settingService,
        private SchedulerService $schedulerService,
        private EditableContentService $editableContentService,
        private JournalService $journalService
    ) {
    }

    public function isRunning(): bool
    {
        $startedAt = (int) ($this->settingService->get(self::RUNNING_SETTING_KEY) ?: '0');

        return $startedAt > 0 && (time() - $startedAt) < self::RUNNING_STALE_AFTER_SECONDS;
    }

    /**
     * Queue one run. False when one is already in flight — never stacks a
     * second, which on a job this expensive would be two providers' bills
     * for one document and a race over which answer gets saved.
     */
    public function scheduleBackgroundRun(string $prompt, ?int $userAccountId): bool
    {
        if ($this->isRunning()) {
            return false;
        }

        $this->register(self::RUNNING_SETTING_KEY, '0', 'number', 'Génération RGPD en cours depuis');
        $this->settingService->setInternal(self::RUNNING_SETTING_KEY, (string) time());
        $this->storeOutcome(['status' => 'running']);

        $this->schedulerService->scheduleAfter(
            'core',
            self::TASK_KEY,
            0,
            ['prompt' => $prompt],
            null,
            $userAccountId
        );

        return true;
    }

    /**
     * What the page polls: whether a run is in flight, and what the last
     * one came to.
     *
     * @return array{running: bool, status: string, error?: string, content?: string}
     */
    public function status(): array
    {
        $outcome = $this->lastOutcome();
        $running = $this->isRunning();

        if ($running) {
            return ['running' => true, 'status' => 'running'];
        }

        // The marker expired with no outcome written: the pass that held
        // it died. Saying so is better than « en cours » for ever, and
        // better than « terminé » for a document nobody wrote.
        if ($outcome['status'] === 'running') {
            return [
                'running' => false,
                'status' => 'failed',
                'error' => "La génération s'est interrompue sans terminer. Réessayez.",
            ];
        }

        return ['running' => false] + $outcome;
    }

    /**
     * The task handler's entry point: generate, save, record — and clear
     * the marker whatever happens, because a run that crashed must not
     * leave the button disabled for half an hour.
     */
    public function runInBackground(string $prompt, ?int $userAccountId): void
    {
        // max_execution_time is a hard script timeout and not a catchable
        // exception, so it is raised before the work rather than handled
        // after it — the same reasoning as everywhere else this is done.
        // Under the CLI SAPI a cron pass runs with no limit at all and
        // this changes nothing; it is the web-triggered pass that needs it.
        $previousLimit = (int) ini_get('max_execution_time');
        @set_time_limit(self::RUNNING_STALE_AFTER_SECONDS);

        // Registered here as well as in scheduleBackgroundRun(), because
        // this runs in a DIFFERENT process: setInternal() refuses a key
        // nothing declared, and a `finally` that throws would replace the
        // real failure with a SettingException about bookkeeping.
        $this->register(self::RUNNING_SETTING_KEY, '0', 'number', 'Génération RGPD en cours depuis');
        $this->register(self::OUTCOME_SETTING_KEY, '', 'text', 'Dernière génération RGPD');

        try {
            $content = ($this->rgpdContentService)()->generateWithAi($prompt);

            $this->settingService->setInternal('rgpd_generation_mode', 'ai');
            $this->settingService->setInternal('rgpd_custom_prompt', $prompt);
            // `modifiedBy` is an int on purpose: the content table records
            // WHO last wrote a block. A background run has the account that
            // asked for it, and 0 stands for « the site itself » on the one
            // path where nobody did (a task re-queued with no requester).
            $this->editableContentService->set('rgpd.text', $content, 'rich_text', $userAccountId ?? 0);

            $this->storeOutcome(['status' => 'done', 'content' => $content]);
            $this->journalService->log(
                'core',
                'rgpd_content_generated',
                'info',
                'Contenu RGPD généré via IA et enregistré automatiquement',
                ['prompt_length' => strlen($prompt)],
                $userAccountId
            );
        } catch (\Throwable $e) {
            $this->storeOutcome([
                'status' => 'failed',
                // Core\View\RgpdGenerationException's own sentences are
                // written for this administrator and survive; everything
                // else gets the generic one, with the detail in the
                // journal entry below.
                'error' => 'Échec de génération : ' . \Core\Exception\UserFacingMessage::from(
                    $e,
                    'le texte produit n\'a pas pu être traité et n\'a pas été enregistré. Réessayez, ou '
                    . 'simplifiez les instructions personnalisées.'
                ),
            ]);

            $this->journalService->log(
                'core',
                'rgpd_generation_failed',
                'error',
                'Échec de génération du contenu RGPD via IA',
                [
                    'error' => $e->getMessage(),
                    'error_class' => get_class($e),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace_preview' => array_slice($e->getTrace(), 0, 5),
                ],
                $userAccountId
            );
        } finally {
            $this->settingService->setInternal(self::RUNNING_SETTING_KEY, '0');
            @set_time_limit($previousLimit);
        }
    }

    /**
     * @return array{status: string, error?: string, content?: string}
     */
    private function lastOutcome(): array
    {
        $decoded = json_decode((string) ($this->settingService->get(self::OUTCOME_SETTING_KEY) ?? ''), true);

        return is_array($decoded) && is_string($decoded['status'] ?? null)
            ? $decoded
            : ['status' => 'idle'];
    }

    /**
     * @param array<string, mixed> $outcome
     */
    private function storeOutcome(array $outcome): void
    {
        $this->register(self::OUTCOME_SETTING_KEY, '', 'text', 'Dernière génération RGPD');
        $this->settingService->setInternal(self::OUTCOME_SETTING_KEY, (string) json_encode($outcome));
    }

    private function register(string $key, string $default, string $type, string $label): void
    {
        $this->settingService->register(
            $key,
            $default,
            $type,
            $label,
            'Indicateur interne — ne pas modifier.',
            null,
            null,
            null,
            false
        );
    }
}
