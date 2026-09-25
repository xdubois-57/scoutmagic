<?php

declare(strict_types=1);

namespace Tests\Modules\SupportDashboard;

use Core\Security\EncryptionService;
use Modules\SupportDashboard\Repository\DeskMappingGapRepository;
use Modules\SupportDashboard\Repository\SupportInstallationRepository;
use Modules\SupportDashboard\Service\DeskMappingGapReport;
use Modules\SupportDashboard\Service\StatisticsIntakeService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The central list of unresolved Desk values (issue #356), derived from the
 * reports this receiver holds.
 *
 * Nothing is catalogued (D5), so the tests that matter most are the ones
 * about things LEAVING the list: a value this version recognises, and a
 * value somebody set aside.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class DeskMappingGapReportTest extends TestCase
{
    private \PDO $pdo;
    private SupportInstallationRepository $installations;
    private DeskMappingGapRepository $gaps;

    protected function setUp(): void
    {
        SupportDashboardTestHelper::ensureAutoloadable();
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        SupportDashboardTestHelper::createTables($this->pdo);

        $this->installations = new SupportInstallationRepository(
            $this->pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
        $this->gaps = new DeskMappingGapRepository($this->pdo);
    }

    public function testAValueEveryoneReportsIsListedWithItsInstallationCount(): void
    {
        $this->reportFrom('aaaabbbbccccdddd', 'https://25e-sv.be', '1.0.33', [['function', 'Animateur Nutons']]);
        $this->reportFrom('eeeeffff00001111', 'https://12e-uccle.be', '1.0.40', [['function', 'Animateur Nutons']]);

        $rows = $this->report()->rows();

        $this->assertCount(1, $rows);
        $this->assertSame('Animateur Nutons', $rows[0]->valueRaw);
        $this->assertSame(2, $rows[0]->installations);
        // Alphabetical, not « whoever reported last »: the list must read
        // the same on two consecutive loads.
        $this->assertSame(['12e-uccle.be', '25e-sv.be'], $rows[0]->instances);
    }

    /**
     * D7. Without the folded key the same federation word is two lines,
     * two notifications, and a count that understates how widespread it is.
     */
    public function testTwoSpellingsOfTheSameWordAreOneLine(): void
    {
        $this->reportFrom('aaaabbbbccccdddd', 'https://a.be', '1.0.33', [['function', 'Animateur Nutons']]);
        $this->reportFrom('eeeeffff00001111', 'https://b.be', '1.0.33', [['function', 'animateur  nutons']]);

        $rows = $this->report()->rows();

        $this->assertCount(1, $rows);
        $this->assertSame(2, $rows[0]->installations);
    }

    /**
     * D5, and the reason nothing is catalogued: this receiver runs the
     * same software, so it answers the question itself. A branch its own
     * `canonicalSortOrder()` knows is one a release has already fixed —
     * the sender is simply behind.
     */
    public function testAValueThisVersionRecognisesNeverAppears(): void
    {
        $this->reportFrom('aaaabbbbccccdddd', 'https://a.be', '1.0.33', [
            ['branch', 'Baladins'],
            ['branch', 'Nutons'],
        ]);

        $rows = $this->report()->rows();

        $this->assertCount(1, $rows);
        $this->assertSame('Nutons', $rows[0]->valueRaw);
    }

    /**
     * Not decoration: a value corrected in the code keeps being reported
     * by everybody who has not upgraded. Without this column it gets
     * picked up again by somebody who thinks it was forgotten.
     */
    public function testTheOldestVersionStillReportingItIsTheOneShown(): void
    {
        $this->reportFrom('aaaabbbbccccdddd', 'https://a.be', '1.2.0', [['function', 'Animateur Nutons']]);
        $this->reportFrom('eeeeffff00001111', 'https://b.be', '1.0.33', [['function', 'Animateur Nutons']]);

        $this->assertSame('1.0.33', $this->report()->rows()[0]->oldestVersion);
    }

    public function testSettingAValueAsideTakesItOutOfTheDefaultViewAndLeavesItReadable(): void
    {
        $this->reportFrom('aaaabbbbccccdddd', 'https://a.be', '1.0.33', [['function', 'Animateur Baladinss']]);
        $this->gaps->rememberIfNew('function', 'animateur baladinss', 'Animateur Baladinss');
        $stored = $this->gaps->findAllKeyed()['function|animateur baladinss'];

        $this->gaps->setIgnored($stored['id'], true);

        $this->assertSame([], $this->report()->rows());

        $withIgnored = $this->report()->rows(includeIgnored: true);
        $this->assertCount(1, $withIgnored);
        $this->assertTrue($withIgnored[0]->ignored);
    }

    /**
     * Reversible, and never a delete: the next report would recreate the
     * row and the judgement would have to be made again every morning.
     */
    public function testBringingItBackRestoresIt(): void
    {
        $this->reportFrom('aaaabbbbccccdddd', 'https://a.be', '1.0.33', [['function', 'Animateur Baladinss']]);
        $this->gaps->rememberIfNew('function', 'animateur baladinss', 'Animateur Baladinss');
        $id = $this->gaps->findAllKeyed()['function|animateur baladinss']['id'];

        $this->gaps->setIgnored($id, true);
        $this->gaps->setIgnored($id, false);

        $this->assertCount(1, $this->report()->rows());
    }

    /**
     * A report this receiver cannot make sense of must cost that entry and
     * nothing else: the document was written by another installation, one
     * version ahead or simply broken.
     */
    public function testAMalformedEntryCostsOnlyItself(): void
    {
        $this->installations->register(
            'aaaabbbbccccdddd',
            password_hash('x', PASSWORD_DEFAULT),
            (string) json_encode([
                'statistics_schema_version' => 1,
                'installation_id' => 'aaaabbbbccccdddd',
                'instance_url' => 'https://a.be',
                'desk_unresolved' => ['listed' => [
                    ['kind' => 'function'],
                    ['kind' => 'inventée', 'value' => 'x'],
                    ['kind' => 'function', 'value' => 'Animateur Nutons'],
                ]],
            ]),
            []
        );

        $rows = $this->report()->rows();

        $this->assertCount(1, $rows);
        $this->assertSame('Animateur Nutons', $rows[0]->valueRaw);
    }

    /**
     * « N unités » must mean units, not payload entries.
     *
     * One installation can carry two entries that fold to the same line:
     * `functions` is unique on `desk_code`, not on `label`, so two
     * unconfirmed functions can share a label — and two spellings folding
     * together is D7 working as designed. Counting entries turned a single
     * installation into « 2 unités », and the page highlights a value in
     * red past three, so a handful of variants on ONE unit could read as a
     * federation-wide problem. That is the opposite of what this page is
     * for.
     */
    public function testOneInstallationCountsOnceHoweverManyOfItsEntriesFoldTogether(): void
    {
        $this->reportFrom('aaaa', 'https://une-unite.be', '1.2.3', [
            ['function', 'Animateur Nutons'],
            ['function', 'ANIMATEUR NUTONS'],
            ['function', 'animateur nutons'],
        ]);

        $rows = $this->report()->rows();

        $this->assertCount(1, $rows);
        $this->assertSame(1, $rows[0]->installations, 'three entries, one unit');
        $this->assertSame(['une-unite.be'], $rows[0]->instances);
    }

    /**
     * And the count still follows the units when there really are several,
     * so the fix above cannot have been a stuck 1.
     */
    public function testTheCountFollowsTheUnitsWhenSeveralReportTheSameValue(): void
    {
        $this->reportFrom('aaaa', 'https://premiere.be', '1.2.3', [
            ['function', 'Animateur Nutons'],
            ['function', 'ANIMATEUR NUTONS'],
        ]);
        $this->reportFrom('bbbb', 'https://seconde.be', '1.2.3', [['function', 'Animateur Nutons']]);

        $rows = $this->report()->rows();

        $this->assertSame(2, $rows[0]->installations);
        $this->assertSame(['premiere.be', 'seconde.be'], $rows[0]->instances);
    }

    private function report(): DeskMappingGapReport
    {
        return new DeskMappingGapReport($this->installations, $this->gaps);
    }

    /**
     * @param list<array{0: string, 1: string}> $unresolved
     */
    private function reportFrom(string $installationId, string $url, string $version, array $unresolved): void
    {
        $payload = [
            'statistics_schema_version' => 1,
            'installation_id' => $installationId,
            'instance_url' => $url,
            'scoutmagic' => ['version' => $version, 'is_dev_build' => false],
            'desk_unresolved' => [
                'total' => count($unresolved),
                'listed' => array_map(
                    static fn(array $pair): array => ['kind' => $pair[0], 'value' => $pair[1]],
                    $unresolved
                ),
            ],
        ];

        $this->installations->register(
            $installationId,
            password_hash('x', PASSWORD_DEFAULT),
            (string) json_encode($payload),
            StatisticsIntakeService::denormalize($payload)
        );
    }
}
