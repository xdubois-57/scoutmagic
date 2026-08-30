<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Fixtures\ReferenceDataset;

use Core\File\FileRepository;
use Core\File\UploadHandler;
use Core\Security\EncryptionService;
use Core\Url\ShortUrlRepository;
use Core\Url\ShortUrlService;
use Core\View\EditableContentRepository;
use Core\View\EditableContentService;
use Modules\News\Repository\ArticleRepository;
use Modules\News\Repository\FormFieldRepository;
use Modules\News\Repository\FormRepository;
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
 * bytes, EXIF stripped by re-encoding, a `files` row with `role_min = public`
 * and `module_id = news`. The photo lot is the only image source this
 * repository has, so a section group photo doubles as a cover; the file is
 * copied first, exactly as the portrait pipeline does, because a handler that
 * consumes its input must never be handed a versioned fixture.
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

    /** @var list<string> MIME types NewsController accepts for an article image. */
    private const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    private const IMAGE_MAX_BYTES = 5 * 1024 * 1024;

    public function __construct(
        \PDO $pdo,
        EncryptionService $encryption,
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
            // The finance side of an article is only used when a form is
            // deleted (its receivables go with it). Nothing is deleted here.
            null,
        );
        $this->formService = new FormService($formRepository, new FormFieldRepository($pdo), $this->articleService);
        $this->responseRepository = new FormResponseRepository($pdo, $encryption);
        $this->uploadHandler = new UploadHandler(new FileRepository($pdo), $this->storagePath);
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
                $this->responseRepository->create($form->id, null, null, $response['email'], $values, null, null);
                $responses++;
            }
        }

        return ['articles' => $articles, 'forms' => $forms, 'responses' => $responses];
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
            return $this->uploadHandler->handle(
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
        } finally {
            if (is_file($copy)) {
                @unlink($copy);
            }
        }
    }
}
