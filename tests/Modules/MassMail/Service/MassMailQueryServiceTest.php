<?php

declare(strict_types=1);

namespace Tests\Modules\MassMail\Service;

use Core\Security\EncryptionService;
use Modules\MassMail\Repository\AudienceRepository;
use Modules\MassMail\Repository\Delivery;
use Modules\MassMail\Repository\DeliveryRepository;
use Modules\MassMail\Repository\TargetRepository;
use Modules\MassMail\Service\MassMailQueryService;
use Modules\MassMail\Service\MergeRenderer;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\MassMail\MassMailTestHelper;

/**
 * Modules\MassMail\Api\MassMailQueryInterface's concrete implementation —
 * the only entry point core's MemberController uses (ARCHITECTURE.md
 * §7.5). Graceful degradation when the module is disabled is verified at
 * Core\Http\Controller\MemberController level (see
 * tests/Core/Http/Controller/MemberControllerMassMailTest.php); this test
 * covers the implementation itself.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class MassMailQueryServiceTest extends TestCase
{
    public function testReturnsOnlySentEmailsForTheGivenMember(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        MassMailTestHelper::createTables($pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $recipientRepository = new DeliveryRepository($pdo, $encryption);
        $queryService = new MassMailQueryService(
            new TargetRepository($pdo),
            new AudienceRepository($pdo, $encryption),
            new MergeRenderer()
        );

        $pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)");
        $scoutYearId = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('LOU', 'Louveteaux', 1)");
        $branchId = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO sections (desk_code, age_branch_id, name) VALUES ('LOU01', {$branchId}, 'Meute A')");
        $sectionId = (int) $pdo->lastInsertId();
        $pdo->exec(
            "INSERT INTO mass_mail_emails (subject, body_html, section_id, list_type, status)
             VALUES ('Sujet envoyé', '<p>x</p>', {$sectionId}, 'default_active_members', 'sent')"
        );
        $emailId = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK_1')");
        $memberId = (int) $pdo->lastInsertId();

        self::sentTo($pdo, $recipientRepository, $emailId, $memberId, $scoutYearId, ['a@test.be']);

        $result = $queryService->getRecentEmailsForMember($memberId, 10);

        $this->assertCount(1, $result);
        $this->assertSame('Sujet envoyé', $result[0]['subject']);
        $this->assertSame('Meute A', $result[0]['section_name']);

        $detail = $queryService->findEmailDetailForMember($memberId, $result[0]['id']);
        $this->assertNotNull($detail);
        $this->assertSame('Sujet envoyé', $detail['subject']);
        $this->assertSame('<p>x</p>', $detail['body_html']);

        $this->assertNull($queryService->findEmailDetailForMember($memberId + 999, $result[0]['id']));
    }

    /**
     * Issue #287. A publipostage is stored once as a template and
     * substituted per recipient in local variables just before the send,
     * so everything that read the e-mail back afterwards showed
     * `{{Prenom}}` to somebody whose mailbox holds their own first name.
     * The list and the detail page both re-render from the recipient's
     * OWN audience row.
     */
    public function testAPublipostageIsReadBackWithThisMembersOwnValues(): void
    {
        $fixture = $this->mergeFixture();

        $result = $fixture['service']->getRecentEmailsForMember($fixture['member_id'], 10);
        $this->assertCount(1, $result);
        $this->assertSame('Camp de Kaa', $result[0]['subject']);
        $this->assertFalse($result[0]['merge_purged']);

        $detail = $fixture['service']->findEmailDetailForMember($fixture['member_id'], $result[0]['id']);
        $this->assertNotNull($detail);
        $this->assertSame('Camp de Kaa', $detail['subject']);
        $this->assertSame('<p>Cher Kaa, tu paieras 145 €.</p>', $detail['body_html']);
        $this->assertFalse($detail['merge_purged']);
    }

    /**
     * The other member of the same mailing gets THEIR row, not the first
     * one — which is the whole failure mode a single stored copy would
     * reintroduce.
     */
    public function testTwoRecipientsOfOneMailingReadTwoDifferentEmails(): void
    {
        $fixture = $this->mergeFixture();

        $first = $fixture['service']->getRecentEmailsForMember($fixture['member_id'], 10);
        $second = $fixture['service']->getRecentEmailsForMember($fixture['other_member_id'], 10);

        $this->assertSame('Camp de Kaa', $first[0]['subject']);
        $this->assertSame('Camp de Emma', $second[0]['subject']);

        $secondDetail = $fixture['service']->findEmailDetailForMember(
            $fixture['other_member_id'],
            $second[0]['id']
        );
        $this->assertNotNull($secondDetail);
        $this->assertSame('<p>Cher Emma, tu paieras 80 €.</p>', $secondDetail['body_html']);
    }

    /**
     * Retention (Task\PurgeMergeAudiencesHandler) erases a merge audience
     * 18 months after the last send, and reading through the audience row
     * is what makes the personalisation disappear with it rather than
     * survive in a frozen copy. What is left cannot be rendered — so the
     * caller is TOLD, instead of being handed a template that reads as a
     * bug.
     */
    public function testAPurgedAudienceSaysSoRatherThanPassingOffTheTemplate(): void
    {
        $fixture = $this->mergeFixture();
        $recipientId = $fixture['service']->getRecentEmailsForMember($fixture['member_id'], 10)[0]['id'];

        $fixture['pdo']->exec('DELETE FROM mass_mail_audience_rows');

        $detail = $fixture['service']->findEmailDetailForMember($fixture['member_id'], $recipientId);
        $this->assertNotNull($detail);
        $this->assertTrue($detail['merge_purged']);
        $this->assertStringContainsString('{{Prenom}}', $detail['body_html']);

        // And the LIST says it too. It falls back to the same stored
        // template, so a line reading « Camp de {{Prenom}} » with nothing
        // beside it would be the very symptom this change removes — the
        // page behind it explaining itself is not enough.
        $listed = $fixture['service']->getRecentEmailsForMember($fixture['member_id'], 10);
        $this->assertTrue($listed[0]['merge_purged']);
        $this->assertStringContainsString('{{Prenom}}', $listed[0]['subject']);
    }

    /**
     * An ordinary list has nothing to substitute, so it has nothing to
     * have lost either — `merge_purged` must not turn into "this is an
     * old e-mail".
     */
    public function testAnOrdinaryListIsNeverReportedAsPurged(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        MassMailTestHelper::createTables($pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $recipientRepository = new DeliveryRepository($pdo, $encryption);
        $service = new MassMailQueryService(
            new TargetRepository($pdo),
            new AudienceRepository($pdo, $encryption),
            new MergeRenderer()
        );

        [$sectionId, $scoutYearId] = $this->seedSectionAndYear($pdo);
        $pdo->exec(
            "INSERT INTO mass_mail_emails (subject, body_html, section_id, list_type, status)
             VALUES ('Réunion', '<p>Bonjour.</p>', {$sectionId}, 'default_active_members', 'sent')"
        );
        $emailId = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK_ORD')");
        $memberId = (int) $pdo->lastInsertId();
        $recipientId = self::sentTo($pdo, $recipientRepository, $emailId, $memberId, $scoutYearId, ['a@test.be']);

        $detail = $service->findEmailDetailForMember($memberId, $recipientId);
        $this->assertNotNull($detail);
        $this->assertFalse($detail['merge_purged']);
        $this->assertSame('<p>Bonjour.</p>', $detail['body_html']);

        $this->assertFalse($service->getRecentEmailsForMember($memberId, 10)[0]['merge_purged']);
    }

    /**
     * One publipostage, two members, one row each.
     *
     * @return array{pdo: \PDO, service: MassMailQueryService, member_id: int, other_member_id: int}
     */
    private function mergeFixture(): array
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        MassMailTestHelper::createTables($pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $recipientRepository = new DeliveryRepository($pdo, $encryption);
        $audienceRepository = new AudienceRepository($pdo, $encryption);
        $service = new MassMailQueryService(new TargetRepository($pdo), $audienceRepository, new MergeRenderer());

        [$sectionId, $scoutYearId] = $this->seedSectionAndYear($pdo);

        $audienceId = $audienceRepository->createAudience('camp.xlsx', 'Camp', ['Prenom', 'Montant'], 2, null);
        $pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK_KAA')");
        $memberId = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK_EMMA')");
        $otherMemberId = (int) $pdo->lastInsertId();

        $kaaRow = $audienceRepository->createRow($audienceId, 2, $memberId, null,
            ['Prenom' => 'Kaa', 'Montant' => '145']);
        $emmaRow = $audienceRepository->createRow($audienceId, 3, $otherMemberId, null,
            ['Prenom' => 'Emma', 'Montant' => '80']);

        $pdo->exec(
            "INSERT INTO mass_mail_emails (subject, body_html, section_id, list_type, audience_id, status)
             VALUES ('Camp de {{Prenom}}', '<p>Cher {{Prenom}}, tu paieras {{Montant}} €.</p>',
                     {$sectionId}, 'mail_merge', {$audienceId}, 'sent')"
        );
        $emailId = (int) $pdo->lastInsertId();

        self::sentTo($pdo, $recipientRepository, $emailId, $memberId, $scoutYearId, ['kaa@test.be'], $kaaRow);
        self::sentTo($pdo, $recipientRepository, $emailId, $otherMemberId, $scoutYearId, ['emma@test.be'], $emmaRow);

        return [
            'pdo' => $pdo,
            'service' => $service,
            'member_id' => $memberId,
            'other_member_id' => $otherMemberId,
        ];
    }

    /**
     * One target of $memberId in $emailId and one message per address, each
     * with its status, linked to it — what the freeze and the batch leave.
     *
     * @param string[] $addresses
     * @param string[] $statuses one per address, 'sent' by default
     * @return int the target id
     */
    private static function sentTo(
        \PDO $pdo,
        DeliveryRepository $deliveries,
        int $emailId,
        int $memberId,
        ?int $scoutYearId,
        array $addresses,
        ?int $audienceRowId = null,
        array $statuses = []
    ): int {
        $targetId = (new TargetRepository($pdo))->create($emailId, $memberId, $scoutYearId, $audienceRowId);
        foreach ($addresses as $index => $address) {
            $status = $statuses[$index] ?? Delivery::STATUS_SENT;
            $deliveryId = $deliveries->create($emailId, $address, Delivery::STATUS_PENDING, null, null, $audienceRowId);
            if ($status === Delivery::STATUS_SENT) {
                $deliveries->recordSendSuccess($deliveryId);
            } elseif ($status === Delivery::STATUS_ERROR) {
                $deliveries->recordSendFailure($deliveryId, 'Boîte pleine');
            }
            $deliveries->linkTargets($deliveryId, [$targetId]);
        }

        return $targetId;
    }

    /**
     * @return array{pdo: \PDO, service: MassMailQueryService, deliveries: DeliveryRepository,
     *               section_id: int, year_id: int}
     */
    private function historyFixture(): array
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        MassMailTestHelper::createTables($pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        [$sectionId, $scoutYearId] = $this->seedSectionAndYear($pdo);

        return [
            'pdo' => $pdo,
            'service' => new MassMailQueryService(
                new TargetRepository($pdo),
                new AudienceRepository($pdo, $encryption),
                new MergeRenderer()
            ),
            'deliveries' => new DeliveryRepository($pdo, $encryption),
            'section_id' => $sectionId,
            'year_id' => $scoutYearId,
        ];
    }

    private static function email(\PDO $pdo, int $sectionId, string $subject, string $status = 'sent', string $listType = 'default_active_members'): int
    {
        $pdo->prepare(
            'INSERT INTO mass_mail_emails (subject, body_html, section_id, list_type, status) VALUES (?, ?, ?, ?, ?)'
        )->execute([$subject, '<p>x</p>', $sectionId, $listType, $status]);

        return (int) $pdo->lastInsertId();
    }

    private static function member(\PDO $pdo, string $deskId): int
    {
        $pdo->prepare('INSERT INTO members (desk_id) VALUES (?)')->execute([$deskId]);

        return (int) $pdo->lastInsertId();
    }

    /** Issue #999, §5: « 1 animé, 3 adresses » — three messages, ONE entry. */
    public function testThreeAddressesOfOneMemberAreOneEntry(): void
    {
        $f = $this->historyFixture();
        $alice = self::member($f['pdo'], 'ALICE');
        $email = self::email($f['pdo'], $f['section_id'], 'Week-end');
        self::sentTo($f['pdo'], $f['deliveries'], $email, $alice, $f['year_id'], ['a@x.be', 'b@x.be', 'c@x.be']);

        $entries = $f['service']->getRecentEmailsForMember($alice, 10);

        $this->assertCount(1, $entries);
        $this->assertFalse($entries[0]['not_sent']);
    }

    /**
     * §5: « 3 animés ciblés, même adresse familiale » — one message, an
     * entry on each of the three pages; and « Alice ciblée, Bob non ciblé,
     * adresse partagée » — nothing on Bob's.
     */
    public function testOneFamilyMessageIsAnEntryOnEveryTargetedPageAndOnNoOther(): void
    {
        $f = $this->historyFixture();
        $email = self::email($f['pdo'], $f['section_id'], 'Réunion');
        $targets = new TargetRepository($f['pdo']);
        $children = [self::member($f['pdo'], 'C1'), self::member($f['pdo'], 'C2'), self::member($f['pdo'], 'C3')];
        $notTargeted = self::member($f['pdo'], 'BOB');
        $family = $f['deliveries']->create($email, 'famille@x.be');
        $f['deliveries']->recordSendSuccess($family);
        $f['deliveries']->linkTargets($family, array_map(
            fn(int $child): int => $targets->create($email, $child, $f['year_id']),
            $children
        ));

        foreach ($children as $child) {
            $this->assertCount(1, $f['service']->getRecentEmailsForMember($child, 10));
        }
        $this->assertSame([], $f['service']->getRecentEmailsForMember($notTargeted, 10));
    }

    /**
     * §5: « animé ciblé sans aucune adresse autorisée » and « toutes en
     * erreur » — an entry « Non envoyée », with its content; and while a
     * message is still pending and none left, no entry yet.
     */
    public function testAnEntryIsNotSentWhenNothingLeftAndHiddenWhileStillGoing(): void
    {
        $f = $this->historyFixture();
        $member = self::member($f['pdo'], 'M');
        $noAddress = self::email($f['pdo'], $f['section_id'], 'Sans adresse');
        $allFailed = self::email($f['pdo'], $f['section_id'], 'En erreur');
        $going = self::email($f['pdo'], $f['section_id'], 'En cours', 'sending');
        $partly = self::email($f['pdo'], $f['section_id'], 'En partie', 'sending');
        self::sentTo($f['pdo'], $f['deliveries'], $noAddress, $member, $f['year_id'], []);
        self::sentTo($f['pdo'], $f['deliveries'], $allFailed, $member, $f['year_id'], ['a@x.be', 'b@x.be'], null, ['error', 'error']);
        self::sentTo($f['pdo'], $f['deliveries'], $going, $member, $f['year_id'], ['a@x.be', 'b@x.be'], null, ['error', 'pending']);
        self::sentTo($f['pdo'], $f['deliveries'], $partly, $member, $f['year_id'], ['a@x.be', 'b@x.be'], null, ['sent', 'pending']);

        $entries = $f['service']->getRecentEmailsForMember($member, 10);
        $bySubject = array_column($entries, 'not_sent', 'subject');

        ksort($bySubject);
        $this->assertSame(['En erreur' => true, 'En partie' => false, 'Sans adresse' => true], $bySubject);
        $this->assertArrayNotHasKey('En cours', $bySubject, 'still going out and nothing left: not shown yet');
        $this->assertCount(3, $entries);

        $notSent = array_values(array_filter($entries, fn(array $e) => $e['subject'] === 'En erreur'))[0];
        $detail = $f['service']->findEmailDetailForMember($member, $notSent['id']);
        $this->assertNotNull($detail);
        $this->assertTrue($detail['not_sent']);
        $this->assertSame('<p>x</p>', $detail['body_html']);
    }

    /** §5: « 2 campagnes séparées, mêmes destinataires et objet » — 2 entries. */
    public function testTwoCampaignsWithTheSameSubjectAreTwoEntries(): void
    {
        $f = $this->historyFixture();
        $member = self::member($f['pdo'], 'M');
        foreach ([1, 2] as $unused) {
            $email = self::email($f['pdo'], $f['section_id'], 'Même objet');
            self::sentTo($f['pdo'], $f['deliveries'], $email, $member, $f['year_id'], ['a@x.be']);
        }

        $this->assertCount(2, $f['service']->getRecentEmailsForMember($member, 10));
    }

    /** « Communications récentes » shows ten ENTRIES — copies never take two places. */
    public function testTheLimitCountsEntriesNotMessages(): void
    {
        $f = $this->historyFixture();
        $member = self::member($f['pdo'], 'M');
        for ($i = 1; $i <= 12; $i++) {
            $email = self::email($f['pdo'], $f['section_id'], 'Envoi ' . $i);
            self::sentTo($f['pdo'], $f['deliveries'], $email, $member, $f['year_id'], ['a@x.be', 'b@x.be', 'c@x.be']);
        }

        $entries = $f['service']->getRecentEmailsForMember($member, 10);

        $this->assertCount(10, $entries);
        $this->assertCount(10, array_unique(array_column($entries, 'subject')));
    }

    /**
     * §5: « 2 lignes pour le même Tiers » — two entries; and « concerne
     * aussi » is a classic list's: a line has one target, no candidates.
     */
    public function testTwoLinesForOneTiersAreTwoEntriesWithNobodyElseConcerned(): void
    {
        $f = $this->historyFixture();
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $audiences = new AudienceRepository($f['pdo'], $encryption);
        $member = self::member($f['pdo'], 'T');
        $audienceId = $audiences->createAudience('f.xlsx', 'F', ['Prenom'], 2, null);
        $email = self::email($f['pdo'], $f['section_id'], 'Cotisation {{Prenom}}', 'sent', 'mail_merge');
        $f['pdo']->exec("UPDATE mass_mail_emails SET audience_id = {$audienceId} WHERE id = {$email}");
        foreach (['Kaa', 'Baloo'] as $index => $name) {
            $row = $audiences->createRow($audienceId, $index + 2, $member, null, ['Prenom' => $name]);
            self::sentTo($f['pdo'], $f['deliveries'], $email, $member, $f['year_id'], ['p@x.be', 'm@x.be'], $row);
        }

        $entries = $f['service']->getRecentEmailsForMember($member, 10);

        $this->assertEqualsCanonicalizing(['Cotisation Kaa', 'Cotisation Baloo'], array_column($entries, 'subject'));
        $detail = $f['service']->findEmailDetailForMember($member, $entries[0]['id']);
        $this->assertSame([], $detail['other_targets'] ?? null);
    }

    /**
     * « Concerne aussi »: the other targets of a classic list, every one of
     * them with its year — the caller filters by canAccess(), never this.
     */
    public function testTheDetailListsTheOtherTargetsOfTheSameCommunication(): void
    {
        $f = $this->historyFixture();
        $email = self::email($f['pdo'], $f['section_id'], 'Week-end');
        $alice = self::member($f['pdo'], 'A');
        $bob = self::member($f['pdo'], 'B');
        $aliceTarget = self::sentTo($f['pdo'], $f['deliveries'], $email, $alice, $f['year_id'], ['a@x.be']);
        $bobTarget = self::sentTo($f['pdo'], $f['deliveries'], $email, $bob, null, ['b@x.be'], null, ['error']);
        // Still going out: not shown yet, here as on their own page.
        self::sentTo($f['pdo'], $f['deliveries'], $email, self::member($f['pdo'], 'C'), $f['year_id'], ['c@x.be'], null, ['pending']);

        $detail = $f['service']->findEmailDetailForMember($alice, $aliceTarget);

        $this->assertSame(
            [['target_id' => $bobTarget, 'member_id' => $bob, 'scout_year_id' => null]],
            $detail['other_targets'] ?? null
        );
        $this->assertNull($f['service']->findEmailDetailForMember($bob, $aliceTarget), 'not Bob\'s entry');
    }

    /**
     * @return array{0: int, 1: int} [section id, scout year id]
     */
    private function seedSectionAndYear(\PDO $pdo): array
    {
        $pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)");
        $scoutYearId = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('LOU', 'Louveteaux', 1)");
        $branchId = (int) $pdo->lastInsertId();
        $pdo->exec("INSERT INTO sections (desk_code, age_branch_id, name) VALUES ('LOU01', {$branchId}, 'Meute A')");

        return [(int) $pdo->lastInsertId(), $scoutYearId];
    }
}
