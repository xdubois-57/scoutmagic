<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Journal;

use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\UsesProductionEngine;

/**
 * A journal line always fits, on the engine an installation runs (issue
 * #1438).
 *
 * SQLite — the rest of the suite — stores a description of any length in
 * `event_log.description`; MySQL/MariaDB in strict mode refuse the 501st
 * character of its VARCHAR(500), and the half of an « é » a byte cut
 * leaves behind. A context with a byte that is not UTF-8 made
 * json_encode() return false on every engine. Each of these used to throw
 * at the caller — the scheduler's catch block among them.
 */
#[Group('database')]
final class JournalServiceOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private \PDO $pdo;
    private JournalService $journal;

    protected function setUp(): void
    {
        $this->pdo = $this->productionEngine();
        $this->journal = new JournalService(new JournalRepository($this->pdo));
    }

    public function testADescriptionOfTwoThousandCharactersIsWrittenCutToTheColumn(): void
    {
        $this->journal->log('core', 'long_line', 'info', str_repeat('é', 2000));

        $description = (string) $this->pdo->query("SELECT description FROM event_log WHERE event_type = 'long_line'")->fetchColumn();
        $this->assertSame(JournalService::DESCRIPTION_MAX, mb_strlen($description, 'UTF-8'));
        $this->assertStringEndsWith('…', $description);
        $this->assertTrue(mb_check_encoding($description, 'UTF-8'));
    }

    public function testAContextThatIsNotUtf8IsWrittenWithItsBadBytesReplaced(): void
    {
        $this->journal->log('core', 'bad_bytes', 'info', "Bo\xEEte pleine", ['error' => "Bo\xEEte pleine"]);

        $row = $this->pdo->query("SELECT description, context FROM event_log WHERE event_type = 'bad_bytes'")->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($row);
        $this->assertTrue(mb_check_encoding((string) $row['description'], 'UTF-8'));
        $context = json_decode((string) $row['context'], true);
        $this->assertIsArray($context);
        $this->assertStringStartsWith('Bo', $context['error']);
        $this->assertStringEndsWith('te pleine', $context['error']);
    }

    public function testACategoryAndTypeLongerThanTheirColumnsAreCutToFit(): void
    {
        $this->journal->log(str_repeat('c', 80), str_repeat('t', 150), 'info', 'Ligne');

        $row = $this->pdo->query("SELECT category, event_type FROM event_log WHERE description = 'Ligne'")->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($row);
        $this->assertSame(JournalService::CATEGORY_MAX, mb_strlen((string) $row['category']));
        $this->assertSame(JournalService::TYPE_MAX, mb_strlen((string) $row['event_type']));
    }
}
