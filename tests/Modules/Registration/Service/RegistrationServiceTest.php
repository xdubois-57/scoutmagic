<?php

declare(strict_types=1);

namespace Tests\Modules\Registration\Service;

use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Import\MemberYearRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\EncryptionService;
use Core\View\EditableContentRepository;
use Core\View\EditableContentService;
use Modules\Registration\Repository\RegistrationRequestRepository;
use Modules\Registration\Repository\RegistrationYearCodeRepository;
use Modules\Registration\Service\RegistrationService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Registration\RegistrationTestHelper;

/**
 * @group database
 */
class RegistrationServiceTest extends TestCase
{
    private \PDO $pdo;
    private RegistrationService $service;
    private RegistrationRequestRepository $requestRepository;
    private RegistrationYearCodeRepository $yearCodeRepository;
    private SettingService $settingService;
    private ScoutYearService $scoutYearService;
    private ScoutYearResolver $scoutYearResolver;
    private MailService&\PHPUnit\Framework\MockObject\MockObject $mailService;
    private JournalService&\PHPUnit\Framework\MockObject\MockObject $journalService;
    private int $publicYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RegistrationTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->requestRepository = new RegistrationRequestRepository($this->pdo, $encryption);
        $this->yearCodeRepository = new RegistrationYearCodeRepository($this->pdo);
        $this->settingService = new SettingService(new SettingRepository($this->pdo));
        $this->settingService->register('registration_form_open', '0', 'boolean', 'Ouvert', 'desc', 'registration');
        $this->settingService->register('registration_unit_alert_email', '', 'text', 'Alerte', 'desc', 'registration');
        $this->settingService->register(ScoutYearResolver::SETTING_PUBLIC_YEAR, '0', 'number', 'Public', 'desc', null, '^[0-9]+$', null, false);
        $this->settingService->register(ScoutYearResolver::SETTING_STAFF_YEAR, '0', 'number', 'Staff', 'desc', null, '^[0-9]+$', null, false);

        $this->scoutYearService = new ScoutYearService($this->pdo);
        $this->scoutYearResolver = new ScoutYearResolver($this->scoutYearService, $this->settingService, new MemberYearRepository($this->pdo));

        $this->publicYearId = $this->scoutYearService->ensureYear('2026-2027');
        $this->settingService->setInternal(ScoutYearResolver::SETTING_PUBLIC_YEAR, (string) $this->publicYearId);

        $this->mailService = $this->createMock(MailService::class);
        $this->journalService = $this->createMock(JournalService::class);
        $editableContentService = new EditableContentService(new EditableContentRepository($this->pdo));

        $this->service = new RegistrationService(
            $this->requestRepository,
            $this->yearCodeRepository,
            $this->scoutYearResolver,
            $this->scoutYearService,
            $this->settingService,
            $this->mailService,
            $editableContentService,
            $this->journalService,
            'https://example.com',
            'Unité Test'
        );
    }

    private function sampleFields(array $overrides = []): array
    {
        return array_merge([
            'parent_name' => 'Marie Dupont',
            'child_last_name' => 'Dupont',
            'child_first_name' => 'Léa',
            'gender' => 'F',
            'birth_date' => '2019-05-12',
            'street' => 'Rue de la Paix',
            'number' => '12',
            'postal_code' => '1000',
            'city' => 'Bruxelles',
            'email' => 'marie.dupont@example.com',
            'phone1' => '0470123456',
            'phone2' => null,
            'remarks' => null,
        ], $overrides);
    }

    public function testIsFormOpenReflectsSetting(): void
    {
        $this->assertFalse($this->service->isFormOpen());

        $this->settingService->set('registration_form_open', '1', 'registration');
        $this->assertTrue($this->service->isFormOpen());
    }

    public function testCanSubmitTrueWhenFormOpenRegardlessOfCode(): void
    {
        $this->settingService->set('registration_form_open', '1', 'registration');

        $this->assertTrue($this->service->canSubmit(null));
        $this->assertTrue($this->service->canSubmit('anything'));
    }

    /**
     * A valid in-year code is its own, independent open/close mechanism —
     * a family a chief has given a code to must still be able to register
     * while the general form is closed (module spec, §8.35).
     */
    public function testCanSubmitTrueWhenFormClosedButCodeIsValid(): void
    {
        $this->assertFalse($this->service->isFormOpen());
        $code = $this->yearCodeRepository->regenerate($this->publicYearId);

        $this->assertTrue($this->service->canSubmit($code));
    }

    public function testCanSubmitFalseWhenFormClosedAndNoCode(): void
    {
        $this->assertFalse($this->service->isFormOpen());

        $this->assertFalse($this->service->canSubmit(null));
        $this->assertFalse($this->service->canSubmit(''));
    }

    public function testCanSubmitFalseWhenFormClosedAndCodeInvalid(): void
    {
        $this->yearCodeRepository->regenerate($this->publicYearId);

        $this->assertFalse($this->service->canSubmit('WRONG-CODE'));
    }

    public function testResolveTargetYearWithoutCodeTargetsNextYear(): void
    {
        $target = $this->service->resolveTargetYear(null);

        $this->assertSame('2027-2028', $target['label']);
        $this->assertFalse($target['used_code']);
    }

    public function testResolveTargetYearWithValidCodeTargetsCurrentYear(): void
    {
        $code = $this->yearCodeRepository->regenerate($this->publicYearId);

        $target = $this->service->resolveTargetYear($code);

        $this->assertSame($this->publicYearId, $target['id']);
        $this->assertSame('2026-2027', $target['label']);
        $this->assertTrue($target['used_code']);
    }

    public function testResolveTargetYearWithInvalidCodeFallsBackToNextYear(): void
    {
        $this->yearCodeRepository->regenerate($this->publicYearId);

        $target = $this->service->resolveTargetYear('WRONG-CODE');

        $this->assertSame('2027-2028', $target['label']);
        $this->assertFalse($target['used_code']);
    }

    public function testResolveTargetYearWithNoActiveCodeFallsBackToNextYear(): void
    {
        $target = $this->service->resolveTargetYear('ANYTHING');

        $this->assertSame('2027-2028', $target['label']);
        $this->assertFalse($target['used_code']);
    }

    public function testSubmitPersistsAPendingEncryptedRequest(): void
    {
        $target = $this->service->resolveTargetYear(null);

        $requestId = $this->service->submit(
            (int) $target['id'],
            (string) $target['label'],
            $this->sampleFields(),
            null,
            [],
            'Baladins — 1ère année'
        );

        $stored = $this->requestRepository->findById($requestId);
        $this->assertNotNull($stored);
        $this->assertSame('pending', $stored->status);
        $this->assertSame('Léa', $stored->childFirstName);

        $row = $this->pdo->query('SELECT child_first_name_encrypted FROM registration_requests WHERE id = ' . $requestId)->fetch(\PDO::FETCH_ASSOC);
        $this->assertStringNotContainsString('Léa', (string) $row['child_first_name_encrypted']);
    }

    public function testSubmitSendsReceiptToParentAndAlertToUnitWithOnlyFirstName(): void
    {
        $this->settingService->set('registration_unit_alert_email', 'unite@example.com', 'registration');
        $target = $this->service->resolveTargetYear(null);

        $captured = [];
        $this->mailService->expects($this->exactly(2))
            ->method('send')
            ->willReturnCallback(function (...$args) use (&$captured): void {
                $captured[] = ['to' => $args[0], 'subject' => $args[1], 'bodyHtml' => $args[2]];
            });

        $this->service->submit(
            (int) $target['id'],
            (string) $target['label'],
            $this->sampleFields(),
            null,
            [],
            'Baladins — 1ère année'
        );

        $this->assertCount(2, $captured);

        $toParent = array_values(array_filter($captured, fn($c) => $c['to'] === 'marie.dupont@example.com'));
        $this->assertCount(1, $toParent);
        $this->assertStringContainsString('Léa', $toParent[0]['bodyHtml']);

        $toUnit = array_values(array_filter($captured, fn($c) => $c['to'] === 'unite@example.com'));
        $this->assertCount(1, $toUnit);
        $this->assertStringContainsString('Léa', $toUnit[0]['bodyHtml']);
        // The unit alert must never contain the family's identifying details.
        $this->assertStringNotContainsString('Dupont', $toUnit[0]['bodyHtml']);
        $this->assertStringNotContainsString('marie.dupont@example.com', $toUnit[0]['bodyHtml']);
        $this->assertStringNotContainsString('Rue de la Paix', $toUnit[0]['bodyHtml']);
    }

    public function testSubmitSkipsUnitAlertWhenNoAlertEmailConfigured(): void
    {
        $target = $this->service->resolveTargetYear(null);

        $this->mailService->expects($this->once())->method('send');

        $this->service->submit(
            (int) $target['id'],
            (string) $target['label'],
            $this->sampleFields(),
            null,
            [],
            'Baladins — 1ère année'
        );
    }
}
