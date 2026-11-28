<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\News\Service;

use Core\Badge\MemberBadgeRepository;
use Core\Database\Connection;
use Core\Import\MemberYearRepository;
use Core\Mail\MailService;
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
use Modules\News\Service\FieldAnswerException;
use Modules\News\Service\ResponseService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Core\Mail\Template\EmailTemplateRendererFactory;
use Tests\UsesProductionEngine;

/**
 * Issue #1486: a free-text answer is encrypted into
 * `news_form_response_values.value`, a `BLOB` of 65 535 bytes, and SQLite
 * bounds nothing — so the ceiling is judged here, on the engine that
 * enforces it.
 *
 * Before the fix a « Remarques » answer of a pasted document reached the
 * INSERT, the engine refused it (1406), and the respondent got the generic
 * error page with their input gone. FormField::MAX_ANSWER_LENGTH now
 * refuses it in the service, naming the field, and the largest answer it
 * lets through — every character the widest UTF-8 takes — still fits.
 */
#[Group('database')]
final class ResponseServiceAnswerLengthOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    /** The widest character UTF-8 encodes: four bytes. */
    private const WIDEST = "\u{1F600}";

    private \PDO $pdo;
    private FormResponseRepository $responses;
    private FormFieldRepository $fields;
    private Article $article;
    private NewsForm $form;
    private FormField $remarks;

    protected function setUp(): void
    {
        $this->pdo = $this->productionEngine();
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->responses = new FormResponseRepository($this->pdo, $encryption);
        $this->fields = new FormFieldRepository($this->pdo);

        $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)')
            ->execute(['enc', str_repeat('a', 64)]);
        $authorId = (int) $this->pdo->lastInsertId();
        $articles = new ArticleRepository($this->pdo);
        $articleId = $articles->create('Souper', Article::VISIBILITY_PUBLIC, true, null, null, $authorId);
        $article = $articles->findById($articleId);
        \assert($article !== null);
        $this->article = $article;

        $forms = new FormRepository($this->pdo);
        $formId = $forms->create(
            $articleId,
            NewsForm::ACCESS_PUBLIC,
            NewsForm::RESPONSE_LIMIT_UNLIMITED,
            null,
            null,
            false,
            'chief',
            null,
            null
        );
        $form = $forms->findById($formId);
        \assert($form !== null);
        $this->form = $form;

        $remarks = $this->fields->findById($this->fields->create(
            $formId,
            0,
            FormField::TYPE_LONG_TEXT,
            'Remarques',
            false,
            null,
            null,
            null,
            null,
            null
        ));
        \assert($remarks !== null);
        $this->remarks = $remarks;
    }

    private function service(): ResponseService
    {
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $connection = Connection::withPdo($this->pdo);
        $twig = \Core\View\TwigFactory::create(
            dirname(__DIR__, 4) . '/core/View/templates',
            false,
            ['news' => dirname(__DIR__, 4) . '/modules/news/views']
        );
        $twig->addGlobal('site_name', 'Test Unit');
        $mail = $this->createStub(MailService::class);

        return new ResponseService(
            $this->responses,
            new RoleResolver(new MemberYearRepository($this->pdo), $encryption, $this->pdo),
            new SectionService(
                new SectionRepository($connection),
                new MemberProfileRepository($connection, $encryption, new MemberBadgeRepository($this->pdo))
            ),
            $mail,
            EmailTemplateRendererFactory::shippedOnlyForModule($twig, 'news'),
            new ShortUrlService(new ShortUrlRepository($this->pdo, $encryption)),
            'https://example.com',
            'Test Unit',
            fieldRepository: $this->fields
        );
    }

    /** @param array<int, string> $answers */
    private function submit(array $answers): int
    {
        return $this->service()->submit(
            $this->article,
            $this->form,
            [$this->remarks],
            null,
            null,
            1,
            'parent@example.com',
            $answers,
            null
        )->id;
    }

    /**
     * The premise, asserted rather than assumed: this engine refuses a
     * value wider than the column. Were it SQLite, nothing below would
     * prove anything.
     */
    public function testThisEngineRefusesAValueWiderThanTheColumn(): void
    {
        $responseId = $this->responses->create($this->form->id, null, null, 'a@test.com', [], null, null);

        try {
            $this->pdo->prepare('INSERT INTO news_form_response_values (response_id, field_id, value) VALUES (?, ?, ?)')
                ->execute([$responseId, $this->remarks->id, str_repeat('a', 65536)]);
            $this->fail('the column accepted 65 536 bytes; the cap below would be judged against nothing');
        } catch (\PDOException $e) {
            $this->assertSame(1406, (int) ($e->errorInfo[1] ?? 0), $e->getMessage());
        }
    }

    /** The scenario of the issue: a pasted document, refused by name — no longer by the engine. */
    public function testAPastedDocumentIsRefusedWithTheFieldsLabelAndTheLimit(): void
    {
        try {
            $this->submit([$this->remarks->id => str_repeat('é', 40000)]);
            $this->fail('an answer of 40 000 characters was accepted');
        } catch (FieldAnswerException $e) {
            $this->assertSame($this->remarks->id, $e->fieldId);
            $this->assertStringContainsString('"Remarques"', $e->getMessage());
            $this->assertStringContainsString('10 000 caractères', $e->getMessage());
        }

        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM news_form_responses')->fetchColumn());
    }

    public function testOneCharacterAboveTheCapIsRefused(): void
    {
        $this->expectException(FieldAnswerException::class);

        $this->submit([$this->remarks->id => str_repeat('a', FormField::MAX_ANSWER_LENGTH + 1)]);
    }

    /**
     * The worst case the cap admits: every character four bytes wide. It
     * must reach the column whole and decrypt back to what was typed.
     */
    public function testTheWidestAnswerTheCapAdmitsIsStoredAndReadBack(): void
    {
        $answer = str_repeat(self::WIDEST, FormField::MAX_ANSWER_LENGTH);

        $responseId = $this->submit([$this->remarks->id => $answer]);

        $this->assertSame([$this->remarks->id => $answer], $this->responses->getValues($responseId));
    }

    /**
     * A textarea's `maxlength` counts a line break as one character and
     * the browser then sends it as two: an answer the page accepted must
     * not be refused for its line breaks.
     */
    public function testLineBreaksAsTheBrowserSendsThemCountOnce(): void
    {
        $line = str_repeat('a', 99);
        $answer = implode("\r\n", array_fill(0, FormField::MAX_ANSWER_LENGTH / 100, $line));

        $responseId = $this->submit([$this->remarks->id => $answer]);

        $this->assertSame($answer, $this->responses->getValues($responseId)[$this->remarks->id]);
    }

    /** The edit is refused the same way, and the stored answer is left as it was. */
    public function testAnEditAboveTheCapIsRefusedAndLeavesTheAnswerAsItWas(): void
    {
        $responseId = $this->submit([$this->remarks->id => 'Allergique aux arachides.']);
        $response = $this->responses->findById($responseId);
        \assert($response !== null);

        try {
            $this->service()->update(
                $response,
                $this->form,
                [$this->remarks],
                'parent@example.com',
                [$this->remarks->id => str_repeat('a', 70000)],
                null,
                1
            );
            $this->fail('an edit of 70 000 characters was accepted');
        } catch (FieldAnswerException $e) {
            $this->assertStringContainsString('"Remarques"', $e->getMessage());
        }

        $this->assertSame(
            [$this->remarks->id => 'Allergique aux arachides.'],
            $this->responses->getValues($responseId)
        );
    }
}
