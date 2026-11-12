<?php

declare(strict_types=1);

namespace Tests\Modules\News\Service;

use Core\File\FileRepository;
use Core\File\UploadHandler;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Security\EncryptionService;
use Core\Url\ShortUrlRepository;
use Core\Url\ShortUrlService;
use Modules\News\Api\ImportedArticle;
use Modules\News\Api\ImportedFormField;
use Modules\News\Api\ImportedImage;
use Modules\News\Api\NewsImportWriterInterface;
use Modules\News\File\NewsCoverOwnershipChecker;
use Modules\News\Repository\Article;
use Modules\News\Repository\ArticleRepository;
use Modules\News\Repository\FormField;
use Modules\News\Repository\FormFieldRepository;
use Modules\News\Repository\FormRepository;
use Modules\News\Repository\FormResponseRepository;
use Modules\News\Service\DigestService;
use Modules\News\Service\NewsImportWriter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Core\Mail\Template\EmailTemplateRendererFactory;
use Tests\DatabaseTestHelper;
use Tests\Modules\News\NewsTestHelper;
use Tests\TestTwig;

/**
 * The news module's write contract for the data migration (chantier
 * « Migration de données », lot D): history written with the module's
 * rules — sanitizer, upload handler, cover access, short link, journal —
 * and none of its side effects — no notice, no scheduled task, no digest,
 * no ticket, no receivable.
 */
#[Group('database')]
final class NewsImportWriterTest extends TestCase
{
    private \PDO $pdo;
    private string $storage;
    private int $adminId;
    private ArticleRepository $articles;
    private FormRepository $forms;
    private FormFieldRepository $fields;
    private FileRepository $files;
    private NewsImportWriter $writer;
    /** @var list<string> */
    private array $temporary = [];

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        NewsTestHelper::createTables($this->pdo);
        $this->storage = sys_get_temp_dir() . '/news_import_' . bin2hex(random_bytes(6));
        mkdir($this->storage, 0700, true);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)')
            ->execute([$encryption->encrypt('admin@test.com', 'user_accounts.email'), $encryption->blindIndex('admin@test.com', 'email')]);
        $this->adminId = (int) $this->pdo->lastInsertId();

        $this->articles = new ArticleRepository($this->pdo);
        $this->forms = new FormRepository($this->pdo);
        $this->fields = new FormFieldRepository($this->pdo);
        $this->files = new FileRepository($this->pdo);
        $this->writer = new NewsImportWriter(
            $this->articles,
            $this->forms,
            $this->fields,
            new ShortUrlService(new ShortUrlRepository($this->pdo, $encryption)),
            $this->files,
            new UploadHandler($this->files, $this->storage),
            $this->storage,
            new JournalService(new JournalRepository($this->pdo))
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->temporary as $path) {
            @unlink($path);
        }
        exec('rm -rf ' . escapeshellarg($this->storage));
    }

    public function testAnArticleIsCreatedOnlineAtItsHistoricalDateAndLocked(): void
    {
        $id = $this->writer->importArticle($this->article(publishedAt: '2023-03-02T18:00:00+01:00'));

        $article = $this->articles->findById($id) ?? $this->fail('No article.');
        $this->assertSame('Souper aux spaghettis 2023', $article->title);
        $this->assertSame(Article::STATUS_PUBLISHED, $article->status);
        $this->assertSame(
            (new \DateTimeImmutable('2023-03-02T18:00:00+01:00'))
                ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
                ->format('Y-m-d H:i:s'),
            $article->publishedAt
        );
        $this->assertTrue($article->isImported());
        $this->assertSame('scoutmagic-news-history:ancien-site', $article->importSource);
        $this->assertSame(Article::VISIBILITY_IDENTIFIED, $article->visibility);
        $this->assertFalse($article->isIndexed);
        $this->assertNotNull($article->shortUrlCode, 'The poster and the share link need the short link.');
        $this->assertTrue($article->hasForm);
        $this->assertSame($this->adminId, $article->createdBy);
    }

    /**
     * No side effect: the scheduling notice stays down, nothing is
     * scheduled, and the form is one the digest, the ticket tasks and the
     * receivables never read.
     */
    public function testNothingIsAnnouncedScheduledOrSent(): void
    {
        $id = $this->writer->importArticle($this->article());
        $formId = $this->writer->importForm($id, [new ImportedFormField('short_text', 'Nom du groupe', true)], $this->adminId);

        $notice = $this->pdo->query('SELECT publication_notice_pending FROM news_articles WHERE id = ' . $id)->fetchColumn();
        $this->assertSame(0, (int) $notice);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM scheduled_actions')->fetchColumn());

        $form = $this->forms->findById($formId) ?? $this->fail('No form.');
        $this->assertTrue($form->isForceClosed);
        $this->assertFalse($form->isOpen());
        $this->assertNull($form->digestEmail);
        $this->assertFalse($form->issuesTicket);
        $this->assertNull($form->financeAccountId);
        $this->assertNull($form->financeCategoryId);
        $this->assertSame([], $this->forms->findAllWithDigestEmail());
        $this->assertSame([], $this->forms->findAllIssuingTickets());

        // Even a response nobody could have given here: the daily digest
        // does not see the form.
        $responses = new FormResponseRepository($this->pdo, new EncryptionService(str_repeat('a', 32), str_repeat('b', 32)));
        $responses->create($formId, null, null, 'parent@test.com', [], null, null);
        $mail = $this->createMock(MailService::class);
        $mail->expects($this->never())->method('send');
        (new DigestService(
            $this->forms,
            $responses,
            $this->articles,
            $mail,
            EmailTemplateRendererFactory::shippedOnlyForModule(TestTwig::create(['news']), 'news'),
            'Test Unit',
            'https://example.com'
        ))->sendPendingDigests();
    }

    public function testTheBodyIsTheFormsFirstTextFieldSanitizedWithItsImagesStored(): void
    {
        $inline = $this->image('png');
        $id = $this->writer->importArticle($this->article(
            bodyHtml: '<p onclick="x()">Merci <img src="media/abc" alt="La salle"> '
                . '<img src="https://ancien.example/photo.jpg"></p><script>alert(1)</script>',
            bodyImages: ['media/abc' => new ImportedImage($inline, 'abc.png')]
        ));

        $form = $this->forms->findByArticleId($id) ?? $this->fail('No form.');
        $fields = $this->fields->findByFormId($form->id);
        $this->assertCount(1, $fields);
        $this->assertSame(FormField::TYPE_TEXT, $fields[0]->fieldType);
        $body = (string) $fields[0]->confirmationText;

        $this->assertStringNotContainsString('<script', $body);
        $this->assertStringNotContainsString('onclick', $body);
        $this->assertStringNotContainsString('ancien.example', $body, 'An image the archive does not carry is dropped.');
        $this->assertStringNotContainsString('media/abc', $body);
        $this->assertMatchesRegularExpression('~<img[^>]*src="/files/(\d+)"~', $body);
        preg_match('~src="/files/(\d+)"~', $body, $match);

        // The editor stores a body image `public`, whatever the article's
        // visibility (NewsController::uploadBodyImage()): so does this.
        $file = $this->files->findById((int) $match[1]) ?? $this->fail('No file.');
        $this->assertSame('public', $file->roleMin);
        $this->assertFileExists($this->storage . '/' . $file->relativePath);
    }

    public function testTheCoverCarriesTheVisibilitysFloorAndBelongsToItsArticle(): void
    {
        $id = $this->writer->importArticle($this->article(visibility: 'chief', cover: new ImportedImage($this->image('jpeg'), 'cover.jpg')));

        $article = $this->articles->findById($id) ?? $this->fail('No article.');
        $cover = $this->files->findById((int) $article->imageFileId) ?? $this->fail('No cover.');
        $this->assertSame('chief', $cover->roleMin);
        $this->assertSame(NewsCoverOwnershipChecker::OWNER_TYPE, $cover->ownerType);
        $this->assertSame($id, $cover->ownerId);
    }

    /** D4: no summary and no image are accepted for an imported article — nothing invented. */
    public function testAnArticleWithoutSummaryOrImageKeepsNeither(): void
    {
        $id = $this->writer->importArticle($this->article(summary: null, cover: null, bodyHtml: ''));

        $article = $this->articles->findById($id) ?? $this->fail('No article.');
        $this->assertNull($article->summary);
        $this->assertNull($article->imageFileId);
        $form = $this->forms->findByArticleId($id) ?? $this->fail('No form.');
        $this->assertSame([], $this->fields->findByFormId($form->id), 'No empty body block is invented either.');
    }

    public function testAValueOutsideTheRulesIsRefusedNeverCorrected(): void
    {
        foreach ([
            'unknown visibility' => $this->article(visibility: 'members'),
            'future date' => $this->article(publishedAt: '2999-01-01T00:00:00+00:00'),
            'empty title' => $this->article(title: '   '),
            'long summary' => $this->article(summary: str_repeat('a', 301)),
        ] as $case => $article) {
            try {
                $this->writer->importArticle($article);
                $this->fail('Accepted: ' . $case);
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM news_articles')->fetchColumn());
    }

    public function testAFailedImageLeavesNoFileOnDisk(): void
    {
        $notAnImage = tempnam(sys_get_temp_dir(), 'nimg');
        file_put_contents($notAnImage, 'pas une image');
        $this->temporary[] = $notAnImage;

        try {
            $this->writer->importArticle($this->article(
                bodyHtml: '<p><img src="media/a"></p>',
                bodyImages: ['media/a' => new ImportedImage($this->image('png'), 'a.png')],
                cover: null
            ));
            $this->writer->importArticle($this->article(
                cover: new ImportedImage($this->image('jpeg'), 'ok.jpg'),
                bodyHtml: '<p><img src="media/b"></p>',
                bodyImages: ['media/b' => new ImportedImage($notAnImage, 'b.png')]
            ));
            $this->fail('A file that is not an image was stored.');
        } catch (\RuntimeException) {
            // The first article's own image is still there; the second's
            // cover, stored before its body image failed, is not.
        }

        $onDisk = array_values(array_filter(
            (array) glob($this->storage . '/news/images/*'),
            static fn ($path): bool => is_string($path) && is_file($path)
        ));
        $this->assertCount(1, $onDisk);
    }

    public function testTheQuestionsComeAfterTheBodyInTheOldOrder(): void
    {
        $id = $this->writer->importArticle($this->article(bodyHtml: '<p>Le texte</p>'));
        $this->assertTrue($this->writer->acceptsImportedForm($id));

        $formId = $this->writer->importForm($id, [
            new ImportedFormField('number', 'Nombre d\'adultes', true),
            new ImportedFormField('radio', 'Menu', false, ['Bolognaise', 'Végétarien']),
            new ImportedFormField('text', null, false, [], '<p>Merci</p><script>x</script>'),
        ], $this->adminId);

        $fields = $this->fields->findByFormId($formId);
        $this->assertSame(['text', 'number', 'radio', 'text'], array_map(static fn (FormField $f): string => $f->fieldType, $fields));
        $this->assertSame('Nombre d\'adultes', $fields[1]->label);
        $this->assertTrue($fields[1]->isRequired);
        $this->assertSame(['Bolognaise', 'Végétarien'], $fields[2]->manualOptions());
        $this->assertStringNotContainsString('<script', (string) $fields[3]->confirmationText);
        $this->assertTrue(($this->forms->findById($formId) ?? $this->fail('No form.'))->isForceClosed);

        $this->assertFalse($this->writer->acceptsImportedForm($id), 'An article has one form.');
        $this->expectException(\InvalidArgumentException::class);
        $this->writer->importForm($id, [new ImportedFormField('short_text', 'Encore', false)], $this->adminId);
    }

    public function testAFormIsOnlyEverAddedToAnImportedArticle(): void
    {
        $native = NewsTestHelper::publish($this->pdo, $this->articles->create('Écrite ici', 'public', false, null, null, $this->adminId, 'Résumé', null));

        $this->assertFalse($this->writer->acceptsImportedForm($native));
        $this->expectException(\InvalidArgumentException::class);
        $this->writer->importForm($native, [new ImportedFormField('short_text', 'Nom', false)], $this->adminId);
    }

    public function testAQuestionOutsideTheRulesIsRefused(): void
    {
        $id = $this->writer->importArticle($this->article());

        foreach ([
            [new ImportedFormField('signature', 'Signez', false)],
            [new ImportedFormField('short_text', 'Âge <12 ans', false)],
            [new ImportedFormField('radio', 'Menu', false, [])],
            [new ImportedFormField('text', null, false, [], '<p>Rien à remplir</p>')],
        ] as $fields) {
            try {
                $this->writer->importForm($id, $fields, $this->adminId);
                $this->fail('Accepted: ' . $fields[0]->type);
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertTrue($this->writer->acceptsImportedForm($id));
    }

    /**
     * @param array<string, ImportedImage> $bodyImages
     */
    private function article(
        string $title = 'Souper aux spaghettis 2023',
        ?string $summary = 'Merci aux convives !',
        string $bodyHtml = '<p>Merci à tous.</p>',
        string $publishedAt = '2023-03-02T18:00:00+01:00',
        string $visibility = NewsImportWriterInterface::PROTECTIVE_VISIBILITY,
        ?ImportedImage $cover = null,
        array $bodyImages = []
    ): ImportedArticle {
        return new ImportedArticle(
            $title,
            $summary,
            $bodyHtml,
            new \DateTimeImmutable($publishedAt),
            $visibility,
            $cover,
            $bodyImages,
            'scoutmagic-news-history:ancien-site',
            $this->adminId
        );
    }

    private function image(string $type): string
    {
        $image = imagecreatetruecolor(40, 30);
        $path = tempnam(sys_get_temp_dir(), 'nimg');
        $this->temporary[] = $path;
        $type === 'png' ? imagepng($image, $path) : imagejpeg($image, $path);

        return $path;
    }
}
