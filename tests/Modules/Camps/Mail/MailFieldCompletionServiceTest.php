<?php

declare(strict_types=1);

namespace Tests\Modules\Camps\Mail;

use Core\Audit\AuditRepository;
use Core\Audit\AuditService;
use Core\Audit\AuditSource;
use Core\Security\EncryptionService;
use Modules\Camps\Mail\MailFieldCompletionService;
use Modules\Camps\Mail\MessageReader;
use Modules\Camps\Repository\Camp;
use Modules\Camps\Repository\CampRepository;
use Modules\Camps\Repository\FieldProposalRepository;
use Modules\Camps\Service\CampService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Camps\CampsTestHelper;

class MailFieldCompletionServiceTest extends TestCase
{
    private \PDO $pdo;
    private CampRepository $camps;
    private FieldProposalRepository $proposals;
    private AuditService $audit;
    private MailFieldCompletionService $service;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        CampsTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->camps = new CampRepository($this->pdo, $encryption);
        $this->proposals = new FieldProposalRepository($this->pdo, $encryption);
        $this->audit = new AuditService(new AuditRepository($this->pdo, $encryption));
        $this->service = new MailFieldCompletionService(
            $this->camps, $this->proposals, $this->audit, new MessageReader()
        );
        $this->pdo->exec("INSERT INTO camp_places (name) VALUES ('Domaine de Mozet')");
    }

    // ── Empty field: filled ─────────────────────────────────────────

    public function testAnEmptyPriceIsFilledFromTheMessage(): void
    {
        $camp = $this->camp(null);

        $result = $this->service->apply($camp, 'Le montant est de 2 450 €.', 'inbound-message-7');

        $this->assertSame(['price'], $result['filled']);
        $this->assertSame(245000, $this->camps->findById($camp->id)?->priceCents);
        $this->assertSame([], $this->proposals->findByCamp($camp->id));
    }

    public function testAYearOnlyStayCountsAsEmptyForDates(): void
    {
        $camp = $this->camp(null, null, 2028);

        // "On y va en 2028" is a placeholder waiting for exactly this
        // message, not a value anybody would defend.
        $result = $this->service->apply($camp, 'Réservé du 12 au 19 juillet 2028.', 'inbound-message-7');

        $this->assertSame(['dates'], $result['filled']);
        $updated = $this->camps->findById($camp->id);
        $this->assertSame('2028-07-12', $updated?->startDate);
        $this->assertNull($updated->yearOnly);
    }

    public function testAFilledFieldIsRecordedAsComingFromAMessage(): void
    {
        $camp = $this->camp(null);
        $this->service->apply($camp, 'Total : 2 450 €', 'inbound-message-7');

        $entry = $this->audit->page(CampService::ENTITY_TYPE, $camp->id, 1, 10)->entries[0];
        $this->assertSame(AuditSource::Email, $entry->source);
        $this->assertTrue($entry->isAutomatic());
        $this->assertSame('inbound-message-7', $entry->sourceReference);
    }

    // ── Filled field: never overwritten ─────────────────────────────

    public function testAFilledPriceIsNeverOverwritten(): void
    {
        $camp = $this->camp(245000);

        $result = $this->service->apply($camp, 'Le nouveau tarif est de 2 650 €.', 'inbound-message-9');

        // A chief typed 2 450 € because they read a contract; a regex
        // over an e-mail body does not get to disagree silently.
        $this->assertSame(['price'], $result['proposed']);
        $this->assertSame(245000, $this->camps->findById($camp->id)?->priceCents);

        $proposals = $this->proposals->findByCamp($camp->id);
        $this->assertCount(1, $proposals);
        $this->assertSame('2 450,00 €', $proposals[0]->currentValue);
        $this->assertSame('2 650,00 €', $proposals[0]->proposedValue);
    }

    public function testAValueMatchingWhatIsAlreadyThereProducesNothing(): void
    {
        $camp = $this->camp(245000);

        $result = $this->service->apply($camp, 'Pour rappel, 2 450 €.', 'inbound-message-9');

        $this->assertSame([], $result['filled']);
        $this->assertSame([], $result['proposed']);
        $this->assertSame([], $this->proposals->findByCamp($camp->id));
    }

    public function testASecondReadingReplacesTheFirstRatherThanStacking(): void
    {
        $camp = $this->camp(245000);
        $this->service->apply($camp, '2 650 €', 'inbound-message-9');
        $this->service->apply($camp, '2 800 €', 'inbound-message-11');

        // Three cards disagreeing about one price is not more
        // information, it is noise.
        $proposals = $this->proposals->findByCamp($camp->id);
        $this->assertCount(1, $proposals);
        $this->assertSame('2 800,00 €', $proposals[0]->proposedValue);
    }

    public function testProposedValuesAreEncryptedAtRest(): void
    {
        $camp = $this->camp(245000);
        $this->service->apply($camp, '2 650 €', 'inbound-message-9');

        $stored = (string) $this->pdo->query('SELECT proposed_value FROM camp_field_proposals')->fetchColumn();
        $this->assertStringNotContainsString('650', $stored);
    }

    // ── Both outcomes are recorded ──────────────────────────────────

    public function testAcceptingAProposalMovesTheValueAndRecordsIt(): void
    {
        $camp = $this->camp(245000);
        $this->service->apply($camp, '2 650 €', 'inbound-message-9');
        $proposal = $this->proposals->findByCamp($camp->id)[0];

        $this->service->accept($proposal, 42);

        $this->assertSame(265000, $this->camps->findById($camp->id)?->priceCents);
        $this->assertSame([], $this->proposals->findByCamp($camp->id));

        $entry = $this->audit->page(CampService::ENTITY_TYPE, $camp->id, 1, 10)->entries[0];
        $this->assertSame('Information du message acceptée', $entry->summary);
        $this->assertSame(42, $entry->actorUserAccountId);
    }

    public function testAcceptingProposedDatesReplacesThem(): void
    {
        $camp = $this->camp(null, '2028-07-01');
        $this->service->apply($camp, 'Finalement du 12 au 19 juillet 2028.', 'inbound-message-9');
        $proposal = $this->proposals->findByCamp($camp->id)[0];

        $this->service->accept($proposal, 42);

        $this->assertSame('2028-07-12', $this->camps->findById($camp->id)?->startDate);
        $this->assertSame('2028-07-19', $this->camps->findById($camp->id)?->endDate);
    }

    public function testDismissingAProposalIsRecordedToo(): void
    {
        $camp = $this->camp(245000);
        $this->service->apply($camp, '2 650 €', 'inbound-message-9');
        $proposal = $this->proposals->findByCamp($camp->id)[0];

        $this->service->dismiss($proposal, 42);

        // Six months later somebody will ask why the page does not say
        // what the mail says. "A chief looked at it and said no" is the
        // answer, and it has to be written down.
        $this->assertSame(245000, $this->camps->findById($camp->id)?->priceCents);
        $this->assertSame([], $this->proposals->findByCamp($camp->id));
        $entry = $this->audit->page(CampService::ENTITY_TYPE, $camp->id, 1, 10)->entries[0];
        $this->assertSame('Information du message ignorée', $entry->summary);
    }

    public function testAnUnwritableProposalIsNotRecordedAsAccepted(): void
    {
        // Nothing to write: the machine value is not a number, so no
        // branch of accept() can apply it. Recording "acceptée" and
        // deleting the proposal anyway was the worst of both — the history
        // claimed a chief's decision had been applied, the stay still said
        // the old thing, and the proposal that would have let anybody
        // notice was gone.
        $camp = $this->camp(245000);
        $this->proposals->save(
            $camp->id,
            'price',
            '2 450,00 €',
            'deux mille six cent cinquante euros',
            'deux mille six cent cinquante',
            'inbound-message-9'
        );
        $proposal = $this->proposals->findByCamp($camp->id)[0];

        $this->service->accept($proposal, 42);

        $this->assertSame(245000, $this->camps->findById($camp->id)?->priceCents);
        $this->assertSame([], $this->proposals->findByCamp($camp->id));
        $entry = $this->audit->page(CampService::ENTITY_TYPE, $camp->id, 1, 10)->entries[0];
        $this->assertStringContainsString('inapplicable', (string) $entry->summary);
        $this->assertNotSame('Information du message acceptée', $entry->summary);
    }

    public function testAProposedDatePairThatIsNotAPairIsNotRecordedAsAcceptedEither(): void
    {
        $camp = $this->camp(null, '2028-07-19');
        $this->proposals->save(
            $camp->id,
            'dates',
            '12–19 juillet 2028',
            'cet été',
            'cet-ete',
            'inbound-message-9'
        );
        $proposal = $this->proposals->findByCamp($camp->id)[0];

        $this->service->accept($proposal, 42);

        $this->assertSame('2028-07-19', $this->camps->findById($camp->id)?->startDate);
        $entry = $this->audit->page(CampService::ENTITY_TYPE, $camp->id, 1, 10)->entries[0];
        $this->assertStringContainsString('inapplicable', (string) $entry->summary);
    }

    public function testAProposalForAStayThatDisappearedIsJustDropped(): void
    {
        $camp = $this->camp(245000);
        $this->service->apply($camp, '2 650 €', 'inbound-message-9');
        $proposal = $this->proposals->findByCamp($camp->id)[0];
        $this->camps->delete($camp->id);

        $this->service->accept($proposal, 42);

        $this->assertNull($this->proposals->findById($proposal->id));
    }

    private function camp(?int $priceCents, ?string $endDate = null, ?int $yearOnly = null): Camp
    {
        $id = $this->camps->create(
            1, Camp::STAY_GRAND_CAMP,
            $endDate, $endDate, $endDate === null ? ($yearOnly ?? 2028) : null,
            Camp::STATUS_CONFIRMED, $priceCents, null, null, null, []
        );
        $camp = $this->camps->findById($id);
        $this->assertNotNull($camp);

        return $camp;
    }
}
