<?php

declare(strict_types=1);

namespace Tests\Modules\MassMail\Service;

use Core\Badge\MemberBadgeRepository;
use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
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
use Core\ScoutYear\ScoutYearResolver;
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
use Modules\MassMail\Api\MassMailException;
use Modules\MassMail\Service\MassMailService;
use Modules\MassMail\Service\MergeRenderer;
use Modules\MassMail\Service\SenderAuthorization;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\MassMail\MassMailTestHelper;
use Tests\Core\Mail\Template\EmailTemplateRendererFactory;

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
    private ScoutYearResolver $scoutYearResolver;

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
        $this->scoutYearResolver = new ScoutYearResolver(
            new ScoutYearService($this->pdo),
            new SettingService(new SettingRepository($this->pdo)),
            new MemberYearRepository($this->pdo)
        );
        $listService = new MailingListService(
            new MailingListRepository($this->pdo),
            new MemberResolutionRepository($this->pdo, $encryption),
            $sectionService,
            new FunctionRepository($this->pdo),
            null,
            null,
            null,
            null,
            $this->scoutYearResolver
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
            $this->siteMailService(),
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

        [$label, $yearStart, $yearEnd] = DatabaseTestHelper::scoutYear();
        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('{$label}', '{$yearStart}', '{$yearEnd}', 1)");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('LOU', 'Louveteaux', 1)");
        $branchId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO sections (desk_code, age_branch_id, name) VALUES ('LOU01', {$branchId}, 'Meute A')");
        $this->sectionId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO sections (desk_code, age_branch_id, name) VALUES ('LOU02', {$branchId}, 'Meute B')");
        $this->otherSectionId = (int) $this->pdo->lastInsertId();

        $this->unrestricted = new SenderAuthorization(true, [], null);

        // « Anciens » resolves its own reference year rather than reading
        // the submitted one, so pin the public year instead of letting the
        // date fallback make these tests depend on the day they run.
        (new SettingService(new SettingRepository($this->pdo)))->register(
            ScoutYearResolver::SETTING_PUBLIC_YEAR,
            (string) $this->scoutYearId,
            'number',
            'Année scoute courante',
            'Description.'
        );
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
            new FunctionRepository($this->pdo),
            null,
            null,
            null,
            null,
            $this->scoutYearResolver
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

    /**
     * A mail service that answers the one question the service asks it
     * outside of send(): what the site's own From is, for a section that
     * has none. An unconfigured mock answers [] there, which is not a
     * shape that method ever returns.
     */
    private function siteMailService(): MailService
    {
        $mailService = $this->createMock(MailService::class);
        $mailService->method('getDefaultSender')
            ->willReturn(['address' => 'unite@test.be', 'name' => 'Test Unité']);

        return $mailService;
    }

    private function buildMemberEmailService(EncryptionService $encryption, SectionService $sectionService, MemberService $memberService): MemberEmailService
    {
        return new MemberEmailService(
            new MemberEmailRepository($this->pdo, $encryption),
            $this->createMock(MailService::class),
            EmailTemplateRendererFactory::overTestDatabase($this->pdo, $this->createMock(\Twig\Environment::class)),
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

    // ── One journal line per copy ───────────────────────────────────────

    /**
     * The report this was built for: an email to seven people, one copy
     * that never left, and « aucune trace nulle part ». The row refused
     * at freeze time is created straight in `error` and never reaches
     * Task\SendBatchHandler, so before this it passed through no code
     * that wrote anything down — the only record of it was one cell of
     * one column on the tracking page.
     */
    public function testACopyRefusedBeforeItIsEvenAttemptedLeavesAJournalLine(): void
    {
        $this->createMemberWithEmail('valid@test.be');
        $this->createMemberWithEmail(null);

        $email = $this->createDraft();
        $this->service->moveToTest($email->id, null);
        $this->service->startSending($email->id, null);

        $refused = $this->journalEntries('recipient_not_sendable');
        $this->assertCount(1, $refused);
        $this->assertSame('error', $refused[0]['level'], 'The tracking page calls this an error; so must the journal.');
        $this->assertStringContainsString('Adresse invalide', $refused[0]['description']);

        $context = json_decode((string) $refused[0]['context'], true);
        $this->assertSame($email->id, $context['email_id']);
        $this->assertNotNull($context['recipient_id']);
    }

    public function testAnUnsubscribedAddressIsWrittenDownAsWellAsShown(): void
    {
        $this->suppressedAddressRepository->suppress('optout@test.be');
        $audienceId = $this->createAudience([
            ['member_id' => null, 'email' => 'optout@test.be', 'data' => ['Email' => 'optout@test.be']],
        ]);

        $email = $this->createMergeDraft($audienceId);
        $this->service->moveToTest($email->id, null);
        $this->service->startSending($email->id, null);

        $refused = $this->journalEntries('recipient_not_sendable');
        $this->assertCount(1, $refused);
        $this->assertStringContainsString('Adresse désinscrite des emails groupés', $refused[0]['description']);
    }

    /**
     * SECURITY.md §11 — no e-mail address ever appears in a journal
     * entry. This is the rule the whole feature runs closest to: the
     * natural way to make a per-copy line searchable is to write the
     * address into it, and that is precisely what must not happen.
     * `member_id` is the only personal reference allowed, and the
     * address-only recipients carry none at all.
     */
    public function testNoRecipientAddressEverReachesTheJournal(): void
    {
        $this->suppressedAddressRepository->suppress('optout@test.be');
        $audienceId = $this->createAudience([
            ['member_id' => null, 'email' => 'optout@test.be', 'data' => ['Email' => 'optout@test.be']],
        ]);

        $email = $this->createMergeDraft($audienceId);
        $this->service->moveToTest($email->id, null);
        $this->service->startSending($email->id, null);

        $stmt = $this->pdo->query('SELECT description, context FROM event_log');
        $rows = $stmt !== false ? (string) json_encode($stmt->fetchAll(\PDO::FETCH_ASSOC)) : '';
        $this->assertStringNotContainsString('optout@test.be', $rows);
        $this->assertStringNotContainsString('optout', $rows);
    }

    /**
     * `/admin/journal`'s search box matches `description` and nothing
     * else (Core\Journal\JournalRepository::buildFilters()), so an id
     * that lives only in the JSON context is an id nobody can search on.
     * Searching one mailing has to return its whole story — start, every
     * copy, end.
     */
    public function testOneSearchOnTheEmailIdFindsTheWholeStory(): void
    {
        $this->createMemberWithEmail('valid@test.be');
        $this->createMemberWithEmail(null);

        $email = $this->createDraft();
        $this->service->moveToTest($email->id, null);
        $this->service->startSending($email->id, null);

        $stmt = $this->pdo->prepare('SELECT event_type FROM event_log WHERE description LIKE ?');
        $stmt->execute(['%#' . $email->id . '%']);
        $found = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        $this->assertContains('email_sending_started', $found);
        $this->assertContains('recipient_not_sendable', $found);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function journalEntries(string $type): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM event_log WHERE event_type = ? ORDER BY id');
        $stmt->execute([$type]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    // ── How many this would reach, asked before it is sent ──────────────

    public function testTheRecipientCountAnswersWhoRatherThanHowManyAddresses(): void
    {
        // « Lancer l'envoi ? » on its own is a question about an unknown
        // quantity, and 42 versus 400 is the difference between one
        // section and the whole unit.
        $this->createMemberWithEmail('un@test.be');
        $this->createMemberWithEmail('deux@test.be');
        $this->createMemberWithEmail('trois@test.be');

        $email = $this->createDraft();

        $this->assertSame(
            ['count' => 3, 'kind' => 'members'],
            $this->service->estimateRecipientCount($email->id)
        );
    }

    public function testAMemberWithNoUsableAddressIsStillSomebodyTheListDesignates(): void
    {
        // The count answers "who is this going to?". A member the list
        // resolves to is part of that answer whether or not the send will
        // later find an address for them — leaving them out would make the
        // number quietly disagree with the list the manager picked.
        $this->createMemberWithEmail('valide@test.be');
        $this->createMemberWithEmail(null);

        $this->assertSame(2, $this->service->estimateRecipientCount($this->createDraft()->id)['count']);
    }

    public function testAnEmptyListCountsZeroRatherThanFailing(): void
    {
        $this->assertSame(
            ['count' => 0, 'kind' => 'members'],
            $this->service->estimateRecipientCount($this->createDraft()->id)
        );
    }

    public function testTheCountIsReResolvedRatherThanFrozenWithTheEmail(): void
    {
        // The list behind an email is live: a count taken when the dialog
        // was opened is a count from before somebody else edited it.
        $email = $this->createDraft();
        $this->assertSame(0, $this->service->estimateRecipientCount($email->id)['count']);

        $this->createMemberWithEmail('tardif@test.be');

        $this->assertSame(1, $this->service->estimateRecipientCount($email->id)['count']);
    }

    public function testAnUnknownEmailHasNoCount(): void
    {
        $this->expectException(MassMailException::class);
        $this->service->estimateRecipientCount(9999);
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

    /**
     * #244. `suppress()` caught every PDOException — the intent was « the
     * hash is already there », the reach was « anything at all ». A table
     * missing after a half-applied schema, a full or read-only database,
     * a dropped connection: the unsubscribe was lost in silence, and the
     * next campaign wrote to somebody who had asked not to be written to.
     */
    public function testAnUnsubscribeIsNeverLostInSilence(): void
    {
        // Twice is not an error: the mailbox prefetch, the confirmation
        // page and a manual resubmit all land here for one address.
        $this->suppressedAddressRepository->suppress('deux-fois@test.be');
        $this->suppressedAddressRepository->suppress('deux-fois@test.be');
        $this->assertTrue($this->suppressedAddressRepository->isSuppressed('deux-fois@test.be'));

        // Anything else is.
        $this->pdo->exec('DROP TABLE mass_mail_suppressed_addresses');
        $this->expectException(\PDOException::class);
        $this->suppressedAddressRepository->suppress('perdue@test.be');
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

    private function createPastScoutYear(int $yearsBack = -1): int
    {
        [$label, $yearStart, $yearEnd] = DatabaseTestHelper::scoutYear($yearsBack);
        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('{$label}', '{$yearStart}', '{$yearEnd}', 0)");
        return (int) $this->pdo->lastInsertId();
    }

    private function addMemberYear(int $memberId, int $scoutYearId, string $email): void
    {
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted,
                                       email_encrypted, email_blind_index, unit_mail_consent, is_active)
             VALUES (?, ?, ?, ?, ?, ?, 1, 1)'
        );
        $stmt->execute([
            $memberId,
            $scoutYearId,
            $encryption->encrypt('John', 'member_years.first_name'),
            $encryption->encrypt('Doe', 'member_years.last_name'),
            $encryption->encrypt($email, 'member_years.email'),
            $encryption->blindIndex($email, 'email'),
        ]);
    }

    /**
     * The « Anciens » list is the only one whose recipients do not share
     * one scout year: each former member is reached at the address of
     * THEIR OWN last active year, and the recipient row must carry that
     * year — the tracking page looks a profile up by it, and theirs only
     * exists for that year.
     */
    public function testStartSendingTagsEachFormerMemberWithTheirOwnLastActiveYear(): void
    {
        $lastYear = $this->createPastScoutYear(-1);
        $twoYearsAgo = $this->createPastScoutYear(-2);
        $threeYearsAgo = $this->createPastScoutYear(-3);

        $recent = $this->createMemberWithEmail('recent@test.be', scoutYearId: $twoYearsAgo);
        $this->addMemberYear($recent, $lastYear, 'recent@test.be');

        $older = $this->createMemberWithEmail('older@test.be', scoutYearId: $threeYearsAgo);
        $this->addMemberYear($older, $twoYearsAgo, 'older@test.be');

        // Still here: never a former member, whatever their history.
        $stillHere = $this->createMemberWithEmail('encore@test.be', scoutYearId: $twoYearsAgo);
        $this->addMemberYear($stillHere, $this->scoutYearId, 'encore@test.be');

        $email = $this->service->createDraft(
            'Sujet', '<p>Corps</p>', $this->sectionId, Email::LIST_TYPE_DEFAULT_FORMER_MEMBERS, null, null,
            [$this->scoutYearId], null, $this->unrestricted
        );
        $this->service->moveToTest($email->id, null);
        $this->service->startSending($email->id, null);

        $byMember = [];
        foreach ($this->recipientRepository->findByEmailId($email->id) as $recipient) {
            $byMember[(int) $recipient->memberId] = $recipient->scoutYearId;
        }

        $this->assertSame([$recent, $older], array_keys($byMember));
        $this->assertSame($lastYear, $byMember[$recent]);
        $this->assertSame($twoYearsAgo, $byMember[$older]);
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
        // Starts on an allowed list ("Animateurs uniquement") ...
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
        [$label, $yearStart, $yearEnd] = DatabaseTestHelper::scoutYear(1);
        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('{$label}', '{$yearStart}', '{$yearEnd}', 0)");
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

    /**
     * `resolveSenderIdentity()`'s null is an INSTRUCTION to send() («
     * use the site's own configuration »), and a screen cannot print an
     * instruction. The test-mode preview names the sender the recipient
     * will actually read, which means resolving that fallback — once,
     * here, rather than in a template guessing at it.
     */
    public function testTheDisplayedSenderFallsBackToTheSiteConfigurationRatherThanToNothing(): void
    {
        // $this->sectionId has no email of its own (see setUp).
        $displayed = $this->service->resolveDisplayedSender($this->sectionId);

        $this->assertSame('unite@test.be', $displayed['address']);
        // The NAME is still the section's: send() takes it as an
        // override, and a non-null override wins whatever the address did.
        $this->assertSame('Meute A', $displayed['name']);
    }

    public function testTheDisplayedSenderIsTheSectionsOwnAddressWhenItHasOne(): void
    {
        $stmt = $this->pdo->prepare('UPDATE sections SET email = ? WHERE id = ?');
        $stmt->execute(['meute-a@test.be', $this->sectionId]);

        $this->assertSame(
            ['address' => 'meute-a@test.be', 'name' => 'Meute A'],
            $this->service->resolveDisplayedSender($this->sectionId)
        );
    }

    // ── The preview opens on somebody at random ─────────────────────────

    /**
     * Line 1 is the row whose values the author already had in front of
     * them while writing, so it is the one row their variables were
     * unconsciously fitted to — the one row a preview proves nothing
     * about. Asked with no offset, the preview picks somebody.
     *
     * Twenty draws over ten rows: the odds of them all landing on the
     * same row are 10 × (1/10)^20, which is not a flaky test.
     */
    public function testThePreviewOpensOnARowChosenAtRandom(): void
    {
        $rows = [];
        for ($i = 1; $i <= 10; $i++) {
            $rows[] = ['member_id' => null, 'email' => "ligne{$i}@test.be",
                'data' => ['Email' => "ligne{$i}@test.be", 'Prenom' => 'Nom' . $i]];
        }
        $email = $this->createMergeDraft($this->createAudience($rows));

        $seen = [];
        for ($i = 0; $i < 20; $i++) {
            $preview = $this->service->getMergePreview($email->id, null);
            $this->assertGreaterThanOrEqual(0, $preview['offset']);
            $this->assertLessThan(10, $preview['offset']);
            $seen[$preview['offset']] = true;
        }

        $this->assertGreaterThan(1, count($seen), 'A preview that always opens on the same row is not a sample.');
    }

    /**
     * The other half: the arrows still walk the file in order. Random is
     * where the reader LANDS, never where they are kept.
     */
    public function testAnExplicitOffsetIsStillTheRowThatIsShown(): void
    {
        $email = $this->createMergeDraft($this->createAudience([
            ['member_id' => null, 'email' => 'a@test.be', 'data' => ['Email' => 'a@test.be', 'Prenom' => 'Anne']],
            ['member_id' => null, 'email' => 'b@test.be', 'data' => ['Email' => 'b@test.be', 'Prenom' => 'Bruno']],
        ]));

        $this->assertSame(1, $this->service->getMergePreview($email->id, 1)['offset']);
        $this->assertSame(0, $this->service->getMergePreview($email->id, 0)['offset']);
        // Out of range is clamped, as it always was.
        $this->assertSame(1, $this->service->getMergePreview($email->id, 99)['offset']);
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
