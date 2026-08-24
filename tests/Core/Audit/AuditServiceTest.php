<?php

declare(strict_types=1);

namespace Tests\Core\Audit;

use Core\Audit\AuditRepository;
use Core\Audit\AuditService;
use Core\Audit\AuditSource;
use Core\Security\EncryptionService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

class AuditServiceTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private AuditService $service;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->service = new AuditService(new AuditRepository($this->pdo, $this->encryption));
    }

    public function testRecordStoresAChangeReadableThroughPage(): void
    {
        $this->service->record('camp_camp', 7, 'price', '2 450 €', '2 650 €', AuditSource::Human);

        $page = $this->service->page('camp_camp', 7, 1, 10);

        $this->assertSame(1, $page->total);
        $this->assertSame('price', $page->entries[0]->fieldKey);
        $this->assertSame('2 450 €', $page->entries[0]->fromValue);
        $this->assertSame('2 650 €', $page->entries[0]->toValue);
        $this->assertSame(AuditSource::Human, $page->entries[0]->source);
    }

    public function testValuesAreEncryptedAtRest(): void
    {
        $this->service->record('camp_camp', 7, 'contact', null, 'Mme Lambert', AuditSource::Human);

        $stored = $this->pdo->query('SELECT to_value FROM entity_changes')->fetchColumn();

        // Unconditional encryption is the component's rule, including for
        // values that look harmless — a history is exactly where a name
        // ends up in a field nobody classified as personal.
        $this->assertNotSame('Mme Lambert', $stored);
        $this->assertStringNotContainsString('Lambert', (string) $stored);
        $this->assertSame('Mme Lambert', $this->encryption->decrypt((string) $stored, 'entity_changes.value'));
    }

    public function testANullValueStaysNullRatherThanBecomingEncryptedEmptiness(): void
    {
        $this->service->record('camp_camp', 7, 'contact', null, 'Mme Lambert', AuditSource::Human);

        $this->assertNull($this->pdo->query('SELECT from_value FROM entity_changes')->fetchColumn() ?: null);
        $this->assertNull($this->service->page('camp_camp', 7, 1, 10)->entries[0]->fromValue);
    }

    public function testHistoryIsScopedToItsOwnEntity(): void
    {
        $this->service->record('camp_camp', 7, 'price', null, '100 €', AuditSource::Human);
        $this->service->record('camp_camp', 8, 'price', null, '200 €', AuditSource::Human);
        $this->service->record('camp_place', 7, 'name', null, 'Mozet', AuditSource::Human);

        $this->assertSame(1, $this->service->page('camp_camp', 7, 1, 10)->total);
        $this->assertSame('100 €', $this->service->page('camp_camp', 7, 1, 10)->entries[0]->toValue);
        $this->assertSame(1, $this->service->page('camp_place', 7, 1, 10)->total);
    }

    public function testPageReturnsNewestFirstAndPaginates(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->service->record('camp_camp', 7, 'field' . $i, null, (string) $i, AuditSource::Human);
        }

        $first = $this->service->page('camp_camp', 7, 1, 2);
        $second = $this->service->page('camp_camp', 7, 2, 2);
        $third = $this->service->page('camp_camp', 7, 3, 2);

        $this->assertSame(5, $first->total);
        $this->assertTrue($first->hasMore());
        // Same-second writes: the id tie-break is what keeps this order
        // stable between two loads of the same history.
        $this->assertSame(['field5', 'field4'], array_map(fn($e) => $e->fieldKey, $first->entries));
        $this->assertSame(['field3', 'field2'], array_map(fn($e) => $e->fieldKey, $second->entries));
        $this->assertSame(['field1'], array_map(fn($e) => $e->fieldKey, $third->entries));
        $this->assertFalse($third->hasMore());
    }

    public function testPageBelowOneReadsAsTheFirstPage(): void
    {
        $this->service->record('camp_camp', 7, 'price', null, '100 €', AuditSource::Human);

        // A negative page would otherwise produce a negative OFFSET.
        $this->assertSame(1, $this->service->page('camp_camp', 7, 0, 10)->page);
        $this->assertCount(1, $this->service->page('camp_camp', 7, -3, 10)->entries);
    }

    public function testAnEmptyHistoryIsAnEmptyPageNotAnError(): void
    {
        $page = $this->service->page('camp_camp', 999, 1, 10);

        $this->assertSame(0, $page->total);
        $this->assertSame([], $page->entries);
        $this->assertFalse($page->hasMore());
    }

    public function testAnEntryWithoutAnActorIsAutomaticAndCarriesNoName(): void
    {
        $this->service->record('camp_camp', 7, 'price', null, '100 €', AuditSource::Email, null, 'msg-42');

        $entry = $this->service->page('camp_camp', 7, 1, 10)->entries[0];

        $this->assertTrue($entry->isAutomatic());
        $this->assertNull($entry->actorName);
        $this->assertSame('msg-42', $entry->sourceReference);
        $this->assertSame(AuditSource::Email, $entry->source);
    }

    public function testAnEntryWithAnActorCarriesTheirDecryptedName(): void
    {
        $accountId = $this->createAccount('Camille', 'Wauters');
        $this->service->record('camp_camp', 7, 'status', 'À confirmer', 'Confirmé', AuditSource::Human, null, null, $accountId);

        $entry = $this->service->page('camp_camp', 7, 1, 10)->entries[0];

        $this->assertFalse($entry->isAutomatic());
        $this->assertSame('Camille Wauters', $entry->actorName);
    }

    public function testSummaryIsStoredEncryptedAndReadBack(): void
    {
        $this->service->record(
            'camp_camp', 7, 'contact', null, 'M. Martin', AuditSource::Human, 'Contact ajouté'
        );

        $this->assertSame('Contact ajouté', $this->service->page('camp_camp', 7, 1, 10)->entries[0]->summary);
    }

    public function testAnonymiseValuesBlanksOnlyTheTargetedFields(): void
    {
        $this->service->record('camp_camp', 7, 'contact', 'Mme Lambert', 'M. Martin', AuditSource::Human, 'Contact remplacé');
        $this->service->record('camp_camp', 7, 'price', '2 450 €', '2 650 €', AuditSource::Human);

        $changed = $this->service->anonymiseValues('camp_camp', [7], ['contact']);

        $this->assertSame(1, $changed);
        $entries = $this->service->page('camp_camp', 7, 1, 10)->entries;
        $byField = [];
        foreach ($entries as $entry) {
            $byField[$entry->fieldKey] = $entry;
        }
        $this->assertSame(AuditRepository::ANONYMISED_MARKER, $byField['contact']->fromValue);
        $this->assertSame(AuditRepository::ANONYMISED_MARKER, $byField['contact']->toValue);
        $this->assertSame(AuditRepository::ANONYMISED_MARKER, $byField['contact']->summary);
        // The price is somebody else's business and must survive intact.
        $this->assertSame('2 450 €', $byField['price']->fromValue);
    }

    public function testAnonymiseValuesKeepsTheRowsAndTheirMetadata(): void
    {
        $accountId = $this->createAccount('Camille', 'Wauters');
        $this->service->record('camp_camp', 7, 'contact', 'Mme Lambert', 'M. Martin', AuditSource::Human, null, null, $accountId);

        $this->service->anonymiseValues('camp_camp', [7], ['contact']);

        // That a field changed, when, and by whom is not the personal data —
        // the old and new values were. Deleting the row would erase the
        // trace of the change itself.
        $entry = $this->service->page('camp_camp', 7, 1, 10)->entries[0];
        $this->assertSame(1, $this->service->page('camp_camp', 7, 1, 10)->total);
        $this->assertSame('contact', $entry->fieldKey);
        $this->assertSame('Camille Wauters', $entry->actorName);
    }

    public function testAnonymiseValuesLeavesNullValuesNull(): void
    {
        $this->service->record('camp_camp', 7, 'contact', null, 'M. Martin', AuditSource::Human);

        $this->service->anonymiseValues('camp_camp', [7], ['contact']);

        $entry = $this->service->page('camp_camp', 7, 1, 10)->entries[0];
        $this->assertNull($entry->fromValue);
        $this->assertSame(AuditRepository::ANONYMISED_MARKER, $entry->toValue);
        $this->assertNull($entry->summary);
    }

    public function testAnonymiseValuesSpansSeveralEntitiesAtOnce(): void
    {
        $this->service->record('camp_camp', 7, 'contact', null, 'Mme Lambert', AuditSource::Human);
        $this->service->record('camp_camp', 9, 'contact', null, 'Mme Lambert', AuditSource::Human);
        $this->service->record('camp_camp', 11, 'contact', null, 'Quelqu\'un d\'autre', AuditSource::Human);

        $this->assertSame(2, $this->service->anonymiseValues('camp_camp', [7, 9], ['contact']));
        $this->assertSame('Quelqu\'un d\'autre', $this->service->page('camp_camp', 11, 1, 10)->entries[0]->toValue);
    }

    public function testAnonymiseValuesWithNothingToMatchChangesNothing(): void
    {
        $this->service->record('camp_camp', 7, 'contact', null, 'Mme Lambert', AuditSource::Human);

        $this->assertSame(0, $this->service->anonymiseValues('camp_camp', [], ['contact']));
        $this->assertSame(0, $this->service->anonymiseValues('camp_camp', [7], []));
        $this->assertSame(0, $this->service->anonymiseValues('camp_place', [7], ['contact']));
        $this->assertSame('Mme Lambert', $this->service->page('camp_camp', 7, 1, 10)->entries[0]->toValue);
    }

    public function testAnAnonymisedValueIsStillCiphertextAtRest(): void
    {
        $this->service->record('camp_camp', 7, 'contact', null, 'Mme Lambert', AuditSource::Human);
        $this->service->anonymiseValues('camp_camp', [7], ['contact']);

        // A marker written in clear would leave the column holding both
        // ciphertext and plaintext, and the reader decrypts unconditionally.
        $stored = (string) $this->pdo->query('SELECT to_value FROM entity_changes')->fetchColumn();
        $this->assertNotSame(AuditRepository::ANONYMISED_MARKER, $stored);
        $this->assertSame(AuditRepository::ANONYMISED_MARKER, $this->encryption->decrypt($stored, 'entity_changes.value'));
    }

    private function createAccount(string $first, string $last): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO user_accounts (email_encrypted, email_blind_index, first_name_encrypted, last_name_encrypted)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            $this->encryption->encrypt($first . '@example.org', 'user_accounts.email'),
            $this->encryption->blindIndex($first . '@example.org', 'user_accounts.email'),
            $this->encryption->encrypt($first, 'user_accounts.first_name'),
            $this->encryption->encrypt($last, 'user_accounts.last_name'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }
}
