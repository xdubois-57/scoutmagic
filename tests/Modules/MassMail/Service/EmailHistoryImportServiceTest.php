<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\MassMail\Service;

use Modules\MassMail\Api\ImportedAttachment;
use Modules\MassMail\Api\ImportedRecipient;
use Modules\MassMail\Api\MassMailException;
use Modules\MassMail\Repository\Delivery;
use Modules\MassMail\Repository\DeliveryRepository;
use Modules\MassMail\Repository\Email;
use Modules\MassMail\Repository\EmailRepository;
use Modules\MassMail\Repository\ImportedEmailRepository;
use Modules\MassMail\Service\EmailHistoryImportService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\MassMail\ImportedEmailFixture;
use Tests\Modules\MassMail\MassMailTestHelper;

/**
 * mass_mail's import interface (chantier « Migration de données », lot C):
 * an email of a former site written into the history, and NOTHING sent —
 * no pending message for Task\SendBatchHandler, no batch scheduled, no
 * notification, no mail.
 */
#[Group('database')]
final class EmailHistoryImportServiceTest extends TestCase
{
    private \PDO $pdo;
    private string $storage;
    private EmailHistoryImportService $service;
    private int $sectionId;
    private int $staffId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        MassMailTestHelper::createTables($this->pdo);
        $this->storage = sys_get_temp_dir() . '/mm_import_' . bin2hex(random_bytes(6));
        mkdir($this->storage, 0700, true);
        $this->service = ImportedEmailFixture::service($this->pdo, $this->storage);

        $this->statement("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('ECL', 'Éclaireurs', 30)");
        $branch = (int) $this->pdo->lastInsertId();
        $this->statement(
            'INSERT INTO sections (desk_code, age_branch_id, name, email) VALUES (?, ?, ?, ?)',
            ['ECL01', $branch, 'Éclaireurs', ' Eclaireurs@Exemple.test '],
        );
        $this->sectionId = (int) $this->pdo->lastInsertId();
        $this->staffId = ImportedEmailFixture::staffSection($this->pdo);
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

    public function testTheEmailIsWrittenSentImportedAndSanitized(): void
    {
        $result = $this->service->import(ImportedEmailFixture::email());

        $email = (new EmailRepository($this->pdo))->findById($result->emailId);
        $this->assertNotNull($email);
        $this->assertTrue($email->isImported());
        $this->assertFalse($email->isEditable());
        $this->assertSame(Email::STATUS_SENT, $email->status);
        $this->assertSame(Email::LIST_TYPE_IMPORTED, $email->listType);
        $this->assertSame([], $email->scoutYearIds);
        $this->assertNull($email->listId);
        $this->assertNull($email->audienceId);
        $this->assertNull($email->createdBy);
        $this->assertSame('Parents des Éclaireurs', $email->originalAudienceName);
        $this->assertStringNotContainsString('<script', $email->bodyHtml);
        $this->assertStringContainsString('Le camp aura lieu en juillet.', $email->bodyHtml);
        // The historical date, in the site's local time, is the creation
        // and the sending: the list sorts it among the emails of 2024.
        $expected = (new \DateTimeImmutable('2024-05-21T18:30:00+02:00'))
            ->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
        $this->assertSame($expected, $email->sentAt);
        $this->assertSame($expected, $email->createdAt);
    }

    public function testEveryKnownMessageKeepsItsOriginalStatusAndNoneIsPending(): void
    {
        $result = $this->service->import(ImportedEmailFixture::email());

        $this->assertSame(3, $result->deliveries);
        $deliveries = (new DeliveryRepository($this->pdo, ImportedEmailFixture::encryption()))
            ->findByEmailId($result->emailId);
        $byAddress = [];
        foreach ($deliveries as $delivery) {
            $byAddress[$delivery->emailAddress] = $delivery;
        }
        $this->assertSame(Delivery::STATUS_SENT, $byAddress['famille.alpha@exemple.test']->status);
        $this->assertNotNull($byAddress['famille.alpha@exemple.test']->sentAt);
        $this->assertSame(Delivery::STATUS_UNKNOWN, $byAddress['famille.beta@exemple.test']->status);
        $this->assertNull($byAddress['famille.beta@exemple.test']->sentAt);
        $this->assertSame(Delivery::STATUS_ERROR, $byAddress['ancien.chef@exemple.test']->status);
        $this->assertSame(
            EmailHistoryImportService::FAILED_MESSAGE,
            $byAddress['ancien.chef@exemple.test']->errorMessage,
        );
        foreach ($deliveries as $delivery) {
            $this->assertSame(0, $delivery->attempts, 'no attempt was made on this site');
        }

        // The whole of « no side effect », as the database sees it.
        $this->assertSame(0, $this->rows("SELECT COUNT(*) FROM mass_mail_deliveries WHERE status = 'pending'"));
        $this->assertSame(0, $this->rows('SELECT COUNT(*) FROM scheduled_actions'));
        $this->assertSame(0, $this->rows('SELECT COUNT(*) FROM notifications'));
        $this->assertSame(0, $this->rows('SELECT COUNT(*) FROM mass_mail_notified_accounts'));
    }

    public function testTheAddressesAndTheOriginalSenderAreEncryptedAtRest(): void
    {
        $result = $this->service->import(ImportedEmailFixture::email());

        $row = $this->statement(
            'SELECT original_sender_address_encrypted, original_sender_name_encrypted FROM mass_mail_emails WHERE id = ?',
            [$result->emailId],
        )->fetch(\PDO::FETCH_ASSOC);
        $this->assertStringNotContainsString(
            'eclaireurs@exemple.test',
            (string) $row['original_sender_address_encrypted'],
        );
        $this->assertStringNotContainsString('Exempleville', (string) $row['original_sender_name_encrypted']);
        $ciphers = $this->statement('SELECT email_address_encrypted FROM mass_mail_deliveries')
            ->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($ciphers as $cipher) {
            $this->assertStringNotContainsString('@exemple.test', (string) $cipher);
        }

        $senders = (new ImportedEmailRepository($this->pdo, ImportedEmailFixture::encryption()))
            ->findOriginalSenders([$result->emailId]);
        $this->assertSame(
            ['address' => 'eclaireurs@exemple.test', 'name' => 'Éclaireurs 99e Exempleville'],
            $senders[$result->emailId]
        );
    }

    public function testAnEmailWithoutAnyRecipientIsImportable(): void
    {
        $result = $this->service->import(ImportedEmailFixture::email('c-sans-destinataire', []));

        $this->assertSame(0, $result->deliveries);
        $this->assertSame(0, $result->targets);
        $this->assertNotNull((new EmailRepository($this->pdo))->findById($result->emailId));
    }

    public function testTheSectionIsTheOneWhoseAddressSentItElseTheStaff(): void
    {
        // Compared trimmed and lowercased, in PHP: no collation decides.
        $this->assertSame($this->sectionId, $this->service->senderSection('ECLAIREURS@exemple.test')?->sectionId);
        $this->assertTrue($this->service->senderSection('eclaireurs@exemple.test')?->fromSenderAddress);

        $staff = $this->service->senderSection('quelquun@ailleurs.test');
        $this->assertSame($this->staffId, $staff?->sectionId);
        $this->assertFalse($staff?->fromSenderAddress);

        $result = $this->service->import(ImportedEmailFixture::email('c-1', [], [], [], 'inconnu@ailleurs.test'));
        $this->assertSame($this->staffId, (new EmailRepository($this->pdo))->findById($result->emailId)?->sectionId);
    }

    public function testTwoSectionsSharingTheAddressLeaveTheEmailToTheStaff(): void
    {
        $this->statement(
            "INSERT INTO sections (desk_code, age_branch_id, name, email) "
                . "SELECT 'ECL02', age_branch_id, 'Éclaireurs B', "
                . "'eclaireurs@exemple.test' FROM sections WHERE id = ?",
            [$this->sectionId],
        );

        $section = $this->service->senderSection('eclaireurs@exemple.test');

        $this->assertSame($this->staffId, $section?->sectionId);
        $this->assertFalse($section?->fromSenderAddress);
    }

    public function testWithoutAStaffSectionAnUnrecognisedSenderHasNowhereToGo(): void
    {
        $this->statement("DELETE FROM sections WHERE desk_code = 'STAFFDU'");

        $this->assertNull($this->service->senderSection('quelquun@ailleurs.test'));
        $this->expectException(MassMailException::class);
        $this->service->import(ImportedEmailFixture::email('c-2', [], [], [], 'quelquun@ailleurs.test'));
    }

    public function testAContentEmptyOnceSanitizedIsRefused(): void
    {
        $email = new \Modules\MassMail\Api\ImportedEmail(
            "site\nc-vide",
            'Sujet',
            '<script>alert(1)</script>',
            new \DateTimeImmutable('2020-01-01T10:00:00Z'),
            'staff@exemple.test'
        );

        $this->expectException(MassMailException::class);
        $this->service->import($email);
    }

    public function testMembersBecomeTargetsLinkedToTheirMessageWithTheirLatestYear(): void
    {
        $this->statement(
            "INSERT INTO scout_years (label, start_date, end_date) VALUES ('2022-2023', '2022-09-01', '2023-08-31')",
        );
        $older = (int) $this->pdo->lastInsertId();
        $this->statement(
            "INSERT INTO scout_years (label, start_date, end_date) VALUES ('2023-2024', '2023-09-01', '2024-08-31')",
        );
        $latest = (int) $this->pdo->lastInsertId();
        $this->statement("INSERT INTO members (desk_id) VALUES ('900001')");
        $alpha = (int) $this->pdo->lastInsertId();
        $this->statement("INSERT INTO members (desk_id) VALUES ('900003')");
        $withoutAddress = (int) $this->pdo->lastInsertId();
        foreach ([$older, $latest] as $year) {
            $this->statement(
                "INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted) "
                    . "VALUES (?, ?, 'x', 'y')",
                [$alpha, $year],
            );
        }

        $result = $this->service->import(ImportedEmailFixture::email('c-3', [
            new ImportedRecipient('famille.alpha@exemple.test', ImportedRecipient::ACCEPTED, null, [$alpha]),
        ], [$withoutAddress]));

        $this->assertSame(2, $result->targets);
        $targets = $this->statement(
            'SELECT member_id, scout_year_id FROM mass_mail_targets ORDER BY member_id'
        )->fetchAll(\PDO::FETCH_KEY_PAIR);
        $this->assertSame([$alpha => $latest, $withoutAddress => null], array_map(
            static fn(mixed $year): ?int => $year !== null ? (int) $year : null,
            $targets
        ));
        $this->assertSame(1, $this->rows('SELECT COUNT(*) FROM mass_mail_delivery_targets'));
    }

    public function testAnAttachmentTheStorageRefusesIsLeftOutAndTheEmailKept(): void
    {
        $pdf = ImportedEmailFixture::pdf();
        $text = (string) tempnam(sys_get_temp_dir(), 'mm_import_');
        file_put_contents($text, "Ceci n'est pas un PDF.\n");

        try {
            $result = $this->service->import(ImportedEmailFixture::email('c-4', [], [], [
                new ImportedAttachment($pdf, 'programme.pdf'),
                new ImportedAttachment($text, 'faux.pdf'),
                new ImportedAttachment($this->storage . '/absent', 'absent.pdf'),
            ]));
        } finally {
            @unlink($pdf);
            @unlink($text);
        }

        $this->assertSame(1, $result->attachments);
        $this->assertSame(2, $result->attachmentsRefused);
        $file = $this->statement(
            'SELECT f.original_name, f.mime_type, f.role_min, f.module_id FROM mass_mail_attachments a '
                . 'JOIN files f ON f.id = a.file_id'
        )->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(
            [
                'original_name' => 'programme.pdf',
                'mime_type' => 'application/pdf',
                'role_min' => 'chief',
                'module_id' => 'mass_mail',
            ],
            $file
        );
    }

    public function testTheProvenanceIsFoundAgainByteForByte(): void
    {
        $result = $this->service->import(ImportedEmailFixture::email('A-1', []));

        $this->assertSame($result->emailId, $this->service->findImported("17:site-exempleville\nA-1"));
        $this->assertNull($this->service->findImported("17:site-exempleville\na-1"));
        $this->assertNull($this->service->findImported('17:site-exempleville'));
    }

    public function testItsImportIsJournaledWithoutSubjectNorAddress(): void
    {
        $result = $this->service->import(ImportedEmailFixture::email());

        $row = $this->statement("SELECT description, context FROM event_log WHERE event_type = 'email_imported'")
            ->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($row);
        $this->assertStringContainsString('#' . $result->emailId, (string) $row['description']);
        $this->assertStringNotContainsString('camp', (string) $row['description']);
        $this->assertStringNotContainsString('@', (string) $row['description']);
        // Counts and ids, nothing else.
        $context = json_decode((string) $row['context'], true);
        $this->assertIsArray($context);
        $this->assertSame(
            ['attachments', 'attachments_refused', 'deliveries', 'email_id', 'section_id', 'targets'],
            self::sortedKeys($context)
        );
        foreach ($context as $value) {
            $this->assertIsInt($value);
        }
    }

    public function testTheAcceptedAttachmentsAreTheOnesAChiefMayAdd(): void
    {
        $this->assertSame(
            \Modules\MassMail\Repository\EmailAttachment::ALLOWED_MIMES,
            $this->service->acceptedAttachmentTypes(),
        );
        $this->assertSame(10 * 1024 * 1024, $this->service->maxAttachmentBytes());
    }

    /**
     * @param array<string, mixed> $values
     * @return list<string>
     */
    private static function sortedKeys(array $values): array
    {
        $keys = array_map('strval', array_keys($values));
        sort($keys);

        return $keys;
    }

    private function rows(string $sql): int
    {
        return (int) $this->statement($sql)->fetchColumn();
    }

    /**
     * A prepared and executed statement, values bound.
     *
     * @param list<int|string> $values
     */
    private function statement(string $sql, array $values = []): \PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($values);

        return $stmt;
    }
}
