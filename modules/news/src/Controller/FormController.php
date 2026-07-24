<?php

declare(strict_types=1);

namespace Modules\News\Controller;

use Core\Config\ScoutYearService;
use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\Role;
use Modules\Finance\Api\ExpectedReceivableInterface;
use Modules\News\Repository\FormField;
use Modules\News\Repository\FormResponse;
use Modules\News\Repository\NewsForm;
use Modules\News\Service\ArticleService;
use Modules\News\Service\FormService;
use Modules\News\Service\NewsException;
use Modules\News\Service\ResponseService;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Twig\Environment;

class FormController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private ArticleService $articleService,
        private FormService $formService,
        private ResponseService $responseService,
        private ScoutYearService $scoutYearService,
        private ?ExpectedReceivableInterface $expectedReceivable = null
    ) {
    }

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
        if (!CsrfGuard::validateToken((string) $request->getBody('_csrf_token', ''))) {
            return new Response('Jeton CSRF invalide.', 403);
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

        try {
            $response = $this->responseService->submit(
                $article, $form, $fields, $accountId, $email, $scoutYearId,
                (string) $request->getBody('contact_email', ''),
                $this->extractAnswers($request, $fields),
                $memberYearId
            );
        } catch (NewsException $e) {
            $memberOptions = $form->access === NewsForm::ACCESS_IDENTIFIED ? $this->responseService->resolveMemberOptions($email, $scoutYearId) : [];
            return $this->render('@news/detail.html.twig', [
                'article' => $article,
                'body_html' => $this->articleService->getBodyHtml($article->id),
                'form' => $form,
                'fields' => $this->fieldsForTemplate($fields, $memberOptions),
                'form_open' => $form->isOpen(),
                'already_responded' => false,
                'requires_login' => false,
                'requires_member_selector' => $form->responseLimit === NewsForm::RESPONSE_LIMIT_ONE_PER_MEMBER,
                'payment_available' => false,
                'contact_email_default' => $email ?? '',
                'member_options' => $memberOptions,
                'submit_error' => $e->getMessage(),
                'csrf_token' => CsrfGuard::generateToken(),
            ])->setStatusCode(422);
        }

        $storedAnswers = $this->responseService->getAnswers($response->id);
        $total = $this->responseService->computeTotal($fields, $storedAnswers);

        return $this->render('@news/confirmation.html.twig', [
            'article' => $article,
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
        $rows = array_map(function (FormResponse $response) use ($fields, $form, $role, $accountId) {
            return [
                'response' => $response,
                'answers' => $this->answerLines($fields, $this->responseService->getAnswers($response->id)),
                'payment' => $this->buildReceivableStatus($response),
                'can_edit' => $this->responseService->canEditResponse($response, $form, $role, $accountId),
            ];
        }, $this->responseService->findByFormId($form->id));

        return $this->render('@news/responses.html.twig', [
            'article' => $article,
            'form' => $form,
            'fields' => $fields,
            'rows' => $rows,
            'finance_available' => $this->expectedReceivable !== null,
            'csrf_token' => CsrfGuard::generateToken(),
        ]);
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
        $responses = $this->responseService->findByFormId($form->id);
        $xlsx = $this->buildXlsx($fields, $responses, $form);

        return (new Response($xlsx))
            ->setHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->setHeader('Content-Disposition', 'attachment; filename="reponses-' . $article->id . '.xlsx"')
            ->setHeader('Content-Length', (string) strlen($xlsx));
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
        if (!CsrfGuard::validateToken((string) $request->getBody('_csrf_token', ''))) {
            return new Response('Jeton CSRF invalide.', 403);
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

        try {
            $ids = array_map('intval', (array) ($data['ids'] ?? []));
            $this->formService->reorderFields($form->id, $ids);
        } catch (NewsException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 422);
        }

        return $this->json(['success' => true]);
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
            if ($field->fieldType === FormField::TYPE_CONFIRMATION) {
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
            if ($field->fieldType === FormField::TYPE_CONFIRMATION) {
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
    private function buildXlsx(array $fields, array $responses, NewsForm $form): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $pricedFields = array_values(array_filter($fields, fn(FormField $f) => $f->isPriced()));
        $hasPayment = $pricedFields !== [] && $this->expectedReceivable !== null;

        $columns = ['Contact'];
        $fieldColumnLetters = [];
        $col = 2;
        foreach ($fields as $field) {
            if ($field->fieldType === FormField::TYPE_CONFIRMATION) {
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

        foreach ($columns as $index => $header) {
            $sheet->setCellValue([$index + 1, 1], $header);
        }

        $rowNum = 2;
        foreach ($responses as $response) {
            $answers = $this->responseService->getAnswers($response->id);
            $sheet->setCellValue([1, $rowNum], $response->contactEmail);

            $colIndex = 2;
            foreach ($fields as $field) {
                if ($field->fieldType === FormField::TYPE_CONFIRMATION) {
                    continue;
                }
                $value = $answers[$field->id] ?? '';
                if ($field->fieldType === FormField::TYPE_SWITCH) {
                    $value = $value === '1' ? 'Oui' : 'Non';
                }
                $sheet->setCellValue([$colIndex, $rowNum], $value);
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
                $sheet->setCellValue([$colIndex + 2, $rowNum], $response->structuredCommunication ?? '');
                $sheet->setCellValue([$colIndex + 3, $rowNum], $status !== null ? $this->statusLabel($status['status']) : 'Non payé');
            }

            $rowNum++;
        }

        $writer = new Xlsx($spreadsheet);
        ob_start();
        $writer->save('php://output');
        return (string) ob_get_clean();
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
