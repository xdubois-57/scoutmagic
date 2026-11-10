<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Template;

use Core\Security\HtmlSanitizer;

/**
 * Writing and undoing an e-mail's customisation.
 *
 * Two rules live here rather than in the repository or the controller,
 * because both are about what may be stored at all:
 *
 * - **`editable: false` is refused on the server.** The four
 *   authentication e-mails have no editor on the page, and this is what
 *   makes that true rather than merely displayed — a POST naming one is
 *   turned away here, wherever it came from.
 * - **The body is sanitised before it is written**, exactly like every
 *   other rich text (SECURITY.md §7). Sanitising on the way out would put
 *   the rule in every future caller instead of in one place.
 * - **A required variable cannot be left out** (EmailTemplateVariable::
 *   $required). Checked on the body as it will be STORED, after the
 *   sanitizer, so a link the sanitizer pruned is a link missing.
 */
class EmailTemplateCustomisationService
{
    public function __construct(
        private EmailTemplateRegistry $registry,
        private EmailTemplateOverrideRepository $repository,
        private HtmlSanitizer $sanitizer
    ) {
    }

    /**
     * Returns the body as STORED, which the sanitizer may have pruned —
     * the editor repaints its preview with it rather than with its own
     * copy of what it sent. Same reason as
     * {@see \Core\View\EditableContentService::set()}: this modal is the
     * shared one, and a heading it kept on screen but the server dropped
     * was half of issue #306.
     *
     * @throws EmailTemplateException when the e-mail is unknown, is not
     *         editable, the subject is empty or the body leaves out a
     *         required variable
     */
    public function customise(string $templateId, string $subject, string $bodyHtml, ?int $updatedBy): string
    {
        $template = $this->requireEditable($templateId);

        $subject = trim($subject);
        if ($subject === '') {
            throw new EmailTemplateException('Le sujet ne peut pas être vide.');
        }

        $storedBody = $this->sanitizer->sanitize($bodyHtml);

        $missing = $template->missingRequiredVariables($storedBody);
        if ($missing !== []) {
            throw new EmailTemplateException(self::missingMessage($missing));
        }

        $this->repository->save($template->id, $subject, $storedBody, $updatedBy);

        return $storedBody;
    }

    /**
     * « Revenir au gabarit par défaut » — deletes the row, which puts the
     * e-mail back on the shipped template and back on the path of every
     * future update. Nothing is kept: there is no stored version and no
     * difference to compute.
     *
     * @throws EmailTemplateException when the e-mail is unknown or not editable
     */
    public function reset(string $templateId): bool
    {
        return $this->repository->delete($this->requireEditable($templateId)->id);
    }

    /**
     * « Le texte doit contenir la variable « Lien de suivi » ({{ lien_suivi }}). »
     * — the label an administrator recognises from the buttons, and the
     * placeholder they would type.
     *
     * @param non-empty-list<EmailTemplateVariable> $missing
     */
    public static function missingMessage(array $missing): string
    {
        $names = array_map(
            static fn(EmailTemplateVariable $v): string => "« {$v->label} » ({{ {$v->name} }})",
            $missing
        );

        return count($names) === 1
            ? "Le texte doit contenir la variable {$names[0]}, obligatoire pour cet email."
            : 'Le texte doit contenir les variables ' . implode(', ', $names) . ', obligatoires pour cet email.';
    }

    /**
     * @throws EmailTemplateException
     */
    private function requireEditable(string $templateId): EmailTemplate
    {
        $template = $this->registry->find($templateId);

        if ($template === null) {
            throw new EmailTemplateException("Cet email n'existe pas.");
        }

        if (!$template->editable) {
            throw new EmailTemplateException(
                "Cet email n'est pas modifiable : il sert à se connecter au site ou à confirmer une adresse."
            );
        }

        return $template;
    }
}
