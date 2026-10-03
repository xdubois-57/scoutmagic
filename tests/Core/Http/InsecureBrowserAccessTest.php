<?php

declare(strict_types=1);

namespace Tests\Core\Http;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Http\InsecureBrowserAccess;
use Core\Http\RequestScheme;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The one state behind « connexion non sécurisée » (#751): the date of
 * the last insecure browser observation, written at most once a quarter
 * of an hour, active for 24 hours after it and not a second longer.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class InsecureBrowserAccessTest extends TestCase
{
    private const T = 1_800_000_000;

    private SettingService $settings;

    protected function setUp(): void
    {
        $this->settings = new SettingService(new SettingRepository(DatabaseTestHelper::createTestDatabase()));
    }

    protected function tearDown(): void
    {
        // tests/bootstrap.php runs the suite with the development exception.
        RequestScheme::setHttpsRequired(false);
    }

    private const INSECURE = ['HTTP_X_SCOUTMAGIC_SECURE_CONTEXT' => '0'];

    public function testAnAuthenticatedInsecureBrowserIsRecordedWhileHttpsIsRequired(): void
    {
        RequestScheme::setHttpsRequired(true);
        $access = new InsecureBrowserAccess($this->settings);

        $this->assertTrue($access->observe(self::INSECURE, true, self::T));
        $this->assertSame(self::T, $access->lastObservedAt());
    }

    public function testAnAnonymousRequestIsNeverBelieved(): void
    {
        RequestScheme::setHttpsRequired(true);
        $access = new InsecureBrowserAccess($this->settings);

        $this->assertFalse($access->observe(self::INSECURE, false, self::T));
        $this->assertNull($access->lastObservedAt());
    }

    /**
     * Behind a TLS terminator PHP sees HTTP while the browser says it is
     * secure: nothing is recorded, so nothing is raised.
     */
    public function testASecureBrowserBehindATerminatorRecordsNothing(): void
    {
        RequestScheme::setHttpsRequired(true);
        $access = new InsecureBrowserAccess($this->settings);
        $server = ['HTTPS' => 'off', 'SERVER_PORT' => '80', 'HTTP_X_SCOUTMAGIC_SECURE_CONTEXT' => '1'];

        $this->assertFalse($access->observe($server, true, self::T));
        $this->assertNull($access->lastObservedAt());
    }

    public function testAnInstallationThatToleratesHttpRecordsNothing(): void
    {
        RequestScheme::setHttpsRequired(false);
        $access = new InsecureBrowserAccess($this->settings);

        $this->assertFalse($access->observe(self::INSECURE, true, self::T));
        $this->assertNull($access->lastObservedAt());
    }

    public function testOnlyAnExplicitZeroFromTheBrowserCountsAsInsecure(): void
    {
        $this->assertTrue(InsecureBrowserAccess::reportsInsecure(['HTTP_X_SCOUTMAGIC_SECURE_CONTEXT' => '0']));
        $this->assertFalse(InsecureBrowserAccess::reportsInsecure(['HTTP_X_SCOUTMAGIC_SECURE_CONTEXT' => '1']));
        // A request that did not come from api.js states nothing — and
        // what PHP itself sees is never read.
        $this->assertFalse(InsecureBrowserAccess::reportsInsecure(['HTTPS' => 'off', 'SERVER_PORT' => '80']));
        $this->assertFalse(InsecureBrowserAccess::reportsInsecure(['HTTP_X_SCOUTMAGIC_SECURE_CONTEXT' => 'no']));
    }

    public function testNothingObservedIsNotActive(): void
    {
        $access = new InsecureBrowserAccess($this->settings);

        $this->assertNull($access->lastObservedAt());
        $this->assertFalse($access->isActive(self::T));
        $this->assertNull($access->activeUntil(self::T));
    }

    public function testAnObservationIsPersistedAndActiveForTwentyFourHours(): void
    {
        $access = new InsecureBrowserAccess($this->settings);

        $this->assertTrue($access->record(self::T));

        // Persisted: a fresh service over the same rows reads it back.
        $reread = new InsecureBrowserAccess($this->settings);
        $this->assertSame(self::T, $reread->lastObservedAt());
        $this->assertTrue($reread->isActive(self::T + 10 * 3600));
        $this->assertTrue($reread->isActive(self::T + 24 * 3600 - 1));
        $this->assertSame(self::T + 24 * 3600, $reread->activeUntil(self::T + 3600));
        $this->assertFalse($reread->isActive(self::T + 24 * 3600), 'back to healthy after 24 h on its own');
    }

    public function testWritesAreThrottledToOneAQuarterOfAnHour(): void
    {
        $access = new InsecureBrowserAccess($this->settings);
        $access->record(self::T);

        $this->assertFalse($access->record(self::T + 60));
        $this->assertSame(self::T, $access->lastObservedAt());

        $this->assertTrue($access->record(self::T + InsecureBrowserAccess::WRITE_THROTTLE_SECONDS));
        $this->assertSame(self::T + InsecureBrowserAccess::WRITE_THROTTLE_SECONDS, $access->lastObservedAt());
    }

    /**
     * Later HTTPS visits do not erase the observation: nothing but a new
     * insecure one ever writes, so the window runs from the last of them.
     */
    public function testANewInsecureObservationExtendsTheWindow(): void
    {
        $access = new InsecureBrowserAccess($this->settings);
        $access->record(self::T);
        $access->record(self::T + 20 * 3600);

        $this->assertTrue($access->isActive(self::T + 30 * 3600));
        $this->assertFalse($access->isActive(self::T + 44 * 3600));
    }

    public function testTheAgeIsSaidTheSameWayEverywhere(): void
    {
        $this->assertSame('il y a moins d\'une heure', InsecureBrowserAccess::ago(self::T, self::T + 59 * 60));
        $this->assertSame('il y a 3 h', InsecureBrowserAccess::ago(self::T, self::T + 3 * 3600 + 5));
    }
}
