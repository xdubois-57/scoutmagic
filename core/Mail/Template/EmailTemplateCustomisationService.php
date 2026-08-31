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
     * @throws EmailTemplateException when the e-mail is unknown, is not
     *         editable, or the subject is empty
     */
    public function customise(string $templateId, string $subject, string $bodyHtml, ?int $updatedBy): void
    {
        $template = $this->requireEditable($templateId);

        $subject = trim($subject);
        if ($subject === '') {
            throw new EmailTemplateException('Le sujet ne peut pas être vide.');
        }

        $this->repository->save(
            $template->id,
            $subject,
            $this->sanitizer->sanitize($bodyHtml),
            $updatedBy
        );
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
