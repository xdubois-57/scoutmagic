<?php

declare(strict_types=1);

namespace Tests\Modules\Rental\Audit;

use Core\Audit\AuditRepository;
use Core\Audit\AuditService;
use Core\Audit\AuditSource;
use Core\Config\ScoutYearService;
use Core\Member\MemberService;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Modules\Rental\Audit\ActorAccountResolver;
use Modules\Rental\Audit\BookingAudit;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Rental\RentalTestHelper;

/**
 * The rental side of the Core\Audit boundary: this module's field keys and
 * labels, and the one translation core cannot make for it — "which member
 * did this" into "which account did this".
 */
class BookingAuditTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private AuditService $audit;
    private int $scoutYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RentalTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('k', 32), str_repeat('b', 32));
        $this->audit = new AuditService(new AuditRepository($this->pdo, $this->encryption));
        $this->scoutYearId = (int) (new ScoutYearService($this->pdo))->getCurrentYear()['id'];
    }

    public function testEveryFieldKeyHasALabelAReaderCanUnderstand(): void
    {
        $keys = [
            BookingAudit::STATUS_CHANGED, BookingAudit::HOLD_PLACED, BookingAudit::HOLD_CLEARED,
            BookingAudit::PRICE_CHANGED, BookingAudit::DATES_CHANGED, BookingAudit::CHANGE_REQUESTED,
            BookingAudit::CHANGE_DECIDED, BookingAudit::COMMENT_ADDED,
        ];

        foreach ($keys as $key) {
            $this->assertArrayHasKey($key, BookingAudit::FIELD_LABELS, $key . ' has no French label');
            $this->assertNotSame($key, BookingAudit::FIELD_LABELS[$key]);
        }
    }

    public function testAChangeWithNoActorIsRecordedAsAutomatic(): void
    {
        (new BookingAudit($this->audit))->record(7, BookingAudit::HOLD_CLEARED, 'Option', null);

        $entry = $this->onlyEntry(7);
        $this->assertSame(AuditSource::System, $entry->source);
        $this->assertTrue($entry->isAutomatic());
    }

    /**
     * A member the site cannot map to an account still reads as a human
     * change. "A person did this and we can no longer say who" is the
     * honest reading; "the application did it on its own" would be false.
     */
    public function testAChangeByAnUnmappedMemberIsStillAHumanChange(): void
    {
        (new BookingAudit($this->audit))->record(7, BookingAudit::STATUS_CHANGED, 'A', 'B', null, 42);

        $entry = $this->onlyEntry(7);
        $this->assertSame(AuditSource::Human, $entry->source);
        $this->assertNull($entry->actorUserAccountId);
    }

    public function testAMemberWithAnAccountIsRecordedAgainstThatAccount(): void
    {
        $memberId = RentalTestHelper::insertMember($this->pdo, 'D-MANAGER');
        RentalTestHelper::insertMemberYear(
            $this->pdo, $this->encryption, $memberId, $this->scoutYearId, 'manager@test.be'
        );
        $accounts = new UserAccountRepository($this->pdo, $this->encryption);
        $accounts->create('manager@test.be', false);
        $account = $accounts->findByEmail('manager@test.be');
        $this->assertNotNull($account);

        $this->bookingAuditWithResolver()->record(7, BookingAudit::STATUS_CHANGED, 'A', 'B', null, $memberId);

        $entry = $this->onlyEntry(7);
        $this->assertSame($account->id, $entry->actorUserAccountId);
        $this->assertFalse($entry->isAutomatic());
    }

    public function testTheValuesSurviveEncryptionUnchanged(): void
    {
        (new BookingAudit($this->audit))->record(
            7, BookingAudit::PRICE_CHANGED, '450,00 €', '2 450,00 €', 'Supplément ajouté', 1
        );

        $entry = $this->onlyEntry(7);
        $this->assertSame('450,00 €', $entry->fromValue);
        $this->assertSame('2 450,00 €', $entry->toValue);
        $this->assertSame('Supplément ajouté', $entry->summary);
    }

    /**
     * The reason the whole table moved: `rental_booking_events` kept its
     * values in clear under a rule ("no personal data in a summary") that
     * held only for as long as everybody remembered it.
     */
    public function testNothingLandsInTheDatabaseInClear(): void
    {
        (new BookingAudit($this->audit))->record(
            7, BookingAudit::COMMENT_ADDED, null, null, 'Jeanne Martin a rappelé', 1
        );

        $raw = (string) json_encode($this->pdo->query('SELECT * FROM entity_changes')->fetchAll(\PDO::FETCH_ASSOC));
        $this->assertStringNotContainsString('Jeanne Martin', $raw);
    }

    public function testForgettingABookingLeavesTheOthersAlone(): void
    {
        $audit = new BookingAudit($this->audit);
        $audit->record(7, BookingAudit::STATUS_CHANGED, 'A', 'B');
        $audit->record(8, BookingAudit::STATUS_CHANGED, 'A', 'B');

        $this->assertSame(1, $audit->forget(7));

        $this->assertSame(0, $this->audit->page(BookingAudit::ENTITY_TYPE, 7, 1, 10)->total);
        $this->assertSame(1, $this->audit->page(BookingAudit::ENTITY_TYPE, 8, 1, 10)->total);
    }

    public function testTheResolverAnswersNullForAMemberWithNoAccount(): void
    {
        $memberId = RentalTestHelper::insertMember($this->pdo, 'D-NOACCOUNT');
        RentalTestHelper::insertMemberYear(
            $this->pdo, $this->encryption, $memberId, $this->scoutYearId, 'never-logged-in@test.be'
        );

        $this->assertNull($this->resolver()->accountIdFor($memberId));
        $this->assertNull($this->resolver()->accountIdFor(null));
        $this->assertNull($this->resolver()->accountIdFor(999999));
    }

    private function resolver(): ActorAccountResolver
    {
        return new ActorAccountResolver(
            new MemberService(
                new \Core\Import\MemberYearRepository($this->pdo),
                $this->encryption,
                \Core\Database\Connection::withPdo($this->pdo)
            ),
            new UserAccountRepository($this->pdo, $this->encryption),
            new ScoutYearService($this->pdo)
        );
    }

    private function bookingAuditWithResolver(): BookingAudit
    {
        return new BookingAudit($this->audit, $this->resolver());
    }

    private function onlyEntry(int $bookingId): \Core\Audit\AuditEntry
    {
        $entries = $this->audit->page(BookingAudit::ENTITY_TYPE, $bookingId, 1, 10)->entries;
        $this->assertCount(1, $entries);

        return $entries[0];
    }
}
