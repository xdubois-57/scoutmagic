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
use Modules\Registration\Repository\RegistrationRequest;
use Modules\Registration\Repository\RegistrationRequestRepository;
use Modules\Registration\Repository\RegistrationYearCodeRepository;
use Modules\Registration\Service\FamilyEmailCopyService;
use Modules\Registration\Service\RegistrationException;
use Modules\Registration\Service\RegistrationService;
use Modules\Registration\Service\TrackingLinkValidity;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Registration\RegistrationTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
// This class builds its doubles once (in setUp() or a shared helper) and
// hands the same ones to every test: some tests set expectations on
// them, the others only need their answers, and PHPUnit would report
// each of those as a mock with no expectation (issue #665).
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
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
    private JournalService&\PHPUnit\Framework\MockObject\Stub $journalService;
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
        $this->journalService = $this->createStub(JournalService::class);
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

    public function testIsYearCodeValidTrueForARegeneratedCode(): void
    {
        $code = $this->yearCodeRepository->regenerate($this->publicYearId);

        $this->assertTrue($this->service->isYearCodeValid($code));
    }

    public function testIsYearCodeValidFalseForNoCode(): void
    {
        $this->assertFalse($this->service->isYearCodeValid(null));
        $this->assertFalse($this->service->isYearCodeValid(''));
    }

    public function testIsYearCodeValidFalseForWrongCode(): void
    {
        $this->yearCodeRepository->regenerate($this->publicYearId);

        $this->assertFalse($this->service->isYearCodeValid('WRONG-CODE'));
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

    /**
     * Issue #331 — the module's own invariant, not the public form's.
     * Controller\PublicRegistrationController::validate() already refuses
     * this pair with a field error and the family's data kept; the guard
     * here is for a caller that never goes through that form, since a
     * « oui » naming no unit is a claim the fiche cannot substantiate.
     */
    public function testSubmitRefusesAPreviousUnitClaimedButNotNamed(): void
    {
        $target = $this->service->resolveTargetYear(null);

        $this->expectException(RegistrationException::class);
        $this->expectExceptionMessage('de quelle unité');

        $this->service->submit(
            (int) $target['id'],
            (string) $target['label'],
            $this->sampleFields([
                'previous_unit_answer' => RegistrationRequest::PREVIOUS_UNIT_YES,
                'previous_unit_name' => '   ',
            ]),
            null,
            [],
            'Baladins — 1ère année'
        );
    }

    /**
     * The third state stays legitimate: a caller that does not carry the
     * question at all — the reference dataset's seeder, every request
     * filed before the column existed — files a request on which nobody
     * was asked, and nothing refuses it.
     */
    public function testSubmitAcceptsARequestThatNeverCarriedTheQuestion(): void
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
        $this->assertNull($stored->previousUnitAnswer);
        $this->assertFalse($stored->hasPreviousUnit());
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

        // The tracking token grants unauthenticated access to the family's
        // follow-up page — it belongs in the parent's email only. The unit
        // alert links to the staff fiche instead.
        $this->assertStringContainsString('/inscriptions/suivi/', $toParent[0]['bodyHtml']);
        $this->assertStringNotContainsString('/inscriptions/suivi/', $toUnit[0]['bodyHtml']);
        $this->assertStringContainsString('/config/inscriptions/demandes/', $toUnit[0]['bodyHtml']);
    }

    /**
     * Issue #932: the token is generated with the request, stored hashed and
     * encrypted, and the link the receipt carries is that very one — the
     * link every later e-mail will read back.
     */
    public function testTheReceiptCarriesTheLinkTheRequestWasFiledWith(): void
    {
        $target = $this->service->resolveTargetYear(null);
        $bodies = [];
        $this->mailService->method('send')->willReturnCallback(function (...$args) use (&$bodies): void {
            $bodies[$args[0]] = (string) $args[2];
        });

        $requestId = $this->service->submit(
            (int) $target['id'],
            (string) $target['label'],
            $this->sampleFields(),
            null,
            [],
            'Baladins — 1ère année'
        );

        $this->assertSame(
            1,
            preg_match('#https://example\.com/inscriptions/suivi/' . $requestId . '/([^"<\s]+)#', $bodies['marie.dupont@example.com'], $match)
        );
        $token = html_entity_decode($match[1]);
        $this->assertTrue($this->requestRepository->verifyTrackingToken($requestId, $token));
        $this->assertSame($token, $this->requestRepository->trackingLinkOf($requestId)['token'] ?? null);
        // Stored encrypted and hashed, never in clear.
        $row = $this->pdo->query(
            'SELECT tracking_token_hash, tracking_token_encrypted FROM registration_requests WHERE id = ' . $requestId
        )->fetch(\PDO::FETCH_ASSOC);
        $this->assertStringNotContainsString($token, (string) $row['tracking_token_hash']);
        $this->assertStringNotContainsString($token, (string) $row['tracking_token_encrypted']);
        $this->assertNotSame('', (string) $row['tracking_token_encrypted']);
    }

    /**
     * Issue #932: eighteen calendar months from the moment of filing by
     * default, frozen on the row.
     */
    public function testTheLinkExpiresEighteenMonthsAfterTheFilingByDefault(): void
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

        [$received, $expires] = $this->filingAndExpiry($requestId);
        $this->assertSame(
            TrackingLinkValidity::expiresAt(new \DateTimeImmutable($received), 18)->format('Y-m-d H:i:s'),
            $expires
        );
    }

    /**
     * The setting is read when the request is filed: a later change only
     * reaches the requests filed after it.
     */
    public function testChangingTheValidityOnlyAffectsTheRequestsFiledAfterwards(): void
    {
        $this->settingService->register(
            TrackingLinkValidity::SETTING,
            '18',
            'number',
            'Durée',
            'desc',
            'registration'
        );
        $target = $this->service->resolveTargetYear(null);
        $file = fn(string $email): int => $this->service->submit(
            (int) $target['id'],
            (string) $target['label'],
            $this->sampleFields(['email' => $email]),
            null,
            [],
            'Baladins — 1ère année'
        );

        $this->settingService->set(TrackingLinkValidity::SETTING, '6', 'registration');
        $sixMonths = $file('six@example.com');
        $this->settingService->set(TrackingLinkValidity::SETTING, '24', 'registration');
        $twentyFour = $file('vingt-quatre@example.com');

        [$receivedA, $expiresA] = $this->filingAndExpiry($sixMonths);
        [$receivedB, $expiresB] = $this->filingAndExpiry($twentyFour);
        $this->assertSame(
            TrackingLinkValidity::expiresAt(new \DateTimeImmutable($receivedA), 6)->format('Y-m-d H:i:s'),
            $expiresA
        );
        $this->assertSame(
            TrackingLinkValidity::expiresAt(new \DateTimeImmutable($receivedB), 24)->format('Y-m-d H:i:s'),
            $expiresB
        );
        // The first one kept its own expiry after the second change.
        [, $expiresAgain] = $this->filingAndExpiry($sixMonths);
        $this->assertSame($expiresA, $expiresAgain);
    }

    /** Ordinary and in-year (special code) submissions follow the very same rule. */
    public function testAnInYearSubmissionGetsTheSameValidity(): void
    {
        $code = $this->yearCodeRepository->regenerate($this->publicYearId);
        $ordinary = $this->service->resolveTargetYear(null);
        $inYear = $this->service->resolveTargetYear($code);

        $a = $this->service->submit((int) $ordinary['id'], (string) $ordinary['label'], $this->sampleFields(), null, [], 'S');
        $b = $this->service->submit(
            (int) $inYear['id'],
            (string) $inYear['label'],
            $this->sampleFields(['email' => 'autre@example.com']),
            null,
            [],
            'S'
        );

        foreach ([$a, $b] as $id) {
            [$received, $expires] = $this->filingAndExpiry($id);
            $this->assertSame(
                TrackingLinkValidity::expiresAt(new \DateTimeImmutable($received), 18)->format('Y-m-d H:i:s'),
                $expires,
                "request #{$id}"
            );
        }
    }

    /**
     * Issue #932: the receipt never goes out without the family's link. The
     * request is recorded all the same — the family has filed — and the
     * unit is still alerted.
     */
    public function testAReceiptBodyWithoutTheLinkIsNotSentButTheRequestIsStillFiled(): void
    {
        $this->settingService->set('registration_unit_alert_email', 'unite@example.com', 'registration');
        $content = new EditableContentService(new EditableContentRepository($this->pdo));
        $content->set('registration_email_receipt_body', '<p>Merci {{ prenom_enfant }}</p>', 'rich_text', 1);
        $recipients = [];
        $this->mailService->method('send')->willReturnCallback(function (...$args) use (&$recipients): void {
            $recipients[] = $args[0];
        });
        $target = $this->service->resolveTargetYear(null);

        $requestId = $this->service->submit(
            (int) $target['id'],
            (string) $target['label'],
            $this->sampleFields(),
            null,
            [],
            'Baladins — 1ère année'
        );

        $this->assertNotNull($this->requestRepository->findById($requestId));
        $this->assertSame(['unite@example.com'], $recipients, 'nothing went to the family, the alert did');
    }

    /** The staff alert never carries the family's secret, not even as a literal variable. */
    public function testTheUnitAlertNeverCarriesTheFamilysToken(): void
    {
        $this->settingService->set('registration_unit_alert_email', 'unite@example.com', 'registration');
        $content = new EditableContentService(new EditableContentRepository($this->pdo));
        $content->set(
            'registration_email_unit_alert_body',
            '<p>Demande de {{ prenom_enfant }} : {{ lien_fiche }} {{ lien_suivi }}</p>',
            'rich_text',
            1
        );
        $bodies = [];
        $this->mailService->method('send')->willReturnCallback(function (...$args) use (&$bodies): void {
            $bodies[$args[0]] = (string) $args[2];
        });
        $target = $this->service->resolveTargetYear(null);

        $requestId = $this->service->submit(
            (int) $target['id'],
            (string) $target['label'],
            $this->sampleFields(),
            null,
            [],
            'Baladins — 1ère année'
        );

        $token = (string) ($this->requestRepository->trackingLinkOf($requestId)['token'] ?? '');
        $this->assertNotSame('', $token);
        $this->assertStringNotContainsString($token, $bodies['unite@example.com']);
        $this->assertStringNotContainsString('/inscriptions/suivi/', $bodies['unite@example.com']);
        $this->assertStringContainsString('/config/inscriptions/demandes/' . $requestId, $bodies['unite@example.com']);
    }

    /**
     * @return array{0: string, 1: string} received_at and tracking_expires_at, as stored
     */
    private function filingAndExpiry(int $requestId): array
    {
        $row = $this->pdo->query(
            'SELECT received_at, tracking_expires_at FROM registration_requests WHERE id = ' . $requestId
        )->fetch(\PDO::FETCH_ASSOC);

        return [(string) $row['received_at'], (string) $row['tracking_expires_at']];
    }

    /**
     * The receipt to the family carries the unit in copy (chantier #843,
     * D9) — by default the unit's own address, the core « Email de
     * contact » — and the unit alert, which already goes to the unit,
     * carries none.
     */
    public function testTheReceiptCarriesTheUnitAddressInCopyAndTheUnitAlertNone(): void
    {
        $this->settingService->set('registration_unit_alert_email', 'alerte@example.com', 'registration');
        $service = $this->serviceWithCopy(enabled: '1', address: '', unitAddress: 'info@unite.example.com');

        $copies = $this->submitAndRecordCopies($service, 2);

        $this->assertSame('info@unite.example.com', $copies['marie.dupont@example.com'] ?? 'missing');
        $this->assertArrayHasKey('alerte@example.com', $copies);
        $this->assertNull($copies['alerte@example.com'], 'the unit alert is never copied.');
    }

    /** An address typed in the box replaces the unit's own. */
    public function testATypedCopyAddressReplacesTheUnitAddress(): void
    {
        $service = $this->serviceWithCopy('1', 'secretariat@example.com', 'info@unite.example.com');

        $copies = $this->submitAndRecordCopies($service, 1);

        $this->assertSame('secretariat@example.com', $copies['marie.dupont@example.com'] ?? 'missing');
    }

    /** Switched off, the receipt goes to the family alone. */
    public function testNoCopyWhenTheOptionIsOff(): void
    {
        $service = $this->serviceWithCopy('0', 'secretariat@example.com', 'info@unite.example.com');

        $copies = $this->submitAndRecordCopies($service, 1);

        $this->assertArrayHasKey('marie.dupont@example.com', $copies);
        $this->assertNull($copies['marie.dupont@example.com']);
    }

    private function serviceWithCopy(string $enabled, string $address, string $unitAddress): RegistrationService
    {
        $settings = $this->settingService;
        $settings->register(FamilyEmailCopyService::SETTING_ENABLED, '1', 'boolean', 'Copie', 'desc', 'registration');
        $settings->register(FamilyEmailCopyService::SETTING_ADDRESS, '', 'email', 'Adresse', 'desc', 'registration');
        $this->settingService->register(FamilyEmailCopyService::SETTING_UNIT_ADDRESS, '', 'email', 'Contact', 'desc');
        $this->settingService->set(FamilyEmailCopyService::SETTING_ENABLED, $enabled, 'registration');
        $this->settingService->set(FamilyEmailCopyService::SETTING_ADDRESS, $address, 'registration');
        $this->settingService->set(FamilyEmailCopyService::SETTING_UNIT_ADDRESS, $unitAddress);

        return new RegistrationService(
            $this->requestRepository,
            $this->yearCodeRepository,
            $this->scoutYearResolver,
            $this->scoutYearService,
            $this->settingService,
            $this->mailService,
            new EditableContentService(new EditableContentRepository($this->pdo)),
            $this->journalService,
            'https://example.com',
            'Unité Test',
            new FamilyEmailCopyService($this->settingService)
        );
    }

    /**
     * Submits one request and returns, per recipient, the `cc` its message
     * was sent with — read by the parameter's NAME, so a reordering of
     * `MailService::send()` cannot make this read the wrong argument.
     *
     * @return array<string, mixed>
     */
    private function submitAndRecordCopies(RegistrationService $service, int $sends): array
    {
        $ccIndex = self::sendParameterIndex('cc');
        $copies = [];
        $this->mailService->expects($this->exactly($sends))
            ->method('send')
            ->willReturnCallback(function (...$args) use (&$copies, $ccIndex): void {
                $copies[(string) $args[0]] = $args[$ccIndex] ?? null;
            });

        $target = $service->resolveTargetYear(null);
        $service->submit((int) $target['id'], (string) $target['label'], $this->sampleFields(), null, [], 'Baladins');

        return $copies;
    }

    private static function sendParameterIndex(string $name): int
    {
        foreach ((new \ReflectionMethod(MailService::class, 'send'))->getParameters() as $parameter) {
            if ($parameter->getName() === $name) {
                return $parameter->getPosition();
            }
        }

        throw new \LogicException("MailService::send() has no parameter {$name}.");
    }

    public function testSubmitSkipsUnitAlertWhenNoAlertEmailConfigured(): void
    {
        $target = $this->service->resolveTargetYear(null);

        // The one message left is the PARENT's acknowledgement. Without the
        // address, this test passes just as well if the single send were the
        // unit alert going out to a stale address — which is precisely the
        // case it exists to rule out (issue #439).
        $this->mailService->expects($this->once())->method('send')
            ->with($this->identicalTo('marie.dupont@example.com'));

        $this->service->submit(
            (int) $target['id'],
            (string) $target['label'],
            $this->sampleFields(),
            null,
            [],
            'Baladins — 1ère année'
        );
    }

    /**
     * A receipt the unit wrote in « Gabarits » (issue #843, IT-07) arrives
     * the way the editor stores it — spaced variables, the link
     * percent-encoded by the sanitiser — and still says the child's name
     * and carries a working tracking link.
     */
    public function testACustomisedReceiptWrittenInTheEditorIsSubstituted(): void
    {
        (new \Modules\Registration\Service\RequestEmailTemplateService(
            new EditableContentService(new EditableContentRepository($this->pdo))
        ))->save(
            'registration_email_receipt_body',
            '<p>Merci pour {{ prenom_enfant }} : <a href="{{ lien_suivi }}">suivre la demande</a></p>',
            1
        );
        $target = $this->service->resolveTargetYear(null);

        $this->mailService->expects($this->once())->method('send')
            ->willReturnCallback(function (...$args): void {
                $this->assertSame('marie.dupont@example.com', $args[0]);
                $this->assertStringContainsString('Merci pour Léa', $args[2]);
                $this->assertMatchesRegularExpression(
                    '#<a href="https://example\.com/inscriptions/suivi/\d+/[^"]+">suivre la demande</a>#',
                    $args[2]
                );
                $this->assertStringNotContainsString('lien_suivi', $args[2]);
            });

        $this->service->submit(
            (int) $target['id'],
            (string) $target['label'],
            $this->sampleFields(),
            null,
            [],
            'Baladins'
        );
    }
}
