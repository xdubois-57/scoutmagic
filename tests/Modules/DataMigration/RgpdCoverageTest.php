<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\DataMigration;

use Modules\DataMigration\Service\RunService;
use PHPUnit\Framework\TestCase;

/**
 * The deposited file holds the unit's personal data for as long as it is
 * on the server: the RGPD page and the prompt that regenerates it say
 * what is processed and for how long (AGENTS.md § RGPD page maintenance),
 * and the duration they state is the one the purge applies.
 */
final class RgpdCoverageTest extends TestCase
{
    private static function read(string $path): string
    {
        return (string) preg_replace('/\s+/u', ' ', (string) file_get_contents(dirname(__DIR__, 3) . '/' . $path));
    }

    public function testTheDefaultPageDescribesTheModuleAndTheFileRetention(): void
    {
        $page = self::read('core/View/rgpd_default.html');

        $this->assertStringContainsString('<h4>Module Migration de données</h4>', $page);
        $this->assertStringContainsString('au plus tard <strong>24 heures</strong>', $page);
        $this->assertStringContainsString('<li><strong>Fichiers déposés pour la migration de données</strong>', $page);
        $this->assertStringContainsString('<strong>jamais le nom d\'une personne</strong>', $page);
        // Only the site's administrator reaches the page (Configuration menu).
        $this->assertStringContainsString('Le fichier déposé par l\'administrateur du site', $page);
        $this->assertStringNotContainsString('déposé par un chef d\'unité', $page);
        // The gallery import reads each album's page on its host (lot F).
        $this->assertStringContainsString('<p><strong>Galeries photo</strong> : pour chaque album importé', $page);
    }

    /**
     * The ICS import is the module's one outbound request (AGENTS.md
     * § RGPD — a new outbound flow is a documentation change): the page
     * says where it goes, that nothing of the unit's is sent, and that
     * the address is not kept.
     */
    public function testTheDefaultPageDescribesTheCalendarDownload(): void
    {
        $page = self::read('core/View/rgpd_default.html');

        $this->assertStringContainsString('<p><strong>Copie d\'un calendrier en ligne</strong>', $page);
        $this->assertStringContainsString('<strong>aucune donnée de l\'unité</strong>', $page);
        $this->assertStringContainsString('L\'adresse elle-même n\'est pas conservée', $page);

        $prompt = self::read('core/View/RgpdContentService.php');
        $this->assertStringContainsString('(f) le paragraphe « Copie d\'un calendrier en ligne »', $prompt);
    }

    public function testThePromptTellsTheModelWhatToKeepAndWhenToRemoveIt(): void
    {
        $prompt = self::read('core/View/RgpdContentService.php');

        $this->assertStringContainsString('**Module Migration de données (module data_migration)**', $prompt);
        $this->assertStringContainsString('retire entièrement la sous-section "Module Migration de données"', $prompt);
        $this->assertStringContainsString('par l\'administrateur du site seul', $prompt);
    }

    /**
     * The email history import stores personal data in mass_mail that no
     * native send does: the sender as the former site recorded it.
     */
    public function testTheImportedEmailsAndTheirOriginalSenderAreDescribed(): void
    {
        $page = self::read('core/View/rgpd_default.html');
        $prompt = self::read('core/View/RgpdContentService.php');

        $this->assertStringContainsString('<strong>Courriels importés de l\'ancien site</strong>', $page);
        $this->assertStringContainsString(
            '<strong>adresse et le nom de son expéditeur d\'origine</strong> (chiffrés au repos)',
            $page,
        );
        $this->assertStringContainsString('« Courriels importés de l\'ancien site »', $prompt);
    }

    public function testThePromisedDurationIsTheOneThePurgeApplies(): void
    {
        $this->assertSame(24 * 3600, RunService::FILE_RETENTION_SECONDS);
    }
}
