<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Fixtures\ReferenceDataset;

use Core\File\FileRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\File\UploadHandler;
use Core\Photo\ImageVariantProcessor;
use Core\Photo\ImageVariantService;
use Core\Security\EncryptionService;
use Core\Url\ShortUrlRepository;
use Core\Url\ShortUrlService;
use Core\View\EditableContentRepository;
use Core\View\EditableContentService;
use Modules\News\Repository\ArticleRepository;
use Modules\News\Repository\FormFieldRepository;
use Modules\News\Repository\FormRepository;
use Modules\News\Repository\NewsForm;
use Modules\News\Repository\FormResponseRepository;
use Modules\News\Service\ArticleService;
use Modules\News\Service\FormService;

/**
 * Publishes NewsBlueprint's articles through the news module's own services.
 *
 * Three things are worth knowing about how this is wired.
 *
 * **The cover and the in-body image go through Core\File\UploadHandler**, the
 * same call `NewsController::uploadImage()` makes — MIME sniffed from the
 * bytes, EXIF stripped by re-encoding, a `files` row with `module_id = news`.
 * The photo lot is the only image source this repository has, so a section
 * group photo doubles as a cover; the file is copied first, exactly as the
 * portrait pipeline does, because a handler that consumes its input must
 * never be handed a versioned fixture.
 *
 * **Neither upload picks its own `role_min`, and that is the point** (issue
 * #211). The in-body image is `public` because the product's own
 * `NewsController::uploadBodyImage()` stores body images `public` — the
 * article's visibility is not known when the editor uploads one. The cover is
 * uploaded `public` and then put on its real floor by
 * `Service\ArticleService::create()`, which is the only thing that knows the
 * mapping. A fixture that spelled the floor out itself would be a second copy
 * of a product rule, free to drift from it — and it did: every cover here was
 * `public`, staff articles included, so an assertion about what leaks would
 * have measured the fixture instead of the site.
 *
 * **The body is rich text under `news_body_{id}`**, written through
 * Core\View\EditableContentService with type `rich_text` — so the sanitizer
 * runs, and the module's documented storage key is the one used. The in-body
 * `<img>` can only be written after the upload, since it points at
 * `/files/{id}`.
 *
 * **A response is written through Repository\FormResponseRepository, not
 * through Service\ResponseService**, and that is a deliberate, narrow
 * exception. `submit()` is a REQUEST: it wants a signed-in account to check
 * the one-per-account limit against, a Twig environment and a mailbox to send
 * the confirmation to. A CLI build has none of the three, and giving it a
 * mailbox would mean a dataset build that sends mail to fictional families.
 * The repository is still the module's own code, still encrypts the contact
 * address and still writes its blind index — it is one layer down, never a
 * hand-written INSERT.
 */
final class NewsSeeder
{
    private readonly ArticleService $articleService;

    private readonly FormService $formService;

    private readonly FormResponseRepository $responseRepository;

    private readonly EditableContentService $editableContent;

    private readonly UploadHandler $uploadHandler;

    private readonly ImageVariantService $imageVariantService;

    private readonly JournalService $journalService;

    /** @var list<string> MIME types NewsController accepts for an article image. */
    private const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    private const IMAGE_MAX_BYTES = 5 * 1024 * 1024;

    public function __construct(
        private readonly \PDO $pdo,
        private readonly EncryptionService $encryption,
        private readonly string $storagePath,
        private readonly string $datasetRoot,
        private readonly int $authorId,
    ) {
        $articleRepository = new ArticleRepository($pdo);
        $formRepository = new FormRepository($pdo);
        $this->editableContent = new EditableContentService(new EditableContentRepository($pdo));

        $this->articleService = new ArticleService(
            $articleRepository,
            $formRepository,
            $this->editableContent,
            new ShortUrlService(new ShortUrlRepository($pdo, $encryption)),
            // Not decoration: this is what puts each cover on the role_min
            // its article's visibility calls for, on the way out of
            // create() (issue #211).
            new FileRepository($pdo),
            // The finance side of an article is only used when a form is
            // deleted (its receivables go with it). Nothing is deleted here.
            null,
        );
        $this->formService = new FormService($formRepository, new FormFieldRepository($pdo), $this->articleService, new FormResponseRepository($pdo, $encryption));
        $this->responseRepository = new FormResponseRepository($pdo, $encryption);
        $fileRepository = new FileRepository($pdo);
        $this->uploadHandler = new UploadHandler($fileRepository, $this->storagePath);
        $this->imageVariantService = new ImageVariantService($fileRepository, new ImageVariantProcessor(), $this->storagePath);
        $this->journalService = new JournalService(new JournalRepository($pdo));
    }

    /**
     * @return array{articles: int, forms: int, responses: int}
     */
    public function seed(): array
    {
        $articles = 0;
        $forms = 0;
        $responses = 0;

        foreach (NewsBlueprint::ARTICLES as $declared) {
            $article = $this->articleService->create(
                $declared['title'],
                $declared['visibility'],
                $declared['isIndexed'],
                $declared['seoKeywords'],
                null,
                $this->authorId,
                $declared['summary'],
                $this->upload($declared['cover']),
            );
            $articles++;

            // Controller\NewsController::create() journals every article it
            // publishes; ArticleService does not. Five articles the journal
            // never mentioned is exactly the gap this dataset exists to make
            // visible rather than to reproduce.
            $this->journalService->log(
                'news',
                'article_created',
                'info',
                "Article « {$article->title} » créé",
                ['article_id' => $article->id],
                $this->authorId,
            );

            $this->editableContent->set(
                ArticleService::bodyContentKey($article->id),
                $this->bodyOf($declared),
                'rich_text',
                $this->authorId,
            );

            if ($declared['form'] === null) {
                continue;
            }

            $form = $this->formService->save(
                $article->id,
                [
                    'access' => $declared['form']['access'],
                    'response_limit' => $declared['form']['responseLimit'],
                    'opens_at' => null,
                    'closes_at' => null,
                    'is_force_closed' => false,
                    'response_role_min' => $declared['form']['responseRoleMin'],
                    'daily_digest_enabled' => $declared['form']['dailyDigest'],
                    'finance_account_id' => null,
                ],
                array_map(
                    static fn (array $field): array => $field + ['id' => null],
                    $declared['form']['fields'],
                ),
            );
            $forms++;

            $fieldIds = array_map(
                static fn (\Modules\News\Repository\FormField $field): int => $field->id,
                $this->formService->getFields($form->id),
            );

            foreach ($declared['form']['responses'] as $response) {
                $values = [];
                foreach ($response['answers'] as $index => $answer) {
                    if (isset($fieldIds[$index]) && $answer !== '') {
                        $values[$fieldIds[$index]] = $answer;
                    }
                }

                $identity = $this->identityOf($response['tiers'], $response['email']);
                if ($form->access === NewsForm::ACCESS_IDENTIFIED && $identity['accountId'] === null) {
                    // Exactly what Service\ResponseService::submit() does with
                    // a response nobody is logged in for on an `identified`
                    // form: it refuses it. Writing the row anyway produced a
                    // state the application cannot reach — and the page that
                    // lists the responses then shows a submitter it cannot
                    // name.
                    continue;
                }

                $responseId = $this->responseRepository->create(
                    $form->id,
                    $identity['accountId'],
                    null,
                    $identity['email'],
                    $values,
                    null,
                    null,
                );
                $responses++;

                // The line Controller\FormController writes after every
                // submission. The confirmation e-mail is why this seeder goes
                // through the repository rather than submit() (README §8.3);
                // the journal entry was never part of that reason.
                $this->journalService->log(
                    'news',
                    'form_response_submitted',
                    'info',
                    "Réponse soumise pour l'article « {$article->title} »",
                    ['article_id' => $article->id, 'form_id' => $form->id, 'response_id' => $responseId],
                    $identity['accountId'],
                );
            }
        }

        return ['articles' => $articles, 'forms' => $forms, 'responses' => $responses];
    }

    /**
     * Who a declared response belongs to.
     *
     * A response on an `identified` form has an account behind it — that is
     * the whole meaning of the access level — so the blueprint names a
     * member by Tiers and this resolves the account the Desk import created
     * for that member's address. The link between the two is the e-mail
     * blind index, which is how the application itself finds an account
     * from a member (Core\Security\UserAccountRepository::findByBlindIndex(),
     * Core\Import\DeskImportService::ensureUserAccount()).
     *
     * A `public` form's responses carry a plain address and no account,
     * which is a state submit() produces every day.
     *
     * @return array{accountId: ?int, email: string}
     */
    private function identityOf(?string $tiers, ?string $email): array
    {
        if ($tiers === null) {
            return ['accountId' => null, 'email' => (string) $email];
        }

        $statement = $this->pdo->prepare(
            'SELECT my.email_encrypted, ua.id AS account_id
             FROM members m
             JOIN member_years my ON my.member_id = m.id
             LEFT JOIN user_accounts ua ON ua.email_blind_index = my.email_blind_index
             WHERE m.desk_id = ? AND my.email_blind_index IS NOT NULL
             ORDER BY my.scout_year_id DESC
             LIMIT 1'
        );
        $statement->execute([$tiers]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return ['accountId' => null, 'email' => (string) $email];
        }

        return [
            'accountId' => $row['account_id'] !== null ? (int) $row['account_id'] : null,
            'email' => $this->encryption->decrypt($row['email_encrypted'], 'member_years.email'),
        ];
    }

    /**
     * The article's rich-text body, with the in-body image substituted for
     * its placeholder once the file exists.
     *
     * @param array{bodyImage: ?string, body: string} $declared
     */
    private function bodyOf(array $declared): string
    {
        if ($declared['bodyImage'] === null) {
            return str_replace(NewsBlueprint::BODY_IMAGE_PLACEHOLDER, '', $declared['body']);
        }

        $fileId = $this->upload($declared['bodyImage']);

        return str_replace(
            NewsBlueprint::BODY_IMAGE_PLACEHOLDER,
            '<p><img src="/files/' . $fileId . '" alt="Photo de section"></p>',
            $declared['body'],
        );
    }

    /**
     * Uploads one photo of the lot and returns its `files` id.
     *
     * A COPY, for the reason the portraits are copied: UploadHandler moves or
     * re-encodes what it is handed, and pointing it at the versioned lot
     * would consume it.
     *
     * `public` is the floor a body image keeps for good (the product does the
     * same) and the one a cover holds only until `ArticleService::create()`
     * re-applies the article's own — see the class docblock.
     */
    private function upload(string $filename): int
    {
        $source = $this->datasetRoot . '/' . PhotoLot::DIRECTORY . '/' . $filename;
        if (!is_file($source)) {
            throw new \RuntimeException("Image d'article introuvable : {$source}");
        }

        $copy = (string) tempnam(sys_get_temp_dir(), 'refdataset-news');
        copy($source, $copy);

        try {
            $fileId = $this->uploadHandler->handle(
                [
                    'name' => $filename,
                    'type' => 'image/jpeg',
                    'tmp_name' => $copy,
                    'error' => UPLOAD_ERR_OK,
                    'size' => (int) filesize($copy),
                ],
                'news/images',
                self::IMAGE_MIMES,
                self::IMAGE_MAX_BYTES,
                'public',
                'news',
                $this->authorId,
            );

            // The other half of the real upload path, and the half this
            // seeder used to skip: Modules\News\Controller\NewsController::
            // resolveUploadedImageFileId() generates every derivative right
            // after handle(), because the cards render the 192px thumb and
            // the detail page the 1024px md rendition — and
            // Core\Http\Controller\FileController::variant() answers 404
            // rather than falling back to the original when one is missing,
            // deliberately. A dataset built without them served a broken
            // image on every news card until a cron pass caught up, and
            // never again after a `--reset` (the backfill flag survived the
            // wipe). generate() never throws; a derivative that cannot be
            // produced must not fail the upload.
            foreach (ImageVariantService::VARIANTS as $variant) {
                $this->imageVariantService->generate($fileId, $variant);
            }

            return $fileId;
        } finally {
            if (is_file($copy)) {
                @unlink($copy);
            }
        }
    }
}
