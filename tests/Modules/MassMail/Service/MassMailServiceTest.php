<?php

declare(strict_types=1);

namespace Tests\Modules\MassMail\Service;

use Core\Badge\MemberBadgeRepository;
use Core\Config\ScoutYearService;
use Core\Database\Connection;
use Core\File\FileRepository;
use Core\Import\FunctionRepository;
use Core\Import\ImportJournalRepository;
use Core\Import\MemberYearRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Member\MemberEmailRepository;
use Core\Member\MemberEmailService;
use Core\Member\MemberService;
use Core\Member\SectionService;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Security\EncryptionService;
use Core\Security\HtmlSanitizer;
use Modules\MassMail\Repository\AudienceRepository;
use Modules\MassMail\Repository\Email;
use Modules\MassMail\Repository\EmailAttachmentRepository;
use Modules\MassMail\Repository\EmailRepository;
use Modules\MassMail\Repository\MailingListRepository;
use Modules\MassMail\Repository\MemberResolutionRepository;
use Modules\MassMail\Repository\Recipient;
use Modules\MassMail\Repository\RecipientRepository;
use Modules\MassMail\Repository\SuppressedAddressRepository;
use Modules\MassMail\Service\MailingListService;
use Modules\MassMail\Service\MassMailException;
use Modules\MassMail\Service\MassMailService;
use Modules\MassMail\Service\MergeRenderer;
use Modules\MassMail\Service\SenderAuthorization;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\MassMail\MassMailTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class MassMailServiceTest extends TestCase
{
    private \PDO $pdo;
    private MassMailService $service;
    private RecipientRepository $recipientRepository;
    private AudienceRepository $audienceRepository;
    private SuppressedAddressRepository $suppressedAddressRepository;
    private ImportJournalRepository $importJournalRepository;
    private int $scoutYearId;
    private int $sectionId;
    private int $otherSectionId;
    private SenderAuthorization $unrestricted;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        MassMailTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $connection = Connection::withPdo($this->pdo);
        $sectionService = new SectionService($connection, $encryption, new MemberBadgeRepository($this->pdo));
        $memberService = new MemberService(new MemberYearRepository($this->pdo), $encryption, $connection);

        $this->recipientRepository = new RecipientRepository($this->pdo, $encryption);
        $this->importJournalRepository = new ImportJournalRepository($this->pdo);
        $emailRepository = new EmailRepository($this->pdo);
        $listService = new MailingListService(
            new MailingListRepository($this->pdo),
            new MemberResolutionRepository($this->pdo, $encryption),
            $sectionService,
            new FunctionRepository($this->pdo)
        );

        $this->audienceRepository = new AudienceRepository($this->pdo, $encryption);
        $this->suppressedAddressRepository = new SuppressedAddressRepository($this->pdo);
        $this->service = new MassMailService(
            $emailRepository,
            $this->recipientRepository,
            new EmailAttachmentRepository($this->pdo),
            new FileRepository($this->pdo),
            $listService,
            $memberService,
            $this->buildMemberEmailService($encryption, $sectionService, $memberService),
            $sectionService,
            $this->createMock(MailService::class),
            new SchedulerService(new SchedulerRepository($this->pdo)),
            new JournalService(new JournalRepository($this->pdo)),
            new HtmlSanitizer(),
            new ScoutYearService($this->pdo),
            $this->importJournalRepository,
            sys_get_temp_dir(),
            $this->audienceRepository,
            new MemberResolutionRepository($this->pdo, $encryption),
            $this->suppressedAddressRepository,
            new MergeRenderer()
        );

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('LOU', 'Louveteaux', 1)");
        $branchId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO sections (desk_code, age_branch_id, name) VALUES ('LOU01', {$branchId}, 'Meute A')");
        $this->sectionId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO sections (desk_code, age_branch_id, name) VALUES ('LOU02', {$branchId}, 'Meute B')");
        $this->otherSectionId = (int) $this->pdo->lastInsertId();

        $this->unrestricted = new SenderAuthorization(true, [], null);
    }

    /**
     * Same wiring as setUp(), with a caller-supplied (usually capturing)
     * MailService mock instead of the default inert one.
     */
    private function buildServiceWithMailService(MailService $mailServiceMock): MassMailService
    {
        $connection = Connection::withPdo($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $sectionService = new SectionService($connection, $encryption, new MemberBadgeRepository($this->pdo));
        $memberService = new MemberService(new MemberYearRepository($this->pdo), $encryption, $connection);
        $listService = new MailingListService(
            new MailingListRepository($this->pdo),
            new MemberResolutionRepository($this->pdo, $encryption),
            $sectionService,
            new FunctionRepository($this->pdo)
        );

        return new MassMailService(
            new EmailRepository($this->pdo),
            $this->recipientRepository,
            new EmailAttachmentRepository($this->pdo),
            new FileRepository($this->pdo),
            $listService,
            $memberService,
            $this->buildMemberEmailService($encryption, $sectionService, $memberService),
            $sectionService,
            $mailServiceMock,
            new SchedulerService(new SchedulerRepository($this->pdo)),
            new JournalService(new JournalRepository($this->pdo)),
            new HtmlSanitizer(),
            new ScoutYearService($this->pdo),
            $this->importJournalRepository,
            sys_get_temp_dir(),
            new AudienceRepository($this->pdo, $encryption),
            new MemberResolutionRepository($this->pdo, $encryption),
            new SuppressedAddressRepository($this->pdo),
            new MergeRenderer()
        );
    }

    private function buildMemberEmailService(EncryptionService $encryption, SectionService $sectionService, MemberService $memberService): MemberEmailService
    {
        return new MemberEmailService(
            new MemberEmailRepository($this->pdo, $encryption),
            $this->createMock(MailService::class),
            $this->createMock(\Twig\Environment::class),
            new JournalService(new JournalRepository($this->pdo)),
            $sectionService,
            $memberService,
            new ScoutYearService($this->pdo),
            'https://example.test',
            'Test Unité'
        );
    }

    private function createMemberWithEmail(?string $email, bool $consent = true, ?int $scoutYearId = null): int
    {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK_" . uniqid() . "')");
        $memberId = (int) $this->pdo->lastInsertId();

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, email_encrypted, email_blind_index, unit_mail_consent, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1)'
        );
        $stmt->execute([
            $memberId, $scoutYearId ?? $this->scoutYearId,
            $encryption->encrypt('John', 'member_years.first_name'), $encryption->encrypt('Doe', 'member_years.last_name'),
            $email !== null ? $encryption->encrypt($email, 'member_years.email') : null,
            $email !== null ? $encryption->blindIndex($email, 'email') : null,
            $consent ? 1 : 0,
        ]);

        return $memberId;
    }

    private function createDraft(?SenderAuthorization $authorization = null): Email
    {
        return $this->service->createDraft(
            'Sujet', '<p>Corps</p>', $this->sectionId, Email::LIST_TYPE_DEFAULT_ACTIVE_MEMBERS, null, null,
            [$this->scoutYearId], null, $authorization ?? $this->unrestricted
        );
    }

    public function testCreateDraftSanitizesBodyHtml(): void
    {
        $email = $this->service->createDraft(
            'Sujet', '<p>Bonjour</p><script>alert(1)</script>', $this->sectionId,
            Email::LIST_TYPE_DEFAULT_ACTIVE_MEMBERS, null, null, [$this->scoutYearId], null, $this->unrestricted
        );

        $this->assertStringNotContainsString('<script>', $email->bodyHtml);
        $this->assertStringContainsString('Bonjour', $email->bodyHtml);
    }

    public function testCreateDraftRequiresAtLeastOneScoutYear(): void
    {
        $this->expectException(MassMailException::class);
        $this->service->createDraft(
            'Sujet', '<p>Corps</p>', $this->sectionId, Email::LIST_TYPE_DEFAULT_ACTIVE_MEMBERS, null, null,
            [], null, $this->unrestricted
        );
    }

    public function testMoveToTestOnlyAllowedFromDraft(): void
    {
        $email = $this->createDraft();
        $test = $this->service->moveToTest($email->id, null);
        $this->assertSame(Email::STATUS_TEST, $test->status);

        $this->expectException(MassMailException::class);
        $this->service->moveToTest($email->id, null);
    }

    public function testBackToDraftOnlyAllowedFromTest(): void
    {
        $email = $this->createDraft();

        $this->expectException(MassMailException::class);
        $this->service->backToDraft($email->id, null);
    }

    public function testSendingCannotGoBackToDraft(): void
    {
        $email = $this->createDraft();
        $this->service->moveToTest($email->id, null);
        $sending = $this->service->startSending($email->id, null);
        $this->assertSame(Email::STATUS_SENDING, $sending->status);

        $this->expectException(MassMailException::class);
        $this->service->backToDraft($email->id, null);
    }

    public function testStartSendingOnlyAllowedFromTest(): void
    {
        $email = $this->createDraft();

        $this->expectException(MassMailException::class);
        $this->service->startSending($email->id, null);
    }

    public function testStartSendingFreezesRecipientsAndMarksInvalidAddressesAsErrorImmediately(): void
    {
        $this->createMemberWithEmail('valid@test.be');
        $this->createMemberWithEmail(null); // no address at all
        $this->createMemberWithEmail('not-an-email'); // invalid format

        $email = $this->createDraft();
        $this->service->moveToTest($email->id, null);
        $this->service->startSending($email->id, null);

        $counts = $this->service->getStatusCounts($email->id);
        $this->assertSame(1, $counts['pending']);
        $this->assertSame(2, $counts['error']);
        $this->assertSame(3, $counts['total']);
    }

    public function testCheckAndMarkSentIfCompleteTransitionsOnceNoPendingRemain(): void
    {
        $memberId = $this->createMemberWithEmail('valid@test.be');
        $email = $this->createDraft();
        $this->service->moveToTest($email->id, null);
        $sending = $this->service->startSending($email->id, null);

        $recipients = $this->recipientRepository->findByEmailId($sending->id);
        $this->recipientRepository->recordSendSuccess($recipients[0]->id);

        $this->service->checkAndMarkSentIfComplete($sending->id);

        $updated = $this->service->findById($sending->id);
        $this->assertSame(Email::STATUS_SENT, $updated->status);
        $this->assertNotNull($updated->sentAt);
    }

    public function testResendPutsASentEmailBackToSendingAndIncrementsAttempts(): void
    {
        $this->createMemberWithEmail('valid@test.be');
        $email = $this->createDraft();
        $this->service->moveToTest($email->id, null);
        $sending = $this->service->startSending($email->id, null);

        $recipients = $this->recipientRepository->findByEmailId($sending->id);
        $this->recipientRepository->recordSendSuccess($recipients[0]->id);
        $this->service->checkAndMarkSentIfComplete($sending->id);
        $this->assertSame(Email::STATUS_SENT, $this->service->findById($sending->id)->status);

        $this->service->resendToRecipient($recipients[0]->id, null);

        $resent = $this->recipientRepository->findById($recipients[0]->id);
        $this->assertSame(Recipient::STATUS_PENDING, $resent->status);
        $this->assertSame(2, $resent->attempts); // 1 from the original send, +1 from resend

        $this->assertSame(Email::STATUS_SENDING, $this->service->findById($sending->id)->status);
    }

    // --- Mail merge (Excel audience — ARCHITECTURE.md §8.61) ---

    /**
     * @param array<int, array{member_id: ?int, email: ?string, data: array<string, string>}> $rows
     */
    private function createAudience(array $rows, ?int $createdBy = null): int
    {
        $columns = $rows !== [] ? array_keys($rows[0]['data']) : ['Prenom'];
        $audienceId = $this->audienceRepository->createAudience('test.xlsx', 'Feuille1', $columns, count($rows), $createdBy);
        foreach ($rows as $index => $row) {
            $this->audienceRepository->createRow($audienceId, $index + 2, $row['member_id'], $row['email'], $row['data']);
        }
        return $audienceId;
    }

    private function createMergeDraft(int $audienceId, ?SenderAuthorization $authorization = null, ?int $createdBy = null): Email
    {
        return $this->service->createDraft(
            'Infos pour {{Prenom}}', '<p>Cher {{Prenom}}, montant : {{Montant}} €</p>', $this->sectionId,
            Email::LIST_TYPE_MAIL_MERGE, null, null, [], $createdBy, $authorization ?? $this->unrestricted, $audienceId
        );
    }

    public function testCreateMergeDraftRequiresAnAudience(): void
    {
        $this->expectException(MassMailException::class);
        $this->service->createDraft(
            'Sujet', '<p>Corps</p>', $this->sectionId, Email::LIST_TYPE_MAIL_MERGE, null, null,
            [], null, $this->unrestricted, null
        );
    }

    public function testCreateMergeDraftStoresAudienceAndIgnoresScoutYears(): void
    {
        $audienceId = $this->createAudience([
            ['member_id' => null, 'email' => 'a@test.be', 'data' => ['Email' => 'a@test.be', 'Prenom' => 'A', 'Montant' => '10']],
        ]);

        $email = $this->service->createDraft(
            'Sujet', '<p>Corps</p>', $this->sectionId, Email::LIST_TYPE_MAIL_MERGE, null, null,
            [$this->scoutYearId], null, $this->unrestricted, $audienceId
        );

        $this->assertSame($audienceId, $email->audienceId);
        // The file, not a scout year, defines the audience.
        $this->assertSame([], $email->scoutYearIds);
    }

    public function testMergeAudienceOfSomeoneElseIsRefusedForANonChefDUnite(): void
    {
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $accounts = new \Core\Security\UserAccountRepository($this->pdo, $encryption);
        $ownerId = $accounts->create('owner@test.be')->id;
        $otherId = $accounts->create('other@test.be')->id;

        $audienceId = $this->createAudience(
            [['member_id' => null, 'email' => 'a@test.be', 'data' => ['Email' => 'a@test.be']]],
            $ownerId
        );
        $restricted = new SenderAuthorization(false, [$this->sectionId], $this->sectionId);

        // The importer themselves may use it…
        $this->createMergeDraft($audienceId, $restricted, $ownerId);

        // …someone else (non chef d'unité) may not.
        $this->expectException(MassMailException::class);
        $this->createMergeDraft($audienceId, $restricted, $otherId);
    }

    public function testStartSendingFreezesMergeRowsPerRowNotPerList(): void
    {
        $memberId = $this->createMemberWithEmail('member@test.be');
        $audienceId = $this->createAudience([
            ['member_id' => $memberId, 'email' => null, 'data' => ['Tiers' => 'D1', 'Prenom' => 'Louis', 'Montant' => '145']],
            ['member_id' => null, 'email' => 'ext1@test.be; ext2@test.be', 'data' => ['Tiers' => '', 'Prenom' => 'Emma', 'Montant' => '80']],
        ]);

        $email = $this->createMergeDraft($audienceId);
        $this->service->moveToTest($email->id, null);
        $this->service->startSending($email->id, null);

        $recipients = $this->recipientRepository->findByEmailId($email->id);
        $this->assertCount(3, $recipients);

        $memberRecipient = $recipients[0];
        $this->assertSame($memberId, $memberRecipient->memberId);
        $this->assertSame($this->scoutYearId, $memberRecipient->scoutYearId);
        $this->assertSame('member@test.be', $memberRecipient->emailAddress);
        $this->assertNotNull($memberRecipient->audienceRowId);
        $this->assertSame(Recipient::STATUS_PENDING, $memberRecipient->status);

        $external = array_values(array_filter($recipients, fn(Recipient $r) => $r->memberId === null));
        $this->assertCount(2, $external);
        $this->assertSame(['ext1@test.be', 'ext2@test.be'], array_map(fn(Recipient $r) => $r->emailAddress, $external));
        foreach ($external as $recipient) {
            $this->assertNull($recipient->scoutYearId);
            $this->assertNull($recipient->memberEmailId);
            $this->assertNotNull($recipient->audienceRowId);
            $this->assertSame(Recipient::STATUS_PENDING, $recipient->status);
        }
    }

    public function testStartSendingHonorsTheExternalSuppressionList(): void
    {
        $this->suppressedAddressRepository->suppress('Optout@Test.be');
        $audienceId = $this->createAudience([
            ['member_id' => null, 'email' => 'optout@test.be', 'data' => ['Email' => 'optout@test.be']],
            ['member_id' => null, 'email' => 'ok@test.be', 'data' => ['Email' => 'ok@test.be']],
        ]);

        $email = $this->createMergeDraft($audienceId);
        $this->service->moveToTest($email->id, null);
        $this->service->startSending($email->id, null);

        $recipients = $this->recipientRepository->findByEmailId($email->id);
        $this->assertSame(Recipient::STATUS_ERROR, $recipients[0]->status);
        $this->assertSame('Adresse désinscrite des emails groupés', $recipients[0]->errorMessage);
        $this->assertSame(Recipient::STATUS_PENDING, $recipients[1]->status);
    }

    public function testMergeMemberWithoutAnyAddressBecomesAnErrorRowNotARefusal(): void
    {
        $memberId = $this->createMemberWithEmail(null);
        $audienceId = $this->createAudience([
            ['member_id' => $memberId, 'email' => null, 'data' => ['Tiers' => 'D1']],
        ]);

        $email = $this->createMergeDraft($audienceId);
        $this->service->moveToTest($email->id, null);
        $this->service->startSending($email->id, null);

        $counts = $this->service->getStatusCounts($email->id);
        $this->assertSame(0, $counts['pending']);
        $this->assertSame(1, $counts['error']);
    }

    public function testGetMergePreviewRendersTheRowAndFlagsProblems(): void
    {
        $audienceId = $this->createAudience([
            ['member_id' => null, 'email' => 'a@test.be', 'data' => ['Email' => 'a@test.be', 'Prenom' => 'Louis', 'Montant' => '145']],
            ['member_id' => null, 'email' => 'b@test.be', 'data' => ['Email' => 'b@test.be', 'Prenom' => 'Emma', 'Montant' => '']],
        ]);

        $email = $this->service->createDraft(
            'Infos {{Prenom}}', '<p>{{Prenom}} doit {{Montant}} € — {{Typo}}</p>', $this->sectionId,
            Email::LIST_TYPE_MAIL_MERGE, null, null, [], null, $this->unrestricted, $audienceId
        );

        $first = $this->service->getMergePreview($email->id, 0);
        $this->assertSame(0, $first['offset']);
        $this->assertSame(2, $first['total']);
        $this->assertSame('Infos Louis', $first['subject']);
        $this->assertStringContainsString('Louis doit 145', $first['body_html']);
        $this->assertSame('a@test.be', $first['recipient_label']);
        $this->assertSame(['Typo'], $first['unknown_tokens']);
        $this->assertSame([], $first['missing_values']);

        $second = $this->service->getMergePreview($email->id, 1);
        $this->assertSame(['Montant'], $second['missing_values']);

        // Out-of-range offsets clamp instead of erroring.
        $this->assertSame(1, $this->service->getMergePreview($email->id, 99)['offset']);
    }

    public function testSendTestEmailRendersThePreviewedMergeRow(): void
    {
        $audienceId = $this->createAudience([
            ['member_id' => null, 'email' => 'a@test.be', 'data' => ['Email' => 'a@test.be', 'Prenom' => 'Louis', 'Montant' => '145']],
            ['member_id' => null, 'email' => 'b@test.be', 'data' => ['Email' => 'b@test.be', 'Prenom' => 'Emma', 'Montant' => '80']],
        ]);
        $email = $this->createMergeDraft($audienceId);
        $this->service->moveToTest($email->id, null);

        // Rebuild the service around a capturing MailService mock — same
        // approach as testSendTestEmailUsesSectionSenderIdentity() below.
        $capturedSubject = null;
        $capturedBody = null;
        $mailServiceMock = $this->createMock(MailService::class);
        $mailServiceMock->expects($this->once())->method('send')
            ->willReturnCallback(function (...$args) use (&$capturedSubject, &$capturedBody): void {
                $capturedSubject = $args[1];
                $capturedBody = $args[2];
            });
        $service = $this->buildServiceWithMailService($mailServiceMock);

        $service->sendTestEmail($email->id, 'chief@test.be', 1);

        $this->assertSame('[TEST] Infos pour Emma', $capturedSubject);
        $this->assertStringContainsString('Cher Emma, montant : 80', $capturedBody);
    }

    public function testTrackingShowsExternalRecipientsByTheirAddress(): void
    {
        $audienceId = $this->createAudience([
            ['member_id' => null, 'email' => 'ext@test.be', 'data' => ['Email' => 'ext@test.be']],
        ]);
        $email = $this->createMergeDraft($audienceId);
        $this->service->moveToTest($email->id, null);
        $this->service->startSending($email->id, null);

        $tracking = $this->service->getTrackingData($email->id);
        $this->assertSame('ext@test.be', $tracking['recipients'][0]['display_name']);
        $this->assertNull($tracking['recipients'][0]['section_name']);
    }

    // --- Multi-year selection: merge + dedup by address (module addendum) ---

    public function testStartSendingMergesAndDedupesAcrossSelectedYears(): void
    {
        $previousYearId = $this->createPastScoutYear();

        // Same person (same address), active in both years — must count once.
        $bothYears = $this->createMemberWithEmail('both@test.be', scoutYearId: $this->scoutYearId);
        $this->createMemberWithEmail('both@test.be', scoutYearId: $previousYearId);
        // Only in the previous year.
        $this->createMemberWithEmail('onlyprevious@test.be', scoutYearId: $previousYearId);

        $email = $this->service->createDraft(
            'Sujet', '<p>Corps</p>', $this->sectionId, Email::LIST_TYPE_DEFAULT_ACTIVE_MEMBERS, null, null,
            [$this->scoutYearId, $previousYearId], null, $this->unrestricted
        );
        $this->service->moveToTest($email->id, null);
        $this->service->startSending($email->id, null);

        // 2 real people, not 3 rows — the "both@test.be" duplicate collapsed.
        $counts = $this->service->getStatusCounts($email->id);
        $this->assertSame(2, $counts['total']);
    }

    private function createPastScoutYear(): int
    {
        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2024-2025', '2024-09-01', '2025-08-31', 0)");
        return (int) $this->pdo->lastInsertId();
    }

    // --- Sender/list authorization (plain section chief vs chef d'unité) ---

    public function testPlainChiefCanCreateDraftForOwnSectionAndChiefsList(): void
    {
        $auth = new SenderAuthorization(false, [$this->sectionId], $this->sectionId);

        $email = $this->service->createDraft(
            'Sujet', '<p>Corps</p>', $this->sectionId, Email::LIST_TYPE_DEFAULT_SECTION, null, $this->sectionId,
            [$this->scoutYearId], null, $auth
        );
        $this->assertSame($this->sectionId, $email->sectionId);

        $chiefsEmail = $this->service->createDraft(
            'Sujet', '<p>Corps</p>', $this->sectionId, Email::LIST_TYPE_DEFAULT_CHIEFS, null, null,
            [$this->scoutYearId], null, $auth
        );
        $this->assertSame(Email::LIST_TYPE_DEFAULT_CHIEFS, $chiefsEmail->listType);
    }

    public function testPlainChiefCannotTargetActiveMembersOrAnotherSection(): void
    {
        $auth = new SenderAuthorization(false, [$this->sectionId], $this->sectionId);

        $this->expectException(MassMailException::class);
        $this->service->createDraft(
            'Sujet', '<p>Corps</p>', $this->sectionId, Email::LIST_TYPE_DEFAULT_ACTIVE_MEMBERS, null, null,
            [$this->scoutYearId], null, $auth
        );
    }

    public function testPlainChiefCannotTargetAnotherSectionsDefaultList(): void
    {
        $auth = new SenderAuthorization(false, [$this->sectionId], $this->sectionId);

        $this->expectException(MassMailException::class);
        $this->service->createDraft(
            'Sujet', '<p>Corps</p>', $this->sectionId, Email::LIST_TYPE_DEFAULT_SECTION, null, $this->otherSectionId,
            [$this->scoutYearId], null, $auth
        );
    }

    public function testPlainChiefCannotSendFromAnotherSection(): void
    {
        $auth = new SenderAuthorization(false, [$this->sectionId], $this->sectionId);

        $this->expectException(MassMailException::class);
        $this->service->createDraft(
            'Sujet', '<p>Corps</p>', $this->otherSectionId, Email::LIST_TYPE_DEFAULT_CHIEFS, null, null,
            [$this->scoutYearId], null, $auth
        );
    }

    public function testChefDUniteCanTargetAnyListAndSection(): void
    {
        $email = $this->service->createDraft(
            'Sujet', '<p>Corps</p>', $this->otherSectionId, Email::LIST_TYPE_DEFAULT_ACTIVE_MEMBERS, null, null,
            [$this->scoutYearId], null, $this->unrestricted
        );
        $this->assertSame(Email::LIST_TYPE_DEFAULT_ACTIVE_MEMBERS, $email->listType);
    }

    public function testUpdateDraftDoesNotReCheckAnUnchangedOutOfScopeList(): void
    {
        // A chef d'unité creates a draft targeting "Membres actifs" — out of
        // scope for a plain section chief.
        $email = $this->service->createDraft(
            'Sujet', '<p>Corps</p>', $this->sectionId, Email::LIST_TYPE_DEFAULT_ACTIVE_MEMBERS, null, null,
            [$this->scoutYearId], null, $this->unrestricted
        );

        $restrictedAuth = new SenderAuthorization(false, [$this->sectionId], $this->sectionId);
        // The plain chief only edits the subject — list/section untouched —
        // must succeed even though they could never have picked that list themselves.
        $updated = $this->service->updateDraft(
            $email->id, 'Nouveau sujet', '<p>Corps</p>', $this->sectionId, Email::LIST_TYPE_DEFAULT_ACTIVE_MEMBERS, null, null,
            [$this->scoutYearId], $restrictedAuth
        );
        $this->assertSame('Nouveau sujet', $updated->subject);
    }

    public function testUpdateDraftRejectsSwitchingToAnOutOfScopeList(): void
    {
        $restrictedAuth = new SenderAuthorization(false, [$this->sectionId], $this->sectionId);
        // Starts on an allowed list ("Chefs uniquement") ...
        $email = $this->service->createDraft(
            'Sujet', '<p>Corps</p>', $this->sectionId, Email::LIST_TYPE_DEFAULT_CHIEFS, null, null,
            [$this->scoutYearId], null, $restrictedAuth
        );

        // ... then tries to switch to an out-of-scope one.
        $this->expectException(MassMailException::class);
        $this->service->updateDraft(
            $email->id, 'Sujet', '<p>Corps</p>', $this->sectionId, Email::LIST_TYPE_DEFAULT_ACTIVE_MEMBERS, null, null,
            [$this->scoutYearId], $restrictedAuth
        );
    }

    // --- Scout year import gating ---

    public function testCannotCreateDraftForAFutureYearWithoutADeskImport(): void
    {
        $futureYearId = $this->createFutureScoutYear();

        $this->expectException(MassMailException::class);
        $this->service->createDraft(
            'Sujet', '<p>Corps</p>', $this->sectionId, Email::LIST_TYPE_DEFAULT_ACTIVE_MEMBERS, null, null,
            [$futureYearId], null, $this->unrestricted
        );
    }

    public function testCanCreateDraftForAFutureYearOnceDeskHasBeenImported(): void
    {
        $futureYearId = $this->createFutureScoutYear();
        $this->pdo->exec("INSERT INTO import_journal (scout_year_id, line_count, member_count) VALUES ({$futureYearId}, 10, 5)");

        $email = $this->service->createDraft(
            'Sujet', '<p>Corps</p>', $this->sectionId, Email::LIST_TYPE_DEFAULT_ACTIVE_MEMBERS, null, null,
            [$futureYearId], null, $this->unrestricted
        );
        $this->assertSame([$futureYearId], $email->scoutYearIds);
    }

    public function testUpdateDraftDoesNotReCheckAnUnchangedUnimportedScoutYear(): void
    {
        $futureYearId = $this->createFutureScoutYear();
        $this->pdo->exec("INSERT INTO import_journal (scout_year_id, line_count, member_count) VALUES ({$futureYearId}, 10, 5)");
        $email = $this->service->createDraft(
            'Sujet', '<p>Corps</p>', $this->sectionId, Email::LIST_TYPE_DEFAULT_ACTIVE_MEMBERS, null, null,
            [$futureYearId], null, $this->unrestricted
        );

        // The import_journal row is later purged/never existed at edit time —
        // editing unrelated fields without touching the year must still work.
        $this->pdo->exec("DELETE FROM import_journal WHERE scout_year_id = {$futureYearId}");

        $updated = $this->service->updateDraft(
            $email->id, 'Nouveau sujet', '<p>Corps</p>', $this->sectionId, Email::LIST_TYPE_DEFAULT_ACTIVE_MEMBERS, null, null,
            [$futureYearId], $this->unrestricted
        );
        $this->assertSame('Nouveau sujet', $updated->subject);
    }

    private function createFutureScoutYear(): int
    {
        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2026-2027', '2026-09-01', '2027-08-31', 0)");
        return (int) $this->pdo->lastInsertId();
    }

    // --- Sender identity (module addendum: From = sender section, never the site's global mail config) ---

    public function testResolveSenderIdentityReturnsTheSectionsOwnEmailAndName(): void
    {
        $stmt = $this->pdo->prepare('UPDATE sections SET email = ? WHERE id = ?');
        $stmt->execute(['meute-a@test.be', $this->sectionId]);

        $identity = $this->service->resolveSenderIdentity($this->sectionId);

        $this->assertSame('meute-a@test.be', $identity['address']);
        $this->assertSame('Meute A', $identity['name']);
    }

    public function testResolveSenderIdentityFallsBackToNullAddressWhenSectionHasNoEmail(): void
    {
        // $this->sectionId was created with no email (see setUp).
        $identity = $this->service->resolveSenderIdentity($this->sectionId);

        $this->assertNull($identity['address']);
        $this->assertSame('Meute A', $identity['name']);
    }

    public function testSendTestEmailUsesTheSenderSectionsAddressNotTheSiteDefault(): void
    {
        $stmt = $this->pdo->prepare('UPDATE sections SET email = ? WHERE id = ?');
        $stmt->execute(['meute-a@test.be', $this->sectionId]);

        $mailServiceMock = $this->createMock(MailService::class);
        $mailServiceMock->expects($this->once())->method('send')->with(
            'to@test.be', $this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything(),
            'meute-a@test.be', 'Meute A'
        );

        $service = $this->buildServiceWithMailService($mailServiceMock);

        $email = $service->createDraft(
            'Sujet', '<p>Corps</p>', $this->sectionId, Email::LIST_TYPE_DEFAULT_ACTIVE_MEMBERS, null, null,
            [$this->scoutYearId], null, $this->unrestricted
        );
        $service->moveToTest($email->id, null);

        $service->sendTestEmail($email->id, 'to@test.be');
    }
}
