<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\News\Controller;

use Core\Config\ScoutYearService;
use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Journal\JournalService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\HumanCheck\HumanCheckService;
use Core\Security\Role;
use Core\Http\FlashMessage;
use Core\Service\IntegerInput;
use Modules\Finance\Api\ExpectedReceivableInterface;
use Modules\Finance\Api\StatementImportStatusInterface;
use Modules\News\Repository\Article;
use Modules\News\Repository\FormField;
use Modules\News\Repository\FormResponse;
use Modules\News\Repository\NewsForm;
use Modules\News\Service\ArticleService;
use Modules\News\Service\FormService;
use Modules\News\Service\NewsException;
use Modules\News\Service\ResponseService;
use Modules\News\Service\TicketQrTokenService;
use Modules\News\Service\TicketService;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Modules\MassMail\Api\MassMailDraftInterface;
use Twig\Environment;

class FormController extends AbstractController
{
    /**
     * Core\Security\HumanCheck form key — must match
     * NewsController::HUMAN_CHECK_FORM_KEY (a signature made with one is
     * rejected against the other).
     */
    private const HUMAN_CHECK_FORM_KEY = 'news_form_response';

    /** The first audience column, and the export's first header. */
    private const MERGE_CONTACT_COLUMN = 'Contact';

    /**
     * The ticket's two merge columns. Offered only by a form that
     * delivers a ticket, so a plain sign-up's composer is unchanged.
     */
    private const MERGE_TICKET_COLUMN = 'Référence du billet';
    private const MERGE_TICKET_QR_COLUMN = 'QR du billet';

    public function __construct(
        protected Environment $twig,
        private ArticleService $articleService,
        private FormService $formService,
        private ResponseService $responseService,
        private ScoutYearService $scoutYearService,
        private JournalService $journalService,
        private ?ExpectedReceivableInterface $expectedReceivable = null,
        private ?HumanCheckService $humanCheck = null,
        // Optional-module dependency (ARCHITECTURE.md §7.5): null when
        // mass_mail is disabled, and the "Écrire aux répondants" button
        // then does not exist. Never an error — this module works exactly
        // as before without it, and it is the composition root, never this
        // controller, that decides whether mass_mail is on.
        private ?MassMailDraftInterface $massMailDraft = null,
        private string $baseUrl = '',
        // Finance again, and optional again: the one date that stops
        // somebody sending a reminder to people who have already paid.
        // Without it the « entrés sans paiement » warning still names the
        // consequence, it just cannot name the day.
        private ?StatementImportStatusInterface $statementStatus = null,
        // This module's own, and trailing-optional only so a caller that
        // never writes to anybody (the responses screen's read path) need
        // not build it.
        private ?TicketQrTokenService $ticketQrTokens = null
    ) {
    }

    /**
     * The cross filter — the whole of IT-05's screen half.
     *
     * Two questions get asked the morning after, and neither can be
     * answered by the payment state or the ticket state alone. « Entré et
     * impayé » is the list somebody reads to know who to write to; « payé
     * et jamais venu » explains a discrepancy in the count.
     */
    private const FILTER_ALL = 'all';
    private const FILTER_IN_UNPAID = 'in_unpaid';
    private const FILTER_PAID_ABSENT = 'paid_absent';

    /**
     * POST /news/{id}/form/submit — in-controller: article visibility +
     * form access intersection (module spec §10). Renders the
     * confirmation page directly (module spec §11.10: a distinct page,
     * not a flash message).
     *
     * @param array<string, string> $params
     */
    public function submit(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, '/news/' . (int) ($params['id'] ?? 0))) !== null) {
            return $guard;
        }

        $article = $this->articleService->findById((int) $params['id']);
        if ($article === null) {
            return new Response('Not Found', 404);
        }

        $role = Role::fromString(AuthSession::getRole());
        if (!$this->articleService->canView($article, $role)) {
            return new Response('Forbidden', 403);
        }

        $form = $this->formService->findByArticleId($article->id);
        if ($form === null) {
            return new Response('Not Found', 404);
        }

        $fields = $this->formService->getFields($form->id);
        $scoutYearId = $this->scoutYearService->getCurrentYear()['id'];
        $accountId = AuthSession::getUserAccountId();
        $email = AuthSession::getEmail();
        $memberYearId = $request->getBody('member_year_id') !== null && $request->getBody('member_year_id') !== ''
            ? (int) $request->getBody('member_year_id') : null;

        // Core\Security\HumanCheck: only applies to a non-identified
        // session (ARCHITECTURE.md §8 — an identified session's response
        // is never anonymous spam). A rejection re-renders the form with
        // a fresh challenge rather than a dead-end error page, same
        // pattern as the NewsException catch below — a legitimate slow
        // human never has to retype anything.
        $humanCheckResult = $this->humanCheck?->verify(
            self::HUMAN_CHECK_FORM_KEY,
            AuthSession::isAuthenticated(),
            $request->getBodyAll(),
            (string) $request->getServer('REMOTE_ADDR', '')
        );
        if ($humanCheckResult !== null && !$humanCheckResult->accepted) {
            return $this->rerenderFormWithError($request, $article, $form, $fields, $email, $scoutYearId, 'Une erreur est survenue. Veuillez réessayer.');
        }

        try {
            $response = $this->responseService->submit(
                $article, $form, $fields, $accountId, $email, $scoutYearId,
                (string) $request->getBody('contact_email', ''),
                $this->extractAnswers($request, $fields),
                $memberYearId
            );
        } catch (NewsException $e) {
            return $this->rerenderFormWithError($request, $article, $form, $fields, $email, $scoutYearId, $e->getMessage());
        }

        $this->journalService->log(
            'news', 'form_response_submitted', 'info', "Réponse soumise pour l'article « {$article->title} »",
            ['article_id' => $article->id, 'form_id' => $form->id, 'response_id' => $response->id], $accountId
        );

        $storedAnswers = $this->responseService->getAnswers($response->id);
        $total = $this->responseService->computeTotal($fields, $storedAnswers);

        return $this->render('@news/confirmation.html.twig', [
            'article' => $article,
            'breadcrumb_current' => $article->title,
            'response' => $response,
            'answers' => $this->answerLines($fields, $storedAnswers),
            'payment' => $this->responseService->buildPaymentSummary($form, $response, $total),
            'edit_url' => ($response->userAccountId !== null && $form->isOpen())
                ? '/news/' . $article->id . '/form/responses/' . $response->id . '/edit'
                : null,
        ]);
    }

    /**
     * GET /news/{id}/form/responses — in-controller: role vs response_role_min.
     *
     * @param array<string, string> $params
     */
    public function responses(Request $request, array $params): Response
    {
        $article = $this->articleService->findById((int) $params['id']);
        $form = $article !== null ? $this->formService->findByArticleId($article->id) : null;
        if ($article === null || $form === null) {
            return new Response('Not Found', 404);
        }

        $role = Role::fromString(AuthSession::getRole());
        if (!$role->hasAccess(Role::fromString($form->responseRoleMin))) {
            return new Response('Forbidden', 403);
        }

        $fields = $this->formService->getFields($form->id);
        $accountId = (int) AuthSession::getUserAccountId();
        $allRows = array_map(function (FormResponse $response) use ($fields, $form, $role, $accountId) {
            return [
                'response' => $response,
                'answers' => $this->answerLines($fields, $this->responseService->getAnswers($response->id)),
                'payment' => $this->buildReceivableStatus($response),
                'can_edit' => $this->responseService->canEditResponse($response, $form, $role, $accountId),
            ];
        }, $this->responseService->findByFormId($form->id));

        $filter = self::normalizeFilter((string) $request->getQuery('filter', self::FILTER_ALL));
        $rows = self::applyFilter($allRows, $filter);

        return $this->render('@news/responses.html.twig', [
            'article' => $article,
            'form' => $form,
            // A genuinely dynamic ancestor, so a controller trail rather
            // than the route's static `breadcrumb.ancestors`: these are
            // the responses to THIS article's form, and the article is
            // where the reader came from. Its own route is `public`, so
            // the step survives for every role that reaches this page —
            // which the management list, `chief`, would not have done for
            // the intendants this page is open to.
            'breadcrumb_trail' => [
                ['label' => $article->title, 'url' => '/news/' . $article->id],
            ],
            'fields' => $fields,
            'rows' => $rows,
            // The ticket column and the cross filter only mean anything
            // on a form that delivers a ticket; on one that does not,
            // this screen is exactly what it was.
            'issues_ticket' => $form->issuesTicket,
            'filter' => $filter,
            'filter_counts' => [
                self::FILTER_ALL => count($allRows),
                self::FILTER_IN_UNPAID => count(self::applyFilter($allRows, self::FILTER_IN_UNPAID)),
                self::FILTER_PAID_ABSENT => count(self::applyFilter($allRows, self::FILTER_PAID_ABSENT)),
            ],
            // Named on the « entrés sans paiement » list, because that
            // list contains, alongside the real ones, everybody who paid
            // after the last statement was imported. Without this
            // caption somebody sends them a reminder.
            'last_statement_imported_at' => $form->financeAccountId !== null
                ? $this->statementStatus?->lastStatementImportedAt($form->financeAccountId)
                : null,
            'finance_available' => $this->expectedReceivable !== null,
            // The nav rail's last tab, pushed to the far end because it
            // leaves the module — null hides it (FormService::
            // receivablesLinkFor()). It used to be a button in this
            // page's own action row, which is where it made the row
            // overflow on a 375-pixel screen.
            'finance_link' => $this->formService->receivablesLinkFor(
                $form,
                $this->expectedReceivable !== null,
                $role
            ),
            // Presentation only, and both halves matter: the mail merge
            // does not exist below `chief`, and it does not exist at all
            // when mass_mail is disabled. Hiding the button is a courtesy —
            // the route's own `chief` floor and mass_mail's own rules are
            // what actually refuse the request (SECURITY.md §3).
            'mail_draft_available' => $this->massMailDraft !== null && $role->hasAccess(Role::CHIEF),
            'csrf_token' => CsrfGuard::generateToken(),
        ]);
    }

    /**
     * The rows the filter is applied to, carrying only what it reads —
     * the response and its payment state. The screen builds a richer row
     * on top of the same set; the export and the mail draft need no more
     * than this.
     *
     * @return array<int, array{response: FormResponse, payment: ?array{amount_due: int, amount_received: int, status: string}}>
     */
    private function filterableRows(NewsForm $form): array
    {
        return array_map(
            fn (FormResponse $response) => [
                'response' => $response,
                'payment' => $this->buildReceivableStatus($response),
            ],
            $this->responseService->findByFormId($form->id)
        );
    }

    private static function normalizeFilter(string $filter): string
    {
        return in_array($filter, [self::FILTER_ALL, self::FILTER_IN_UNPAID, self::FILTER_PAID_ABSENT], true)
            ? $filter
            : self::FILTER_ALL;
    }

    /**
     * The cross of the two states, and the one place it is written.
     *
     * The screen, the export and the mail draft all read the same filter
     * from the same query parameter, so what a chief sees, downloads and
     * writes to cannot describe three different sets of people.
     *
     * « Impayé » is deliberately « not fully paid » — a partial payment
     * is somebody who still owes, and folding it in with « payé » would
     * quietly drop them from the list they belong on.
     *
     * @template TRow of array{response: FormResponse, payment: ?array{amount_due: int, amount_received: int, status: string}}
     * @param array<int, TRow> $rows
     * @return array<int, TRow>
     */
    private static function applyFilter(array $rows, string $filter): array
    {
        if ($filter === self::FILTER_ALL) {
            return $rows;
        }

        return array_values(array_filter($rows, static function (array $row) use ($filter): bool {
            $used = $row['response']->isTicketUsed();
            $paid = ($row['payment']['status'] ?? null) === 'paid';

            return $filter === self::FILTER_IN_UNPAID ? ($used && !$paid) : ($paid && !$used);
        }));
    }

    /**
     * POST /news/{id}/form/responses/mail-draft — hands the respondents to
     * the mail-merge composer as a ready-made draft, and redirects there.
     *
     * The four manual steps this replaces were: export to Excel, open the
     * mail merge, re-import the file just downloaded as an audience,
     * start writing.
     *
     * **The role gap is real and is checked here, twice.** This page is
     * `role_min: intendant`; the mail merge is `chief`. The route below
     * therefore declares `chief` — the guard is the boundary — and the
     * button is hidden below that, which is presentation only. mass_mail
     * then re-checks its own rules a third time (which section may send,
     * which lists may be targeted): a hidden button is not a boundary
     * (SECURITY.md §3), and neither is a route's floor on its own.
     *
     * Nothing is sent. The user lands in the ordinary composition screen
     * with an empty body.
     *
     * @param array<string, string> $params
     */
    public function createMailDraft(Request $request, array $params): Response
    {
        $articleId = (int) ($params['id'] ?? 0);
        if (($guard = $this->guardCsrf($request, '/news/' . $articleId . '/form/responses')) !== null) {
            return $guard;
        }

        $article = $this->articleService->findById($articleId);
        $form = $article !== null ? $this->formService->findByArticleId($article->id) : null;
        if ($article === null || $form === null) {
            return new Response('Not Found', 404);
        }

        // Disabled module: the feature is not offered, which is a 404 and
        // not an error page — the route exists only because manifests are
        // static, and there is nothing here to reach.
        if ($this->massMailDraft === null) {
            return new Response('Not Found', 404);
        }

        $role = Role::fromString(AuthSession::getRole());
        if (!$role->hasAccess(Role::fromString($form->responseRoleMin))) {
            return new Response('Forbidden', 403);
        }

        $fields = $this->formService->getFields($form->id);
        // The same filter the screen is showing: a chief who filtered to
        // « payé, jamais venu » and pressed « Écrire » means those people
        // and not everybody.
        $responses = array_map(
            static fn (array $row) => $row['response'],
            self::applyFilter($this->filterableRows($form), self::normalizeFilter((string) $request->getBody('filter', self::FILTER_ALL)))
        );

        try {
            $url = $this->massMailDraft->createMergeDraft(
                'Réponses — ' . $article->title,
                'Réponses — ' . $article->title,
                $this->responseColumns($fields, $form),
                $this->responseMergeRows($fields, $responses, $form),
                AuthSession::getRole(),
                AuthSession::getEmail() ?? '',
                (int) AuthSession::getUserAccountId(),
                $this->ticketDraftBody($form)
            );
        } catch (\Throwable $e) {
            FlashMessage::set('error', $e->getMessage());
            return $this->redirect('/news/' . $article->id . '/form/responses');
        }

        $this->journalService->log(
            'news',
            'form_responses_mail_draft',
            'info',
            "Brouillon d'e-mail créé vers les répondants de l'article « {$article->title} »",
            ['article_id' => $article->id, 'form_id' => $form->id, 'response_count' => count($responses)],
            (int) AuthSession::getUserAccountId()
        );

        return $this->redirect($url);
    }

    /**
     * The audience's column headers, in the SAME order the XLSX export
     * uses — `Contact` then every input field's label. Read from the same
     * `isNonInput()` rule rather than restated, so the spreadsheet a chief
     * downloads and the merge variables they get in the composer cannot
     * describe the same form differently.
     *
     * The export's four payment columns are deliberately absent: they are
     * accounting figures for a treasurer's spreadsheet, not something to
     * offer as a merge variable in a mail to the respondent.
     *
     * @param FormField[] $fields
     * @return string[]
     */
    private function responseColumns(array $fields, NewsForm $form): array
    {
        $columns = [self::MERGE_CONTACT_COLUMN];
        foreach ($fields as $field) {
            if (!$field->isNonInput()) {
                $columns[] = (string) $field->label;
            }
        }

        // The one thing the reminder before an event needs and the
        // spreadsheet export deliberately does not offer: the ticket, so
        // the message can give it back to whoever lost it.
        if ($form->issuesTicket) {
            $columns[] = self::MERGE_TICKET_COLUMN;
            $columns[] = self::MERGE_TICKET_QR_COLUMN;
        }

        return $columns;
    }

    /**
     * The reminder's starting body, or null — the composer opens empty
     * for a form that delivers no ticket, exactly as it always has.
     *
     * **A starting block, not a written message.** The rest is still the
     * staff's to write, and this block can be deleted; what it exists for
     * is the QR, which a chief cannot insert by hand — the composer's own
     * image button takes a URL, and this one is a merge variable that
     * only resolves per recipient.
     *
     * Wrapped in a section (`{{#…}} … {{/…}}`, mass_mail's MergeRenderer)
     * so a response that has no reference yet — one recorded before the
     * switch was raised and not yet backfilled — receives the message
     * without an empty frame and a blank reference.
     */
    private function ticketDraftBody(NewsForm $form): ?string
    {
        if (!$form->issuesTicket) {
            return null;
        }

        $reference = '{{' . self::MERGE_TICKET_COLUMN . '}}';
        $qr = '{{' . self::MERGE_TICKET_QR_COLUMN . '}}';

        return '{{#' . self::MERGE_TICKET_COLUMN . '}}'
            . '<p>Voici votre billet, à présenter à l\'entrée :</p>'
            . '<p><strong>' . $reference . '</strong></p>'
            . '<p><img src="' . $qr . '" alt="QR du billet" width="200" height="200"></p>'
            . '<p>Si l\'image ne s\'affiche pas, la référence ci-dessus suffit : nous pouvons la saisir à la main.</p>'
            . '{{/' . self::MERGE_TICKET_COLUMN . '}}';
    }

    /**
     * One row per response, keyed by the headers above. A switch answer
     * reads "Oui"/"Non" exactly as it does in the export — a merge
     * variable rendering "1" in a mail to a family would be nonsense.
     *
     * @param FormField[] $fields
     * @param FormResponse[] $responses
     * @return list<array{email: string, values: array<string, string>}>
     */
    private function responseMergeRows(array $fields, array $responses, NewsForm $form): array
    {
        $rows = [];
        foreach ($responses as $response) {
            $answers = $this->responseService->getAnswers($response->id);
            $values = [self::MERGE_CONTACT_COLUMN => (string) $response->contactEmail];

            if ($form->issuesTicket) {
                $reference = $response->hasTicket() ? (string) $response->ticketReference : null;
                $values[self::MERGE_TICKET_COLUMN] = $reference !== null ? TicketService::format($reference) : '';
                // An absolute URL rather than an inline image: a
                // mail-merge body is sanitized, and the sanitizer refuses
                // `data:`. Same mechanism as finance's payment reminder
                // (Service\TicketQrTokenService).
                $values[self::MERGE_TICKET_QR_COLUMN] = $reference !== null
                    ? (string) ($this->ticketQrTokens?->urlFor($reference, $this->baseUrl) ?? '')
                    : '';
            }

            foreach ($fields as $field) {
                if ($field->isNonInput()) {
                    continue;
                }
                $value = (string) ($answers[$field->id] ?? '');
                if ($field->fieldType === FormField::TYPE_SWITCH) {
                    $value = $value === '1' ? 'Oui' : 'Non';
                }
                $values[(string) $field->label] = $value;
            }

            $rows[] = ['email' => (string) $response->contactEmail, 'values' => $values];
        }

        return $rows;
    }

    /**
     * GET /news/{id}/form/responses/export — XLSX (module spec §9).
     *
     * @param array<string, string> $params
     */
    public function exportResponses(Request $request, array $params): Response
    {
        $article = $this->articleService->findById((int) $params['id']);
        $form = $article !== null ? $this->formService->findByArticleId($article->id) : null;
        if ($article === null || $form === null) {
            return new Response('Not Found', 404);
        }

        $role = Role::fromString(AuthSession::getRole());
        if (!$role->hasAccess(Role::fromString($form->responseRoleMin))) {
            return new Response('Forbidden', 403);
        }

        $fields = $this->formService->getFields($form->id);
        // The same filter the screen is showing — « dans l'ordre affiché,
        // filtre compris ». A download that quietly held more people than
        // the list above it would be worse than no filter at all.
        $responses = array_map(
            static fn (array $row) => $row['response'],
            self::applyFilter($this->filterableRows($form), self::normalizeFilter((string) $request->getQuery('filter', self::FILTER_ALL)))
        );
        $xlsx = $this->buildXlsx($fields, $responses, $form);

        $this->journalService->log(
            'news', 'form_responses_exported', 'info', "Export des réponses de l'article « {$article->title} »",
            ['article_id' => $article->id, 'form_id' => $form->id, 'response_count' => count($responses)],
            (int) AuthSession::getUserAccountId()
        );

        return \Core\Http\SpreadsheetResponse::download($xlsx, 'reponses-' . $article->id . '.xlsx');
    }

    /**
     * GET /news/{id}/form/responses/{response_id}/edit — in-controller
     * access check (module spec §11.9).
     *
     * @param array<string, string> $params
     */
    public function editResponse(Request $request, array $params): Response
    {
        [$article, $form, $response, $error] = $this->loadResponseContext($params);
        if ($error !== null) {
            return $error;
        }

        $role = Role::fromString(AuthSession::getRole());
        $accountId = AuthSession::getUserAccountId();
        if (!$this->responseService->canEditResponse($response, $form, $role, $accountId)) {
            return new Response('Forbidden', 403);
        }

        $fields = $this->formService->getFields($form->id);
        $scoutYearId = $this->scoutYearService->getCurrentYear()['id'];
        $memberOptions = $form->access === NewsForm::ACCESS_IDENTIFIED
            ? $this->responseService->resolveMemberOptions(AuthSession::getEmail(), $scoutYearId)
            : [];
        $existingAnswers = $this->responseService->getAnswers($response->id);

        return $this->render('@news/response_edit.html.twig', [
            'article' => $article,
            'form' => $form,
            'response' => $response,
            'fields' => $this->fieldsForTemplate($fields, $memberOptions, excludeResponseId: $response->id),
            'existing_answers' => $existingAnswers,
            'member_options' => $memberOptions,
            'csrf_token' => CsrfGuard::generateToken(),
        ]);
    }

    /**
     * POST /news/{id}/form/responses/{response_id}/edit
     *
     * @param array<string, string> $params
     */
    public function updateResponse(Request $request, array $params): Response
    {
        if (($guard = $this->guardCsrf($request, '/news/' . (int) ($params['id'] ?? 0) . '/form/responses/' . (int) ($params['response_id'] ?? 0) . '/edit')) !== null) {
            return $guard;
        }

        [$article, $form, $response, $error] = $this->loadResponseContext($params);
        if ($error !== null) {
            return $error;
        }

        $role = Role::fromString(AuthSession::getRole());
        $accountId = AuthSession::getUserAccountId();
        if (!$this->responseService->canEditResponse($response, $form, $role, $accountId)) {
            return new Response('Forbidden', 403);
        }

        $fields = $this->formService->getFields($form->id);
        $scoutYearId = $this->scoutYearService->getCurrentYear()['id'];

        try {
            $this->responseService->update(
                $response, $form, $fields,
                (string) $request->getBody('contact_email', ''),
                $this->extractAnswers($request, $fields),
                AuthSession::getEmail(), $scoutYearId
            );
        } catch (NewsException $e) {
            $memberOptions = $form->access === NewsForm::ACCESS_IDENTIFIED
                ? $this->responseService->resolveMemberOptions(AuthSession::getEmail(), $scoutYearId)
                : [];
            return $this->render('@news/response_edit.html.twig', [
                'article' => $article,
                'form' => $form,
                'response' => $response,
                'fields' => $this->fieldsForTemplate($fields, $memberOptions, excludeResponseId: $response->id),
                'existing_answers' => $this->responseService->getAnswers($response->id),
                'member_options' => $memberOptions,
                'submit_error' => $e->getMessage(),
                'csrf_token' => CsrfGuard::generateToken(),
            ])->setStatusCode(422);
        }

        $this->journalService->log(
            'news', 'form_response_updated', 'info', "Réponse modifiée pour l'article « {$article->title} »",
            ['article_id' => $article->id, 'form_id' => $form->id, 'response_id' => $response->id], $accountId
        );

        return $this->redirect('/news/' . $article->id);
    }

    /**
     * PATCH /news/{id}/form/fields/reorder
     *
     * @param array<string, string> $params
     */
    public function reorderFields(Request $request, array $params): Response
    {
        $data = json_decode($request->getRawBody(), true);
        if (!is_array($data) || !CsrfGuard::validateToken((string) ($data['_csrf_token'] ?? ''))) {
            return $this->json(['success' => false, 'error' => 'Requête invalide.'], 400);
        }

        $article = $this->articleService->findById((int) $params['id']);
        $form = $article !== null ? $this->formService->findByArticleId($article->id) : null;
        if ($article === null || $form === null) {
            return $this->json(['success' => false, 'error' => 'Introuvable.'], 404);
        }

        $role = Role::fromString(AuthSession::getRole());
        $accountId = (int) AuthSession::getUserAccountId();
        if (!$this->articleService->canEdit($article, $role, $accountId)) {
            return $this->json(['success' => false, 'error' => 'Accès refusé.'], 403);
        }

        $ids = IntegerInput::idList($data['ids'] ?? []);
        if ($ids === null) {
            return $this->json(['success' => false, 'error' => 'Identifiant invalide.'], 400);
        }

        try {
            $this->formService->reorderFields($form->id, $ids);
        } catch (NewsException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 422);
        }

        return $this->json(['success' => true]);
    }

    /**
     * Re-renders the submission form with an error message and a fresh
     * Core\Security\HumanCheck challenge — shared by the human-check
     * rejection path and the NewsException catch in submit(). Never a
     * dead-end error page: every field is prefilled with the values the
     * visitor just posted (checkbox arrays included), along with their
     * contact email and member selection — nothing to retype, same
     * value-injection contract as response_edit.html.twig.
     *
     * @param FormField[] $fields
     */
    private function rerenderFormWithError(Request $request, Article $article, NewsForm $form, array $fields, ?string $email, int $scoutYearId, string $errorMessage): Response
    {
        $memberOptions = $form->access === NewsForm::ACCESS_IDENTIFIED ? $this->responseService->resolveMemberOptions($email, $scoutYearId) : [];

        return $this->render('@news/detail.html.twig', [
            'article' => $article,
            'breadcrumb_current' => $article->title,
            'form' => $form,
            'fields' => $this->fieldsForTemplate($fields, $memberOptions),
            'has_real_input' => (bool) array_filter($fields, fn(FormField $f) => !$f->isNonInput()),
            'form_open' => $form->isOpen(),
            'already_responded' => false,
            'requires_login' => false,
            'requires_member_selector' => $form->responseLimit === NewsForm::RESPONSE_LIMIT_ONE_PER_MEMBER,
            'payment_available' => false,
            'contact_email_default' => (string) $request->getBody('contact_email', $email ?? ''),
            'submitted_answers' => $this->extractAnswers($request, $fields),
            'member_year_id_selected' => (string) $request->getBody('member_year_id', ''),
            'member_options' => $memberOptions,
            'submit_error' => $errorMessage,
            'csrf_token' => CsrfGuard::generateToken(),
            'human_check' => $this->humanCheck !== null && !AuthSession::isAuthenticated()
                ? $this->humanCheck->generateChallenge(self::HUMAN_CHECK_FORM_KEY)
                : null,
        ])->setStatusCode(422);
    }

    /**
     * @param array<string, string> $params
     * @return array{0: ?\Modules\News\Repository\Article, 1: ?NewsForm, 2: ?FormResponse, 3: ?Response}
     */
    private function loadResponseContext(array $params): array
    {
        $article = $this->articleService->findById((int) $params['id']);
        $form = $article !== null ? $this->formService->findByArticleId($article->id) : null;
        $response = $form !== null ? $this->responseService->findById((int) $params['response_id']) : null;

        if ($article === null || $form === null || $response === null || $response->formId !== $form->id) {
            return [null, null, null, new Response('Not Found', 404)];
        }

        return [$article, $form, $response, null];
    }

    /**
     * @param FormField[] $fields
     * @return array<int, string|string[]>
     */
    private function extractAnswers(Request $request, array $fields): array
    {
        $answers = [];
        foreach ($fields as $field) {
            if ($field->isNonInput()) {
                continue;
            }
            $answers[$field->id] = $request->getBody('field_' . $field->id, $field->fieldType === FormField::TYPE_CHECKBOX ? [] : '');
        }
        return $answers;
    }

    /**
     * @param FormField[] $fields
     * @param array<int, string> $memberOptions
     * @return array<int, array<string, mixed>>
     */
    private function fieldsForTemplate(array $fields, array $memberOptions, ?int $excludeResponseId = null): array
    {
        return array_map(function (FormField $field) use ($memberOptions, $excludeResponseId) {
            $options = $field->optionsSource === FormField::OPTIONS_SOURCE_MEMBERS ? array_values($memberOptions) : $field->manualOptions();
            return [
                'field' => $field,
                'options' => $options,
                'remaining_capacity' => $this->responseService->remainingCapacity($field, $excludeResponseId),
            ];
        }, $fields);
    }

    /**
     * @param FormField[] $fields
     * @param array<int, string> $answers
     * @return array<int, array{label: ?string, value: string}>
     */
    private function answerLines(array $fields, array $answers): array
    {
        $lines = [];
        foreach ($fields as $field) {
            if ($field->isNonInput()) {
                continue;
            }
            // Always one entry per field (even if this response predates
            // the field, e.g. it was added to the form after this
            // response was submitted) so the responses table's columns
            // never shift out of alignment row to row.
            $value = $answers[$field->id] ?? '';
            if ($field->fieldType === FormField::TYPE_SWITCH) {
                $value = $value === '1' ? 'Oui' : 'Non';
            }
            $lines[] = ['label' => $field->label, 'value' => $value];
        }
        return $lines;
    }

    /**
     * @return array{amount_due: int, amount_received: int, status: string}|null
     */
    private function buildReceivableStatus(FormResponse $response): ?array
    {
        if ($response->receivableId === null || $this->expectedReceivable === null) {
            return null;
        }
        return $this->expectedReceivable->getReceivableStatus($response->receivableId);
    }

    /**
     * @param FormField[] $fields
     * @param FormResponse[] $responses
     */
    private function buildXlsx(array $fields, array $responses, NewsForm $form): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $pricedFields = array_values(array_filter($fields, fn(FormField $f) => $f->isPriced()));
        $hasPayment = $pricedFields !== [] && $this->expectedReceivable !== null;

        $columns = ['Contact'];
        $fieldColumnLetters = [];
        $col = 2;
        foreach ($fields as $field) {
            if ($field->isNonInput()) {
                continue;
            }
            $columns[] = (string) $field->label;
            $fieldColumnLetters[$field->id] = Coordinate::stringFromColumnIndex($col);
            $col++;
        }

        if ($hasPayment) {
            $columns[] = 'Montant attendu';
            $columns[] = 'Montant reçu';
            $columns[] = 'Communication structurée';
            $columns[] = 'Statut paiement';
        }

        // The ticket, after the money and in the order the screen shows
        // them. An export saying only « payé / impayé » would send a
        // treasurer back to the site to learn an amount — and a
        // spreadsheet is precisely where an evening's accounts get done.
        if ($form->issuesTicket) {
            $columns[] = 'Référence du billet';
            $columns[] = 'État du billet';
            $columns[] = 'Heure d\'entrée';
        }

        // Explicit string typing on every cell that carries free text —
        // header labels, the contact address, and each answer. Without it
        // PhpSpreadsheet's DefaultValueBinder promotes a value beginning
        // with '=' (or +, -, @) to a live formula, and form answers are
        // submitted by anyone (POST /news/{id}/form/submit is public), so a
        // crafted answer like =HYPERLINK(...) would execute in the staff
        // member's spreadsheet when they open the export — the CSV/XLSX
        // formula-injection class. Same treatment Core\Member\Export\
        // MemberExportService already applies throughout.
        foreach ($columns as $index => $header) {
            $sheet->setCellValueExplicit([$index + 1, 1], (string) $header, DataType::TYPE_STRING);
        }

        $rowNum = 2;
        foreach ($responses as $response) {
            $answers = $this->responseService->getAnswers($response->id);
            $sheet->setCellValueExplicit([1, $rowNum], (string) $response->contactEmail, DataType::TYPE_STRING);

            $colIndex = 2;
            foreach ($fields as $field) {
                if ($field->isNonInput()) {
                    continue;
                }
                $value = $answers[$field->id] ?? '';
                if ($field->fieldType === FormField::TYPE_SWITCH) {
                    $value = $value === '1' ? 'Oui' : 'Non';
                }
                $sheet->setCellValueExplicit([$colIndex, $rowNum], (string) $value, DataType::TYPE_STRING);
                $colIndex++;
            }

            if ($hasPayment) {
                $formulaParts = [];
                foreach ($pricedFields as $priced) {
                    $letter = $fieldColumnLetters[$priced->id] ?? null;
                    if ($letter !== null) {
                        $formulaParts[] = $letter . $rowNum . '*' . $priced->pricePerUnit;
                    }
                }
                $sheet->setCellValue([$colIndex, $rowNum], '=' . implode('+', $formulaParts));

                $status = $response->receivableId !== null ? $this->buildReceivableStatus($response) : null;
                $sheet->setCellValue([$colIndex + 1, $rowNum], $status !== null ? $status['amount_received'] / 100 : 0);
                $sheet->setCellValueExplicit([$colIndex + 2, $rowNum], (string) ($response->structuredCommunication ?? ''), DataType::TYPE_STRING);
                $sheet->setCellValueExplicit([$colIndex + 3, $rowNum], $status !== null ? $this->statusLabel($status['status']) : 'Non payé', DataType::TYPE_STRING);
                $colIndex += 4;
            }

            if ($form->issuesTicket) {
                $sheet->setCellValueExplicit(
                    [$colIndex, $rowNum],
                    $response->hasTicket() ? TicketService::format((string) $response->ticketReference) : '',
                    DataType::TYPE_STRING
                );
                $sheet->setCellValueExplicit(
                    [$colIndex + 1, $rowNum],
                    $response->isTicketUsed() ? 'Entré' : 'Non venu',
                    DataType::TYPE_STRING
                );
                $sheet->setCellValueExplicit(
                    [$colIndex + 2, $rowNum],
                    (string) ($response->ticketUsedAt ?? ''),
                    DataType::TYPE_STRING
                );
            }

            $rowNum++;
        }

        return $spreadsheet;
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'paid' => 'Payé',
            'partial' => 'Partiel',
            default => 'Non payé',
        };
    }
}
