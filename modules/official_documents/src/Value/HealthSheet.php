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
     * The five swimming levels the form prints, plus the unanswered case.
     *
     * A closed vocabulary because it is a set of printed boxes, one of
     * which gets ticked; a free string would tick none of them. **These are
     * read off the template, not invented**: an earlier version of this
     * class offered « nage 25 mètres » and « nage 50 mètres », which the
     * federation's form does not print — so the PDF could have ticked
     * nothing at all for a parent who chose one.
     */
    public const SWIMMING_LEVELS = ['', 'very_good', 'good', 'fair', 'poor', 'not_at_all'];

    /**
     * The three answers to a printed OUI/NON question, the third being
     * « the family did not say ».
     *
     * Three questions on the form are a pair of squares and nothing else —
     * « peut-elle participer aux activités proposées ? », « est-elle en
     * ordre de vaccination contre le tétanos ? » and « est-elle autonome
     * dans la prise de ces médicaments ? ». They were free text when this
     * class was first written, which meant a parent could answer « oui
     * sauf la natation » and the PDF would cross neither square.
     *
     * **The empty string is never « non ».** A question left alone leaves
     * both squares blank, exactly as a paper form handed back half-filled
     * does. Crossing NON for a family who said nothing would be the site
     * making a medical statement on their behalf.
     */
    public const YES_NO = ['', 'yes', 'no'];

    /**
     * The twelve conditions the form lists as tick-boxes, **in the order it
     * prints them** — three rows of four, read left to right.
     *
     * Kept as a list of keys rather than twelve properties: the screen
     * draws them in a loop, the PDF ticks them in a loop, and a thirteenth
     * one the federation adds next year is one line here.
     *
     * The order matters beyond tidiness: `Pdf\HealthSheetLayout` maps each
     * key to the box printed beside its own words, so a key out of place
     * would tick « asthme » for a child who has « diabète ».
     */
    public const CONDITIONS = [
        // Row 1
        'diabetes',
        'car_sickness',
        'heart_condition',
        'mental_disability',
        // Row 2
        'asthma',
        'rheumatism',
        'skin_condition',
        'motor_disability',
        // Row 3
        'epilepsy',
        'bedwetting',
        'sleepwalking',
        'headaches',
    ];

    /**
     * The words the screen puts above each box, keyed by the answer they
     * name.
     *
     * Here rather than in the template because of what reads them: when a
     * value does not fit the federation's printed line, the screen says so
     * **by name**, and a name with no entry here would put a raw
     * `contact2_email` in front of a French-speaking parent. CLAUDE.md is
     * blunt about that one — « an English UI label is a bug, never a
     * detail » — and a map that lives beside the keys it names is a map a
     * new field cannot quietly slip past, because a test checks the two
     * lists against each other.
     *
     * `conditions` is absent on purpose: it is twelve tick-boxes rather
     * than a value with a line to overflow, and the template labels them
     * where it draws them.
     */
    public const LABELS = [
        'contact1_name' => 'Personne à contacter 1 — Nom et prénom',
        'contact1_relationship' => 'Personne à contacter 1 — Lien de parenté',
        'contact1_phone' => 'Personne à contacter 1 — Téléphone',
        'contact1_email' => 'Personne à contacter 1 — Adresse e-mail',
        'contact1_note' => 'Personne à contacter 1 — Remarque',
        'contact2_name' => 'Personne à contacter 2 — Nom et prénom',
        'contact2_relationship' => 'Personne à contacter 2 — Lien de parenté',
        'contact2_phone' => 'Personne à contacter 2 — Téléphone',
        'contact2_email' => 'Personne à contacter 2 — Adresse e-mail',
        'contact2_note' => 'Personne à contacter 2 — Remarque',
        'doctor_first_name' => 'Médecin traitant — Prénom',
        'doctor_last_name' => 'Médecin traitant — Nom',
        'doctor_phone' => 'Médecin traitant — Téléphone',
        'height' => 'Taille',
        'weight' => 'Poids',
        'participation' => 'Peut participer à toutes les activités proposées',
        'participation_details' => 'Précisions sur la participation aux activités',
        'swimming_level' => 'Niveau de natation',
        'conditions_details' => 'Fréquence, gravité, mesures à prendre',
        'illnesses_and_operations' => 'Maladies et opérations',
        'useful_information' => 'Autres informations utiles',
        'tetanus_vaccinated' => 'En ordre de vaccination contre le tétanos',
        'tetanus_last_booster' => 'Date du dernier rappel',
        'allergies' => 'Allergies',
        'allergy_consequences' => 'Conséquences et mesures à prendre',
        'diet' => 'Régime alimentaire',
        'treatment' => 'Traitement en cours',
        'treatment_autonomy' => 'Votre enfant prend-il ce traitement seul ?',
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

        // Same closed-vocabulary rule as the swimming level, and same
        // reason: these three are squares on the printed form, so a value
        // none of them matches reads as unanswered rather than being kept
        // and silently crossing nothing.
        $choice = static function (string $key) use ($text): string {
            $value = $text($key);

            return in_array($value, self::YES_NO, true) ? $value : '';
        };

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
            participation: $choice('participation'),
            participationDetails: $text('participation_details'),
            // An unknown level reads as unanswered rather than being kept:
            // the PDF ticks one of five printed boxes, and a value none of
            // them matches would tick none at all.
            swimmingLevel: in_array($level, self::SWIMMING_LEVELS, true) ? $level : '',
            conditions: $conditions,
            conditionsDetails: $text('conditions_details'),
            illnessesAndOperations: $text('illnesses_and_operations'),
            usefulInformation: $text('useful_information'),
            tetanusVaccinated: $choice('tetanus_vaccinated'),
            tetanusLastBooster: $text('tetanus_last_booster'),
            allergies: $text('allergies'),
            allergyConsequences: $text('allergy_consequences'),
            diet: $text('diet'),
            treatment: $text('treatment'),
            treatmentAutonomy: $choice('treatment_autonomy')
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
