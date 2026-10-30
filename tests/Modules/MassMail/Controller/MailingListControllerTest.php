<?php

declare(strict_types=1);

namespace Tests\Modules\MassMail\Controller;

use Core\Badge\BadgeRepository;
use Core\Badge\BadgeService;
use Core\Badge\MemberBadgeRepository;
use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Http\Request;
use Tests\RequestWithInput;
use Core\Import\FunctionRepository;
use Core\Import\MemberYearRepository;
use Core\Member\SectionService;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\EncryptionService;
use Modules\MassMail\Controller\MailingListController;
use Modules\MassMail\Repository\ContactRepository;
use Modules\MassMail\Repository\ListBlockRepository;
use Modules\MassMail\Repository\MailingListRepository;
use Modules\MassMail\Repository\MemberResolutionRepository;
use Modules\MassMail\Repository\SuppressedAddressRepository;
use Modules\MassMail\Service\ContactGroupImportService;
use Modules\MassMail\Service\ListBlockService;
use Modules\MassMail\Service\SendableScoutYears;
use Modules\MassMail\Service\MailingListService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\MassMail\MassMailTestHelper;
use Twig\Environment;
use Core\Member\Repository\MemberProfileRepository;
use Core\Member\Repository\SectionRepository;

/**
 * What the mailing lists page and its JSON endpoints actually answer.
 *
 * `MailingListRbacTest` drives the same routes through the real
 * Router/RbacGuard and asks only who gets past the guard; this one calls
 * the actions and asks what they do — which list was created, what a
 * refusal says, and what is in the database afterwards. The page moved
 * menu and floor without changing any of that, and that is precisely the
 * claim a move has to be able to make.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class MailingListControllerTest extends TestCase
{
    private \PDO $pdo;
    private MailingListController $controller;
    private MailingListService $listService;
    private int $accountId;
    private int $sectionId;
    private int $functionId;
    private int $badgeId;
    private ScoutYearResolver $scoutYearResolver;
    private ListBlockService $blockService;
    private ContactGroupImportService $contactGroups;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        MassMailTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute([
            $encryption->encrypt('cu@test.com', 'user_accounts.email'),
            $encryption->blindIndex('cu@test.com', 'email'),
        ]);
        $this->accountId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('LOU', 'Louveteaux', 1)");
        $branchId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec(
            "INSERT INTO sections (desk_code, age_branch_id, name, is_active) VALUES ('LOU01', {$branchId}, 'Meute', 1)"
        );
        $this->sectionId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO functions (desk_code, label, role) VALUES ('INT', 'Intendant', 'intendant')");
        $this->functionId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO badges (name, is_default) VALUES ('Infirmier', 1)");
        $this->badgeId = (int) $this->pdo->lastInsertId();

        $sectionService = new SectionService(
    new SectionRepository(Connection::withPdo($this->pdo)),
    new MemberProfileRepository(Connection::withPdo($this->pdo), $encryption, new MemberBadgeRepository($this->pdo))
);
        $this->listService = new MailingListService(
            new MailingListRepository($this->pdo),
            new MemberResolutionRepository($this->pdo, $encryption),
            $sectionService,
            new FunctionRepository($this->pdo),
            new BadgeService(new BadgeRepository($this->pdo), new MemberBadgeRepository($this->pdo), $sectionService),
            new ListBlockRepository($this->pdo),
            new ContactRepository($this->pdo, $encryption)
        );
        $this->blockService = new ListBlockService(
            new ListBlockRepository($this->pdo),
            new ContactRepository($this->pdo, $encryption),
            new MailingListRepository($this->pdo),
            $this->createStub(\Core\Journal\JournalService::class)
        );
        $this->scoutYearResolver = new ScoutYearResolver(
            new ScoutYearService($this->pdo),
            new SettingService(new SettingRepository($this->pdo)),
            new MemberYearRepository($this->pdo)
        );
        $this->contactGroups = new ContactGroupImportService(
            new ListBlockRepository($this->pdo),
            new ContactRepository($this->pdo, $encryption),
            $this->blockService,
            new SettingService(new SettingRepository($this->pdo)),
            $this->createStub(\Core\Journal\JournalService::class),
            new SuppressedAddressRepository($this->pdo)
        );
        $this->controller = $this->controller($this->createStub(Environment::class));

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        AuthSession::login($this->accountId, 'cu@test.com', 'admin');
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
    }

    /**
     * The page only summarises a list: what it is made of, counted per
     * kind of block for the whole page at once, and nothing decrypted.
     */
    public function testIndexHandsTheTemplateWhatEachListIsMadeOf(): void
    {
        $list = $this->list('Les intendants');
        $this->blockService->createMemberGroup($list->id, [], [], [$this->badgeId], null);
        $empty = $this->list('Vide');

        $twig = $this->createStub(Environment::class);
        $captured = null;
        $twig->method('render')->willReturnCallback(
            function (string $name, array $context) use (&$captured): string {
                $captured = $context;
                return '';
            }
        );

        $response = $this->controller($twig)
            ->index(new Request('GET', '/admin/listes-de-diffusion', [], [], [], []), []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertIsArray($captured);
        $this->assertCount(2, $captured['custom_lists']);
        $this->assertSame(
            [$list->id => ['member_group' => 1, 'contact_group' => 0]],
            $captured['block_counts']
        );
        $this->assertArrayNotHasKey($empty->id, $captured['block_counts']);
        // A count per list that has a block — the send's own number — and
        // none for a list without one, which designates nobody.
        $this->assertSame([$list->id => 0], $captured['recipient_counts']);
        $this->assertNotSame('', $captured['effective_year_label']);
        $this->assertNotSame([], $captured['default_lists']);
        $this->assertNotSame('', $captured['csrf_token']);
    }

    /** The page renders those counts as a sentence, a list without blocks included. */
    public function testThePageSaysWhatEachListIsMadeOf(): void
    {
        $list = $this->list('Staff élargi');
        $this->blockService->createMemberGroup($list->id, [$this->functionId], [], [], null);
        $this->blockService->createMemberGroup($list->id, [], [], [$this->badgeId], null);
        $this->blockService->createMemberGroup($this->list('Secouristes')->id, [], [], [$this->badgeId], null);
        $this->list('Vide');

        $html = $this->controller(\Tests\TestTwig::create(['mass_mail']))
            ->index(new Request('GET', '/admin/listes-de-diffusion', [], [], [], []), [])
            ->getBody();

        $this->assertStringContainsString('2 groupes de membres', $html);
        $this->assertStringContainsString('1 groupe de membres', $html);
        $this->assertStringContainsString('Aucun groupe : cette liste ne désigne personne.', $html);
        $this->assertStringNotContainsString('cfg-list-modal', $html);
        // Each card leads to the list's page, and says how many it reaches.
        $this->assertStringContainsString('href="/admin/listes-de-diffusion/' . $list->id . '"', $html);
        $this->assertStringContainsString('≈ 0 destinataire en ', $html);
        $this->assertStringContainsString('aria-label="Supprimer la liste Staff élargi"', $html);
        // « Nouvelle liste » and its two-field dialog.
        $this->assertStringContainsString('Nouvelle liste', $html);
        $this->assertStringContainsString('id="mm-list-identity-modal"', $html);
        $this->assertStringContainsString('data-list-identity-save', $html);
    }

    public function testTheAdminPageOffersOneConfirmedAddressWideRestoration(): void
    {
        $twig = \Tests\TestTwig::create(['mass_mail']);
        $html = $this->controller($twig)->index(new Request('GET', '/admin/listes-de-diffusion', [], [], [], []), [])
            ->getBody();
        $doc = new \DOMDocument();
        @$doc->loadHTML($html);
        $xpath = new \DOMXPath($doc);
        $forms = $xpath->query('//form[@action="/admin/listes-de-diffusion/adresses/reactivate"]');
        $this->assertCount(1, $forms);
        $this->assertStringContainsString('Le titulaire a demandé', $forms->item(0)->getAttribute('data-confirm'));
        $this->assertSame('Réactiver', $forms->item(0)->getAttribute('data-confirm-label'));
        $this->assertCount(1, $xpath->query(
            './/input[@name="email" and @type="email" and @required]', $forms->item(0)
        ));
        $this->assertCount(1, $xpath->query('.//input[@name="_csrf_token"]', $forms->item(0)));
    }

    public function testShowAnswersNotFoundForAnUnknownList(): void
    {
        $response = $this->controller->show(new Request('GET', '/admin/listes-de-diffusion/999', [], [], [], []), [
            'id' => '999',
        ]);

        $this->assertSame(404, $response->getStatusCode());
    }

    /**
     * The page of a list: its name as the title and the trail's last
     * step, the blocks handed to the script in an island, and the hooks
     * the contact-group dialog opens from — present whether that dialog
     * is on the page or not.
     */
    public function testShowRendersTheListPageWithItsBlocksAndDialogs(): void
    {
        $list = $this->list('Staff élargi');
        $this->blockService->createMemberGroup($list->id, [$this->functionId], [], [], null);
        $this->contactGroup($list->id, 'Partenaires', ['commune@example.be']);

        $html = $this->controller(\Tests\TestTwig::create(['mass_mail']))
            ->show(new Request('GET', '/admin/listes-de-diffusion/' . $list->id, [], [], [], []), [
                'id' => (string) $list->id,
            ])->getBody();

        $this->assertStringContainsString('<h1 class="h3 mb-0">Staff élargi</h1>', $html);
        $this->assertStringContainsString('aria-label="Modifier le nom et la description"', $html);
        $this->assertStringContainsString('id="mm-list-data"', $html);
        $this->assertStringContainsString('Ajouter des destinataires', $html);
        $this->assertStringContainsString('Que voulez-vous ajouter ?', $html);
        // The creation hook of the contract with the contact-group dialog:
        // [data-contact-group-open] without a data-block-id.
        $this->assertMatchesRegularExpression('/<button[^>]*data-contact-group-open>/', $html);
        // The member-group dialog's three axes read « Peu importe » empty,
        // and « Enregistrer » starts disabled — a group needs one choice.
        $this->assertStringContainsString('Peu importe', $html);
        $this->assertStringNotContainsString('Toutes les fonctions', $html);
        $this->assertStringContainsString('data-member-group-save disabled', $html);
        $this->assertStringContainsString('Rechercher un destinataire', $html);

        preg_match('#<script type="application/json" id="mm-list-data">(.*?)</script>#s', $html, $island);
        $data = json_decode($island[1] ?? '', true);
        $this->assertIsArray($data);
        $this->assertSame($list->id, $data['list_id']);
        $this->assertSame(['member_group', 'contact_group'], array_column($data['blocks']['blocks'], 'kind'));
    }

    public function testShowNamesTheListInTheBreadcrumb(): void
    {
        $list = $this->list('Secouristes');
        $twig = $this->createStub(Environment::class);
        $captured = null;
        $twig->method('render')->willReturnCallback(
            function (string $name, array $context) use (&$captured): string {
                $captured = $context;
                return '';
            }
        );

        $this->controller($twig)->show(new Request('GET', '/', [], [], [], []), ['id' => (string) $list->id]);

        $this->assertIsArray($captured);
        $this->assertSame('Secouristes', $captured['breadcrumb_current']);
        $this->assertNotSame([], $captured['recipient_years']);
        $this->assertContains($captured['effective_year_id'], array_column($captured['recipient_years'], 'id'));
    }

    public function testUpdateListRenamesAndRedescribes(): void
    {
        $list = $this->list('Ancien nom');

        $payload = $this->call('updateList', ['id' => (string) $list->id], [
            'name' => 'Nouveau nom',
            'description' => 'Une autre description.',
        ]);

        $this->assertTrue($payload['success']);
        $this->assertSame('Nouveau nom', $this->listService->getCustomListById($list->id)?->name);
        $this->assertSame('Une autre description.', $this->listService->getCustomListById($list->id)?->description);
    }

    public function testUpdateListRefusesAnEmptyNameAndKeepsTheOldOne(): void
    {
        $list = $this->list('Gardé');

        $response = $this->controller->updateList(
            $this->jsonRequest(['name' => ' ', 'description' => 'x', '_csrf_token' => CsrfGuard::generateToken()]),
            ['id' => (string) $list->id]
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('Le nom de la liste est obligatoire.', json_decode($response->getBody(), true)['error']);
        $this->assertSame('Gardé', $this->listService->getCustomListById($list->id)?->name);
    }

    /**
     * What the page redraws from: the total and split, then each block —
     * a member group with its sentence in segments and the ids its dialog
     * reopens with, a contact group with its name, its counts and the
     * date its file was applied.
     */
    public function testBlocksAnswersTheTotalAndEveryBlockOfBothKinds(): void
    {
        $list = $this->list('Staff élargi');
        $memberGroup = $this->blockService->createMemberGroup($list->id, [$this->functionId], [], [], null);
        $contactGroupId = $this->contactGroup($list->id, 'Partenaires', ['a@example.be', 'b@example.be']);
        $this->blockService->unsubscribeEverywhere('b@example.be');

        $response = $this->controller->blocks(new Request('GET', '/', [], [], [], []), ['id' => (string) $list->id]);
        $payload = json_decode($response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($payload['success']);
        $this->assertSame(
            ['total' => 1, 'members' => 0, 'contacts' => 2, 'contacts_unsubscribed' => 1],
            $payload['summary']
        );
        $this->assertNotSame('', $payload['scout_year_label']);

        [$member, $contact] = $payload['blocks'];
        $this->assertSame($memberGroup->id, $member['id']);
        $this->assertSame('member_group', $member['kind']);
        $this->assertNull($member['name']);
        $this->assertSame([
            ['text' => 'Les membres qui ont la fonction ', 'strong' => false],
            ['text' => 'Intendant', 'strong' => true],
            ['text' => '.', 'strong' => false],
        ], $member['sentence']);
        $this->assertSame([$this->functionId], $member['function_ids']);
        $this->assertSame([], $member['section_ids']);
        $this->assertSame(0, $member['count']);

        $this->assertSame($contactGroupId, $contact['id']);
        $this->assertSame('contact_group', $contact['kind']);
        $this->assertSame('Partenaires', $contact['name']);
        $this->assertSame([], $contact['sentence']);
        $this->assertSame(2, $contact['count']);
        $this->assertSame(1, $contact['unsubscribed']);
        $this->assertNotNull($contact['file_replaced_at']);
        $this->assertMatchesRegularExpression('/^\d{1,2} \p{L}+ \d{4}$/u', (string) $contact['file_replaced_label']);
    }

    public function testBlocksAnswersNotFoundForAnUnknownList(): void
    {
        $response = $this->controller->blocks(new Request('GET', '/', [], [], [], []), ['id' => '999']);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testCreateMemberGroupAddsAGroupToTheList(): void
    {
        $list = $this->list('Les intendants');

        $payload = $this->call('createMemberGroup', ['id' => (string) $list->id], [
            'function_ids' => [$this->functionId],
            'section_ids' => [],
            'badge_ids' => [$this->badgeId],
        ]);

        $this->assertTrue($payload['success']);
        $this->assertSame('member_group', $payload['block']['kind']);
        $blocks = $this->blockService->findForList($list->id);
        $this->assertCount(1, $blocks);
        $this->assertSame([$this->functionId], $blocks[0]->functionIds);
        $this->assertSame([$this->badgeId], $blocks[0]->badgeIds);
    }

    /** « Peu importe » three times is no group: refused, nothing written. */
    public function testCreateMemberGroupRefusesAGroupWithoutAnyChoice(): void
    {
        $list = $this->list('Vide');

        $response = $this->controller->createMemberGroup(
            $this->jsonRequest(['function_ids' => [], '_csrf_token' => CsrfGuard::generateToken()]),
            ['id' => (string) $list->id]
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(
            'Choisissez au moins une fonction, une section ou un badge.',
            json_decode($response->getBody(), true)['error']
        );
        $this->assertSame([], $this->blockService->findForList($list->id));
    }

    public function testUpdateMemberGroupReplacesItsAxes(): void
    {
        $list = $this->list('Groupe');
        $block = $this->blockService->createMemberGroup($list->id, [$this->functionId], [], [], null);

        $this->assertTrue($this->call('updateMemberGroup', ['id' => (string) $block->id], [
            'function_ids' => [],
            'section_ids' => [$this->sectionId],
            'badge_ids' => [],
        ])['success']);

        $updated = $this->blockService->findById($block->id);
        $this->assertSame([], $updated?->functionIds);
        $this->assertSame([$this->sectionId], $updated?->sectionIds);
    }

    /** A contact group changes by its file only — never through this route. */
    public function testUpdateMemberGroupRefusesAContactGroup(): void
    {
        $list = $this->list('Contacts');
        $contactGroupId = $this->contactGroup($list->id, 'Partenaires', ['a@example.be']);

        $response = $this->controller->updateMemberGroup(
            $this->jsonRequest(['function_ids' => [$this->functionId], '_csrf_token' => CsrfGuard::generateToken()]),
            ['id' => (string) $contactGroupId]
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame([], $this->blockService->findById($contactGroupId)?->functionIds);
    }

    /** One route removes a block of either kind. */
    public function testDeleteBlockRemovesBothKinds(): void
    {
        $list = $this->list('Deux blocs');
        $memberGroup = $this->blockService->createMemberGroup($list->id, [$this->functionId], [], [], null);
        $contactGroupId = $this->contactGroup($list->id, 'Partenaires', ['a@example.be']);

        $this->assertTrue($this->call('deleteBlock', ['id' => (string) $memberGroup->id], [])['success']);
        $this->assertTrue($this->call('deleteBlock', ['id' => (string) $contactGroupId], [])['success']);

        $this->assertSame([], $this->blockService->findForList($list->id));
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM mass_mail_block_contacts')->fetchColumn());
    }

    public function testDeleteBlockRefusesAnUnknownBlock(): void
    {
        $response = $this->controller->deleteBlock(
            $this->jsonRequest(['_csrf_token' => CsrfGuard::generateToken()]),
            ['id' => '999']
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('Groupe introuvable.', json_decode($response->getBody(), true)['error']);
    }

    /**
     * « Voir les destinataires »: one row per person who would receive the
     * e-mail, the blocks that bring them, and a label per block for the
     * tags — never an unsubscribed address.
     */
    public function testRecipientsListsWhoReceivesAndWhichBlockBringsThem(): void
    {
        $list = $this->list('Partenaires');
        $memberGroup = $this->blockService->createMemberGroup($list->id, [$this->functionId], [], [], null);
        $contactGroupId = $this->contactGroup($list->id, 'Commune', ['commune@example.be', 'parti@example.be']);
        $this->blockService->unsubscribeEverywhere('parti@example.be');

        $response = $this->controller->recipients(
            new Request('GET', '/', [], [], [], []),
            ['id' => (string) $list->id]
        );
        $payload = json_decode($response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($payload['success']);
        $this->assertNotSame('', $payload['scout_year_label']);
        $this->assertSame(['commune@example.be'], array_column($payload['recipients'], 'email'));
        $this->assertSame([$contactGroupId], $payload['recipients'][0]['block_ids']);
        $this->assertSame([
            $memberGroup->id => ['kind' => 'member_group', 'label' => 'Intendant'],
            $contactGroupId => ['kind' => 'contact_group', 'label' => 'Commune'],
        ], $payload['blocks']);
    }

    /** Only a year an e-mail can target: anything else is refused, not resolved. */
    public function testRecipientsRefusesAYearNoEmailCanTarget(): void
    {
        $list = $this->list('Partenaires');
        $this->pdo->exec(
            "INSERT INTO scout_years (label, start_date, end_date, is_current)
             VALUES ('1990-1991', '1990-09-01', '1991-08-31', 0)"
        );
        $oldYearId = (int) $this->pdo->lastInsertId();

        $response = $this->controller->recipients(
            new Request('GET', '/', ['year' => (string) $oldYearId], [], [], []),
            ['id' => (string) $list->id]
        );

        $this->assertSame(422, $response->getStatusCode());
    }

    public function testRecipientsAnswersNotFoundForAnUnknownList(): void
    {
        $response = $this->controller->recipients(new Request('GET', '/', [], [], [], []), ['id' => '999']);

        $this->assertSame(404, $response->getStatusCode());
    }

    /**
     * A new list is a name and a description; criteria sent along are
     * not a list's any more and create no block.
     */
    public function testCreateListStoresANameAndADescriptionAndNoBlock(): void
    {
        $payload = $this->call('createList', [], [
            'name' => 'Les intendants',
            'description' => 'Tous les intendants de la meute.',
            'function_ids' => [$this->functionId],
        ]);

        $this->assertTrue($payload['success']);
        $this->assertSame('Les intendants', $payload['list']['name']);
        $this->assertTrue($payload['list']['is_active']);

        $listId = (int) $payload['list']['id'];
        $this->assertSame(
            'Tous les intendants de la meute.',
            $this->listService->getCustomListById($listId)?->description
        );
        $this->assertSame([], $this->blockService->findForList($listId));
    }

    /**
     * The refusal a chef d'unité reads has to be the service's own French
     * sentence, not a generic « Erreur » — and nothing may be written.
     */
    public function testCreateListRefusesAnEmptyDescriptionAndSaysWhy(): void
    {
        $response = $this->controller->createList(
            $this->jsonRequest([
                'name' => 'Sans description',
                'description' => '',
                '_csrf_token' => CsrfGuard::generateToken(),
            ]),
            []
        );

        $this->assertSame(422, $response->getStatusCode());
        $payload = json_decode($response->getBody(), true);
        $this->assertIsArray($payload);
        $this->assertFalse($payload['success']);
        $this->assertSame('La description de la liste est obligatoire.', $payload['error']);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM mass_mail_lists')->fetchColumn());
    }

    public function testToggleListDeactivatesAndReactivates(): void
    {
        $list = $this->list('Bascule');

        $this->assertTrue($this->call('toggleList', ['id' => (string) $list->id], ['active' => false])['success']);
        $this->assertFalse($this->listService->getCustomListById($list->id)?->isActive);

        $this->assertTrue($this->call('toggleList', ['id' => (string) $list->id], ['active' => true])['success']);
        $this->assertTrue($this->listService->getCustomListById($list->id)?->isActive);
    }

    public function testDeleteListRemovesAListNoEmailUses(): void
    {
        $list = $this->list('Jamais utilisée');

        $this->assertTrue($this->call('deleteList', ['id' => (string) $list->id], [])['success']);
        $this->assertNull($this->listService->getCustomListById($list->id));
    }

    /**
     * The « deactivate instead » rule, same precedent as a badge already
     * assigned: a list an email references is never actually deletable,
     * and the refusal says what to do instead.
     */
    public function testDeleteListIsRefusedWhenAnEmailReferencesIt(): void
    {
        $list = $this->list('Utilisée');
        $this->pdo->exec(
            "INSERT INTO mass_mail_emails (subject, body_html, section_id, list_type, list_id, status)
             VALUES ('Sujet', '<p>Corps</p>', {$this->sectionId}, 'custom', {$list->id}, 'draft')"
        );

        $response = $this->controller->deleteList(
            $this->jsonRequest(['_csrf_token' => CsrfGuard::generateToken()]),
            ['id' => (string) $list->id]
        );

        $this->assertSame(422, $response->getStatusCode());
        $payload = json_decode($response->getBody(), true);
        $this->assertIsArray($payload);
        $this->assertStringContainsString('désactivez-la', $payload['error']);
        $this->assertNotNull($this->listService->getCustomListById($list->id));
    }

    /** Removing a list takes its blocks with it. */
    public function testDeleteListTakesItsBlocksWithIt(): void
    {
        $list = $this->list('Avec un groupe');
        $this->blockService->createMemberGroup($list->id, [$this->functionId], [], [], null);

        $this->assertTrue($this->call('deleteList', ['id' => (string) $list->id], [])['success']);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM mass_mail_list_blocks')->fetchColumn());
    }

    /**
     * Every one of the endpoints that WRITES reads its JSON body, and a
     * body that is not JSON at all is a refusal before anything else
     * happens — not a PHP notice on `$data['name']`.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('writingActionProvider')]
    public function testEveryWriteRefusesABodyThatIsNotJson(string $action): void
    {
        $response = $this->controller->{$action}($this->rawRequest('pas du json'), ['id' => '1']);

        $this->assertSame(400, $response->getStatusCode());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('writingActionProvider')]
    public function testEveryWriteRefusesAnInvalidCsrfToken(string $action): void
    {
        $response = $this->controller->{$action}($this->jsonRequest(['_csrf_token' => 'invalide']), ['id' => '1']);

        $this->assertSame(400, $response->getStatusCode());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function writingActionProvider(): array
    {
        return [
            'createList' => ['createList'],
            'updateList' => ['updateList'],
            'toggleList' => ['toggleList'],
            'deleteList' => ['deleteList'],
            'createMemberGroup' => ['createMemberGroup'],
            'updateMemberGroup' => ['updateMemberGroup'],
            'deleteBlock' => ['deleteBlock'],
            'previewCount' => ['previewCount'],
        ];
    }

    private function controller(Environment $twig): MailingListController
    {
        return new MailingListController(
            $twig,
            $this->listService,
            $this->scoutYearResolver,
            $this->blockService,
            new SendableScoutYears(
                new ScoutYearService($this->pdo),
                new \Core\Import\ImportJournalRepository($this->pdo),
                $this->listService
            )
        );
    }

    /**
     * @param string[] $emails
     */
    private function contactGroup(int $listId, string $name, array $emails): int
    {
        return $this->contactGroups->createGroup(
            $listId,
            $name,
            array_map(static fn(string $e): array => ['name' => null, 'email' => $e], $emails),
            null
        )->id;
    }

    private function list(string $name): \Modules\MassMail\Repository\MailingList
    {
        return $this->listService->createCustomList($name, 'Description.', $this->accountId);
    }

    /**
     * @param array<string, string> $params
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function call(string $action, array $params, array $body): array
    {
        $response = $this->controller->{$action}(
            $this->jsonRequest($body + ['_csrf_token' => CsrfGuard::generateToken()]),
            $params
        );

        $this->assertSame(200, $response->getStatusCode(), $response->getBody());
        $payload = json_decode($response->getBody(), true);
        $this->assertIsArray($payload);

        return $payload;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function jsonRequest(array $body): Request
    {
        return $this->rawRequest((string) json_encode($body));
    }

    /**
     * `Request::getRawBody()` reads `php://input`, which a test cannot
     * write — stubbed here, the same way every other JSON-endpoint test in
     * this module does it.
     */
    private function rawRequest(string $body): Request
    {
        $request = new RequestWithInput('POST', '/admin/listes-de-diffusion/lists', [], [], [], [], $body);

        return $request;
    }
}
