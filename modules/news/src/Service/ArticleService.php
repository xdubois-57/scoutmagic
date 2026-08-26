<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\News\Service;

use Core\Module\HomeNewsProvider;
use Core\Security\Role;
use Core\Url\ShortUrlService;
use Core\View\EditableContentService;
use Modules\Finance\Api\ExpectedReceivableInterface;
use Modules\News\Repository\Article;
use Modules\News\Repository\ArticleRepository;
use Modules\News\Repository\FormRepository;

class ArticleService implements HomeNewsProvider
{
    public function __construct(
        private ArticleRepository $articleRepository,
        private FormRepository $formRepository,
        private EditableContentService $editableContentService,
        private ShortUrlService $shortUrlService,
        private ?ExpectedReceivableInterface $expectedReceivable = null
    ) {
    }

    public static function bodyContentKey(int $articleId): string
    {
        return 'news_body_' . $articleId;
    }

    public function findById(int $id): ?Article
    {
        return $this->articleRepository->findById($id);
    }

    public function getBodyHtml(int $articleId): string
    {
        return $this->editableContentService->get(self::bodyContentKey($articleId), '') ?? '';
    }

    /**
     * The visibilities a reader at $role may be shown in a LIST — the
     * public list and the homepage column alike, which is why both go
     * through this one method rather than each spelling out their own
     * set and drifting apart.
     *
     * `public` for an anonymous visitor, `public` + `identified` once
     * signed in. Never `chief`/`admin` (they have the manager view) and
     * never `direct_link`, which means "listed nowhere" no matter who is
     * asking — it is not a rung of the ladder.
     *
     * @return string[]
     */
    public function listableVisibilities(Role $role): array
    {
        $visibilities = [Article::VISIBILITY_PUBLIC];
        if ($role->hasAccess(Role::IDENTIFIED)) {
            $visibilities[] = Article::VISIBILITY_IDENTIFIED;
        }

        return $visibilities;
    }

    /**
     * The /news list, as $role may see it.
     *
     * @return Article[]
     */
    public function findPublicList(Role $role): array
    {
        return $this->articleRepository->findByVisibilities($this->listableVisibilities($role));
    }

    /**
     * One page of the list plus its total — /news paginates in SQL so a
     * decade of articles costs the same as its first month.
     *
     * @return array{articles: Article[], total: int}
     */
    public function findPublicListPage(Role $role, int $limit, int $offset): array
    {
        $visibilities = $this->listableVisibilities($role);

        return [
            'articles' => $this->articleRepository->findByVisibilitiesPage($visibilities, $limit, $offset),
            'total' => $this->articleRepository->countByVisibilities($visibilities),
        ];
    }

    /**
     * Core\Module\HomeNewsProvider — homepage news column, same
     * role-awareness as the list above: an `identified` article the
     * reader may open has to be reachable from somewhere.
     *
     * @return array<int, array{id: int, title: string, summary: ?string, image_url: ?string, created_at: string}>
     */
    public function getLatestVisibleArticles(int $limit, string $role): array
    {
        $visibilities = $this->listableVisibilities(Role::fromString($role));

        return array_map(fn(Article $article) => [
            'id' => $article->id,
            'title' => $article->title,
            'summary' => $article->summary,
            // The 192px thumb, not the original: the homepage card renders
            // this in a 56px box, and originals can weigh several MB.
            'image_url' => $article->imageFileId !== null ? '/files/' . $article->imageFileId . '/thumb' : null,
            'created_at' => $article->createdAt,
        ], $this->articleRepository->findLatestByVisibilities($visibilities, $limit));
    }

    /**
     * Chief/admin management list: every article the given role can see
     * (public + identified + chief, plus admin if the role is admin+),
     * plus any direct_link article the current account itself authored.
     *
     * @return Article[]
     */
    public function findManagerList(Role $role, int $currentAccountId): array
    {
        $visibilities = [Article::VISIBILITY_PUBLIC, Article::VISIBILITY_IDENTIFIED, Article::VISIBILITY_CHIEF];
        if ($role->hasAccess(Role::ADMIN)) {
            $visibilities[] = Article::VISIBILITY_ADMIN;
        }

        return $this->articleRepository->findForManager($visibilities, $currentAccountId);
    }

    /**
     * Whether $role may view the article at /news/{id} — visibility gate,
     * independent of the form's own access gate (module spec §10: the
     * effective form access is the INTERSECTION of both).
     */
    public function canView(Article $article, Role $role): bool
    {
        return match ($article->visibility) {
            // direct_link is "unlisted", never a rung of the ladder:
            // holding the address IS the permission.
            Article::VISIBILITY_PUBLIC, Article::VISIBILITY_DIRECT_LINK => true,
            Article::VISIBILITY_IDENTIFIED => $role->hasAccess(Role::IDENTIFIED),
            Article::VISIBILITY_CHIEF => $role->hasAccess(Role::CHIEF),
            Article::VISIBILITY_ADMIN => $role->hasAccess(Role::ADMIN),
            default => false,
        };
    }

    /**
     * Whether this article's title, summary and cover image may be
     * exposed as og:/twitter: metadata.
     *
     * The body of a restricted article is protected by canView() above,
     * but a preview is not a body: a link pasted into a public group
     * would otherwise render title, summary and picture for anyone. So
     * the metadata is emitted only for an article a caller with no
     * session may read anyway — which is exactly PUBLICLY_READABLE_
     * VISIBILITIES. Decided server-side, before rendering, never by
     * hiding markup the response already carries.
     */
    public function isSociallyShareable(Article $article): bool
    {
        return in_array($article->visibility, Article::PUBLICLY_READABLE_VISIBILITIES, true);
    }

    public function canEdit(Article $article, Role $role, int $currentAccountId): bool
    {
        return $role->hasAccess(Role::ADMIN) || $article->createdBy === $currentAccountId;
    }

    /**
     * $imageFileId is mandatory on create (module addendum) — validated
     * here rather than only relying on the HTML5 `required` file input,
     * since a server-side check is the only one that actually matters.
     */
    public function create(
        string $title,
        string $visibility,
        bool $isIndexed,
        ?string $seoKeywords,
        ?string $seoStopDate,
        int $createdBy,
        string $summary,
        ?int $imageFileId
    ): Article {
        $this->assertValidVisibility($visibility);
        $this->assertValidSummary($summary);
        if ($imageFileId === null) {
            throw new NewsException('Une image est obligatoire pour l\'article.');
        }
        [$isIndexed, $seoKeywords, $seoStopDate] = $this->enforceSeoRules($visibility, $isIndexed, $seoKeywords, $seoStopDate);

        $id = $this->articleRepository->create($title, $visibility, $isIndexed, $seoKeywords, $seoStopDate, $createdBy, $summary, $imageFileId);

        $code = $this->shortUrlService->createShortUrl('/news/' . $id, $createdBy);
        $this->articleRepository->setShortUrlCode($id, $code);

        return $this->articleRepository->findById($id);
    }

    /**
     * $imageFileId = null means "keep the existing image" (see
     * Repository\ArticleRepository::update()) — the editor only uploads a
     * new file when the author actually replaces the picture.
     */
    public function update(
        int $id,
        string $title,
        string $visibility,
        bool $isIndexed,
        ?string $seoKeywords,
        ?string $seoStopDate,
        string $summary,
        ?int $imageFileId
    ): Article {
        $this->assertValidVisibility($visibility);
        $this->assertValidSummary($summary);
        $existing = $this->articleRepository->findById($id);
        if ($imageFileId === null && ($existing === null || $existing->imageFileId === null)) {
            throw new NewsException('Une image est obligatoire pour l\'article.');
        }
        [$isIndexed, $seoKeywords, $seoStopDate] = $this->enforceSeoRules($visibility, $isIndexed, $seoKeywords, $seoStopDate);

        $this->articleRepository->update($id, $title, $visibility, $isIndexed, $seoKeywords, $seoStopDate, $summary, $imageFileId);

        return $this->articleRepository->findById($id);
    }

    /**
     * Deletes the article and (via ON DELETE CASCADE in schema.sql) its
     * form, fields, responses and response values. Finance receivables
     * have no FK back to news (optional-dependency table, see schema.sql
     * doc comment) so they're explicitly cleaned up first when Finance is
     * available (module spec §11.5: "Cette action est irréversible" also
     * covers the money side).
     */
    public function delete(int $id): void
    {
        $form = $this->formRepository->findByArticleId($id);
        if ($form !== null && $this->expectedReceivable !== null) {
            $this->expectedReceivable->deleteReceivablesForSource('news', $form->id);
        }

        $this->editableContentService->delete(self::bodyContentKey($id));
        $this->articleRepository->delete($id);
    }

    public function markHasForm(int $articleId, bool $hasForm): void
    {
        $this->articleRepository->setHasForm($articleId, $hasForm);
    }

    private function assertValidVisibility(string $visibility): void
    {
        if (!in_array($visibility, Article::VISIBILITIES, true)) {
            throw new NewsException('Visibilité invalide.');
        }
    }

    private function assertValidSummary(string $summary): void
    {
        if (trim($summary) === '') {
            throw new NewsException('Un résumé en une phrase est obligatoire.');
        }
    }

    /**
     * direct_link AND identified visibility both force is_indexed =
     * false, enforced here (service layer) not just hidden in the UI
     * (module spec §16).
     *
     * They fail the same test for two different reasons: a direct_link
     * article is deliberately in no list, and an `identified` one hands
     * a crawler — which never signs in — a title, a summary and a cover
     * image whose whole point was to stay inside the unit.
     *
     * @return array{0: bool, 1: ?string, 2: ?string}
     */
    private function enforceSeoRules(string $visibility, bool $isIndexed, ?string $seoKeywords, ?string $seoStopDate): array
    {
        if (in_array($visibility, [Article::VISIBILITY_DIRECT_LINK, Article::VISIBILITY_IDENTIFIED], true)) {
            return [false, null, null];
        }

        return [$isIndexed, $seoKeywords, $seoStopDate];
    }
}
