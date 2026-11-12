<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\MassMail\Repository;

use Modules\MassMail\Api\ImportedRecipient;
use Modules\MassMail\Repository\AudienceRepository;
use Modules\MassMail\Repository\EmailRepository;
use Modules\MassMail\Repository\TargetRepository;
use Modules\MassMail\Service\EmailHistoryImportService;
use Modules\MassMail\Service\MassMailQueryService;
use Modules\MassMail\Service\MergeRenderer;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Modules\MassMail\ImportedEmailFixture;
use Tests\UsesProductionEngine;

/**
 * Emails imported from a former site (data_migration, lot C) on the engine
 * production runs, against the schema as the migration builds it.
 *
 * What the engine decides here and SQLite does not:
 * - the two ENUMs the lot widened: a strict server refuses `imported` and
 *   `unknown` outright unless the migration really added them;
 * - `import_source`, VARBINARY, compared byte for byte: `A-1` is not `a-1`,
 *   whatever the table's collation thinks of letters;
 * - `body_html` as MEDIUMTEXT: a body past TEXT's 64 KiB is kept whole;
 * - the member history's order on a `CASE` inside a derived table, which
 *   puts an imported email at its historical date and not at the day it
 *   was imported.
 */
#[Group('database')]
final class ImportedEmailsOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private \PDO $pdo;
    private string $storage;
    private EmailHistoryImportService $service;

    protected function setUp(): void
    {
        $this->pdo = $this->productionEngine();
        $this->storage = sys_get_temp_dir() . '/mm_mysql_' . bin2hex(random_bytes(6));
        mkdir($this->storage, 0700, true);
        $this->pdo->exec("INSERT INTO age_branches (desk_code, label) VALUES ('STAFF', 'Staff d''U')");
        $branch = (int) $this->pdo->lastInsertId();
        $this->pdo->exec(
            "INSERT INTO sections (age_branch_id, desk_code, name) VALUES ({$branch}, 'STAFFDU', 'Staff d''U')",
        );
        $this->service = ImportedEmailFixture::service($this->pdo, $this->storage);
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->storage, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        ) as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->storage);
    }

    public function testTheMigrationWidenedTheEnumsAndTheBody(): void
    {
        $types = [];
        foreach ($this->pdo->query(
            "SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND (
                (TABLE_NAME = 'mass_mail_emails' AND COLUMN_NAME IN ('list_type', 'body_html', 'import_source'))
                OR (TABLE_NAME = 'mass_mail_deliveries' AND COLUMN_NAME = 'status'))"
        )->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $types[$row['TABLE_NAME'] . '.' . $row['COLUMN_NAME']] = strtolower((string) $row['COLUMN_TYPE']);
        }

        $this->assertStringContainsString("'imported'", $types['mass_mail_emails.list_type'] ?? '');
        $this->assertStringContainsString("'unknown'", $types['mass_mail_deliveries.status'] ?? '');
        $this->assertSame('mediumtext', $types['mass_mail_emails.body_html'] ?? '');
        $this->assertSame('varbinary(400)', $types['mass_mail_emails.import_source'] ?? '');
    }

    public function testAnImportedEmailAndItsMessageOfUnknownFateAreStored(): void
    {
        $result = $this->service->import(ImportedEmailFixture::email());

        $email = (new EmailRepository($this->pdo))->findById($result->emailId);
        $this->assertTrue($email?->isImported());
        // Grouped in PHP: an ENUM sorts by its declaration order on the
        // server, not alphabetically.
        $counts = array_map('intval', $this->pdo->query(
            'SELECT status, COUNT(*) FROM mass_mail_deliveries GROUP BY status'
        )->fetchAll(\PDO::FETCH_KEY_PAIR));
        ksort($counts);
        $this->assertSame(['error' => 1, 'sent' => 1, 'unknown' => 1], $counts);
    }

    public function testTheProvenanceIsComparedByteForByte(): void
    {
        // The premise: this table's collation would call the two equal.
        $this->assertSame(1, (int) $this->pdo->query(
            "SELECT 'A-1' = 'a-1' COLLATE utf8mb4_unicode_ci"
        )->fetchColumn());

        $result = $this->service->import(ImportedEmailFixture::email('A-1', []));

        $this->assertSame($result->emailId, $this->service->findImported("17:site-exempleville\nA-1"));
        $this->assertNull($this->service->findImported("17:site-exempleville\na-1"));
        $this->assertNull($this->service->findImported("17:site-exempleville\nA-1 "));
    }

    public function testABodyPastSixtyFourKibibytesIsKeptWhole(): void
    {
        $paragraph = '<p>' . str_repeat('Une longue lettre d\'information. ', 40) . '</p>';
        $body = str_repeat($paragraph, 80);
        $this->assertGreaterThan(65535, strlen($body));

        $email = new \Modules\MassMail\Api\ImportedEmail(
            "site-exempleville\nc-long",
            'Lettre longue',
            $body,
            new \DateTimeImmutable('2020-02-01T09:00:00+01:00'),
            'staff@exemple.test'
        );
        $result = $this->service->import($email);

        $stored = (string) (new EmailRepository($this->pdo))->findById($result->emailId)?->bodyHtml;
        $this->assertGreaterThan(65535, strlen($stored));
        $this->assertStringEndsWith('</p>', $stored);
    }

    public function testTheMemberHistoryPlacesAnImportedEmailAtItsHistoricalDate(): void
    {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('900001')");
        $memberId = (int) $this->pdo->lastInsertId();
        $this->service->import(ImportedEmailFixture::email('c-old', [
            new ImportedRecipient('famille@exemple.test', ImportedRecipient::UNKNOWN, null, [$memberId]),
        ]));

        // A native email to the same member, sent today.
        $sectionId = (int) $this->pdo->query("SELECT id FROM sections WHERE desk_code = 'STAFFDU'")->fetchColumn();
        $this->pdo->prepare(
            "INSERT INTO mass_mail_emails (subject, body_html, section_id, list_type, status, sent_at)
             VALUES ('Aujourd''hui', '<p>x</p>', ?, 'default_active_members', 'sent', NOW())"
        )->execute([$sectionId]);
        $nativeId = (int) $this->pdo->lastInsertId();
        $target = (new TargetRepository($this->pdo))->create($nativeId, $memberId, null);
        $deliveries = new \Modules\MassMail\Repository\DeliveryRepository(
            $this->pdo,
            ImportedEmailFixture::encryption(),
        );
        $delivery = $deliveries->create($nativeId, 'famille@exemple.test');
        $deliveries->linkTargets($delivery, [$target]);
        $deliveries->recordSendSuccess($delivery);

        $entries = (new MassMailQueryService(
            new TargetRepository($this->pdo),
            new AudienceRepository($this->pdo, ImportedEmailFixture::encryption()),
            new MergeRenderer()
        ))->getRecentEmailsForMember($memberId, 10);

        $this->assertSame(['Aujourd\'hui', "Infos camp d'été 2024"], array_column($entries, 'subject'));
        $this->assertFalse($entries[0]['imported']);
        $this->assertTrue($entries[1]['imported']);
        $this->assertFalse($entries[1]['not_sent'], 'an unknown is not a failure');
        $this->assertStringStartsWith('2024-05-21', $entries[1]['sent_at']);
    }

    public function testTheRepositoryRefusesToDeleteAnImportedEmailOnThisEngineToo(): void
    {
        $result = $this->service->import(ImportedEmailFixture::email('c-del', []));
        $this->pdo->prepare("UPDATE mass_mail_emails SET status = 'draft' WHERE id = ?")->execute([$result->emailId]);

        $this->assertFalse((new EmailRepository($this->pdo))->deleteIfNotStarted($result->emailId));
        $this->assertNotNull((new EmailRepository($this->pdo))->findById($result->emailId));
    }
}
