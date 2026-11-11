<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Service;

use Core\Service\DateInput;
use Modules\Registration\Repository\RegistrationRequest;

/**
 * What a registration request must hold to be a request this module can
 * work with — the one list of rules, shared by the public form
 * (Controller\PublicRegistrationController) and by the import of an old
 * site's requests (Service\RequestImportService, chantier #1142 lot H).
 *
 * It answers with {@see RegistrationRequestViolation}s rather than
 * sentences, because the two callers word the same failure differently:
 * the form keeps its own generic sentences — « Merci de compléter tous les
 * champs obligatoires. », naming no field, exactly as it always has — and
 * the import names the field it was missing, since the chief reading the
 * report has no form in front of them.
 *
 * Two rules are the form's alone and are asked for by the caller:
 *
 * - the privacy policy accepted on THIS site — a request typed on an old
 *   site was filed under that site's policy, and nothing here can know
 *   or check it;
 * - the answer to « Cette personne fait-elle ou a-t-elle fait partie
 *   d'une autre unité Les Scouts ? » (issue #331). The form always asks
 *   it; an old site may never have, and an unasked question is a third
 *   state the module stores as NULL (schema.sql), never as « non ». When
 *   an answer is given, it must be one of the two, and « oui » must name
 *   the unit — on every path.
 *
 * Expects the fields already trimmed, optional ones null when empty — the
 * shape Controller\PublicRegistrationController::extractFields() builds.
 */
final class RegistrationRequestValidator
{
    /**
     * Server-side length ceilings, matching the public form's own
     * `maxlength` attributes. The columns are encrypted BLOBs, so nothing
     * truncates on the SQL side either: without these, a POST carrying
     * hundreds of kilobytes of "remarques" was accepted and encrypted
     * as-is. The browser attribute is a convenience, never the boundary.
     *
     * @var array<string, int>
     */
    public const MAX_LENGTHS = [
        'parent_name' => 150,
        'child_last_name' => 100,
        'child_first_name' => 100,
        'street' => 200,
        'number' => 20,
        'postal_code' => 20,
        'city' => 100,
        'email' => 254, // RFC 5321's maximum path length
        'phone1' => 30,
        'phone2' => 30,
        'remarks' => 2000,
        'previous_unit_name' => 150,
    ];

    /** The fields every request must carry, non-empty, whatever else it says. */
    public const REQUIRED_TEXT_FIELDS = [
        'parent_name', 'child_last_name', 'child_first_name', 'street', 'number', 'postal_code', 'city', 'phone1',
    ];

    /**
     * Every field, by its French name — what an import's report says when
     * one is wrong.
     *
     * @var array<string, string>
     */
    public const FIELD_LABELS = [
        'parent_name' => 'nom du responsable',
        'child_last_name' => 'nom de l\'enfant',
        'child_first_name' => 'prénom de l\'enfant',
        'gender' => 'genre',
        'birth_date' => 'date de naissance',
        'street' => 'rue',
        'number' => 'numéro',
        'postal_code' => 'code postal',
        'city' => 'localité',
        'email' => 'adresse électronique',
        'phone1' => 'téléphone',
        'phone2' => 'autre téléphone',
        'remarks' => 'remarques',
        'previous_unit_answer' => 'ancienne unité',
        'previous_unit_name' => 'nom de l\'ancienne unité',
        'privacy' => 'politique de confidentialité',
    ];

    /** Desk's own three values (issue #871): F, H for a boy, X. */
    public const GENDERS = ['F', 'H', 'X'];

    public const MESSAGE_REQUIRED = 'Merci de compléter tous les champs obligatoires.';
    public const MESSAGE_GENDER = 'Genre invalide.';
    public const MESSAGE_BIRTH_DATE = 'Date de naissance invalide.';
    public const MESSAGE_EMAIL = 'Adresse email invalide.';
    public const MESSAGE_PRIVACY = 'Merci d\'accepter la politique de confidentialité.';
    public const MESSAGE_PREVIOUS_UNIT_ANSWER =
        'Merci d\'indiquer si cette personne fait ou a fait partie d\'une autre unité Les Scouts.';
    public const MESSAGE_PREVIOUS_UNIT_NAME = 'Merci d\'indiquer de quelle unité il s\'agit.';
    public const MESSAGE_TOO_LONG = 'Un des champs dépasse la longueur autorisée.';

    /**
     * Every rule $fields breaks, in the order the public form has always
     * listed its errors: the required fields, the gender, the birth date,
     * the e-mail address, the privacy policy, the previous unit, the
     * lengths.
     *
     * @param array<string, ?string> $fields trimmed, optional ones null
     * @param bool|null $privacyAccepted whether the privacy policy of this
     *        site was accepted; null when this caller cannot ask (an import)
     * @param bool $previousUnitAsked whether the question about a previous
     *        unit was put — then an answer is required
     * @return list<RegistrationRequestViolation>
     */
    public function violations(array $fields, ?bool $privacyAccepted, bool $previousUnitAsked): array
    {
        $violations = [];

        foreach (self::REQUIRED_TEXT_FIELDS as $key) {
            if (($fields[$key] ?? '') === '') {
                $violations[] = self::missing($key, self::MESSAGE_REQUIRED);
            }
        }

        $gender = $fields['gender'] ?? '';
        if (!in_array($gender, self::GENDERS, true)) {
            $violations[] = $gender === ''
                ? self::missing('gender', self::MESSAGE_GENDER)
                : self::invalid('gender', self::MESSAGE_GENDER);
        }

        $birthDate = $fields['birth_date'] ?? '';
        if (!DateInput::isIso($birthDate)) {
            $violations[] = $birthDate === ''
                ? self::missing('birth_date', self::MESSAGE_BIRTH_DATE)
                : self::invalid('birth_date', self::MESSAGE_BIRTH_DATE);
        }

        $email = $fields['email'] ?? '';
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $violations[] = $email === ''
                ? self::missing('email', self::MESSAGE_EMAIL)
                : self::invalid('email', self::MESSAGE_EMAIL);
        }

        if ($privacyAccepted === false) {
            $violations[] = self::missing('privacy', self::MESSAGE_PRIVACY);
        }

        // Issue #331 — both halves of the question, and the second one only
        // when the first says « oui ». The unit the child comes from is what
        // changes the chief's encoding procedure in Desk, so a « oui » with
        // no unit named is a request nobody can act on.
        $answer = $fields['previous_unit_answer'] ?? null;
        if ($answer === null && $previousUnitAsked) {
            $violations[] = self::missing('previous_unit_answer', self::MESSAGE_PREVIOUS_UNIT_ANSWER);
        } elseif ($answer !== null
            && !in_array($answer, [RegistrationRequest::PREVIOUS_UNIT_NO, RegistrationRequest::PREVIOUS_UNIT_YES], true)
        ) {
            $violations[] = self::invalid('previous_unit_answer', self::MESSAGE_PREVIOUS_UNIT_ANSWER);
        } elseif ($answer === RegistrationRequest::PREVIOUS_UNIT_YES && ($fields['previous_unit_name'] ?? null) === null) {
            $violations[] = self::missing('previous_unit_name', self::MESSAGE_PREVIOUS_UNIT_NAME);
        }

        foreach (self::MAX_LENGTHS as $key => $max) {
            $value = $fields[$key] ?? null;
            if ($value !== null && mb_strlen($value) > $max) {
                $violations[] = new RegistrationRequestViolation(
                    $key,
                    RegistrationRequestViolation::TOO_LONG,
                    self::MESSAGE_TOO_LONG
                );
            }
        }

        return $violations;
    }

    /**
     * The public form's sentences for these violations: each once, in
     * order — one generic sentence for every missing field and one for
     * every field too long, naming none, as the form always has.
     *
     * @param list<RegistrationRequestViolation> $violations
     * @return list<string>
     */
    public static function formMessages(array $violations): array
    {
        return array_values(array_unique(array_map(
            static fn (RegistrationRequestViolation $violation): string => $violation->formMessage,
            $violations
        )));
    }

    /**
     * The sentence an import's report gives for one violation, naming the
     * field — never its value, which may be a name (D8).
     */
    public static function importReason(RegistrationRequestViolation $violation): string
    {
        $label = self::FIELD_LABELS[$violation->field] ?? $violation->field;

        return match ($violation->kind) {
            RegistrationRequestViolation::MISSING => 'Champ obligatoire manquant : ' . $label,
            RegistrationRequestViolation::TOO_LONG => 'Champ trop long : ' . $label,
            default => 'Valeur non reconnue : ' . $label,
        };
    }

    private static function missing(string $field, string $message): RegistrationRequestViolation
    {
        return new RegistrationRequestViolation($field, RegistrationRequestViolation::MISSING, $message);
    }

    private static function invalid(string $field, string $message): RegistrationRequestViolation
    {
        return new RegistrationRequestViolation($field, RegistrationRequestViolation::INVALID, $message);
    }
}
