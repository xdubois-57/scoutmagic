<?php

declare(strict_types=1);

namespace Tests\Core\Contact\CardDav;

use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Contact\CardDav\AddressBookRepository;
use Core\Contact\CardDav\AddressBookService;
use Core\Contact\ContactCardService;
use Core\Contact\Repository\ContactCardRepository;
use Core\Contact\VCardBuilder;
use Core\Database\Connection;
use Core\Import\MemberYearRepository;
use Core\Member\MemberEmailRepository;
use Core\Member\MemberService;
use Core\Security\EncryptionService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * Who the synchronised address book contains, and what a client is told
 * when it polls.
 *
 * The membership assertions are the ones with a rule behind them:
 * `SECURITY.md` §6 authorises this export because what leaves is what
 * every identified member already sees on the trombinoscope. A test that
 * let an animé in would not be a failing test, it would be a breach.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class AddressBookServiceTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $enc;
    private AddressBookService $service;
    private int $yearId;
    private int $visibleSectionId;
    private int $chiefFunctionId;
    private int $animeFunctionId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->enc = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $connection = Connection::withPdo($this->pdo);

        $settings = new SettingService(new SettingRepository($this->pdo));
        $settings->register('site_name', '15e Unité Saint-Michel', 'text', 'Nom', 'Nom de l\'unité');

        $this->service = new AddressBookService(
            new AddressBookRepository($connection),
            new ContactCardRepository($connection),
            new ContactCardService(
                new ContactCardRepository($connection),
                $settings,
                new MemberEmailRepository($this->pdo, $this->enc)
            ),
            new VCardBuilder(),
            new MemberService(new MemberYearRepository($this->pdo), $this->enc, $connection),
            new ScoutYearService($this->pdo)
        );

        [$label, $start, $end] = DatabaseTestHelper::scoutYear();
        $stmt = $this->pdo->prepare(
            'INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES (?, ?, ?, 1)'
        );
        $stmt->execute([$label, $start, $end]);
        $this->yearId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO age_branches (desk_code, label) VALUES ('LOUV', 'Louveteaux')");
        $branchId = (int) $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare(
            'INSERT INTO sections (age_branch_id, desk_code, name, is_visible, is_active) VALUES (?, ?, ?, 1, 1)'
        );
        $stmt->execute([$branchId, 'LOUV1', 'Les Loups Gris']);
        $this->visibleSectionId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare('INSERT INTO functions (desk_code, label, role) VALUES (?, ?, ?)');
        $stmt->execute(['ANIM', 'Animateur', 'chief']);
        $this->chiefFunctionId = (int) $this->pdo->lastInsertId();
        $stmt->execute(['ANIME', 'Animé', 'identified']);
        $this->animeFunctionId = (int) $this->pdo->lastInsertId();
    }

    /**
     * @return array{0: int, 1: int} member id, member_year id
     */
    private function member(string $firstName, string $lastName, int $functionId, ?int $sectionId = null): array
    {
        $stmt = $this->pdo->prepare('INSERT INTO members (desk_id) VALUES (?)');
        $stmt->execute(['D-' . bin2hex(random_bytes(4))]);
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, is_active)
             VALUES (?, ?, ?, ?, 1)'
        );
        $stmt->execute([
            $memberId,
            $this->yearId,
            $this->enc->encrypt($firstName, 'member_years.first_name'),
            $this->enc->encrypt($lastName, 'member_years.last_name'),
        ]);
        $memberYearId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, section_id, is_main_function)
             VALUES (?, ?, ?, 1)'
        );
        $stmt->execute([$memberYearId, $functionId, $sectionId ?? $this->visibleSectionId]);

        return [$memberId, $memberYearId];
    }

    public function testTheAddressBookHoldsTheLeadersOfTheCurrentYear(): void
    {
        [$chiefId] = $this->member('Camille', 'Dupont', $this->chiefFunctionId);

        $entries = $this->service->entries($this->yearId);

        $this->assertCount(1, $entries);
        $this->assertSame($chiefId, $entries[0]->memberId);
    }

    /**
     * The line this whole chantier rests on: no animé, ever. A child's
     * contact details are not in the set the trombinoscope shows, so
     * they are not in the set that leaves over CardDAV.
     */
    public function testAnAnimeIsNeverInTheAddressBook(): void
    {
        $this->member('Petit', 'Loup', $this->animeFunctionId);

        $this->assertSame([], $this->service->entries($this->yearId));
    }

    /**
     * A section hidden from every picker on the site is hidden here too
     * — the equality with the trombinoscope has to hold in both
     * directions or the sentence in SECURITY.md §6 stops being true.
     */
    public function testALeaderOfAnInvisibleSectionIsLeftOut(): void
    {
        $this->pdo->exec("INSERT INTO age_branches (desk_code, label) VALUES ('PION', 'Pionniers')");
        $branchId = (int) $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare(
            'INSERT INTO sections (age_branch_id, desk_code, name, is_visible, is_active) VALUES (?, ?, ?, 0, 1)'
        );
        $stmt->execute([$branchId, 'PION1', 'Section cachée']);
        $hiddenSectionId = (int) $this->pdo->lastInsertId();

        $this->member('Alex', 'Martin', $this->chiefFunctionId, $hiddenSectionId);

        $this->assertSame([], $this->service->entries($this->yearId));
    }

    public function testAnInactiveMemberYearIsLeftOut(): void
    {
        [, $memberYearId] = $this->member('Camille', 'Dupont', $this->chiefFunctionId);
        $stmt = $this->pdo->prepare('UPDATE member_years SET is_active = 0 WHERE id = ?');
        $stmt->execute([$memberYearId]);

        $this->assertSame([], $this->service->entries($this->yearId));
    }

    /**
     * Two functions, one person, one card: the UID is the member, so a
     * duplicate here would make a client create two contacts for the
     * same leader.
     */
    public function testALeaderHoldingTwoFunctionsAppearsOnce(): void
    {
        [$memberId, $memberYearId] = $this->member('Camille', 'Dupont', $this->chiefFunctionId);
        $stmt = $this->pdo->prepare('INSERT INTO functions (desk_code, label, role) VALUES (?, ?, ?)');
        $stmt->execute(['CU', 'Chef d\'unité', 'admin']);
        $second = (int) $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, section_id, is_main_function)
             VALUES (?, ?, ?, 0)'
        );
        $stmt->execute([$memberYearId, $second, $this->visibleSectionId]);

        $entries = $this->service->entries($this->yearId);

        $this->assertCount(1, $entries);
        $this->assertSame($memberId, $entries[0]->memberId);
    }

    public function testEachEntryCarriesItsOwnTagAndItsOwnPath(): void
    {
        [$firstId] = $this->member('Camille', 'Dupont', $this->chiefFunctionId);
        [$secondId] = $this->member('Alex', 'Martin', $this->chiefFunctionId);

        $entries = $this->service->entries($this->yearId);
        $this->assertCount(2, $entries);
        $this->assertNotSame($entries[0]->etag, $entries[1]->etag);
        $this->assertSame('/carddav/staff/' . $firstId . '.vcf', $entries[0]->href());
        $this->assertSame('/carddav/staff/' . $secondId . '.vcf', $entries[1]->href());
    }

    /**
     * An entity tag is echoed in logs and proxies, so it says « this
     * card » and never « this person changed at 21:04 ».
     */
    public function testATagCarriesNoTimestampInClear(): void
    {
        $this->member('Camille', 'Dupont', $this->chiefFunctionId);
        $entries = $this->service->entries($this->yearId);

        $this->assertMatchesRegularExpression('/^"[0-9a-f]{64}"$/', $entries[0]->etag);
        $this->assertMatchesRegularExpression('/^"[0-9a-f]{64}"$/', $this->service->collectionTag($this->yearId));
    }

    public function testTheCollectionTagIsStableWhileNothingChanges(): void
    {
        $this->member('Camille', 'Dupont', $this->chiefFunctionId);

        $this->assertSame(
            $this->service->collectionTag($this->yearId),
            $this->service->collectionTag($this->yearId)
        );
    }

    public function testTheCollectionTagMovesWhenSomebodyJoins(): void
    {
        $this->member('Camille', 'Dupont', $this->chiefFunctionId);
        $before = $this->service->collectionTag($this->yearId);

        $this->member('Alex', 'Martin', $this->chiefFunctionId);

        $this->assertNotSame($before, $this->service->collectionTag($this->yearId));
    }

    /**
     * And when somebody leaves: a client that only ever heard about
     * additions would keep a card for a leader who is gone.
     */
    public function testTheCollectionTagMovesWhenSomebodyLeaves(): void
    {
        $this->member('Camille', 'Dupont', $this->chiefFunctionId);
        [, $memberYearId] = $this->member('Alex', 'Martin', $this->chiefFunctionId);
        $before = $this->service->collectionTag($this->yearId);

        $stmt = $this->pdo->prepare('UPDATE member_years SET is_active = 0 WHERE id = ?');
        $stmt->execute([$memberYearId]);

        $this->assertNotSame($before, $this->service->collectionTag($this->yearId));
    }

    public function testAnEmptyAddressBookStillAnswersATag(): void
    {
        $this->assertSame('"empty"', $this->service->collectionTag($this->yearId));
        $this->assertSame([], $this->service->entries($this->yearId));
    }

    public function testACardIsTheCompleteVcardOfThatLeader(): void
    {
        [$memberId] = $this->member('Camille', 'Dupont', $this->chiefFunctionId);

        $card = $this->service->card($memberId, $this->yearId);

        $this->assertNotNull($card);
        $this->assertStringContainsString('BEGIN:VCARD', $card['body']);
        $this->assertStringContainsString('FN:Camille Dupont', $card['body']);
        $this->assertStringContainsString('UID:scoutmagic-member-' . $memberId, $card['body']);
        $this->assertSame($this->service->entries($this->yearId)[0]->etag, $card['etag']);
    }

    /**
     * « Not a leader » and « no such member » are the same answer on
     * purpose: a client holding a stale path, and anybody guessing
     * identifiers, must not be able to tell them apart.
     */
    public function testAMemberOutsideTheAddressBookAnswersNothing(): void
    {
        [$animeId] = $this->member('Petit', 'Loup', $this->animeFunctionId);

        $this->assertNull($this->service->card($animeId, $this->yearId));
        $this->assertNull($this->service->card(999999, $this->yearId));
        $this->assertNull($this->service->card(0, $this->yearId));
        $this->assertNull($this->service->card(-1, $this->yearId));
    }

    /**
     * Hrefs come back from a client verbatim in a multiget, so this
     * parses a value that came from outside.
     */
    public function testOnlyThisCollectionsOwnPathsResolveToAMember(): void
    {
        $this->assertSame(7, $this->service->memberIdForHref('/carddav/staff/7.vcf'));
        $this->assertSame(7, $this->service->memberIdForHref('https://example.org/carddav/staff/7.vcf'));
        $this->assertSame(7, $this->service->memberIdForHref('  /carddav/staff/7.vcf  '));

        $this->assertNull($this->service->memberIdForHref('/carddav/staff/7'));
        $this->assertNull($this->service->memberIdForHref('/carddav/staff/07.vcf'));
        $this->assertNull($this->service->memberIdForHref('/carddav/staff/7.vcf.bak'));
        $this->assertNull($this->service->memberIdForHref('/carddav/staff/../../etc/passwd'));
        $this->assertNull($this->service->memberIdForHref('/other/7.vcf'));
        $this->assertNull($this->service->memberIdForHref('/carddav/staff/abc.vcf'));
        $this->assertNull($this->service->memberIdForHref(''));
    }

    public function testTheCurrentScoutYearIsTheOneThePublishedBookUses(): void
    {
        $this->assertSame($this->yearId, $this->service->currentScoutYearId());
    }
}
