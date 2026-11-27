<?php

declare(strict_types=1);

namespace Tests\Modules\News\Controller;

use Core\Badge\MemberBadgeRepository;
use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\File\FileRepository;
use Core\File\UploadHandler;
use Core\Http\Request;
use Core\Import\MemberRepository;
use Core\Import\MemberYearRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Member\MemberService;
use Core\Member\Repository\MemberProfileRepository;
use Core\Member\Repository\SectionRepository;
use Core\Member\SectionService;
use Core\Pdf\PosterPdfService;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\EncryptionService;
use Core\Security\RoleResolver;
use Core\Security\UserAccountRepository;
use Core\Url\ShortUrlRepository;
use Core\Url\ShortUrlService;
use Core\View\EditableContentRepository;
use Core\View\EditableContentService;
use Modules\News\Controller\FormController;
use Modules\News\Controller\NewsController;
use Modules\News\Repository\Article;
use Modules\News\Repository\ArticleRepository;
use Modules\News\Repository\FormField;
use Modules\News\Repository\FormFieldRepository;
use Modules\News\Repository\FormRepository;
use Modules\News\Repository\FormResponseRepository;
use Modules\News\Repository\NewsForm;
use Modules\News\Service\ArticleService;
use Modules\News\Service\FormService;
use Modules\News\Service\MemberAnswer;
use Modules\News\Service\MemberAnswerResolver;
use Modules\News\Service\ResponseService;
use Modules\News\Service\SeoKeywordService;
use PHPUnit\Framework\TestCase;
use Tests\Core\Mail\Template\EmailTemplateRendererFactory;
use Tests\DatabaseTestHelper;
use Tests\Modules\News\NewsMemberFixtures;
use Tests\Modules\News\NewsTestHelper;

/**
 * « Membres » fields through the real controllers and templates (issue
 * #1381): the entry form, the « Membre concerné » selector, the editor's
 * preview, a validation error, the edit form, the responses screen, the
 * export and the mail merge all name the same members the same way —
 * « Totem (Prénom Nom) » or « Prénom Nom » — and every HTML value is a
 * Desk id. Fictitious family only.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
final class NewsMemberFieldsIntegrationTest extends TestCase
{
    private const FAMILY = 'famille.lambert@example.test';

    private \PDO $pdo;
    private EncryptionService $encryption;
    private FormRepository $formRepository;
    private FormFieldRepository $fieldRepository;
    private FormResponseRepository $responseRepository;
    private ArticleRepository $articleRepository;
    private ArticleService $articleService;
    private FormService $formService;
    private ResponseService $responseService;
    private ScoutYearService $scoutYearService;
    private JournalService $journalService;
    private NewsController $newsController;
    private \Twig\Environment $twig;
    private int $chiefAccountId;
    private int $familyAccountId;
    private int $year;
    /** @var array<string, array{member_id: int, member_year_id: int}> */
    private array $members = [];

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        NewsTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $accounts = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $accounts->execute([$this->encryption->encrypt('chief@test.com', 'user_accounts.email'), $this->encryption->blindIndex('chief@test.com', 'email')]);
        $this->chiefAccountId = (int) $this->pdo->lastInsertId();
        $accounts->execute([$this->encryption->encrypt(self::FAMILY, 'user_accounts.email'), $this->encryption->blindIndex(self::FAMILY, 'email')]);
        $this->familyAccountId = (int) $this->pdo->lastInsertId();

        $fixtures = new NewsMemberFixtures($this->pdo, $this->encryption);
        $this->year = $fixtures->year('2026-2027', '2026-09-01', '2027-08-31', true);
        $this->members['zoe'] = $fixtures->member('D-1001', $this->year, 'Zoé', 'Lambert', 'Castor', self::FAMILY);
        $this->members['zoe2'] = $fixtures->member('D-1002', $this->year, 'Zoé', 'Martin', null, self::FAMILY);
        $this->members['marie'] = $fixtures->member('D-1003', $this->year, 'Marie-Élise', 'Van den Berghe', 'Castor', self::FAMILY);

        $this->articleRepository = new ArticleRepository($this->pdo);
        $this->formRepository = new FormRepository($this->pdo);
        $this->fieldRepository = new FormFieldRepository($this->pdo);
        $this->responseRepository = new FormResponseRepository($this->pdo, $this->encryption);

        $shortUrlService = new ShortUrlService(new ShortUrlRepository($this->pdo, $this->encryption));
        $this->articleService = new ArticleService(
            $this->articleRepository,
            $this->formRepository,
            new EditableContentService(new EditableContentRepository($this->pdo)),
            $shortUrlService,
            new FileRepository($this->pdo)
        );
        $this->formService = new FormService($this->formRepository, $this->fieldRepository, $this->articleService, $this->responseRepository);

        $connection = Connection::withPdo($this->pdo);
        $sectionService = new SectionService(
            new SectionRepository($connection),
            new MemberProfileRepository($connection, $this->encryption, new MemberBadgeRepository($this->pdo))
        );

        $twig = \Tests\TestTwig::create(['news']);
        $twig->addGlobal('site_name', 'Test Unit');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_role', 'identified');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('current_path', '/news');
        $this->twig = $twig;

        $this->responseService = new ResponseService(
            $this->responseRepository,
            new RoleResolver(new MemberYearRepository($this->pdo), $this->encryption, $this->pdo),
            $sectionService,
            $this->createStub(MailService::class),
            EmailTemplateRendererFactory::shippedOnlyForModule($twig, 'news'),
            $shortUrlService,
            'https://example.com',
            'Test Unit',
            fieldRepository: $this->fieldRepository,
            memberAnswers: new MemberAnswerResolver(new MemberRepository($this->pdo), new MemberYearRepository($this->pdo), $sectionService)
        );

        $settingService = new SettingService(new SettingRepository($this->pdo));
        $this->scoutYearService = new ScoutYearService($this->pdo);
        $this->journalService = $this->createStub(JournalService::class);
        $this->newsController = new NewsController(
            $twig,
            $this->articleService,
            $this->formService,
            $this->responseService,
            new SeoKeywordService(null),
            new PosterPdfService(),
            $this->scoutYearService,
            $settingService,
            new SchedulerService(new SchedulerRepository($this->pdo)),
            new UserAccountRepository($this->pdo, $this->encryption),
            new MemberService(new MemberYearRepository($this->pdo), new MemberProfileRepository($connection, $this->encryption)),
            $sectionService,
            new UploadHandler(new FileRepository($this->pdo), sys_get_temp_dir()),
            new FileRepository($this->pdo),
            sys_get_temp_dir(),
            $this->journalService,
            new \Modules\News\Service\TicketService($this->responseRepository)
        );

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
    }

    private function formController(?\Modules\MassMail\Api\MassMailDraftInterface $draft = null): FormController
    {
        return new FormController(
            $this->twig,
            $this->articleService,
            $this->formService,
            $this->responseService,
            $this->scoutYearService,
            $this->journalService,
            null,
            null,
            $draft,
            'https://example.be'
        );
    }

    /**
     * An identified article whose form asks the same question three ways.
     *
     * @return array{article: int, form: int, dropdown: int, radio: int, checkbox: int, name: int}
     */
    private function memberForm(string $responseLimit = NewsForm::RESPONSE_LIMIT_UNLIMITED): array
    {
        $articleId = NewsTestHelper::publish(
            $this->pdo,
            $this->articleRepository->create('Week-end', Article::VISIBILITY_IDENTIFIED, false, null, null, $this->chiefAccountId)
        );
        $formId = $this->formRepository->create($articleId, NewsForm::ACCESS_IDENTIFIED, $responseLimit, null, null, false, 'chief', null, null);
        $members = FormField::OPTIONS_SOURCE_MEMBERS;

        return [
            'article' => $articleId,
            'form' => $formId,
            'dropdown' => $this->fieldRepository->create($formId, 0, FormField::TYPE_DROPDOWN, 'Responsable', false, $members, null, null, null, null),
            'radio' => $this->fieldRepository->create($formId, 1, FormField::TYPE_RADIO, 'Covoiturage', false, $members, null, null, null, null),
            'checkbox' => $this->fieldRepository->create($formId, 2, FormField::TYPE_CHECKBOX, 'Participants', false, $members, null, null, null, null),
            'name' => $this->fieldRepository->create($formId, 3, FormField::TYPE_SHORT_TEXT, 'Remarque', true, null, null, null, null, null),
        ];
    }

    /**
     * @param array{article: int, form: int, dropdown: int, radio: int, checkbox: int, name: int} $form
     */
    private function storedResponse(array $form): int
    {
        return $this->responseRepository->create($form['form'], $this->familyAccountId, null, self::FAMILY, [
            $form['dropdown'] => MemberAnswer::encode(['D-1002']),
            $form['radio'] => MemberAnswer::encode(['D-1003']),
            $form['checkbox'] => MemberAnswer::encode(['D-1001', 'D-1003']),
            $form['name'] => 'Rien',
        ], null, null);
    }

    public function testTheFormOffersEveryMemberByFullNameWithTheDeskIdAsValue(): void
    {
        $form = $this->memberForm(NewsForm::RESPONSE_LIMIT_ONE_PER_MEMBER);
        AuthSession::login($this->familyAccountId, self::FAMILY, 'identified');

        $body = $this->newsController->show(new Request('GET', '/news/' . $form['article'], [], [], [], []), ['id' => (string) $form['article']])->getBody();

        $this->assertMatchesRegularExpression('#<option value="D-1001"\s*>Castor \(Zoé Lambert\)</option>#', $body);
        $this->assertMatchesRegularExpression('#<option value="D-1002"\s*>Zoé Martin</option>#', $body);
        $this->assertMatchesRegularExpression('#<option value="D-1003"\s*>Castor \(Marie-Élise Van den Berghe\)</option>#', $body);
        $this->assertMatchesRegularExpression('#type="radio" name="field_' . $form['radio'] . '"[^>]*value="D-1003"#', $body);
        $this->assertMatchesRegularExpression('#type="checkbox" name="field_' . $form['checkbox'] . '\[\]"[^>]*value="D-1001"#', $body);
        // The « Membre concerné » selector: the same labels, and still the
        // annual record as its value (the response's own member_year_id).
        $this->assertMatchesRegularExpression(
            '#<option value="' . $this->members['zoe2']['member_year_id'] . '"\s*>Zoé Martin</option>#',
            $body
        );
        $this->assertStringNotContainsString('value="Castor', $body, 'a label is never a value');
    }

    /**
     * Twig's `==` compares numeric strings as numbers: « 0123 » == « 123 ».
     * Two Desk ids that differ only by a leading zero must not both read
     * as chosen.
     */
    public function testOnlyTheExactDeskIdIsSelected(): void
    {
        $options = [['value' => '0123', 'label' => 'Zoé Martin'], ['value' => '123', 'label' => 'Zoé Lambert']];
        foreach ([FormField::TYPE_DROPDOWN, FormField::TYPE_RADIO, FormField::TYPE_CHECKBOX] as $type) {
            $field = new FormField(9, 1, 0, $type, 'Pour qui ?', false, FormField::OPTIONS_SOURCE_MEMBERS, null, null, null, null);
            $value = $type === FormField::TYPE_CHECKBOX ? ['123'] : '123';

            $html = $this->twig->render('@news/partials/_field.html.twig', [
                'field' => $field,
                'options' => $options,
                'remaining_capacity' => null,
                'value' => $value,
            ]);

            $this->assertSame(1, preg_match_all('#\b(selected|checked)\b#', $html), $type);
            $this->assertMatchesRegularExpression('#value="123"\s*(selected|checked)#', $html, $type);
        }
    }

    public function testAVisitorWhoIsNotSignedInSeesNoMember(): void
    {
        $form = $this->memberForm();

        $body = $this->newsController->show(new Request('GET', '/news/' . $form['article'], [], [], [], []), ['id' => (string) $form['article']])->getBody();

        $this->assertStringNotContainsString('Zoé', $body);
        $this->assertStringNotContainsString('D-1001', $body);
    }

    public function testTheEditorsPreviewNamesTheSameMembers(): void
    {
        $form = $this->memberForm();
        // The chief previewing is a parent of the same family here.
        AuthSession::login($this->chiefAccountId, self::FAMILY, 'chief');

        $body = $this->newsController->edit(
            new Request('GET', '/news/' . $form['article'] . '/gerer', ['tab' => 'preview'], [], [], []),
            ['id' => (string) $form['article']]
        )->getBody();

        $this->assertMatchesRegularExpression('#<option value="D-1001"\s*>Castor \(Zoé Lambert\)</option>#', $body);
        $this->assertStringContainsString('Castor (Marie-Élise Van den Berghe)', $body);
    }

    public function testASubmissionStoresDeskIdsAndAValidationErrorKeepsTheChoices(): void
    {
        $form = $this->memberForm();
        AuthSession::login($this->familyAccountId, self::FAMILY, 'identified');
        $post = [
            '_csrf_token' => CsrfGuard::generateToken(),
            'contact_email' => self::FAMILY,
            'field_' . $form['dropdown'] => 'D-1002',
            'field_' . $form['radio'] => 'D-1003',
            'field_' . $form['checkbox'] => ['D-1001', 'D-1003'],
            'field_' . $form['name'] => '',
        ];

        // The required « Remarque » is empty: the form comes back with
        // the same members chosen.
        $error = $this->formController()->submit(
            new Request('POST', '/news/' . $form['article'] . '/form/submit', [], $post, [], []),
            ['id' => (string) $form['article']]
        );
        $this->assertSame(422, $error->getStatusCode());
        $body = $error->getBody();
        $this->assertMatchesRegularExpression('#<option value="D-1002"\s*selected\s*>Zoé Martin</option>#', $body);
        $this->assertMatchesRegularExpression('#value="D-1003"\s*checked#', $body);
        $this->assertMatchesRegularExpression('#name="field_' . $form['checkbox'] . '\[\]" id="[^"]+"\s*value="D-1001"\s*checked#', $body);
        $this->assertDoesNotMatchRegularExpression('#name="field_' . $form['checkbox'] . '\[\]" id="[^"]+"\s*value="D-1002"\s*checked#', $body);

        $post['field_' . $form['name']] = 'Rien';
        $post['_csrf_token'] = CsrfGuard::generateToken();
        $ok = $this->formController()->submit(
            new Request('POST', '/news/' . $form['article'] . '/form/submit', [], $post, [], []),
            ['id' => (string) $form['article']]
        );
        $this->assertSame(302, $ok->getStatusCode());

        $responseId = (int) $this->pdo->query('SELECT id FROM news_form_responses')->fetchColumn();
        $answers = $this->responseService->getAnswers($responseId);
        $this->assertSame('["D-1002"]', $answers[$form['dropdown']]);
        $this->assertSame('["D-1001","D-1003"]', $answers[$form['checkbox']]);

        $confirmation = $this->formController()->confirmation(
            new Request('GET', '/news/' . $form['article'] . '/form/confirmation', [], [], [], []),
            ['id' => (string) $form['article']]
        )->getBody();
        $this->assertStringContainsString('Castor (Zoé Lambert), Castor (Marie-Élise Van den Berghe)', $confirmation);
        $this->assertStringNotContainsString('D-1001', $confirmation);
    }

    public function testAForgedIdIsRefusedAndNothingIsStored(): void
    {
        $form = $this->memberForm();
        AuthSession::login($this->familyAccountId, self::FAMILY, 'identified');

        $response = $this->formController()->submit(
            new Request('POST', '/news/' . $form['article'] . '/form/submit', [], [
                '_csrf_token' => CsrfGuard::generateToken(),
                'contact_email' => self::FAMILY,
                'field_' . $form['checkbox'] => ['D-1001', 'D-9999'],
                'field_' . $form['name'] => 'Rien',
            ], [], []),
            ['id' => (string) $form['article']]
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('Valeur invalide pour le champ', $response->getBody());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM news_form_responses')->fetchColumn());
    }

    public function testTheEditFormPreselectsTheStoredMembersAndSavesThemUnchanged(): void
    {
        $form = $this->memberForm();
        $responseId = $this->storedResponse($form);
        $before = $this->responseService->getAnswers($responseId);
        AuthSession::login($this->familyAccountId, self::FAMILY, 'identified');
        $params = ['id' => (string) $form['article'], 'response_id' => (string) $responseId];

        $body = $this->formController()->editResponse(
            new Request('GET', '/news/' . $form['article'] . '/form/responses/' . $responseId . '/edit', [], [], [], []),
            $params
        )->getBody();

        $this->assertMatchesRegularExpression('#<option value="D-1002"\s*selected\s*>Zoé Martin</option>#', $body);
        $this->assertMatchesRegularExpression('#<option value="D-1001"\s*>Castor \(Zoé Lambert\)</option>#', $body);
        $this->assertMatchesRegularExpression('#name="field_' . $form['checkbox'] . '\[\]" id="[^"]+"\s*value="D-1001"\s*checked#', $body);
        $this->assertMatchesRegularExpression('#name="field_' . $form['checkbox'] . '\[\]" id="[^"]+"\s*value="D-1003"\s*checked#', $body);
        $this->assertDoesNotMatchRegularExpression('#name="field_' . $form['checkbox'] . '\[\]" id="[^"]+"\s*value="D-1002"\s*checked#', $body);

        // What the browser sends back from that page, untouched.
        $saved = $this->formController()->updateResponse(
            new Request('POST', '/news/' . $form['article'] . '/form/responses/' . $responseId . '/edit', [], [
                '_csrf_token' => CsrfGuard::generateToken(),
                'contact_email' => self::FAMILY,
                'field_' . $form['dropdown'] => 'D-1002',
                'field_' . $form['radio'] => 'D-1003',
                'field_' . $form['checkbox'] => ['D-1001', 'D-1003'],
                'field_' . $form['name'] => 'Rien',
            ], [], []),
            $params
        );

        $this->assertSame(302, $saved->getStatusCode());
        $this->assertSame($before, $this->responseService->getAnswers($responseId));
    }

    public function testTheResponsesScreenTheExportAndTheMailMergeNameTheMembers(): void
    {
        $form = $this->memberForm();
        $this->storedResponse($form);
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        $params = ['id' => (string) $form['article']];

        $screen = $this->formController()->responses(
            new Request('GET', '/news/' . $form['article'] . '/form/responses', [], [], [], []),
            $params
        )->getBody();
        $this->assertStringContainsString('Castor (Zoé Lambert), Castor (Marie-Élise Van den Berghe)', $screen);
        $this->assertStringContainsString('Zoé Martin', $screen);
        $this->assertStringNotContainsString('D-100', $screen);

        $export = $this->formController()->exportResponses(
            new Request('GET', '/news/' . $form['article'] . '/form/responses/export', [], [], [], []),
            $params
        );
        $path = tempnam(sys_get_temp_dir(), 'news-export-') . '.xlsx';
        file_put_contents($path, $export->getBody());
        $rows = \PhpOffice\PhpSpreadsheet\IOFactory::load($path)->getActiveSheet()->toArray();
        @unlink($path);
        $this->assertSame(
            ['famille.lambert@example.test', 'Zoé Martin', 'Castor (Marie-Élise Van den Berghe)', 'Castor (Zoé Lambert), Castor (Marie-Élise Van den Berghe)', 'Rien'],
            $rows[1]
        );

        $captured = [];
        $draft = $this->createStub(\Modules\MassMail\Api\MassMailDraftInterface::class);
        $draft->method('createMergeDraft')->willReturnCallback(
            function (string $label, string $subject, array $columns, array $rows) use (&$captured): string {
                $captured = $rows;

                return '/mass-mail/7';
            }
        );
        $this->formController($draft)->createMailDraft(
            new Request('POST', '/news/' . $form['article'] . '/form/responses/mail-draft', [], ['_csrf_token' => CsrfGuard::generateToken()], [], []),
            $params
        );
        $this->assertSame('Castor (Zoé Lambert), Castor (Marie-Élise Van den Berghe)', $captured[0]['values']['Participants']);
        $this->assertSame('Zoé Martin', $captured[0]['values']['Responsable']);
    }
}
