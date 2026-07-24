<?php

declare(strict_types=1);

namespace Modules\News\Service;

use Core\Security\Role;
use Core\Url\ShortUrlService;
use Core\View\EditableContentService;
use Modules\Finance\Api\ExpectedReceivableInterface;
use Modules\News\Repository\Article;
use Modules\News\Repository\ArticleRepository;
use Modules\News\Repository\FormRepository;

class ArticleService
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

    public function create(
        string $title,
        string $bodyHtml,
        string $visibility,
        bool $isIndexed,
        ?string $seoKeywords,
        ?string $seoStopDate,
        int $createdBy
    ): Article {
        $this->assertValidVisibility($visibility);
        [$isIndexed, $seoKeywords, $seoStopDate] = $this->enforceSeoRules($visibility, $isIndexed, $seoKeywords, $seoStopDate);

        $id = $this->articleRepository->create($title, $visibility, $isIndexed, $seoKeywords, $seoStopDate, $createdBy);
        $this->editableContentService->set(self::bodyContentKey($id), $bodyHtml, 'rich_text', $createdBy);

        $code = $this->shortUrlService->createShortUrl('/news/' . $id, $createdBy);
        $this->articleRepository->setShortUrlCode($id, $code);

        return $this->articleRepository->findById($id);
    }

    public function update(
        int $id,
        string $title,
        string $bodyHtml,
        string $visibility,
        bool $isIndexed,
        ?string $seoKeywords,
        ?string $seoStopDate,
        int $modifiedBy
    ): Article {
        $this->assertValidVisibility($visibility);
        [$isIndexed, $seoKeywords, $seoStopDate] = $this->enforceSeoRules($visibility, $isIndexed, $seoKeywords, $seoStopDate);

        $this->articleRepository->update($id, $title, $visibility, $isIndexed, $seoKeywords, $seoStopDate);
        $this->editableContentService->set(self::bodyContentKey($id), $bodyHtml, 'rich_text', $modifiedBy);

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
