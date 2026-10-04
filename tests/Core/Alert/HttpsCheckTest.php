<?php

declare(strict_types=1);

namespace Tests\Core\Alert;

use Core\Alert\Check\HttpsCheck;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Http\InsecureBrowserAccess;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * « Connexion sécurisée » reads what browsers observed (#751), never the
 * scheme PHP sees: behind a host terminating TLS, that is HTTP on a site
 * every visitor reaches over HTTPS, and the alert used to fire there
 * (#352).
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class HttpsCheckTest extends TestCase
{
    private SettingService $settings;
    /** @var array<string, mixed> */
    private array $serverBackup = [];

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
        $this->settings = new SettingService(new SettingRepository(DatabaseTestHelper::createTestDatabase()));
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
    }

    private function at(string $moment): \DateTimeImmutable
    {
        return new \DateTimeImmutable($moment);
    }

    private function observedAt(string $moment): void
    {
        (new InsecureBrowserAccess($this->settings))->record($this->at($moment)->getTimestamp());
    }

    private function check(string $now): HttpsCheck
    {
        return new HttpsCheck(new InsecureBrowserAccess($this->settings), $this->at($now));
    }

    /**
     * The false positive this ticket exists for: PHP sees HTTP, no browser
     * ever reported an insecure page, so nothing is raised.
     */
    public function testPhpSeeingHttpRaisesNothingWithoutABrowserObservation(): void
    {
        $_SERVER = ['HTTPS' => 'off', 'SERVER_PORT' => '80'];

        $reading = $this->check('2026-06-01 12:00:00')->read();

        $this->assertFalse($reading->overTrigger);
        $this->assertTrue($reading->underRearm);
        $this->assertSame('', $reading->value);
    }

    public function testAnInsecureBrowserAccessTriggersForTwentyFourHours(): void
    {
        $this->observedAt('2026-06-01 10:00:00');

        $reading = $this->check('2026-06-01 13:00:00')->read();

        $this->assertTrue($reading->overTrigger);
        $this->assertFalse($reading->underRearm);
        $this->assertSame('dernier accès non sécurisé il y a 3 h', $reading->value);
        $this->assertSame(HttpsCheck::TITLE, $reading->title);
        $this->assertStringContainsString('connexion non sécurisée', $reading->title);
        $this->assertStringNotContainsString('servi en HTTP', $reading->title);

        $this->assertTrue($this->check('2026-06-02 09:59:59')->read()->overTrigger);
    }

    /** 10:00 today → active until 10:00 tomorrow, then healthy again. */
    public function testItClearsOnItsOwnADayLater(): void
    {
        $this->observedAt('2026-06-01 10:00:00');

        $reading = $this->check('2026-06-02 10:00:00')->read();

        $this->assertFalse($reading->overTrigger);
        $this->assertTrue($reading->underRearm);
        $this->assertSame('dernier accès non sécurisé il y a 24 h', $reading->value);
    }

    public function testItLeadsToTheHelpTopicThatExplainsIt(): void
    {
        $this->observedAt('2026-06-01 10:00:00');
        $reading = $this->check('2026-06-01 10:30:00')->read();

        $this->assertSame('/aide/connexion-securisee', $reading->actionUrl);
        $this->assertSame('Comprendre cette alerte', $reading->actionLabel);
        $this->assertFileExists(dirname(__DIR__, 3) . '/docs/help/connexion-securisee.md');
    }

    /**
     * The topic tells the administrator to read the alert's value and
     * names the 24 hours: both have to match what this check produces.
     */
    public function testTheHelpTopicQuotesTheReadingAnAdministratorWillSee(): void
    {
        $topic = (string) file_get_contents(dirname(__DIR__, 3) . '/docs/help/connexion-securisee.md');
        $this->observedAt('2026-06-01 11:30:00');

        $value = $this->check('2026-06-01 12:00:00')->read()->value;
        $this->assertSame('dernier accès non sécurisé il y a moins d\'une heure', $value);
        $this->assertStringContainsString('« dernier accès non sécurisé »', $topic);
        $this->assertStringContainsString("moins d'une\nheure", $topic);
        $this->assertSame(24, InsecureBrowserAccess::ACTIVE_HOURS);
        $this->assertStringContainsString('24 heures', $topic);
    }
}
