<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\News\Controller;

use Core\Config\ScoutYearService;
use Core\Config\SettingService;
use Core\Exception\UserFacingMessage;
use Core\File\FileRepository;
use Core\File\UploadException;
use Core\File\UploadHandler;
use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Journal\JournalService;
use Core\Member\MemberService;
use Core\Member\SectionService;
use Core\Pdf\PosterPdfService;
use Core\Scheduler\SchedulerService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\HumanCheck\HumanCheckChallenge;
use Core\Security\HumanCheck\HumanCheckService;
use Core\Security\Role;
use Core\Security\UserAccountRepository;
use Core\View\SectionPickerHelper;
use Modules\Finance\Api\FinanceAccountInterface;
use Modules\News\Repository\Article;
use Modules\News\Repository\FormField;
use Modules\News\Repository\NewsForm;
use Modules\News\Service\ArticleService;
use Modules\News\Service\FormService;
use Modules\News\Service\NewsException;
use Modules\News\Service\ResponseService;
use Modules\News\Service\SeoKeywordService;
use Modules\News\Task\SendResponseDigestHandler;
use Twig\Environment;

class NewsController extends AbstractController
{
    /** @var string[] */
    private const IMAGE_ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    private const IMAGE_MAX_SIZE_BYTES = 5 * 1024 * 1024;

    /**
     * Core\Security\HumanCheck form key for a public form response — must
     * match FormController::HUMAN_CHECK_FORM_KEY (a signature made with
     * one is rejected against the other).
     */
    private const HUMAN_CHECK_FORM_KEY = 'news_form_response';

    public function __construct(
        protected Environment $twig,
        private ArticleService $articleService,
        private FormService $formService,
        private ResponseService $responseService,
        private SeoKeywordService $seoKeywordService,
        private PosterPdfService $posterPdfService,
        private ScoutYearService $scoutYearService,
        private SettingService $settingService,
        private SchedulerService $schedulerService,
        private UserAccountRepository $userAccountRepository,
        private MemberService $memberService,
        private SectionService $sectionService,
        private UploadHandler $uploadHandler,
        private FileRepository $fileRepository,
        private string $storagePath,
        private JournalService $journalService,
        private ?FinanceAccountInterface $financeAccount = null,
        private ?HumanCheckService $humanCheck = null,
        // Optional and trailing (same shape as MemberService's own optional
        // dependencies): without it, uploads are stored full-size only and
        // the templates' variant URLs simply 404 into a hidden image — the
        // composition root always wires it.
        private ?\Core\Photo\ImageVariantService $imageVariantService = null
    ) {
    }

    /**
     * GET /news — public list (module spec §11.1).
     *
     * @param array<string, string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $articles = $this->articleService->findPublicList();

        return $this->render('@news/list.html.twig', [
            'articles' => $this->buildListCards($articles),
            'manage' => false,
        ]);
    }

    /**
     * GET /news/manage — chief/admin management list (module spec §11.2).
     *
     * @param array<string, string> $params
     */
    public function manage(Request $request, array $params): Response
    {
        $role = Role::fromString(AuthSession::getRole());
        $accountId = (int) AuthSession::getUserAccountId();

        $this->ensureDigestTaskScheduled();

        $articles = $this->articleService->findManagerList($role, $accountId);

        return $this->render('@news/list.html.twig', [
            'articles' => $this->buildListCards($articles, currentAccountId: $accountId),
            'manage' => true,
        ]);
    }

    /**
     * GET /news/create
     *
     * @param array<string, string> $params
     */
    public function create(Request $request, array $params): Response
    {
        return $this->render('@news/editor.html.twig', $this->editorContext(null));
    }

    /**
     * POST /news/seo/generate-keywords — AJAX, "Générer avec l'IA" button
     * (module spec §11.5). Takes title/body directly rather than an
     * article id: the editor may hold unsaved changes, or be creating a
     * brand new article that has no id yet.
     *
     * @param array<string, string> $params
     */
    public function generateSeoKeywords(Request $request, array $params): Response
    {
        $data = json_decode($request->getRawBody(), true);
        if (!is_array($data) || !CsrfGuard::validateToken((string) ($data['_csrf_token'] ?? ''))) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        try {
            $keywords = $this->seoKeywordService->generateKeywords(
                (string) ($data['title'] ?? ''),
                (string) ($data['body_html'] ?? '')
            );
        } catch (NewsException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 422);
        }

        return $this->json(['success' => true, 'keywords' => $keywords]);
    }

    /**
     * POST /news/seo/generate-summary — AJAX, "Générer avec l'IA" button
     * next to the summary field (module usability review). Same
     * title/body-not-article-id shape as generateSeoKeywords() above, for
     * the same reason (unsaved editor state).
     *
     * @param array<string, string> $params
     */
    public function generateSummary(Request $request, array $params): Response
    {
        $data = json_decode($request->getRawBody(), true);
        if (!is_array($data) || !CsrfGuard::validateToken((string) ($data['_csrf_token'] ?? ''))) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        try {
            $summary = $this->seoKeywordService->generateSummary(
                (string) ($data['title'] ?? ''),
                (string) ($data['body_html'] ?? '')
            );
        } catch (NewsException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 422);
        }

        return $this->json(['success' => true, 'summary' => $summary]);
    }

    /**
     * POST /news/images/upload — AJAX, the inline rich-text toolbar's
     * image button (module usability review). Article visibility isn't
     * known yet at this point (mid-edit, possibly still unsaved), so the
     * uploaded image is stored role_min "public" like any other embedded
     * body image — a chief/admin-only article's body images would still
     * technically be reachable via direct /files/{id} link, same
     * trade-off already accepted by the shared editable() image pipeline
     * elsewhere in the app.
     *
     * @param array<string, string> $params
     */
    public function uploadBodyImage(Request $request, array $params): Response
    {
        $csrf = (string) $request->getBody('_csrf_token', '');
        if (($guard = $this->guardCsrfJson($request, $csrf)) !== null) {
            return $guard;
        }

        $uploadedFile = $request->getFile('image');
        if ($uploadedFile === null) {
            return $this->json(['success' => false, 'error' => 'Aucun fichier envoyé.'], 400);
        }

        try {
            $fileId = $this->uploadHandler->handle(
                $uploadedFile, 'news/images', self::IMAGE_ALLOWED_MIMES, self::IMAGE_MAX_SIZE_BYTES, 'public', 'news', AuthSession::getUserAccountId()
            );
        } catch (UploadException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 422);
        }

        return $this->json(['success' => true, 'url' => '/files/' . $fileId]);
    }

    /**
     * POST /news — create article (+ optional form), one combined request
     * (module spec §11.5: "Enregistrer... saves article + form definition
     * in one POST").
     *
     * @param array<string, string> $params
     */
    public function store(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, '/news/create')) !== null) {
            return $guard;
        }

        $accountId = (int) AuthSession::getUserAccountId();
        $visibility = (string) $request->getBody('visibility', Article::VISIBILITY_PUBLIC);

        try {
            $imageFileId = $this->resolveUploadedImageFileId($request, $visibility, $accountId);

            $article = $this->articleService->create(
                (string) $request->getBody('title', ''),
                $visibility,
                (bool) $request->getBody('is_indexed', false),
                $this->nullableString($request->getBody('seo_keywords')),
                $this->nullableString($request->getBody('seo_stop_date')),
                $accountId,
                (string) $request->getBody('summary', ''),
                $imageFileId
            );

            // The form is no longer optional (usability review: "only the
            // form, with by default a bloc de texte") — every article
            // always gets one, saved together in this same POST.
            $this->formService->save($article->id, $this->extractFormSettings($request, $visibility), $this->extractFields($request));
        } catch (NewsException $e) {
            return $this->render('@news/editor.html.twig', $this->editorErrorContext(null, $request, $e->getMessage()))->setStatusCode(422);
        }

        $this->journalService->log(
            'news', 'article_created', 'info', "Article « {$article->title} » créé",
            ['article_id' => $article->id], $accountId
        );

        return $this->redirect('/news/' . $article->id . '/edit');
    }

    /**
     * GET /news/{id}/edit — in-controller: author or admin only.
     *
     * @param array<string, string> $params
     */
    public function edit(Request $request, array $params): Response
    {
        $article = $this->articleService->findById((int) $params['id']);
        if ($article === null) {
            return new Response('Not Found', 404);
        }

        $role = Role::fromString(AuthSession::getRole());
        $accountId = (int) AuthSession::getUserAccountId();
        if (!$this->articleService->canEdit($article, $role, $accountId)) {
            return new Response('Forbidden', 403);
        }

        $context = $this->editorContext($article);
        $context['preview'] = (string) $request->getQuery('tab', '') === 'preview';

        return $this->render('@news/editor.html.twig', $context);
    }

    /**
     * POST /news/{id} — update article (+ form). In-controller: author or admin only.
     *
     * @param array<string, string> $params
     */
    public function update(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, '/news/' . (int) ($params['id'] ?? 0) . '/edit')) !== null) {
            return $guard;
        }

        $article = $this->articleService->findById((int) $params['id']);
        if ($article === null) {
            return new Response('Not Found', 404);
        }

        $role = Role::fromString(AuthSession::getRole());
        $accountId = (int) AuthSession::getUserAccountId();
        if (!$this->articleService->canEdit($article, $role, $accountId)) {
            return new Response('Forbidden', 403);
        }

        $visibility = (string) $request->getBody('visibility', Article::VISIBILITY_PUBLIC);

        try {
            $imageFileId = $this->resolveUploadedImageFileId($request, $visibility, $accountId);

            $article = $this->articleService->update(
                $article->id,
                (string) $request->getBody('title', ''),
                $visibility,
                (bool) $request->getBody('is_indexed', false),
                $this->nullableString($request->getBody('seo_keywords')),
                $this->nullableString($request->getBody('seo_stop_date')),
                (string) $request->getBody('summary', ''),
                $imageFileId
            );

            $this->formService->save($article->id, $this->extractFormSettings($request, $visibility), $this->extractFields($request));
        } catch (NewsException $e) {
            return $this->render('@news/editor.html.twig', $this->editorErrorContext($article, $request, $e->getMessage()))->setStatusCode(422);
        }

        $this->journalService->log(
            'news', 'article_updated', 'info', "Article « {$article->title} » modifié",
            ['article_id' => $article->id], $accountId
        );

        return $this->redirect('/news/' . $article->id . '/edit');
    }

    /**
     * DELETE /news/{id} — in-controller: author or admin only.
     *
     * @param array<string, string> $params
     */
    public function delete(Request $request, array $params): Response
    {
        $data = json_decode($request->getRawBody(), true);
        if (!is_array($data) || !CsrfGuard::validateToken((string) ($data['_csrf_token'] ?? ''))) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        $article = $this->articleService->findById((int) $params['id']);
        if ($article === null) {
            return $this->json(['success' => false, 'error' => 'Article introuvable.'], 404);
        }

        $role = Role::fromString(AuthSession::getRole());
        $accountId = (int) AuthSession::getUserAccountId();
        if (!$this->articleService->canEdit($article, $role, $accountId)) {
            return $this->json(['success' => false, 'error' => 'Accès refusé.'], 403);
        }

        $this->articleService->delete($article->id);

        $this->journalService->log(
            'news', 'article_deleted', 'info', "Article « {$article->title} » supprimé",
            ['article_id' => $article->id], $accountId
        );

        return $this->json(['success' => true]);
    }

    /**
     * GET /news/{id}/poster — streams the A4 poster PDF.
     *
     * @param array<string, string> $params
     */
    public function poster(Request $request, array $params): Response
    {
        $article = $this->articleService->findById((int) $params['id']);
        if ($article === null || $article->shortUrlCode === null) {
            return new Response('Not Found', 404);
        }

        // Same visibility gate as show(): the poster carries the article's
        // title, its summary and a QR code to its short URL, so without this
        // a chief blocked from an admin-visibility article at /news/{id}
        // could still read all three straight out of the PDF.
        if (!$this->articleService->canView($article, Role::fromString(AuthSession::getRole()))) {
            return new Response('Forbidden', 403);
        }

        $baseUrl = rtrim((string) ($this->settingService->get('base_url') ?: ''), '/');
        $qrUrl = $baseUrl . '/s/' . $article->shortUrlCode;
        $shortName = (string) ($this->settingService->get('short_name') ?: '');

        // The poster body uses the mandatory one-sentence summary (module
        // usability review), not the full article body — consistent with
        // the list card and social-share description, and short enough
        // that Core\Pdf\PosterPdfService's own truncation never kicks in.
        $pdf = $this->posterPdfService->generate(
            $article->title, (string) $article->summary, $qrUrl, $shortName, $this->buildImageDataUri($article->imageFileId)
        );
        $slug = preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($article->title)) ?? 'article';

        return (new Response($pdf))
            ->setHeader('Content-Type', 'application/pdf')
            ->setHeader('Content-Disposition', 'attachment; filename="affiche-' . trim($slug, '-') . '.pdf"')
            ->setHeader('Content-Length', (string) strlen($pdf));
    }

    /**
     * GET /news/{id} — public detail page + inline form (module spec
     * §11.3/§11.4). Visibility is an in-controller check (route role_min
     * is public).
     *
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        $article = $this->articleService->findById((int) $params['id']);
        if ($article === null) {
            return new Response('Not Found', 404);
        }

        $role = Role::fromString(AuthSession::getRole());
        if (!$this->articleService->canView($article, $role)) {
            return new Response('Forbidden', 403);
        }

        $form = $this->formService->findByArticleId($article->id);
        $fields = $this->withDefaultTextField($form !== null ? $this->formService->getFields($form->id) : [], $article);
        $hasRealInput = $this->hasRealInputField($fields);
        $scoutYearId = $this->scoutYearService->getCurrentYear()['id'];
        $accountId = AuthSession::getUserAccountId();
        $email = AuthSession::getEmail();

        $alreadyResponded = false;
        $memberOptions = [];
        if ($form !== null) {
            $memberOptions = $form->access === NewsForm::ACCESS_IDENTIFIED ? $this->responseService->resolveMemberOptions($email, $scoutYearId) : [];
            if ($form->responseLimit === NewsForm::RESPONSE_LIMIT_ONE_PER_MEMBER) {
                $alreadyResponded = $this->responseService->hasAlreadyRespondedForAllMembers($form, array_keys($memberOptions));
            } else {
                $alreadyResponded = $this->responseService->hasAlreadyResponded($form, $accountId, null);
            }
        }

        $author = $this->userAccountRepository->findById($article->createdBy);
        $baseUrl = rtrim((string) ($this->settingService->get('base_url') ?: ''), '/');

        return $this->render('@news/detail.html.twig', [
            'article' => $article,
            'breadcrumb_current' => $article->title,
            'author_name' => $author?->firstName ?? $author?->email,
            'form' => $form,
            'fields' => $this->fieldsForTemplate($fields, $memberOptions),
            'has_real_input' => $hasRealInput,
            'form_open' => $form?->isOpen() ?? false,
            'already_responded' => $alreadyResponded,
            'requires_login' => $form !== null && $form->access === NewsForm::ACCESS_IDENTIFIED && $accountId === null,
            'requires_member_selector' => $form !== null && $form->responseLimit === NewsForm::RESPONSE_LIMIT_ONE_PER_MEMBER,
            'payment_available' => $form !== null && $this->responseService->isPaymentAvailable() && $form->financeAccountId !== null && $this->hasPricedField($fields),
            'contact_email_default' => $email ?? '',
            'member_options' => $memberOptions,
            'csrf_token' => CsrfGuard::generateToken(),
            // Social sharing (Facebook/Instagram/etc. — module usability
            // review). Absolute URLs are mandatory: the og:image/og:url
            // meta tags are read by an external crawler, not the browser,
            // so a relative /files/{id} path would never resolve for it.
            // The share target is always the short URL (works whether the
            // visitor shared the short or the full link — a crawler
            // re-fetching og:url gets redirected the same way either way).
            'og_url' => $article->shortUrlCode !== null ? $baseUrl . '/s/' . $article->shortUrlCode : $baseUrl . '/news/' . $article->id,
            'og_image_url' => $article->imageFileId !== null ? $baseUrl . '/files/' . $article->imageFileId : null,
            'human_check' => $this->humanCheckChallenge(),
        ]);
    }

    /**
     * Core\Security\HumanCheck: no challenge needed for an identified
     * session (HumanCheckService::verify() short-circuits it regardless —
     * see the service's own docblock), so nothing is generated in that
     * case.
     */
    private function humanCheckChallenge(): ?HumanCheckChallenge
    {
        if ($this->humanCheck === null || AuthSession::isAuthenticated()) {
            return null;
        }

        return $this->humanCheck->generateChallenge(self::HUMAN_CHECK_FORM_KEY);
    }

    /**
     * @param Article[] $articles
     * @return array<int, array<string, mixed>>
     */
    private function buildListCards(array $articles, ?int $currentAccountId = null): array
    {
        return array_map(function (Article $article) use ($currentAccountId) {
            $form = $this->formService->findByArticleId($article->id);
            $author = $currentAccountId !== null ? $this->userAccountRepository->findById($article->createdBy) : null;

            return [
                'article' => $article,
                // Single-line summary (module usability review) — the
                // mandatory one-sentence Article::$summary, never the old
                // stripped-body excerpt, so the list never shows leftover
                // multi-paragraph formatting.
                'excerpt' => $article->summary,
                'form_badge' => $form === null ? null : ($form->isOpen() ? 'open' : 'closed'),
                'can_edit' => $currentAccountId !== null,
                'author_name' => $author?->firstName ?? $author?->email,
            ];
        }, $articles);
    }

    /**
     * @return array<string, mixed>
     */
    private function editorContext(?Article $article): array
    {
        $form = $article !== null ? $this->formService->findByArticleId($article->id) : null;
        $fields = $this->withDefaultTextField($form !== null ? $this->formService->getFields($form->id) : [], $article);

        $financeAccounts = $this->financeAccount?->getConfiguredAccounts() ?? [];

        $scoutYearId = $this->scoutYearService->getCurrentYear()['id'];
        $memberOptions = $form !== null && $form->access === NewsForm::ACCESS_IDENTIFIED
            ? $this->responseService->resolveMemberOptions(AuthSession::getEmail(), $scoutYearId)
            : [];

        return [
            'article' => $article,
            // Only meaningful for edit/update (a real article, whose title
            // is worth showing in the trail) — create/store pass $article
            // as null, so this falls back to the route's static
            // breadcrumb.label ("Nouvel article") via Twig's default()
            // filter, same as MemberController's pattern for a dynamic title.
            'breadcrumb_current' => $article?->title,
            'title_value' => $article?->title ?? '',
            'summary_value' => $article?->summary ?? '',
            'visibility_value' => $article?->visibility ?? Article::VISIBILITY_PUBLIC,
            'is_indexed_value' => $article?->isIndexed ?? false,
            'seo_keywords_value' => $article?->seoKeywords ?? '',
            // Same "usability review" default as opens_at/closes_at below:
            // 6 months out rather than blank (forever-indexed, easy to forget).
            'seo_stop_date_value' => $article?->seoStopDate ?? (new \DateTimeImmutable('+6 months'))->format('Y-m-d'),
            'form' => $form,
            'fields' => $fields,
            'preview_fields' => $this->fieldsForPreview($fields, $memberOptions),
            'preview_contact_email' => AuthSession::getEmail() ?? '',
            'field_types' => FormField::TYPES,
            'seo_ai_available' => $this->seoKeywordService->isAvailable(),
            'finance_available' => $this->financeAccount !== null,
            'finance_accounts' => $financeAccounts,
            // Only used when the form doesn't already have its own saved
            // finance_account_id (new form) — the section of the current
            // user's highest-role linked member, same resolution as the
            // SectionPicker component (ARCHITECTURE.md §8.8).
            'default_finance_account_id' => $form === null ? $this->resolveDefaultFinanceAccountId($scoutYearId) : null,
            // Usability review defaults for a brand-new form: open
            // immediately (today) and close 6 months later, rather than
            // leaving both blank (forever-open, easy to forget about).
            'default_opens_at' => $form?->opensAt ?? (new \DateTimeImmutable())->format('Y-m-d'),
            'default_closes_at' => $form?->closesAt ?? (new \DateTimeImmutable('+6 months'))->format('Y-m-d'),
            'short_url' => $article?->shortUrlCode !== null ? rtrim((string) ($this->settingService->get('base_url') ?: ''), '/') . '/s/' . $article->shortUrlCode : null,
            'csrf_token' => CsrfGuard::generateToken(),
        ];
    }

    /**
     * Re-renders the editor with the submitted values preserved (module
     * usability review: a validation error on a plain HTML form POST must
     * never just dump raw JSON — the author would lose everything they
     * typed).
     *
     * @return array<string, mixed>
     */
    private function editorErrorContext(?Article $article, Request $request, string $error): array
    {
        $context = $this->editorContext($article);

        $context['title_value'] = (string) $request->getBody('title', '');
        $context['summary_value'] = (string) $request->getBody('summary', '');
        $context['visibility_value'] = (string) $request->getBody('visibility', Article::VISIBILITY_PUBLIC);
        $context['is_indexed_value'] = (bool) $request->getBody('is_indexed', false);
        $context['seo_keywords_value'] = (string) $request->getBody('seo_keywords', '');
        $context['seo_stop_date_value'] = (string) $request->getBody('seo_stop_date', '');
        $context['submit_error'] = $error;

        // Preserve the field list the author had just built (module
        // usability review) — without this, a validation error on an
        // unrelated field (e.g. the mandatory summary/image) would wipe
        // every form field they'd already added. The form is no longer
        // optional, so this now always applies (not just when a "has_form"
        // checkbox was ticked).
        $context['fields_override'] = $this->extractFields($request);

        return $context;
    }

    /**
     * Handles the article image upload (module addendum: mandatory
     * featured image). Returns null when no new file was submitted this
     * request — Repository\ArticleRepository::update() then keeps
     * whatever image was already set.
     */
    private function resolveUploadedImageFileId(Request $request, string $visibility, int $accountId): ?int
    {
        $uploadedFile = $request->getFile('image');
        if ($uploadedFile === null || empty($uploadedFile['tmp_name'])) {
            return null;
        }

        $roleMin = match ($visibility) {
            Article::VISIBILITY_CHIEF => 'chief',
            Article::VISIBILITY_ADMIN => 'admin',
            default => 'public',
        };

        try {
            $fileId = $this->uploadHandler->handle(
                $uploadedFile, 'news/images', self::IMAGE_ALLOWED_MIMES, self::IMAGE_MAX_SIZE_BYTES, $roleMin, 'news', $accountId
            );

            // Same generate-at-upload pipeline as the core photo contexts
            // (Core\Http\Controller\UploadController): the list/home cards
            // render the 192px thumb and the detail page the 1024px md
            // rendition instead of the full multi-megabyte original.
            // generate() never throws — a derivative that couldn't be
            // produced must not fail the upload.
            if ($this->imageVariantService !== null) {
                foreach (\Core\Photo\ImageVariantService::VARIANTS as $variant) {
                    $this->imageVariantService->generate($fileId, $variant);
                }
            }

            return $fileId;
        } catch (UploadException $e) {
            // Same reasoning as Gallery\Service\MediaService::store(): the
            // upload handler's own sentence is worth keeping, but only
            // because Core\File\UploadException claims it is fit for a
            // visitor — the helper is what checks that claim.
            throw new NewsException(
                UserFacingMessage::from(
                    $e,
                    "L'image n'a pas pu être envoyée — vérifiez son format et sa taille, puis réessayez."
                ),
                0,
                $e
            );
        }
    }

    /**
     * News images are always stored unencrypted (Core\File\UploadHandler
     * never sets the `encrypted` flag) — a plain file_get_contents is
     * enough, no need for Core\File\EncryptedFileStorageService here.
     * Returns null (poster renders without an image) whenever anything is
     * missing, rather than failing the whole PDF over a stale file row.
     */
    private function buildImageDataUri(?int $imageFileId): ?string
    {
        if ($imageFileId === null) {
            return null;
        }

        $file = $this->fileRepository->findById($imageFileId);
        if ($file === null) {
            return null;
        }

        $path = $this->storagePath . '/' . $file->relativePath;
        if (!is_file($path)) {
            return null;
        }

        $bytes = file_get_contents($path);
        if ($bytes === false) {
            return null;
        }

        return 'data:' . $file->mimeType . ';base64,' . base64_encode($bytes);
    }

    private function resolveDefaultFinanceAccountId(int $scoutYearId): ?int
    {
        if ($this->financeAccount === null) {
            return null;
        }

        $email = AuthSession::getEmail();
        if ($email === null) {
            return null;
        }

        $linkedMembers = $this->memberService->getLinkedMembers($email, $scoutYearId);
        $sections = $this->sectionService->getAllWithBranches();
        $sectionId = SectionPickerHelper::resolveDefault(null, $linkedMembers, $sections);
        return $sectionId !== null ? $this->financeAccount->getDefaultAccountForSection($sectionId) : null;
    }

    /**
     * The form is always present now, and its first field is always a
     * pinned "bloc de texte" (usability review: "there must always be a
     * bloc de texte on top, it cannot be deleted or moved") — no separate
     * article body editor. Whenever the field list is empty, or its first
     * field isn't type 'text' (a brand-new article, or one saved before
     * this change), a draft text field is synthesized and prepended so the
     * editor/public page always has at least the article's content to
     * show. id=0 marks it as never-saved (same convention JS already uses
     * for id=null on a freshly-added field — 0 is falsy in JS too, so
     * every existing "is this saved?" check keeps working unmodified); the
     * moment it's actually saved it gets a real id and this never re-fires
     * for that article again.
     *
     * @param FormField[] $fields
     * @return FormField[]
     */
    private function withDefaultTextField(array $fields, ?Article $article): array
    {
        if ($fields !== [] && $fields[0]->fieldType === FormField::TYPE_TEXT) {
            return $fields;
        }

        $legacyBodyHtml = $fields === [] && $article !== null ? $this->articleService->getBodyHtml($article->id) : '';

        $draft = new FormField(
            id: 0, formId: 0, sortOrder: 0, fieldType: FormField::TYPE_TEXT, label: null, isRequired: false,
            optionsSource: null, optionsManual: null, capacityMax: null, pricePerUnit: null,
            confirmationText: $legacyBodyHtml !== '' ? $legacyBodyHtml : null
        );

        return array_merge([$draft], $fields);
    }

    /**
     * Whether at least one field actually collects an answer — gates
     * whether the submission mechanics (open/closed state, contact email,
     * submit button on the public page; scheduling/access/payment/etc. in
     * the editor) make any sense to show at all.
     *
     * @param FormField[] $fields
     */
    private function hasRealInputField(array $fields): bool
    {
        foreach ($fields as $field) {
            if (!$field->isNonInput()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Preview tab (module spec §11.7): capacity indicators show the FULL
     * max (nobody has actually responded in preview), not the live
     * remaining count.
     *
     * @param FormField[] $fields
     * @param array<int, string> $memberOptions
     * @return array<int, array<string, mixed>>
     */
    private function fieldsForPreview(array $fields, array $memberOptions): array
    {
        return array_map(fn(FormField $field) => [
            'field' => $field,
            'options' => $field->optionsSource === FormField::OPTIONS_SOURCE_MEMBERS ? array_values($memberOptions) : $field->manualOptions(),
            'remaining_capacity' => $field->capacityMax,
        ], $fields);
    }

    /**
     * There is no standalone "Accès au formulaire" setting anymore
     * (usability review) — it's derived from the article's own visibility:
     * public/direct_link pages are reachable by anyone anyway, so the form
     * itself requires no login either; chief/admin visibility already
     * requires being logged in just to see the page, so the form is
     * effectively "identified" there too.
     *
     * @return array{access: string, response_limit: string, opens_at: ?string, closes_at: ?string, is_force_closed: bool, response_role_min: string, daily_digest_enabled: bool, finance_account_id: ?int}
     */
    private function extractFormSettings(Request $request, string $visibility): array
    {
        $access = in_array($visibility, [Article::VISIBILITY_PUBLIC, Article::VISIBILITY_DIRECT_LINK], true)
            ? NewsForm::ACCESS_PUBLIC
            : NewsForm::ACCESS_IDENTIFIED;

        return [
            'access' => $access,
            'response_limit' => (string) $request->getBody('form_response_limit', NewsForm::RESPONSE_LIMIT_UNLIMITED),
            'opens_at' => $this->nullableString($request->getBody('form_opens_at')),
            'closes_at' => $this->nullableString($request->getBody('form_closes_at')),
            'is_force_closed' => (bool) $request->getBody('form_is_force_closed', false),
            'response_role_min' => (string) $request->getBody('form_response_role_min', 'chief'),
            'daily_digest_enabled' => (bool) $request->getBody('form_daily_digest_enabled', false),
            'finance_account_id' => $this->resolveFormFinanceAccountId($request),
        ];
    }

    /**
     * The submitted finance account, validated against the very list the
     * form's own picker is built from (finance_accounts in edit()).
     *
     * Without this the id was stored verbatim and later drove
     * Service\ResponseService's createReceivable(), so an author could bind
     * a form's payments to any account id at all — including a draft or
     * archived one the picker never offered. Unknown ids fall back to null
     * ("no payment") rather than raising: the field is optional, and a
     * silently-dropped account is safer than a receivable booked somewhere
     * nobody chose.
     */
    private function resolveFormFinanceAccountId(Request $request): ?int
    {
        $raw = $request->getBody('form_finance_account_id');
        if ($raw === null || $raw === '') {
            return null;
        }

        $accountId = (int) $raw;
        foreach ($this->financeAccount?->getConfiguredAccounts() ?? [] as $account) {
            if ($account['id'] === $accountId) {
                return $accountId;
            }
        }

        return null;
    }

    /**
     * @return array<int, array{id: ?int, field_type: string, label: ?string, is_required: bool, options_source: ?string, options_manual: ?string, capacity_max: ?int, price_per_unit: ?float, confirmation_text: ?string}>
     */
    private function extractFields(Request $request): array
    {
        $raw = json_decode((string) $request->getBody('fields_json', '[]'), true);
        if (!is_array($raw)) {
            return [];
        }

        return array_map(fn(array $f) => [
            'id' => isset($f['id']) && $f['id'] !== null ? (int) $f['id'] : null,
            'field_type' => (string) ($f['field_type'] ?? ''),
            'label' => isset($f['label']) && $f['label'] !== '' ? (string) $f['label'] : null,
            'is_required' => (bool) ($f['is_required'] ?? false),
            'options_source' => isset($f['options_source']) && $f['options_source'] !== '' ? (string) $f['options_source'] : null,
            'options_manual' => isset($f['options_manual']) && $f['options_manual'] !== '' ? (string) $f['options_manual'] : null,
            'capacity_max' => isset($f['capacity_max']) && $f['capacity_max'] !== '' && $f['capacity_max'] !== null ? (int) $f['capacity_max'] : null,
            'price_per_unit' => isset($f['price_per_unit']) && $f['price_per_unit'] !== '' && $f['price_per_unit'] !== null ? (float) $f['price_per_unit'] : null,
            'confirmation_text' => isset($f['confirmation_text']) && $f['confirmation_text'] !== '' ? (string) $f['confirmation_text'] : null,
        ], $raw);
    }

    /**
     * @param FormField[] $fields
     * @param array<int, string> $memberOptions
     * @return array<int, array<string, mixed>>
     */
    private function fieldsForTemplate(array $fields, array $memberOptions): array
    {
        return array_map(function (FormField $field) use ($memberOptions) {
            $options = $field->optionsSource === FormField::OPTIONS_SOURCE_MEMBERS ? array_values($memberOptions) : $field->manualOptions();
            return [
                'field' => $field,
                'options' => $options,
                'remaining_capacity' => $this->responseService->remainingCapacity($field),
            ];
        }, $fields);
    }

    /**
     * @param FormField[] $fields
     */
    private function hasPricedField(array $fields): bool
    {
        foreach ($fields as $field) {
            if ($field->isPriced()) {
                return true;
            }
        }
        return false;
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }

    private function ensureDigestTaskScheduled(): void
    {
        if ($this->schedulerService->find('news', 'send_response_digest', SendResponseDigestHandler::REFERENCE) === null) {
            $this->schedulerService->schedule('news', 'send_response_digest', new \DateTimeImmutable('+1 day'), [], SendResponseDigestHandler::REFERENCE);
        }
    }

}
