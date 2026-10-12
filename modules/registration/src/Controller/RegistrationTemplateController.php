<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Controller;

use Core\Http\Controller\AbstractController;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Journal\JournalService;
use Core\Security\AuthSession;
use Modules\Registration\Service\RegistrationException;
use Modules\Registration\Service\RequestEmailTemplate;
use Modules\Registration\Service\RequestEmailTemplateService;
use Twig\Environment;

/**
 * « Gabarits », the third sub-page of « Formulaire d'inscription » (issue
 * #843, D10): the four e-mails of the request flow as a list — each with its
 * state and a pencil — and one edit page per e-mail, on the model of a
 * rental asset's Gabarits (`modules/rental`, #708 IT-10).
 *
 * Every route is `role_min: admin`, the floor of the whole page; RBAC is
 * the router's, never this class's. What a template may say, what its state
 * is and how its body is stored all belong to
 * Service\RequestEmailTemplateService — this controller reads the form,
 * checks the token, journals, and sends the visitor back to the list.
 */
class RegistrationTemplateController extends AbstractController
{
    private const PATH_LIST = '/config/inscriptions/gabarits';

    public function __construct(
        protected Environment $twig,
        private RequestEmailTemplateService $templateService,
        private JournalService $journalService
    ) {
    }

    /**
     * GET /config/inscriptions/gabarits — the four templates, their state,
     * and the warning while a decision e-mail cannot leave.
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $rows = [];
        foreach ($this->templateService->all() as $template) {
            $state = $this->templateService->stateOf($template->key);
            $rows[] = [
                'slug' => $template->slug,
                'name' => $template->name,
                'description' => $template->description,
                'state' => $state,
                'state_label' => RequestEmailTemplateService::STATE_LABELS[$state],
            ];
        }

        $decisionTemplatesToWrite = $this->templateService->isToWrite(RequestEmailTemplateService::KEY_ACCEPTED)
            || $this->templateService->isToWrite(RequestEmailTemplateService::KEY_REFUSED);

        return $this->render('@registration/config/templates.html.twig', [
            'templates' => $rows,
            'decision_templates_to_write' => $decisionTemplatesToWrite,
        ]);
    }

    /**
     * GET /config/inscriptions/gabarits/{template} — one template's editor,
     * its variables shown open beside it. The editor holds the text that
     * would go out today: the unit's own, the default while nobody has
     * written one, or nothing for a decision e-mail still to write.
     *
     * @param array<string, string> $params
     */
    public function edit(Request $request, array $params): Response
    {
        $template = $this->templateService->findBySlug((string) ($params['template'] ?? ''));
        if ($template === null) {
            return $this->notFound();
        }

        $body = (string) $this->templateService->bodyFor($template->key);

        return $this->render('@registration/config/template_edit.html.twig', [
            'template' => $template,
            'state' => $this->templateService->stateOf($template->key),
            'body' => $body,
            'variables' => $template->variables->palette(),
            'unknown_variables' => $this->templateService->unknownVariablesIn($template, $body),
            'breadcrumb_current' => 'Modifier — ' . $template->name,
        ]);
    }

    /**
     * POST /config/inscriptions/gabarits/{template} — stores the body,
     * sanitised by the content store itself (SECURITY.md §7), and goes back
     * to the list. A body that renders as nothing is refused, and the edit
     * page reopens on the text stored before — what was refused had no text
     * to keep.
     *
     * @param array<string, string> $params
     */
    public function save(Request $request, array $params): Response
    {
        $template = $this->templateService->findBySlug((string) ($params['template'] ?? ''));
        if ($template === null) {
            return $this->notFound();
        }
        if (($guard = $this->guardCsrf($request, $this->editPath($template))) !== null) {
            return $guard;
        }

        try {
            $this->templateService->save(
                $template->key,
                (string) $request->getBody('body', ''),
                (int) AuthSession::getUserAccountId()
            );
        } catch (RegistrationException $e) {
            FlashMessage::set('error', $e->getMessage());

            return $this->redirect($this->editPath($template));
        }

        $this->journal($template, 'registration_email_template_saved', 'Gabarit d\'e-mail de demande enregistré');
        FlashMessage::set('success', '« ' . $template->name . ' » enregistré.');

        return $this->redirect(self::PATH_LIST);
    }

    /**
     * POST /config/inscriptions/gabarits/{template}/defaut — « Rétablir le
     * texte par défaut », for the two templates that have one.
     *
     * @param array<string, string> $params
     */
    public function restoreDefault(Request $request, array $params): Response
    {
        $template = $this->templateService->findBySlug((string) ($params['template'] ?? ''));
        if ($template === null || !$template->hasDefault()) {
            return $this->notFound();
        }
        if (($guard = $this->guardCsrf($request, $this->editPath($template))) !== null) {
            return $guard;
        }

        $this->templateService->restoreDefault($template->key);

        $this->journal(
            $template,
            'registration_email_template_reset',
            'Gabarit d\'e-mail de demande rétabli par défaut'
        );
        FlashMessage::set('success', '« ' . $template->name . ' » utilise de nouveau le texte par défaut.');

        return $this->redirect(self::PATH_LIST);
    }

    private function editPath(RequestEmailTemplate $template): string
    {
        return self::PATH_LIST . '/' . $template->slug;
    }

    /**
     * A body is the unit's wording, not personal data — the journal names
     * which template changed and who changed it, never its text.
     */
    private function journal(RequestEmailTemplate $template, string $action, string $message): void
    {
        $this->journalService->log(
            'registration',
            $action,
            'info',
            $message,
            ['template' => $template->key],
            (int) AuthSession::getUserAccountId()
        );
    }
}
