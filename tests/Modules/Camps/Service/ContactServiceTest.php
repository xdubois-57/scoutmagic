<?php

declare(strict_types=1);

namespace Tests\Modules\Camps\Service;

use Core\Audit\AuditRepository;
use Core\Audit\AuditService;
use Core\Journal\JournalService;
use Core\Security\EncryptionService;
use Modules\Camps\Repository\ContactRepository;
use Modules\Camps\Service\CampService;
use Modules\Camps\Service\CampsException;
use Modules\Camps\Service\ContactService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Camps\CampsTestHelper;

class ContactServiceTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private ContactRepository $contacts;
    private AuditService $audit;
    private ContactService $service;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        CampsTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->contacts = new ContactRepository($this->pdo, $this->encryption);
        $this->audit = new AuditService(new AuditRepository($this->pdo, $this->encryption));
        // A stub, not a mock: only one test below cares what the journal
        // is told, and a mock with no expectations makes every other test
        // in this file report a PHPUnit notice.
        $this->service = new ContactService($this->contacts, $this->audit, $this->createStub(JournalService::class));

        $this->pdo->exec("INSERT INTO camp_places (name) VALUES ('Domaine de Mozet')");
        for ($i = 1; $i <= 3; $i++) {
            $this->pdo->exec("INSERT INTO camp_camps (place_id, year_only, status) VALUES (1, 202{$i}, 'confirmed')");
        }
    }

    // ── Validation ──────────────────────────────────────────────────

    public function testAContactNeedsAnEmailOrAPhone(): void
    {
        // A row with a name and nothing else is not a contact — it is a
        // note, and there is a field for those.
        $this->expectException(CampsException::class);
        $this->service->validate(['name' => 'Mme Lambert']);
    }

    public function testAPhoneAloneIsEnough(): void
    {
        $values = $this->service->validate(['phone' => '+32 81 58 00 00']);

        $this->assertSame('+32 81 58 00 00', $values['phone']);
        $this->assertNull($values['email']);
    }

    public function testAnInvalidEmailIsRefused(): void
    {
        $this->expectException(CampsException::class);
        $this->service->validate(['email' => 'pas-une-adresse']);
    }

    public function testAnUnknownRoleIsRefused(): void
    {
        $this->expectException(CampsException::class);
        $this->service->validate(['phone' => '0478', 'role_label' => 'Concierge']);
    }

    // ── Storage ─────────────────────────────────────────────────────

    public function testEveryPersonalFieldIsEncryptedAtRest(): void
    {
        $this->service->create(1, [
            'name' => 'Mme Lambert',
            'email' => 'contact@domainedemozet.be',
            'phone' => '+32 81 58 00 00',
            'other_details' => 'GSM du fils : 0478 …',
        ], 42);

        $row = $this->pdo->query('SELECT * FROM camp_contacts')->fetch(\PDO::FETCH_ASSOC);
        foreach (['name', 'email', 'phone', 'other_details'] as $column) {
            $this->assertStringNotContainsString('Lambert', (string) $row[$column]);
            $this->assertStringNotContainsString('domainedemozet', (string) $row[$column]);
            $this->assertStringNotContainsString('0478', (string) $row[$column]);
        }

        // The role is a function, not a person — in clear on purpose.
        $this->assertNull($row['role_label']);
    }

    public function testTheRoleLabelStaysInClear(): void
    {
        $this->service->create(1, ['phone' => '0478', 'role_label' => 'Propriétaire'], 42);

        $this->assertSame(
            'Propriétaire',
            $this->pdo->query('SELECT role_label FROM camp_contacts')->fetchColumn()
        );
    }

    public function testTheBlindIndexIsCaseAndSpaceInsensitive(): void
    {
        $this->service->create(1, ['email' => 'Contact@Domainedemozet.be'], 42);
        $this->service->create(2, ['email' => '  contact@domainedemozet.be '], 42);

        $first = $this->contacts->findById(1);
        $this->assertNotNull($first);

        // Two chiefs typing the same address differently must still be
        // one person when that person asks to be erased.
        $this->assertCount(2, $this->contacts->findSamePerson($first));
    }

    public function testCreatingAContactIsRecordedOnTheCampsTimeline(): void
    {
        $this->service->create(1, ['name' => 'Mme Lambert', 'phone' => '0478'], 42);

        $page = $this->audit->page(CampService::ENTITY_TYPE, 1, 1, 10);
        $this->assertSame(1, $page->total);
        $this->assertSame('contact', $page->entries[0]->fieldKey);
        $this->assertSame('Contact ajouté', $page->entries[0]->summary);
        $this->assertStringContainsString('Mme Lambert', (string) $page->entries[0]->toValue);
    }

    public function testTheHistoryNeverRepeatsTheFreeTextField(): void
    {
        $this->service->create(1, [
            'name' => 'Mme Lambert', 'phone' => '0478',
            'other_details' => 'Ne pas appeler après 20h, demander Jean-Marie',
        ], 42);

        $entry = $this->audit->page(CampService::ENTITY_TYPE, 1, 1, 10)->entries[0];
        $this->assertStringNotContainsString('Jean-Marie', (string) $entry->toValue);
    }

    // ── Erasure ─────────────────────────────────────────────────────

    public function testAnonymisationScopeIsKnownBeforeAnythingHappens(): void
    {
        $this->service->create(1, ['name' => 'Mme Lambert', 'email' => 'lambert@example.org'], 42);
        $this->service->create(2, ['name' => 'Mme Lambert', 'email' => 'lambert@example.org'], 42);
        $this->service->create(3, ['name' => 'M. Martin', 'email' => 'martin@example.org'], 42);

        $contact = $this->contacts->findById(1);
        $this->assertNotNull($contact);
        $scope = $this->service->anonymisationScope($contact);

        // The confirmation screen has to say this BEFORE the button:
        // erasure reaches past the stay the chief came from.
        $this->assertCount(2, $scope['contacts']);
        $this->assertSame([1, 2], $scope['camp_ids']);
    }

    public function testAContactWithoutAnEmailCanOnlyBeAnonymisedOnItsOwnRow(): void
    {
        $this->service->create(1, ['name' => 'M. Martin', 'phone' => '0476'], 42);
        $this->service->create(2, ['name' => 'M. Martin', 'phone' => '0476'], 42);

        $contact = $this->contacts->findById(1);
        $this->assertNotNull($contact);

        // Two "M. Martin" at two different farms are two different
        // people; matching them by name would erase a stranger.
        $this->assertCount(1, $this->service->anonymisationScope($contact)['contacts']);
    }

    public function testAnonymiseErasesEveryRowOfThatPersonAcrossTheModule(): void
    {
        $this->service->create(1, ['name' => 'Mme Lambert', 'email' => 'lambert@example.org', 'phone' => '081'], 42);
        $this->service->create(2, ['name' => 'Mme Lambert', 'email' => 'lambert@example.org'], 42);
        $this->service->create(3, ['name' => 'M. Martin', 'email' => 'martin@example.org'], 42);

        $contact = $this->contacts->findById(1);
        $this->assertNotNull($contact);
        $result = $this->service->anonymise($contact, 42);

        $this->assertSame(2, $result['contacts']);
        $this->assertSame(2, $result['camps']);
        $this->assertSame(ContactRepository::ANONYMISED_MARKER, $this->contacts->findById(1)?->name);
        $this->assertSame(ContactRepository::ANONYMISED_MARKER, $this->contacts->findById(2)?->email);
        // Somebody else entirely, untouched.
        $this->assertSame('M. Martin', $this->contacts->findById(3)?->name);
    }

    public function testAnonymiseClearsTheBlindIndexToo(): void
    {
        $this->service->create(1, ['name' => 'Mme Lambert', 'email' => 'lambert@example.org'], 42);
        $contact = $this->contacts->findById(1);
        $this->assertNotNull($contact);

        $this->service->anonymise($contact, 42);

        // Leaving it would keep a queryable fingerprint of the very
        // address that was asked to be removed.
        $this->assertNull($this->pdo->query('SELECT email_blind_index FROM camp_contacts WHERE id = 1')->fetchColumn() ?: null);
    }

    public function testAnonymiseAlsoClearsTheValuesLeftInTheCampsHistory(): void
    {
        $this->service->create(1, ['name' => 'Mme Lambert', 'email' => 'lambert@example.org'], 42);
        $contact = $this->contacts->findById(1);
        $this->assertNotNull($contact);

        $this->service->anonymise($contact, 42);

        // Without this the timeline would go on displaying the name the
        // person just asked to have removed — the exact failure the whole
        // feature exists to prevent.
        foreach ($this->audit->page(CampService::ENTITY_TYPE, 1, 1, 20)->entries as $entry) {
            $this->assertStringNotContainsString('Lambert', (string) $entry->fromValue);
            $this->assertStringNotContainsString('Lambert', (string) $entry->toValue);
            $this->assertStringNotContainsString('Lambert', (string) $entry->summary);
        }
    }

    public function testAnonymiseLeavesTheHistoryRowsThemselves(): void
    {
        $this->service->create(1, ['name' => 'Mme Lambert', 'email' => 'lambert@example.org'], 42);
        $contact = $this->contacts->findById(1);
        $this->assertNotNull($contact);
        $before = $this->audit->page(CampService::ENTITY_TYPE, 1, 1, 20)->total;

        $this->service->anonymise($contact, 42);

        // That a contact was added, when and by whom is a fact about the
        // stay, not about the person. Deleting the rows would erase the
        // trace of the change itself.
        $after = $this->audit->page(CampService::ENTITY_TYPE, 1, 1, 20);
        $this->assertGreaterThanOrEqual($before, $after->total);
    }

    public function testTheJournalRecordsCountsAndNeverTheValues(): void
    {
        $this->service->create(1, ['name' => 'Mme Lambert', 'email' => 'lambert@example.org'], 42);
        $contact = $this->contacts->findById(1);
        $this->assertNotNull($contact);

        $journal = $this->createMock(JournalService::class);
        $journal->expects($this->once())
            ->method('log')
            ->with(
                'camps',
                'contact_anonymised',
                'security',
                $this->callback(static function (string $description): bool {
                    // The journal is the installation's administrative log
                    // and forbids personal data. Recording WHICH address
                    // was erased in the log of the erasure would defeat it.
                    return !str_contains($description, 'Lambert')
                        && !str_contains($description, 'lambert@example.org');
                }),
                [],
                42
            );

        (new ContactService($this->contacts, $this->audit, $journal))->anonymise($contact, 42);
    }

    // ── The contact deleted BEFORE the request ──────────────────────

    /**
     * @return string[] every value the camp's history still shows
     */
    private function historyValues(int $campId): array
    {
        $values = [];
        foreach ($this->audit->page(CampService::ENTITY_TYPE, $campId, 1, 50)->entries as $entry) {
            $values[] = (string) $entry->fromValue;
            $values[] = (string) $entry->toValue;
            $values[] = (string) $entry->summary;
        }

        return $values;
    }

    public function testDeletingAContactLeavesNoneOfTheirDetailsInTheHistory(): void
    {
        // `anonymisationScope()` is computed from the LIVING rows sharing
        // an address, so a contact deleted before the request is out of
        // every later erasure's reach — forever. Whatever the history
        // holds about them at deletion time is what it holds for good.
        $this->service->create(
            1,
            [
                'name' => 'Mme Lambert',
                'role_label' => 'Propriétaire',
                'email' => 'lambert@example.org',
                'phone' => '081 58 00 00',
            ],
            42
        );
        $contact = $this->contacts->findById(1);
        $this->assertNotNull($contact);

        $this->service->delete($contact, 42);

        $values = implode("\n", $this->historyValues(1));
        $this->assertStringNotContainsString('Lambert', $values);
        $this->assertStringNotContainsString('lambert@example.org', $values);
        $this->assertStringNotContainsString('081 58 00 00', $values);
        // The role survives: it is a fact about the stay, not about the
        // person, and it is what makes the entry readable at all.
        $this->assertStringContainsString('Propriétaire', $values);
    }

    public function testDeletingOneContactKeepsTheOthersHistory(): void
    {
        // Erasing one person out of a field three people share must not
        // destroy two histories nobody asked to lose.
        $this->service->create(1, ['name' => 'Mme Lambert', 'email' => 'lambert@example.org'], 42);
        $this->service->create(1, ['name' => 'M. Martin', 'email' => 'martin@example.org'], 42);

        $contact = $this->contacts->findById(1);
        $this->assertNotNull($contact);
        $this->service->delete($contact, 42);

        $this->assertStringContainsString('M. Martin', implode("\n", $this->historyValues(1)));
    }

    public function testAnErasureFromOneCampReachesNoNameLeftBehindOnAnother(): void
    {
        // The whole hole, end to end: created on camp A, deleted there,
        // the same person recreated on camp B, and the request finally
        // made from B. Camp A is not in scope and never will be — so camp
        // A's timeline must already be clean.
        $this->service->create(1, ['name' => 'Mme Lambert', 'email' => 'lambert@example.org'], 42);
        $first = $this->contacts->findById(1);
        $this->assertNotNull($first);
        $this->service->delete($first, 42);

        $this->service->create(2, ['name' => 'Mme Lambert', 'email' => 'lambert@example.org'], 42);
        $second = $this->contacts->findById(2);
        $this->assertNotNull($second);

        $scope = $this->service->anonymisationScope($second);
        $this->assertSame([2], $scope['camp_ids'], 'Camp A is out of reach — that is the premise.');

        $this->service->anonymise($second, 42);

        $this->assertStringNotContainsString('Lambert', implode("\n", $this->historyValues(1)));
        $this->assertStringNotContainsString('Lambert', implode("\n", $this->historyValues(2)));
    }

    public function testTheModuleWorksWithoutAJournalAtAll(): void
    {
        $service = new ContactService($this->contacts, $this->audit);
        $service->create(1, ['name' => 'Mme Lambert', 'email' => 'lambert@example.org'], 42);
        $contact = $this->contacts->findById(1);
        $this->assertNotNull($contact);

        $this->assertSame(1, $service->anonymise($contact, 42)['contacts']);
    }
}
