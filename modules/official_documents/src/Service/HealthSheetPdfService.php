<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\OfficialDocuments\Service;

use Core\Member\MemberProfile;
use Modules\OfficialDocuments\Api\OfficialDocumentsException;
use Modules\OfficialDocuments\Pdf\HealthSheetLayout;
use Modules\OfficialDocuments\Pdf\OverlayPdf;
use Modules\OfficialDocuments\Pdf\TemplateLibrary;
use Modules\OfficialDocuments\Value\HealthSheet;

/**
 * The federation's health sheet, drawn.
 *
 * Same three-way split as `ParentalAuthorizationPdfService`: WHERE is
 * `Pdf\HealthSheetLayout`, WHAT is `HealthSheetFilling`, HOW is
 * `Pdf\OverlayPdf`. What is left here is the assembly, plus the one thing
 * only the assembly can know — that a free-text answer has to be wrapped
 * before anybody can say whether it fits.
 *
 * **Two pages, and the order they are written in is not a detail.** tFPDF
 * draws on the page that is currently open, so everything page 1 carries is
 * written before page 2 starts. The wrapping happens first, for all of it,
 * because « maladies importantes ou opérations subies » runs over three
 * printed lines and the third is at the top of page 2 — the form's own
 * doing, not this module's.
 *
 * Nothing touches the filesystem: `render()` answers bytes.
 */
final class HealthSheetPdfService
{
    public function __construct(private readonly TemplateLibrary $templates)
    {
    }

    /**
     * Render the sheet, in memory.
     *
     * @return array{pdf: string, overflowing: list<string>} the bytes, and
     *         the answers that did not fit the form's printed lines — named
     *         as the screen names them, so the page can tell the parent
     *         which box to shorten
     * @throws OfficialDocumentsException when the template is missing or
     *         cannot be read
     */
    public function render(MemberProfile $member, HealthSheet $sheet): array
    {
        if (!$this->templates->has(TemplateLibrary::HEALTH_SHEET)) {
            throw new OfficialDocumentsException(
                'Le formulaire officiel est introuvable sur ce site. Prévenez votre chef d\'unité.'
            );
        }

        $pdf = new OverlayPdf();

        try {
            $pdf->openTemplate($this->templates->path(TemplateLibrary::HEALTH_SHEET));
        } catch (\Throwable $e) {
            // Never the library's own message: FPDI names the file it could
            // not parse, and this exception's text is shown to a parent.
            throw new OfficialDocumentsException(
                'Le formulaire officiel n\'a pas pu être ouvert. Prévenez votre chef d\'unité.',
                0,
                $e
            );
        }

        $fields = HealthSheetLayout::textFields();
        $values = HealthSheetFilling::values($member, $sheet);
        $overflowing = [];

        // Wrap every free-text answer first, across the page break where
        // the form puts one. `$values` ends up carrying one string per
        // printed line, so the writing loop below does not care which
        // answer a line came from.
        foreach (HealthSheetLayout::paragraphLines() as $answer => $names) {
            $lines = array_map(
                static fn(string $name): \Modules\OfficialDocuments\Pdf\TextField => $fields[$name],
                $names
            );
            $wrapped = $pdf->wrapInto(HealthSheetFilling::paragraphs($sheet)[$answer] ?? '', $lines);

            foreach ($names as $index => $name) {
                $values[$name] = $wrapped['lines'][$index];
            }
            if ($wrapped['overflow']) {
                $overflowing[] = $answer;
            }
        }

        $boxes = HealthSheetLayout::tickBoxes();
        $ticks = HealthSheetFilling::ticks($sheet);

        for ($page = 1; $page <= HealthSheetLayout::PAGE_COUNT; $page++) {
            try {
                $pdf->startPage($page);
            } catch (\Throwable $e) {
                throw new OfficialDocumentsException(
                    'Le formulaire officiel n\'a pas pu être ouvert. Prévenez votre chef d\'unité.',
                    0,
                    $e
                );
            }

            foreach ($values as $name => $value) {
                $field = $fields[$name] ?? null;
                if ($field === null || $field->page !== $page) {
                    continue;
                }
                if (!$pdf->writeText($value, $field)) {
                    $overflowing[] = HealthSheetLayout::answerFor($name);
                }
            }

            foreach ($ticks as $name) {
                $box = $boxes[$name] ?? null;
                if ($box !== null && $box->page === $page) {
                    $pdf->tick($box);
                }
            }
        }

        return [
            'pdf' => $pdf->render(),
            // A paragraph that overflowed AND whose last line was cramped
            // would otherwise be named twice on the screen.
            'overflowing' => array_values(array_unique($overflowing)),
        ];
    }
}
