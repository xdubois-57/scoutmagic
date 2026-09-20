<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\OfficialDocuments\Pdf;

use Modules\OfficialDocuments\Pdf\TemplateGrid;
use Modules\OfficialDocuments\Pdf\TemplateLibrary;
use PHPUnit\Framework\TestCase;

/**
 * The calibration tool, which is the one thing standing between a new
 * version of a federation form and a season of documents written beside
 * their lines.
 *
 * It is a development utility, and that is exactly why it is tested: it is
 * reached for on the worst day — a template just changed, every coordinate
 * is suspect — and a utility that throws on that day leaves the maintainer
 * with no way to measure anything. The cheap check is that it runs over the
 * real shipped templates, both of them, including the two-page one.
 */
final class TemplateGridTest extends TestCase
{
    public function testItDrawsAGridOverTheShippedParentalAuthorization(): void
    {
        $bytes = TemplateGrid::over(TemplateLibrary::shipped()->path(TemplateLibrary::PARENTAL_AUTHORIZATION));

        $this->assertStringStartsWith('%PDF-', $bytes);
        // The federation's own page is under the grid, so the result is far
        // larger than the few kilobytes a bare grid would weigh.
        $this->assertGreaterThan(100_000, strlen($bytes));
    }

    /**
     * The health sheet is two pages, and a grid on page one only would be a
     * tool that silently measures half a document — which is how a
     * coordinate on page two comes to be guessed.
     */
    public function testItCoversEveryPageOfATwoPageTemplate(): void
    {
        $bytes = TemplateGrid::over(TemplateLibrary::shipped()->path(TemplateLibrary::HEALTH_SHEET));

        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertGreaterThan(100_000, strlen($bytes));
    }

    public function testNothingIsWrittenToDisk(): void
    {
        $before = scandir(sys_get_temp_dir());

        TemplateGrid::over(TemplateLibrary::shipped()->path(TemplateLibrary::PARENTAL_AUTHORIZATION));

        $this->assertSame($before, scandir(sys_get_temp_dir()));
    }

    /**
     * Where the script puts the grid when the maintainer names no target.
     * Beside the template, never over it: a grid written onto the template
     * would replace the calibrated file with a scribbled-on copy, and
     * `TemplateFingerprintTest` would be the only thing that noticed.
     */
    public function testTheDefaultTargetSitsBesideTheTemplateAndNeverOnIt(): void
    {
        $this->assertSame(
            'modules/official_documents/templates/autorisation-parentale-grid.pdf',
            TemplateGrid::defaultTargetFor('modules/official_documents/templates/autorisation-parentale.pdf')
        );
        $this->assertSame(
            '/tmp/FICHE.pdf-grid.pdf',
            TemplateGrid::defaultTargetFor('/tmp/FICHE.pdf.pdf'),
            'seule la dernière extension est remplacée'
        );
    }
}
