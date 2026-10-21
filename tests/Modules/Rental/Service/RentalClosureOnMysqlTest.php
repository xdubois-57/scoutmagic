<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Rental\Service;

use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\EncryptionService;
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Repository\ExpectedReceivableRepository;
use Modules\Finance\Repository\TransactionRepository;
use Modules\Finance\Service\StructuredCommunicationService;
use Modules\Rental\Booking\BookingStatus;
use Modules\Rental\Booking\RentalBooking;
use Modules\Rental\Payment\PaymentSettings;
use Modules\Rental\Pricing\BillingUnit;
use Modules\Rental\Pricing\PriceLine;
use Modules\Rental\Pricing\PriceQuote;
use Modules\Rental\Pricing\RentalPricingEngine;
use Modules\Rental\Repository\AssetLock;
use Modules\Rental\Repository\RentalAssetRepository;
use Modules\Rental\Repository\RentalBookingRepository;
use Modules\Rental\Repository\RentalModificationRepository;
use Modules\Rental\Repository\RentalPaymentRepository;
use Modules\Rental\Repository\RentalPricingRepository;
use Modules\Rental\Repository\RentalStayRepository;
use Modules\Rental\Service\RentalClosureService;
use Modules\Rental\Service\RentalException;
use Modules\Rental\Service\RentalPaymentService;
use Modules\Rental\Service\RentalPricingService;
use Modules\Rental\Service\RentalStayService;
use Modules\Rental\Stay\IncidentDecision;
use Modules\Rental\Stay\SettlementCalculator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Finance\FinanceTestHelper;
use Tests\Modules\Rental\RentalTestHelper;
use Tests\UsesProductionEngine;

/**
 * « Clôturer avec un solde non recouvré » on the engine production runs
 * (#980, CHANTIER-locations-incidents.md §2.9), with **two real connections**: two managers — or
 * one pressing twice — must not write the balance off twice.
 *
 * The closure runs under the asset's lock, so the second request waits for
 * the first; once it gets the lock it reads the booking again, finds it
 * closed, and is refused before writing anything. The status itself moves
 * by compare-and-set, whose answer on this engine is rows CHANGED — the
 * premise is asserted first.
 */
#[Group('database')]
final class RentalClosureOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private \PDO $pdo;
    private int $assetId;
    private int $bookingId;
    private PaymentSettings $settings;
    private int $accountId;

    protected function setUp(): void
    {
        $this->pdo = $this->productionEngine();
        $this->assetId = (new RentalAssetRepository($this->pdo, self::encryption()))
            ->create('Local', 'Local Saint-Georges', 'local', 60, 1, null, null, null, true);

        $this->accountId = (new AccountRepository($this->pdo, self::encryption()))
            ->create('Compte unité', 'bank', null, 'BE68539007547034', 'Unité Saint-Georges', 'intendant');
        $this->pdo->prepare('UPDATE finance_accounts SET status = ? WHERE id = ?')
            ->execute(['active', $this->accountId]);
        $this->settings = new PaymentSettings(
            enabled: true,
            financeAccountId: $this->accountId,
            securityDepositEnabled: true,
            securityDepositAmountCents: 15000,
            financeCategoryId: FinanceTestHelper::createCategory($this->pdo, 'Locations')
        );
        $payments = self::payments($this->pdo);
        $payments->saveSettings($this->assetId, $this->settings);

        $bookings = new RentalBookingRepository($this->pdo, self::encryption());
        $created = $bookings->create(
            $this->assetId,
            'LOC-A2B3C4',
            '2027-07-01',
            '2027-07-04',
            1,
            20,
            null,
            ['name' => 'Jeanne Martin', 'email' => 'jeanne@example.be', 'phone' => null,
                'organisation' => null, 'purpose' => null, 'comment' => null],
            new PriceQuote(
                lines: [new PriceLine('Séjour', 1, null, 46750, PriceLine::RULE_BASE)],
                totalCents: 46750,
                nights: 3,
                persons: 20,
                quantity: 1,
                billingUnit: BillingUnit::FLAT_STAY
            ),
            null,
            null,
            'v1',
            str_repeat('0', 64),
            'v1',
            str_repeat('0', 64),
            new \DateTimeImmutable('2027-01-01 10:00:00')
        );
        $this->bookingId = $created['id'];
        $bookings->setStatus($this->bookingId, BookingStatus::CONFIRMED, new \DateTimeImmutable());

        // 617,50 € paid, 800 € of damage charged and settled: 650 € due.
        $communication = (string) $payments->communicationForContract($this->booking($this->pdo), $this->settings);
        (new TransactionRepository($this->pdo, self::encryption()))->create(
            $this->accountId,
            FinanceTestHelper::createScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31'),
            null,
            '2027-06-20',
            'Virement ' . $communication,
            617.50,
            null,
            null,
            'import',
            null
        );
        $stay = self::stay($this->pdo);
        $incident = $stay->reportIncident($this->booking($this->pdo), 'Châssis brisé', 80000, null, 1);
        $stay->decideIncident($this->booking($this->pdo), $incident, IncidentDecision::CHARGE, null, 1);
        $settlement = $stay->recordSettlement($this->booking($this->pdo), $this->assetId, null, [], 1);
        $stay->validateSettlement($this->booking($this->pdo), $settlement->id, 1);
    }

    public function testTheCompareAndSetReportsAClosureAlreadyMade(): void
    {
        // The premise: on this engine, an UPDATE that changes nothing
        // answers 0 — the second closure is told, not waved through.
        $bookings = new RentalBookingRepository($this->pdo, self::encryption());
        $now = new \DateTimeImmutable();
        $this->assertTrue($bookings->compareAndSetStatus(
            $this->bookingId,
            BookingStatus::CONFIRMED,
            BookingStatus::CLOSED,
            $now
        ));
        $this->assertFalse($bookings->compareAndSetStatus(
            $this->bookingId,
            BookingStatus::CONFIRMED,
            BookingStatus::CLOSED,
            $now
        ));
    }

    public function testASecondClosureFromAPageRenderedBeforeTheFirstIsRefused(): void
    {
        $other = $this->secondConnection();
        $stale = $this->booking($other);

        self::closure($this->pdo)->closeUnrecovered(
            $this->booking($this->pdo),
            65000,
            'Assurance refusée',
            1,
            new \DateTimeImmutable()
        );

        try {
            self::closure($other)->closeUnrecovered($stale, 65000, 'Assurance refusée', 2, new \DateTimeImmutable());
            $this->fail('the balance was written off twice');
        } catch (RentalException $e) {
            $this->assertStringContainsString('Clôturée', $e->getMessage());
        }

        $this->assertSame(1, $this->unrecoveredVersions());
        $this->assertSame(BookingStatus::CLOSED, $this->booking($this->pdo)->status);
        $status = self::payments($this->pdo)->statusFor($this->booking($this->pdo), $this->settings);
        $this->assertSame(61750, $status['total_cents']);
        $this->assertSame(0, $status['remaining_cents']);
    }

    public function testTheClosureWaitsForTheAssetsLock(): void
    {
        $other = $this->secondConnection();

        $whileHeld = null;
        AssetLock::run($this->pdo, $this->assetId, function () use ($other, &$whileHeld): void {
            try {
                self::closure($other)->closeUnrecovered(
                    $this->booking($other),
                    65000,
                    'Assurance refusée',
                    2,
                    new \DateTimeImmutable()
                );
                $whileHeld = 'closed';
            } catch (\Throwable $e) {
                $whileHeld = self::isLockTimeout($e) ? 'waited' : 'failed: ' . $e->getMessage();
            }
        });

        $this->assertSame('waited', $whileHeld, 'the closure wrote while another request held the asset');
        $this->assertSame(0, $this->unrecoveredVersions());
        $this->assertSame(BookingStatus::CONFIRMED, $this->booking($this->pdo)->status);

        // Released: the same request goes through.
        self::closure($other)->closeUnrecovered(
            $this->booking($other),
            65000,
            'Assurance refusée',
            2,
            new \DateTimeImmutable()
        );
        $this->assertSame(1, $this->unrecoveredVersions());
        $this->assertSame(BookingStatus::CLOSED, $this->booking($this->pdo)->status);
    }

    private function unrecoveredVersions(): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM rental_settlements WHERE booking_id = ? AND unrecovered_cents IS NOT NULL'
        );
        $stmt->execute([$this->bookingId]);

        return (int) $stmt->fetchColumn();
    }

    private static function isLockTimeout(\Throwable $e): bool
    {
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof \PDOException) {
                return true;
            }
        }

        return false;
    }

    private function booking(\PDO $pdo): RentalBooking
    {
        return (new RentalBookingRepository($pdo, self::encryption()))->findById($this->bookingId)
            ?? self::fail('no booking');
    }

    private static function payments(\PDO $pdo): RentalPaymentService
    {
        $receivables = new ExpectedReceivableRepository($pdo, self::encryption());

        return new RentalPaymentService(
            new RentalPaymentRepository($pdo, self::encryption()),
            RentalTestHelper::bookingAudit($pdo, self::encryption()),
            new JournalService(new JournalRepository($pdo)),
            FinanceTestHelper::receivableService($pdo, self::encryption(), $receivables),
            new StructuredCommunicationService($receivables)
        );
    }

    private static function stay(\PDO $pdo): RentalStayService
    {
        $journal = new JournalService(new JournalRepository($pdo));

        return new RentalStayService(
            new RentalStayRepository($pdo, self::encryption()),
            RentalTestHelper::bookingAudit($pdo, self::encryption()),
            new RentalPricingService(new RentalPricingRepository($pdo), new RentalPricingEngine(), $journal),
            new SettlementCalculator(),
            $journal,
            self::payments($pdo)
        );
    }

    private static function closure(\PDO $pdo): RentalClosureService
    {
        return new RentalClosureService(
            new RentalBookingRepository($pdo, self::encryption()),
            new RentalStayRepository($pdo, self::encryption()),
            self::stay($pdo),
            new RentalModificationRepository($pdo),
            RentalTestHelper::bookingAudit($pdo, self::encryption()),
            new JournalService(new JournalRepository($pdo)),
            self::payments($pdo)
        );
    }

    private static function encryption(): EncryptionService
    {
        return new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
    }

    private function secondConnection(): \PDO
    {
        $database = (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        $other = self::productionEngineConnection($database)->getPdo();
        $other->exec('SET SESSION innodb_lock_wait_timeout = 1');

        return $other;
    }
}
