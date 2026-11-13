<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\News\Service;

use Core\File\FilePublicIds;
use Core\File\FileRecord;
use Core\File\FileRepository;
use Modules\News\File\NewsTextImageOwnershipChecker;
use Modules\News\Repository\Article;
use Modules\News\Repository\ArticleRepository;

/**
 * Who may read the images inserted in an article's text (issue #1110).
 *
 * The editor uploads an image before the article it belongs to is saved,
 * so the upload cannot know the article's visibility and stores it at the
 * provisional floor of whoever may write one (`chief`,
 * Controller\NewsController::uploadBodyImage()). Then two layers decide,
 * and they are split on purpose:
 *
 * - **The floor, written at every save of a text** (syncArticle()): each
 *   image the text names gets the floor of the WIDEST text naming it —
 *   `public` for a public or unlisted article, `identified`, `chief` or
 *   `admin` otherwise. **No social exception**: that one is the cover's
 *   alone (ArticleService::coverImageRoleMin()); a crawler is shown the
 *   title, the summary and the cover, never the body (SECURITY.md §3).
 *   It depends only on texts and visibilities, which change only by a
 *   save — the save that calls this.
 * - **The rest, asked at every request** (File\NewsTextImageOwnershipChecker,
 *   owner `news_text_image` → the file itself): a reader gets the image
 *   only if some article naming it is online and visible to them, or if
 *   they manage the news. Whether an article is online changes with no
 *   save at all — a scheduled article simply reaches its time — so that
 *   part is never written down, exactly as for the cover (#845).
 *
 * So one file pasted into several articles opens to the readers of each
 * of them and of none other; an image taken out of every text, or only
 * left in a draft, is shown to the news managers alone, whatever floor it
 * last had. Nothing depends on the order the articles were saved in.
 *
 * Only files this module stored for article text are touched
 * (`news/images/`, a directory nothing else writes into), and never an
 * article's cover, which keeps its own rule even when its address is
 * pasted into a text.
 */
final class BodyImageAccess
{
    /** The provisional floor of an image nobody has saved in an article yet. */
    public const UNSAVED_ROLE_MIN = 'chief';

    private const IMAGE_DIRECTORY = 'news/images/';
    private const ADDRESS = '~/files/([A-Za-z0-9_-]{22})(?=[/"\'?#\s]|$)~';

    public function __construct(
        private readonly \PDO $pdo,
        private readonly ArticleRepository $articles,
        private readonly FileRepository $files
    ) {
    }

    /** Built from a connection alone, for the catch-up task and the checker. */
    public static function forPdo(\PDO $pdo): self
    {
        return new self($pdo, new ArticleRepository($pdo), new FileRepository($pdo));
    }

    /**
     * The floor of an article's TEXT — not of its cover.
     */
    public static function bodyImageRoleMin(string $visibility): string
    {
        return match ($visibility) {
            Article::VISIBILITY_PUBLIC, Article::VISIBILITY_DIRECT_LINK => 'public',
            Article::VISIBILITY_IDENTIFIED => 'identified',
            Article::VISIBILITY_CHIEF => 'chief',
            // As for the cover: a visibility that drifted closes rather
            // than opens.
            default => 'admin',
        };
    }

    /**
     * Put every image article $articleId's text names on the floor of the
     * widest text naming it, owned by itself for the request-time check;
     * returns how many files changed.
     */
    public function syncArticle(int $articleId): int
    {
        $changed = 0;
        foreach ($this->publicIdsIn($this->textOf($articleId)) as $publicId) {
            $changed += $this->syncFile($publicId) ? 1 : 0;
        }

        return $changed;
    }

    /**
     * Close the text images no saved article ever claimed: uploaded
     * `public` before issue #1110, then never saved into an article (an
     * abandoned draft, an image removed before saving). They go to the
     * provisional floor of an unsaved upload, and nothing is deleted.
     * Returns how many. For the catch-up task, after every article was
     * synced.
     */
    public function closeUnclaimed(): int
    {
        $stmt = $this->pdo->prepare(
            "UPDATE files SET role_min = ?
             WHERE relative_path LIKE 'news/images/%'
               AND role_min = 'public'
               AND owner_type IS NULL
               AND id NOT IN (SELECT image_file_id FROM news_articles WHERE image_file_id IS NOT NULL)"
        );
        $stmt->execute([self::UNSAVED_ROLE_MIN]);

        return $stmt->rowCount();
    }

    /**
     * Every article whose text names file $file — what the request-time
     * check reads.
     *
     * @return list<Article>
     */
    public function articlesNaming(FileRecord $file): array
    {
        if ($file->publicId === null) {
            return [];
        }

        $articles = [];
        foreach ($this->articleIdsNaming($file->publicId) as $articleId) {
            $article = $this->articles->findById($articleId);
            if ($article !== null) {
                $articles[] = $article;
            }
        }

        return $articles;
    }

    /** Whether anything changed. */
    private function syncFile(string $publicId): bool
    {
        $file = $this->files->findByPublicId($publicId);
        if ($file === null
            || !str_starts_with($file->relativePath, self::IMAGE_DIRECTORY)
            || $this->isACover($file->id)
        ) {
            return false;
        }

        $articles = $this->articlesNaming($file);
        if ($articles === []) {
            return false;
        }

        $roleMin = self::widestFloor($articles);
        $owner = NewsTextImageOwnershipChecker::OWNER_TYPE;
        if ($file->roleMin === $roleMin && $file->ownerType === $owner && $file->ownerId === $file->id) {
            return false;
        }

        $this->files->updateRoleMin($file->id, $roleMin);
        $this->files->updateOwner($file->id, $owner, $file->id);

        return true;
    }

    /**
     * @param non-empty-list<Article> $articles
     */
    private static function widestFloor(array $articles): string
    {
        $order = ['public' => 0, 'identified' => 1, 'chief' => 2, 'admin' => 3];
        $floors = array_map(static fn(Article $a): string => self::bodyImageRoleMin($a->visibility), $articles);
        usort($floors, static fn(string $a, string $b): int => $order[$a] <=> $order[$b]);

        return $floors[0];
    }

    /**
     * Every article whose text — a `text` field of its form, or the body
     * stored before forms carried it — contains the address.
     *
     * @return list<int>
     */
    private function articleIdsNaming(string $publicId): array
    {
        $needle = '%' . FilePublicIds::addressOf($publicId) . '%';
        $ids = [];

        $stmt = $this->pdo->prepare(
            "SELECT DISTINCT f.news_article_id
             FROM news_form_fields ff
             JOIN news_forms f ON f.id = ff.form_id
             WHERE ff.field_type = 'text' AND ff.confirmation_text LIKE ?"
        );
        $stmt->execute([$needle]);
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $id) {
            $ids[(int) $id] = (int) $id;
        }

        $stmt = $this->pdo->prepare(
            "SELECT content_key FROM editable_contents WHERE content_key LIKE 'news_body_%' AND content_value LIKE ?"
        );
        $stmt->execute([$needle]);
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $key) {
            $id = (int) substr((string) $key, strlen('news_body_'));
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    /** The article's text, every part of it that can carry an image. */
    private function textOf(int $articleId): string
    {
        $stmt = $this->pdo->prepare(
            "SELECT ff.confirmation_text
             FROM news_form_fields ff
             JOIN news_forms f ON f.id = ff.form_id
             WHERE f.news_article_id = ? AND ff.field_type = 'text'"
        );
        $stmt->execute([$articleId]);
        $parts = array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));

        $stmt = $this->pdo->prepare('SELECT content_value FROM editable_contents WHERE content_key = ?');
        $stmt->execute([ArticleService::bodyContentKey($articleId)]);
        $parts[] = (string) $stmt->fetchColumn();

        return implode("\n", $parts);
    }

    /** @return list<string> */
    private function publicIdsIn(string $html): array
    {
        preg_match_all(self::ADDRESS, $html, $matches);

        return array_values(array_unique(array_filter(
            $matches[1],
            static fn(string $id): bool => FilePublicIds::isWellFormed($id)
        )));
    }

    private function isACover(int $fileId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM news_articles WHERE image_file_id = ? LIMIT 1');
        $stmt->execute([$fileId]);

        return $stmt->fetchColumn() !== false;
    }
}
