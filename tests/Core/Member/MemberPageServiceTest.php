<?php

declare(strict_types=1);

namespace Tests\Core\Member;

use Core\Badge\BadgeRepository;
use Core\Badge\MemberBadgeRepository;
use Core\Database\Connection;
use Core\Import\AgeBranchRepository;
use Core\Import\MemberYearRepository;
use Core\Member\MemberDocumentRepository;
use Core\Member\MemberDocumentService;
use Core\Member\MemberEmailRepository;
use Core\Member\MemberEmailService;
use Core\Member\MemberPageService;
use Core\Member\MemberProfile;
use Core\Member\MemberService;
use Core\Member\SectionService;
use Core\Module\SectionResponsableProvider;
use Core\Security\EncryptionService;
use Core\Security\Role;
use Modules\Calendar\Api\CalendarEventLookupInterface;
use Modules\Calendar\Api\EventSummary;
use Modules\Gallery\Api\GalleryAlbumProvider;
use Modules\MassMail\Api\MassMailQueryInterface;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
class MemberPageServiceTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private SectionService $sectionService;
    private MemberService $memberService;
    private BadgeRepository $badgeRepository;
    private MemberBadgeRepository $memberBadgeRepository;
    private AgeBranchRepository $ageBranchRepository;
    private MemberDocumentService $memberDocumentService;
    private MemberEmailService $memberEmailService;
    private int $scoutYearId;
    private int $sectionId;
    private int $branchId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $connection = Connection::withPdo($this->pdo);
        $this->memberBadgeRepository = new MemberBadgeRepository($this->pdo);
        $this->sectionService = new SectionService($connection, $this->encryption, $this->memberBadgeRepository);
        $this->memberService = new MemberService(new MemberYearRepository($this->pdo), $this->encryption, $connection);
        $this->badgeRepository = new BadgeRepository($this->pdo);
        $this->ageBranchRepository = new AgeBranchRepository($this->pdo);
        $this->memberDocumentService = new MemberDocumentService(new MemberDocumentRepository($this->pdo));
        $this->memberEmailService = new MemberEmailService(
            new MemberEmailRepository($this->pdo, $this->encryption),
            $this->createMock(\Core\Mail\MailService::class),
            $this->createMock(\Twig\Environment::class),
            $this->createMock(\Core\Journal\JournalService::class),
            $this->sectionService,
            $this->memberService,
            new \Core\Config\ScoutYearService($this->pdo),
            'https://example.test',
            'Test Unité'
        );

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('LOU', 'Louveteaux', 20)");
        $this->branchId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO sections (desk_code, age_branch_id, name, email) VALUES ('LOU01', {$this->branchId}, 'Meute A', 'meutea@example.com')");
        $this->sectionId = (int) $this->pdo->lastInsertId();
    }

    private function buildService(
        ?SectionResponsableProvider $responsableProvider = null,
        ?MassMailQueryInterface $massMailQuery = null,
        ?GalleryAlbumProvider $galleryAlbumProvider = null,
        ?CalendarEventLookupInterface $calendarEventLookup = null
    ): MemberPageService {
        return new MemberPageService(
            $this->sectionService,
            $this->memberService,
            $this->badgeRepository,
            $this->memberBadgeRepository,
            $this->ageBranchRepository,
            $this->memberDocumentService,
            $this->memberEmailService,
            $responsableProvider,
            $massMailQuery,
            $galleryAlbumProvider,
            $calendarEventLookup
        );
    }

    private function createMemberInSection(string $deskId = 'DESK1'): MemberProfile
    {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('{$deskId}')");
        $memberId = (int) $this->pdo->lastInsertId();
        $functionId = (int) $this->pdo->query("SELECT id FROM functions WHERE desk_code = 'ANIME'")->fetchColumn();
        if ($functionId === 0) {
            $this->pdo->exec("INSERT INTO functions (desk_code, label, role, confirmed) VALUES ('ANIME', 'Animé', 'identified', 1)");
            $functionId = (int) $this->pdo->lastInsertId();
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$memberId, $this->scoutYearId, $this->encryption->encrypt('Jean'), $this->encryption->encrypt('Dupont')]);
        $memberYearId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, section_id, is_main_function)
             VALUES (?, ?, ?, 1)'
        );
        $stmt->execute([$memberYearId, $functionId, $this->sectionId]);

        return $this->memberService->getMemberProfile($memberYearId);
    }

    public function testBranchCardIsNullWhenMemberHasNoSection(): void
    {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('NOSEC')");
        $memberId = (int) $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare('INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted) VALUES (?, ?, ?, ?)');
        $stmt->execute([$memberId, $this->scoutYearId, $this->encryption->encrypt('No'), $this->encryption->encrypt('Section')]);
        $memberYearId = (int) $this->pdo->lastInsertId();
        $profile = $this->memberService->getMemberProfile($memberYearId);

        $data = $this->buildService()->buildPageData($profile, $this->scoutYearId, true, false, Role::IDENTIFIED);

        $this->assertNull($data['branch_card']);
        $this->assertNull($data['section_info']);
    }

    public function testBranchCardFallsBackToShippedDefaultLogoWhenNoneUploaded(): void
    {
        $profile = $this->createMemberInSection();

        $data = $this->buildService()->buildPageData($profile, $this->scoutYearId, true, false, Role::IDENTIFIED);

        $this->assertNotNull($data['branch_card']);
        $this->assertSame('Louveteaux', $data['branch_card']['label']);
        $this->assertNull($data['branch_card']['logo_file_id']);
        $this->assertSame('logo_louveteaux.png', $data['branch_card']['default_logo']);
        $this->assertSame('https://lesscouts.be/fr/site-parents/le-parcours-scout', $data['branch_card']['explanation_url']);
    }

    public function testBranchCardUsesUploadedLogoAndCustomUrlWhenSet(): void
    {
        $profile = $this->createMemberInSection();
        $this->pdo->exec("INSERT INTO files (relative_path, original_name, mime_type, size_bytes, role_min) VALUES ('logo.png', 'logo.png', 'image/png', 10, 'public')");
        $fileId = (int) $this->pdo->lastInsertId();
        $this->ageBranchRepository->setLogo($this->branchId, $fileId);
        $this->ageBranchRepository->setExplanationUrl($this->branchId, 'https://example.test/louveteaux');

        $data = $this->buildService()->buildPageData($profile, $this->scoutYearId, true, false, Role::IDENTIFIED);

        $this->assertSame($fileId, $data['branch_card']['logo_file_id']);
        $this->assertSame('https://example.test/louveteaux', $data['branch_card']['explanation_url']);
    }

    public function testBranchCardHasNoDefaultLogoForAnUnrecognizedBranch(): void
    {
        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('MYSTERY', 'Mystery', 99)");
        $staffBranchId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO sections (desk_code, age_branch_id, name) VALUES ('MYSTERY', {$staffBranchId}, 'Mystery')");
        $staffSectionId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('CHIEF1')");
        $memberId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO functions (desk_code, label, role, confirmed) VALUES ('CHEF', 'Chef', 'admin', 1)");
        $functionId = (int) $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare('INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted) VALUES (?, ?, ?, ?)');
        $stmt->execute([$memberId, $this->scoutYearId, $this->encryption->encrypt('Chief'), $this->encryption->encrypt('One')]);
        $memberYearId = (int) $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare('INSERT INTO member_functions (member_year_id, function_id, section_id, is_main_function) VALUES (?, ?, ?, 1)');
        $stmt->execute([$memberYearId, $functionId, $staffSectionId]);
        $profile = $this->memberService->getMemberProfile($memberYearId);

        $data = $this->buildService()->buildPageData($profile, $this->scoutYearId, true, false, Role::ADMIN);

        $this->assertNotNull($data['branch_card']);
        $this->assertNull($data['branch_card']['logo_file_id']);
        $this->assertNull($data['branch_card']['default_logo']);
    }

    public function testSectionInfoIncludesSectionNameAndEmail(): void
    {
        $profile = $this->createMemberInSection();

        $data = $this->buildService()->buildPageData($profile, $this->scoutYearId, true, false, Role::IDENTIFIED);

        $this->assertSame('Meute A', $data['section_info']['section']['name']);
        $this->assertSame('meutea@example.com', $data['section_info']['section']['email']);
    }

    public function testSectionInfoResponsableIsNullWhenNoProviderWired(): void
    {
        $profile = $this->createMemberInSection();

        $data = $this->buildService(null)->buildPageData($profile, $this->scoutYearId, true, false, Role::IDENTIFIED);

        $this->assertNull($data['section_info']['responsable']);
        $this->assertFalse($data['trombinoscope_enabled']);
    }

    public function testSectionInfoResolvesResponsableWithRealAddress(): void
    {
        $lead = $this->createMemberInSection('LEAD1');
        // Give the lead a postal address — hydrateMemberProfile() (which
        // backs every SectionResponsableProvider implementation) never
        // loads addresses, so MemberPageService must re-resolve via
        // MemberService::findProfileByMemberAndYear() to get this.
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_addresses (member_year_id, address_type, street_encrypted, number_encrypted, postal_code_encrypted, city_encrypted, country_encrypted)
             VALUES (?, "home", ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $lead->memberYearId,
            $this->encryption->encrypt('Rue de la Station'),
            $this->encryption->encrypt('12'),
            $this->encryption->encrypt('5000'),
            $this->encryption->encrypt('Namur'),
            $this->encryption->encrypt('Belgique'),
        ]);

        $provider = $this->createMock(SectionResponsableProvider::class);
        $provider->method('getResponsable')->willReturn($lead);

        $profile = $this->createMemberInSection('MEMBER2');
        $data = $this->buildService($provider)->buildPageData($profile, $this->scoutYearId, true, false, Role::IDENTIFIED);

        $this->assertNotNull($data['section_info']['responsable']);
        $this->assertCount(1, $data['section_info']['responsable']->addresses);
        $this->assertTrue($data['trombinoscope_enabled']);
    }

    public function testSectionInfoIncludesBadgeAssignmentsFromSectionStaff(): void
    {
        $this->pdo->exec("INSERT INTO functions (desk_code, label, role, confirmed) VALUES ('CHEF', 'Chef', 'chief', 1)");
        $chiefFunctionId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('CHIEF2')");
        $chiefMemberId = (int) $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare('INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, totem_encrypted) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$chiefMemberId, $this->scoutYearId, $this->encryption->encrypt('Marie'), $this->encryption->encrypt('Curie'), $this->encryption->encrypt('Loutre')]);
        $chiefMemberYearId = (int) $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare('INSERT INTO member_functions (member_year_id, function_id, section_id, is_main_function) VALUES (?, ?, ?, 1)');
        $stmt->execute([$chiefMemberYearId, $chiefFunctionId, $this->sectionId]);

        $badgeId = $this->badgeRepository->create('Trésorier', false);
        $this->memberBadgeRepository->assign($chiefMemberYearId, $badgeId, null);

        $profile = $this->createMemberInSection();
        $data = $this->buildService()->buildPageData($profile, $this->scoutYearId, true, false, Role::IDENTIFIED);

        $this->assertArrayHasKey('Trésorier', $data['section_info']['badge_assignments']);
        $holders = $data['section_info']['badge_assignments']['Trésorier'];
        $this->assertCount(1, $holders);
        $this->assertSame('Loutre', $holders[0]->getDisplayName());
    }

    public function testSectionInfoIncludesReferentHolders(): void
    {
        $referentBadgeId = $this->badgeRepository->create('Référent Meute A', false, $this->sectionId);

        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('STAFFDU2', 'Staff dU', 50)");
        $staffBranchId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO sections (desk_code, age_branch_id, name) VALUES ('STAFFDU2', {$staffBranchId}, 'Staff dU')");

        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('REFERENT1')");
        $referentMemberId = (int) $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare('INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, totem_encrypted) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$referentMemberId, $this->scoutYearId, $this->encryption->encrypt('Referent'), $this->encryption->encrypt('One'), $this->encryption->encrypt('Aigle')]);
        $referentMemberYearId = (int) $this->pdo->lastInsertId();
        $this->memberBadgeRepository->assign($referentMemberYearId, $referentBadgeId, null);

        $profile = $this->createMemberInSection();
        $data = $this->buildService()->buildPageData($profile, $this->scoutYearId, true, false, Role::IDENTIFIED);

        $referentHolders = $data['section_info']['referent_holders'];
        $this->assertCount(1, $referentHolders);
        $this->assertSame('Aigle', $referentHolders[0]->getDisplayName());
    }

    public function testSectionInfoIncludesNextEventWhenCalendarEnabled(): void
    {
        $event = new EventSummary(1, 'Grand jeu', 'Meute A', '2099-01-01', '2099-01-01');
        $lookup = $this->createMock(CalendarEventLookupInterface::class);
        $lookup->method('findEventsInWindow')->willReturn([$event]);

        $profile = $this->createMemberInSection();
        $data = $this->buildService(null, null, null, $lookup)->buildPageData($profile, $this->scoutYearId, true, false, Role::IDENTIFIED);

        $this->assertNotNull($data['section_info']['next_event']);
        $this->assertSame('Grand jeu', $data['section_info']['next_event']->title);
        $this->assertTrue($data['calendar_enabled']);
    }

    public function testSectionInfoNextEventIsNullWhenCalendarDisabled(): void
    {
        $profile = $this->createMemberInSection();
        $data = $this->buildService()->buildPageData($profile, $this->scoutYearId, true, false, Role::IDENTIFIED);

        $this->assertNull($data['section_info']['next_event']);
        $this->assertFalse($data['calendar_enabled']);
    }

    public function testMassMailIsEmptyWhenNeitherSelfNorChief(): void
    {
        $massMailQuery = $this->createMock(MassMailQueryInterface::class);
        $massMailQuery->expects($this->never())->method('getRecentEmailsForMember');

        $profile = $this->createMemberInSection();
        $data = $this->buildService(null, $massMailQuery)->buildPageData($profile, $this->scoutYearId, false, false, Role::IDENTIFIED);

        $this->assertSame([], $data['recent_mass_mail_emails']);
    }

    public function testMassMailIsPopulatedForSelf(): void
    {
        $massMailQuery = $this->createMock(MassMailQueryInterface::class);
        $massMailQuery->method('getRecentEmailsForMember')->willReturn([['id' => 1, 'subject' => 'Sujet', 'sent_at' => '2026-01-01', 'section_name' => 'Meute A']]);

        $profile = $this->createMemberInSection();
        $data = $this->buildService(null, $massMailQuery)->buildPageData($profile, $this->scoutYearId, true, false, Role::IDENTIFIED);

        $this->assertCount(1, $data['recent_mass_mail_emails']);
    }

    public function testMassMailIsPopulatedForChiefEvenWhenNotSelf(): void
    {
        $massMailQuery = $this->createMock(MassMailQueryInterface::class);
        $massMailQuery->method('getRecentEmailsForMember')->willReturn([['id' => 1, 'subject' => 'Sujet', 'sent_at' => '2026-01-01', 'section_name' => 'Meute A']]);

        $profile = $this->createMemberInSection();
        $data = $this->buildService(null, $massMailQuery)->buildPageData($profile, $this->scoutYearId, false, true, Role::CHIEF);

        $this->assertCount(1, $data['recent_mass_mail_emails']);
    }

    public function testMemberDocumentsOnlyShownToSelfNeverToChief(): void
    {
        $profile = $this->createMemberInSection();
        $this->pdo->exec("INSERT INTO files (relative_path, original_name, mime_type, size_bytes, role_min, owner_member_id) VALUES ('doc.pdf.enc', 'a.pdf', 'application/pdf', 10, 'identified', {$profile->memberId})");
        $fileId = (int) $this->pdo->lastInsertId();
        (new MemberDocumentRepository($this->pdo))->create($profile->memberId, $this->scoutYearId, 'Attestation', $fileId, null);

        $dataAsSelf = $this->buildService()->buildPageData($profile, $this->scoutYearId, true, false, Role::IDENTIFIED);
        $this->assertCount(1, $dataAsSelf['member_documents']);

        $dataAsChief = $this->buildService()->buildPageData($profile, $this->scoutYearId, false, true, Role::CHIEF);
        $this->assertSame([], $dataAsChief['member_documents']);
    }

    public function testGalleryAlbumsDegradesToEmptyWhenProviderIsNull(): void
    {
        $profile = $this->createMemberInSection();
        $data = $this->buildService()->buildPageData($profile, $this->scoutYearId, true, false, Role::IDENTIFIED);

        $this->assertSame([], $data['gallery_albums']);
    }

    public function testGalleryAlbumsPopulatedWhenProviderProvided(): void
    {
        $provider = $this->createMock(GalleryAlbumProvider::class);
        $provider->expects($this->once())
            ->method('getAlbumsForMember')
            ->with(['LOU01'], '2025-2026', 6)
            ->willReturn([['id' => 1, 'title' => 'Camp', 'album_date' => '2026-01-01', 'cover_url' => null, 'url' => '/gallery/1']]);

        $profile = $this->createMemberInSection();
        $data = $this->buildService(null, null, $provider)->buildPageData($profile, $this->scoutYearId, true, false, Role::IDENTIFIED);

        $this->assertCount(1, $data['gallery_albums']);
    }

    /**
     * mass_mail_enabled/gallery_enabled let the template tell "module
     * enabled, nothing to show yet" (render the card with an empty-state
     * message) apart from "module disabled" (don't render the card at
     * all) — a plain empty array can't distinguish the two.
     */
    public function testMassMailAndGalleryEnabledFlagsReflectWhetherTheirProviderIsWired(): void
    {
        $profile = $this->createMemberInSection();

        $dataDisabled = $this->buildService()->buildPageData($profile, $this->scoutYearId, true, false, Role::IDENTIFIED);
        $this->assertFalse($dataDisabled['mass_mail_enabled']);
        $this->assertFalse($dataDisabled['gallery_enabled']);

        $massMailQuery = $this->createMock(MassMailQueryInterface::class);
        $massMailQuery->method('getRecentEmailsForMember')->willReturn([]);
        $galleryProvider = $this->createMock(GalleryAlbumProvider::class);
        $galleryProvider->method('getAlbumsForMember')->willReturn([]);

        $dataEnabled = $this->buildService(null, $massMailQuery, $galleryProvider)->buildPageData($profile, $this->scoutYearId, true, false, Role::IDENTIFIED);
        $this->assertTrue($dataEnabled['mass_mail_enabled']);
        $this->assertTrue($dataEnabled['gallery_enabled']);
        $this->assertSame([], $dataEnabled['recent_mass_mail_emails']);
        $this->assertSame([], $dataEnabled['gallery_albums']);
    }
}
