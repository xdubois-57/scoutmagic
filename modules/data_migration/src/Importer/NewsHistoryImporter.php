<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\DataMigration\Importer;

use Core\Archive\ArchiveLimits;
use Core\Archive\ArchiveRejectedException;
use Modules\DataMigration\Repository\ItemMappingRepository;
use Modules\News\Api\ImportedArticle;
use Modules\News\Api\ImportedFormField;
use Modules\News\Api\ImportedImage;
use Modules\News\Api\NewsImportWriterInterface;

/**
 * « Actualités et formulaires » — the old site's articles and the
 * definition of their forms, read from a `scoutmagic-news-history` 1.x
 * archive (docs/formats/data-migration/news-history.md) and written through
 * the news module's {@see NewsImportWriterInterface}: online at their
 * historical date, without any announcement, locked read-only (D11), their
 * forms closed for good and without a single response.
 *
 * Two kinds of item, in this order, so an article is always written before
 * the form that hangs on it:
 *
 * - `article`: `articles/<id>.json`;
 * - `form`: `forms/<id>.json`, which names its article by `article_id`. A
 *   form whose article is neither already imported nor importable from the
 *   same archive is ignored (« Formulaire sans actualité reconnue »).
 *
 * Every other JSON, JSON Lines or CSV file — a responses file above all —
 * is listed as ignored and never opened: no response is ever imported.
 *
 * What it decides itself, never the writer: an unknown visibility becomes
 * « Membres connectés », never public; a missing summary or cover stays
 * missing (D4); an image that is not one, or too heavy, is left out and its
 * article imported without it.
 */
final class NewsHistoryImporter implements Importer
{
    public const KEY = 'actualites';
    public const FORMAT = 'scoutmagic-news-history';
    public const MAJOR_VERSION = 1;

    public const KIND_ARTICLE = 'article';
    public const KIND_FORM = 'form';
    /** A file of the archive the format does not read (responses, anything else). */
    public const KIND_FILE = 'file';

    public const TARGET_ARTICLE = 'news_article';
    public const TARGET_FORM = 'news_form';

    /** A JSON member is read whole: an article's body is its largest part. */
    private const MAX_JSON_BYTES = 8 * 1024 * 1024;

    /** How much of an image's first bytes its type is read from. */
    private const SNIFF_BYTES = 65536;

    private const ARTICLE_PATH = '~^articles/([^/]+)\.json$~';
    private const FORM_PATH = '~^forms/([^/]+)\.json$~';
    private const MEDIA_PATH = '~^media/([0-9a-f]{64})(?:\.(?:jpg|jpeg|png|webp|gif))?$~';
    private const IMG_SRC = '~<img\b[^>]*?\ssrc\s*=\s*(["\'])(.*?)\1~is';

    public const REASON_NO_TITLE = 'Sans titre';
    public const REASON_TITLE_TOO_LONG = 'Titre de plus de 255 caractères';
    public const REASON_NO_DATE = 'Date de publication absente ou illisible';
    public const REASON_FUTURE_DATE = 'Date de publication à venir';
    public const REASON_NOT_PUBLISHED = 'Pas publiée sur l\'ancien site';
    public const REASON_SUMMARY_TOO_LONG = 'Résumé de plus de 300 caractères';
    public const REASON_INVALID_FIELD = 'Champ de type inattendu';
    public const REASON_FILE_NAME = 'Nom de fichier différent de l\'identifiant';
    public const REASON_UNREADABLE = 'Fichier illisible';
    public const REASON_NO_PARENT = 'Formulaire sans actualité reconnue';
    public const REASON_PARENT_HAS_FORM = 'L\'actualité a déjà un formulaire';
    public const REASON_NO_QUESTION = 'Formulaire sans question';
    public const REASON_UNKNOWN_TYPE = 'Question d\'un type non reconnu';
    public const REASON_BAD_LABEL = 'Question sans libellé ou au libellé invalide';
    public const REASON_BAD_OPTIONS = 'Question à choix sans options valides';
    public const REASON_RESPONSES = 'Réponses de formulaire : jamais importées';
    public const REASON_OTHER_FILE = 'Fichier non prévu par ce format';

    public const TAG_FORM = 'form';
    public const TAG_VISIBILITY_UNKNOWN = 'visibility_unknown';
    public const TAG_NO_IMAGE = 'no_image';
    public const TAG_NO_SUMMARY = 'no_summary';
    public const TAG_IMAGE_LEFT_OUT = 'image_left_out';
    public const TAG_RESPONSES_LEFT_OUT = 'responses_left_out';

    /**
     * Per opened source: for each article id, the path of the first form of
     * the archive that would be imported for it — the one that wins, a
     * second being ignored as the article's form is already there.
     *
     * @var \WeakMap<ImportSource, array<string, string>>
     */
    private \WeakMap $firstForms;

    public function __construct(
        private ?NewsImportWriterInterface $writer,
        private ItemMappingRepository $mappings
    ) {
        $this->firstForms = new \WeakMap();
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function requiredModule(): string
    {
        return 'news';
    }

    public function title(): string
    {
        return 'Actualités et formulaires';
    }

    public function description(): string
    {
        return 'Les anciennes actualités et la définition de leurs formulaires, sans les réponses. '
            . 'Tout reste en lecture seule, formulaires fermés.';
    }

    public function unitLabel(): string
    {
        return 'actualités';
    }

    public function archiveFormat(): ArchiveFormat
    {
        return new ArchiveFormat(
            self::FORMAT,
            self::MAJOR_VERSION,
            new ArchiveLimits(
                50000,
                16 * 1024 * 1024,
                3 * 1024 * 1024 * 1024,
                // `''`: the format's own `media/<sha256>`; csv and jsonl: a
                // responses export tolerated, and never read.
                ['json', 'jsonl', 'csv', '', 'jpg', 'jpeg', 'png', 'webp', 'gif']
            )
        );
    }

    public function items(ImportSource $source, int $from): iterable
    {
        $articles = [];
        $forms = [];
        $others = [];
        foreach ($source->archive()->names() as $name) {
            if (preg_match(self::ARTICLE_PATH, $name) === 1) {
                $articles[] = $name;
            } elseif (preg_match(self::FORM_PATH, $name) === 1) {
                $forms[] = $name;
            } elseif ($name !== 'manifest.json' && preg_match('~\.(json|jsonl|csv)$~i', $name) === 1) {
                $others[] = $name;
            }
        }
        // A stable order whatever the archive's own: byte order of the paths.
        sort($articles, SORT_STRING);
        sort($forms, SORT_STRING);
        sort($others, SORT_STRING);
        $paths = [
            ...array_map(static fn (string $p): array => [self::KIND_ARTICLE, $p], $articles),
            ...array_map(static fn (string $p): array => [self::KIND_FORM, $p], $forms),
            ...array_map(static fn (string $p): array => [self::KIND_FILE, $p], $others),
        ];

        for ($position = max(0, $from); $position < count($paths); $position++) {
            [$kind, $path] = $paths[$position];
            yield $position => $kind === self::KIND_FILE
                ? SourceItem::unreadable(self::KIND_FILE, $path, self::isResponsesFile($path)
                    ? self::REASON_RESPONSES
                    : self::REASON_OTHER_FILE)
                : $this->readItem($source, $kind, $path);
        }
    }

    public function inspect(SourceItem $item, ImportContext $context): ItemVerdict
    {
        return $item->kind === self::KIND_FORM
            ? $this->inspectForm($item, $context)
            : $this->inspectArticle($item, $context->source);
    }

    public function write(SourceItem $item, ImportContext $context): WrittenTarget
    {
        $writer = $this->writer ?? throw new \LogicException('The news module is not enabled.');
        $userId = $context->userAccountId ?? throw new \LogicException('An import is run by an account.');

        if ($item->kind === self::KIND_FORM) {
            $articleId = $this->parentArticleId($item, $context)
                ?? throw new \RuntimeException('The parent article of this form has not been imported.');

            return new WrittenTarget(
                self::TARGET_FORM,
                (string) $writer->importForm($articleId, $this->formFields($item->data), $userId)
            );
        }

        $temporary = [];
        try {
            $data = $item->data;
            $cover = $this->usableImage($context->source, $data['image'] ?? null)
                ? $this->extract($context->source, (string) $data['image'], $temporary)
                : null;
            $bodyImages = [];
            foreach ($this->bodyImagePaths(self::bodyHtml($data)) as $src) {
                if ($this->usableImage($context->source, $src)) {
                    $bodyImages[$src] = $this->extract($context->source, $src, $temporary);
                }
            }

            $id = $writer->importArticle(new ImportedArticle(
                trim((string) $data['title']),
                self::summary($data),
                self::bodyHtml($data),
                self::publishedAt($data) ?? throw new \LogicException('Checked by inspect().'),
                self::visibility($data),
                $cover,
                $bodyImages,
                self::FORMAT . ':' . $context->installationId,
                $userId
            ));
        } finally {
            foreach ($temporary as $path) {
                @unlink($path);
            }
        }

        return new WrittenTarget(self::TARGET_ARTICLE, (string) $id);
    }

    public function previewNotes(AnalysisSummary $summary): array
    {
        $notes = [];

        $forms = $summary->tag(self::TAG_FORM);
        $responses = ($summary->ignoredReasons[self::REASON_RESPONSES] ?? 0) + $summary->tag(self::TAG_RESPONSES_LEFT_OUT);
        if ($forms > 0 || $responses > 0) {
            $notes[] = PreviewNote::info(trim(
                ($forms > 0
                    ? self::counted($forms, 'formulaire est importé et fermé.', 'formulaires sont importés et fermés.') . ' '
                    : '')
                . ($responses > 0
                    ? 'Les réponses présentes dans le fichier sont ignorées : aucune n\'est importée.'
                    : 'Le fichier ne contient aucune réponse.')
            ));
        }

        $unknown = $summary->tag(self::TAG_VISIBILITY_UNKNOWN);
        if ($unknown > 0) {
            $notes[] = PreviewNote::warning(
                self::counted($unknown, 'actualité a une visibilité inconnue. Elle sera visible', 'actualités ont une visibilité inconnue. Elles seront visibles')
                . ' des seuls « Membres connectés ».'
            );
        }

        $noImage = $summary->tag(self::TAG_NO_IMAGE);
        $noSummary = $summary->tag(self::TAG_NO_SUMMARY);
        if ($noImage > 0 || $noSummary > 0) {
            $parts = [];
            if ($noImage > 0) {
                $parts[] = self::counted($noImage, 'actualité n\'a pas', 'actualités n\'ont pas') . ' d\'image';
            }
            if ($noSummary > 0) {
                $parts[] = $noImage > 0
                    ? $noSummary . ' pas de résumé'
                    : self::counted($noSummary, 'actualité n\'a pas', 'actualités n\'ont pas') . ' de résumé';
            }
            $notes[] = PreviewNote::info(
                implode(' et ', $parts) . '. '
                . ($noImage + $noSummary > 1 ? 'Elles sont importées telles quelles.' : 'Elle est importée telle quelle.')
            );
        }

        $leftOut = $summary->tag(self::TAG_IMAGE_LEFT_OUT);
        if ($leftOut > 0) {
            $notes[] = PreviewNote::warning(
                self::counted($leftOut, 'image est illisible, absente du fichier ou trop lourde', 'images sont illisibles, absentes du fichier ou trop lourdes')
                . ' (plus de 5 Mo). ' . ($leftOut > 1 ? 'Leur actualité est importée sans elles.' : 'Son actualité est importée sans elle.')
            );
        }

        return $notes;
    }

    public function effects(): array
    {
        return [
            'Aucune annonce de publication.',
            'Les formulaires restent fermés et n\'acceptent aucune réponse.',
            'Aucune créance, aucun billet, aucun récapitulatif quotidien.',
        ];
    }

    private function readItem(ImportSource $source, string $kind, string $path): SourceItem
    {
        $data = $source->jsonObject($path, self::MAX_JSON_BYTES);
        if ($data === null) {
            return SourceItem::unreadable($kind, $path, self::REASON_UNREADABLE);
        }
        $id = SourceItem::idFrom($data['id'] ?? null);

        return new SourceItem($kind, $id ?? '', ['_path' => $path] + $data, $id ?? $path);
    }

    private function inspectArticle(SourceItem $item, ImportSource $source): ItemVerdict
    {
        $data = $item->data;
        if (($data['_path'] ?? null) !== 'articles/' . $item->sourceId . '.json') {
            return ItemVerdict::ignored(self::REASON_FILE_NAME);
        }

        $title = $data['title'] ?? null;
        if ($title !== null && !is_string($title)) {
            return ItemVerdict::ignored(self::REASON_INVALID_FIELD);
        }
        $title = trim((string) $title);
        if ($title === '') {
            return ItemVerdict::ignored(self::REASON_NO_TITLE);
        }
        if (mb_strlen($title) > NewsImportWriterInterface::MAX_TITLE_LENGTH) {
            return ItemVerdict::ignored(self::REASON_TITLE_TOO_LONG);
        }

        $status = $data['status'] ?? 'published';
        if ($status !== 'published') {
            return ItemVerdict::ignored(self::REASON_NOT_PUBLISHED);
        }

        $publishedAt = self::publishedAt($data);
        if ($publishedAt === null) {
            return ItemVerdict::ignored(self::REASON_NO_DATE);
        }
        if ($publishedAt > new \DateTimeImmutable()) {
            return ItemVerdict::ignored(self::REASON_FUTURE_DATE);
        }

        foreach (['summary', 'body_html', 'body_text', 'image'] as $field) {
            if (isset($data[$field]) && !is_string($data[$field])) {
                return ItemVerdict::ignored(self::REASON_INVALID_FIELD);
            }
        }
        $summary = self::summary($data);
        if ($summary !== null && mb_strlen($summary) > NewsImportWriterInterface::MAX_SUMMARY_LENGTH) {
            return ItemVerdict::ignored(self::REASON_SUMMARY_TOO_LONG);
        }

        $tags = [];
        if (!in_array($data['visibility'] ?? null, NewsImportWriterInterface::VISIBILITIES, true)) {
            $tags[] = self::TAG_VISIBILITY_UNKNOWN;
        }
        if ($summary === null) {
            $tags[] = self::TAG_NO_SUMMARY;
        }

        $image = $data['image'] ?? null;
        if ($image === null || $image === '') {
            $tags[] = self::TAG_NO_IMAGE;
        } elseif (!$this->usableImage($source, $image)) {
            $tags[] = self::TAG_NO_IMAGE;
            $tags[] = self::TAG_IMAGE_LEFT_OUT;
        }
        foreach ($this->bodyImagePaths(self::bodyHtml($data)) as $src) {
            if (!$this->usableImage($source, $src)) {
                $tags[] = self::TAG_IMAGE_LEFT_OUT;
            }
        }

        return ItemVerdict::import()->tagged(...$tags);
    }

    private function inspectForm(SourceItem $item, ImportContext $context): ItemVerdict
    {
        $data = $item->data;
        $problem = self::formProblem($data);
        if ($problem !== null) {
            return ItemVerdict::ignored($problem);
        }

        $parentId = SourceItem::idFrom($data['article_id'] ?? null);
        if ($parentId === null) {
            return ItemVerdict::ignored(self::REASON_NO_PARENT);
        }

        $target = $this->mappings->findTarget($context->installationId, $context->format, self::KIND_ARTICLE, $parentId);
        if ($target !== null) {
            if ($target['target_type'] !== self::TARGET_ARTICLE || $this->writer === null) {
                return ItemVerdict::ignored(self::REASON_NO_PARENT);
            }
            if (!$this->writer->acceptsImportedForm((int) $target['target_id'])) {
                return ItemVerdict::ignored(self::REASON_PARENT_HAS_FORM);
            }
        } else {
            // Not imported yet: importable from this very archive, where
            // the articles come before the forms.
            $path = 'articles/' . $parentId . '.json';
            if (preg_match(self::ARTICLE_PATH, $path) !== 1 || !$context->source->archive()->has($path)) {
                return ItemVerdict::ignored(self::REASON_NO_PARENT);
            }
            $parent = $this->readItem($context->source, self::KIND_ARTICLE, $path);
            if ($parent->isUnreadable() || $parent->sourceId !== $parentId
                || $this->inspectArticle($parent, $context->source)->outcome !== ItemVerdict::IMPORT) {
                return ItemVerdict::ignored(self::REASON_NO_PARENT);
            }
            // Two forms for one article: the first wins, as it will once
            // written — so the preview counts what the import will do.
            if (($this->firstFormsOf($context->source)[$parentId] ?? null) !== ($data['_path'] ?? null)) {
                return ItemVerdict::ignored(self::REASON_PARENT_HAS_FORM);
            }
        }

        $tags = [self::TAG_FORM];
        if (array_key_exists('responses', $data)) {
            $tags[] = self::TAG_RESPONSES_LEFT_OUT;
        }

        return ItemVerdict::import()->tagged(...$tags);
    }

    /** The id of the imported article a form hangs on, or null. */
    private function parentArticleId(SourceItem $item, ImportContext $context): ?int
    {
        $parentId = SourceItem::idFrom($item->data['article_id'] ?? null);
        $target = $parentId !== null
            ? $this->mappings->findTarget($context->installationId, $context->format, self::KIND_ARTICLE, $parentId)
            : null;

        return $target !== null && $target['target_type'] === self::TARGET_ARTICLE ? (int) $target['target_id'] : null;
    }

    /**
     * Why a form cannot be imported for what it holds itself — its parent
     * aside — or null.
     *
     * @param array<string, mixed> $data
     */
    private static function formProblem(array $data): ?string
    {
        $fields = $data['fields'] ?? null;
        if (!is_array($fields) || !array_is_list($fields) || $fields === []) {
            return self::REASON_NO_QUESTION;
        }

        $asks = false;
        foreach ($fields as $field) {
            $problem = self::fieldProblem($field);
            if ($problem !== null) {
                return $problem;
            }
            $asks = $asks || !in_array($field['type'], NewsImportWriterInterface::NON_INPUT_FIELD_TYPES, true);
        }

        return $asks ? null : self::REASON_NO_QUESTION;
    }

    /**
     * For each article id, the first form of the archive (in the import's
     * order) that would be imported for it on its own merits.
     *
     * @return array<string, string> article id => form path
     */
    private function firstFormsOf(ImportSource $source): array
    {
        if (!isset($this->firstForms[$source])) {
            $first = [];
            $paths = array_values(array_filter(
                $source->archive()->names(),
                static fn (string $name): bool => preg_match(self::FORM_PATH, $name) === 1
            ));
            sort($paths, SORT_STRING);
            foreach ($paths as $path) {
                $form = $this->readItem($source, self::KIND_FORM, $path);
                $parentId = SourceItem::idFrom($form->data['article_id'] ?? null);
                if ($form->isUnreadable() || $form->sourceId === '' || $parentId === null
                    || isset($first[$parentId]) || self::formProblem($form->data) !== null) {
                    continue;
                }
                $first[$parentId] = $path;
            }
            $this->firstForms[$source] = $first;
        }

        return $this->firstForms[$source];
    }

    /**
     * Why one question of a form cannot be imported as it was asked, or null.
     */
    private static function fieldProblem(mixed $field): ?string
    {
        if (!is_array($field) || !is_string($field['type'] ?? null)
            || !in_array($field['type'], NewsImportWriterInterface::FIELD_TYPES, true)) {
            return self::REASON_UNKNOWN_TYPE;
        }
        if (in_array($field['type'], NewsImportWriterInterface::NON_INPUT_FIELD_TYPES, true)) {
            return isset($field['content']) && !is_string($field['content']) ? self::REASON_INVALID_FIELD : null;
        }

        $label = $field['label'] ?? null;
        if (!is_string($label) || trim($label) === '' || mb_strlen(trim($label)) > NewsImportWriterInterface::MAX_LABEL_LENGTH
            || strpbrk($label, '<>') !== false) {
            return self::REASON_BAD_LABEL;
        }
        if (isset($field['required']) && !is_bool($field['required'])) {
            return self::REASON_INVALID_FIELD;
        }

        if (in_array($field['type'], NewsImportWriterInterface::OPTION_FIELD_TYPES, true)) {
            $options = $field['options'] ?? null;
            if (!is_array($options) || !array_is_list($options) || $options === []) {
                return self::REASON_BAD_OPTIONS;
            }
            foreach ($options as $option) {
                if (!is_string($option) || trim($option) === '' || preg_match('/[\r\n]/', $option) === 1) {
                    return self::REASON_BAD_OPTIONS;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     * @return list<ImportedFormField>
     */
    private function formFields(array $data): array
    {
        $fields = [];
        foreach ((array) ($data['fields'] ?? []) as $field) {
            if (!is_array($field)) {
                continue;
            }
            $type = (string) ($field['type'] ?? '');
            $fields[] = new ImportedFormField(
                $type,
                is_string($field['label'] ?? null) ? $field['label'] : null,
                ($field['required'] ?? false) === true,
                in_array($type, NewsImportWriterInterface::OPTION_FIELD_TYPES, true)
                    ? array_values(array_map('strval', (array) ($field['options'] ?? [])))
                    : [],
                is_string($field['content'] ?? null) ? $field['content'] : null
            );
        }

        return $fields;
    }

    /**
     * Whether a `media/…` path names an image this site would take: in the
     * archive, named after its own fingerprint, of an image type by its
     * bytes, and no heavier than the editor accepts.
     */
    private function usableImage(ImportSource $source, mixed $path): bool
    {
        if (!is_string($path) || preg_match(self::MEDIA_PATH, $path, $match) !== 1) {
            return false;
        }
        $archive = $source->archive();
        if (!$archive->has($path)) {
            return false;
        }
        $inventoried = $source->manifest?->files[$path]['sha256'] ?? null;
        if ($inventoried !== $match[1]) {
            return false;
        }

        $head = '';
        try {
            $archive->stream($path, static function (string $chunk) use (&$head): void {
                if (strlen($head) < self::SNIFF_BYTES) {
                    $head .= substr($chunk, 0, self::SNIFF_BYTES - strlen($head));
                }
            }, NewsImportWriterInterface::MAX_IMAGE_BYTES);
        } catch (ArchiveRejectedException) {
            // Heavier than the ceiling: this image is left out, the article
            // is not.
            return false;
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($head);

        return is_string($mime) && in_array($mime, NewsImportWriterInterface::IMAGE_MIME_TYPES, true);
    }

    /**
     * Copies an archive member to a temporary file for the writer, which
     * takes a path as an upload does.
     *
     * @param list<string> $temporary the files to delete afterwards, this one appended
     */
    private function extract(ImportSource $source, string $path, array &$temporary): ImportedImage
    {
        $file = tempnam(sys_get_temp_dir(), 'dm_news_');
        if ($file === false) {
            throw new \RuntimeException('No temporary file for an imported image.');
        }
        $temporary[] = $file;
        $handle = fopen($file, 'wb');
        if ($handle === false) {
            throw new \RuntimeException('No temporary file for an imported image.');
        }
        try {
            $source->archive()->stream($path, static function (string $chunk) use ($handle): void {
                fwrite($handle, $chunk);
            }, NewsImportWriterInterface::MAX_IMAGE_BYTES);
        } finally {
            fclose($handle);
        }

        return new ImportedImage($file, basename($path));
    }

    /**
     * The `media/…` sources the body's images point at, each once.
     *
     * @return list<string>
     */
    private function bodyImagePaths(string $html): array
    {
        if (preg_match_all(self::IMG_SRC, $html, $matches) === false) {
            return [];
        }
        $paths = [];
        foreach ($matches[2] as $src) {
            $src = html_entity_decode($src, ENT_QUOTES | ENT_HTML5);
            if (str_starts_with($src, 'media/')) {
                $paths[$src] = true;
            }
        }

        return array_keys($paths);
    }

    /**
     * The body as HTML: `body_html` as it is (the writer sanitizes it), or
     * `body_text` made into paragraphs, or nothing.
     *
     * @param array<string, mixed> $data
     */
    private static function bodyHtml(array $data): string
    {
        if (is_string($data['body_html'] ?? null) && trim($data['body_html']) !== '') {
            return $data['body_html'];
        }
        if (!is_string($data['body_text'] ?? null) || trim($data['body_text']) === '') {
            return '';
        }

        $paragraphs = preg_split('/\R\s*\R/u', trim(str_replace("\r\n", "\n", $data['body_text']))) ?: [];

        return implode('', array_map(
            static fn (string $paragraph): string => '<p>'
                . nl2br(htmlspecialchars(trim($paragraph), ENT_QUOTES | ENT_HTML5, 'UTF-8'), false) . '</p>',
            $paragraphs
        ));
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function summary(array $data): ?string
    {
        $summary = is_string($data['summary'] ?? null) ? trim($data['summary']) : '';

        return $summary !== '' ? $summary : null;
    }

    /**
     * An ISO 8601 moment WITH its offset — a date without one would be read
     * in whichever zone this server happens to run.
     *
     * @param array<string, mixed> $data
     */
    private static function publishedAt(array $data): ?\DateTimeImmutable
    {
        $value = $data['published_at'] ?? null;
        if (!is_string($value)
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?(\.\d+)?(Z|[+-]\d{2}:\d{2})$/', $value) !== 1) {
            return null;
        }
        try {
            $moment = new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }

        // Read in its own offset, the moment shows the date and time that
        // were written — unless PHP rolled an impossible one over
        // (`2023-02-30`, `25:00`), which is not the one written.
        return $moment->format('Y-m-d\TH:i') === substr($value, 0, 16) ? $moment : null;
    }

    /**
     * The visibility as written when this site has it, « Membres connectés »
     * otherwise — never `public` by default (spec §7.1).
     *
     * @param array<string, mixed> $data
     */
    private static function visibility(array $data): string
    {
        $visibility = $data['visibility'] ?? null;

        return is_string($visibility) && in_array($visibility, NewsImportWriterInterface::VISIBILITIES, true)
            ? $visibility
            : NewsImportWriterInterface::PROTECTIVE_VISIBILITY;
    }

    private static function isResponsesFile(string $path): bool
    {
        return preg_match('~(^|/)(responses?|reponses?|réponses?)(/|[._-])~iu', $path) === 1;
    }

    private static function counted(int $count, string $singular, string $plural): string
    {
        return $count . ' ' . ($count > 1 ? $plural : $singular);
    }
}
