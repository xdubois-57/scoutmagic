<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Retro\Task;

use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Modules\LlmConnector\Api\LlmConnectorInterface;
use Modules\Retro\Repository\BoardRepository;
use Modules\Retro\Repository\CommentRepository;
use Modules\Retro\Service\RetroException;
use Modules\Retro\Service\SummaryService;

/**
 * Scheduled by Service\BoardService::create()/update() (reference
 * "board_{id}") for a board's configured auto-close delay. Idempotent: a
 * chief may have already closed the board manually before this runs, so it
 * always re-checks the board is still open before doing anything — never a
 * raw unconditional close.
 *
 * Deliberately does not go through Service\BoardService (whose constructor
 * needs MemberService/SectionService/SchedulerService/ShortUrlService —
 * all irrelevant to auto-closing) — a small, self-contained duplication of
 * the close+optional-summary logic instead, same "small tolerated
 * duplication" convention as Core\Maintenance's purge-beyond-limit helpers.
 * The connector is a capability (TaskContext::getOptional(),
 * ARCHITECTURE.md §7.5 on the scheduled path): null — module absent or
 * disabled — means the board still closes and notifies, only the AI
 * summary is skipped.
 */
class AutoCloseHandler implements TaskHandlerInterface
{
    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $boardId = (int) ($payload['board_id'] ?? 0);
        if ($boardId <= 0) {
            return;
        }

        $pdo = $context->connection->getPdo();
        $boardRepository = new BoardRepository($pdo, $context->encryption);
        $commentRepository = new CommentRepository($pdo);

        $board = $boardRepository->findById($boardId);
        if ($board === null || !$board->isOpen()) {
            return;
        }

        $boardRepository->close($boardId);
        $context->journal->log('retro', 'board_auto_closed', 'info', 'Rétrospective clôturée automatiquement', ['board_id' => $boardId]);

        $llmConnector = $context->getOptional(LlmConnectorInterface::class);
        $summaryService = $llmConnector !== null ? new SummaryService($llmConnector) : null;

        $visible = array_values(array_filter($commentRepository->findByBoardId($boardId), fn($c) => !$c->hidden));

        if ($summaryService !== null && $summaryService->isAvailable()) {
            try {
                $summary = $summaryService->generate($visible);
                if ($summary !== '') {
                    $boardRepository->setAiSummary($boardId, $summary);
                }
            } catch (RetroException $e) {
                $context->journal->log('retro', 'board_summary_failed', 'info', 'Échec de la synthèse IA', ['board_id' => $boardId, 'error' => $e->getMessage()]);
            }
        }

        $refreshed = $boardRepository->findById($boardId);
        if ($refreshed !== null && $refreshed->closeNotifyEnabled && $refreshed->closeNotifyEmail !== null) {
            $this->sendCloseNotification($refreshed, $refreshed->closeNotifyEmail, $visible, $context);
        }
    }

    /**
     * Same best-effort close-notification email as Service\BoardService::
     * close() — duplicated here (not shared) for the same self-contained-
     * handler reason as the rest of this class's docblock. TaskContext
     * exposes no Twig environment, so a standalone one is built here,
     * pointed at the same template roots the real composition root uses.
     * A failure is journal-logged, same 'board_close_notification_failed'
     * event as the manual-close path — the swallow was previously silent.
     *
     * @param array<int, \Modules\Retro\Repository\Comment> $visibleComments
     */
    private function sendCloseNotification(\Modules\Retro\Repository\Board $board, string $email, array $visibleComments, TaskContext $context): void
    {
        $repoRoot = dirname(__DIR__, 4);
        // Through TwigFactory like every other handler that sends an
        // e-mail: a hand-built Environment escaped the `.text.twig` half
        // as if it were HTML, so a word containing an apostrophe reached
        // the plain-text part as « c&#039;est » (Tests\Core\View\
        // PlainTextEmailEscapingTest).
        $twig = \Core\View\TwigFactory::create(
            $repoRoot . '/core/View/templates',
            false,
            ['retro' => $repoRoot . '/modules/retro/views']
        );

        $baseUrl = rtrim((string) ($context->settings->get('base_url') ?: ''), '/');
        $publicUrl = $board->shortCode !== null ? '/s/' . $board->shortCode : '/r/' . $board->token;

        $twigContext = [
            'site_name' => (string) ($context->settings->get('site_name') ?: 'Unité scoute'),
            'board_title' => $board->title,
            'board_date' => $board->boardDate,
            'board_content' => \Modules\Retro\Service\BoardEmailSummary::fromComments($visibleComments),
            'ai_summary' => $board->aiSummary ?? '',
            'board_url' => $baseUrl . $publicUrl,
        ];

        // Core's templates plus this module's own: a handler runs outside
        // the composition root, so nothing has aggregated the manifests
        // for it (ARCHITECTURE.md §8.7bis). A customisation is honoured
        // all the same — that lives in the database, not in the registry.
        $registry = new \Core\Mail\Template\EmailTemplateRegistry();
        $registry->registerModuleManifest(
            \Core\Module\ModuleManifest::fromFile($repoRoot . '/modules/retro/module.json')
        );
        $renderer = new \Core\Mail\Template\EmailTemplateRenderer(
            $twig,
            $registry,
            new \Core\Mail\Template\EmailTemplateOverrideRepository($context->connection->getPdo()),
            $context->journal
        );

        try {
            $rendered = $renderer->render('retro.board_closed', $twigContext);

            $context->mailService->send(
                to: $email,
                subject: $rendered->subject,
                bodyHtml: $rendered->bodyHtml,
                bodyText: $rendered->bodyText
            );
        } catch (\Throwable $e) {
            $context->journal->log(
                'retro',
                'board_close_notification_failed',
                'info',
                'Échec de l\'envoi de la notification de clôture',
                ['board_id' => $board->id, 'error' => $e->getMessage()]
            );
        }
    }
}
