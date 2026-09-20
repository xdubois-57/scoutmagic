<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\OfficialDocuments\Service;

use Core\Member\MemberProfile;
use Modules\OfficialDocuments\Api\OfficialDocumentsException;
use Modules\OfficialDocuments\Pdf\OverlayPdf;
use Modules\OfficialDocuments\Pdf\ParentalAuthorizationLayout;
use Modules\OfficialDocuments\Pdf\TemplateLibrary;

/**
 * The federation's parental authorization, drawn.
 *
 * Three things, each of them somewhere else: WHERE is
 * `ParentalAuthorizationLayout`, WHAT is `ParentalAuthorizationFilling`,
 * and HOW is `OverlayPdf`. What is left here is the assembly — which is why
 * this class is short and why a new version of the form is a coordinate
 * change rather than a rewrite.
 *
 * The two « Signature représentant·e légal·e » lines are deliberately left
 * empty: the form provides two signatures for one « Je soussigné(e) », and
 * signing is the whole point of printing it.
 */
final class ParentalAuthorizationPdfService
{
    public function __construct(private readonly TemplateLibrary $templates)
    {
    }

    /**
     * Render the document, in memory — nothing touches the filesystem.
     *
     * @param MemberProfile $member the animé the authorization is for
     * @param ?MemberProfile $leader the section's responsable, with their
     *        addresses loaded — `hydrateMemberProfile()` does not load them,
     *        so the caller resolves the full profile
     *        (`MemberService::findProfileByMemberAndYear()`, ARCHITECTURE.md
     *        §8.22). Null when the section has designated nobody, which
     *        leaves the federation's own blank lines for a pen.
     * @param string $unit « code — nom » of the unit, or just the name
     * @return array{pdf: string, overflowing: list<string>} the bytes, and
     *         the fields that did not fit even at the smallest size — the
     *         screen says so rather than letting the parent discover it on
     *         paper
     * @throws OfficialDocumentsException when the template is missing or
     *         cannot be read
     */
    public function render(
        MemberProfile $member,
        ?MemberProfile $leader,
        string $unit,
        ParentalAuthorizationInput $input,
        \DateTimeImmutable $today
    ): array {
        if (!$this->templates->has(TemplateLibrary::PARENTAL_AUTHORIZATION)) {
            throw new OfficialDocumentsException(
                'Le formulaire officiel est introuvable sur ce site. Prévenez votre chef d\'unité.'
            );
        }

        $pdf = new OverlayPdf();

        try {
            $pdf->openTemplate($this->templates->path(TemplateLibrary::PARENTAL_AUTHORIZATION));
            $pdf->startPage(1);
        } catch (\Throwable $e) {
            // Never the library's own message: FPDI names the file it could
            // not parse, and this exception's text is shown to a parent.
            throw new OfficialDocumentsException(
                'Le formulaire officiel n\'a pas pu être ouvert. Prévenez votre chef d\'unité.',
                0,
                $e
            );
        }

        $fields = ParentalAuthorizationLayout::textFields();
        $zones = ParentalAuthorizationLayout::strikeZones();
        $overflowing = [];

        foreach (ParentalAuthorizationFilling::values($member, $leader, $unit, $input, $today) as $name => $value) {
            if (!$pdf->writeText($value, $fields[$name])) {
                $overflowing[] = $name;
            }
        }

        foreach (ParentalAuthorizationFilling::strikes($member, $input) as $name) {
            $pdf->strikeThrough($zones[$name]);
        }

        return ['pdf' => $pdf->render(), 'overflowing' => $overflowing];
    }
}
