<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\News\Task;

use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Core\View\TwigFactory;
use Modules\News\Repository\ArticleRepository;
use Modules\News\Repository\FormRepository;
use Modules\News\Repository\FormResponseRepository;
use Modules\News\Service\TicketMailService;

/**
 * The catch-up that raising a form's ticketing switch schedules: every
 * response already recorded gets its reference, and its ticket in the
 * post.
 *
 * **Why a task and not the save itself.** The references are a handful of
 * UPDATEs and the controller does those inline, synchronously, so the
 * door screen is usable the moment the switch is flipped. The e-mails are
 * the slow half — one SMTP round trip per family, and a popular event has
 * a hundred — and an author who ticked a checkbox must not be left
 * watching a spinner for two minutes, nor lose the whole batch to one
 * bounce halfway through.
 *
 * A failure to send is journaled and the batch continues: the response
 * already has its reference, so the ticket also reaches its holder
 * through the responses screen and the door's name search.
 *
 * One-shot, scheduled per form (the reference is the form id), so raising
 * the switch on two forms schedules two runs and neither waits for the
 * other. Nothing re-arms it — the opposite transition needs no work at
 * all, since tickets already issued stay valid.
 *
 * **The payload names the responses, and that is what stops a duplicate.**
 * The controller backfills first and hands over exactly the rows it just
 * gave a reference to. Anyone who answers between the switch being flipped
 * and this run already received their ticket inside their ordinary
 * confirmation, so re-deriving the batch here from "every response of the
 * form" would post them a second one.
 */
class SendPendingTicketsHandler implements TaskHandlerInterface
{
    public const TASK_KEY = 'send_pending_tickets';

    public static function referenceFor(int $formId): string
    {
        return 'form-' . $formId;
    }

    public function handle(array $payload, TaskContext $context): void
    {
        $formId = (int) ($payload['form_id'] ?? 0);
        $responseIds = array_values(array_filter(array_map(
            'intval',
            is_array($payload['response_ids'] ?? null) ? $payload['response_ids'] : []
        )));
        if ($formId <= 0 || $responseIds === []) {
            return;
        }

        $pdo = $context->connection->getPdo();

        $formRepository = new FormRepository($pdo);
        $form = $formRepository->findById($formId);
        // The switch was lowered again before this ran: we stop
        // delivering. Nothing already issued is revoked.
        if ($form === null || !$form->issuesTicket) {
            return;
        }

        $article = (new ArticleRepository($pdo))->findById($form->newsArticleId);
        if ($article === null) {
            return;
        }

        // Core's templates plus this module's own: a handler runs outside
        // the composition root, so nothing has aggregated the manifests
        // for it (ARCHITECTURE.md §8.7bis). A customisation is honoured
        // all the same — that lives in the database, not in the registry.
        $twig = TwigFactory::create(
            dirname(__DIR__, 4) . '/core/View/templates',
            false,
            ['news' => dirname(__DIR__, 2) . '/views']
        );
        $registry = new \Core\Mail\Template\EmailTemplateRegistry();
        $registry->registerModuleManifest(
            \Core\Module\ModuleManifest::fromFile(dirname(__DIR__, 2) . '/module.json')
        );

        $ticketMail = new TicketMailService(
            $context->mailService,
            new \Core\Mail\Template\EmailTemplateRenderer(
                $twig,
                $registry,
                new \Core\Mail\Template\EmailTemplateOverrideRepository($pdo),
                $context->journal
            ),
            (string) ($context->settings->get('site_name') ?: 'Unité scoute'),
            // The ICS is the calendar module's to render, and the module
            // may be off. The e-mail then carries no attachment and says
            // everything it said before.
            $context->getOptional(\Modules\Calendar\Api\IcsFeedBuilderInterface::class)
        );

        $responseRepository = new FormResponseRepository($pdo, $context->encryption);

        foreach ($responseIds as $responseId) {
            $response = $responseRepository->findById($responseId);
            // The payload is this application's own, but it survives a
            // deployment in the database, so the row may be gone or may
            // belong to another form by the time it runs.
            if ($response === null || $response->formId !== $formId || !$response->hasTicket()) {
                continue;
            }

            try {
                $ticketMail->sendTicketEmail($article, $form, $response);
            } catch (\Core\Mail\MailException) {
                // No personal data in the journal — identifiers only.
                $context->journal->log(
                    'news',
                    'ticket_email_failed',
                    'info',
                    "Échec de l'envoi d'un billet pour une réponse de formulaire",
                    ['article_id' => $article->id, 'form_id' => $formId, 'response_id' => $response->id],
                    null
                );
            }
        }
    }
}
