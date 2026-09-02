<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\News\Service;

use Core\Security\HtmlSanitizer;
use Core\Service\DateInput;
use Core\Security\Role;
use Modules\News\Repository\Article;
use Modules\News\Repository\FormField;
use Modules\News\Repository\FormFieldRepository;
use Modules\News\Repository\FormRepository;
use Modules\News\Repository\FormResponse;
use Modules\News\Repository\FormResponseRepository;
use Modules\News\Repository\NewsForm;

/**
 * Builds/saves the form definition (settings + full field list) as a
 * single operation, alongside the article (module spec §11.5: "Enregistrer
 * ... saves article + form definition in one POST"). Existing fields are
 * updated in place (matched by id) rather than deleted and re-inserted,
 * so a field that already has responses never loses them just because an
 * unrelated field elsewhere in the form was edited the same save.
 */
class FormService
{
    private HtmlSanitizer $htmlSanitizer;

    public function __construct(
        private FormRepository $formRepository,
        private FormFieldRepository $fieldRepository,
        private ArticleService $articleService,
        private FormResponseRepository $responseRepository
    ) {
        $this->htmlSanitizer = new HtmlSanitizer();
    }

    public function findByArticleId(int $articleId): ?NewsForm
    {
        return $this->formRepository->findByArticleId($articleId);
    }

    /**
     * @return FormField[]
     */
    public function getFields(int $formId): array
    {
        return $this->fieldRepository->findByFormId($formId);
    }

    /**
     * The « Paiements attendus » deep link for a form's tab, or null when
     * there is nothing there to open.
     *
     * Three conditions, and all three are about not offering a tab that
     * would disappoint. The finance module may be disabled entirely. The
     * page it opens is `role_min: intendant`, so a viewer below that
     * would follow the tab into a 403 — a hidden control is a courtesy,
     * never the boundary (SECURITY.md §3), and the boundary is that
     * route's own floor plus the per-account partitioning
     * ReceivablesOverviewService applies on top of it. And a form with no
     * finance account never raised a receivable, so the page would open
     * on an accordion that does not contain it.
     *
     * `id` carries the FORM's id on purpose: Service\ResponseService
     * calls createReceivable('news', $form->id, …), so `source_reference_id`
     * — what receivables.html.twig compares `focus_id` against — is a
     * form id, and this is the value that expands the right section.
     */
    public function receivablesLinkFor(?NewsForm $form, bool $financeAvailable, Role $viewerRole): ?string
    {
        if ($form === null || !$financeAvailable || $form->financeAccountId === null) {
            return null;
        }
        if (!$viewerRole->hasAccess(Role::INTENDANT)) {
            return null;
        }

        return '/finance/receivables?source=news&id=' . $form->id;
    }

    /**
     * @param array{access: string, response_limit: string, opens_at: ?string, closes_at: ?string, is_force_closed: bool, response_role_min: string, daily_digest_enabled: bool, finance_account_id: ?int, issues_ticket?: bool, event_date?: ?string, event_location?: ?string} $settings
     * @param array<int, array{id: ?int, field_type: string, label: ?string, is_required: bool, options_source: ?string, options_manual: ?string, capacity_max: ?int, price_per_unit: ?float, confirmation_text: ?string}> $fields
     */
    public function save(int $articleId, array $settings, array $fields): NewsForm
    {
        $access = $settings['access'] === NewsForm::ACCESS_PUBLIC ? NewsForm::ACCESS_PUBLIC : NewsForm::ACCESS_IDENTIFIED;
        // Forced to unlimited when access is public (module spec: no
        // account/member to enforce a per-person limit against).
        $responseLimit = $access === NewsForm::ACCESS_PUBLIC ? NewsForm::RESPONSE_LIMIT_UNLIMITED : $this->normalizeResponseLimit($settings['response_limit']);
        $responseRoleMin = in_array($settings['response_role_min'], ['intendant', 'chief', 'admin'], true) ? $settings['response_role_min'] : 'chief';

        $issuesTicket = (bool) ($settings['issues_ticket'] ?? false);
        $eventDate = self::normalizeEventDate($settings['event_date'] ?? null);
        $eventLocation = self::normalizeEventLocation($settings['event_location'] ?? null);

        $existing = $this->formRepository->findByArticleId($articleId);

        if ($existing === null) {
            $formId = $this->formRepository->create(
                $articleId, $access, $responseLimit, $settings['opens_at'], $settings['closes_at'],
                $settings['is_force_closed'], $responseRoleMin, $settings['daily_digest_enabled'], $settings['finance_account_id'],
                $issuesTicket, $eventDate, $eventLocation
            );
        } else {
            $formId = $existing->id;
            $this->formRepository->update(
                $formId, $access, $responseLimit, $settings['opens_at'], $settings['closes_at'],
                $settings['is_force_closed'], $responseRoleMin, $settings['daily_digest_enabled'], $settings['finance_account_id'],
                $issuesTicket, $eventDate, $eventLocation
            );
        }

        $this->reconcileFields($formId, $fields);
        $this->articleService->markHasForm($articleId, true);

        return $this->formRepository->findById($formId);
    }

    /**
     * Whether this save is the moment the form STARTS delivering tickets
     * — false to true, and only that direction.
     *
     * The caller reads it before calling save() and acts on it after, to
     * backfill the references the responses already recorded do not have
     * and post their tickets. The opposite transition needs nothing: a
     * ticket already issued stays valid and stays scannable. We stop
     * delivering; we do not revoke what was promised.
     */
    public function willStartIssuingTickets(int $articleId, bool $issuesTicket): bool
    {
        if (!$issuesTicket) {
            return false;
        }

        $existing = $this->formRepository->findByArticleId($articleId);

        return $existing !== null && !$existing->issuesTicket;
    }

    public function hasResponses(int $formId): bool
    {
        return $this->responseRepository->countByFormId($formId) > 0;
    }

    public function findById(int $formId): ?NewsForm
    {
        return $this->formRepository->findById($formId);
    }

    public function findResponseById(int $responseId): ?FormResponse
    {
        return $this->responseRepository->findById($responseId);
    }

    /**
     * A browser's own `<input type="date">` always sends `Y-m-d`; anything
     * else reached this from somewhere that is not the form, and a date the
     * ICS cannot render is worse than no date at all.
     *
     * Through `Core\Service\DateInput` rather than PHP's own
     * format-parsing constructor: that idiom raises a ValueError, not
     * `false`, on a value carrying a NUL byte, and
     * `Tests\Security\DateParsingConvergenceTest` is what stops the
     * twenty-first copy of that bug.
     */
    private static function normalizeEventDate(?string $value): ?string
    {
        return DateInput::isoStringOrNull($value);
    }

    private static function normalizeEventLocation(?string $value): ?string
    {
        $value = $value !== null ? trim($value) : '';

        return $value === '' ? null : mb_substr($value, 0, 255);
    }

    /**
     * @param int[] $orderedFieldIds
     */
    public function reorderFields(int $formId, array $orderedFieldIds): void
    {
        $existingIds = array_map(fn(FormField $f) => $f->id, $this->fieldRepository->findByFormId($formId));
        $validIds = array_values(array_intersect($orderedFieldIds, $existingIds));

        if (count($validIds) !== count($existingIds)) {
            throw new NewsException('Liste de champs invalide.');
        }

        $this->fieldRepository->reorder($validIds);
    }

    /**
     * @param array<int, array{id: ?int, field_type: string, label: ?string, is_required: bool, options_source: ?string, options_manual: ?string, capacity_max: ?int, price_per_unit: ?float, confirmation_text: ?string}> $fields
     */
    private function reconcileFields(int $formId, array $fields): void
    {
        $existingFields = $this->fieldRepository->findByFormId($formId);
        $existingIds = array_map(fn(FormField $f) => $f->id, $existingFields);
        $incomingIds = [];

        foreach ($fields as $index => $field) {
            if (!in_array($field['field_type'], FormField::TYPES, true)) {
                throw new NewsException('Type de champ invalide.');
            }

            $isNonInput = in_array($field['field_type'], FormField::NON_INPUT_TYPES, true);
            $optionsSource = in_array($field['field_type'], FormField::OPTION_BASED_TYPES, true) ? $field['options_source'] : null;
            $optionsManual = $optionsSource === FormField::OPTIONS_SOURCE_MANUAL ? $field['options_manual'] : null;
            $capacityMax = $field['field_type'] === FormField::TYPE_NUMBER ? $field['capacity_max'] : null;
            $pricePerUnit = $field['field_type'] === FormField::TYPE_NUMBER ? $field['price_per_unit'] : null;
            $confirmationText = $isNonInput ? $field['confirmation_text'] : null;
            // The 'text' type's content is rich HTML (rendered with |raw)
            // — sanitized here, same pipeline as the article body
            // (Core\Security\HtmlSanitizer via Core\View\EditableContentService
            // elsewhere); 'confirmation' stays plain text, Twig-escaped.
            if ($field['field_type'] === FormField::TYPE_TEXT && $confirmationText !== null) {
                $confirmationText = $this->htmlSanitizer->sanitize($confirmationText);
            }
            $label = $isNonInput ? null : $field['label'];
            $isRequired = $isNonInput ? false : $field['is_required'];

            if ($field['id'] !== null && in_array($field['id'], $existingIds, true)) {
                $this->fieldRepository->update(
                    $field['id'], $index, $field['field_type'], $label, $isRequired,
                    $optionsSource, $optionsManual, $capacityMax, $pricePerUnit, $confirmationText
                );
                $incomingIds[] = $field['id'];
            } else {
                $incomingIds[] = $this->fieldRepository->create(
                    $formId, $index, $field['field_type'], $label, $isRequired,
                    $optionsSource, $optionsManual, $capacityMax, $pricePerUnit, $confirmationText
                );
            }
        }

        foreach (array_diff($existingIds, $incomingIds) as $removedId) {
            $this->fieldRepository->delete($removedId);
        }
    }

    private function normalizeResponseLimit(string $value): string
    {
        return in_array($value, [NewsForm::RESPONSE_LIMIT_UNLIMITED, NewsForm::RESPONSE_LIMIT_ONE_PER_ACCOUNT, NewsForm::RESPONSE_LIMIT_ONE_PER_MEMBER], true)
            ? $value
            : NewsForm::RESPONSE_LIMIT_UNLIMITED;
    }
}
