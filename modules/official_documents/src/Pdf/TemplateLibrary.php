<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\OfficialDocuments\Pdf;

/**
 * Where the federation's own forms live, and how one is replaced.
 *
 * The two PDFs under `modules/official_documents/templates/` are the
 * federation's documents, committed as they are. The site writes on them
 * and never rewrites them: no stamp, no added mention, no unit logo.
 *
 * ---------------------------------------------------------------------
 * REPLACING A TEMPLATE — the procedure, in the file that loads them
 * ---------------------------------------------------------------------
 *
 * The federation republishes its forms; they carry a « Version <année> »
 * mention at the top right. When a new one comes out:
 *
 * 1. **Convert it, on a development machine.** FPDI in its free version
 *    reads classic cross-reference tables only, which means PDF up to
 *    version 1.4; the federation ships 1.7, the health sheet with object
 *    streams. Nothing on the server converts anything — `qpdf` is not a
 *    dependency of this project and does not exist on shared hosting:
 *
 *        qpdf --object-streams=disable --force-version=1.4 \
 *             <file-received>.pdf \
 *             modules/official_documents/templates/<template>.pdf
 *
 * 2. **Run the tests.** `Tests\Modules\OfficialDocuments\Pdf\
 *    TemplateFingerprintTest` will fail, on purpose: it is the only thing
 *    standing between a silently replaced template and a season of
 *    unreadable documents.
 *
 * 3. **Re-align the coordinates.** Values are written at fixed millimetre
 *    positions. A margin that moved 3 mm in the new form puts every one of
 *    them beside its line, and nothing raises an error. Produce the
 *    template with its millimetre grid:
 *
 *        php scripts/pdf-template-grid.php \
 *            modules/official_documents/templates/<template>.pdf
 *
 *    then adjust that document's layout — and LOOK at the PDF. No test can
 *    check that a value is opposite the right dotted line.
 *
 * 4. **Update the SHA-256**, once, when the rendering is right: in the test
 *    above, and in `templates/README.md`'s table.
 *
 * @see modules/official_documents/templates/README.md — the same procedure,
 *   for a maintainer who is not reading the code.
 */
final class TemplateLibrary
{
    public const PARENTAL_AUTHORIZATION = 'autorisation-parentale.pdf';
    public const HEALTH_SHEET = 'fiche-sante.pdf';

    public function __construct(private readonly string $directory)
    {
    }

    /**
     * The library as the composition root builds it, from the module's own
     * directory. A caller that needs it somewhere else (a test, the grid
     * script) constructs one with its own path rather than guessing at this
     * one's.
     */
    public static function shipped(): self
    {
        return new self(dirname(__DIR__, 2) . '/templates');
    }

    public function path(string $template): string
    {
        return $this->directory . '/' . $template;
    }

    /**
     * Whether a template is actually there.
     *
     * Asked rather than assumed because the answer decides what a visitor
     * sees: a missing template is a French sentence on the page, never a
     * stack trace from FPDI about a file it could not open.
     */
    public function has(string $template): bool
    {
        return is_file($this->path($template));
    }
}
