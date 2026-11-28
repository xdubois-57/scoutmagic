<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

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
    /** Multi-line rich-formatted static text — no input, no label, never an answer (module usability review). */
    public const TYPE_TEXT = 'text';

    /** @var string[] field types with no input at all — excluded from validation, answers, and exports */
    public const NON_INPUT_TYPES = [self::TYPE_CONFIRMATION, self::TYPE_TEXT];

    /** @var string[] */
    public const TYPES = [
        self::TYPE_SHORT_TEXT, self::TYPE_LONG_TEXT, self::TYPE_NUMBER, self::TYPE_DATE, self::TYPE_PHONE,
        self::TYPE_EMAIL, self::TYPE_DROPDOWN, self::TYPE_RADIO, self::TYPE_CHECKBOX, self::TYPE_SWITCH,
        self::TYPE_CONFIRMATION, self::TYPE_TEXT,
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
        public readonly ?string $confirmationText,
        /**
         * How much of $capacityMax is taken, kept up to date by the
         * writes (schema.sql). Read straight off the field, so showing a
         * form costs no query and no decryption at all for it.
         */
        public readonly float $capacityUsed = 0.0,
        /**
         * The highest capacity this field has ever had (schema.sql): the
         * per-answer ceiling of storedQuantity() never falls below it.
         */
        public readonly ?int $capacityPeak = null
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

    /**
     * A dropdown, radio or checkbox field whose options are the
     * respondent's members: its answer stores Desk ids, never names
     * (Service\MemberAnswer).
     */
    public function isMemberSourced(): bool
    {
        return $this->optionsSource === self::OPTIONS_SOURCE_MEMBERS
            && in_array($this->fieldType, self::OPTION_BASED_TYPES, true);
    }

    /**
     * The most one answer may ask of a quantity field that has no capacity
     * of its own (issue #1525). Far above any real order of a unit's event,
     * far below what a typed `1e5` once put on the books: 1 800 000 €
     * « à recevoir » for an 18 € meal.
     */
    public const MAX_QUANTITY_PER_ANSWER = 999;

    public function isPriced(): bool
    {
        return $this->fieldType === self::TYPE_NUMBER && $this->pricePerUnit !== null;
    }

    /**
     * A number that counts something — places, meals, tickets: it carries
     * a price or a capacity. Its answer is a whole number of units, never
     * a fraction and never a negative (issue #1525), because it is
     * multiplied by a price and added to a running capacity total.
     */
    public function isQuantity(): bool
    {
        return $this->fieldType === self::TYPE_NUMBER
            && ($this->pricePerUnit !== null || $this->capacityMax !== null);
    }

    /**
     * The units a STORED answer of this quantity field counts, or null
     * when the answer is one the server would never have taken (issue
     * #1525): not written in digits alone, or above the per-answer ceiling
     * — MAX_QUANTITY_PER_ANSWER, raised to the highest capacity the field
     * has ever had when that is larger. What the door shows as
     * « invalide », what the reprise flags, and what every seat or
     * capacity sum leaves out are all this one rule. An empty answer
     * counts zero.
     *
     * **Never the current capacity, nor what remains of it**: staff may
     * lower a cap after people booked, and an answer valid when it was
     * written stays valid — counting it as nothing would free its places
     * for resale and flag a family who did nothing wrong (#1546 review).
     * No answer above MAX_QUANTITY_PER_ANSWER was ever taken but under a
     * cap at least that high, so the peak bounds every valid one, and the
     * rule depends on the field alone — never on a running total that
     * cannot say which answers it counted. What remains of a capacity is
     * checked when an answer is written (ResponseService), under the row
     * lock, and nowhere else.
     */
    public function storedQuantity(?string $stored): ?int
    {
        if ($stored === null || $stored === '') {
            return 0;
        }
        if (preg_match('/^\d+$/', $stored) !== 1) {
            return null;
        }

        return \Core\Service\IntegerInput::bounded(
            $stored,
            0,
            max(self::MAX_QUANTITY_PER_ANSWER, $this->capacityMax ?? 0, $this->capacityPeak ?? 0)
        );
    }

    /** The same field with $text in place of its text. */
    public function withConfirmationText(?string $text): self
    {
        return new self(
            $this->id,
            $this->formId,
            $this->sortOrder,
            $this->fieldType,
            $this->label,
            $this->isRequired,
            $this->optionsSource,
            $this->optionsManual,
            $this->capacityMax,
            $this->pricePerUnit,
            $text,
            $this->capacityUsed,
            $this->capacityPeak
        );
    }

    public function isNonInput(): bool
    {
        return in_array($this->fieldType, self::NON_INPUT_TYPES, true);
    }
}
