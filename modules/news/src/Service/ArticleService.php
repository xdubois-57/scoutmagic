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
     * Public list: only `public`-visibility articles — `direct_link`
     * articles never appear in any list (module spec), `chief`/`admin`
     * ones have their own manager view.
     *
     * @return Article[]
     */
    public function findPublicList(): array
    {
        return $this->articleRepository->findByVisibilities([Article::VISIBILITY_PUBLIC]);
    }

    /**
     * Core\Module\HomeNewsProvider — homepage news column.
     *
     * @return array<int, array{id: int, title: string, summary: ?string, image_url: ?string, created_at: string}>
     */
    public function getLatestPublicArticles(int $limit): array
    {
        return array_map(fn(Article $article) => [
            'id' => $article->id,
            'title' => $article->title,
            'summary' => $article->summary,
            'image_url' => $article->imageFileId !== null ? '/files/' . $article->imageFileId : null,
            'created_at' => $article->createdAt,
        ], $this->articleRepository->findLatestPublic($limit));
    }

    /**
     * Chief/admin management list: every article the given role can see
     * (public + chief, plus admin if the role is admin+), plus any
     * direct_link article the current account itself authored.
     *
     * @return Article[]
     */
    public function findManagerList(Role $role, int $currentAccountId): array
    {
        $visibilities = [Article::VISIBILITY_PUBLIC, Article::VISIBILITY_CHIEF];
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
            Article::VISIBILITY_PUBLIC, Article::VISIBILITY_DIRECT_LINK => true,
            Article::VISIBILITY_CHIEF => $role->hasAccess(Role::CHIEF),
            Article::VISIBILITY_ADMIN => $role->hasAccess(Role::ADMIN),
            default => false,
        };
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
     * direct_link visibility forces is_indexed = false, enforced here
     * (service layer) not just hidden in the UI (module spec §16).
     *
     * @return array{0: bool, 1: ?string, 2: ?string}
     */
    private function enforceSeoRules(string $visibility, bool $isIndexed, ?string $seoKeywords, ?string $seoStopDate): array
    {
        if ($visibility === Article::VISIBILITY_DIRECT_LINK) {
            return [false, null, null];
        }

        return [$isIndexed, $seoKeywords, $seoStopDate];
    }
}
