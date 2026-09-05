<?php

declare(strict_types=1);

namespace Tests\Modules\Registration\Task;

use Core\Badge\MemberBadgeRepository;
use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailException;
use Core\Mail\MailService;
use Core\ScoutYear\ScoutYearResolver;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Modules\Registration\Service\ReenrollmentCampaignService;
use Modules\Registration\Task\SendReenrollmentEmailsHandler;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Registration\RegistrationTestHelper;

/**
 * The half of the reenrollment campaign that actually writes to families.
 *
 * `Task\ReenrollmentCampaignHandler` decides WHAT is due; this one sends
 * it, in batches, and its own docblock states eight guarantees. None of
 * them was verified: the file was measured at 0 % — nothing in the
 * repository executed a single line of it — while it is the code that
 * mails every family of the unit, four times, on a clock nobody watches.
 *
 * What is pinned here is that list, in the order it matters to a parent:
 *
 * - a family gets ONE message naming all of its children, never one each;
 * - a family is never split across two batches, and never written to twice;
 * - a family who answers between two batches drops out of the reminders;
 * - the opening goes to everybody, the three reminders only to those who
 *   still owe an answer;
 * - one unusable address does not cost the rest of the unit its e-mail;
 * - the journal counts families and names none (ARCHITECTURE.md § 7.9);
 * - a short batch closes the campaign, a full one queues the next with a
 *   cursor whose reference cannot be queued twice.
 *
 * Real collaborators throughout — the recipient grouping, the template
 * renderer and the scheduler are the behaviour under test, not decoration
 * around it. Only the transport is a double, because asserting on what
 * was sent is the whole point.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class SendReenrollmentEmailsHandlerTest extends TestCase
{
    private const BATCH_SIZE = 25;
    private const CAMPAIGN = '2027-05-15';

    private \PDO $pdo;
    private EncryptionService $encryption;
    private SettingService $settingService;
    private TaskContext $context;
    private int $currentYearId;
    private int $sectionId;

    /** @var array<int, array{to: string, subject: string, html: string, text: string}> */
    private array $sent = [];

    /** Addresses the transport refuses, as a broken mailbox would. */
    private array $rejected = [];

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RegistrationTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->currentYearId = RegistrationTestHelper::insertScoutYear(
            $this->pdo,
            '2026-2027',
            '2026-09-01',
            '2027-08-31'
        );
        RegistrationTestHelper::insertScoutYear($this->pdo, '2027-2028', '2027-09-01', '2028-08-31');

        $branchId = RegistrationTestHelper::insertAgeBranch($this->pdo, 'LOUV', 'Louveteaux', 20);
        $stmt = $this->pdo->prepare(
            'INSERT INTO sections (desk_code, age_branch_id, name, is_visible) VALUES (?, ?, ?, 1)'
        );
        $stmt->execute(['LOUV1', $branchId, 'Louveteaux A']);
        $this->sectionId = (int) $this->pdo->lastInsertId();

        $this->settingService = new SettingService(new SettingRepository($this->pdo));
        $this->settingService->register(
            ScoutYearResolver::SETTING_PUBLIC_YEAR,
            (string) $this->currentYearId,
            'text',
            'Année publique',
            'Test.'
        );
        $this->settingService->set(ScoutYearResolver::SETTING_PUBLIC_YEAR, (string) $this->currentYearId);
        $this->settingService->register('site_name', 'Unité test', 'text', 'Nom du site', 'Test.');
        $this->settingService->register('base_url', 'https://exemple.be', 'text', 'URL', 'Test.');

        foreach ([
            ReenrollmentCampaignService::EMAIL_OPENING,
            ReenrollmentCampaignService::EMAIL_REMINDER_1,
            ReenrollmentCampaignService::EMAIL_REMINDER_2,
            ReenrollmentCampaignService::EMAIL_CLOSING,
        ] as $type) {
            $marker = ReenrollmentCampaignService::emailMarker($type);
            $this->settingService->register($marker, '', 'text', $marker, 'Test.', 'registration');
        }

        $this->context = new TaskContext(
            Connection::withPdo($this->pdo),
            $this->encryption,
            $this->recordingMailService(),
            new JournalService(new JournalRepository($this->pdo)),
            $this->settingService,
            new UserAccountRepository($this->pdo, $this->encryption),
            sys_get_temp_dir()
        );
    }

    // ── one message per family, not per child ─────────────────────────

    public function testAFamilyOfThreeReceivesOneMessageNamingAllThree(): void
    {
        $this->createAnime('Alix', 'famille@example.be');
        $this->createAnime('Bo', 'famille@example.be');
        $this->createAnime('Cléo', 'famille@example.be');

        $this->deliver(ReenrollmentCampaignService::EMAIL_OPENING);

        $this->assertCount(1, $this->sent, 'Three messages to one address would read as a mistake, and would be one.');
        $this->assertSame('famille@example.be', $this->sent[0]['to']);
        foreach (['Alix', 'Bo', 'Cléo'] as $name) {
            $this->assertStringContainsString($name, $this->sent[0]['html']);
        }
    }

    /**
     * The names are joined into ONE declared variable rather than looped in
     * the template: a unit that rewords this e-mail substitutes plain
     * strings, and a list would simply vanish from the message.
     */
    public function testTheChildrensNamesArriveAsOneJoinedString(): void
    {
        $this->createAnime('Alix', 'famille@example.be');
        $this->createAnime('Bo', 'famille@example.be');

        $this->deliver(ReenrollmentCampaignService::EMAIL_OPENING);

        $this->assertMatchesRegularExpression(
            '/Alix Dupont, Bo Dupont/',
            $this->sent[0]['html']
        );
    }

    // ── batching ──────────────────────────────────────────────────────

    /**
     * The cursor is the smallest member id of the last FAMILY handled,
     * never a raw member id. With a cursor on the member id, the family
     * sitting on the batch boundary would get its first children in one
     * message and the rest in another.
     */
    public function testAFamilyOnTheBatchBoundaryIsNotSplitInTwo(): void
    {
        for ($i = 1; $i < self::BATCH_SIZE; $i++) {
            $this->createAnime('Seul' . $i, 'seul' . $i . '@example.be');
        }
        // The 25th and last family of the batch, with three children.
        $this->createAnime('Alix', 'grande@example.be');
        $this->createAnime('Bo', 'grande@example.be');
        $this->createAnime('Cléo', 'grande@example.be');

        $this->deliver(ReenrollmentCampaignService::EMAIL_OPENING);

        $this->assertCount(self::BATCH_SIZE, $this->sent);
        $toTheFamily = array_values(array_filter(
            $this->sent,
            static fn (array $mail): bool => $mail['to'] === 'grande@example.be'
        ));
        $this->assertCount(1, $toTheFamily, 'A family must never be written to twice.');
        foreach (['Alix', 'Bo', 'Cléo'] as $name) {
            $this->assertStringContainsString($name, $toTheFamily[0]['html']);
        }
    }

    public function testAFullBatchQueuesTheNextOneWithItsCursorAndLeavesTheCampaignOpen(): void
    {
        for ($i = 1; $i <= self::BATCH_SIZE + 3; $i++) {
            $this->createAnime('Seul' . $i, 'seul' . $i . '@example.be');
        }

        $this->deliver(ReenrollmentCampaignService::EMAIL_OPENING);

        $this->assertCount(self::BATCH_SIZE, $this->sent);
        $this->assertSame('', $this->marker(ReenrollmentCampaignService::EMAIL_OPENING),
            'A campaign with families still to write to is not done.');

        $queued = $this->queued();
        $this->assertCount(1, $queued);
        $payload = json_decode((string) $queued[0]['payload'], true);
        $this->assertGreaterThan(0, $payload['after_key']);
        $this->assertStringContainsString(
            (string) $payload['after_key'],
            (string) $queued[0]['reference'],
            'The reference carries the cursor so the same batch cannot be queued twice.'
        );
    }

    public function testTheSecondBatchStartsWhereTheFirstStoppedAndRepeatsNobody(): void
    {
        for ($i = 1; $i <= self::BATCH_SIZE + 3; $i++) {
            $this->createAnime('Seul' . $i, 'seul' . $i . '@example.be');
        }

        $this->deliver(ReenrollmentCampaignService::EMAIL_OPENING);
        $first = array_column($this->sent, 'to');

        $payload = json_decode((string) $this->queued()[0]['payload'], true);
        $this->sent = [];
        $this->deliver(ReenrollmentCampaignService::EMAIL_OPENING, (int) $payload['after_key']);
        $second = array_column($this->sent, 'to');

        $this->assertCount(3, $second);
        $this->assertSame([], array_intersect($first, $second), 'No family is written to twice.');
        $this->assertCount(
            self::BATCH_SIZE + 3,
            array_unique(array_merge($first, $second)),
            'Between them the two batches cover every family exactly once.'
        );
    }

    public function testAShortBatchClosesTheCampaignAndQueuesNothing(): void
    {
        $this->createAnime('Alix', 'une@example.be');

        $this->deliver(ReenrollmentCampaignService::EMAIL_OPENING);

        $this->assertCount(1, $this->sent);
        $this->assertSame(self::CAMPAIGN, $this->marker(ReenrollmentCampaignService::EMAIL_OPENING));
        $this->assertSame([], $this->queued());
    }

    public function testARunWithNobodyLeftClosesTheCampaign(): void
    {
        $this->deliver(ReenrollmentCampaignService::EMAIL_OPENING);

        $this->assertSame([], $this->sent);
        $this->assertSame(self::CAMPAIGN, $this->marker(ReenrollmentCampaignService::EMAIL_OPENING));
    }

    // ── who is owed what ──────────────────────────────────────────────

    public function testTheOpeningGoesToEverybody(): void
    {
        $alix = $this->createAnime('Alix', 'a@example.be');
        $this->createAnime('Bo', 'b@example.be');
        $this->answerFor($alix);

        $this->deliver(ReenrollmentCampaignService::EMAIL_OPENING);

        $this->assertCount(2, $this->sent, 'The opening announces the campaign; it is owed to everybody.');
    }

    public function testAReminderGoesOnlyToFamiliesWhoStillOweAnAnswer(): void
    {
        $alix = $this->createAnime('Alix', 'a@example.be');
        $this->createAnime('Bo', 'b@example.be');
        $this->answerFor($alix);

        $this->deliver(ReenrollmentCampaignService::EMAIL_REMINDER_1);

        $this->assertCount(1, $this->sent, 'Chasing somebody who has just answered teaches a unit to ignore these e-mails.');
        $this->assertSame('b@example.be', $this->sent[0]['to']);
    }

    public function testAFamilyWhoAnswersBetweenTwoBatchesDropsOutOfTheNext(): void
    {
        for ($i = 1; $i <= self::BATCH_SIZE + 1; $i++) {
            $this->createAnime('Seul' . $i, 'seul' . $i . '@example.be');
        }

        $this->deliver(ReenrollmentCampaignService::EMAIL_REMINDER_1);
        $payload = json_decode((string) $this->queued()[0]['payload'], true);

        // The last family answers before the follow-up batch runs.
        $remaining = $this->memberIdsAfter((int) $payload['after_key']);
        $this->assertNotEmpty($remaining);
        $this->answerFor($remaining[0]);

        $this->sent = [];
        $this->deliver(ReenrollmentCampaignService::EMAIL_REMINDER_1, (int) $payload['after_key']);

        $this->assertSame([], $this->sent);
    }

    /**
     * A family who answered for two of three children is still owed a
     * reminder, and that reminder names only the child who is missing.
     */
    public function testAReminderNamesOnlyTheChildStillMissing(): void
    {
        $alix = $this->createAnime('Alix', 'famille@example.be');
        $this->createAnime('Bo', 'famille@example.be');
        $this->answerFor($alix);

        $this->deliver(ReenrollmentCampaignService::EMAIL_REMINDER_1);

        $this->assertCount(1, $this->sent);
        $this->assertStringContainsString('Bo', $this->sent[0]['html']);
        $this->assertStringNotContainsString('Alix', $this->sent[0]['html']);
    }

    // ── failure, and what must survive it ─────────────────────────────

    public function testOneUnusableAddressDoesNotCostTheRestOfTheUnitItsEmail(): void
    {
        $this->createAnime('Alix', 'cassee@example.be');
        $this->createAnime('Bo', 'b@example.be');
        $this->createAnime('Cléo', 'c@example.be');
        $this->rejected = ['cassee@example.be'];

        $this->deliver(ReenrollmentCampaignService::EMAIL_OPENING);

        $this->assertSame(['b@example.be', 'c@example.be'], array_column($this->sent, 'to'));
        $this->assertSame(
            self::CAMPAIGN,
            $this->marker(ReenrollmentCampaignService::EMAIL_OPENING),
            'A bad address is not a reason to run the whole campaign again.'
        );
    }

    // ── the journal ───────────────────────────────────────────────────

    public function testTheJournalCountsTheFamiliesItWroteTo(): void
    {
        $this->createAnime('Alix', 'a@example.be');
        $this->createAnime('Bo', 'b@example.be');

        $this->deliver(ReenrollmentCampaignService::EMAIL_OPENING);

        $entry = $this->lastJournalEntry();
        $this->assertSame('reenrollment_emails_sent', $entry['event_type']);
        $this->assertStringContainsString('"families":2', (string) $entry['context']);
    }

    public function testTheJournalNamesNoFamilyAndCarriesNoAddress(): void
    {
        $this->createAnime('Alix', 'alix@example.be');

        $this->deliver(ReenrollmentCampaignService::EMAIL_OPENING);

        $entry = $this->lastJournalEntry();
        $written = strtolower((string) $entry['context'] . ' ' . (string) $entry['description']);
        $this->assertStringNotContainsString('alix', $written);
        $this->assertStringNotContainsString('@example.be', $written);
        $this->assertStringNotContainsString('dupont', $written);
    }

    // ── the payload guard ─────────────────────────────────────────────

    /**
     * @return array<int, array{0: array<string, mixed>}>
     */
    public static function incompletePayloads(): array
    {
        return [
            'no type' => [['campaign' => self::CAMPAIGN]],
            'no campaign' => [['type' => ReenrollmentCampaignService::EMAIL_OPENING]],
            'nothing at all' => [[]],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('incompletePayloads')]
    public function testAnIncompletePayloadSendsNothing(array $payload): void
    {
        $this->createAnime('Alix', 'a@example.be');

        (new SendReenrollmentEmailsHandler())->handle($payload, $this->context);

        $this->assertSame([], $this->sent);
        $this->assertSame([], $this->queued());
    }

    // ── harness ───────────────────────────────────────────────────────

    private function deliver(string $type, int $afterKey = 0): void
    {
        (new SendReenrollmentEmailsHandler())->handle(
            ['type' => $type, 'campaign' => self::CAMPAIGN, 'after_key' => $afterKey],
            $this->context
        );
    }

    private function marker(string $type): string
    {
        return (string) $this->settingService->get(
            ReenrollmentCampaignService::emailMarker($type),
            'registration',
            ''
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function queued(): array
    {
        return $this->pdo
            ->query("SELECT payload, reference FROM scheduled_actions WHERE task_key = 'send_reenrollment_emails'")
            ->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * @return array<string, mixed>
     */
    private function lastJournalEntry(): array
    {
        $row = $this->pdo
            ->query('SELECT event_type, description, context FROM event_log ORDER BY id DESC LIMIT 1')
            ->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $row : [];
    }

    /**
     * @return array<int, int>
     */
    private function memberIdsAfter(int $afterKey): array
    {
        $stmt = $this->pdo->prepare('SELECT member_id FROM member_years WHERE member_id > ? ORDER BY member_id ASC');
        $stmt->execute([$afterKey]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * The transport is the one double here: what was sent, to whom, is
     * exactly what these scenarios are about.
     */
    private function recordingMailService(): MailService
    {
        // A stub, not a mock: what matters is what it RECORDS, and a mock
        // without configured expectations is a notice in this suite.
        $mail = $this->createStub(MailService::class);
        $mail->method('send')->willReturnCallback(
            function (string $to, string $subject, string $html, string $text): bool {
                if (in_array($to, $this->rejected, true)) {
                    throw new MailException('Invalid address: (To): ' . $to);
                }

                $this->sent[] = ['to' => $to, 'subject' => $subject, 'html' => $html, 'text' => $text];

                return true;
            }
        );

        return $mail;
    }

    /**
     * One animé of the current year, in a section, with a family address —
     * the shape `Service\ReenrollmentRecipientService` groups on.
     */
    private function createAnime(string $firstName, string $email): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO members (desk_id) VALUES (?)');
        $stmt->execute(['DESK_' . $firstName . '_' . uniqid()]);
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years
                (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, birth_date_encrypted,
                 gender_encrypted, email_encrypted, email_blind_index, leaving, scout_year_offset, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 1)'
        );
        $stmt->execute([
            $memberId,
            $this->currentYearId,
            $this->encryption->encrypt($firstName, 'member_years.first_name'),
            $this->encryption->encrypt('Dupont', 'member_years.last_name'),
            $this->encryption->encrypt('2017-06-01', 'member_years.birth_date'),
            $this->encryption->encrypt('M', 'member_years.gender'),
            $this->encryption->encrypt($email, 'member_years.email'),
            $this->encryption->blindIndex($email, 'email'),
        ]);
        $memberYearId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec(
            "INSERT OR IGNORE INTO functions (desk_code, label, role) VALUES ('identified', 'Fn', 'identified')"
        );
        $functionId = (int) $this->pdo->query("SELECT id FROM functions WHERE desk_code = 'identified'")->fetchColumn();
        $branchId = (int) $this->pdo
            ->query('SELECT age_branch_id FROM sections WHERE id = ' . $this->sectionId)
            ->fetchColumn();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, section_id, age_branch_id, is_main_function)
             VALUES (?, ?, ?, ?, 1)'
        );
        $stmt->execute([$memberYearId, $functionId, $this->sectionId, $branchId]);

        return $memberId;
    }

    private function answerFor(int $memberId): void
    {
        $targetYearId = (new ScoutYearService($this->pdo))->ensureYear('2027-2028');
        (new \Modules\Registration\Repository\ReenrollmentRepository($this->pdo, $this->encryption))
            ->saveAnswer($memberId, $targetYearId, 'returning', null, null, null, []);
    }
}
