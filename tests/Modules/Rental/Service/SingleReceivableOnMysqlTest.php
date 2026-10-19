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
use Modules\Finance\Service\StructuredCommunicationService;
use Modules\Rental\Audit\BookingAudit;
use Modules\Rental\Booking\RentalBooking;
use Modules\Rental\Payment\PaymentSettings;
use Modules\Rental\Pricing\BillingUnit;
use Modules\Rental\Pricing\PriceLine;
use Modules\Rental\Pricing\PriceQuote;
use Modules\Rental\Repository\AssetLock;
use Modules\Rental\Repository\RentalAssetRepository;
use Modules\Rental\Repository\RentalBookingRepository;
use Modules\Rental\Repository\RentalPaymentRepository;
use Modules\Rental\Service\RentalPaymentService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Finance\FinanceTestHelper;
use Tests\Modules\Rental\RentalTestHelper;
use Tests\UsesProductionEngine;

/**
 * The booking's single receivable raised by two requests at once (issue
 * #927), on the engine production runs, with **two real connections**.
 *
 * Two managers generating the first contract together both read « no
 * receivable yet »; without a lock both raised one in Finance, the link
 * kept the last, and one contract printed the communication of a
 * receivable the booking no longer pointed at. The raise runs under the
 * asset's lock, re-reading the link inside it: the second request waits,
 * then reuses the first one's receivable.
 */
#[Group('database')]
final class SingleReceivableOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private \PDO $pdo;
    private int $assetId;
    private int $bookingId;
    private PaymentSettings $settings;

    protected function setUp(): void
    {
        $this->pdo = $this->productionEngine();
        $this->assetId = (new RentalAssetRepository($this->pdo, self::encryption()))
            ->create('Local', 'Local Saint-Georges', 'local', 60, 1, null, null, null, true);

        $accountId = (new AccountRepository($this->pdo, self::encryption()))
            ->create('Compte unité', 'bank', null, 'BE68539007547034', 'Unité Saint-Georges', 'intendant');
        $this->pdo->prepare('UPDATE finance_accounts SET status = ? WHERE id = ?')->execute(['active', $accountId]);
        $this->settings = new PaymentSettings(
            enabled: true,
            financeAccountId: $accountId,
            securityDepositEnabled: true,
            securityDepositAmountCents: 15000,
            financeCategoryId: FinanceTestHelper::createCategory($this->pdo, 'Locations')
        );

        $created = (new RentalBookingRepository($this->pdo, self::encryption()))->create(
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
    }

    public function testTwoSimultaneousFirstContractsShareOneReceivable(): void
    {
        $other = $this->secondConnection();
        $mine = self::payments($this->pdo);
        $theirs = self::payments($other);

        $whileHeld = null;
        $first = AssetLock::run($this->pdo, $this->assetId, function () use ($mine, $theirs, $other, &$whileHeld) {
            try {
                $theirs->communicationForContract($this->booking($other), $this->settings);
                $whileHeld = 'raised';
            } catch (\Throwable $e) {
                $whileHeld = self::isLockTimeout($e) ? 'waited' : 'failed: ' . $e->getMessage();
            }

            return $mine->communicationForContract($this->booking($this->pdo), $this->settings);
        });

        $this->assertSame('waited', $whileHeld, 'the second request raised a receivable while the first held the asset');

        $second = $theirs->communicationForContract($this->booking($other), $this->settings);

        $this->assertNotNull($first);
        $this->assertSame($first, $second);
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM finance_expected_receivables WHERE source_module = ?');
        $count->execute([RentalPaymentService::SOURCE_MODULE]);
        $this->assertSame(1, (int) $count->fetchColumn());
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
