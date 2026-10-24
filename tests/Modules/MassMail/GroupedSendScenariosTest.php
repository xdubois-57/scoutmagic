<?php

declare(strict_types=1);

namespace Tests\Modules\MassMail;

use Core\Badge\MemberBadgeRepository;
use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\File\FileRepository;
use Core\Http\Request;
use Core\Import\FunctionRepository;
use Core\Import\ImportJournalRepository;
use Core\Import\MemberYearRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailException;
use Core\Mail\MailService;
use Core\Member\MemberEmail;
use Core\Member\MemberEmailRepository;
use Core\Member\MemberEmailService;
use Core\Member\MemberService;
use Core\Member\Repository\MemberProfileRepository;
use Core\Member\Repository\SectionRepository;
use Core\Member\SectionService;
use Core\Notification\NotificationPreferenceRepository;
use Core\Notification\NotificationRepository;
use Core\Notification\NotificationService;
use Core\Notification\PushSubscriptionRepository;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\CapabilityToken;
use Core\Security\EncryptionService;
use Core\Security\HtmlSanitizer;
use Core\Security\UserAccountRepository;
use Minishlink\WebPush\WebPush;
use Modules\MassMail\Controller\UnsubscribeController;
use Modules\MassMail\Repository\AudienceRepository;
use Modules\MassMail\Repository\ContactRepository;
use Modules\MassMail\Repository\Delivery;
use Modules\MassMail\Repository\DeliveryRepository;
use Modules\MassMail\Repository\Email;
use Modules\MassMail\Repository\EmailAttachmentRepository;
use Modules\MassMail\Repository\EmailRepository;
use Modules\MassMail\Repository\ListBlockRepository;
use Modules\MassMail\Repository\MailingListRepository;
use Modules\MassMail\Repository\MemberResolutionRepository;
use Modules\MassMail\Repository\NotifiedAccountRepository;
use Modules\MassMail\Repository\SuppressedAddressRepository;
use Modules\MassMail\Repository\TargetRepository;
use Modules\MassMail\Service\ContactGroupImportService;
use Modules\MassMail\Service\ListBlockService;
use Modules\MassMail\Service\MailingListService;
use Modules\MassMail\Service\MassMailQueryService;
use Modules\MassMail\Service\MassMailService;
use Modules\MassMail\Service\MergeRenderer;
use Modules\MassMail\Service\SenderAuthorization;
use Modules\MassMail\Service\TargetEmailLink;
use Modules\MassMail\Task\SendBatchHandler;
use Modules\Registration\Api\ExternalMailingListProvider;
use PHPUnit\Framework\TestCase;
use Tests\Core\Mail\Template\EmailTemplateRendererFactory;
use Tests\DatabaseTestHelper;
use Twig\Environment;

/**
 * Issue #999, end to end: every line of the issue's validation table (§5)
 * and the extra cases of the roadmap's lot B, each run through the real
 * freeze (Service\MassMailService::startSending()), the real batch
 * (Task\SendBatchHandler, run until nothing is pending) and the real
 * history (Service\MassMailQueryService) — and counted three ways, apart:
 *
 * - **messages**: what the mail service was asked to send, address by
 *   address;
 * - **history**: the entries on each member's page;
 * - **notifications**: the « Nouvel email » rows of each account.
 *
 * Accounts are created for the addresses a test names, so « 1 par compte
 * concerné » is counted on real accounts.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
final class GroupedSendScenariosTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private MassMailService $service;
    private MailingListService $listService;
    private ListBlockService $blockService;
    private ContactGroupImportService $contactGroups;
    private MassMailQueryService $history;
    private DeliveryRepository $deliveries;
    private TargetRepository $targets;
    private AudienceRepository $audiences;
    private SuppressedAddressRepository $suppressed;
    private UserAccountRepository $accounts;
    private MemberEmailService $memberEmailService;
    private MemberService $memberService;
    private ?ExternalMailingListProvider $externalList = null;
    private int $scoutYearId;
    private int $sectionId;
    private int $chiefFunction;
    private int $otherFunction;

    /** @var list<string> every address the mail service was asked to send to */
    private array $sent = [];
    /** @var array<string, true> addresses the transport refuses */
    private array $failing = [];

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        MassMailTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->accounts = new UserAccountRepository($this->pdo, $this->encryption);

        [$label, $yearStart, $yearEnd] = DatabaseTestHelper::scoutYear();
        $this->pdo->exec(
            "INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('{$label}', '{$yearStart}', '{$yearEnd}', 1)"
        );
        $this->scoutYearId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('LOU', 'Louveteaux', 1)");
        $branchId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO sections (desk_code, age_branch_id, name) VALUES ('LOU01', {$branchId}, 'Meute')");
        $this->sectionId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO functions (desk_code, label, role) VALUES ('CHEF', 'Chef', 'chief')");
        $this->chiefFunction = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO functions (desk_code, label, role) VALUES ('ANIM', 'Animé', 'identified')");
        $this->otherFunction = (int) $this->pdo->lastInsertId();

        // « Anciens » reads the public year; pin it.
        (new SettingService(new SettingRepository($this->pdo)))->register(
            ScoutYearResolver::SETTING_PUBLIC_YEAR,
            (string) $this->scoutYearId,
            'number',
            'Année scoute courante',
            'Description.'
        );

        $this->build();
    }

    /** (Re)builds the services — again once a test installs an external list. */
    private function build(): void
    {
        $connection = Connection::withPdo($this->pdo);
        $sectionService = new SectionService(
            new SectionRepository($connection),
            new MemberProfileRepository($connection, $this->encryption, new MemberBadgeRepository($this->pdo))
        );
        $this->memberService = new MemberService(
            new MemberYearRepository($this->pdo),
            new MemberProfileRepository($connection, $this->encryption)
        );
        $this->memberEmailService = new MemberEmailService(
            new MemberEmailRepository($this->pdo, $this->encryption),
            $this->createStub(MailService::class),
            EmailTemplateRendererFactory::overTestDatabase($this->pdo, $this->createStub(Environment::class)),
            new JournalService(new JournalRepository($this->pdo)),
            $sectionService,
            $this->memberService,
            new ScoutYearService($this->pdo),
            'https://example.test',
            'Test Unité'
        );

        $contactRepository = new ContactRepository($this->pdo, $this->encryption);
        $blockRepository = new ListBlockRepository($this->pdo);
        $listRepository = new MailingListRepository($this->pdo);
        $this->suppressed = new SuppressedAddressRepository($this->pdo);
        $this->blockService = new ListBlockService(
            $blockRepository,
            $contactRepository,
            $listRepository,
            $this->createStub(JournalService::class)
        );
        $this->contactGroups = new ContactGroupImportService(
            $blockRepository,
            $contactRepository,
            $this->blockService,
            new SettingService(new SettingRepository($this->pdo)),
            $this->createStub(JournalService::class),
            $this->suppressed
        );
        $resolver = new ScoutYearResolver(
            new ScoutYearService($this->pdo),
            new SettingService(new SettingRepository($this->pdo)),
            new MemberYearRepository($this->pdo)
        );
        $this->listService = new MailingListService(
            $listRepository,
            new MemberResolutionRepository($this->pdo, $this->encryption),
            $sectionService,
            new FunctionRepository($this->pdo),
            null,
            $blockRepository,
            $contactRepository,
            $this->externalList,
            null,
            $resolver,
            new ScoutYearService($this->pdo),
            new SettingService(new SettingRepository($this->pdo)),
            $this->memberService,
            $this->memberEmailService,
            $this->suppressed
        );

        $this->deliveries = new DeliveryRepository($this->pdo, $this->encryption);
        $this->targets = new TargetRepository($this->pdo);
        $this->audiences = new AudienceRepository($this->pdo, $this->encryption);
        $this->service = new MassMailService(
            new EmailRepository($this->pdo),
            $this->deliveries,
            $this->targets,
            new EmailAttachmentRepository($this->pdo),
            new FileRepository($this->pdo),
            $this->listService,
            $this->memberService,
            $this->memberEmailService,
            $sectionService,
            $this->createStub(MailService::class),
            new SchedulerService(new SchedulerRepository($this->pdo)),
            new JournalService(new JournalRepository($this->pdo)),
            new HtmlSanitizer(),
            new ScoutYearService($this->pdo),
            new ImportJournalRepository($this->pdo),
            sys_get_temp_dir(),
            $this->audiences,
            new MemberResolutionRepository($this->pdo, $this->encryption),
            $this->suppressed,
            new MergeRenderer()
        );
        $this->history = new MassMailQueryService($this->targets, $this->audiences, new MergeRenderer());
    }

    // ── The validation table, classic lists ─────────────────────────────

    /** « 1 animé, 1 adresse » — 1 / 1 entrée / 1 par compte. */
    public function testOneMemberOneAddress(): void
    {
        $alice = $this->member('Alice', 'alice@x.be');
        $account = $this->account('alice@x.be');

        $this->send($this->activeMembersEmail());

        $this->assertSame(['alice@x.be'], $this->sent);
        $this->assertCount(1, $this->entries($alice));
        $this->assertSame(1, $this->notificationsOf($account));
    }

    /** « 1 animé, 3 adresses » — 3, one per address / 1 entrée / 1 par compte. */
    public function testOneMemberThreeAddresses(): void
    {
        $alice = $this->member('Alice', 'alice@x.be');
        $this->secondary($alice, 'papa@x.be');
        $this->secondary($alice, 'maman@x.be');
        $alicesAccount = $this->account('alice@x.be');
        $papasAccount = $this->account('papa@x.be');

        $this->send($this->activeMembersEmail());

        $this->assertEqualsCanonicalizing(['alice@x.be', 'papa@x.be', 'maman@x.be'], $this->sent);
        $this->assertCount(1, $this->entries($alice));
        $this->assertSame(1, $this->notificationsOf($alicesAccount));
        $this->assertSame(1, $this->notificationsOf($papasAccount));
    }

    /** « 3 animés ciblés, même adresse familiale » — 1 / 1 entrée sur chaque fiche / 1 pour le compte. */
    public function testThreeTargetsOnOneFamilyAddress(): void
    {
        $children = [
            $this->member('Alice', 'famille@x.be'),
            $this->member('Bob', 'famille@x.be'),
            $this->member('Charlie', 'Famille@X.be'),
        ];
        $account = $this->account('famille@x.be');

        $this->send($this->activeMembersEmail());

        $this->assertCount(1, $this->sent);
        foreach ($children as $child) {
            $this->assertCount(1, $this->entries($child));
        }
        $this->assertSame(1, $this->notificationsOf($account));
    }

    /** « 2 animés, 2 adresses propres + 1 commune » — 3 / 1 entrée par fiche / pas de doublon. */
    public function testTwoTargetsWithOwnAddressesAndOneInCommon(): void
    {
        $alice = $this->member('Alice', 'alice@x.be');
        $bob = $this->member('Bob', 'bob@x.be');
        $this->secondary($alice, 'famille@x.be');
        $this->secondary($bob, 'famille@x.be');
        $family = $this->account('famille@x.be');
        $alicesAccount = $this->account('alice@x.be');

        $this->send($this->activeMembersEmail());

        $this->assertEqualsCanonicalizing(['alice@x.be', 'bob@x.be', 'famille@x.be'], $this->sent);
        $this->assertCount(1, $this->entries($alice));
        $this->assertCount(1, $this->entries($bob));
        $this->assertSame(1, $this->notificationsOf($family));
        $this->assertSame(1, $this->notificationsOf($alicesAccount));
    }

    /** « Alice ciblée, Bob non ciblé, adresse partagée » — 1 / Alice seulement / 1 pour le compte. */
    public function testSharingAnAddressDoesNotMakeSomebodyATarget(): void
    {
        $alice = $this->member('Alice', 'famille@x.be');
        $bob = $this->member('Bob', 'famille@x.be', active: false);
        $account = $this->account('famille@x.be');

        $this->send($this->activeMembersEmail());

        $this->assertSame(['famille@x.be'], $this->sent);
        $this->assertCount(1, $this->entries($alice));
        $this->assertSame([], $this->entries($bob));
        $this->assertSame(1, $this->notificationsOf($account));
    }

    /**
     * « même animé présent plusieurs fois dans les critères » — 1 par
     * adresse / 1 entrée / pas de doublon; and the lot's extra case, two
     * member groups designating the same member.
     */
    public function testAMemberBroughtByTwoMemberGroupsIsOneTarget(): void
    {
        $alice = $this->member('Alice', 'alice@x.be');
        $this->secondary($alice, 'papa@x.be');
        $account = $this->account('papa@x.be');
        $listId = $this->listService->createCustomList('Deux fois', 'Deux groupes.', null)->id;
        $this->blockService->createMemberGroup($listId, [$this->chiefFunction], [], [], null);
        $this->blockService->createMemberGroup($listId, [], [$this->sectionId], [], null);

        $email = $this->send($this->customListEmail($listId));

        $this->assertEqualsCanonicalizing(['alice@x.be', 'papa@x.be'], $this->sent);
        $this->assertCount(1, $this->targets->findByEmailId($email->id));
        $this->assertCount(1, $this->entries($alice));
        $this->assertSame(1, $this->notificationsOf($account));
    }

    /** « 2 campagnes séparées, mêmes destinataires et objet » — 2 par adresse / 2 entrées / 2 par compte. */
    public function testTwoSeparateCampaignsAreTwoOfEverything(): void
    {
        $alice = $this->member('Alice', 'alice@x.be');
        $account = $this->account('alice@x.be');

        $this->send($this->activeMembersEmail('Même objet'));
        $this->send($this->activeMembersEmail('Même objet'));

        $this->assertSame(['alice@x.be', 'alice@x.be'], $this->sent);
        $this->assertCount(2, $this->entries($alice));
        $this->assertSame(2, $this->notificationsOf($account));
    }

    /** « animé ciblé sans aucune adresse autorisée » — 0 / 1 entrée « Non envoyée » / aucune. */
    public function testATargetWithoutAnyAddressIsNotSentAndNotifiesNobody(): void
    {
        $alice = $this->member('Alice', null);

        $email = $this->send($this->activeMembersEmail());

        $this->assertSame([], $this->sent);
        $entries = $this->entries($alice);
        $this->assertCount(1, $entries);
        $this->assertTrue($entries[0]['not_sent']);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn());
        $this->assertSame(Email::STATUS_SENT, $this->service->findById($email->id)?->status, 'nothing pending: over');
    }

    /** Every message in error is « Non envoyée » too — and notifies nobody. */
    public function testATargetWhoseEveryMessageFailedIsNotSent(): void
    {
        $alice = $this->member('Alice', 'alice@x.be');
        $account = $this->account('alice@x.be');
        $this->failing['alice@x.be'] = true;

        $this->send($this->activeMembersEmail());

        $this->assertTrue($this->entries($alice)[0]['not_sent']);
        $this->assertSame(0, $this->notificationsOf($account));
    }

    // ── The validation table, mail merge ────────────────────────────────

    /** « 1 ligne Tiers avec 3 adresses » — 3 messages / 1 entrée / 1 par compte. */
    public function testOneTiersLineWithThreeAddresses(): void
    {
        $alice = $this->member('Alice', 'alice@x.be');
        $this->secondary($alice, 'papa@x.be');
        $this->secondary($alice, 'maman@x.be');
        $account = $this->account('papa@x.be');

        $this->send($this->mergeEmail([[$alice, null, 'Alice']]));

        $this->assertEqualsCanonicalizing(['alice@x.be', 'papa@x.be', 'maman@x.be'], $this->sent);
        $this->assertCount(1, $this->entries($alice));
        $this->assertSame(1, $this->notificationsOf($account));
    }

    /**
     * « 3 lignes Tiers (3 enfants), même adresse familiale » — 3 messages
     * à cette adresse / 1 entrée par enfant / 3 pour le compte; and « Rappel
     * Finances : famille de 2 enfants » the same way, through rows a module
     * drafts with a member_id and no address.
     */
    public function testThreeTiersLinesOnOneFamilyAddressAreThreeOfEverything(): void
    {
        $children = [
            $this->member('Alice', 'famille@x.be'),
            $this->member('Bob', 'famille@x.be'),
            $this->member('Charlie', 'famille@x.be'),
        ];
        $account = $this->account('famille@x.be');

        $this->send($this->mergeEmail(array_map(fn(int $child) => [$child, null, 'X'], $children)));

        $this->assertSame(['famille@x.be', 'famille@x.be', 'famille@x.be'], $this->sent);
        foreach ($children as $child) {
            $this->assertCount(1, $this->entries($child));
        }
        $this->assertSame(3, $this->notificationsOf($account));
    }

    /** « 2 lignes pour le même Tiers » — 2 messages par adresse / 2 entrées / 2 par compte. */
    public function testTwoLinesForTheSameTiers(): void
    {
        $alice = $this->member('Alice', 'alice@x.be');
        $this->secondary($alice, 'papa@x.be');
        $account = $this->account('papa@x.be');

        $this->send($this->mergeEmail([[$alice, null, 'Camp'], [$alice, null, 'Cotisation']]));

        $this->assertEqualsCanonicalizing(['alice@x.be', 'alice@x.be', 'papa@x.be', 'papa@x.be'], $this->sent);
        $this->assertCount(2, $this->entries($alice));
        $this->assertSame(2, $this->notificationsOf($account));
    }

    /**
     * « 1 ligne sans Tiers avec 2 adresses » — 2 messages / aucune fiche /
     * aucune; even when one of them is a member's address with an account.
     * The cell is split on `,` as well as `;`, and an address written twice
     * in it gets one message.
     */
    public function testALineWithoutTiersHasNoEntryAndNotifiesNobody(): void
    {
        $alice = $this->member('Alice', 'alice@x.be');
        $account = $this->account('alice@x.be');

        $this->send($this->mergeEmail([[null, 'alice@x.be, autre@x.be; ALICE@x.be', 'Fichier']]));

        $this->assertEqualsCanonicalizing(['alice@x.be', 'autre@x.be'], $this->sent);
        $this->assertSame([], $this->entries($alice));
        $this->assertSame(0, $this->notificationsOf($account));
    }

    /** « ligne Tiers avec une adresse dans la colonne Email » — the member's addresses only. */
    public function testTheEmailColumnBesideATiersIsIgnored(): void
    {
        $alice = $this->member('Alice', 'alice@x.be');
        $account = $this->account('alice@x.be');

        $this->send($this->mergeEmail([[$alice, 'ailleurs@x.be', 'Alice']]));

        $this->assertSame(['alice@x.be'], $this->sent);
        $this->assertCount(1, $this->entries($alice));
        $this->assertSame(1, $this->notificationsOf($account));
    }

    /**
     * « Nouvelle adresse secondaire confirmée après une communication » —
     * aucun envoi rétroactif / historique accessible / aucune notification
     * rétroactive.
     */
    public function testAnAddressAddedAfterwardsGetsNothingButSeesTheHistory(): void
    {
        $alice = $this->member('Alice', 'alice@x.be');
        $this->send($this->activeMembersEmail());
        $this->sent = [];

        $this->secondary($alice, 'nouvelle@x.be');
        $account = $this->account('nouvelle@x.be');
        $this->drain();

        $this->assertSame([], $this->sent);
        $this->assertSame(0, $this->notificationsOf($account));
        $memberYearId = (new MemberYearRepository($this->pdo))->findByMemberAndYear($alice, $this->scoutYearId)['id'] ?? 0;
        // The page is open to the new address (a confirmed secondary one of
        // the member), and the entry is on it: nothing to resend for that.
        $withSecondaries = new MemberService(
            new MemberYearRepository($this->pdo),
            new MemberProfileRepository(Connection::withPdo($this->pdo), $this->encryption),
            null,
            new MemberEmailRepository($this->pdo, $this->encryption)
        );
        $this->assertTrue($withSecondaries->canAccess('nouvelle@x.be', (int) $memberYearId, 'identified'));
        $this->assertCount(1, $this->entries($alice));
    }

    // ── The lot's extra cases ───────────────────────────────────────────

    /**
     * A contact equal to the address of a targeted member and of a member
     * the list does not target: ONE message, concerning the targeted one
     * only.
     */
    public function testAContactOnATargetedAndAnUntargetedMembersAddress(): void
    {
        $alice = $this->member('Alice', 'famille@x.be', function: $this->chiefFunction);
        $bob = $this->member('Bob', 'famille@x.be', function: $this->otherFunction);
        $account = $this->account('famille@x.be');
        $listId = $this->listService->createCustomList('Chefs et contacts', 'Les chefs.', null)->id;
        $this->blockService->createMemberGroup($listId, [$this->chiefFunction], [], [], null);
        $this->contactGroups->createGroup($listId, 'Famille', [['name' => null, 'email' => 'FAMILLE@x.be']], null);

        $email = $this->send($this->customListEmail($listId));

        $this->assertSame(['famille@x.be'], $this->sent);
        $this->assertCount(1, $this->targets->findByEmailId($email->id));
        $this->assertCount(1, $this->entries($alice));
        $this->assertSame([], $this->entries($bob));
        $this->assertSame(1, $this->notificationsOf($account));
    }

    /** « Anciens »: two former members on one address — one message, two targets, each with their own year. */
    public function testTwoFormerMembersOnOneAddress(): void
    {
        $lastYear = $this->pastYear(-1);
        $twoYearsAgo = $this->pastYear(-2);
        $threeYearsAgo = $this->pastYear(-3);
        $first = $this->member('Alice', 'famille@x.be', year: $twoYearsAgo);
        $this->memberYear($first, $lastYear, 'famille@x.be', 'Alice');
        $second = $this->member('Bob', 'famille@x.be', year: $threeYearsAgo);
        $this->memberYear($second, $twoYearsAgo, 'famille@x.be', 'Bob');

        $email = $this->send($this->email(Email::LIST_TYPE_DEFAULT_FORMER_MEMBERS));

        $this->assertSame(['famille@x.be'], $this->sent);
        $years = [];
        foreach ($this->targets->findByEmailId($email->id) as $target) {
            $years[$target->memberId] = $target->scoutYearId;
        }
        ksort($years);
        $this->assertSame([$first => $lastYear, $second => $twoYearsAgo], $years);
        $this->assertCount(1, $this->entries($first));
        $this->assertCount(1, $this->entries($second));
    }

    private function pastYear(int $offset): int
    {
        [$label, $start, $end] = DatabaseTestHelper::scoutYear($offset);
        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('{$label}', '{$start}', '{$end}', 0)");

        return (int) $this->pdo->lastInsertId();
    }

    /** The registration module's external list: two children on one address — one message, two targets. */
    public function testTheExternalRegistrationList(): void
    {
        $alice = $this->member('Alice', 'famille@x.be');
        $bob = $this->member('Bob', 'famille@x.be');
        $yearId = $this->scoutYearId;
        $this->externalList = new class ([$alice, $bob], $yearId) implements ExternalMailingListProvider {
            /** @param int[] $members */
            public function __construct(private array $members, private int $yearId)
            {
            }

            public function describeMailingList(): array
            {
                return ['label' => 'Inscrits', 'description' => 'Les inscrits.'];
            }

            public function resolveMailingListMembers(): array
            {
                return array_map(fn(int $id) => ['member_id' => $id, 'email' => 'famille@x.be'], $this->members);
            }

            public function targetScoutYearId(): int
            {
                return $this->yearId;
            }
        };
        $this->build();

        $email = $this->send($this->email(Email::LIST_TYPE_EXTERNAL));

        $this->assertSame(['famille@x.be'], $this->sent);
        $this->assertCount(2, $this->targets->findByEmailId($email->id));
        $this->assertCount(1, $this->entries($alice));
        $this->assertCount(1, $this->entries($bob));
    }

    /**
     * Several years: a member active in both is ONE target, with the most
     * recent year; two members of different years on one address are one
     * message.
     */
    public function testSeveralYears(): void
    {
        $previous = $this->pastYear(-1);
        $both = $this->member('Alice', 'alice@x.be');
        $this->memberYear($both, $previous, 'alice@x.be', 'Alice');
        $this->member('Bob', 'Alice@x.be', year: $previous);

        $email = $this->send($this->email(Email::LIST_TYPE_DEFAULT_ACTIVE_MEMBERS, [$this->scoutYearId, $previous]));

        $this->assertSame(['alice@x.be'], array_map('strtolower', $this->sent));
        $years = [];
        foreach ($this->targets->findByEmailId($email->id) as $target) {
            $years[$target->memberId] = $target->scoutYearId;
        }
        $this->assertCount(2, $years);
        $this->assertSame($this->scoutYearId, $years[$both]);
    }

    /** An unsubscribed address is left out of a member's addresses (§2.2). */
    public function testASuppressedAddressIsExcludedFromAMember(): void
    {
        $alice = $this->member('Alice', 'alice@x.be');
        $this->secondary($alice, 'papa@x.be');
        $this->suppressed->suppress('PAPA@x.be');

        $this->send($this->activeMembersEmail());

        $this->assertSame(['alice@x.be'], $this->sent);
        $this->assertCount(1, $this->entries($alice));
    }

    /** « Renvoyer » sends the message again and notifies nobody again. */
    public function testAResendDoesNotNotifyAgain(): void
    {
        $this->member('Alice', 'alice@x.be');
        $account = $this->account('alice@x.be');
        $email = $this->send($this->activeMembersEmail());

        $this->service->resendDelivery($this->deliveries->findByEmailId($email->id)[0]->id, null);
        $this->drain();

        $this->assertSame(['alice@x.be', 'alice@x.be'], $this->sent);
        $this->assertSame(1, $this->notificationsOf($account));
        $this->assertSame(Email::STATUS_SENT, $this->service->findById($email->id)?->status);
    }

    /**
     * Two messages of one communication whose addresses lead to the same
     * account — a guarantee held at the batch, whatever produced them: one
     * notification.
     */
    public function testTwoAddressesOfOneAccountInOneCommunicationAreOneNotification(): void
    {
        $alice = $this->member('Alice', 'papa@x.be');
        $bob = $this->member('Bob', 'papa@x.be');
        $account = $this->account('papa@x.be');
        $email = $this->activeMembersEmail();
        $this->pdo->exec("UPDATE mass_mail_emails SET status = 'sending' WHERE id = {$email->id}");
        foreach ([[$alice, 'papa@x.be'], [$bob, 'PAPA@x.be']] as [$member, $address]) {
            $target = $this->targets->create($email->id, $member, $this->scoutYearId);
            $this->deliveries->linkTargets($this->deliveries->create($email->id, $address), [$target]);
        }

        $this->drain();

        $this->assertCount(2, $this->sent);
        $this->assertSame(1, $this->notificationsOf($account));
        $this->assertSame(1, (new NotifiedAccountRepository($this->pdo))->countForEmail($email->id));
    }

    /**
     * The notification leads to the entry of a target the account may see;
     * a target with no member_years row (a Tiers without a profile) has no
     * entry to lead to: no link, and the plain subject.
     */
    public function testANotificationLinksToTheEntryOrToNothingWithoutAMemberYear(): void
    {
        $alice = $this->member('Alice', 'alice@x.be');
        $aliceAccount = $this->account('alice@x.be');
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('SANS_FICHE')");
        $noProfile = (int) $this->pdo->lastInsertId();
        $this->secondary($noProfile, 'orphelin@x.be');
        $orphanAccount = $this->account('orphelin@x.be');

        $email = $this->send($this->mergeEmail([[$alice, null, 'A'], [$noProfile, null, 'B']], 'Infos camp'));

        $targets = [];
        foreach ($this->targets->findByEmailId($email->id) as $target) {
            $targets[$target->memberId] = $target;
        }
        $memberYearId = (int) ((new MemberYearRepository($this->pdo))->findByMemberAndYear($alice, $this->scoutYearId)['id'] ?? 0);
        $notifications = new NotificationRepository($this->pdo, $this->encryption);
        $this->assertSame(
            TargetEmailLink::url($memberYearId, $targets[$alice]->id),
            $notifications->findByUserAccountId($aliceAccount)[0]->url
        );
        $this->assertNull($targets[$noProfile]->scoutYearId);
        $orphan = $notifications->findByUserAccountId($orphanAccount);
        $this->assertCount(1, $orphan);
        $this->assertNull($orphan[0]->url);
        $this->assertSame('Infos camp', $orphan[0]->body);
    }

    /**
     * The unsubscribe stays per MESSAGE: each one has its own token, one
     * message's token opens nothing on another, the one-click POST works,
     * and a wrong token answers the « invalid » page and does nothing.
     */
    public function testTheUnsubscribeIsPerMessage(): void
    {
        $alice = $this->member('Alice', 'alice@x.be');
        $this->secondary($alice, 'papa@x.be');
        $email = $this->send($this->activeMembersEmail());
        [$first, $second] = $this->deliveries->findByEmailId($email->id);
        $tokens = [];
        foreach ([$first, $second] as $delivery) {
            $tokens[$delivery->id] = CapabilityToken::generate();
            $this->deliveries->setUnsubscribeTokenHash($delivery->id, CapabilityToken::hash($tokens[$delivery->id]));
        }

        $this->assertNull($this->deliveries->verifyUnsubscribeToken($second->id, $tokens[$first->id]));

        $controller = new UnsubscribeController(
            $this->createStub(Environment::class),
            $this->deliveries,
            $this->memberEmailService,
            $this->suppressed,
            $this->blockService
        );
        $controller->unsubscribe(
            new Request('POST', '/mass-mail/unsubscribe/' . $second->id, ['token' => $tokens[$first->id]], [], [], []),
            ['id' => (string) $second->id]
        );
        $this->assertFalse($this->suppressed->isSuppressed($second->emailAddress), 'a wrong token does nothing');

        // One click, the List-Unsubscribe-Post way: the token in the query.
        $controller->unsubscribe(
            new Request('POST', '/mass-mail/unsubscribe/' . $second->id, ['token' => $tokens[$second->id]], [], [], []),
            ['id' => (string) $second->id]
        );

        $this->assertTrue($this->suppressed->isSuppressed($second->emailAddress));
        $this->assertFalse($this->suppressed->isSuppressed($first->emailAddress), 'the other message\'s address is untouched');

        // And the next mailing leaves that address out.
        $this->sent = [];
        $this->send($this->activeMembersEmail());
        $this->assertSame([$first->emailAddress], $this->sent);
    }

    // ── Fixture ─────────────────────────────────────────────────────────

    private function member(
        string $firstName,
        ?string $email,
        bool $active = true,
        ?int $function = null,
        ?int $year = null
    ): int {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK_" . uniqid('', true) . "')");
        $memberId = (int) $this->pdo->lastInsertId();
        $this->memberYear($memberId, $year ?? $this->scoutYearId, $email, $firstName, $active, $function);

        return $memberId;
    }

    private function memberYear(
        int $memberId,
        int $yearId,
        ?string $email,
        string $firstName,
        bool $active = true,
        ?int $function = null
    ): void {
        $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted,
                                       email_encrypted, email_blind_index, is_active, unit_mail_consent)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1)'
        )->execute([
            $memberId,
            $yearId,
            $this->encryption->encrypt($firstName, 'member_years.first_name'),
            $this->encryption->encrypt('Dupont', 'member_years.last_name'),
            $email !== null ? $this->encryption->encrypt($email, 'member_years.email') : null,
            $email !== null ? $this->encryption->blindIndex(strtolower($email), 'email') : null,
            $active ? 1 : 0,
        ]);
        $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, section_id, is_main_function) VALUES (?, ?, ?, 1)'
        )->execute([(int) $this->pdo->lastInsertId(), $function ?? $this->chiefFunction, $this->sectionId]);
    }

    private function secondary(int $memberId, string $email): void
    {
        (new MemberEmailRepository($this->pdo, $this->encryption))
            ->create($memberId, $email, MemberEmail::SOURCE_MANUAL, MemberEmail::STATUS_VALID, null, null);
    }

    private function account(string $email): int
    {
        return $this->accounts->create($email)->id;
    }

    /**
     * @param int[]|null $years
     */
    private function email(string $listType, ?array $years = null, ?int $listId = null): Email
    {
        return $this->service->createDraft(
            'Sujet',
            '<p>Corps</p>',
            $this->sectionId,
            $listType,
            $listId,
            null,
            $years ?? [$this->scoutYearId],
            null,
            new SenderAuthorization(true, [], null)
        );
    }

    private function activeMembersEmail(string $subject = 'Sujet'): Email
    {
        return $this->service->createDraft(
            $subject,
            '<p>Corps</p>',
            $this->sectionId,
            Email::LIST_TYPE_DEFAULT_ACTIVE_MEMBERS,
            null,
            null,
            [$this->scoutYearId],
            null,
            new SenderAuthorization(true, [], null)
        );
    }

    private function customListEmail(int $listId): Email
    {
        return $this->email(Email::LIST_TYPE_CUSTOM, null, $listId);
    }

    /**
     * @param list<array{0: ?int, 1: ?string, 2: string}> $rows [member id, Email cell, Prenom]
     */
    private function mergeEmail(array $rows, string $subject = 'Cotisation de {{Prenom}}'): Email
    {
        $audienceId = $this->audiences->createAudience('f.xlsx', 'F', ['Prenom', 'Email'], count($rows), null);
        foreach ($rows as $index => [$memberId, $cell, $name]) {
            $this->audiences->createRow($audienceId, $index + 2, $memberId, $cell, ['Prenom' => $name, 'Email' => (string) $cell]);
        }

        return $this->service->createDraft(
            $subject,
            '<p>Bonjour {{Prenom}}</p>',
            $this->sectionId,
            Email::LIST_TYPE_MAIL_MERGE,
            null,
            null,
            [],
            null,
            new SenderAuthorization(true, [], null),
            $audienceId
        );
    }

    /** Freezes the email, then runs the batch until nothing is pending. */
    private function send(Email $email): Email
    {
        $this->service->moveToTest($email->id, null);
        $this->service->startSending($email->id, null);
        $this->drain();

        $sent = $this->service->findById($email->id);
        self::assertNotNull($sent);

        return $sent;
    }

    private function drain(): void
    {
        $mailService = $this->createMock(MailService::class);
        $mailService->method('getDefaultSender')->willReturn(['address' => 'unite@test.be', 'name' => 'Unité']);
        $mailService->method('send')->willReturnCallback(function (string $to): void {
            if (isset($this->failing[mb_strtolower($to)])) {
                throw new MailException('550 mailbox full');
            }
            $this->sent[] = $to;
        });

        $context = new TaskContext(
            Connection::withPdo($this->pdo),
            $this->encryption,
            $mailService,
            new JournalService(new JournalRepository($this->pdo)),
            new SettingService(new SettingRepository($this->pdo)),
            $this->accounts,
            sys_get_temp_dir(),
            $this->notificationService()
        );
        for ($run = 0; $run < 20 && $this->deliveries->findOldestPending(1) !== []; $run++) {
            (new SendBatchHandler())->handle([], $context);
        }
        self::assertSame([], $this->deliveries->findOldestPending(1), 'the batch drained the mailing');
    }

    private function notificationService(): NotificationService
    {
        $notifications = new NotificationService(
            new NotificationRepository($this->pdo, $this->encryption),
            new PushSubscriptionRepository($this->pdo, $this->encryption),
            new NotificationPreferenceRepository($this->pdo),
            $this->createStub(WebPush::class),
            new SettingService(new SettingRepository($this->pdo)),
            new JournalService(new JournalRepository($this->pdo)),
            new SchedulerService(new SchedulerRepository($this->pdo)),
            $this->accounts
        );
        $notifications->registerModuleTypes('mass_mail', [[
            'id' => 'mass_mail.email_received', 'label' => 'Nouvel email reçu', 'description' => 'd',
            'group' => 'Emails groupés', 'role_min' => 'identified',
            'channels' => ['in_app' => 'default_on', 'push' => 'default_on', 'email' => 'off'],
        ]]);

        return $notifications;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function entries(int $memberId): array
    {
        return $this->history->getRecentEmailsForMember($memberId, 10);
    }

    private function notificationsOf(int $accountId): int
    {
        return count((new NotificationRepository($this->pdo, $this->encryption))->findByUserAccountId($accountId));
    }
}
