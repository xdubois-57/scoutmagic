<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\News\Service;

use Core\Journal\JournalService;
use Core\Mail\MailException;
use Core\Mail\MailService;
use Core\Mail\Template\EmailTemplateRenderer;
use Core\Member\SectionService;
use Core\Security\Role;
use Core\Security\RoleResolver;
use Core\Service\IntegerInput;
use Core\Url\ShortUrlService;
use Modules\Finance\Api\ExpectedReceivableInterface;
use Modules\Finance\Api\FinanceException;
use Modules\Finance\Api\FinanceAccountInterface;
use Modules\Finance\Api\ReceivableStanding;
use Modules\Finance\Api\SepaQrCodeInterface;
use Modules\Finance\Api\StructuredCommunicationInterface;
use Modules\News\Repository\Article;
use Modules\News\Repository\FormField;
use Modules\News\Repository\FormFieldRepository;
use Modules\News\Repository\FormResponse;
use Modules\News\Repository\FormResponseRepository;
use Modules\News\Repository\NewsForm;

/**
 * Handles form-response submission/editing: response_limit enforcement,
 * required-field/options validation, capacity locking, payment
 * calculation (nullable Finance dependency, ARCHITECTURE.md §7.5), and
 * the synchronous confirmation email (module spec §16: never via
 * SchedulerService).
 */
class ResponseService
{
    /**
     * The most one response may ask of a quantity field that has no
     * capacity of its own (issue #1525) — a capped field is bounded by
     * what remains of its capacity instead. FormField owns the figure.
     */
    public const MAX_QUANTITY_PER_RESPONSE = FormField::MAX_QUANTITY_PER_ANSWER;

    /** The module name finance's receivables are raised under. */
    private const RECEIVABLE_SOURCE = 'news';

    public function __construct(
        private FormResponseRepository $responseRepository,
        private RoleResolver $roleResolver,
        private SectionService $sectionService,
        private MailService $mailService,
        private EmailTemplateRenderer $emailTemplateRenderer,
        private ShortUrlService $shortUrlService,
        private string $baseUrl,
        private string $siteName,
        private ?StructuredCommunicationInterface $structuredCommunication = null,
        private ?ExpectedReceivableInterface $expectedReceivable = null,
        private ?SepaQrCodeInterface $sepaQrCode = null,
        private ?FinanceAccountInterface $financeAccount = null,
        private ?JournalService $journalService = null,
        // The ticket half. TicketService is this module's own, so it is
        // required; the ICS builder belongs to the calendar module and is
        // an optional dependency (ARCHITECTURE.md §7.5) — with calendar
        // disabled, the confirmation simply carries no attachment and
        // says everything it said before.
        private ?TicketService $ticketService = null,
        // Both are this module's own, and both are trailing-optional for
        // the same reason as the finance four above: a caller that only
        // needs the read side (the responses screen, the digest) has no
        // business constructing a mailer. When they are null the form
        // simply issues no ticket.
        private ?TicketMailService $ticketMail = null,
        /**
         * Where the running capacity total lives
         * (`news_form_fields.capacity_used`). Trailing-optional so the
         * many existing constructions keep working; when it is absent the
         * capacity check falls back on summing the whole column, which is
         * what every caller did before.
         */
        private ?FormFieldRepository $fieldRepository = null,
        /**
         * Who the Desk ids of a « Membres » answer designate now — the
         * read half of MemberAnswer. Trailing-optional like the one
         * above; without it the members of the account are still offered
         * and validated, but an answer read back can name nobody and
         * shows MemberAnswer::UNAVAILABLE_LABEL, and a code kept from
         * before a merge is not recognised.
         */
        private ?MemberAnswerResolver $memberAnswers = null
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
     * The answers of a whole page of responses, in one query — see
     * Repository\FormResponseRepository::getValuesForResponses().
     *
     * @param int[] $responseIds
     * @return array<int, array<int, string>> response id => field_id => answer
     */
    public function getAnswersFor(array $responseIds): array
    {
        return $this->responseRepository->getValuesForResponses($responseIds);
    }

    /**
     * Shared by the confirmation email and Controller\FormController's
     * confirmation screen (module spec §16: "SEPA QR code in both") — a
     * second QR render for the screen is a deliberate, cheap trade-off
     * over caching the email's PNG bytes on the response row.
     *
     * **`total` is what is still to pay**, not the price of the answers:
     * once a response has been edited (issue #1525) its receivable may
     * already have received part of it, and a parent shown the whole sum
     * again would pay twice. `received` is what already came in, net of
     * refunds. Nothing left to pay — paid, waived, or overpaid — is no
     * payment block at all.
     *
     * @return array{
     *     total: float,
     *     received: float,
     *     iban: string,
     *     beneficiary: string,
     *     communication: string,
     *     qr_data_uri: string
     * }|null
     */
    public function buildPaymentSummary(NewsForm $form, FormResponse $response, float $total): ?array
    {
        if (
            $total <= 0.0
            || $response->structuredCommunication === null
            || !$this->isPaymentAvailable()
            || $form->financeAccountId === null
        ) {
            return null;
        }

        $receivedCents = 0;
        $standing = $this->standingOf($response);
        if ($standing !== null) {
            if ($standing->amountRemainingCents <= 0) {
                return null;
            }
            $total = $standing->amountRemainingCents / 100.0;
            $receivedCents = max(0, $standing->amountReceivedCents - $standing->amountRefundedCents);
        }

        $accounts = $this->financeAccount->getConfiguredAccounts();
        $account = current(array_filter($accounts, fn($a) => $a['id'] === $form->financeAccountId));
        if ($account === false || $account['iban'] === null) {
            return null;
        }

        $qrPng = $this->sepaQrCode->generatePng(
            $account['holder_name'] ?? $this->siteName,
            $account['iban'],
            null,
            (int) round($total * 100),
            $response->structuredCommunication
        );

        return [
            'total' => $total,
            'received' => $receivedCents / 100.0,
            'iban' => $account['iban'],
            'beneficiary' => $account['holder_name'] ?? $this->siteName,
            'communication' => $response->structuredCommunication,
            'qr_data_uri' => 'data:image/png;base64,' . base64_encode($qrPng),
        ];
    }

    /**
     * What the unit owes this respondent back, in cents — a paid response
     * corrected down below what came in (issue #1525). Zero when nothing
     * is owed, and whenever finance cannot say.
     */
    public function amountOwedBackCents(FormResponse $response): int
    {
        return $this->standingOf($response)->amountOverpaidCents ?? 0;
    }

    /**
     * What has come in for this response, in cents, net of refunds — what
     * the confirmation page shows beside the new total when nothing is
     * left to pay (a paid response corrected, issue #1525). Zero when
     * finance cannot say.
     */
    public function amountReceivedCents(FormResponse $response): int
    {
        $standing = $this->standingOf($response);

        return $standing !== null ? max(0, $standing->amountReceivedCents - $standing->amountRefundedCents) : 0;
    }

    private function standingOf(FormResponse $response): ?ReceivableStanding
    {
        if ($response->receivableId === null || $this->expectedReceivable === null) {
            return null;
        }

        return $this->expectedReceivable->getReceivableStanding(self::RECEIVABLE_SOURCE, $response->receivableId);
    }

    public function isPaymentAvailable(): bool
    {
        return $this->structuredCommunication !== null && $this->expectedReceivable !== null
            && $this->sepaQrCode !== null && $this->financeAccount !== null;
    }

    /**
     * The members this account may name, this scout year: the options of
     * a `members` field and of the « Membre concerné » selector alike.
     * Nobody for an anonymous visitor (module spec: unavailable in public
     * access).
     *
     * Labelled « Totem (Prénom Nom) » or « Prénom Nom »
     * (`MemberProfile::getDisplayNameFull()`, the Contact page's rule):
     * the bare totem used to be the whole label, and two children sharing
     * a first name were two identical options.
     *
     * @return array<int, MemberOption> memberYearId => option
     */
    public function resolveMemberOptions(?string $email, int $scoutYearId): array
    {
        if ($email === null) {
            return [];
        }

        $memberYearIds = $this->roleResolver->getLinkedMemberYears($email, $scoutYearId);
        $profiles = $this->sectionService->hydrateMemberProfiles($memberYearIds);

        $options = [];
        foreach ($memberYearIds as $memberYearId) {
            if (isset($profiles[$memberYearId])) {
                $profile = $profiles[$memberYearId];
                $options[$memberYearId] = new MemberOption(
                    $memberYearId,
                    $profile->deskId,
                    $profile->getDisplayNameFull()
                );
            }
        }
        return $options;
    }

    /**
     * The options one field offers, as value and label: a manual option is
     * both, a member is their Desk id and their name. The one place the
     * entry form, the edit form and the editor's preview take them from.
     *
     * `$storedAnswer` is the answer being edited. A member it holds whom
     * this account can no longer name (left the unit, another year, an
     * administrator correcting somebody else's answer) stays an option,
     * still selected, under MemberAnswer::UNAVAILABLE_LABEL — dropped, the
     * next save would erase the choice; named, the page would show a
     * member to someone outside their scope.
     *
     * @param array<int, MemberOption> $memberOptions
     * @return list<array{value: string, label: string}>
     */
    public function optionsFor(FormField $field, array $memberOptions, ?string $storedAnswer = null): array
    {
        if (!$field->isMemberSourced()) {
            return array_map(
                static fn(string $option): array => ['value' => $option, 'label' => $option],
                $field->manualOptions()
            );
        }

        $options = [];
        foreach ($memberOptions as $option) {
            $options[] = ['value' => $option->deskId, 'label' => $option->label];
        }

        if ($storedAnswer !== null) {
            $offered = array_column($options, 'value');
            foreach ($this->editableMemberAnswer($storedAnswer, $memberOptions) as $deskId) {
                if (!in_array($deskId, $offered, true)) {
                    $options[] = ['value' => $deskId, 'label' => MemberAnswer::UNAVAILABLE_LABEL];
                }
            }
        }

        return $options;
    }

    /**
     * Stored answers as the edit form pre-fills them: a `members` answer
     * becomes the option values it selects — one Desk id, or a list for
     * checkboxes — with a code kept from before a merge translated to the
     * member's current one when that member is offered. Every other
     * answer is as stored.
     *
     * @param FormField[] $fields
     * @param array<int, string> $storedAnswers
     * @param array<int, MemberOption> $memberOptions
     * @return array<int, string|list<string>>
     */
    public function formValues(array $fields, array $storedAnswers, array $memberOptions): array
    {
        $values = $storedAnswers;
        foreach ($fields as $field) {
            if (!$field->isMemberSourced() || !isset($storedAnswers[$field->id])) {
                continue;
            }
            $deskIds = $this->editableMemberAnswer($storedAnswers[$field->id], $memberOptions);
            $values[$field->id] = $field->fieldType === FormField::TYPE_CHECKBOX ? $deskIds : ($deskIds[0] ?? '');
        }

        return $values;
    }

    /**
     * The Desk ids an answer holds, each one the code the account's
     * options use for that member when the account may name them (a merge
     * may have changed it), as stored otherwise.
     *
     * @param array<int, MemberOption> $memberOptions
     * @return list<string>
     */
    private function editableMemberAnswer(string $storedAnswer, array $memberOptions): array
    {
        $stored = MemberAnswer::decode($storedAnswer);
        $offered = array_map(static fn(MemberOption $option): string => $option->deskId, $memberOptions);
        $unknown = array_values(array_filter($stored, static fn(string $id): bool => !in_array($id, $offered, true)));
        $current = $unknown !== [] && $this->memberAnswers !== null ? $this->memberAnswers->resolve($unknown) : [];

        $deskIds = [];
        foreach ($stored as $deskId) {
            $currentId = $current[$deskId]->deskId ?? null;
            $deskIds[] = $currentId !== null && in_array($currentId, $offered, true) ? $currentId : $deskId;
        }

        return array_values(array_unique($deskIds));
    }

    /**
     * The answers as people read them: every `members` answer becomes
     * the names of the members it designates now, « , »-separated, a code
     * that designates nobody reading MemberAnswer::UNAVAILABLE_LABEL — a
     * Desk code is never shown on its own. Every other answer is
     * returned as stored.
     *
     * For a whole set of responses at once, and resolved in one batch
     * (MemberAnswerResolver), so the responses page, the export and the
     * mail merge cost the same few queries whatever their size.
     *
     * @param FormField[] $fields
     * @param array<int, array<int, string>> $answersByResponse response id => field id => stored answer
     * @return array<int, array<int, string>> the same, readable
     */
    public function readableAnswers(array $fields, array $answersByResponse): array
    {
        $memberFields = array_filter(
            $fields,
            static fn(FormField $field): bool => $field->isMemberSourced()
        );
        if ($memberFields === []) {
            return $answersByResponse;
        }

        $deskIds = [];
        foreach ($answersByResponse as $answers) {
            foreach ($memberFields as $field) {
                array_push($deskIds, ...MemberAnswer::decode($answers[$field->id] ?? null));
            }
        }
        $resolved = $deskIds !== [] && $this->memberAnswers !== null
            ? $this->memberAnswers->resolve(array_values(array_unique($deskIds)))
            : [];

        foreach ($answersByResponse as $responseId => $answers) {
            foreach ($memberFields as $field) {
                $stored = $answers[$field->id] ?? null;
                if ($stored === null || $stored === '') {
                    continue;
                }
                $labels = array_map(
                    static fn(string $deskId): string => $resolved[$deskId]->label ?? MemberAnswer::UNAVAILABLE_LABEL,
                    MemberAnswer::decode($stored)
                );
                $answersByResponse[$responseId][$field->id] = $labels !== []
                    ? implode(', ', $labels)
                    : MemberAnswer::UNAVAILABLE_LABEL;
            }
        }

        return $answersByResponse;
    }

    /**
     * One response's answers, readable — see readableAnswers().
     *
     * @param FormField[] $fields
     * @return array<int, string>
     */
    public function readableAnswersOf(array $fields, int $responseId): array
    {
        return $this->readableAnswers($fields, [$responseId => $this->getAnswers($responseId)])[$responseId];
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

        // The running total the writes keep (schema.sql), read straight
        // off the field: no query, no decryption. This used to re-read
        // and re-decrypt the whole column of answers on every display of
        // the form and, inside the door's own loop, once per priced field
        // per scan.
        $used = $this->fieldRepository !== null
            ? $field->capacityUsed
            : $this->responseRepository->sumFieldValues($field, $excludeResponseId);

        // One response's own share, given back for the length of its
        // edit (module spec §11.9). Bounded to that response, unlike the
        // whole-column sum it replaces.
        if ($excludeResponseId !== null && $this->fieldRepository !== null) {
            $ownAnswer = $this->responseRepository->getValues($excludeResponseId)[$field->id] ?? null;
            $used -= self::numericAnswer($field, $ownAnswer);
        }

        return max(0, (int) ($field->capacityMax - $used));
    }

    /**
     * How long two identical answers from one address count as one
     * submission.
     *
     * Long enough to cover a double click, a refresh and a woken
     * application; short enough that a family answering again on purpose
     * an hour later is never turned away.
     */
    private const DUPLICATE_WINDOW_SECONDS = 120;

    /**
     * The response this submission would duplicate, or null.
     *
     * @param array<int, string> $normalizedAnswers the answers as they would be stored
     */
    private function findRecentIdenticalResponse(
        NewsForm $form,
        string $contactEmail,
        array $normalizedAnswers
    ): ?FormResponse {
        if (trim($contactEmail) === '') {
            return null;
        }

        $since = (new \DateTimeImmutable())
            ->modify('-' . self::DUPLICATE_WINDOW_SECONDS . ' seconds')
            ->format('Y-m-d H:i:s');

        foreach ($this->responseRepository->findRecentByContact($form->id, $contactEmail, $since) as $candidate) {
            if ($this->responseRepository->getValues($candidate->id) == $normalizedAnswers) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * A stored answer read as the places it consumes — exactly
     * FormField::storedQuantity(), zero for one the server would refuse
     * today (issue #1525). The same rule as the running total's own sum
     * (FormResponseRepository::sumFieldValues()), so an edit gives back
     * exactly what that total counted for it — and the one rule for what a
     * new answer adds too, capped field or plain number (#1546 review):
     * a plain field's fraction adds nothing, as its recount would count it.
     */
    private static function numericAnswer(FormField $field, ?string $answer): float
    {
        return (float) ($field->storedQuantity($answer) ?? 0);
    }

    /**
     * Locks every number field of the form before its answers are written
     * — capped or not, in ascending id order (#1546 review):
     * FormFieldRepository::lockUsedCapacities().
     *
     * @param FormField[] $fields
     * @return array<int, float> field id => capacity_used, empty without the field repository
     */
    private function lockNumberFields(array $fields): array
    {
        if ($this->fieldRepository === null) {
            return [];
        }
        $ids = [];
        foreach ($fields as $field) {
            if ($field->fieldType === FormField::TYPE_NUMBER) {
                $ids[] = $field->id;
            }
        }

        return $this->fieldRepository->lockUsedCapacities($ids);
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

        return count($this->responseRepository->findAnsweredMemberYearIds(
            $form->id,
            $memberYearIds
        )) >= count($memberYearIds);
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
        // Before the form's own state: an imported form is closed too, and
        // this says why, whatever its row says (D11).
        if ($article->isImported()) {
            throw new NewsException(ArticleService::IMPORTED_FORM_MESSAGE);
        }
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
        $normalizedAnswers = $this->validateAndNormalizeAnswers($fields, $answers, $memberOptions, null);
        $total = $this->computeTotalOrRefuse($fields, $normalizedAnswers);

        // The double submission, which no rule above catches on a form
        // that accepts any number of answers: a second click while the
        // first request is in flight, a refresh, an application woken
        // from the background. On a paying form that was a second
        // response, a second receivable and a second ticket. An identical
        // answer from the same address inside the window is the SAME
        // submission, so it gets that submission's own response back
        // rather than a new one — a family legitimately answering twice
        // for two children types something different, and a minute later
        // is a new submission whatever they type.
        $duplicate = $this->findRecentIdenticalResponse($form, $contactEmail, $normalizedAnswers);
        if ($duplicate !== null) {
            return $duplicate;
        }

        $this->responseRepository->beginTransaction();
        try {
            $held = $this->lockNumberFields($fields);
            foreach ($fields as $field) {
                if ($field->capacityMax === null) {
                    continue;
                }
                $requested = (float) ($normalizedAnswers[$field->id] ?? 0);
                // The running total, under the same row lock the
                // whole-column sum used to take — and moved by this
                // submission's own share once it is written.
                $used = $this->fieldRepository !== null
                    ? ($held[$field->id] ?? 0.0)
                    : $this->responseRepository->sumFieldValues($field, null, lockForUpdate: true);
                if ($used + $requested > $field->capacityMax) {
                    throw new NewsException('Il n\'y a plus assez de places disponibles pour "' . $field->label . '".');
                }
            }

            [$structuredCommunication, $receivableId] = $this->opensReceivable($form, $total)
                ? $this->openReceivable($form, $total, $contactEmail, "Votre réponse n'a pas été enregistrée.")
                : [null, null];

            $responseId = $this->responseRepository->create(
                $form->id,
                $userAccountId,
                $memberYearId,
                $contactEmail,
                $normalizedAnswers,
                $structuredCommunication,
                $receivableId
            );

            // Kept for EVERY number field, capped or not (issue #1465):
            // a cap set later then starts from what is already taken, and
            // an answer in flight while the editor sets that cap is never
            // lost between the two — FormFieldRepository::
            // recomputeUsedCapacity() and this increment take the same
            // row lock.
            foreach ($fields as $field) {
                if ($field->fieldType !== FormField::TYPE_NUMBER) {
                    continue;
                }
                // Never below zero, never a fraction: what the recount
                // counts for it (issue #1525, #1546 review).
                $this->fieldRepository?->addUsedCapacity(
                    $field->id,
                    self::numericAnswer($field, $normalizedAnswers[$field->id] ?? null)
                );
            }

            $this->responseRepository->commit();
        } catch (\Throwable $e) {
            $this->responseRepository->rollBack();
            throw $e;
        }

        // The ticket, issued right after the commit and outside it: a
        // reference is a second, independent write, and a form that
        // issues none skips it entirely. A failure here must not undo a
        // submission that otherwise fully succeeded, which is the same
        // reasoning as the e-mail below.
        if ($form->issuesTicket && $this->ticketService !== null) {
            try {
                $this->ticketService->issueFor($this->responseRepository->findById($responseId));
            } catch (NewsException $e) {
                $this->journalService?->log(
                    'news',
                    'ticket_reference_failed',
                    'info',
                    'Échec de la génération de la référence de billet pour une réponse de formulaire',
                    ['article_id' => $article->id, 'form_id' => $form->id, 'response_id' => $responseId],
                    $userAccountId
                );
            }
        }

        $response = $this->responseRepository->findById($responseId);

        // The response is already committed at this point — a failure to
        // send the confirmation email (e.g. the site's SMTP "from"
        // address isn't configured yet) must never turn into a fatal
        // error for a submission that otherwise fully succeeded. Journaled
        // without personal data (no email address/answers), same
        // convention as everywhere else in the app.
        try {
            $this->sendConfirmationEmail($article, $form, $fields, $response, $total);
        } catch (MailException $e) {
            $this->journalService?->log(
                // 'warning' isn't a valid event_log.level (only 'info'/
                // 'security' — see JournalService::log()'s docblock).
                'news',
                'confirmation_email_failed',
                'info',
                'Échec de l\'envoi de l\'email de confirmation pour une réponse de formulaire',
                ['article_id' => $article->id, 'form_id' => $form->id, 'response_id' => $response->id],
                $response->userAccountId
            );
        }

        return $response;
    }

    /**
     * The total of validated answers, refused when it is negative — which
     * a negative unit price on one field can still produce once every
     * quantity is a whole number (issue #1525). A negative total is a debt
     * owed to the respondent, and no form is meant to create one.
     *
     * @param FormField[] $fields
     * @param array<int, string> $normalizedAnswers
     */
    private function computeTotalOrRefuse(array $fields, array $normalizedAnswers): float
    {
        $total = $this->computeTotal($fields, $normalizedAnswers);
        if ($total < 0.0) {
            throw new NewsException(
                'Le total de cette réponse ne peut pas être négatif : vérifiez les quantités indiquées.'
            );
        }

        return $total;
    }

    /** Whether a response of this total, on this form, owes a receivable. */
    private function opensReceivable(NewsForm $form, float $total): bool
    {
        return $total > 0.0 && $form->hasPaymentDestination() && $this->isPaymentAvailable();
    }

    /**
     * Raises the receivable a response owes, with its new structured
     * communication — at submission, and when an edit makes a free
     * response a paying one (issue #1525). Called inside the caller's
     * transaction.
     *
     * @param string $notRecorded the sentence telling the respondent their
     *        answer was not saved, which differs between a submission and
     *        an edit
     * @return array{0: string, 1: int} the communication and the receivable id
     */
    private function openReceivable(NewsForm $form, float $total, string $contactEmail, string $notRecorded): array
    {
        \assert(
            $this->structuredCommunication !== null && $this->expectedReceivable !== null
            && $form->financeAccountId !== null && $form->financeCategoryId !== null
        );
        $structuredCommunication = $this->structuredCommunication->generate();
        try {
            $receivableId = $this->expectedReceivable->createReceivable(
                self::RECEIVABLE_SOURCE,
                $form->id,
                $form->financeAccountId,
                (int) round($total * 100),
                $structuredCommunication,
                $contactEmail,
                $form->financeCategoryId
            );
        } catch (FinanceException $e) {
            // The form's payment settings no longer hold — its Finances
            // category was archived or deleted since it was chosen (issue
            // #838). Finance's own wording is meant for whoever configures
            // the form; the person answering is told what happened to
            // their answer.
            throw new NewsException(
                'Ce formulaire ne peut pas recevoir de paiement pour le moment : prévenez son responsable. '
                . $notRecorded,
                0,
                $e
            );
        }

        return [$structuredCommunication, $receivableId];
    }

    /**
     * Moves what an edited response owes to its new total — the
     * maintainer's decision on issue #1525: the amount due follows the
     * edit. Called inside update()'s transaction, so a refusal from
     * finance leaves the answers as they were.
     *
     * - no receivable yet, and the total became positive: one is raised,
     *   with its communication, exactly as at submission;
     * - a receivable whose amount differs: it moves in place, keeping its
     *   communication and every payment matched against it. Below what
     *   has already come in, finance is told so explicitly, and the
     *   surplus reads as a trop-perçu on the treasurer's « Paiements à
     *   traiter » — never silently;
     * - the total became zero: the receivable stays, at zero, with its
     *   communication — never deleted, even when nothing has come in yet:
     *   imports lag by days, and a transfer already made must still find
     *   its receivable and read as a trop-perçu rather than as money nobody
     *   expected. Finance reads a zero amount as owing nothing.
     *
     * @return bool whether what the response owes changed
     */
    private function moveReceivable(FormResponse $response, NewsForm $form, float $total, string $contactEmail): bool
    {
        $notRecorded = "Votre modification n'a pas été enregistrée.";
        $cents = (int) round($total * 100);
        // The link as committed now, under the response's row lock — never
        // the caller's copy of the response, loaded before that lock: a
        // competing edit may have raised a receivable since, and deciding
        // on the stale copy would raise a second one.
        $receivableId = $this->responseRepository->lockReceivableLink($response->id)['receivable_id'];

        $standing = null;
        if ($receivableId !== null && $this->expectedReceivable !== null) {
            $standing = $this->expectedReceivable->getReceivableStanding(
                self::RECEIVABLE_SOURCE,
                $receivableId
            );
            // Linked to a receivable finance no longer knows (deleted by
            // hand): the link is dropped, and the response is treated as
            // one that has none. Finance reads the receivable as committed
            // now inside this transaction (ExpectedReceivableService::
            // currentReads()), so one a competing edit raised after this
            // transaction's snapshot is found, never mistaken for gone.
            if ($standing === null) {
                $this->responseRepository->clearReceivable($response->id);
            }
        }

        if ($standing === null) {
            if ($receivableId !== null && $this->expectedReceivable === null) {
                // Finance is disabled: nothing can be read or moved.
                return false;
            }
            if (!$this->opensReceivable($form, $total)) {
                return false;
            }
            [$communication, $newReceivableId] = $this->openReceivable($form, $total, $contactEmail, $notRecorded);
            $this->responseRepository->setReceivable($response->id, $communication, $newReceivableId);

            return true;
        }

        if ($standing->amountDueCents === $cents) {
            return false;
        }

        $held = $standing->amountReceivedCents - $standing->amountRefundedCents;
        try {
            $this->expectedReceivable->updateReceivableAmount(
                $receivableId,
                $cents,
                allowBelowReceived: $cents < $held
            );
        } catch (FinanceException $e) {
            throw new NewsException(
                'Le montant dû de cette réponse ne peut pas être modifié pour le moment : prévenez le responsable '
                . 'du formulaire. ' . $notRecorded,
                0,
                $e
            );
        }

        return true;
    }

    /**
     * The one-off reprise of issue #1525: marks every response stored with
     * a quantity the server now refuses — a negative on any number field,
     * anything but a whole number on a quantity field — so the Staff d'U
     * sees them on the attention-points page (NewsAttentionProvider) and
     * corrects them. Nothing is changed in the answers themselves: what
     * the respondent meant is for a person to decide.
     *
     * Reads every number answer once; run from the composition root behind
     * a settings flag, never on a page.
     *
     * @return int the number of responses marked
     */
    public function flagResponsesWithInvalidQuantities(): int
    {
        if ($this->fieldRepository === null) {
            return 0;
        }

        /** @var array<int, ?FormField> $fields */
        $fields = [];
        $flagged = [];
        foreach ($this->responseRepository->findNumberAnswers() as $answer) {
            if (!array_key_exists($answer['field_id'], $fields)) {
                $fields[$answer['field_id']] = $this->fieldRepository->findById($answer['field_id']);
            }
            $field = $fields[$answer['field_id']];
            if ($field !== null && self::isInvalidStoredNumber($field, $answer['value'])) {
                $flagged[$answer['response_id']] = $answer['response_id'];
            }
        }

        $this->responseRepository->flagInvalidQuantity(array_values($flagged), date('Y-m-d H:i:s'));

        return count($flagged);
    }

    /**
     * Whether a stored number answer breaks the rules normalizeNumberAnswer()
     * now applies — the same rule as the door's (FormField::storedQuantity()).
     * An answer that cannot be read at all (null) is flagged too: a person
     * has to look at it.
     */
    private static function isInvalidStoredNumber(FormField $field, ?string $value): bool
    {
        if ($value === null) {
            return true;
        }
        if ($value === '') {
            return false;
        }
        if (is_numeric($value) && (float) $value < 0) {
            return true;
        }

        return $field->isQuantity() && $field->storedQuantity($value) === null;
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
    public function update(
        FormResponse $response,
        NewsForm $form,
        array $fields,
        string $contactEmail,
        array $answers,
        ?string $userEmail,
        int $scoutYearId,
        // The article, for the confirmation e-mail an edit that changes
        // the amount due sends (issue #1525). Trailing-optional so the
        // callers that only correct answers keep working; without it no
        // e-mail leaves.
        ?Article $article = null
    ): FormResponse
    {
        $memberOptions = $form->access === NewsForm::ACCESS_IDENTIFIED
            ? $this->resolveMemberOptions($userEmail, $scoutYearId)
            : [];
        $normalizedAnswers = $this->validateAndNormalizeAnswers(
            $fields,
            $answers,
            $memberOptions,
            $response->id,
            $this->responseRepository->getValues($response->id)
        );
        $total = $this->computeTotalOrRefuse($fields, $normalizedAnswers);

        $this->responseRepository->beginTransaction();
        try {
            // What this response already holds, so an edit is measured
            // against the pool MINUS its own share (module spec §11.9)
            // and the running total moves by the difference.
            $previousAnswers = $this->responseRepository->getValues($response->id);
            $held = $this->lockNumberFields($fields);

            foreach ($fields as $field) {
                if ($field->capacityMax === null) {
                    continue;
                }
                $requested = (float) ($normalizedAnswers[$field->id] ?? 0);
                $previous = self::numericAnswer($field, $previousAnswers[$field->id] ?? null);
                $used = $this->fieldRepository !== null
                    ? ($held[$field->id] ?? 0.0) - $previous
                    : $this->responseRepository->sumFieldValues($field, $response->id, lockForUpdate: true);
                if ($used + $requested > $field->capacityMax) {
                    throw new NewsException('Il n\'y a plus assez de places disponibles pour "' . $field->label . '".');
                }
            }

            $this->responseRepository->update($response->id, $contactEmail, $normalizedAnswers);

            // Every number field, capped or not: see submit() (#1465).
            foreach ($fields as $field) {
                if ($field->fieldType !== FormField::TYPE_NUMBER) {
                    continue;
                }
                $this->fieldRepository?->addUsedCapacity(
                    $field->id,
                    self::numericAnswer($field, $normalizedAnswers[$field->id] ?? null)
                        - self::numericAnswer($field, $previousAnswers[$field->id] ?? null)
                );
            }

            $amountChanged = $this->moveReceivable($response, $form, $total, $contactEmail);

            $this->responseRepository->commit();
        } catch (\Throwable $e) {
            $this->responseRepository->rollBack();
            throw $e;
        }

        $updated = $this->responseRepository->findById($response->id);
        \assert($updated !== null);

        // What the respondent owes changed: they are told, with what is
        // left to pay and how — the same confirmation as at submission,
        // and the same rule that a mail failure never undoes the save.
        if ($amountChanged && $article !== null) {
            try {
                $this->sendConfirmationEmail($article, $form, $fields, $updated, $total);
            } catch (MailException $e) {
                $this->journalService?->log(
                    'news',
                    'confirmation_email_failed',
                    'info',
                    'Échec de l\'envoi de l\'email de confirmation pour une réponse de formulaire modifiée',
                    ['article_id' => $article->id, 'form_id' => $form->id, 'response_id' => $updated->id],
                    $updated->userAccountId
                );
            }
        }

        return $updated;
    }

    /**
     * @param FormField[] $fields
     * @param array<int, string|string[]> $answers
     * @param array<int, MemberOption> $memberOptions
     * @param ?int $excludeResponseId the response being edited, whose own
     *        consumption is returned to the capacity pool (see remainingCapacity())
     * @param array<int, string> $previousAnswers the stored answers of the response being edited
     * @return array<int, string>
     */
    private function validateAndNormalizeAnswers(
        array $fields,
        array $answers,
        array $memberOptions,
        ?int $excludeResponseId,
        array $previousAnswers = []
    ): array
    {
        $normalized = [];

        foreach ($fields as $field) {
            if ($field->isNonInput()) {
                continue;
            }

            $raw = $answers[$field->id] ?? null;

            if ($field->isMemberSourced()) {
                $normalized[$field->id] = $this->normalizeMemberAnswer(
                    $field,
                    $raw,
                    $memberOptions,
                    $previousAnswers[$field->id] ?? null
                );
                continue;
            }

            if ($field->fieldType === FormField::TYPE_CHECKBOX) {
                $values = is_array($raw) ? $raw : ($raw !== null && $raw !== '' ? [$raw] : []);
                if ($field->isRequired && $values === []) {
                    throw new NewsException('Le champ "' . $field->label . '" est obligatoire.');
                }
                $this->assertValidOptions($field, $values);
                $normalized[$field->id] = implode(', ', $values);
                continue;
            }

            $value = is_array($raw) ? implode(', ', $raw) : (string) ($raw ?? '');

            // A required capped field whose capacity is exhausted is
            // rendered with no input at all ("Complet" — partials/
            // _field.html.twig), so the browser submits nothing for it.
            // Enforcing "required" there would make the whole form
            // unsubmittable for everyone as soon as one quota runs out.
            if ($field->isRequired && trim($value) === '' && !$this->isCapacityExhausted($field, $excludeResponseId)) {
                throw new NewsException('Le champ "' . $field->label . '" est obligatoire.');
            }

            if (in_array($field->fieldType, [FormField::TYPE_DROPDOWN, FormField::TYPE_RADIO], true) && $value !== '') {
                $this->assertValidOptions($field, [$value]);
            }

            if ($field->fieldType === FormField::TYPE_NUMBER && $value !== '') {
                $value = $this->normalizeNumberAnswer($field, $value);
            }

            // Mirrors the client-side type="email"/pattern constraints
            // (partials/_field.html.twig) — those block a normal browser
            // submission, but the server is the only check that actually
            // matters (a JS-disabled or scripted client bypasses HTML5
            // validation entirely).
            if ($field->fieldType === FormField::TYPE_EMAIL && $value !== '' && !filter_var(
                $value,
                FILTER_VALIDATE_EMAIL
            )) {
                throw new NewsException('Le champ "' . $field->label . '" doit être une adresse email valide.');
            }

            if ($field->fieldType === FormField::TYPE_PHONE && $value !== '' && !preg_match(
                '/^\+?[0-9 .\-()]{6,20}$/',
                $value
            )) {
                throw new NewsException('Le champ "' . $field->label . '" doit être un numéro de téléphone valide.');
            }

            $normalized[$field->id] = $value;
        }

        return $normalized;
    }

    /**
     * A number answer as it is stored, or a refusal in French (issue
     * #1525). The browser's `min="0"` and `step="1"` are a convenience; a
     * request sent directly bypasses them, so the rules live here:
     *
     * - no number field takes a negative;
     * - a quantity field (FormField::isQuantity()) takes a whole number
     *   written in digits and nothing else — `0.5`, `1e2`, ` 3` and `+3`
     *   are refused rather than read as some number the respondent did not
     *   type — and stores it canonical (`007` is `7`);
     * - and at most its capacity when it has one — what REMAINS of it is
     *   checked under the row lock when the answer is written (submit(),
     *   update()) — or MAX_QUANTITY_PER_RESPONSE when it has none.
     */
    private function normalizeNumberAnswer(FormField $field, string $value): string
    {
        $label = (string) $field->label;

        if (is_numeric($value) && (float) $value < 0) {
            throw new NewsException('Le champ "' . $label . '" ne peut pas être négatif.');
        }

        if (!$field->isQuantity()) {
            if (!is_numeric($value)) {
                throw new NewsException('Le champ "' . $label . '" doit être un nombre.');
            }

            return $value;
        }

        if (preg_match('/^\d+$/', $value) !== 1) {
            throw new NewsException(
                'Le champ "' . $label . '" doit être un nombre entier, écrit en chiffres seulement (0, 1, 2…).'
            );
        }

        $max = $field->capacityMax ?? self::MAX_QUANTITY_PER_RESPONSE;
        $quantity = IntegerInput::bounded($value, 0, $max);
        if ($quantity === null) {
            throw new NewsException($field->capacityMax !== null
                ? 'Il n\'y a plus assez de places disponibles pour "' . $label . '".'
                : 'Le champ "' . $label . '" ne peut pas dépasser '
                    . self::MAX_QUANTITY_PER_RESPONSE . ' par réponse.');
        }

        return (string) $quantity;
    }

    private function isCapacityExhausted(FormField $field, ?int $excludeResponseId): bool
    {
        return $field->capacityMax !== null && $this->remainingCapacity($field, $excludeResponseId) <= 0;
    }

    /**
     * A manual option is checked against the options the editor wrote; a
     * `members` field never comes here (normalizeMemberAnswer()).
     *
     * @param array<mixed> $values
     */
    private function assertValidOptions(FormField $field, array $values): void
    {
        $validOptions = $field->manualOptions();

        foreach ($values as $value) {
            if (!in_array($value, $validOptions, true)) {
                throw new NewsException('Valeur invalide pour le champ "' . $field->label . '".');
            }
        }
    }

    /**
     * A `members` answer as it is stored (MemberAnswer): the Desk ids
     * chosen, each one checked against WHO this account may name this
     * scout year — never against a label, which two members can share.
     *
     * A code is accepted when it is:
     * - one of the account's members (`$memberOptions`);
     * - or a code an old record carried, before a merge, for one of them
     *   (MemberAnswerResolver) — stored under the member's current code;
     * - or, on an edit, a code this very answer already held: a member
     *   the account can no longer name is kept as chosen rather than
     *   erased by a save that did not touch the field. The edit form
     *   offers it as MemberAnswer::UNAVAILABLE_LABEL.
     * Anything else — a forged code, a member of another family, another
     * year's record — refuses the whole submission.
     *
     * @param array<int, MemberOption> $memberOptions
     */
    private function normalizeMemberAnswer(
        FormField $field,
        mixed $raw,
        array $memberOptions,
        ?string $previousAnswer
    ): string {
        $invalid = 'Valeur invalide pour le champ "' . $field->label . '".';

        if ($field->fieldType === FormField::TYPE_CHECKBOX) {
            $values = is_array($raw) ? array_values($raw) : ($raw !== null && $raw !== '' ? [$raw] : []);
        } else {
            if (is_array($raw)) {
                throw new NewsException($invalid);
            }
            $values = $raw !== null && $raw !== '' ? [$raw] : [];
        }
        $chosen = [];
        foreach ($values as $value) {
            if (!is_string($value) || $value === '') {
                throw new NewsException($invalid);
            }
            $chosen[$value] = $value;
        }
        $values = array_values($chosen);

        if ($field->isRequired && $values === []) {
            throw new NewsException('Le champ "' . $field->label . '" est obligatoire.');
        }

        $offered = array_map(static fn(MemberOption $option): string => $option->deskId, $memberOptions);
        $previous = MemberAnswer::decode($previousAnswer);

        $unknown = array_values(array_filter($values, static fn(string $id): bool => !in_array($id, $offered, true)));
        $current = $unknown !== [] && $this->memberAnswers !== null ? $this->memberAnswers->resolve($unknown) : [];

        $deskIds = [];
        foreach ($values as $value) {
            $currentId = $current[$value]->deskId ?? null;
            $deskIds[] = match (true) {
                in_array($value, $offered, true) => $value,
                $currentId !== null && in_array($currentId, $offered, true) => $currentId,
                in_array($value, $previous, true) => $value,
                default => throw new NewsException($invalid),
            };
        }

        return MemberAnswer::encode($deskIds);
    }

    /**
     * @param FormField[] $fields
     */
    private function sendConfirmationEmail(
        Article $article,
        NewsForm $form,
        array $fields,
        FormResponse $response,
        float $total
    ): void
    {
        // Readable: a « Membres » answer is Desk ids, and the e-mail names
        // the members instead (readableAnswers()).
        $answers = $this->readableAnswersOf($fields, $response->id);
        $answerLines = [];
        foreach ($fields as $field) {
            if ($field->isNonInput() || !isset($answers[$field->id])) {
                continue;
            }
            $answerLines[] = ['label' => $field->label, 'value' => $answers[$field->id]];
        }

        $payment = $this->buildPaymentSummary($form, $response, $total);
        $ticket = $response->hasTicket() ? (string) $response->ticketReference : null;

        $editUrl = null;
        if ($response->userAccountId !== null && $form->isOpen()) {
            $editPath = '/news/' . $article->id . '/form/responses/' . $response->id . '/edit';
            $code = $this->shortUrlService->createShortUrl($editPath, $response->userAccountId);
            $editUrl = rtrim($this->baseUrl, '/') . '/s/' . $code;
        }

        // Never echo the submitter's own free-text answers back to an
        // unverified, form-supplied address on an ANONYMOUS submission (audit
        // M14): there both the recipient (contact_email) and the answer text
        // are fully attacker-controlled, which would turn this DKIM-signed
        // confirmation into a content relay — arbitrary text delivered to an
        // arbitrary third party, authenticated as the unit's domain. An
        // identified member's submission (traceable, rate-limited) still gets
        // the full confirmation; the anonymous one gets a neutral receipt
        // (title + payment summary, both unit-generated, no attacker text).
        $answersForEmail = $response->userAccountId !== null ? $answerLines : [];

        $context = [
            'site_name' => $this->siteName,
            'article_title' => $article->title,
            // Both recaps are DECLARED variables and therefore plain text,
            // one item per line: the template renders them with |nl2br,
            // and a unit that reworded this e-mail still gets them
            // substituted into its own wording. A list handed over as a
            // list would have vanished from the message on the day it was
            // customised — which is exactly the moment nobody re-reads the
            // confirmation a family receives.
            'answers_summary' => self::summaryLines($answersForEmail),
            'payment_summary' => self::paymentLines($payment),
            // Shipped-only: an image is the one thing the editor cannot
            // store, so the QR code never reaches a customised body. What
            // pays the bill — amount, IBAN, communication — is in
            // `payment_summary` and always survives.
            'payment_qr' => $payment['qr_data_uri'] ?? '',
            'edit_url' => $editUrl,
            // The ticket. `ticket_reference` is a DECLARED variable, so
            // it survives a reworded body — and it has to, because it is
            // the half of the ticket that still works when a mail client
            // blocks images, which most of them do by default. The QR
            // itself is shipped-only for the same reason `payment_qr` is.
            ...($this->ticketMail?->ticketVariables($form, $ticket)
                ?? ['ticket_reference' => '', 'ticket_qr' => '', 'event_summary' => '']),
        ];

        // Through the register (ARCHITECTURE.md §8.7bis): the declared
        // subject is « Confirmation — {{ article_title }} », so with
        // nothing customised this is the message it replaces, unchanged.
        $email = $this->emailTemplateRenderer->render('news.confirmation', $context);

        // The calendar file rides along only on a ticketed event with a
        // date — TicketMailService owns that pairing, so this message and
        // the standalone ticket cannot disagree about it.
        if ($ticket !== null && $this->ticketMail !== null) {
            $this->ticketMail->sendWithIcs(
                $article,
                $form,
                $response->contactEmail,
                $email->subject,
                $email->bodyHtml,
                $email->bodyText
            );

            return;
        }

        $this->mailService->send(
            to: $response->contactEmail,
            subject: $email->subject,
            bodyHtml: $email->bodyHtml,
            bodyText: $email->bodyText
        );
    }

    /**
     * The answers as one block of text, « Libellé : valeur » per line —
     * the shape a declared variable can carry into both halves of the
     * e-mail and into a customised body.
     *
     * @param array<int, array{label: string, value: string}> $lines
     */
    private static function summaryLines(array $lines): string
    {
        $rendered = [];
        foreach ($lines as $line) {
            $rendered[] = $line['label'] . ' : ' . $line['value'];
        }

        return implode("\n", $rendered);
    }

    /**
     * The same for the payment details. Empty when there is nothing to
     * pay, which is what the templates' `{% if payment_summary %}` reads.
     *
     * @param array{
     *     total: float,
     *     received: float,
     *     iban: string,
     *     beneficiary: string,
     *     communication: string,
     *     qr_data_uri: string
     * }|null $payment
     */
    private static function paymentLines(?array $payment): string
    {
        if ($payment === null) {
            return '';
        }

        return implode("\n", [
            'Montant à payer : ' . number_format($payment['total'], 2, ',', ' ') . ' €',
            // After an edit that raised the amount (issue #1525): what is
            // asked is the difference, and the parent sees why.
            ...($payment['received'] > 0.0
                ? ['Déjà reçu : ' . number_format($payment['received'], 2, ',', ' ') . ' €']
                : []),
            'Compte : ' . $payment['beneficiary'],
            'IBAN : ' . $payment['iban'],
            'Communication structurée : ' . $payment['communication'],
        ]);
    }
}
