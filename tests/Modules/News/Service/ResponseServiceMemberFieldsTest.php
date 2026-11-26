<?php

declare(strict_types=1);

namespace Tests\Modules\News\Service;

use Core\Badge\MemberBadgeRepository;
use Core\Database\Connection;
use Core\Import\MemberRepository;
use Core\Import\MemberYearRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Member\Duplicate\DuplicateMemberRepository;
use Core\Member\Duplicate\MemberMergeRepository;
use Core\Member\Duplicate\MemberMergeService;
use Core\Member\Repository\MemberProfileRepository;
use Core\Member\Repository\SectionRepository;
use Core\Member\SectionService;
use Core\Security\EncryptionService;
use Core\Security\RoleResolver;
use Core\Url\ShortUrlRepository;
use Core\Url\ShortUrlService;
use Modules\News\Repository\Article;
use Modules\News\Repository\ArticleRepository;
use Modules\News\Repository\FormField;
use Modules\News\Repository\FormFieldRepository;
use Modules\News\Repository\FormRepository;
use Modules\News\Repository\FormResponseRepository;
use Modules\News\Repository\NewsForm;
use Modules\News\Service\MemberAnswer;
use Modules\News\Service\MemberAnswerResolver;
use Modules\News\Service\MemberOption;
use Modules\News\Service\NewsException;
use Modules\News\Service\ResponseService;
use PHPUnit\Framework\TestCase;
use Tests\Core\Mail\Template\EmailTemplateRendererFactory;
use Tests\DatabaseTestHelper;
use Tests\Modules\News\NewsMemberFixtures;
use Tests\Modules\News\NewsTestHelper;

/**
 * A field whose options are the account's members (issue #1381): each
 * option is the member's Desk id under their full name, an answer stores
 * Desk ids and nothing else, and every reader names the members again.
 *
 * The family: Zoé Lambert « Castor », Zoé Martin (same first name, no
 * totem) and Marie-Élise Van den Berghe « Castor » (same totem) — three
 * Desk ids an answer must never confuse. Hugo Renard belongs to another
 * family. Every one of them is fictitious.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
final class ResponseServiceMemberFieldsTest extends TestCase
{
    private const FAMILY = 'famille.lambert@example.test';
    private const OTHER_FAMILY = 'famille.renard@example.test';

    private \PDO $pdo;
    private EncryptionService $encryption;
    private NewsMemberFixtures $fixtures;
    private FormResponseRepository $responseRepository;
    private FormFieldRepository $fieldRepository;
    private MailService $mailService;
    private Article $article;
    private int $formId;
    private int $accountId;
    private int $lastYear;
    private int $year;
    /** @var array<string, array{member_id: int, member_year_id: int}> */
    private array $members = [];

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        NewsTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->fixtures = new NewsMemberFixtures($this->pdo, $this->encryption);
        $this->responseRepository = new FormResponseRepository($this->pdo, $this->encryption);
        $this->fieldRepository = new FormFieldRepository($this->pdo);
        $this->mailService = $this->createMock(MailService::class);

        $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)')
            ->execute(['enc', 'idx']);
        $this->accountId = (int) $this->pdo->lastInsertId();
        $articles = new ArticleRepository($this->pdo);
        $articleId = $articles->create('Week-end', Article::VISIBILITY_IDENTIFIED, true, null, null, $this->accountId);
        $this->article = $articles->findById($articleId);
        $this->formId = (new FormRepository($this->pdo))->create(
            $articleId,
            NewsForm::ACCESS_IDENTIFIED,
            NewsForm::RESPONSE_LIMIT_UNLIMITED,
            null,
            null,
            false,
            'chief',
            null,
            null
        );

        $this->lastYear = $this->fixtures->year('2025-2026', '2025-09-01', '2026-08-31', false);
        $this->year = $this->fixtures->year('2026-2027', '2026-09-01', '2027-08-31', true);
        $this->members['zoe'] = $this->fixtures->member('D-1001', $this->year, 'Zoé', 'Lambert', 'Castor', self::FAMILY);
        $this->members['zoe2'] = $this->fixtures->member('D-1002', $this->year, 'Zoé', 'Martin', null, self::FAMILY);
        $this->members['marie'] = $this->fixtures->member(
            'D-1003',
            $this->year,
            'Marie-Élise',
            'Van den Berghe',
            'Castor',
            self::FAMILY
        );
        $this->members['hugo'] = $this->fixtures->member('D-2001', $this->year, 'Hugo', 'Renard', null, self::OTHER_FAMILY);
    }

    private function service(): ResponseService
    {
        $connection = Connection::withPdo($this->pdo);
        $sectionService = new SectionService(
            new SectionRepository($connection),
            new MemberProfileRepository($connection, $this->encryption, new MemberBadgeRepository($this->pdo))
        );
        $twig = \Core\View\TwigFactory::create(
            dirname(__DIR__, 4) . '/core/View/templates',
            false,
            ['news' => dirname(__DIR__, 4) . '/modules/news/views']
        );
        $twig->addGlobal('site_name', 'Test Unit');
        $renderer = EmailTemplateRendererFactory::shippedOnlyForModule($twig, 'news');

        return new ResponseService(
            $this->responseRepository,
            new RoleResolver(new MemberYearRepository($this->pdo), $this->encryption, $this->pdo),
            $sectionService,
            $this->mailService,
            $renderer,
            new ShortUrlService(new ShortUrlRepository($this->pdo, $this->encryption)),
            'https://example.com',
            'Test Unit',
            fieldRepository: $this->fieldRepository,
            memberAnswers: new MemberAnswerResolver(
                new MemberRepository($this->pdo),
                new MemberYearRepository($this->pdo),
                $sectionService
            )
        );
    }

    private function form(): NewsForm
    {
        return (new FormRepository($this->pdo))->findById($this->formId);
    }

    private function field(string $type, string $source = FormField::OPTIONS_SOURCE_MEMBERS, bool $required = false): FormField
    {
        $manual = $source === FormField::OPTIONS_SOURCE_MANUAL ? "Pâtes\nRiz, sauce\nPizza" : null;

        return $this->fieldRepository->findById(
            $this->fieldRepository->create($this->formId, 0, $type, 'Pour qui ?', $required, $source, $manual, null, null, null)
        );
    }

    /**
     * @param array<int, string|string[]> $answers
     */
    private function submit(FormField $field, array $answers, ?int $accountId = null): int
    {
        return $this->service()->submit(
            $this->article,
            $this->form(),
            [$field],
            $accountId ?? $this->accountId,
            self::FAMILY,
            $this->year,
            self::FAMILY,
            $answers,
            null
        )->id;
    }

    /** @return array<int, MemberOption> */
    private function familyOptions(): array
    {
        return $this->service()->resolveMemberOptions(self::FAMILY, $this->year);
    }

    private function readable(FormField $field, int $responseId): string
    {
        return $this->service()->readableAnswersOf([$field], $responseId)[$field->id];
    }

    // -----------------------------------------------------------------
    // What a member option is
    // -----------------------------------------------------------------

    public function testEachMemberIsOfferedUnderTheirFullNameWithTheirDeskIdAsValue(): void
    {
        $options = $this->service()->optionsFor($this->field(FormField::TYPE_DROPDOWN), $this->familyOptions());

        $this->assertSame([
            ['value' => 'D-1001', 'label' => 'Castor (Zoé Lambert)'],
            ['value' => 'D-1002', 'label' => 'Zoé Martin'],
            ['value' => 'D-1003', 'label' => 'Castor (Marie-Élise Van den Berghe)'],
        ], $options);
    }

    public function testTheMemberSelectorOptionsAreKeyedByAnnualRecordAndLabelledByFullName(): void
    {
        $options = $this->familyOptions();

        $this->assertSame(
            [$this->members['zoe']['member_year_id'], $this->members['zoe2']['member_year_id'], $this->members['marie']['member_year_id']],
            array_keys($options)
        );
        $this->assertSame('Castor (Zoé Lambert)', $options[$this->members['zoe']['member_year_id']]->label);
        $this->assertSame('D-1001', $options[$this->members['zoe']['member_year_id']]->deskId);
    }

    public function testAnAnonymousVisitorIsOfferedNobody(): void
    {
        $this->assertSame([], $this->service()->resolveMemberOptions(null, $this->year));
        $this->assertSame([], $this->service()->optionsFor($this->field(FormField::TYPE_CHECKBOX), []));
    }

    public function testManualOptionsAreUnchangedAndStoredAsText(): void
    {
        $dropdown = $this->field(FormField::TYPE_DROPDOWN, FormField::OPTIONS_SOURCE_MANUAL);
        $checkbox = $this->field(FormField::TYPE_CHECKBOX, FormField::OPTIONS_SOURCE_MANUAL);

        $this->assertSame(
            [['value' => 'Pâtes', 'label' => 'Pâtes'], ['value' => 'Riz, sauce', 'label' => 'Riz, sauce'], ['value' => 'Pizza', 'label' => 'Pizza']],
            $this->service()->optionsFor($dropdown, $this->familyOptions())
        );

        $response = $this->service()->submit(
            $this->article,
            $this->form(),
            [$dropdown, $checkbox],
            $this->accountId,
            self::FAMILY,
            $this->year,
            self::FAMILY,
            [$dropdown->id => 'Pizza', $checkbox->id => ['Pâtes', 'Pizza']],
            null
        );

        $this->assertSame(
            [$dropdown->id => 'Pizza', $checkbox->id => 'Pâtes, Pizza'],
            $this->service()->getAnswers($response->id)
        );
        $this->expectException(NewsException::class);
        $this->service()->submit(
            $this->article,
            $this->form(),
            [$dropdown],
            $this->accountId,
            self::FAMILY,
            $this->year,
            self::FAMILY,
            [$dropdown->id => 'D-1001'],
            null
        );
    }

    // -----------------------------------------------------------------
    // What an answer stores
    // -----------------------------------------------------------------

    public function testADropdownStoresTheDeskIdEncryptedAndNeverTheName(): void
    {
        $field = $this->field(FormField::TYPE_DROPDOWN);
        $responseId = $this->submit($field, [$field->id => 'D-1003']);

        $this->assertSame([$field->id => '["D-1003"]'], $this->service()->getAnswers($responseId));
        $raw = (string) $this->pdo->query('SELECT value FROM news_form_response_values')->fetchColumn();
        $this->assertStringNotContainsString('D-1003', $raw, 'the value column is encrypted');
        $this->assertSame('Castor (Marie-Élise Van den Berghe)', $this->readable($field, $responseId));
    }

    public function testARadioStoresOneDeskId(): void
    {
        $field = $this->field(FormField::TYPE_RADIO);
        $responseId = $this->submit($field, [$field->id => 'D-1002']);

        $this->assertSame(['D-1002'], MemberAnswer::decode($this->service()->getAnswers($responseId)[$field->id]));
        $this->assertSame('Zoé Martin', $this->readable($field, $responseId));
    }

    public function testCheckboxesStoreSeveralDeskIdsAndHomonymsStayDistinct(): void
    {
        $field = $this->field(FormField::TYPE_CHECKBOX);
        // The two « Castor » and the two « Zoé »: four ways a name-keyed
        // answer would have collided.
        $responseId = $this->submit($field, [$field->id => ['D-1001', 'D-1003', 'D-1002', 'D-1001']]);

        $this->assertSame(
            ['D-1001', 'D-1003', 'D-1002'],
            MemberAnswer::decode($this->service()->getAnswers($responseId)[$field->id])
        );
        $this->assertSame(
            'Castor (Zoé Lambert), Castor (Marie-Élise Van den Berghe), Zoé Martin',
            $this->readable($field, $responseId)
        );
    }

    public function testAnEmptyOptionalChoiceStoresNothingAndARequiredOneIsRefused(): void
    {
        $optional = $this->field(FormField::TYPE_CHECKBOX);
        $responseId = $this->submit($optional, [$optional->id => []]);
        $this->assertSame([$optional->id => ''], $this->service()->getAnswers($responseId));
        $this->assertSame('', $this->readable($optional, $responseId));

        $required = $this->field(FormField::TYPE_DROPDOWN, required: true);
        $this->expectExceptionMessage('Le champ "Pour qui ?" est obligatoire.');
        $this->submit($required, [$required->id => '']);
    }

    // -----------------------------------------------------------------
    // What is refused
    // -----------------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: string|string[]}>
     */
    public static function refusedAnswers(): array
    {
        return [
            'a forged Desk id' => [FormField::TYPE_DROPDOWN, 'D-9999'],
            'another family\'s member' => [FormField::TYPE_RADIO, 'D-2001'],
            'the name instead of the id' => [FormField::TYPE_DROPDOWN, 'Castor (Zoé Lambert)'],
            'the bare totem' => [FormField::TYPE_RADIO, 'Castor'],
            'an annual record id' => [FormField::TYPE_DROPDOWN, '1'],
            'a list on a dropdown' => [FormField::TYPE_DROPDOWN, ['D-1001', 'D-1002']],
            'one forged id among good ones' => [FormField::TYPE_CHECKBOX, ['D-1001', 'D-2001']],
        ];
    }

    /**
     * @param string|string[] $answer
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('refusedAnswers')]
    public function testAnIdOutsideTheAccountsMembersIsRefused(string $type, string|array $answer): void
    {
        $field = $this->field($type);

        try {
            $this->submit($field, [$field->id => $answer]);
            $this->fail('the answer should have been refused');
        } catch (NewsException $e) {
            $this->assertSame('Valeur invalide pour le champ "Pour qui ?".', $e->getMessage());
        }
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM news_form_responses')->fetchColumn());
    }

    public function testAMemberOfLastYearIsOutOfScopeThisYear(): void
    {
        $leaver = $this->fixtures->member('D-0500', $this->lastYear, 'Lou', 'Dumont', null, self::FAMILY);
        $field = $this->field(FormField::TYPE_DROPDOWN);

        $this->expectException(NewsException::class);
        $this->submit($field, [$field->id => 'D-0500']);
        unset($leaver);
    }

    public function testAPublicFormOffersAndAcceptsNoMember(): void
    {
        $this->pdo->exec("UPDATE news_forms SET access = 'public'");
        $field = $this->field(FormField::TYPE_DROPDOWN);

        $this->expectException(NewsException::class);
        $this->service()->submit(
            $this->article,
            $this->form(),
            [$field],
            null,
            null,
            $this->year,
            'visiteur@example.test',
            [$field->id => 'D-1001'],
            null
        );
    }

    // -----------------------------------------------------------------
    // Reading an answer back
    // -----------------------------------------------------------------

    public function testARenamedMemberIsTheSameAnswerUnderTheirCurrentName(): void
    {
        $field = $this->field(FormField::TYPE_CHECKBOX);
        $responseId = $this->submit($field, [$field->id => ['D-1002', 'D-1003']]);

        $this->fixtures->rename($this->members['zoe2']['member_year_id'], 'Zoé', 'Martin-Leroy', 'Écureuil');
        $this->fixtures->rename($this->members['marie']['member_year_id'], 'Marie-Élise', 'Van den Berghe', null);

        $this->assertSame(
            'Écureuil (Zoé Martin-Leroy), Marie-Élise Van den Berghe',
            $this->readable($field, $responseId)
        );
        $this->assertSame(
            ['D-1002', 'D-1003'],
            $this->service()->formValues([$field], $this->service()->getAnswers($responseId), $this->familyOptions())[$field->id]
        );
    }

    public function testACodeThatDesignatesNobodyReadsAsUnavailableNeverAsACode(): void
    {
        $field = $this->field(FormField::TYPE_CHECKBOX);
        $responseId = $this->responseRepository->create(
            $this->formId,
            $this->accountId,
            null,
            self::FAMILY,
            [$field->id => MemberAnswer::encode(['D-7777', 'D-1001'])],
            null,
            null
        );
        $garbled = $this->responseRepository->create($this->formId, $this->accountId, null, self::FAMILY, [$field->id => 'Zoé'], null, null);

        $readable = $this->service()->readableAnswers([$field], $this->service()->getAnswersFor([$responseId, $garbled]));

        $this->assertSame('Membre indisponible, Castor (Zoé Lambert)', $readable[$responseId][$field->id]);
        $this->assertSame('Membre indisponible', $readable[$garbled][$field->id]);
    }

    public function testTheConfirmationEmailNamesTheMembersAndShowsNoCode(): void
    {
        $field = $this->field(FormField::TYPE_CHECKBOX);
        $sent = [];
        $this->mailService->expects($this->once())->method('send')->willReturnCallback(
            function (string $to, string $subject, string $bodyHtml, ?string $bodyText = null) use (&$sent): void {
                $sent = ['to' => $to, 'html' => $bodyHtml, 'text' => (string) $bodyText];
            }
        );

        $this->submit($field, [$field->id => ['D-1001', 'D-1003']]);

        $this->assertSame(self::FAMILY, $sent['to']);
        $this->assertStringContainsString('Castor (Zoé Lambert), Castor (Marie-Élise Van den Berghe)', $sent['text']);
        $this->assertStringNotContainsString('D-1001', $sent['text']);
        $this->assertStringNotContainsString('D-1001', $sent['html']);
    }

    // -----------------------------------------------------------------
    // Editing an answer
    // -----------------------------------------------------------------

    public function testTheEditFormPreselectsTheStoredMembersAndAnUnchangedSaveKeepsThem(): void
    {
        $dropdown = $this->field(FormField::TYPE_DROPDOWN);
        $checkbox = $this->field(FormField::TYPE_CHECKBOX);
        $response = $this->service()->submit(
            $this->article,
            $this->form(),
            [$dropdown, $checkbox],
            $this->accountId,
            self::FAMILY,
            $this->year,
            self::FAMILY,
            [$dropdown->id => 'D-1002', $checkbox->id => ['D-1001', 'D-1003']],
            null
        );
        $stored = $this->service()->getAnswers($response->id);

        $values = $this->service()->formValues([$dropdown, $checkbox], $stored, $this->familyOptions());
        $this->assertSame('D-1002', $values[$dropdown->id]);
        $this->assertSame(['D-1001', 'D-1003'], $values[$checkbox->id]);

        $this->service()->update($response, $this->form(), [$dropdown, $checkbox], self::FAMILY, $values, self::FAMILY, $this->year);

        $this->assertSame($stored, $this->service()->getAnswers($response->id));
    }

    public function testAMemberTheAccountNoLongerReachesIsKeptAsUnavailableOnEdit(): void
    {
        $field = $this->field(FormField::TYPE_CHECKBOX);
        $response = $this->responseRepository->findById($this->submit($field, [$field->id => ['D-1001', 'D-1002']]));
        $this->fixtures->detach($this->members['zoe2']['member_year_id'], 'autre.parent@example.test');
        $stored = $this->service()->getAnswers($response->id);
        $options = $this->familyOptions();

        $this->assertSame([
            ['value' => 'D-1001', 'label' => 'Castor (Zoé Lambert)'],
            ['value' => 'D-1003', 'label' => 'Castor (Marie-Élise Van den Berghe)'],
            ['value' => 'D-1002', 'label' => 'Membre indisponible'],
        ], $this->service()->optionsFor($field, $options, $stored[$field->id]));

        // Saved untouched, the choice survives; never silently handed to
        // somebody else.
        $values = $this->service()->formValues([$field], $stored, $options);
        $this->service()->update($response, $this->form(), [$field], self::FAMILY, $values, self::FAMILY, $this->year);
        $this->assertSame(['D-1001', 'D-1002'], MemberAnswer::decode($this->service()->getAnswers($response->id)[$field->id]));
        // The responses screen, read by the staff, still names her.
        $this->assertSame('Castor (Zoé Lambert), Zoé Martin', $this->readable($field, $response->id));

        // Unticked, it is gone — and a code the answer never held is
        // still refused on an edit.
        $this->service()->update($response, $this->form(), [$field], self::FAMILY, [$field->id => ['D-1001']], self::FAMILY, $this->year);
        $this->assertSame('["D-1001"]', $this->service()->getAnswers($response->id)[$field->id]);
        $this->expectException(NewsException::class);
        $this->service()->update($response, $this->form(), [$field], self::FAMILY, [$field->id => ['D-1002']], self::FAMILY, $this->year);
    }

    public function testAChangeOfYearKeepsTheSameMemberAndNamesThemFromTheNewRecord(): void
    {
        $field = $this->field(FormField::TYPE_DROPDOWN);
        $response = $this->responseRepository->findById($this->submit($field, [$field->id => 'D-1001']));

        $nextYear = $this->fixtures->year('2027-2028', '2027-09-01', '2028-08-31', false);
        $this->pdo->exec('UPDATE scout_years SET is_current = 0');
        $this->pdo->prepare('UPDATE scout_years SET is_current = 1 WHERE id = ?')->execute([$nextYear]);
        $this->fixtures->record($this->members['zoe']['member_id'], $nextYear, 'Zoé', 'Lambert', 'Castor rieur', self::FAMILY);

        $this->assertSame('Castor rieur (Zoé Lambert)', $this->readable($field, $response->id));
        $nextOptions = $this->service()->resolveMemberOptions(self::FAMILY, $nextYear);
        $values = $this->service()->formValues([$field], $this->service()->getAnswers($response->id), $nextOptions);
        $this->assertSame('D-1001', $values[$field->id]);
        $this->assertSame(
            [['value' => 'D-1001', 'label' => 'Castor rieur (Zoé Lambert)']],
            $this->service()->optionsFor($field, $nextOptions, $this->service()->getAnswers($response->id)[$field->id])
        );

        // Somebody who did not come back is not offered next year.
        $this->expectException(NewsException::class);
        $this->service()->update($response, $this->form(), [$field], self::FAMILY, [$field->id => 'D-1003'], self::FAMILY, $nextYear);
    }

    public function testACodeAbandonedByAMergeStillDesignatesTheMemberKept(): void
    {
        // Lou was re-created in Desk this year under a new code (D-1004)
        // instead of having last year's record (D-0904) reopened.
        $old = $this->fixtures->member('D-0904', $this->lastYear, 'Lou', 'Dumont', null, self::FAMILY);
        $new = $this->fixtures->member('D-1004', $this->year, 'Lou', 'Dumont', 'Hibou', self::FAMILY);
        $field = $this->field(FormField::TYPE_CHECKBOX);
        $response = $this->responseRepository->findById($this->submit($field, [$field->id => ['D-1004', 'D-1001']]));

        $duplicates = new DuplicateMemberRepository($this->pdo, $this->encryption);
        (new MemberMergeService(
            new MemberMergeRepository($this->pdo, $duplicates),
            $duplicates,
            new JournalService(new JournalRepository($this->pdo))
        ))->merge($old['member_id'], $new['member_id'], null);

        // The account still reaches Lou, now under the code kept.
        $options = $this->familyOptions();
        $this->assertContains('D-0904', array_map(static fn(MemberOption $o): string => $o->deskId, $options));
        $stored = $this->service()->getAnswers($response->id);

        $this->assertSame('Hibou (Lou Dumont), Castor (Zoé Lambert)', $this->readable($field, $response->id));
        $values = $this->service()->formValues([$field], $stored, $options);
        $this->assertSame(['D-0904', 'D-1001'], $values[$field->id]);
        $this->assertNotContains(
            'Membre indisponible',
            array_column($this->service()->optionsFor($field, $options, $stored[$field->id]), 'label')
        );

        // A form opened before the merge still posts the old code: it is
        // recognised, and stored under the current one.
        $this->service()->update($response, $this->form(), [$field], self::FAMILY, [$field->id => ['D-1004']], self::FAMILY, $this->year);
        $this->assertSame('["D-0904"]', $this->service()->getAnswers($response->id)[$field->id]);
    }

    public function testANewAnswerGivenWithAnAbandonedCodeIsStoredUnderTheCurrentOne(): void
    {
        $old = $this->fixtures->member('D-0904', $this->lastYear, 'Lou', 'Dumont', null, self::FAMILY);
        $new = $this->fixtures->member('D-1004', $this->year, 'Lou', 'Dumont', null, self::FAMILY);
        $duplicates = new DuplicateMemberRepository($this->pdo, $this->encryption);
        (new MemberMergeService(
            new MemberMergeRepository($this->pdo, $duplicates),
            $duplicates,
            new JournalService(new JournalRepository($this->pdo))
        ))->merge($old['member_id'], $new['member_id'], null);
        $field = $this->field(FormField::TYPE_RADIO);

        $responseId = $this->submit($field, [$field->id => 'D-1004']);

        $this->assertSame('["D-0904"]', $this->service()->getAnswers($responseId)[$field->id]);
    }
}
