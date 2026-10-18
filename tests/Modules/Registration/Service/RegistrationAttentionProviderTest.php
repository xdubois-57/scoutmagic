<?php

declare(strict_types=1);

namespace Tests\Modules\Registration\Service;

use Core\Attention\AttentionPoint;
use Core\Config\SettingException;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Security\EncryptionService;
use Modules\Registration\Repository\RegistrationRequestRepository;
use Modules\Registration\Service\RegistrationAttentionProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Registration\RegistrationTestHelper;

/**
 * Issue #718 § C point 5: one point for every request pending longer than
 * the configured age, from the configured day of the scout year onwards —
 * and each setting's default and a changed value.
 */
#[Group('database')]
class RegistrationAttentionProviderTest extends TestCase
{
    private \PDO $pdo;
    private SettingService $settings;
    private RegistrationRequestRepository $requests;
    private int $yearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RegistrationTestHelper::createTables($this->pdo);
        $this->settings = new SettingService(new SettingRepository($this->pdo));
        RegistrationTestHelper::registerManifestSettings($this->settings);
        $this->requests = new RegistrationRequestRepository(
            $this->pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
        $this->yearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
    }

    /** @return AttentionPoint[] */
    private function pointsOn(string $now): array
    {
        return (new RegistrationAttentionProvider($this->requests, $this->settings, new \DateTimeImmutable($now)))
            ->collect($this->yearId);
    }

    private function requestReceived(string $at, string $status = 'pending'): void
    {
        $id = $this->requests->create($this->yearId, [
            'parent_name' => 'Marie Dupont', 'child_last_name' => 'Dupont', 'child_first_name' => 'Léa',
            'gender' => 'F', 'birth_date' => '2019-05-12', 'street' => 'Rue', 'number' => '1',
            'postal_code' => '1000', 'city' => 'Ville', 'email' => 'lea' . random_int(1, 1_000_000) . '@example.com',
            'phone1' => '000', 'phone2' => null, 'remarks' => null,
        ], null, [])['id'];
        $this->pdo->prepare('UPDATE registration_requests SET received_at = ?, status = ? WHERE id = ?')
            ->execute([$at, $status, $id]);
    }

    public function testTheDefaultsAreTheFirstOfJuneAndFifteenDays(): void
    {
        $this->assertSame('01/06', $this->settings->get(RegistrationAttentionProvider::SETTING_FROM, 'registration'));
        $this->assertSame('15', $this->settings->get(RegistrationAttentionProvider::SETTING_AGE_DAYS, 'registration'));
    }

    public function testFromTheFirstOfJuneOnePointCountsTheOldPendingRequestsOnly(): void
    {
        $this->requestReceived('2027-05-01 10:00:00');
        $this->requestReceived('2027-05-20 10:00:00');
        $this->requestReceived('2027-06-05 10:00:00'); // ten days old: ignored
        $this->requestReceived('2027-04-01 10:00:00', 'accepted'); // decided: ignored

        $points = $this->pointsOn('2027-06-15 12:00:00');

        $this->assertCount(1, $points, 'one entry for all of them, never one per request');
        $this->assertSame("2 demandes d'inscription attendent depuis plus de 15 jours", $points[0]->title);
        $this->assertSame(AttentionPoint::SEVERITY_ATTENTION, $points[0]->severity);
        $this->assertSame('/config/inscriptions/demandes', $points[0]->actionUrl);
    }

    public function testBeforeTheConfiguredDayNothingShows(): void
    {
        $this->requestReceived('2027-03-01 10:00:00');

        $this->assertSame([], $this->pointsOn('2027-05-31 23:00:00'));
        $this->assertCount(1, $this->pointsOn('2027-06-01 00:30:00'));
    }

    /** The period closes with the scout year and opens again next June. */
    public function testTheNewScoutYearClosesThePeriodUntilTheDayComesAgain(): void
    {
        $this->requestReceived('2027-03-01 10:00:00');

        $this->assertCount(1, $this->pointsOn('2027-08-31 12:00:00'));
        $this->assertSame([], $this->pointsOn('2027-09-01 12:00:00'));
        $this->assertSame([], $this->pointsOn('2028-02-01 12:00:00'));
        $this->assertCount(1, $this->pointsOn('2028-06-01 12:00:00'));
    }

    public function testADayInTheAutumnOpensThePeriodEarlyInTheScoutYear(): void
    {
        $this->settings->set(RegistrationAttentionProvider::SETTING_FROM, '15/10', 'registration');
        $this->requestReceived('2026-09-10 10:00:00');

        $this->assertSame([], $this->pointsOn('2026-10-14 12:00:00'));
        $this->assertCount(1, $this->pointsOn('2026-10-15 12:00:00'));
        $this->assertCount(1, $this->pointsOn('2027-03-01 12:00:00'));
    }

    public function testTheAgeIsTheSetting(): void
    {
        $this->settings->set(RegistrationAttentionProvider::SETTING_AGE_DAYS, '30', 'registration');
        $this->requestReceived('2027-05-20 10:00:00');

        $this->assertSame([], $this->pointsOn('2027-06-15 12:00:00'), 'twenty-six days is under thirty');
        $points = $this->pointsOn('2027-06-25 12:00:00');
        $this->assertSame("Une demande d'inscription attend depuis plus de 30 jours", $points[0]->title);
    }

    public function testTheSettingsRefuseMalformedValues(): void
    {
        foreach ([[RegistrationAttentionProvider::SETTING_FROM, '1/6'], [RegistrationAttentionProvider::SETTING_FROM, '32/01'],
            [RegistrationAttentionProvider::SETTING_FROM, '01/13'], [RegistrationAttentionProvider::SETTING_FROM, '31/04'],
            [RegistrationAttentionProvider::SETTING_FROM, '30/02'], [RegistrationAttentionProvider::SETTING_FROM, '31/11'],
            [RegistrationAttentionProvider::SETTING_AGE_DAYS, '0'],
            [RegistrationAttentionProvider::SETTING_AGE_DAYS, 'abc']] as [$key, $value]) {
            try {
                $this->settings->set($key, $value, 'registration');
                $this->fail("{$key} accepted « {$value} »");
            } catch (SettingException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** Every date a calendar has is accepted, the 29th of February included. */
    public function testTheSettingAcceptsEveryRealDayAndMonth(): void
    {
        foreach (['01/01', '29/02', '30/04', '31/05', '31/12', '30/11'] as $value) {
            $this->settings->set(RegistrationAttentionProvider::SETTING_FROM, $value, 'registration');
            $this->assertSame($value, $this->settings->get(RegistrationAttentionProvider::SETTING_FROM, 'registration'));
        }
    }

    public function testNoPendingRequestNoPoint(): void
    {
        $this->assertSame([], $this->pointsOn('2027-06-15 12:00:00'));
    }
}
