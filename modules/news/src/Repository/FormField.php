<?php

declare(strict_types=1);

namespace Modules\News\Repository;

final class FormField
{
    public const TYPE_SHORT_TEXT = 'short_text';
    public const TYPE_LONG_TEXT = 'long_text';
    public const TYPE_NUMBER = 'number';
    public const TYPE_DATE = 'date';
    public const TYPE_PHONE = 'phone';
    public const TYPE_EMAIL = 'email';
    public const TYPE_DROPDOWN = 'dropdown';
    public const TYPE_RADIO = 'radio';
    public const TYPE_CHECKBOX = 'checkbox';
    public const TYPE_SWITCH = 'switch';
    public const TYPE_CONFIRMATION = 'confirmation';

    /** @var string[] */
    public const TYPES = [
        self::TYPE_SHORT_TEXT, self::TYPE_LONG_TEXT, self::TYPE_NUMBER, self::TYPE_DATE, self::TYPE_PHONE,
        self::TYPE_EMAIL, self::TYPE_DROPDOWN, self::TYPE_RADIO, self::TYPE_CHECKBOX, self::TYPE_SWITCH, self::TYPE_CONFIRMATION,
    ];

    /** @var string[] field types that offer options_source (dropdown/radio/checkbox) */
    public const OPTION_BASED_TYPES = [self::TYPE_DROPDOWN, self::TYPE_RADIO, self::TYPE_CHECKBOX];

    public const OPTIONS_SOURCE_MANUAL = 'manual';
    public const OPTIONS_SOURCE_MEMBERS = 'members';

    public function __construct(
        public readonly int $id,
        public readonly int $formId,
        public readonly int $sortOrder,
        public readonly string $fieldType,
        public readonly ?string $label,
        public readonly bool $isRequired,
        public readonly ?string $optionsSource,
        public readonly ?string $optionsManual,
        public readonly ?int $capacityMax,
        public readonly ?float $pricePerUnit,
        public readonly ?string $confirmationText
    ) {
    }

    /**
     * @return string[]
     */
    public function manualOptions(): array
    {
        if ($this->optionsManual === null || trim($this->optionsManual) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode("\n", $this->optionsManual)), fn($o) => $o !== ''));
    }

    public function isPriced(): bool
    {
        return $this->fieldType === self::TYPE_NUMBER && $this->pricePerUnit !== null;
    }
}
