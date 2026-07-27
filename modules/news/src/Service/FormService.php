<?php

declare(strict_types=1);

namespace Modules\News\Service;

use Core\Security\HtmlSanitizer;
use Modules\News\Repository\Article;
use Modules\News\Repository\FormField;
use Modules\News\Repository\FormFieldRepository;
use Modules\News\Repository\FormRepository;
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
        private ArticleService $articleService
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
     * @param array{access: string, response_limit: string, opens_at: ?string, closes_at: ?string, is_force_closed: bool, response_role_min: string, daily_digest_enabled: bool, finance_account_id: ?int} $settings
     * @param array<int, array{id: ?int, field_type: string, label: ?string, is_required: bool, options_source: ?string, options_manual: ?string, capacity_max: ?int, price_per_unit: ?float, confirmation_text: ?string}> $fields
     */
    public function save(int $articleId, array $settings, array $fields): NewsForm
    {
        $access = $settings['access'] === NewsForm::ACCESS_PUBLIC ? NewsForm::ACCESS_PUBLIC : NewsForm::ACCESS_IDENTIFIED;
        // Forced to unlimited when access is public (module spec: no
        // account/member to enforce a per-person limit against).
        $responseLimit = $access === NewsForm::ACCESS_PUBLIC ? NewsForm::RESPONSE_LIMIT_UNLIMITED : $this->normalizeResponseLimit($settings['response_limit']);
        $responseRoleMin = in_array($settings['response_role_min'], ['intendant', 'chief', 'admin'], true) ? $settings['response_role_min'] : 'chief';

        $existing = $this->formRepository->findByArticleId($articleId);

        if ($existing === null) {
            $formId = $this->formRepository->create(
                $articleId, $access, $responseLimit, $settings['opens_at'], $settings['closes_at'],
                $settings['is_force_closed'], $responseRoleMin, $settings['daily_digest_enabled'], $settings['finance_account_id']
            );
        } else {
            $formId = $existing->id;
            $this->formRepository->update(
                $formId, $access, $responseLimit, $settings['opens_at'], $settings['closes_at'],
                $settings['is_force_closed'], $responseRoleMin, $settings['daily_digest_enabled'], $settings['finance_account_id']
            );
        }

        $this->reconcileFields($formId, $fields);
        $this->articleService->markHasForm($articleId, true);

        return $this->formRepository->findById($formId);
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
