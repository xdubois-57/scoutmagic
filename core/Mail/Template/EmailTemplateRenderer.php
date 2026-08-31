<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Template;

use Core\Journal\JournalService;
use Twig\Environment;

/**
 * Turns a declared e-mail (EmailTemplateRegistry) plus a context into the
 * subject and the two bodies `MailService::send()` needs.
 *
 * Two paths, and the difference between them is the whole feature:
 *
 * - **No customisation** — the Twig templates shipped with the
 *   application are rendered exactly as the calling service used to
 *   render them itself. Byte for byte: a migrated sender must produce the
 *   same message it produced before, or the migration is a rewrite
 *   wearing a refactor's clothes.
 * - **A customisation exists** — the stored subject and body win, and
 *   each declared `{{ variable }}` is replaced by **string substitution**.
 *
 * **The stored content is never given to Twig.** Not `createTemplate()`,
 * not a sandbox, not `include`. An administration page that could define
 * a Twig template would be an administration page that could run code,
 * and string substitution is the only shape with nothing to escape from.
 * The stored body is dropped into `email/base.html.twig` through
 * `email/custom.html.twig`, so the header, the footer and the unit's name
 * stay code and stay out of reach.
 *
 * The subject carries no `[{short_name}]` prefix — MailService adds that.
 */
class EmailTemplateRenderer
{
    /**
     * Template ids already reported for naming an undeclared variable.
     *
     * Per instance, so a batch of two hundred sends produces at most one
     * entry per template rather than two hundred — which is the promise
     * that matters. A later request may report the same template again;
     * that is a cheap price for not carrying a table of what has already
     * been said.
     *
     * @var array<string, true>
     */
    private array $reportedUndeclared = [];

    public function __construct(
        private Environment $twig,
        private EmailTemplateRegistry $registry,
        private EmailTemplateOverrideRepository $overrides,
        private ?JournalService $journal = null
    ) {
    }

    /**
     * @param array<string, mixed> $context every value the shipped
     *        Twig template needs, including `site_name`. A shipped Twig
     *        template legitimately takes lists and objects (a roster, a
     *        booking); only the DECLARED names are substituted into a
     *        customised body, and only when their value is a scalar —
     *        there is no sensible string for a list, and printing
     *        "Array" into an e-mail would be worse than leaving the
     *        placeholder visible.
     *
     * @throws \InvalidArgumentException when $templateId is not declared —
     *         a caller naming an e-mail nobody declared is a bug here, not
     *         a message to send anyway.
     */
    public function render(string $templateId, array $context = []): RenderedEmail
    {
        $template = $this->registry->find($templateId);
        if ($template === null) {
            throw new \InvalidArgumentException("Unknown e-mail template '{$templateId}'");
        }

        $override = $this->overrides->find($templateId);
        if ($override === null) {
            return $this->renderShipped($template, $context);
        }

        return $this->renderCustomised($template, $override, $context);
    }

    /**
     * Whether this e-mail currently has a customisation — what the
     * Configuration > E-mails page shows as « Personnalisé ».
     */
    public function isCustomised(string $templateId): bool
    {
        return $this->overrides->find($templateId) !== null;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function renderShipped(EmailTemplate $template, array $context): RenderedEmail
    {
        return new RenderedEmail(
            subject: $this->substitute($template, $template->defaultSubject, $context),
            bodyHtml: $this->twig->render($template->template, $context),
            bodyText: $this->twig->render($this->textTemplateOf($template), $context),
        );
    }

    /**
     * @param array{subject: string, body_html: string, updated_at: string, updated_by: ?int} $override
     * @param array<string, mixed> $context
     */
    private function renderCustomised(EmailTemplate $template, array $override, array $context): RenderedEmail
    {
        $bodyHtml = $this->substitute($template, $override['body_html'], $context);

        return new RenderedEmail(
            subject: $this->substitute($template, $override['subject'], $context),
            // Through custom.html.twig so the frame — header, footer, the
            // unit's name — is the same code every other e-mail uses. The
            // body itself is a finished string by now, never a template.
            bodyHtml: $this->twig->render('email/custom.html.twig', [
                'site_name' => is_scalar($context['site_name'] ?? null) ? (string) $context['site_name'] : '',
                'body_html' => $bodyHtml,
            ]),
            bodyText: self::toPlainText($bodyHtml),
        );
    }

    /**
     * Replace every declared `{{ name }}` with its value, as a plain
     * string.
     *
     * **Longest name first**, which is not a nicety: with `member` and
     * `member_name` both declared, replacing the short one first eats the
     * start of the long one and leaves `Akéla_name` in the message.
     *
     * A `{{ … }}` naming something the registry does not declare is left
     * exactly as written — an administrator sees their own text back
     * rather than a hole — and reported once per template, because a
     * placeholder that silently renders as itself in every e-mail a unit
     * sends is the kind of thing nobody notices for a season.
     *
     * @param array<string, mixed> $context
     */
    private function substitute(EmailTemplate $template, string $text, array $context): string
    {
        $variables = $template->variables;
        usort(
            $variables,
            static fn (EmailTemplateVariable $a, EmailTemplateVariable $b): int
                => strlen($b->name) <=> strlen($a->name)
        );

        foreach ($variables as $variable) {
            $raw = $context[$variable->name] ?? null;
            if ($raw !== null && !is_scalar($raw)) {
                continue;
            }

            $value = $raw === null ? '' : (string) $raw;

            foreach (self::placeholdersFor($variable->name) as $placeholder) {
                $text = str_replace($placeholder, $value, $text);
            }
        }

        $this->reportUndeclared($template, $text);

        return $text;
    }

    /**
     * The two spellings a placeholder can reasonably arrive in — the one
     * the insertion button writes (`{{ name }}`) and the one somebody
     * typing it by hand produces (`{{name}}`).
     *
     * @return list<string>
     */
    private static function placeholdersFor(string $name): array
    {
        return ['{{ ' . $name . ' }}', '{{' . $name . '}}'];
    }

    private function reportUndeclared(EmailTemplate $template, string $text): void
    {
        if (isset($this->reportedUndeclared[$template->id]) || $this->journal === null) {
            return;
        }

        if (preg_match_all('/\{\{\s*([a-z][a-z0-9_]*)\s*\}\}/', $text, $matches) < 1) {
            return;
        }

        $unknown = array_values(array_unique($matches[1]));
        if ($unknown === []) {
            return;
        }

        $this->reportedUndeclared[$template->id] = true;

        $this->journal->log(
            'core',
            'email_template_unknown_variable',
            'warning',
            "Le gabarit d'email « {$template->label} » contient une variable inconnue.",
            ['template_id' => $template->id, 'variables' => $unknown],
            null
        );
    }

    /**
     * The plain-text half, derived from the HTML because a customised
     * e-mail has only one body and multipart is mandatory (SECURITY.md
     * §8).
     *
     * Block-level tags become line breaks before the tags are stripped —
     * without that step a paragraph and the paragraph after it run
     * together into one sentence — and entities are decoded, since the
     * text part is read as text and `&amp;` in it is a mistake, not an
     * escape.
     */
    public static function toPlainText(string $html): string
    {
        $text = preg_replace('#<br\s*/?>#i', "\n", $html) ?? $html;
        $text = preg_replace('#</(p|div|h[1-6]|li|tr|blockquote)>#i', "\n\n", $text) ?? $text;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Collapse the runs of blank lines the substitutions above leave
        // behind, and the trailing spaces they leave on each line.
        $text = preg_replace('/[ \t]+\n/', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * The `.text.twig` twin of a shipped template. Every e-mail ships
     * both halves (multipart is mandatory), and they differ by exactly
     * this suffix — deriving it beats declaring it twice in every
     * manifest and getting one of them wrong.
     */
    private function textTemplateOf(EmailTemplate $template): string
    {
        return preg_replace('/\.html\.twig$/', '.text.twig', $template->template) ?? $template->template;
    }
}
