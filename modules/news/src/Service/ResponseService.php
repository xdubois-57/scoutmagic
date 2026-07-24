<?php

declare(strict_types=1);

namespace Modules\News\Service;

use Core\Mail\MailService;
use Core\Member\SectionService;
use Core\Security\Role;
use Core\Security\RoleResolver;
use Core\Url\ShortUrlService;
use Modules\Finance\Api\ExpectedReceivableInterface;
use Modules\Finance\Api\FinanceAccountInterface;
use Modules\Finance\Api\SepaQrCodeInterface;
use Modules\Finance\Api\StructuredCommunicationInterface;
use Modules\News\Repository\Article;
use Modules\News\Repository\FormField;
use Modules\News\Repository\FormResponse;
use Modules\News\Repository\FormResponseRepository;
use Modules\News\Repository\NewsForm;
use Twig\Environment;

/**
 * Handles form-response submission/editing: response_limit enforcement,
 * required-field/options validation, capacity locking, payment
 * calculation (nullable Finance dependency, ARCHITECTURE.md §7.5), and
 * the synchronous confirmation email (module spec §16: never via
 * SchedulerService).
 */
class ResponseService
{
    public function __construct(
        private FormResponseRepository $responseRepository,
        private RoleResolver $roleResolver,
        private SectionService $sectionService,
        private MailService $mailService,
        private Environment $twig,
        private ShortUrlService $shortUrlService,
        private string $baseUrl,
        private string $siteName,
        private ?StructuredCommunicationInterface $structuredCommunication = null,
        private ?ExpectedReceivableInterface $expectedReceivable = null,
        private ?SepaQrCodeInterface $sepaQrCode = null,
        private ?FinanceAccountInterface $financeAccount = null
    ) {
    }

    public function findById(int $id): ?FormResponse
    {
        return $this->responseRepository->findById($id);
    }

    /**
     * @return FormResponse[]
     */
    public function findByFormId(int $formId): array
    {
        return $this->responseRepository->findByFormId($formId);
    }

    /**
     * @return array<int, string> field_id => decrypted answer
     */
    public function getAnswers(int $responseId): array
    {
        return $this->responseRepository->getValues($responseId);
    }

    /**
     * Shared by the confirmation email and Controller\FormController's
     * confirmation screen (module spec §16: "SEPA QR code in both") — a
     * second QR render for the screen is a deliberate, cheap trade-off
     * over caching the email's PNG bytes on the response row.
     *
     * @return array{total: float, iban: string, beneficiary: string, communication: string, qr_data_uri: string}|null
     */
    public function buildPaymentSummary(NewsForm $form, FormResponse $response, float $total): ?array
    {
        if ($total <= 0.0 || $response->structuredCommunication === null || !$this->isPaymentAvailable() || $form->financeAccountId === null) {
            return null;
        }

        $accounts = $this->financeAccount->getConfiguredAccounts();
        $account = current(array_filter($accounts, fn($a) => $a['id'] === $form->financeAccountId));
        if ($account === false || $account['iban'] === null) {
            return null;
        }

        $qrPng = $this->sepaQrCode->generatePng(
            $account['holder_name'] ?? $this->siteName, $account['iban'], null,
            (int) round($total * 100), $response->structuredCommunication
        );

        return [
            'total' => $total,
            'iban' => $account['iban'],
            'beneficiary' => $account['holder_name'] ?? $this->siteName,
            'communication' => $response->structuredCommunication,
            'qr_data_uri' => 'data:image/png;base64,' . base64_encode($qrPng),
        ];
    }

    public function isPaymentAvailable(): bool
    {
        return $this->structuredCommunication !== null && $this->expectedReceivable !== null
            && $this->sepaQrCode !== null && $this->financeAccount !== null;
    }

    /**
     * @return array<int, string> memberYearId => display name — 'members' options_source, unavailable in public access (module spec)
     */
    public function resolveMemberOptions(?string $email, int $scoutYearId): array
    {
        if ($email === null) {
            return [];
        }

        $options = [];
        foreach ($this->roleResolver->getLinkedMemberYears($email, $scoutYearId) as $memberYearId) {
            $profile = $this->sectionService->hydrateMemberProfile($memberYearId);
            if ($profile !== null) {
                $options[$memberYearId] = $profile->getDisplayName();
            }
        }
        return $options;
    }

    /**
     * Remaining capacity for a field, or null when it has no cap.
     * $excludeResponseId "returns" that response's own previous value to
     * the pool (module spec §11.9 — editing counts against the same cap
     * it already consumed).
     */
    public function remainingCapacity(FormField $field, ?int $excludeResponseId = null): ?int
    {
        if ($field->capacityMax === null) {
            return null;
        }

        $used = $this->responseRepository->sumFieldValues($field->id, $excludeResponseId);
        return max(0, (int) ($field->capacityMax - $used));
    }

    /**
     * @param FormField[] $fields
     * @param array<int, string> $answers field_id => raw answer
     */
    public function computeTotal(array $fields, array $answers): float
    {
        $total = 0.0;
        foreach ($fields as $field) {
            if (!$field->isPriced()) {
                continue;
            }
            $quantity = (float) ($answers[$field->id] ?? 0);
            $total += $quantity * $field->pricePerUnit;
        }
        return round($total, 2);
    }

    /**
     * @param int[] $memberYearIds
     */
    public function hasAlreadyRespondedForAllMembers(NewsForm $form, array $memberYearIds): bool
    {
        if ($memberYearIds === []) {
            return false;
        }

        return count($this->responseRepository->findAnsweredMemberYearIds($form->id, $memberYearIds)) >= count($memberYearIds);
    }

    public function hasAlreadyResponded(NewsForm $form, ?int $userAccountId, ?int $memberYearId): bool
    {
        if ($form->responseLimit === NewsForm::RESPONSE_LIMIT_ONE_PER_ACCOUNT && $userAccountId !== null) {
            return $this->responseRepository->findByAccountAndForm($form->id, $userAccountId) !== null;
        }
        if ($form->responseLimit === NewsForm::RESPONSE_LIMIT_ONE_PER_MEMBER && $memberYearId !== null) {
            return $this->responseRepository->findByMemberYearAndForm($form->id, $memberYearId) !== null;
        }
        return false;
    }

    /**
     * @param FormField[] $fields
     * @param array<int, string|string[]> $answers field_id => raw answer (string, or string[] for checkbox)
     */
    public function submit(
        Article $article,
        NewsForm $form,
        array $fields,
        ?int $userAccountId,
        ?string $userEmail,
        int $scoutYearId,
        string $contactEmail,
        array $answers,
        ?int $memberYearId
    ): FormResponse {
        if (!$form->isOpen()) {
            throw new NewsException('Ce formulaire est fermé et n\'accepte plus de réponses.');
        }

        if ($form->access === NewsForm::ACCESS_IDENTIFIED && $userAccountId === null) {
            throw new NewsException('Vous devez être connecté(e) pour remplir ce formulaire.');
        }

        if ($form->responseLimit === NewsForm::RESPONSE_LIMIT_ONE_PER_MEMBER) {
            $linked = $this->roleResolver->getLinkedMemberYears((string) $userEmail, $scoutYearId);
            if ($memberYearId === null || !in_array($memberYearId, $linked, true)) {
                throw new NewsException('Membre invalide.');
            }
        } else {
            $memberYearId = null;
        }

        if ($this->hasAlreadyResponded($form, $userAccountId, $memberYearId)) {
            throw new NewsException('Vous avez déjà répondu à ce formulaire.');
        }

        $memberOptions = $form->access === NewsForm::ACCESS_IDENTIFIED
            ? $this->resolveMemberOptions($userEmail, $scoutYearId)
            : [];
        $normalizedAnswers = $this->validateAndNormalizeAnswers($fields, $answers, $memberOptions);

        $this->responseRepository->beginTransaction();
        try {
            foreach ($fields as $field) {
                if ($field->capacityMax === null) {
                    continue;
                }
                $requested = (float) ($normalizedAnswers[$field->id] ?? 0);
                $used = $this->responseRepository->sumFieldValues($field->id, null, lockForUpdate: true);
                if ($used + $requested > $field->capacityMax) {
                    throw new NewsException('Il n\'y a plus assez de places disponibles pour "' . $field->label . '".');
                }
            }

            $total = $this->computeTotal($fields, $normalizedAnswers);
            $structuredCommunication = null;
            $receivableId = null;

            if ($total > 0.0 && $form->financeAccountId !== null && $this->isPaymentAvailable()) {
                $structuredCommunication = $this->structuredCommunication->generate();
                $receivableId = $this->expectedReceivable->createReceivable(
                    'news', $form->id, $form->financeAccountId, (int) round($total * 100),
                    $structuredCommunication, $contactEmail
                );
            }

            $responseId = $this->responseRepository->create(
                $form->id, $userAccountId, $memberYearId, $contactEmail,
                $normalizedAnswers, $structuredCommunication, $receivableId
            );

            $this->responseRepository->commit();
        } catch (\Throwable $e) {
            $this->responseRepository->rollBack();
            throw $e;
        }

        $response = $this->responseRepository->findById($responseId);
        $this->sendConfirmationEmail($article, $form, $fields, $response, $total);

        return $response;
    }

    public function canEditResponse(FormResponse $response, NewsForm $form, Role $role, ?int $currentAccountId): bool
    {
        if ($role->hasAccess(Role::ADMIN)) {
            return true;
        }
        if ($response->userAccountId === null || $response->userAccountId !== $currentAccountId) {
            return false;
        }
        return $form->isOpen();
    }

    /**
     * @param FormField[] $fields
     * @param array<int, string|string[]> $answers
     */
    public function update(FormResponse $response, NewsForm $form, array $fields, string $contactEmail, array $answers, ?string $userEmail, int $scoutYearId): FormResponse
    {
        $memberOptions = $form->access === NewsForm::ACCESS_IDENTIFIED
            ? $this->resolveMemberOptions($userEmail, $scoutYearId)
            : [];
        $normalizedAnswers = $this->validateAndNormalizeAnswers($fields, $answers, $memberOptions);

        $this->responseRepository->beginTransaction();
        try {
            foreach ($fields as $field) {
                if ($field->capacityMax === null) {
                    continue;
                }
                $requested = (float) ($normalizedAnswers[$field->id] ?? 0);
                $used = $this->responseRepository->sumFieldValues($field->id, $response->id, lockForUpdate: true);
                if ($used + $requested > $field->capacityMax) {
                    throw new NewsException('Il n\'y a plus assez de places disponibles pour "' . $field->label . '".');
                }
            }

            $this->responseRepository->update($response->id, $contactEmail, $normalizedAnswers);
            $this->responseRepository->commit();
        } catch (\Throwable $e) {
            $this->responseRepository->rollBack();
            throw $e;
        }

        return $this->responseRepository->findById($response->id);
    }

    /**
     * @param FormField[] $fields
     * @param array<int, string|string[]> $answers
     * @param array<int, string> $memberOptions
     * @return array<int, string>
     */
    private function validateAndNormalizeAnswers(array $fields, array $answers, array $memberOptions): array
    {
        $normalized = [];

        foreach ($fields as $field) {
            if ($field->fieldType === FormField::TYPE_CONFIRMATION) {
                continue;
            }

            $raw = $answers[$field->id] ?? null;

            if ($field->fieldType === FormField::TYPE_CHECKBOX) {
                $values = is_array($raw) ? $raw : ($raw !== null && $raw !== '' ? [$raw] : []);
                if ($field->isRequired && $values === []) {
                    throw new NewsException('Le champ "' . $field->label . '" est obligatoire.');
                }
                $this->assertValidOptions($field, $values, $memberOptions);
                $normalized[$field->id] = implode(', ', $values);
                continue;
            }

            $value = is_array($raw) ? implode(', ', $raw) : (string) ($raw ?? '');

            if ($field->isRequired && trim($value) === '') {
                throw new NewsException('Le champ "' . $field->label . '" est obligatoire.');
            }

            if (in_array($field->fieldType, [FormField::TYPE_DROPDOWN, FormField::TYPE_RADIO], true) && $value !== '') {
                $this->assertValidOptions($field, [$value], $memberOptions);
            }

            if ($field->fieldType === FormField::TYPE_NUMBER && $value !== '' && !is_numeric($value)) {
                throw new NewsException('Le champ "' . $field->label . '" doit être un nombre.');
            }

            $normalized[$field->id] = $value;
        }

        return $normalized;
    }

    /**
     * @param string[] $values
     * @param array<int, string> $memberOptions
     */
    private function assertValidOptions(FormField $field, array $values, array $memberOptions): void
    {
        $validOptions = $field->optionsSource === FormField::OPTIONS_SOURCE_MEMBERS
            ? array_values($memberOptions)
            : $field->manualOptions();

        foreach ($values as $value) {
            if (!in_array($value, $validOptions, true)) {
                throw new NewsException('Valeur invalide pour le champ "' . $field->label . '".');
            }
        }
    }

    /**
     * @param FormField[] $fields
     */
    private function sendConfirmationEmail(Article $article, NewsForm $form, array $fields, FormResponse $response, float $total): void
    {
        $answers = $this->responseRepository->getValues($response->id);
        $answerLines = [];
        foreach ($fields as $field) {
            if ($field->fieldType === FormField::TYPE_CONFIRMATION || !isset($answers[$field->id])) {
                continue;
            }
            $answerLines[] = ['label' => $field->label, 'value' => $answers[$field->id]];
        }

        $payment = $this->buildPaymentSummary($form, $response, $total);

        $editUrl = null;
        if ($response->userAccountId !== null && $form->isOpen()) {
            $editPath = '/news/' . $article->id . '/form/responses/' . $response->id . '/edit';
            $code = $this->shortUrlService->createShortUrl($editPath, $response->userAccountId);
            $editUrl = rtrim($this->baseUrl, '/') . '/s/' . $code;
        }

        $context = [
            'site_name' => $this->siteName,
            'article_title' => $article->title,
            'answers' => $answerLines,
            'payment' => $payment,
            'edit_url' => $editUrl,
        ];

        $bodyHtml = $this->twig->render('@news/email/confirmation.html.twig', $context);
        $bodyText = $this->twig->render('@news/email/confirmation.text.twig', $context);

        $this->mailService->send(
            to: $response->contactEmail,
            subject: 'Confirmation — ' . $article->title,
            bodyHtml: $bodyHtml,
            bodyText: $bodyText
        );
    }
}
