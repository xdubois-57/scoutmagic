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
 *
 * **What an administrator actually sees is no longer checked here.** This
 * file used to assert that the Configuration menu rendered in the order
 * its `addPage` calls are written in, which was true while those orders
 * were ad hoc and stopped being true the moment every entry declared one
 * grouped by column. Both halves of that check now live somewhere
 * better: `testNoTwoConfigurationEntriesShareAnOrderNumber` below catches
 * the duplicate order it was really guarding against, and
 * `Tests\Core\View\Menu\MenuSnapshotTest` renders every menu for every
 * role through the real builder.
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

    /**
     * The same entries, but in the order a browser would SHOW them:
     * sorted on the `order` argument, ties broken by registration order
     * exactly as `usort()` does since PHP 8.0.
     *
     * This exists because the registration list above is not that order,
     * and the difference is precisely where a defect hides: « Stockage »
     * was once given 47 — the number « E-mails » already carried — and
     * rendered two entries away from where it was meant to, with the
     * registration-order test above perfectly green.
     *
     * @return string[] page labels, in rendered order
     */
    private function renderedPageLabelsForMenu(string $menuConstant): array
    {
        preg_match_all(
            '/\$menuBuilder->addPage\(\s*MenuBuilder::' . preg_quote($menuConstant, '/')
                . ',\s*\'([^\']+)\',\s*\'[^\']*\',\s*\'[^\']*\',\s*(\d+)/',
            $this->indexPhp,
            $matches,
            PREG_SET_ORDER
        );

        /** @var list<array{string, int}> $entries */
        $entries = [];
        foreach ($matches as $match) {
            $entries[] = [$match[1], (int) $match[2]];
        }

        usort($entries, static fn (array $a, array $b): int => $a[1] <=> $b[1]);

        return array_map(static fn (array $entry): string => $entry[0], $entries);
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
            [
                'Modules', 'Pages de texte', 'Badges', 'Correspondances Desk', 'Réglages', 'RGPD',
                'Actions planifiées', 'Comptes superadmin', 'Maintenance', 'Notifications',
                'E-mails', 'Courrier sortant', 'Stockage', 'Support',
                'Synchronisation des contacts',
            ],
            array_slice($labels, 1)
        );
    }

    /**
     * The rule that keeps the test above meaningful: as soon as two
     * entries share a number, the file's order silently becomes load-bearing.
     */
    public function testNoTwoConfigurationEntriesShareAnOrderNumber(): void
    {
        preg_match_all(
            '/\$menuBuilder->addPage\(\s*MenuBuilder::MENU_CONFIGURATION,'
                . '\s*\'([^\']+)\',\s*\'[^\']*\',\s*\'[^\']*\',\s*(\d+)/',
            $this->indexPhp,
            $matches,
            PREG_SET_ORDER
        );
        $this->assertNotSame([], $matches, 'No Configuration menu entry was parsed at all.');

        $byOrder = [];
        foreach ($matches as $match) {
            $byOrder[(int) $match[2]][] = $match[1];
        }
        $shared = array_filter($byOrder, static fn (array $labels): bool => count($labels) > 1);

        $this->assertSame(
            [],
            $shared,
            'Each Configuration entry needs an order of its own: ' . json_encode($shared, JSON_UNESCAPED_UNICODE)
        );
    }
}
