<?php

declare(strict_types=1);

namespace Tests\Core\Http;

use PHPUnit\Framework\TestCase;

/**
 * public/index.php's core `$menuBuilder->addPage(...)` call order for
 * Espace chefs d'U and Configuration (Core\View\MenuBuilder) — a
 * structural/wiring test reading the raw file, same precedent as
 * tests/Security/FileAccessAuditTest.php and tests/Core/View/
 * ServiceWorkerPrecacheTest.php (no PHP unit test can otherwise exercise
 * this giant procedural bootstrap script's literal statement order).
 * Core\View\MenuBuilderTest already covers the sort algorithm itself
 * (group-then-order) — this test only pins down the specific `order`
 * values chosen here, which is the part a future edit could silently
 * get wrong without any other test catching it.
 */
class MenuRegistrationOrderTest extends TestCase
{
    private string $indexPhp;

    protected function setUp(): void
    {
        $this->indexPhp = (string) file_get_contents(dirname(__DIR__, 3) . '/public/index.php');
    }

    /**
     * @return string[] page labels, in the order they appear in the file
     */
    private function addPageLabelsForMenu(string $menuConstant): array
    {
        preg_match_all(
            '/\$menuBuilder->addPage\(\s*MenuBuilder::' . preg_quote($menuConstant, '/') . ',\s*\'([^\']+)\'/',
            $this->indexPhp,
            $matches
        );

        return $matches[1];
    }

    public function testEspaceChefsDUOrderIsConfigGeneraleImportMembresAnneeJournal(): void
    {
        $labels = $this->addPageLabelsForMenu('MENU_ESPACE_ADMIN');

        $this->assertSame(
            ['Édition du site', 'Import Desk', 'Membres', 'Année scoute', 'Journal'],
            $labels
        );
    }

    public function testConfigurationMenuHasConfigurationAvanceeFirstThenTheRestUnchanged(): void
    {
        $labels = $this->addPageLabelsForMenu('MENU_CONFIGURATION');

        $this->assertSame('Installation & serveur', $labels[0]);
        $this->assertSame(
            ['Modules', 'Badges', 'Desk', 'Réglages', 'RGPD', 'Actions planifiées', 'Comptes superadmin', 'Maintenance', 'Notifications', 'E-mails', 'Support'],
            array_slice($labels, 1)
        );
    }
}
