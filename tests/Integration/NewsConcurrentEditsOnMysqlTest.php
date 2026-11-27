<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Integration;

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
use Modules\Finance\Api\FinanceAccountInterface;
use Modules\Finance\Repository\ExpectedReceivableRepository;
use Modules\Finance\Service\SepaQrCodeService;
use Modules\Finance\Service\StructuredCommunicationService;
use Modules\News\Repository\Article;
use Modules\News\Repository\ArticleRepository;
use Modules\News\Repository\FormField;
use Modules\News\Repository\FormFieldRepository;
use Modules\News\Repository\FormRepository;
use Modules\News\Repository\FormResponseRepository;
use Modules\News\Repository\NewsForm;
use Modules\News\Service\ResponseService;
use Modules\News\Service\TicketMailService;
use Modules\News\Service\TicketService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Core\Mail\Template\EmailTemplateRendererFactory;
use Tests\Modules\Finance\FinanceTestHelper;
use Tests\UsesProductionEngine;

/**
 * Two edits of one free response racing to make it a paying one (issue
 * #1525), on the engine whose snapshots make the race possible, through
 * the REAL finance service.
 *
 * The first edit's transaction fixes its snapshot at its first read. The
 * second edit, on another connection, then raises the receivable and
 * commits. When the first edit resumes, its locking read of the response
 * sees the new link — and finance, called inside that same transaction,
 * must see the receivable too: read from the snapshot it does not exist
 * yet, the edit would take it for one deleted by hand and raise a second.
 * One receivable must exist at the end, linked to the response, at the
 * amount of the edit that committed last.
 */
#[Group('database')]
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
final class NewsConcurrentEditsOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private \PDO $pdo;
    private int $accountId;
    private int $formId;
    private int $fieldId;
    private int $responseId;
    private Article $article;

    protected function setUp(): void
    {
        $this->pdo = $this->productionEngine();
        $encryption = self::encryption();

        $this->pdo->exec("INSERT INTO finance_accounts (name, account_type, status) VALUES ('Unité', 'bank', 'active')");
        $this->accountId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO finance_categories (name) VALUES ('Soupers')");
        $categoryId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)')
            ->execute(['enc', str_repeat('a', 64)]);
        $authorId = (int) $this->pdo->lastInsertId();
        $articles = new ArticleRepository($this->pdo);
        $articleId = $articles->create('Souper', Article::VISIBILITY_PUBLIC, true, null, null, $authorId);
        $this->article = $articles->findById($articleId);
        $this->formId = (new FormRepository($this->pdo))->create(
            $articleId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED,
            null, null, false, 'chief', null, $this->accountId, false, null, null, $categoryId
        );
        $this->fieldId = (new FormFieldRepository($this->pdo))
            ->create($this->formId, 0, FormField::TYPE_NUMBER, 'Adultes', false, null, null, null, 18.0, null);
        $this->responseId = (new FormResponseRepository($this->pdo, $encryption))
            ->create($this->formId, null, null, 'famille@test.com', [$this->fieldId => '0'], null, null);
    }

    /** The premise: were this SQLite, there would be no snapshot and no race. */
    public function testThisEngineTakesTheLockingBranch(): void
    {
        $this->assertNotSame('sqlite', $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME));
    }

    public function testTwoEditsMakingAFreeResponsePayingRaiseOneReceivable(): void
    {
        $database = (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        $other = self::productionEngineConnection($database)->getPdo();

        // The first edit, paused right after its transaction's first read
        // — its snapshot — while the second edit runs to its commit.
        $raced = false;
        $first = new class ($this->pdo, self::encryption()) extends FormResponseRepository {
            /** @var ?\Closure(): void */
            public ?\Closure $onFirstReadInTransaction = null;
            private \PDO $connection;

            public function __construct(\PDO $pdo, EncryptionService $encryption)
            {
                parent::__construct($pdo, $encryption);
                $this->connection = $pdo;
            }

            public function getValues(int $responseId): array
            {
                $values = parent::getValues($responseId);
                if ($this->onFirstReadInTransaction !== null && $this->connection->inTransaction()) {
                    $hook = $this->onFirstReadInTransaction;
                    $this->onFirstReadInTransaction = null;
                    $hook();
                }

                return $values;
            }
        };
        $first->onFirstReadInTransaction = function () use ($other, &$raced): void {
            $this->edit($other, new FormResponseRepository($other, self::encryption()), '3');
            $raced = true;
        };

        $this->edit($this->pdo, $first, '2');

        $this->assertTrue($raced, 'the second edit ran inside the first one');
        $receivables = $this->pdo->query('SELECT id, amount_due_cents FROM finance_expected_receivables')
            ->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(1, $receivables, 'one response, one receivable');
        $linked = (new FormResponseRepository($this->pdo, self::encryption()))->findById($this->responseId);
        $this->assertSame((int) $receivables[0]['id'], $linked?->receivableId);
        $this->assertSame(3600, (int) $receivables[0]['amount_due_cents'], 'the edit that committed last sets the amount');
    }

    private function edit(\PDO $pdo, FormResponseRepository $responses, string $quantity): void
    {
        $fields = new FormFieldRepository($pdo);
        $response = $responses->findById($this->responseId);
        \assert($response !== null);
        $form = (new FormRepository($pdo))->findById($this->formId);
        \assert($form !== null);

        $this->service($pdo, $responses, $fields)->update(
            $response, $form, [$fields->findById($this->fieldId)], 'famille@test.com',
            [$this->fieldId => $quantity], null, 1
        );
    }

    private function service(\PDO $pdo, FormResponseRepository $responses, FormFieldRepository $fields): ResponseService
    {
        $encryption = self::encryption();
        $connection = Connection::withPdo($pdo);
        $twig = \Core\View\TwigFactory::create(
            dirname(__DIR__, 2) . '/core/View/templates',
            false,
            ['news' => dirname(__DIR__, 2) . '/modules/news/views']
        );
        $renderer = EmailTemplateRendererFactory::shippedOnlyForModule($twig, 'news');
        $mail = $this->createStub(MailService::class);
        $financeAccount = $this->createStub(FinanceAccountInterface::class);
        $financeAccount->method('getConfiguredAccounts')->willReturn([
            ['id' => $this->accountId, 'name' => 'Unité', 'iban' => 'BE68539007547034', 'holder_name' => 'Unité', 'section_id' => null],
        ]);
        $receivableRepository = new ExpectedReceivableRepository($pdo, $encryption);

        return new ResponseService(
            $responses,
            new RoleResolver(new MemberYearRepository($pdo), $encryption, $pdo),
            new SectionService(
                new SectionRepository($connection),
                new MemberProfileRepository($connection, $encryption, new MemberBadgeRepository($pdo))
            ),
            $mail,
            $renderer,
            new ShortUrlService(new ShortUrlRepository($pdo, $encryption)),
            'https://example.com',
            'Test Unit',
            new StructuredCommunicationService($receivableRepository),
            FinanceTestHelper::receivableService($pdo, $encryption, $receivableRepository),
            new SepaQrCodeService(),
            $financeAccount,
            null,
            new TicketService($responses),
            new TicketMailService($mail, $renderer, 'Test Unit', null),
            $fields
        );
    }

    private static function encryption(): EncryptionService
    {
        return new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
    }
}
