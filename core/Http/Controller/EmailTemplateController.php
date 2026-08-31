<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Journal\JournalService;
use Core\Mail\Template\EmailTemplate;
use Core\Mail\Template\EmailTemplateCustomisationService;
use Core\Mail\Template\EmailTemplateException;
use Core\Mail\Template\EmailTemplateOverrideRepository;
use Core\Mail\Template\EmailTemplateRegistry;
use Core\Mail\Template\EmailTemplateRenderer;
use Core\Security\AuthSession;
use Twig\Environment;

/**
 * Configuration > E-mails (`/config/emails`, `role_min: superadmin`).
 *
 * The inventory of every automatic e-mail the site sends
 * (ARCHITECTURE.md §8.7bis), and the editor for the ones that may be
 * reworded.
 *
 * **`editable: false` is enforced here, not by the page.** The four
 * authentication e-mails are shown without controls, but that is a
 * courtesy: a POST naming one is refused with a 403 and a `security`
 * journal entry, wherever it came from. An administrator who broke the
 * magic link or a password reset would shut themselves out with no way
 * back in, so the refusal has to survive somebody forging the request.
 *
 * The body is edited through the shared rich-text modal
 * (`partials/rich_text_field.html.twig`, `public/assets/js/
 * rich-text-field.js`) pointed at this controller's own save URL rather
 * than the generic one — nothing is forked, only aimed elsewhere. The
 * subject is a plain text field: it is one line, it must never carry
 * markup, and a rich-text editor for it would invite exactly that.
 */
class EmailTemplateController extends AbstractController
{
    private const PAGE_URL = '/config/emails';

    public function __construct(
        protected Environment $twig,
        private EmailTemplateRegistry $registry,
        private EmailTemplateOverrideRepository $overrides,
        private EmailTemplateRenderer $renderer,
        private EmailTemplateCustomisationService $customisation,
        private JournalService $journalService
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $customised = $this->overrides->customisedTemplateIds();

        $groups = [];
        foreach ($this->registry->groupedByModule() as $moduleId => $templates) {
            $groups[] = [
                'module_id' => $moduleId,
                'label' => $this->registry->moduleLabel($moduleId),
                'templates' => array_map(
                    static fn (EmailTemplate $template): array => [
                        'id' => $template->id,
                        'label' => $template->label,
                        'description' => $template->description,
                        'editable' => $template->editable,
                        'customised' => in_array($template->id, $customised, true),
                    ],
                    $templates
                ),
            ];
        }

        return $this->render('config/emails.html.twig', ['groups' => $groups]);
    }

    /**
     * @param array<string, string> $params
     */
    public function edit(Request $request, array $params): Response
    {
        $template = $this->registry->find($params['template'] ?? '');
        if ($template === null) {
            return $this->redirect(self::PAGE_URL);
        }

        $override = $this->overrides->find($template->id);

        return $this->render('config/email_edit.html.twig', [
            'template' => $template,
            'editable' => $template->editable,
            'customised' => $override !== null,
            'subject' => $override['subject'] ?? $template->defaultSubject,
            'body_html' => $override['body_html'] ?? $this->shippedBody($template),
            'preview' => $this->preview($template),
        ]);
    }

    /**
     * The subject, as an ordinary form post: one line of plain text, with
     * the same insertion buttons as the body but no editor.
     *
     * @param array<string, string> $params
     */
    public function saveSubject(Request $request, array $params): Response
    {
        $template = $this->registry->find($params['template'] ?? '');
        if ($template === null) {
            return $this->redirect(self::PAGE_URL);
        }

        if (($guard = $this->guardCsrf($request, $this->editUrl($template->id))) !== null) {
            return $guard;
        }

        if (($refusal = $this->refuseIfNotEditable($template)) !== null) {
            return $refusal;
        }

        return $this->store(
            $template,
            (string) $request->getBody('subject', ''),
            $this->currentBody($template)
        );
    }

    /**
     * The body, as the JSON payload `public/assets/js/rich-text-field.js`
     * posts — `{key, value, type, _csrf_token}` — so the shared editor
     * works here unchanged, only aimed at this URL.
     *
     * @param array<string, string> $params
     */
    public function saveBody(Request $request, array $params): Response
    {
        $payload = json_decode($request->getRawBody(), true);
        $payload = is_array($payload) ? $payload : [];

        if (($guard = $this->guardCsrfJson($request, isset($payload['_csrf_token']) ? (string) $payload['_csrf_token'] : null)) !== null) {
            return $guard;
        }

        $template = $this->registry->find($params['template'] ?? '');
        if ($template === null) {
            return $this->json(['success' => false, 'error' => "Cet email n'existe pas."], 404);
        }

        if (!$template->editable) {
            $this->journalRefusal($template);

            return $this->json([
                'success' => false,
                'error' => "Cet email n'est pas modifiable : il sert à se connecter au site ou à confirmer une adresse.",
            ], 403);
        }

        try {
            $this->customisation->customise(
                $template->id,
                $this->currentSubject($template),
                isset($payload['value']) ? (string) $payload['value'] : '',
                AuthSession::getUserAccountId()
            );
        } catch (EmailTemplateException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 400);
        }

        $this->journalCustomised($template);

        return $this->json(['success' => true]);
    }

    /**
     * @param array<string, string> $params
     */
    public function reset(Request $request, array $params): Response
    {
        $template = $this->registry->find($params['template'] ?? '');
        if ($template === null) {
            return $this->redirect(self::PAGE_URL);
        }

        if (($guard = $this->guardCsrf($request, $this->editUrl($template->id))) !== null) {
            return $guard;
        }

        if (($refusal = $this->refuseIfNotEditable($template)) !== null) {
            return $refusal;
        }

        if ($this->customisation->reset($template->id)) {
            $this->journalService->log(
                'core',
                'email_template_reset',
                'info',
                "Email « {$template->label} » remis au gabarit par défaut",
                ['template_id' => $template->id],
                AuthSession::getUserAccountId()
            );
        }

        FlashMessage::set('success', 'Cet email utilise à nouveau le gabarit par défaut.');

        return $this->redirect($this->editUrl($template->id));
    }

    // ── helpers ───────────────────────────────────────────────────────

    private function store(EmailTemplate $template, string $subject, string $bodyHtml): Response
    {
        try {
            $this->customisation->customise($template->id, $subject, $bodyHtml, AuthSession::getUserAccountId());
        } catch (EmailTemplateException $e) {
            FlashMessage::set('error', $e->getMessage());

            return $this->redirect($this->editUrl($template->id));
        }

        $this->journalCustomised($template);
        FlashMessage::set('success', 'Email enregistré.');

        return $this->redirect($this->editUrl($template->id));
    }

    /**
     * A refusal that is a real 403 rather than a redirect: a POST naming a
     * non-editable e-mail did not come from this page, which offers no
     * control for one, so it is answered as what it is.
     */
    private function refuseIfNotEditable(EmailTemplate $template): ?Response
    {
        if ($template->editable) {
            return null;
        }

        $this->journalRefusal($template);

        return (new Response(
            "Cet email n'est pas modifiable : il sert à se connecter au site ou à confirmer une adresse.",
            403
        ))->setHeader('Content-Type', 'text/plain; charset=UTF-8');
    }

    private function journalRefusal(EmailTemplate $template): void
    {
        $this->journalService->log(
            'core',
            'email_template_edit_refused',
            'security',
            "Tentative de modification d'un email non modifiable",
            ['template_id' => $template->id],
            AuthSession::getUserAccountId()
        );
    }

    private function journalCustomised(EmailTemplate $template): void
    {
        // The id, never the content: what a unit writes to its families is
        // not something the journal needs a copy of.
        $this->journalService->log(
            'core',
            'email_template_customised',
            'info',
            "Email « {$template->label} » personnalisé",
            ['template_id' => $template->id],
            AuthSession::getUserAccountId()
        );
    }

    private function currentSubject(EmailTemplate $template): string
    {
        return $this->overrides->find($template->id)['subject'] ?? $template->defaultSubject;
    }

    private function currentBody(EmailTemplate $template): string
    {
        return $this->overrides->find($template->id)['body_html'] ?? $this->shippedBody($template);
    }

    /**
     * The shipped template's own `content` block, rendered with the
     * registry's example values — the starting point an administrator
     * edits, rather than an empty box they have to rewrite from nothing.
     *
     * The block rather than the whole document: `email/base.html.twig`
     * carries `<!DOCTYPE html>` and the frame, which is code and stays
     * out of the editor.
     */
    private function shippedBody(EmailTemplate $template): string
    {
        $context = $template->exampleValues() + ['site_name' => $this->siteName()];

        try {
            return $this->twig->load($template->template)->renderBlock('content', $context);
        } catch (\Throwable) {
            // A module whose template moved, or one that does not use the
            // shared frame: an empty editor beats a broken page, and the
            // shipped e-mail keeps working either way.
            return '';
        }
    }

    /**
     * What the e-mail looks like with the registry's example values —
     * rendered into the page itself, never a new window and never an
     * iframe.
     *
     * @return array{subject: string, body_html: string}
     */
    private function preview(EmailTemplate $template): array
    {
        $context = $template->exampleValues() + ['site_name' => $this->siteName()];
        $override = $this->overrides->find($template->id);

        if ($override !== null) {
            // Rendering the WHOLE customised e-mail would nest a complete
            // HTML document inside this page. The body is what an
            // administrator is judging, and it is already sanitised.
            $rendered = $this->renderer->render($template->id, $context);

            return [
                'subject' => $rendered->subject,
                'body_html' => $this->substitutedBody($template, $override['body_html'], $context),
            ];
        }

        return [
            'subject' => $template->defaultSubject,
            'body_html' => $this->shippedBody($template),
        ];
    }

    /**
     * @param array<string, string> $context
     */
    private function substitutedBody(EmailTemplate $template, string $body, array $context): string
    {
        foreach ($template->variables as $variable) {
            $body = str_replace(
                ['{{ ' . $variable->name . ' }}', '{{' . $variable->name . '}}'],
                $context[$variable->name] ?? '',
                $body
            );
        }

        return $body;
    }

    /**
     * The unit's own name, out of Twig's globals — where every rendered
     * page and every shipped e-mail already reads it from.
     *
     * Written out rather than left as `{{ site_name }}`, and that is a
     * correctness matter rather than a cosmetic one: `site_name` is
     * deliberately not a declared variable (EmailTemplateRegistry), so the
     * renderer would never substitute it in a customised body. Seeding the
     * editor with the placeholder would put braces in a message an
     * administrator then saves, and the unit's families would receive
     * « les emails groupés de {{ site_name }} ».
     */
    private function siteName(): string
    {
        $global = $this->twig->getGlobals()['site_name'] ?? null;

        return is_scalar($global) ? (string) $global : '';
    }

    private function editUrl(string $templateId): string
    {
        return self::PAGE_URL . '/' . rawurlencode($templateId);
    }
}
