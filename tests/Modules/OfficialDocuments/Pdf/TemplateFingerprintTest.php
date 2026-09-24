<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\OfficialDocuments\Pdf;

use Modules\OfficialDocuments\Pdf\TemplateLibrary;
use PHPUnit\Framework\TestCase;

/**
 * The only thing standing between a silently replaced federation form and a
 * season of unreadable documents.
 *
 * Values are written on these templates at fixed millimetre positions. A new
 * version of a form whose margin moved three millimetres puts every one of
 * them beside its line — the site keeps producing documents, they are just
 * wrong, and nothing anywhere raises a thing. Nobody finds out until a
 * parent says so.
 *
 * So this test fails the moment either file's bytes change, and its failure
 * message carries the procedure rather than pointing at one: the person who
 * just replaced a template is reading THIS, not a README three directories
 * away.
 */
final class TemplateFingerprintTest extends TestCase
{
    /**
     * The two templates as `qpdf --object-streams=disable --force-version=1.4`
     * produced them, and as `modules/official_documents/templates/README.md`
     * records them.
     *
     * Never recomputed from a file somebody converted again: a second
     * conversion produces different bytes, so a digest taken from it would
     * pin nothing at all.
     */
    private const FINGERPRINTS = [
        TemplateLibrary::PARENTAL_AUTHORIZATION =>
            '7bdc1f93c7ef3f659522a19955928dd34e0fe429a21999214e13acc84a9ad928',
        TemplateLibrary::HEALTH_SHEET =>
            '26b573fa86f2658aaff238d329634dbae701a07c53a88fc1a031e4569559a73f',
    ];

    public function testEveryShippedTemplateIsTheOneThatWasCalibrated(): void
    {
        $library = TemplateLibrary::shipped();

        foreach (self::FINGERPRINTS as $template => $expected) {
            $path = $library->path($template);

            $this->assertFileExists($path, "The {$template} template is gone from modules/official_documents/templates/.");

            $this->assertSame(
                $expected,
                hash_file('sha256', $path),
                $this->procedureFor($template)
            );
        }
    }

    /**
     * What to do about a failure — the whole of it, in the message.
     */
    private function procedureFor(string $template): string
    {
        return <<<TEXT
            Le gabarit {$template} n'est plus celui sur lequel les coordonnées ont été calées.

            Si c'est volontaire — la fédération a publié une nouvelle version — la marche à suivre
            est celle-ci, dans cet ordre :

              1. Convertir le fichier reçu, sur un poste de développement, jamais sur le serveur :
                     qpdf --object-streams=disable --force-version=1.4 \\
                          <fichier-reçu>.pdf \\
                          modules/official_documents/templates/{$template}
              2. Produire le gabarit avec sa grille millimétrée :
                     php scripts/pdf-template-grid.php modules/official_documents/templates/{$template}
              3. Recaler la carte de coordonnées du document, puis REGARDER le PDF produit.
                 Aucun test ne peut vérifier qu'un texte est en face de la bonne ligne pointillée.
              4. Une fois le rendu correct, et seulement là, mettre l'empreinte à jour ici et dans
                 modules/official_documents/templates/README.md :
                     shasum -a 256 modules/official_documents/templates/{$template}

            Si ce n'est pas volontaire, le gabarit a été remplacé ou recompressé par erreur :
            restaurez-le depuis l'historique git plutôt que de mettre à jour l'empreinte.
            TEXT;
    }
}
