<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Exception\UserFacingMessage;
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
use Core\Mail\Template\EmailTestSendThrottler;
use Core\Mail\Template\MissingRequiredVariableException;
use Core\Mail\MailException;
use Core\Mail\MailService;
use Core\Security\AuthSession;
use Core\Template\TokenEngine;
use Core\Template\TokenSyntax;
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
 * variable insertion buttons are rendered INSIDE that modal, through the
 * extra-toolbar slot `partials/rich_text_editor.html.twig` exposes: they
 * write at the caret, and the caret is in there. Offered only on the page
 * behind it, adding three variables to a message meant saving, copying a
 * placeholder, reopening the editor and pasting — three times over.
 *
 * The subject is a plain text field: it is one line, it must never carry
 * markup, and a rich-text editor for it would invite exactly that.
 *
 * **One page, also seen through a module** (issue #905). A module that
 * wants its own e-mails edited from its own configuration — and by the
 * role that configures it, rather than by superadmins only — extends this
 * controller with a narrower scope: its own URL, the ids it may reach and
 * the list view that wraps the shared table. Nothing is forked: the same
 * editor, the same states, the same reset, the same refusals. An id
 * outside the scope is answered exactly like an unknown one, so a route
 * opened to a wider role reaches no e-mail beyond the ones it names.
 */
class EmailTemplateController extends AbstractController
{
    private const PAGE_URL = '/config/emails';

    /**
     * @param list<string>|null $templateIds
     */
    public function __construct(
        protected Environment $twig,
        private EmailTemplateRegistry $registry,
        private EmailTemplateOverrideRepository $overrides,
        private EmailTemplateRenderer $renderer,
        private EmailTemplateCustomisationService $customisation,
        private JournalService $journalService,
        private MailService $mailService,
        private EmailTestSendThrottler $throttler,
        private string $pageUrl = self::PAGE_URL,
        // null is every declared e-mail; a list is the scope above.
        private ?array $templateIds = null,
        private string $listView = 'config/emails.html.twig'
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
            $templates = array_values(
                array_filter($templates, fn (EmailTemplate $t): bool => $this->inScope($t->id))
            );
            if ($templates === []) {
                continue;
            }
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

        return $this->render($this->listView, ['groups' => $groups, 'page_url' => $this->pageUrl]);
    }

    /**
     * @param array<string, string> $params
     */
    public function edit(Request $request, array $params): Response
    {
        $template = $this->find($params['template'] ?? '');
        if ($template === null) {
            return $this->redirect($this->pageUrl);
        }

        $override = $this->overrides->find($template->id);
        $missing = $override !== null ? $template->missingRequiredVariables($override['body_html']) : [];

        return $this->render('config/email_edit.html.twig', [
            // The trail ends on the template's own name, the page's title
            // (issue #744) — never the route's generic « Email ».
            'breadcrumb_current' => $template->label,
            'template' => $template,
            'editable' => $template->editable,
            'customised' => $override !== null,
            'subject' => $override['subject'] ?? $template->defaultSubject,
            'body_html' => $override['body_html'] ?? $this->shippedBody(
                $template,
                $this->placeholderContext($template)
            ),
            'preview' => $this->preview($template),
            // A stored body can lack a required variable when it reached
            // the table another way than this editor (an older version, a
            // restored backup): it is not sent, and this says so here,
            // where it can be fixed.
            'missing_required' => $missing !== []
                ? EmailTemplateCustomisationService::missingMessage($missing)
                : null,
            'test_recipient' => AuthSession::getEmail() ?? '',
            'page_url' => $this->pageUrl,
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
        $template = $this->find($params['template'] ?? '');
        if ($template === null) {
            return $this->redirect($this->pageUrl);
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

        if (($guard = $this->guardCsrfJson(
            $request,
            isset($payload['_csrf_token']) ? (string) $payload['_csrf_token'] : null
        )) !== null) {
            return $guard;
        }

        $template = $this->find($params['template'] ?? '');
        if ($template === null) {
            return $this->json(['success' => false, 'error' => "Cet email n'existe pas."], 404);
        }

        if (!$template->editable) {
            $this->journalRefusal($template);

            return $this->json([
                'success' => false,
                'error' => "Cet email n'est pas modifiable : il sert à se connecter au site ou à confirmer une "
                    . "adresse.",
            ], 403);
        }

        try {
            $stored = $this->customisation->customise(
                $template->id,
                $this->currentSubject($template),
                isset($payload['value']) ? (string) $payload['value'] : '',
                AuthSession::getUserAccountId()
            );
        } catch (EmailTemplateException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 400);
        }

        $this->journalCustomised($template);

        // `value` is the body as STORED, which the sanitizer may have
        // pruned; rich-text-field.js repaints the preview with it rather
        // than with its own copy of what it sent. See issue #306.
        return $this->json(['success' => true, 'value' => $stored]);
    }

    /**
     * @param array<string, string> $params
     */
    public function reset(Request $request, array $params): Response
    {
        $template = $this->find($params['template'] ?? '');
        if ($template === null) {
            return $this->redirect($this->pageUrl);
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

    /**
     * « M'envoyer un test » — the e-mail as it stands, with the registry's
     * example values, to the address the administrator is signed in with.
     *
     * **Through `MailService::send()` and nowhere else**, which is the
     * whole point of the button: whatever delivery mode the installation
     * is in — real SMTP, local, the test-tools sandbox — the test goes
     * there too. A button that bypassed the configured mode would prove
     * the one thing nobody needs proved.
     *
     * A non-editable e-mail may be sent as a test: reading the magic-link
     * message is not editing it, and an administrator checking that their
     * relay works should not have to break something to do it.
     *
     * @param array<string, string> $params
     */
    public function sendTest(Request $request, array $params): Response
    {
        $template = $this->find($params['template'] ?? '');
        if ($template === null) {
            return $this->redirect($this->pageUrl);
        }

        if (($guard = $this->guardCsrf($request, $this->editUrl($template->id))) !== null) {
            return $guard;
        }

        $userId = AuthSession::getUserAccountId();
        $to = AuthSession::getEmail();

        $remaining = $this->throttler->secondsRemaining($userId);
        if ($remaining > 0) {
            FlashMessage::set('error', "Un test vient d'être envoyé. Réessayez dans {$remaining} secondes.");

            return $this->redirect($this->editUrl($template->id));
        }

        if ($to === null || $to === '') {
            FlashMessage::set('error', "Votre compte n'a pas d'adresse email : impossible d'envoyer un test.");

            return $this->redirect($this->editUrl($template->id));
        }

        try {
            $email = $this->renderer->render($template->id, $this->exampleContext($template));
        } catch (MissingRequiredVariableException $e) {
            FlashMessage::set('error', "Le test n'est pas parti. " . $e->getMessage());

            return $this->redirect($this->editUrl($template->id));
        }

        try {
            $this->mailService->send($to, $email->subject, $email->bodyHtml, $email->bodyText);
        } catch (MailException $e) {
            // The address is stripped: SECURITY.md §11 bars personal data
            // from journal entries unconditionally, and a relay's error
            // text quotes the recipient often enough to matter.
            $this->journalService->log(
                'core',
                'email_template_test_failed',
                'info',
                "Échec de l'envoi de test d'un email",
                ['template_id' => $template->id, 'error' => str_replace($to, '[adresse]', $e->getMessage())],
                $userId
            );
            // Never the transport's own sentence: Core\Mail\MailException
            // carries PHPMailer's English ("SMTP connect() failed",
            // a wiki URL), which is neither French nor a sentence a
            // visitor can act on. UserFacingMessage is the helper that
            // decides whether an exception claims to be showable, and
            // this one does not.
            FlashMessage::set(
                'error',
                UserFacingMessage::from(
                    $e,
                    "L'envoi de test a échoué — vérifiez la configuration d'envoi du site "
                        . "(Configuration > Email), puis réessayez."
                )
            );

            return $this->redirect($this->editUrl($template->id));
        }

        // Written AFTER the send, and it is what the throttler counts: a
        // failed send costs the account nothing, so an administrator
        // fixing their relay can try again straight away.
        $this->journalService->log(
            'core',
            EmailTestSendThrottler::JOURNAL_TYPE,
            'info',
            "Envoi de test de l'email « {$template->label} »",
            ['template_id' => $template->id],
            $userId
        );

        FlashMessage::set('success', 'Test envoyé à votre adresse.');

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
        return $this->overrides->find($template->id)['body_html']
            ?? $this->shippedBody($template, $this->placeholderContext($template));
    }

    /**
     * The shipped template's own `content` block — the starting point an
     * administrator edits, rather than an empty box they have to rewrite
     * from nothing.
     *
     * The block rather than the whole document: `email/base.html.twig`
     * carries `<!DOCTYPE html>`, the frame and the signature, which are
     * code and stay out of the editor.
     *
     * @param array<string, mixed> $context what the declared variables
     *        render as — their PLACEHOLDERS when this is the editor's
     *        seed (placeholderContext()), their EXAMPLES when it is the
     *        preview (exampleContext()).
     */
    private function shippedBody(EmailTemplate $template, array $context): string
    {
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
        $context = $this->exampleContext($template);
        $override = $this->overrides->find($template->id);

        if ($override !== null) {
            // Rendering the WHOLE customised e-mail would nest a complete
            // HTML document inside this page. The body is what an
            // administrator is judging, and it is already sanitised.
            //
            // The subject goes through the same substitution as the send's,
            // not through render(): a stored body missing a required
            // variable is refused there, and this page is where an
            // administrator repairs it — it must open.
            return [
                'subject' => $this->substitutedSubject($template, $override['subject'], $context),
                // Through the renderer's own substitution, not a second
                // copy of it: what the preview shows has to be what the
                // send produces, escaping included.
                'body_html' => $this->renderer->substituteIntoHtml($template, $override['body_html'], $context),
            ];
        }

        return [
            // Substituted, like the body beside it: a declared subject may
            // carry variables — « Rétrospective clôturée :
            // {{ board_title }} », and rental's decision subject is
            // nothing BUT one — and a preview showing braces where the
            // real e-mail shows words is not a preview.
            'subject' => $this->substitutedSubject($template, $template->defaultSubject, $context),
            'body_html' => $this->shippedBody($template, $context),
        ];
    }

    /**
     * A subject line is not markup: it is substituted as it is, through
     * the same TokenEngine EmailTemplateRenderer uses before handing it to
     * MailService — any spacing, `{{x}}` as well as `{{ x }}` — so the
     * shipped e-mail's preview and its sent subject cannot disagree.
     *
     * @param array<string, string> $context
     */
    private function substitutedSubject(EmailTemplate $template, string $text, array $context): string
    {
        return (new TokenEngine(TokenSyntax::identifiers()))->substitute(
            $text,
            static fn(string $name): ?string => $template->hasVariable($name) ? ($context[$name] ?? '') : null,
            false
        );
    }

    /**
     * The registry's example values — what the preview and the test send
     * render with, so an administrator judges a message rather than a row
     * of braces.
     *
     * @return array<string, string>
     */
    private function exampleContext(EmailTemplate $template): array
    {
        return $template->exampleValues() + ['site_name' => $this->siteName()];
    }

    /**
     * The same context with each declared variable standing for ITSELF —
     * `{{ member_names }}` rather than « Camille Dupont ».
     *
     * This is what the editor is seeded with, and it is the whole
     * difference between a default wording that keeps working and one
     * that does not. Seeded with the examples, the very first save froze
     * somebody's example values into the e-mail: every family then read
     * « Bonjour Camille Dupont » whoever they were, because the
     * placeholders the renderer substitutes had been replaced by their
     * own examples before the administrator ever saw the text.
     *
     * `site_name` is the one value still written out, and for the
     * opposite reason: it is deliberately NOT a declared variable, so the
     * renderer would never substitute it and the braces would be sent as
     * braces.
     *
     * @return array<string, string>
     */
    private function placeholderContext(EmailTemplate $template): array
    {
        $values = [];
        foreach ($template->variables as $variable) {
            $values[$variable->name] = '{{ ' . $variable->name . ' }}';
        }

        return $values + ['site_name' => $this->siteName()];
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
        return $this->pageUrl . '/' . rawurlencode($templateId);
    }

    /**
     * The declared e-mail this page may reach — null for an id nobody
     * declared AND for one outside this page's scope, so a scoped page
     * answers both the same way.
     */
    private function find(string $templateId): ?EmailTemplate
    {
        return $this->inScope($templateId) ? $this->registry->find($templateId) : null;
    }

    private function inScope(string $templateId): bool
    {
        return $this->templateIds === null || in_array($templateId, $this->templateIds, true);
    }
}
