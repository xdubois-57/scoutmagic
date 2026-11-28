<?php

declare(strict_types=1);

namespace Tests\Modules\OfficialDocuments\Service;

use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\EncryptionService;
use Modules\OfficialDocuments\Repository\HealthSheetRepository;
use Modules\OfficialDocuments\Service\HealthSheetMergeListener;
use Modules\OfficialDocuments\Value\HealthSheet;
use PHPUnit\Framework\TestCase;
use Tests\UsesProductionEngine;

/**
 * Issue #1462: a merge brings the child's health sheet along, and a
 * collision — one sheet on each identity — is journalled, never silent.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class HealthSheetMergeListenerTest extends TestCase
{
    use UsesProductionEngine;

    private \PDO $pdo;
    private HealthSheetRepository $sheets;
    private HealthSheetMergeListener $listener;
    private int $kept;
    private int $duplicate;

    protected function setUp(): void
    {
        $this->pdo = $this->productionEngine();
        $this->sheets = new HealthSheetRepository(
            $this->pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
        $this->listener = new HealthSheetMergeListener(
            $this->sheets,
            new JournalService(new JournalRepository($this->pdo))
        );
        $this->kept = $this->member('HSM-1');
        $this->duplicate = $this->member('HSM-2');
    }

    private function member(string $deskId): int
    {
        $this->pdo->prepare('INSERT INTO members (desk_id) VALUES (?)')->execute([$deskId]);

        return (int) $this->pdo->lastInsertId();
    }

    public function testTheSheetIsPreviewedAndBroughtAlongWithoutAJournalLine(): void
    {
        $this->sheets->save(
            $this->duplicate,
            HealthSheet::fromArray(['allergies' => 'pollen']),
            new \DateTimeImmutable()
        );

        $this->assertSame(['fiche santé' => 1], $this->listener->mergePreview($this->duplicate));
        $this->assertSame(1, $this->listener->onMembersMerged($this->kept, $this->duplicate));

        $this->assertSame('pollen', $this->sheets->findForMember($this->kept)?->allergies);
        $this->assertSame(0, (int) $this->pdo->query(
            "SELECT COUNT(*) FROM event_log WHERE event_type = 'health_sheet_merge_collision'"
        )->fetchColumn());
    }

    public function testACollisionIsJournalledWithIdsAndNothingOfTheSheet(): void
    {
        $this->sheets->save(
            $this->kept,
            HealthSheet::fromArray(['allergies' => 'arachides']),
            new \DateTimeImmutable('2026-01-01')
        );
        $this->sheets->save(
            $this->duplicate,
            HealthSheet::fromArray(['allergies' => 'pollen']),
            new \DateTimeImmutable('2026-02-01')
        );

        $this->listener->onMembersMerged($this->kept, $this->duplicate);

        $row = $this->pdo->query(
            "SELECT level, context FROM event_log WHERE event_type = 'health_sheet_merge_collision'"
        )->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($row);
        $this->assertSame('warning', $row['level']);
        $context = json_decode((string) $row['context'], true);
        $this->assertSame('merged_member', $context['kept_sheet_of']);
        $this->assertSame($this->kept, $context['kept_member_id']);
        $this->assertStringNotContainsString('pollen', (string) $row['context']);
        $this->assertStringNotContainsString('arachides', (string) $row['context']);
    }
}
