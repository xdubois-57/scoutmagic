<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\News\Service;

use Core\File\FileRepository;
use Core\File\UploadHandler;
use Core\Journal\JournalService;
use Core\Photo\ImageVariantService;
use Core\Security\HtmlSanitizer;
use Core\Url\ShortUrlService;
use Modules\News\Api\ImportedArticle;
use Modules\News\Api\ImportedFormField;
use Modules\News\Api\ImportedImage;
use Modules\News\Api\NewsImportWriterInterface;
use Modules\News\File\NewsCoverOwnershipChecker;
use Modules\News\Repository\Article;
use Modules\News\Repository\ArticleRepository;
use Modules\News\Repository\FormFieldRepository;
use Modules\News\Repository\FormRepository;
use Modules\News\Repository\NewsForm;

/**
 * {@see NewsImportWriterInterface}: history from the previous site, written
 * with this module's rules and without its side effects.
 *
 * It goes through the REPOSITORIES, never through ArticleService::create()
 * and the editor's intents: those demand a summary and a cover (which an
 * old article may not have, D4), start as a draft, and a scheduling arms
 * the author's « est en ligne » notice. What they apply besides — the
 * cover's `role_min` and owner, the short link, the sanitizer, the form's
 * access derived from the visibility — is applied here the same way.
 *
 * Nothing here sends anything or schedules anything:
 * `ArticleRepository::setPublication()` is called without `announce`, so
 * `publication_notice_pending` stays down and
 * Task\PublishScheduledArticlesHandler has nothing to claim; the form has no
 * digest address (DigestService reads only forms that have one) and does not
 * issue tickets (the ticket tasks and the door screen read only those that
 * do), and it has no finance account, so no response could ever raise a
 * receivable — and none can be given, the article being locked (D11).
 */
class NewsImportWriter implements NewsImportWriterInterface
{
    private const IMAGE_DIRECTORY = 'news/images';

    private HtmlSanitizer $sanitizer;

    /**
     * @param \Closure(): \DateTimeImmutable|null $clock
     */
    public function __construct(
        private ArticleRepository $articles,
        private FormRepository $forms,
        private FormFieldRepository $fields,
        private ShortUrlService $shortUrls,
        private FileRepository $files,
        private UploadHandler $uploads,
        private string $storagePath,
        private JournalService $journal,
        private ?ImageVariantService $imageVariants = null,
        private ?\Closure $clock = null
    ) {
        $this->sanitizer = new HtmlSanitizer();
    }

    public function importArticle(ImportedArticle $article): int
    {
        $title = trim($article->title);
        $summary = $article->summary !== null && trim($article->summary) !== '' ? trim($article->summary) : null;
        $this->assertArticle($article, $title, $summary);

        // Files are not in the caller's transaction: the ones stored before
        // a failure are taken off the disk again, and their rows go with
        // the rollback.
        $stored = [];
        try {
            $coverId = $article->coverImage !== null
                ? $this->storeImage(
                    $article->coverImage,
                    ArticleService::coverImageRoleMin($article->visibility),
                    $article->importedBy,
                    $stored
                )
                : null;
            $body = $this->body($article, $stored);

            $id = $this->articles->create(
                $title,
                $article->visibility,
                false,
                null,
                null,
                $article->importedBy,
                $summary,
                $coverId
            );
            // Online since its historical date — published, and never
            // announced (`announce` left false).
            $this->articles->setPublication(
                $id,
                Article::STATUS_PUBLISHED,
                $article->publishedAt
                    ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
                    ->format('Y-m-d H:i:s')
            );
            $this->articles->markImported($id, $this->now()->format('Y-m-d H:i:s'), $article->importSource);
            $this->articles->setShortUrlCode($id, $this->shortUrls->createShortUrl('/news/' . $id, $article->importedBy));

            // The cover belongs to its article, as ArticleService::
            // syncCoverImageAccess() makes it for one written here.
            if ($coverId !== null) {
                $this->files->updateOwner($coverId, NewsCoverOwnershipChecker::OWNER_TYPE, $id);
            }

            // The body lives where the module reads it: the form's first
            // `text` field (NewsController::withDefaultTextField()).
            $formId = $this->createClosedForm($id, $article->visibility);
            if ($body !== '') {
                $this->fields->create($formId, 0, 'text', null, false, null, null, null, null, $body);
            }
            $this->articles->setHasForm($id, true);
        } catch (\Throwable $e) {
            $this->removeStoredFiles($stored);
            throw $e;
        }

        $this->journal->log(
            'news',
            'article_imported',
            'info',
            'Actualité reprise de l\'ancien site, en lecture seule',
            ['article_id' => $id, 'images' => count($stored)],
            $article->importedBy
        );

        return $id;
    }

    public function acceptsImportedForm(int $articleId): bool
    {
        $article = $this->articles->findById($articleId);
        if ($article === null || !$article->isImported()) {
            return false;
        }

        $form = $this->forms->findByArticleId($articleId);
        if ($form === null) {
            return true;
        }

        foreach ($this->fields->findByFormId($form->id) as $field) {
            if (!$field->isNonInput()) {
                return false;
            }
        }

        return true;
    }

    public function importForm(int $articleId, array $fields, int $importedBy): int
    {
        if (!$this->acceptsImportedForm($articleId)) {
            throw new \InvalidArgumentException('Only an imported article whose form asks nothing yet takes imported questions.');
        }
        $rows = array_map(fn (ImportedFormField $field): array => $this->fieldRow($field), $fields);
        if (array_filter($rows, static fn (array $row): bool => !in_array($row['type'], self::NON_INPUT_FIELD_TYPES, true)) === []) {
            throw new \InvalidArgumentException('An imported form asks at least one question.');
        }

        $article = $this->articles->findById($articleId) ?? throw new \LogicException('Checked above.');
        $form = $this->forms->findByArticleId($articleId);
        $formId = $form !== null ? $form->id : $this->createClosedForm($articleId, $article->visibility);

        // After the body, in the old site's order.
        $order = count($this->fields->findByFormId($formId));
        foreach ($rows as $row) {
            $this->fields->create(
                $formId,
                $order++,
                $row['type'],
                $row['label'],
                $row['required'],
                $row['options'] !== null ? 'manual' : null,
                $row['options'],
                null,
                null,
                $row['content']
            );
        }
        $this->articles->setHasForm($articleId, true);

        $this->journal->log(
            'news',
            'form_imported',
            'info',
            'Formulaire repris de l\'ancien site, fermé et sans réponse',
            ['article_id' => $articleId, 'form_id' => $formId, 'questions' => count($rows)],
            $importedBy
        );

        return $formId;
    }

    /**
     * A form that takes nothing, ever: forced closed, no digest, no ticket,
     * no account, no category. Its access follows the visibility as the
     * editor's does (NewsController::extractFormSettings()), for the one
     * reader it serves — the page.
     */
    private function createClosedForm(int $articleId, string $visibility): int
    {
        $access = in_array($visibility, [Article::VISIBILITY_PUBLIC, Article::VISIBILITY_DIRECT_LINK], true)
            ? NewsForm::ACCESS_PUBLIC
            : NewsForm::ACCESS_IDENTIFIED;

        return $this->forms->create(
            $articleId,
            $access,
            NewsForm::RESPONSE_LIMIT_UNLIMITED,
            null,
            null,
            true,
            'chief',
            null,
            null,
            false,
            null,
            null,
            null
        );
    }

    private function assertArticle(ImportedArticle $article, string $title, ?string $summary): void
    {
        if ($title === '' || mb_strlen($title) > self::MAX_TITLE_LENGTH) {
            throw new \InvalidArgumentException('An imported article has a title of 1 to 255 characters.');
        }
        if ($summary !== null && mb_strlen($summary) > self::MAX_SUMMARY_LENGTH) {
            throw new \InvalidArgumentException('An imported summary is at most 300 characters.');
        }
        if (!in_array($article->visibility, self::VISIBILITIES, true)) {
            throw new \InvalidArgumentException('Unknown visibility for an imported article.');
        }
        if ($article->publishedAt > $this->now()) {
            throw new \InvalidArgumentException('An imported article was published in the past.');
        }
        $source = trim($article->importSource);
        if ($source === '' || strlen($source) > self::MAX_SOURCE_LENGTH) {
            throw new \InvalidArgumentException('An imported article names its source, in at most 255 bytes.');
        }
        if ($article->importedBy < 1) {
            throw new \InvalidArgumentException('An imported article is written by the account running the import.');
        }
    }

    /**
     * The body, sanitized, its images stored and pointed at — the editor's
     * own upload stores a body image `public`, whatever the article's
     * visibility (NewsController::uploadBodyImage()), and so does this. An
     * `<img>` the archive does not carry is dropped rather than left
     * pointing at the old site, which will not stay up and would learn who
     * reads the page meanwhile.
     *
     * Sanitized before the images are resolved, so the pattern below reads
     * the sanitizer's own serialisation (double quotes, one `src`), and
     * again after.
     *
     * @param list<int> $stored
     */
    private function body(ImportedArticle $article, array &$stored): string
    {
        $html = $this->sanitizer->sanitize($article->bodyHtml);
        if (str_contains($html, '<img')) {
            $resolved = [];
            $html = (string) preg_replace_callback(
                '~<img\b[^>]*>~i',
                function (array $tag) use ($article, &$stored, &$resolved): string {
                    if (preg_match('~\ssrc="([^"]*)"~i', $tag[0], $src) !== 1) {
                        return '';
                    }
                    $key = html_entity_decode($src[1], ENT_QUOTES | ENT_HTML5);
                    $image = $article->bodyImages[$key] ?? null;
                    if ($image === null) {
                        return '';
                    }
                    $resolved[$key] ??= $this->storeImage($image, 'public', $article->importedBy, $stored);

                    return str_replace($src[0], ' src="/files/' . $resolved[$key] . '"', $tag[0]);
                },
                $html
            );
            $html = $this->sanitizer->sanitize($html);
        }

        return trim($html);
    }

    /**
     * @param list<int> $stored the ids stored so far, this one appended
     */
    private function storeImage(ImportedImage $image, string $roleMin, int $importedBy, array &$stored): int
    {
        if (!is_file($image->path)) {
            throw new \InvalidArgumentException('An imported image is a readable file.');
        }

        try {
            $fileId = $this->uploads->handle(
                [
                    'tmp_name' => $image->path,
                    'name' => $image->name,
                    'size' => (int) filesize($image->path),
                    'error' => UPLOAD_ERR_OK,
                ],
                self::IMAGE_DIRECTORY,
                self::IMAGE_MIME_TYPES,
                self::MAX_IMAGE_BYTES,
                $roleMin,
                'news',
                $importedBy
            );
        } catch (\Core\File\UploadException $e) {
            throw new \RuntimeException('An imported image was refused by the upload handler.', 0, $e);
        }
        $stored[] = $fileId;

        // The derivatives, as the editor's upload makes them (#883).
        // generate() never throws.
        if ($this->imageVariants !== null) {
            foreach (ImageVariantService::VARIANTS as $variant) {
                $this->imageVariants->generate($fileId, $variant);
            }
        }

        return $fileId;
    }

    /**
     * @param list<int> $stored
     */
    private function removeStoredFiles(array $stored): void
    {
        foreach ($stored as $fileId) {
            $file = $this->files->findById($fileId);
            if ($file === null) {
                continue;
            }
            $paths = [$this->storagePath . '/' . $file->relativePath];
            foreach (ImageVariantService::VARIANTS as $variant) {
                $variantPath = $this->imageVariants?->resolvePath($file->relativePath, $variant);
                if ($variantPath !== null) {
                    $paths[] = $variantPath;
                }
            }
            foreach ($paths as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
        }
    }

    /**
     * One question, checked against the editor's own rules
     * (FormService::reconcileFields(), assertPlainLabel()).
     *
     * @return array{type: string, label: ?string, required: bool, options: ?string, content: ?string}
     */
    private function fieldRow(ImportedFormField $field): array
    {
        if (!in_array($field->type, self::FIELD_TYPES, true)) {
            throw new \InvalidArgumentException('Unknown question type.');
        }

        if (in_array($field->type, self::NON_INPUT_FIELD_TYPES, true)) {
            $content = $field->content !== null ? trim($field->content) : '';
            if ($field->type === 'text') {
                $content = trim($this->sanitizer->sanitizeWithoutImages($content));
            }

            return [
                'type' => $field->type,
                'label' => null,
                'required' => false,
                'options' => null,
                'content' => $content !== '' ? $content : null,
            ];
        }

        $label = $field->label !== null ? trim($field->label) : '';
        if ($label === '' || mb_strlen($label) > self::MAX_LABEL_LENGTH || strpbrk($label, '<>') !== false) {
            throw new \InvalidArgumentException('A question has a plain label of 1 to 255 characters.');
        }

        $options = null;
        if (in_array($field->type, self::OPTION_FIELD_TYPES, true)) {
            $lines = [];
            foreach ($field->options as $option) {
                $option = trim($option);
                if ($option === '' || preg_match('/[\r\n]/', $option) === 1) {
                    throw new \InvalidArgumentException('An option is one non-empty line.');
                }
                $lines[] = $option;
            }
            if ($lines === []) {
                throw new \InvalidArgumentException('A question to choose from has options.');
            }
            $options = implode("\n", $lines);
        }

        return ['type' => $field->type, 'label' => $label, 'required' => $field->required, 'options' => $options, 'content' => null];
    }

    private function now(): \DateTimeImmutable
    {
        return $this->clock !== null ? ($this->clock)() : new \DateTimeImmutable();
    }
}
