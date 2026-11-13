<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\News\Service;

use Core\File\FilePublicIds;
use Core\File\FileRepository;
use Modules\News\File\NewsCoverOwnershipChecker;
use Modules\News\Repository\Article;
use Modules\News\Repository\ArticleRepository;

/**
 * Who may read the images inserted in an article's text (issue #1110).
 *
 * The editor uploads an image before the article it belongs to is saved,
 * so the upload cannot know the article's visibility and stores it at the
 * provisional floor of whoever may write one (`chief`,
 * Controller\NewsController::uploadBodyImage()). It is here, every time
 * the article's text is saved, that each image the text names is put on
 * the floor of the text it sits in: `public` for a public or unlisted
 * article, `identified`, `chief` or `admin` otherwise. **No social
 * exception**: that one is the cover's alone (ArticleService::
 * coverImageRoleMin()) — a crawler is shown the title, the summary and
 * the cover, never the body (SECURITY.md §3).
 *
 * Each image also belongs to its article, under the cover's own rule
 * (File\NewsCoverOwnershipChecker): refused while the article is not
 * online, except to whoever manages the news. A draft shows nothing of
 * itself before it goes online.
 *
 * **One file, several articles.** Pasting an article's HTML into another
 * puts the same file in both. It follows the article that opens it widest,
 * so no reader of either is shown a broken image: the most permissive
 * floor among the ONLINE articles that name it, or, when none is online
 * yet, among all of them — and that article becomes its owner, so the
 * ownership check and the floor always speak for the same article. A
 * photograph is only that open because an editor put it in an article
 * that open.
 *
 * Only files this module stored for article text are touched
 * (`news/images/`, a directory nothing else writes into), and never an article's cover, which
 * keeps its own rule even when its address is pasted into a text.
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

    /** Built from a connection alone, for the catch-up task. */
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
     * Realign every image article $articleId's text names, and every image
     * it owns that its text no longer names; returns how many files
     * changed floor or owner.
     *
     * **Backwards too.** An image taken out of a text, while that article
     * still owns it, would otherwise keep the floor it had there: shared
     * with a staff article and removed from the public one, it would stay
     * public forever. Each image this article owns is therefore reconciled
     * as well — handed to whichever article now governs it, or closed when
     * no saved text names it any more.
     */
    public function syncArticle(int $articleId): int
    {
        $publicIds = array_unique([
            ...$this->publicIdsIn($this->textOf($articleId)),
            ...$this->publicIdsOwnedBy($articleId),
        ]);

        $changed = 0;
        foreach ($publicIds as $publicId) {
            $changed += $this->syncFile($publicId) ? 1 : 0;
        }

        return $changed;
    }

    /**
     * Close the text images no saved article claims: uploaded `public`
     * before issue #1110, then never saved into an article (an abandoned
     * draft, an image removed before saving). They go to the provisional
     * floor of an unsaved upload, and nothing is deleted. Returns how
     * many. For the catch-up task, after every article was synced.
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
     * Put one file on the floor and owner of the article that opens it
     * widest. Whether anything changed.
     */
    private function syncFile(string $publicId): bool
    {
        $file = $this->files->findByPublicId($publicId);
        if ($file === null
            || !str_starts_with($file->relativePath, self::IMAGE_DIRECTORY)
            || $this->isACover($file->id)
        ) {
            return false;
        }

        $governing = $this->governingArticle($publicId);
        if ($governing === null) {
            return $this->release($file);
        }

        $roleMin = self::bodyImageRoleMin($governing->visibility);
        $owner = NewsCoverOwnershipChecker::BODY_IMAGE_OWNER_TYPE;
        if ($file->roleMin === $roleMin && $file->ownerType === $owner && $file->ownerId === $governing->id) {
            return false;
        }

        $this->files->updateRoleMin($file->id, $roleMin);
        $this->files->updateOwner($file->id, $owner, $governing->id);

        return true;
    }

    /**
     * A text image no saved text names any more: back to the provisional
     * floor of an unsaved upload, with no owner — the author who removed
     * it still sees it, nobody else. Nothing is deleted.
     */
    private function release(\Core\File\FileRecord $file): bool
    {
        if ($file->roleMin === self::UNSAVED_ROLE_MIN && $file->ownerType === null) {
            return false;
        }

        $this->pdo->prepare('UPDATE files SET role_min = ?, owner_type = NULL, owner_id = NULL WHERE id = ?')
            ->execute([self::UNSAVED_ROLE_MIN, $file->id]);

        return true;
    }

    /**
     * The images this article owns today, whether or not its text still
     * names them.
     *
     * @return list<string>
     */
    private function publicIdsOwnedBy(int $articleId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT public_id FROM files WHERE owner_type = ? AND owner_id = ? AND public_id IS NOT NULL'
        );
        $stmt->execute([NewsCoverOwnershipChecker::BODY_IMAGE_OWNER_TYPE, $articleId]);

        return array_values(array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN)));
    }

    /**
     * Among the articles whose text names $publicId, the one whose floor
     * is the widest — online ones first, so a draft that shares the image
     * never closes it on a published article's readers.
     */
    private function governingArticle(string $publicId): ?Article
    {
        $candidates = [];
        foreach ($this->articleIdsNaming($publicId) as $articleId) {
            $article = $this->articles->findById($articleId);
            if ($article !== null) {
                $candidates[] = $article;
            }
        }
        if ($candidates === []) {
            return null;
        }

        $live = array_values(array_filter($candidates, static fn(Article $a): bool => ArticleService::isOnline($a)));
        $pool = $live !== [] ? $live : $candidates;
        usort(
            $pool,
            static fn(Article $a, Article $b): int => [self::openness($a->visibility), $a->id]
                <=> [self::openness($b->visibility), $b->id]
        );

        return $pool[0];
    }

    /** Lower is wider. */
    private static function openness(string $visibility): int
    {
        return match (self::bodyImageRoleMin($visibility)) {
            'public' => 0,
            'identified' => 1,
            'chief' => 2,
            default => 3,
        };
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
