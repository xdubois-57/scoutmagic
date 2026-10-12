<?php

declare(strict_types=1);

namespace Tests\Modules\Registration\Service;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Modules\Registration\Service\FamilyEmailCopyService;
use Modules\Registration\Service\RegistrationException;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * « Copie des e-mails d'inscription » (chantier #843, D9 / IT-06): which
 * address the three e-mails to a family carry in copy, and what the box
 * on « Réglages » accepts to save.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class FamilyEmailCopyServiceTest extends TestCase
{
    private \PDO $pdo;
    private SettingService $settings;
    private FamilyEmailCopyService $service;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->settings = new SettingService(new SettingRepository($this->pdo));
        // As module.json declares them: on, an empty box, and neither
        // editable on Configuration > Paramètres — this box writes them.
        $module = 'registration';
        $enabled = FamilyEmailCopyService::SETTING_ENABLED;
        $address = FamilyEmailCopyService::SETTING_ADDRESS;
        $this->settings->register($enabled, '1', 'boolean', 'Copie', 'desc', $module, null, null, false);
        $this->settings->register($address, '', 'email', 'Adresse', 'desc', $module, null, null, false);
        $this->settings->register(FamilyEmailCopyService::SETTING_UNIT_ADDRESS, '', 'email', 'Contact', 'desc');

        $journal = new JournalService(new JournalRepository($this->pdo));
        $this->service = new FamilyEmailCopyService($this->settings, $journal);
    }

    /** Out of the box: on, and the unit's own address is the copy. */
    public function testByDefaultTheUnitAddressIsInCopy(): void
    {
        $this->settings->set(FamilyEmailCopyService::SETTING_UNIT_ADDRESS, 'info@unite.example.com');

        $this->assertTrue($this->service->isEnabled());
        $this->assertSame('info@unite.example.com', $this->service->copyAddress());
    }

    public function testATypedAddressWinsOverTheUnitAddress(): void
    {
        $this->settings->set(FamilyEmailCopyService::SETTING_UNIT_ADDRESS, 'info@unite.example.com');
        $this->settings->setInternal(
            FamilyEmailCopyService::SETTING_ADDRESS,
            'secretariat@unite.example.com',
            'registration'
        );

        $this->assertSame('secretariat@unite.example.com', $this->service->copyAddress());
    }

    public function testSwitchedOffThereIsNoCopy(): void
    {
        $this->settings->set(FamilyEmailCopyService::SETTING_UNIT_ADDRESS, 'info@unite.example.com');
        $this->settings->setInternal(FamilyEmailCopyService::SETTING_ENABLED, '0', 'registration');

        $this->assertNull($this->service->copyAddress());
    }

    /**
     * Switched on with nothing to resolve — the unit's address cleared
     * since, on another page: the e-mail to the family still leaves, with
     * no copy, and the journal says so without naming anybody.
     */
    public function testNothingToResolveMeansNoCopyAndAJournalLine(): void
    {
        $this->assertNull($this->service->copyAddress());

        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM event_log WHERE event_type = ?');
        $statement->execute(['registration_email_copy_unresolved']);
        $this->assertSame(1, (int) $statement->fetchColumn());
    }

    /**
     * A unit address that is not an address — written past the settings
     * page's own check — is not resolvable either.
     */
    public function testAnInvalidUnitAddressIsNotUsed(): void
    {
        (new SettingRepository($this->pdo))
            ->updateValue(null, FamilyEmailCopyService::SETTING_UNIT_ADDRESS, 'pas-une-adresse');
        $this->settings->clearCache();

        $this->assertNull($this->service->copyAddress());
        $this->assertFalse($this->service->boxState()['resolvable']);
    }

    public function testSaveStoresTheSwitchAndTheTrimmedAddress(): void
    {
        $this->service->save(true, '  secretariat@unite.example.com ');

        $this->assertSame('1', $this->settings->get(FamilyEmailCopyService::SETTING_ENABLED, 'registration'));
        $this->assertSame('secretariat@unite.example.com', $this->service->configuredAddress());
    }

    public function testSaveRefusesAnInvalidAddressAndSavesNothing(): void
    {
        $this->settings->set(FamilyEmailCopyService::SETTING_UNIT_ADDRESS, 'info@unite.example.com');

        try {
            $this->service->save(false, 'pas-une-adresse');
            $this->fail('An invalid address must be refused.');
        } catch (RegistrationException $e) {
            $this->assertSame(FamilyEmailCopyService::MESSAGE_INVALID, $e->getMessage());
        }

        $this->assertTrue($this->service->isEnabled(), 'the switch is not saved either.');
        $this->assertSame('', $this->service->configuredAddress());
    }

    public function testSaveRefusesToSwitchOnWithNothingToResolve(): void
    {
        $this->settings->setInternal(FamilyEmailCopyService::SETTING_ENABLED, '0', 'registration');

        $this->expectException(RegistrationException::class);
        $this->expectExceptionMessage(FamilyEmailCopyService::MESSAGE_UNRESOLVABLE);

        try {
            $this->service->save(true, '');
        } finally {
            $this->assertFalse($this->service->isEnabled());
        }
    }

    /** Switched off, an empty box needs no address to resolve. */
    public function testSwitchingOffNeedsNoAddress(): void
    {
        $this->service->save(false, '');

        $this->assertFalse($this->service->isEnabled());
    }

    /** A field that was not submitted (disabled) keeps the stored address. */
    public function testANullAddressKeepsTheStoredOne(): void
    {
        $this->settings->setInternal(
            FamilyEmailCopyService::SETTING_ADDRESS,
            'secretariat@unite.example.com',
            'registration'
        );

        $this->service->save(false, null);

        $this->assertFalse($this->service->isEnabled());
        $this->assertSame('secretariat@unite.example.com', $this->service->configuredAddress());
    }

    public function testBoxStateSaysWhatTheBoxShows(): void
    {
        $this->settings->set(FamilyEmailCopyService::SETTING_UNIT_ADDRESS, 'info@unite.example.com');

        $this->assertSame(
            ['enabled' => true, 'address' => '', 'unit_address' => 'info@unite.example.com', 'resolvable' => true],
            $this->service->boxState()
        );
    }
}
