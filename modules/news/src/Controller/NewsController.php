<?php

declare(strict_types=1);

namespace Modules\News\Controller;

use Core\Config\ScoutYearService;
use Core\Config\SettingService;
use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Pdf\PosterPdfService;
use Core\Scheduler\SchedulerService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\Role;
use Core\Security\UserAccountRepository;
use Modules\Finance\Api\ExpectedReceivableInterface;
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
        private ?FinanceAccountInterface $financeAccount = null,
        private ?ExpectedReceivableInterface $expectedReceivable = null
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
     * POST /news — create article (+ optional form), one combined request
     * (module spec §11.5: "Enregistrer... saves article + form definition
     * in one POST").
     *
     * @param array<string, string> $params
     */
    public function store(Request $request, array $params): Response
    {
        if (!CsrfGuard::validateToken((string) $request->getBody('_csrf_token', ''))) {
            return $this->json(['success' => false, 'error' => 'Jeton CSRF invalide.'], 403);
        }

        $accountId = (int) AuthSession::getUserAccountId();

        try {
            $article = $this->articleService->create(
                (string) $request->getBody('title', ''),
                (string) $request->getBody('body_html', ''),
                (string) $request->getBody('visibility', Article::VISIBILITY_PUBLIC),
                (bool) $request->getBody('is_indexed', false),
                $this->nullableString($request->getBody('seo_keywords')),
                $this->nullableString($request->getBody('seo_stop_date')),
                $accountId
            );

            if ((bool) $request->getBody('has_form', false)) {
                $this->formService->save($article->id, $this->extractFormSettings($request), $this->extractFields($request));
            }
        } catch (NewsException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 422);
        }

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
        if (!CsrfGuard::validateToken((string) $request->getBody('_csrf_token', ''))) {
            return $this->json(['success' => false, 'error' => 'Jeton CSRF invalide.'], 403);
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

        try {
            $article = $this->articleService->update(
                $article->id,
                (string) $request->getBody('title', ''),
                (string) $request->getBody('body_html', ''),
                (string) $request->getBody('visibility', Article::VISIBILITY_PUBLIC),
                (bool) $request->getBody('is_indexed', false),
                $this->nullableString($request->getBody('seo_keywords')),
                $this->nullableString($request->getBody('seo_stop_date')),
                $accountId
            );

            $existingForm = $this->formService->findByArticleId($article->id);
            if ((bool) $request->getBody('has_form', false)) {
                $this->formService->save($article->id, $this->extractFormSettings($request), $this->extractFields($request));
            } elseif ($existingForm !== null) {
                $this->formService->removeForm($existingForm, $article->id, $this->expectedReceivable);
            }
        } catch (NewsException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 422);
        }

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

        $baseUrl = rtrim((string) ($this->settingService->get('base_url') ?: ''), '/');
        $qrUrl = $baseUrl . '/s/' . $article->shortUrlCode;
        $shortName = (string) ($this->settingService->get('short_name') ?: '');

        $pdf = $this->posterPdfService->generate($article->title, $this->articleService->getBodyHtml($article->id), $qrUrl, $shortName);
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
        $fields = $form !== null ? $this->formService->getFields($form->id) : [];
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

        return $this->render('@news/detail.html.twig', [
            'article' => $article,
            'author_name' => $author?->firstName ?? $author?->email,
            'body_html' => $this->articleService->getBodyHtml($article->id),
            'form' => $form,
            'fields' => $this->fieldsForTemplate($fields, $memberOptions),
            'form_open' => $form?->isOpen() ?? false,
            'already_responded' => $alreadyResponded,
            'requires_login' => $form !== null && $form->access === NewsForm::ACCESS_IDENTIFIED && $accountId === null,
            'requires_member_selector' => $form !== null && $form->responseLimit === NewsForm::RESPONSE_LIMIT_ONE_PER_MEMBER,
            'payment_available' => $form !== null && $this->responseService->isPaymentAvailable() && $form->financeAccountId !== null && $this->hasPricedField($fields),
            'contact_email_default' => $email ?? '',
            'member_options' => $memberOptions,
            'csrf_token' => CsrfGuard::generateToken(),
        ]);
    }

    /**
     * @param Article[] $articles
     * @return array<int, array<string, mixed>>
     */
    private function buildListCards(array $articles, ?int $currentAccountId = null): array
    {
        return array_map(function (Article $article) use ($currentAccountId) {
            $form = $this->formService->findByArticleId($article->id);
            $bodyPlain = trim(html_entity_decode(strip_tags($this->articleService->getBodyHtml($article->id)), ENT_QUOTES, 'UTF-8'));
            $excerpt = mb_strlen($bodyPlain) > 160 ? mb_substr($bodyPlain, 0, 160) . '…' : $bodyPlain;

            $author = $currentAccountId !== null ? $this->userAccountRepository->findById($article->createdBy) : null;

            return [
                'article' => $article,
                'excerpt' => $excerpt,
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
        $fields = $form !== null ? $this->formService->getFields($form->id) : [];

        $financeAccounts = $this->financeAccount?->getConfiguredAccounts() ?? [];

        return [
            'article' => $article,
            'body_html' => $article !== null ? $this->articleService->getBodyHtml($article->id) : '',
            'form' => $form,
            'fields' => $fields,
            'field_types' => FormField::TYPES,
            'seo_ai_available' => $this->seoKeywordService->isAvailable(),
            'finance_available' => $this->financeAccount !== null,
            'finance_accounts' => $financeAccounts,
            'short_url' => $article?->shortUrlCode !== null ? rtrim((string) ($this->settingService->get('base_url') ?: ''), '/') . '/s/' . $article->shortUrlCode : null,
            'csrf_token' => CsrfGuard::generateToken(),
        ];
    }

    /**
     * @return array{access: string, response_limit: string, opens_at: ?string, closes_at: ?string, is_force_closed: bool, response_role_min: string, daily_digest_enabled: bool, finance_account_id: ?int}
     */
    private function extractFormSettings(Request $request): array
    {
        return [
            'access' => (string) $request->getBody('form_access', NewsForm::ACCESS_IDENTIFIED),
            'response_limit' => (string) $request->getBody('form_response_limit', NewsForm::RESPONSE_LIMIT_UNLIMITED),
            'opens_at' => $this->nullableString($request->getBody('form_opens_at')),
            'closes_at' => $this->nullableString($request->getBody('form_closes_at')),
            'is_force_closed' => (bool) $request->getBody('form_is_force_closed', false),
            'response_role_min' => (string) $request->getBody('form_response_role_min', 'chief'),
            'daily_digest_enabled' => (bool) $request->getBody('form_daily_digest_enabled', false),
            'finance_account_id' => $request->getBody('form_finance_account_id') !== null && $request->getBody('form_finance_account_id') !== ''
                ? (int) $request->getBody('form_finance_account_id') : null,
        ];
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
