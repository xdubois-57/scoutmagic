<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\OfficialDocuments\Value;

/**
 * The federation's health sheet, as answers rather than as storage.
 *
 * **Every field is optional, and an empty sheet is a valid sheet.** A
 * family may well use the parental authorization and nothing else, and a
 * form that refused to save until it was complete would simply not be
 * saved. So there is no `required` anywhere in this class and no validation
 * that can fail: what a parent typed is what a parent meant.
 *
 * Flat on purpose. The storage is one JSON document (see
 * `Repository\HealthSheetRepository`), the screen is one long form, and the
 * PDF (IT-04) writes one value per printed line — three flat readers, so a
 * nested shape would be three places to walk a tree for nothing.
 *
 * **Identity is absent, and that is a rule rather than an oversight.** The
 * member's own name, birth date and address are on the form, but they come
 * from their membership record at rendering time; this module neither
 * stores nor lets anybody edit them here (specifications.md §44).
 */
final class HealthSheet
{
    /**
     * The four swimming levels the form prints, plus the unanswered case.
     *
     * A closed vocabulary because it is a set of printed boxes, one of
     * which gets ticked; a free string would tick none of them.
     */
    public const SWIMMING_LEVELS = ['', 'none', 'beginner', '25m', '50m', 'confirmed'];

    /**
     * The twelve conditions the form lists as tick-boxes, in its own order.
     *
     * Kept as a list of keys rather than twelve properties: the screen
     * draws them in a loop, the PDF ticks them in a loop, and a thirteenth
     * one the federation adds next year is one line here.
     */
    public const CONDITIONS = [
        'asthma',
        'bronchitis',
        'rheumatism',
        'ear_infections',
        'heart_condition',
        'diabetes',
        'epilepsy',
        'car_sickness',
        'sleepwalking',
        'bedwetting',
        'fainting',
        'other',
    ];

    /**
     * @param array<string, bool> $conditions keyed by CONDITIONS
     */
    private function __construct(
        // --- Two emergency contacts ---
        public readonly string $contact1Name,
        public readonly string $contact1Relationship,
        public readonly string $contact1Phone,
        public readonly string $contact1Email,
        public readonly string $contact1Note,
        public readonly string $contact2Name,
        public readonly string $contact2Relationship,
        public readonly string $contact2Phone,
        public readonly string $contact2Email,
        public readonly string $contact2Note,

        // --- Family doctor ---
        public readonly string $doctorFirstName,
        public readonly string $doctorLastName,
        public readonly string $doctorPhone,

        // --- Health ---
        public readonly string $height,
        public readonly string $weight,
        public readonly string $participation,
        public readonly string $participationDetails,
        public readonly string $swimmingLevel,
        public readonly array $conditions,
        public readonly string $conditionsDetails,
        public readonly string $illnessesAndOperations,
        public readonly string $usefulInformation,
        public readonly string $tetanusVaccinated,
        public readonly string $tetanusLastBooster,
        public readonly string $allergies,
        public readonly string $allergyConsequences,
        public readonly string $diet,
        public readonly string $treatment,
        public readonly string $treatmentAutonomy
    ) {
    }

    /**
     * An empty sheet — what a family sees the first time, and what they are
     * left with after « Tout effacer ».
     */
    public static function empty(): self
    {
        return self::fromArray([]);
    }

    /**
     * Read a sheet from whatever is in the stored document.
     *
     * Forgiving on purpose, in both directions: a key the stored document
     * does not carry reads as empty (a sheet saved before a field existed),
     * and a key it carries that this class no longer knows is dropped (a
     * field the federation removed). Neither is an error, and neither
     * should ever cost a family the rest of their sheet.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $text = static fn(string $key): string => trim((string) ($data[$key] ?? ''));

        $conditions = [];
        $stored = is_array($data['conditions'] ?? null) ? $data['conditions'] : [];
        foreach (self::CONDITIONS as $condition) {
            $conditions[$condition] = (bool) ($stored[$condition] ?? false);
        }

        $level = $text('swimming_level');

        return new self(
            contact1Name: $text('contact1_name'),
            contact1Relationship: $text('contact1_relationship'),
            contact1Phone: $text('contact1_phone'),
            contact1Email: $text('contact1_email'),
            contact1Note: $text('contact1_note'),
            contact2Name: $text('contact2_name'),
            contact2Relationship: $text('contact2_relationship'),
            contact2Phone: $text('contact2_phone'),
            contact2Email: $text('contact2_email'),
            contact2Note: $text('contact2_note'),
            doctorFirstName: $text('doctor_first_name'),
            doctorLastName: $text('doctor_last_name'),
            doctorPhone: $text('doctor_phone'),
            height: $text('height'),
            weight: $text('weight'),
            participation: $text('participation'),
            participationDetails: $text('participation_details'),
            // An unknown level reads as unanswered rather than being kept:
            // the PDF ticks one of five printed boxes, and a value none of
            // them matches would tick none at all.
            swimmingLevel: in_array($level, self::SWIMMING_LEVELS, true) ? $level : '',
            conditions: $conditions,
            conditionsDetails: $text('conditions_details'),
            illnessesAndOperations: $text('illnesses_and_operations'),
            usefulInformation: $text('useful_information'),
            tetanusVaccinated: $text('tetanus_vaccinated'),
            tetanusLastBooster: $text('tetanus_last_booster'),
            allergies: $text('allergies'),
            allergyConsequences: $text('allergy_consequences'),
            diet: $text('diet'),
            treatment: $text('treatment'),
            treatmentAutonomy: $text('treatment_autonomy')
        );
    }

    /**
     * What the form posted, read the same way as stored content.
     *
     * The screen's field names ARE the stored keys, apart from the
     * tick-boxes, which a browser posts as a `conditions[]` list of the
     * ones ticked rather than as booleans.
     *
     * @param array<string, mixed> $body
     */
    public static function fromBody(array $body): self
    {
        $ticked = is_array($body['conditions'] ?? null) ? $body['conditions'] : [];
        $conditions = [];
        foreach (self::CONDITIONS as $condition) {
            $conditions[$condition] = in_array($condition, $ticked, true);
        }

        return self::fromArray(['conditions' => $conditions] + $body);
    }

    /**
     * The sheet as the document that gets encrypted.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'contact1_name' => $this->contact1Name,
            'contact1_relationship' => $this->contact1Relationship,
            'contact1_phone' => $this->contact1Phone,
            'contact1_email' => $this->contact1Email,
            'contact1_note' => $this->contact1Note,
            'contact2_name' => $this->contact2Name,
            'contact2_relationship' => $this->contact2Relationship,
            'contact2_phone' => $this->contact2Phone,
            'contact2_email' => $this->contact2Email,
            'contact2_note' => $this->contact2Note,
            'doctor_first_name' => $this->doctorFirstName,
            'doctor_last_name' => $this->doctorLastName,
            'doctor_phone' => $this->doctorPhone,
            'height' => $this->height,
            'weight' => $this->weight,
            'participation' => $this->participation,
            'participation_details' => $this->participationDetails,
            'swimming_level' => $this->swimmingLevel,
            'conditions' => $this->conditions,
            'conditions_details' => $this->conditionsDetails,
            'illnesses_and_operations' => $this->illnessesAndOperations,
            'useful_information' => $this->usefulInformation,
            'tetanus_vaccinated' => $this->tetanusVaccinated,
            'tetanus_last_booster' => $this->tetanusLastBooster,
            'allergies' => $this->allergies,
            'allergy_consequences' => $this->allergyConsequences,
            'diet' => $this->diet,
            'treatment' => $this->treatment,
            'treatment_autonomy' => $this->treatmentAutonomy,
        ];
    }

    /**
     * Whether the family has actually written anything.
     *
     * What the member's page needs to know to say « commencée » or not —
     * and the one question about this sheet that can be answered without
     * showing a single one of its answers.
     */
    public function isEmpty(): bool
    {
        foreach ($this->toArray() as $value) {
            if (is_array($value)) {
                if (in_array(true, $value, true)) {
                    return false;
                }
                continue;
            }
            if ($value !== '') {
                return false;
            }
        }

        return true;
    }
}
