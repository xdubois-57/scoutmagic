<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Registration\Service;

use Modules\Registration\Service\RegistrationRequestValidator;
use Modules\Registration\Service\RegistrationRequestViolation;
use PHPUnit\Framework\TestCase;

/**
 * The one list of rules a registration request answers to, shared by the
 * public form and the import of an old site's requests (chantier #1142,
 * lot H): the form's sentences must not move, and the import's reasons
 * must name the field.
 */
class RegistrationRequestValidatorTest extends TestCase
{
    /**
     * @param array<string, ?string> $overrides
     * @return array<string, ?string>
     */
    private static function fields(array $overrides = []): array
    {
        return array_replace([
            'parent_name' => 'Marie Dupont', 'child_last_name' => 'Dupont', 'child_first_name' => 'Léa',
            'gender' => 'F', 'birth_date' => '2019-05-12', 'street' => 'Rue des Prés', 'number' => '4',
            'postal_code' => '5000', 'city' => 'Namur', 'email' => 'marie@example.com', 'phone1' => '0470000000',
            'phone2' => null, 'remarks' => null, 'previous_unit_answer' => 'no', 'previous_unit_name' => null,
        ], $overrides);
    }

    public function testACompleteRequestBreaksNoRule(): void
    {
        $this->assertSame([], (new RegistrationRequestValidator())->violations(self::fields(), true, true));
    }

    public function testTheFormKeepsItsSentencesInItsOrder(): void
    {
        $violations = (new RegistrationRequestValidator())->violations(self::fields([
            'parent_name' => '', 'city' => '', 'gender' => 'M', 'birth_date' => '12/05/2019',
            'email' => 'pas-une-adresse', 'previous_unit_answer' => null, 'remarks' => str_repeat('a', 2001),
        ]), false, true);

        $this->assertSame([
            'Merci de compléter tous les champs obligatoires.',
            'Genre invalide.',
            'Date de naissance invalide.',
            'Adresse email invalide.',
            'Merci d\'accepter la politique de confidentialité.',
            'Merci d\'indiquer si cette personne fait ou a fait partie d\'une autre unité Les Scouts.',
            'Un des champs dépasse la longueur autorisée.',
        ], RegistrationRequestValidator::formMessages($violations));
    }

    public function testAYesWithoutAUnitIsRefusedOnEveryPath(): void
    {
        $fields = self::fields(['previous_unit_answer' => 'yes', 'previous_unit_name' => null]);

        foreach ([[true, true], [null, false]] as [$privacy, $asked]) {
            $violations = (new RegistrationRequestValidator())->violations($fields, $privacy, $asked);
            $this->assertCount(1, $violations);
            $this->assertSame('previous_unit_name', $violations[0]->field);
            $this->assertSame(RegistrationRequestViolation::MISSING, $violations[0]->kind);
        }
    }

    /**
     * An old site may never have asked about a previous unit, and its
     * privacy policy is not this site's to check: the import passes
     * neither, and neither is required of it.
     */
    public function testTheImportIsNotAskedTheFormsOwnQuestions(): void
    {
        $violations = (new RegistrationRequestValidator())->violations(
            self::fields(['previous_unit_answer' => null]),
            null,
            false
        );

        $this->assertSame([], $violations);
    }

    public function testAnUnknownAnswerAboutAPreviousUnitIsInvalidEvenWhereTheQuestionIsOptional(): void
    {
        $violations = (new RegistrationRequestValidator())->violations(
            self::fields(['previous_unit_answer' => 'peut-être']),
            null,
            false
        );

        $this->assertSame(
            ['Valeur non reconnue : ancienne unité'],
            array_map([RegistrationRequestValidator::class, 'importReason'], $violations)
        );
    }

    public function testTheImportReasonNamesTheFieldNeverItsValue(): void
    {
        $violations = (new RegistrationRequestValidator())->violations(self::fields([
            'phone1' => '', 'birth_date' => '', 'gender' => 'Z', 'parent_name' => str_repeat('Dupont', 30),
        ]), null, false);

        $this->assertSame([
            'Champ obligatoire manquant : téléphone',
            'Valeur non reconnue : genre',
            'Champ obligatoire manquant : date de naissance',
            'Champ trop long : nom du responsable',
        ], array_map([RegistrationRequestValidator::class, 'importReason'], $violations));
    }
}
