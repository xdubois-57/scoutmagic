<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Mail\Transport;

use Core\Mail\Transport\LaneChainRepository;
use Core\Mail\Transport\MailLane;
use Core\Mail\Transport\MailProvider;
use Core\Mail\Transport\MailReserve;
use Core\Mail\Transport\SendCounterRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The reserve, and above all the four cases where it is NOT the peak
 * plus a margin (D8).
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class MailReserveTest extends TestCase
{
    private \PDO $pdo;
    private SendCounterRepository $counters;
    private LaneChainRepository $chains;
    private MailReserve $reserve;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->counters = new SendCounterRepository($this->pdo);
        $this->chains = new LaneChainRepository($this->pdo);
        $this->reserve = new MailReserve($this->counters, $this->chains);
    }

    /**
     * The ordinary case: a relay shared between the mailing and the rest,
     * with a month of traffic behind it.
     */
    public function testItIsTheNonBulkPeakPlusAMargin(): void
    {
        $this->shareProviderBetweenBulkAndAuthentication(1);
        $this->countNonBulk('2026-09-10', 12);
        $this->countNonBulk('2026-09-11', 40);
        $this->countNonBulk('2026-09-12', 7);

        $reserve = $this->reserve->forProvider($this->provider(1, 500), '2026-09-13');

        $this->assertTrue($reserve->applies());
        $this->assertSame(40 + MailReserve::MARGIN, $reserve->messages);
        $this->assertSame(40, $reserve->peak);
        $this->assertStringContainsString('pointe hors publipostage', $reserve->provenance());
        $this->assertStringContainsString('40', $reserve->provenance());
    }

    /**
     * The publipostage's own traffic is not what the reserve protects, so
     * it cannot be what sets it either — otherwise one big mailing would
     * raise the reserve that the next mailing has to clear.
     */
    public function testTheMailingsOwnTrafficDoesNotRaiseIt(): void
    {
        $this->shareProviderBetweenBulkAndAuthentication(1);
        $this->countNonBulk('2026-09-12', 5);
        for ($i = 0; $i < 400; $i++) {
            $this->counters->increment(1, MailLane::Bulk, '2026-09-12');
        }

        $reserve = $this->reserve->forProvider($this->provider(1, 500), '2026-09-13');

        $this->assertSame(5 + MailReserve::MARGIN, $reserve->messages);
    }

    /** A fresh installation has no history, and that is when it matters most. */
    public function testAFreshInstallationFallsBackToTheFloor(): void
    {
        $this->shareProviderBetweenBulkAndAuthentication(1);

        $reserve = $this->reserve->forProvider($this->provider(1, 500), '2026-09-13');

        $this->assertSame(MailReserve::FLOOR, $reserve->messages);
        $this->assertTrue($reserve->fromFloor);
        $this->assertStringContainsString('pas d’historique', $reserve->provenance());
    }

    /** Past half the quota the mailing would never leave at all. */
    public function testItIsCappedAtHalfTheQuota(): void
    {
        $this->shareProviderBetweenBulkAndAuthentication(1);
        $this->countNonBulk('2026-09-12', 400);

        $reserve = $this->reserve->forProvider($this->provider(1, 100), '2026-09-13');

        $this->assertSame(50, $reserve->messages);
        $this->assertTrue($reserve->cappedByQuota);
        $this->assertStringContainsString('moitié du quota', $reserve->provenance());
    }

    /**
     * The case the decision spells out: a provider the mailing does not
     * share with anything has no sign-in link to shield.
     */
    public function testAProviderThatServesOnlyTheMailingReservesNothing(): void
    {
        $this->chains->append(MailLane::Bulk, 1, true);
        $this->countNonBulk('2026-09-12', 400);

        $reserve = $this->reserve->forProvider($this->provider(1, 500), '2026-09-13');

        $this->assertFalse($reserve->applies());
        $this->assertSame(0, $reserve->messages);
        $this->assertStringContainsString('pas à la fois', $reserve->provenance());
    }

    /** Nor does one the mailing never touches. */
    public function testAProviderThatNeverCarriesTheMailingReservesNothing(): void
    {
        $this->chains->append(MailLane::Authentication, 1, true);
        $this->chains->append(MailLane::Transactional, 1, true);

        $this->assertFalse($this->reserve->forProvider($this->provider(1, 500), '2026-09-13')->applies());
    }

    /**
     * An entry a superadmin switched off carries nothing, so it protects
     * nothing — the reserve would otherwise stand on a lane that is not
     * being used.
     */
    public function testADisabledEntryDoesNotMakeAProviderShared(): void
    {
        $this->chains->append(MailLane::Bulk, 1, true);
        $this->chains->append(MailLane::Authentication, 1, false);

        $this->assertFalse($this->reserve->forProvider($this->provider(1, 500), '2026-09-13')->applies());
    }

    /** No quota, nothing to divide — the local send, every time (D6). */
    public function testAProviderWithoutAQuotaReservesNothing(): void
    {
        $this->shareProviderBetweenBulkAndAuthentication(MailProvider::LOCAL_ID);

        $reserve = $this->reserve->forProvider(MailProvider::local(10, 15), '2026-09-13');

        $this->assertFalse($reserve->applies());
        $this->assertStringContainsString('quota journalier', $reserve->provenance());
    }

    /** Older than the window, so outside the measurement. */
    public function testADayOutsideTheWindowIsNotThePeak(): void
    {
        $this->shareProviderBetweenBulkAndAuthentication(1);
        $this->countNonBulk('2026-07-01', 900);
        $this->countNonBulk('2026-09-12', 11);

        $reserve = $this->reserve->forProvider($this->provider(1, 500), '2026-09-13');

        $this->assertSame(11 + MailReserve::MARGIN, $reserve->messages);
    }

    private function shareProviderBetweenBulkAndAuthentication(int $providerId): void
    {
        $this->chains->append(MailLane::Bulk, $providerId, true);
        $this->chains->append(MailLane::Authentication, $providerId, true);
    }

    private function countNonBulk(string $day, int $messages): void
    {
        for ($i = 0; $i < $messages; $i++) {
            $this->counters->increment(1, MailLane::Transactional, $day);
        }
    }

    private function provider(int $id, int $quota): MailProvider
    {
        return new MailProvider(
            id: $id,
            name: 'Relais',
            host: 'smtp.exemple.test',
            port: 587,
            username: 'u',
            dailyQuota: $quota,
            batchSize: 50,
            batchIntervalMinutes: 10,
            secretPrefix: 'mail_provider_' . $id
        );
    }
}
