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
use Core\Mail\MailService;
use Core\Member\MemberEmail;
use Core\Member\MemberEmailRepository;
use Core\Member\MemberEmailService;
use Core\Member\MemberService;
use Core\Member\SectionService;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Security\EncryptionService;
use Core\Security\HtmlSanitizer;
use Modules\MassMail\Controller\UnsubscribeController;
use Modules\MassMail\Repository\AudienceRepository;
use Modules\MassMail\Repository\Email;
use Modules\MassMail\Repository\EmailAttachmentRepository;
use Modules\MassMail\Repository\EmailRepository;
use Modules\MassMail\Repository\ListAddressRepository;
use Modules\MassMail\Repository\MailingListRepository;
use Modules\MassMail\Repository\MemberResolutionRepository;
use Modules\MassMail\Repository\Recipient;
use Modules\MassMail\Repository\RecipientRepository;
use Modules\MassMail\Repository\SuppressedAddressRepository;
use Modules\MassMail\Service\ListAddressService;
use Modules\MassMail\Service\MailingListService;
use Modules\MassMail\Service\MassMailService;
use Modules\MassMail\Service\MergeRenderer;
use Modules\MassMail\Service\SenderAuthorization;
use PHPUnit\Framework\TestCase;
use Tests\Core\Mail\Template\EmailTemplateRendererFactory;
use Tests\DatabaseTestHelper;
use Twig\Environment;

/**
 * A custom list is the UNION of what its criteria resolve and its own
 * addresses — end to end, from resolution through the recipient freeze to
 * the unsubscribe link at the foot of the mail.
 *
 * The three things this file exists to pin are the three that are easy to
 * get wrong and invisible when they are: an address that is also a
 * member's is ONE recipient, an unsubscribed address is not written to,
 * and an unsubscribe reaches every list rather than the one the mail
 * happened to come from.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class ListAddressFlowTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private MassMailService $massMailService;
    private MailingListService $listService;
    private ListAddressService $addressService;
    private ListAddressRepository $addressRepository;
    private RecipientRepository $recipientRepository;
    private SuppressedAddressRepository $suppressedRepository;
    private \Core\Mail\Feedback\Bounce\BounceStateRepository $bounceStates;
    private MemberEmailService $memberEmailService;
    private int $scoutYearId;
    private int $sectionId;
    private AudienceRepository $audienceRepository;
    private int $functionId;
    private int $listId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        MassMailTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $connection = Connection::withPdo($this->pdo);
        $sectionService = new SectionService($connection, $this->encryption, new MemberBadgeRepository($this->pdo));
        $memberService = new MemberService(new MemberYearRepository($this->pdo), $this->encryption, $connection);

        $this->addressRepository = new ListAddressRepository($this->pdo, $this->encryption);
        $listRepository = new MailingListRepository($this->pdo);
        $this->addressService = new ListAddressService(
            $this->addressRepository,
            $listRepository,
            new SettingService(new SettingRepository($this->pdo)),
            $this->createMock(JournalService::class),
            new SuppressedAddressRepository($this->pdo)
        );
        $this->listService = new MailingListService(
            $listRepository,
            new MemberResolutionRepository($this->pdo, $this->encryption),
            $sectionService,
            new FunctionRepository($this->pdo),
            null,
            $this->addressRepository
        );

        $this->recipientRepository = new RecipientRepository($this->pdo, $this->encryption);
        $this->suppressedRepository = new SuppressedAddressRepository($this->pdo);
        $this->memberEmailService = $this->buildMemberEmailService($sectionService, $memberService);

        $this->massMailService = new MassMailService(
            new EmailRepository($this->pdo),
            $this->recipientRepository,
            new EmailAttachmentRepository($this->pdo),
            new FileRepository($this->pdo),
            $this->listService,
            $memberService,
            $this->memberEmailService,
            $sectionService,
            $this->createMock(MailService::class),
            new SchedulerService(new SchedulerRepository($this->pdo)),
            new JournalService(new JournalRepository($this->pdo)),
            new HtmlSanitizer(),
            new ScoutYearService($this->pdo),
            new ImportJournalRepository($this->pdo),
            sys_get_temp_dir(),
            $this->audienceRepository = new AudienceRepository($this->pdo, $this->encryption),
            new MemberResolutionRepository($this->pdo, $this->encryption),
            $this->suppressedRepository,
            new MergeRenderer(),
            // A REAL bounce service over the same database. Null would
            // answer « jamais rebondi » to every question and the block
            // branch below could never be reached.
            new \Core\Mail\Feedback\Bounce\BounceService(
                $this->bounceStates = new \Core\Mail\Feedback\Bounce\BounceStateRepository(
                    $this->pdo,
                    $this->encryption
                )
            )
        );

        [$label, $yearStart, $yearEnd] = DatabaseTestHelper::scoutYear();
        $this->pdo->exec(
            "INSERT INTO scout_years (label, start_date, end_date, is_current)
             VALUES ('{$label}', '{$yearStart}', '{$yearEnd}', 1)"
        );
        $this->scoutYearId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('LOU', 'Louveteaux', 1)");
        $branchId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO sections (desk_code, age_branch_id, name) VALUES ('LOU01', {$branchId}, 'Meute')");
        $this->sectionId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO functions (desk_code, label, role) VALUES ('CHEF', 'Chef', 'chief')");
        $this->functionId = (int) $this->pdo->lastInsertId();

        $this->listId = $this->listService->createCustomList(
            'Les chefs et la commune',
            'Les chefs de la meute, plus la commune.',
            [$this->functionId],
            [$this->sectionId],
            [],
            null
        )->id;
    }

    public function testAListWithAddressesOnlyResolvesToThoseAddresses(): void
    {
        $listId = $this->listService->createCustomList(
            'Carnet',
            'Uniquement des adresses extérieures.',
            [$this->functionId],
            [],
            [],
            null
        )->id;
        $this->addressService->add($listId, 'Commune', 'jeunesse@wavre.be');

        $resolved = $this->listService->resolveMembersForYears('custom', $listId, null, [$this->scoutYearId]);

        $this->assertSame(
            [['member_id' => null, 'email' => 'jeunesse@wavre.be', 'scout_year_id' => null]],
            $resolved
        );
    }

    public function testAListWithCriteriaAndAddressesResolvesToTheUnion(): void
    {
        $this->createMember('chef@test.be');
        $this->addressService->add($this->listId, 'Commune', 'jeunesse@wavre.be');

        $resolved = $this->listService->resolveMembersForYears('custom', $this->listId, null, [$this->scoutYearId]);

        $this->assertEqualsCanonicalizing(
            ['chef@test.be', 'jeunesse@wavre.be'],
            array_column($resolved, 'email')
        );
    }

    /**
     * The deduplication has to work on the NORMALISED address: the two
     * halves of the union share no identifier at all, so a member id
     * cannot be what catches this.
     */
    public function testAnAddressThatIsAlsoAMembersCountsOnce(): void
    {
        $this->createMember('chef@test.be');
        $this->addressService->add($this->listId, 'Le même', '  CHEF@Test.BE ');

        $resolved = $this->listService->resolveMembersForYears('custom', $this->listId, null, [$this->scoutYearId]);

        $this->assertCount(1, $resolved);
        $this->assertSame('chef@test.be', $resolved[0]['email']);
        $this->assertNotNull($resolved[0]['member_id']);
    }

    public function testAnUnsubscribedAddressIsNeverResolved(): void
    {
        $this->addressService->add($this->listId, 'Commune', 'jeunesse@wavre.be');
        $this->addressService->add($this->listId, 'Curé', 'cure@paroisse.be');
        $this->addressService->unsubscribeEverywhere('cure@paroisse.be');

        $resolved = $this->listService->resolveMembersForYears('custom', $this->listId, null, [$this->scoutYearId]);

        $this->assertSame(['jeunesse@wavre.be'], array_column($resolved, 'email'));
    }

    /**
     * A list address is frozen as a recipient of its own: no member, no
     * scout year, no member_emails row and no audience row — the shape
     * that tells it apart from an external mail-merge recipient.
     */
    public function testAListAddressIsFrozenAsItsOwnRecipient(): void
    {
        $this->createMember('chef@test.be');
        $this->addressService->add($this->listId, 'Commune', 'jeunesse@wavre.be');

        $email = $this->sendableEmail();
        $this->massMailService->startSending($email->id, null);

        $recipients = $this->recipientRepository->findByEmailId($email->id);
        $this->assertCount(2, $recipients);

        $external = $this->recipientFor($recipients, 'jeunesse@wavre.be');
        $this->assertNull($external->memberId);
        $this->assertNull($external->scoutYearId);
        $this->assertNull($external->memberEmailId);
        $this->assertNull($external->audienceRowId);
        $this->assertSame(Recipient::STATUS_PENDING, $external->status);
    }

    /**
     * The other direction of the same decision: an address suppressed by a
     * mail-merge unsubscribe is not written to through a list either, and
     * the row says why rather than quietly disappearing.
     */
    public function testAnAddressSuppressedElsewhereIsFrozenAsAnExplicitError(): void
    {
        $this->addressService->add($this->listId, 'Commune', 'jeunesse@wavre.be');
        $this->suppressedRepository->suppress('jeunesse@wavre.be');

        $email = $this->sendableEmail();
        $this->massMailService->startSending($email->id, null);

        $recipient = $this->recipientFor($this->recipientRepository->findByEmailId($email->id), 'jeunesse@wavre.be');
        $this->assertSame(Recipient::STATUS_ERROR, $recipient->status);
        $this->assertSame('Adresse désinscrite des emails groupés', $recipient->errorMessage);
    }

    /**
     * **The one hole the member-side filter cannot cover** (roadmap
     * IT-05). `MemberEmailService::resolveValidAddressesForMassMail()`
     * drops blocked addresses, but it never sees a list address: a
     * custom list carries addresses that belong to no member. Without
     * this branch, an address the far end has refused twice goes on being
     * written to through a list — which is the reputation damage the
     * whole feature exists to stop.
     *
     * Frozen as an explicit error row rather than silently dropped, like
     * the suppression above, so the tracking page says why.
     */
    public function testABounceBlockedAddressIsFrozenAsAnExplicitError(): void
    {
        $this->addressService->add($this->listId, 'Commune', 'jeunesse@wavre.be');

        $t = new \DateTimeImmutable('2026-03-01 09:00:00');
        // Vouched for by the module, as `SendBatchHandler` does for its
        // own recipients: a list address is one a staff member entered,
        // it lives in a table the core cannot read, and so the core's
        // « is this address on file? » cannot answer for it.
        $this->bounceStates->recordSend('jeunesse@wavre.be', $t, true);
        $state = $this->bounceStates->record(
            'jeunesse@wavre.be',
            \Core\Mail\Feedback\Bounce\BounceCategory::NoSuchAddress,
            \Core\Mail\Feedback\Bounce\BounceSeverity::Permanent,
            '5.1.1',
            $t->modify('+1 minute')
        );
        self::assertNotNull($state);
        $this->bounceStates->block($state->id, $t->modify('+2 minutes'));

        $email = $this->sendableEmail();
        $this->massMailService->startSending($email->id, null);

        $recipient = $this->recipientFor($this->recipientRepository->findByEmailId($email->id), 'jeunesse@wavre.be');
        $this->assertSame(Recipient::STATUS_ERROR, $recipient->status);
        $this->assertSame('Adresse suspendue après des refus répétés', $recipient->errorMessage);
    }

    /**
     * **The same hole, through the other list type** — and this one the
     * list-path check above does not cover.
     *
     * A mail-merge audience row without a « Tiers » carries a raw address:
     * `AudienceImportService` writes one whenever an imported line has an
     * « Email » column and no member match. Nothing else filters it. The
     * member branch of the freeze goes through
     * `resolveValidAddressesForMassMail()`, which drops blocked
     * addresses — but only for rows that HAVE a member. And
     * `MailService::send()`'s own gate never fires, because merge
     * recipients do not vouch for their recipient.
     *
     * So an address suspended after two permanent bounces went on being
     * written to through a merge campaign: exactly the « keeps being
     * written to through a list » the list-path check was added to close,
     * arriving by the door next to it.
     *
     * Written HERE rather than in `MassMailServiceTest` on purpose: that
     * class builds its `MassMailService` without a `BounceStateRepository`,
     * so `$this->bounces?->isBlocked()` short-circuits to null there and
     * the test would pass whatever the freeze did.
     */
    public function testABounceBlockedRawAddressIsFrozenInAMergeCampaignToo(): void
    {
        $t = new \DateTimeImmutable('2026-03-01 09:00:00');
        $this->bounceStates->recordSend('jeunesse@wavre.be', $t, true);
        $state = $this->bounceStates->record(
            'jeunesse@wavre.be',
            \Core\Mail\Feedback\Bounce\BounceCategory::NoSuchAddress,
            \Core\Mail\Feedback\Bounce\BounceSeverity::Permanent,
            '5.1.1',
            $t->modify('+1 minute')
        );
        self::assertNotNull($state);
        $this->bounceStates->block($state->id, $t->modify('+2 minutes'));

        $audienceId = $this->audienceRepository->createAudience(
            'test.xlsx',
            'Feuille1',
            ['Email', 'Prenom'],
            1,
            null
        );
        $this->audienceRepository->createRow(
            $audienceId,
            2,
            null,
            'jeunesse@wavre.be',
            ['Email' => 'jeunesse@wavre.be', 'Prenom' => 'Emma']
        );

        $email = $this->massMailService->createDraft(
            'Infos pour {{Prenom}}',
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
        $this->massMailService->moveToTest($email->id, null);
        $this->massMailService->startSending($email->id, null);

        $recipient = $this->recipientFor(
            $this->recipientRepository->findByEmailId($email->id),
            'jeunesse@wavre.be'
        );
        $this->assertSame(Recipient::STATUS_ERROR, $recipient->status);
        $this->assertSame('Adresse suspendue après des refus répétés', $recipient->errorMessage);
    }

    /** And an address that never bounced goes through untouched. */
    public function testAnAddressThatNeverBouncedIsStillSendable(): void
    {
        $this->addressService->add($this->listId, 'Commune', 'jeunesse@wavre.be');

        $email = $this->sendableEmail();
        $this->massMailService->startSending($email->id, null);

        $recipient = $this->recipientFor($this->recipientRepository->findByEmailId($email->id), 'jeunesse@wavre.be');
        $this->assertSame(Recipient::STATUS_PENDING, $recipient->status);
    }

    /**
     * D2, end to end: the unsubscribe link at the foot of a mail sent to
     * one list flags the same address in EVERY list.
     */
    public function testUnsubscribingFromOneListReachesTheOthers(): void
    {
        $secondListId = $this->listService->createCustomList(
            'Autre liste',
            'Une autre liste qui écrit à la commune.',
            [$this->functionId],
            [$this->sectionId],
            [],
            null
        )->id;
        $this->addressService->add($this->listId, 'Commune', 'jeunesse@wavre.be');
        $this->addressService->add($secondListId, 'Commune de Wavre', 'jeunesse@wavre.be');

        $email = $this->sendableEmail();
        $this->massMailService->startSending($email->id, null);
        $recipient = $this->recipientFor($this->recipientRepository->findByEmailId($email->id), 'jeunesse@wavre.be');

        $token = 'a' . str_repeat('b', 63);
        $this->recipientRepository->setUnsubscribeTokenHash($recipient->id, \Core\Security\CapabilityToken::hash($token));

        $response = $this->unsubscribeController()->unsubscribe(
            new Request('POST', '/mass-mail/unsubscribe/' . $recipient->id, ['token' => $token], [], [], []),
            ['id' => (string) $recipient->id]
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $this->addressRepository->countForList($this->listId)['unsubscribed']);
        $this->assertSame(1, $this->addressRepository->countForList($secondListId)['unsubscribed']);
        // And the address is suppressed for the mail-merge path too: the
        // person asked the unit to stop, not one of its lists.
        $this->assertTrue($this->suppressedRepository->isSuppressed('jeunesse@wavre.be'));
    }

    /**
     * @param Recipient[] $recipients
     */
    private function recipientFor(array $recipients, string $address): Recipient
    {
        foreach ($recipients as $recipient) {
            if ($recipient->emailAddress === $address) {
                return $recipient;
            }
        }

        $this->fail("no recipient frozen for {$address}");
    }

    private function sendableEmail(): Email
    {
        $email = $this->massMailService->createDraft(
            'Sujet',
            '<p>Corps</p>',
            $this->sectionId,
            'custom',
            $this->listId,
            null,
            [$this->scoutYearId],
            null,
            new SenderAuthorization(true, [], null)
        );
        return $this->massMailService->moveToTest($email->id, null);
    }

    /**
     * The dedup at resolution time only knows a member's DESK address —
     * it is the one the criteria carry. A member is written to at every
     * valid address they have, so the freeze is the only place that can
     * see a list address matching a member's SECOND one.
     */
    public function testAListAddressMatchingAMembersSecondAddressIsNotASecondMail(): void
    {
        $memberId = $this->createMember('chef@test.be');
        $this->addSecondaryEmail($memberId, 'perso@test.be');
        $this->addressService->add($this->listId, 'Le même', 'PERSO@test.be');

        $email = $this->sendableEmail();
        $this->massMailService->startSending($email->id, null);

        $recipients = $this->recipientRepository->findByEmailId($email->id);
        $addresses = array_map(
            static fn(Recipient $r): string => mb_strtolower((string) $r->emailAddress),
            $recipients
        );

        $this->assertSame(['chef@test.be', 'perso@test.be'], $addresses);
        // The one row kept is the member's, not a nameless list address.
        $this->assertNotNull($this->recipientFor($recipients, 'perso@test.be')->memberId);
    }

    /**
     * A member's own unsubscribe deactivates their member_emails row and
     * nothing else — so without the suppression write, a chief adding
     * that same address to a list tomorrow would write to somebody who
     * asked the unit to stop.
     */
    public function testAMembersUnsubscribeAlsoClosesTheListAddressPath(): void
    {
        $memberId = $this->createMember('chef@test.be');
        $email = $this->sendableEmail();
        $this->massMailService->startSending($email->id, null);

        $recipient = $this->recipientFor($this->recipientRepository->findByEmailId($email->id), 'chef@test.be');
        $this->assertNotNull($recipient->memberEmailId, 'a member recipient, unsubscribing through member_emails');

        $token = 'a' . str_repeat('b', 63);
        $this->recipientRepository->setUnsubscribeTokenHash($recipient->id, \Core\Security\CapabilityToken::hash($token));
        $this->unsubscribeController()->unsubscribe(
            new Request('POST', '/mass-mail/unsubscribe/' . $recipient->id, ['token' => $token], [], [], []),
            ['id' => (string) $recipient->id]
        );

        $this->assertTrue($this->suppressedRepository->isSuppressed('chef@test.be'));

        // And the row a chief adds afterwards is born unsubscribed, so the
        // screen says so instead of counting an address the send refuses.
        $added = $this->addressService->add($this->listId, 'Ancien chef', 'chef@test.be');
        $this->assertNotNull($this->addressRepository->findById($added->id)?->unsubscribedAt);
        // The member is still WHO the list designates — resolution reads
        // the criteria, not an address's fate — but the new row is not a
        // second entry beside them.
        $resolved = $this->listService->resolveMembersForYears('custom', $this->listId, null, [$this->scoutYearId]);
        $this->assertCount(1, $resolved);
        $this->assertSame($memberId, $resolved[0]['member_id']);
    }

    private function createMember(string $email): int
    {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK_" . uniqid() . "')");
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years
                (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, email_encrypted,
                 email_blind_index, is_active)
             VALUES (?, ?, ?, ?, ?, ?, 1)'
        );
        $stmt->execute([
            $memberId,
            $this->scoutYearId,
            $this->encryption->encrypt('Jean', 'member_years.first_name'),
            $this->encryption->encrypt('Dupont', 'member_years.last_name'),
            $this->encryption->encrypt($email, 'member_years.email'),
            $this->encryption->blindIndex($email, 'email'),
        ]);
        $memberYearId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, section_id, is_main_function)
             VALUES (?, ?, ?, 1)'
        );
        $stmt->execute([$memberYearId, $this->functionId, $this->sectionId]);

        return $memberId;
    }

    /** A confirmed secondary address, as the account page would leave it. */
    private function addSecondaryEmail(int $memberId, string $email): int
    {
        return (new MemberEmailRepository($this->pdo, $this->encryption))->create(
            $memberId,
            $email,
            MemberEmail::SOURCE_MANUAL,
            MemberEmail::STATUS_VALID,
            null,
            null
        );
    }

    private function unsubscribeController(): UnsubscribeController
    {
        return new UnsubscribeController(
            $this->createMock(Environment::class),
            $this->recipientRepository,
            $this->memberEmailService,
            $this->suppressedRepository,
            $this->addressService
        );
    }

    private function buildMemberEmailService(
        SectionService $sectionService,
        MemberService $memberService
    ): MemberEmailService {
        return new MemberEmailService(
            new MemberEmailRepository($this->pdo, $this->encryption),
            $this->createMock(MailService::class),
            EmailTemplateRendererFactory::overTestDatabase($this->pdo, $this->createMock(Environment::class)),
            new JournalService(new JournalRepository($this->pdo)),
            $sectionService,
            $memberService,
            new ScoutYearService($this->pdo),
            'https://example.test',
            'Test Unité'
        );
    }
}
