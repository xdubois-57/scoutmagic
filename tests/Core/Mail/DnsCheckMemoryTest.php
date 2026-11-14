<?php

declare(strict_types=1);

namespace Tests\Core\Mail;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Mail\DnsCheckMemory;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * What the last DNS lookup said, kept so the « Courrier sortant »
 * dashboard can show a dated state without querying a resolver.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class DnsCheckMemoryTest extends TestCase
{
    private \PDO $pdo;
    private SettingService $settings;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->settings = new SettingService(new SettingRepository($this->pdo));
        // Registered as the composition root registers it (public/index.php).
        $this->settings->register(
            DnsCheckMemory::SETTING_KEY,
            '',
            'text',
            'Dernière vérification DNS',
            'Test',
            null,
            null,
            null,
            false
        );
    }

    /**
     * **A reading that cannot be kept never turns the DNS check into an
     * error page.** The lookup itself has succeeded by the time this
     * runs; the memory is a convenience for the dashboard, so a settings
     * write that fails costs the dashboard its line — « jamais vérifié »
     * — and nothing else.
     *
     * Writes to `settings` are refused by a trigger, reads left working:
     * the `PDOException` a database refusing the update throws. Both
     * halves, the trigger being the only difference, so the null below is
     * the swallowed write's and not a reading this class never keeps.
     */
    public function testAReadingThatCannotBeKeptIsLostWithoutFailingTheCheck(): void
    {
        $this->pdo->exec(
            'CREATE TRIGGER refuse_setting_writes BEFORE UPDATE ON settings
             BEGIN SELECT RAISE(FAIL, \'réglages indisponibles\'); END'
        );

        $this->remember();

        $this->assertNull(
            DnsCheckMemory::read($this->settings),
            'Nothing was kept, so the dashboard reads « jamais vérifié ».'
        );

        $this->pdo->exec('DROP TRIGGER refuse_setting_writes');
        $this->remember();

        $memory = DnsCheckMemory::read($this->settings);
        $this->assertNotNull($memory, 'The same reading, once it can be written, is kept.');
        $this->assertTrue($memory->state(DnsCheckMemory::SPF));
    }

    /**
     * **A stored reading that is not JSON is « jamais vérifié », never an
     * error page.** The dashboard, the Authentification sub-page and the
     * support package all read this memory; a value cut short or edited
     * by hand must cost them the line and nothing more.
     *
     * Both halves, the closing brace being the only difference, so the
     * null is the unreadable value's and not a reading this class never
     * keeps.
     */
    public function testAStoredReadingThatIsNotJsonIsNoReading(): void
    {
        $this->remember();
        $whole = (string) $this->settings->get(DnsCheckMemory::SETTING_KEY);
        $this->assertNotNull(DnsCheckMemory::read($this->settings), 'The premise: the whole value is read.');

        $this->settings->setInternal(DnsCheckMemory::SETTING_KEY, substr($whole, 0, -1));

        $this->assertNull(
            DnsCheckMemory::read($this->settings),
            'A truncated value reads as « jamais vérifié ».'
        );
    }

    private function remember(): void
    {
        DnsCheckMemory::remember(
            $this->settings,
            'unite.be',
            'unite.be',
            's2026',
            'rapports@unite.be',
            [DnsCheckMemory::SPF => [
                'exists' => true,
                'expected' => 'v=spf1 a mx ~all',
                'actual' => 'v=spf1 a mx ~all',
            ]]
        );
    }
}
