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
 *   each declared `{{ variable }}` is replaced by **string substitution**:
 *   escaped, with its line breaks turned into `<br>`, on its way into the
 *   HTML body; used exactly as it is in the subject, which is not markup.
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
            subject: $this->substitute($template, $template->defaultSubject, $context, false),
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
        $bodyHtml = $this->substituteIntoHtml($template, $override['body_html'], $context);

        return new RenderedEmail(
            subject: $this->substitute($template, $override['subject'], $context, false),
            // Through custom.html.twig so the frame — header, footer, the
            // unit's name — is the same code every other e-mail uses. The
            // body itself is a finished string by now, never a template.
            bodyHtml: $this->twig->render('email/custom.html.twig', [
                'site_name' => is_scalar($context['site_name'] ?? null) ? (string) $context['site_name'] : '',
                'body_html' => $bodyHtml,
            ]),
            // The signature too: the HTML half gets it from the frame, and
            // a plain-text half that stopped short of it would be the one
            // version of the message that arrives unsigned.
            bodyText: self::toPlainText($bodyHtml) . "\n\n" . $this->twig->render(
                'email/signature.text.twig',
                ['site_name' => is_scalar($context['site_name'] ?? null) ? (string) $context['site_name'] : '']
            ),
        );
    }

    /**
     * Replace every declared `{{ name }}` in a piece of HTML with its
     * value — the substitution a customised body goes through.
     *
     * Public because the Configuration > E-mails page previews a
     * customised body and has to preview exactly what it will send: two
     * implementations of one substitution is two answers to "what will
     * this e-mail say".
     *
     * @param array<string, mixed> $context
     */
    public function substituteIntoHtml(EmailTemplate $template, string $html, array $context): string
    {
        return $this->substitute($template, $html, $context, true);
    }

    /**
     * Replace every declared `{{ name }}` with its value, as a plain
     * string.
     *
     * **Into HTML, the value is escaped and its line breaks become
     * `<br>`** — what Twig does for the same value in a shipped template,
     * and what a customised body has no other way of getting: the stored
     * text is never given to Twig, so nothing else would escape a renter's
     * name, a manager's sentence or a family's answer on its way into a
     * message the site signs and sends. Into a SUBJECT the value is used
     * as it is: a subject line is not markup, and `&amp;` in one is a
     * mistake rather than an escape.
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
    private function substitute(EmailTemplate $template, string $text, array $context, bool $intoHtml): string
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
            if ($intoHtml) {
                $value = nl2br(htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);
            }

            foreach (self::placeholdersFor($variable->name) as $placeholder) {
                $text = str_replace($placeholder, $value, $text);
            }
        }

        $this->reportUndeclared($template, $text);

        return $text;
    }

    /**
     * Every spelling a placeholder can reasonably arrive in.
     *
     * The two obvious ones are what the insertion button writes
     * (`{{ name }}`) and what somebody typing it by hand produces
     * (`{{name}}`). The percent-encoded twins are not obvious at all, and
     * they are the ones that mattered: a placeholder written inside a
     * link — « Suivre ma demande », the call to action of half the e-mails
     * this site sends — is stored as
     * `href="%7B%7B%20tracking_url%20%7D%7D"`, because the sanitizer
     * serialises through DOM and DOM encodes a URL attribute. Matching
     * only the literal spelling left that button pointing at the escaped
     * placeholder itself, in every customised e-mail, with nothing on the
     * page to suggest anything was wrong.
     *
     * Both hex cases, because the encoder's choice is not ours to assume.
     *
     * @return list<string>
     */
    private static function placeholdersFor(string $name): array
    {
        $spellings = [];
        foreach (['{{ ' . $name . ' }}', '{{' . $name . '}}'] as $literal) {
            $spellings[] = $literal;
            $spellings[] = str_replace(['{', '}', ' '], ['%7B', '%7D', '%20'], $literal);
            $spellings[] = str_replace(['{', '}', ' '], ['%7b', '%7d', '%20'], $literal);
        }

        return $spellings;
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
     *
     * **A link keeps its address.** `strip_tags()` alone left « Suivre ma
     * demande » in the text half of every customised e-mail with no URL
     * anywhere near it, which for a renter reading plain text is a
     * message telling them to click nothing.
     */
    public static function toPlainText(string $html): string
    {
        $text = self::linksAsText($html);
        $text = preg_replace('#<br\s*/?>#i', "\n", $text) ?? $text;
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
     * `<a href="…">Libellé</a>` as « Libellé : … », so the address
     * survives `strip_tags()`. A link whose label already IS the address
     * is left as the address alone rather than written twice.
     */
    private static function linksAsText(string $html): string
    {
        return preg_replace_callback(
            '#<a\b[^>]*\shref=(["\'])(.*?)\1[^>]*>(.*?)</a>#is',
            static function (array $match): string {
                $url = trim(html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                $label = trim(strip_tags($match[3]));

                if ($url === '' || $label === '' || $label === $url) {
                    return $url !== '' ? $url : $label;
                }

                return $label . ' : ' . $url;
            },
            $html
        ) ?? $html;
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
