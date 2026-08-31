<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Attestations\Service;

/**
 * The arithmetic guard rail: the file's page count is not a multiple of the
 * detected certificate size, so nothing is produced.
 *
 * **This is the worst outcome this feature can have, and it is what makes
 * the refusal non-negotiable.** A split one page out of step gives every
 * family the next family's certificate — a nominative document, sent by
 * e-mail, sitting afterwards on a member's page under a title that says
 * nothing about whose name is printed inside. Nobody would ever find out
 * without opening one and reading it. So the split stops before it starts
 * rather than guessing where the boundaries are.
 *
 * It carries the two numbers as well as the sentence, because the screen
 * shows the subtraction — « 89 pages, 2 pages par attestation, reste 1 » —
 * and a reader who can see the remainder knows to go back to the federation
 * rather than to retry the same file.
 */
class PageCountMismatchException extends AttestationsException
{
    public function __construct(
        public readonly int $pageCount,
        public readonly int $pagesPerDocument
    ) {
        parent::__construct(sprintf(
            'Le découpage est impossible : le fichier compte %d pages et une attestation en occupe %d, '
            . 'ce qui laisse %d page(s) en trop. Rien n\'a été produit. Le fichier est peut-être incomplet, '
            . 'ou une page de garde s\'y est ajoutée : vérifiez-le auprès de la fédération avant de réessayer.',
            $this->pageCount,
            $this->pagesPerDocument,
            $this->remainder()
        ));
    }

    public function remainder(): int
    {
        return $this->pagesPerDocument > 0 ? $this->pageCount % $this->pagesPerDocument : $this->pageCount;
    }
}
