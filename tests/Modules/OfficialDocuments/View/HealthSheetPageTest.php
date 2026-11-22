<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\OfficialDocuments\View;

use Modules\OfficialDocuments\Service\HealthSheetFilling;
use Modules\OfficialDocuments\Value\HealthSheet;
use PHPUnit\Framework\TestCase;
use Tests\Modules\OfficialDocuments\Service\HealthSheetFillingTest;
use Tests\TestTwig;

/**
 * The screen, as a parent reads it (issue #1346): the federation's own words,
 * the three OUI / NON / non précisé questions with the boxes that depend on
 * them, the identity as it will be printed, and the paper's instructions.
 *
 * Rendered through the real template and the real factory — a stubbed Twig
 * would assert nothing about what a family sees.
 */
final class HealthSheetPageTest extends TestCase
{
    /**
     * @param array<string, mixed> $answers
     */
    private function page(array $answers = []): string
    {
        $member = HealthSheetFillingTest::member(addresses: [
            new \Core\Member\MemberAddress('home', null, null, null, null, '1000', 'Bruxelles', 'Belgique'),
            new \Core\Member\MemberAddress('home', 'Rue de la Station', '148', 'B3', null, '4000', 'Liège', 'Belgique'),
        ]);
        $sheet = HealthSheet::fromArray($answers);

        return TestTwig::create(['official_documents'])->render('@official_documents/health_sheet.html.twig', [
            'is_authenticated' => true,
            'config_mode' => false,
            'cookie_consent_given' => true,
            'member' => $member,
            'sheet' => $sheet->toArray(),
            'conditions' => HealthSheet::CONDITIONS,
            'identity' => HealthSheetFilling::identity($member, $sheet),
            'last_used_at' => null,
            'overflowing' => [],
            'document_error' => null,
            'breadcrumb_trail' => [],
        ]);
    }

    /**
     * The words of the 2026 form, exactly — the ones that matter for what a
     * parent is asked, in the order the rubrics come.
     */
    public function testTheQuestionsAreTheFormsOwnWords(): void
    {
        $html = html_entity_decode($this->page(), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        foreach ([
            'Fiche santé individuelle',
            'Identité de la personne',
            'Personnes à contacter en cas d\'urgence',
            'Coordonnées du médecin traitant',
            'Informations confidentielles concernant la santé de la personne',
            'La personne peut-elle participer aux activités proposées ? (Sport, excursions, jeux, baignade…) ?',
            'La personne sait-elle nager ?',
            'La personne présente-t-elle, de manière permanente ou régulière',
            'Si une case est cochée, précisez la fréquence, la gravité et les mesures à prévoir',
            'Indiquez les maladies importantes ou opérations subies (appendicite, rougeole…) avec l\'année',
            'Mentionnez toute information utile au bon déroulement des activités ou du camp (sommeil, lunettes, etc.)',
            'Vaccination contre le tétanos',
            'La personne est-elle en ordre ?',
            'Date du dernier rappel',
            'La personne est-elle allergique (aliments, médicaments, etc.) ?',
            'Si oui, lesquels ?',
            'Quelles sont les conséquences ?',
            'La personne suit-elle un régime alimentaire particulier ?',
            'Si oui, lequel ?',
            'La personne prend-elle un traitement ?',
            'Si oui, précisez : nom, dosage, fréquence, indication, mode d\'administration et durée.',
            'Est-elle autonome dans la prise de ces médicaments ?',
        ] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }
    }

    public function testTheContactsAndTheDoctorUseTheFormsFieldNamesInTheFormsOrder(): void
    {
        $html = html_entity_decode($this->page(), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $this->assertSame(2, substr_count($html, 'Nom et prénom :'));
        $this->assertSame(2, substr_count($html, 'Lien de parenté :'));
        $this->assertSame(2, substr_count($html, 'Remarque :'));
        // Two contacts and the doctor, plus the identity recap's own line.
        $this->assertGreaterThanOrEqual(3, substr_count($html, 'Tél. / GSM :'));
        $this->assertGreaterThanOrEqual(2, substr_count($html, 'Email :'));
        $this->assertStringNotContainsString('Adresse e-mail', $html);
        $this->assertStringNotContainsString('>Téléphone<', $html);
        $this->assertSame(1, preg_match('/doctor-last-name.*doctor-first-name/s', $html), 'Nom, then Prénom');
        $this->assertSame(1, preg_match('/Taille :.*Poids :/s', $html));
    }

    public function testTheOldWordingsAreGone(): void
    {
        $html = html_entity_decode($this->page(), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        foreach ([
            'Votre enfant souffre-t-il',
            'Votre enfant prend-il ce traitement seul',
            'Traitement en cours',
            'Conséquences et mesures à prendre',
            'Fréquence, gravité, mesures à prendre',
            'Peut participer à toutes les activités proposées',
        ] as $old) {
            $this->assertStringNotContainsString($old, $html, $old);
        }
    }

    /**
     * The three explicit answers reuse the existing « Non précisé / Oui /
     * Non » list, and the boxes that depend on them say so in markup the
     * script reads — with no script every box stays visible.
     */
    public function testTheThreeChoicesAreSelectsWithTheirDependentBoxes(): void
    {
        $html = $this->page(['allergic' => 'no', 'follows_diet' => 'yes', 'takes_treatment' => '']);

        foreach (['allergic', 'follows_diet', 'takes_treatment'] as $name) {
            $this->assertSame(1, preg_match('/<select[^>]*name="' . $name . '"/', $html), $name);
            $this->assertStringContainsString('data-reveal-when="' . $name . '=yes"', $html);
        }
        $this->assertMatchesRegularExpression('/<option value="no" selected[^>]*>Non<\/option>/', $html);
        $this->assertMatchesRegularExpression('/<option value="yes" selected[^>]*>Oui<\/option>/', $html);
        // The boxes are in the page whatever the answer — the server decides what is exported.
        foreach (['allergies', 'allergy_consequences', 'diet', 'treatment', 'treatment_autonomy'] as $name) {
            $this->assertStringContainsString('name="' . $name . '"', $html, $name);
        }
        // Nothing is hidden by the markup itself: hiding is the script's.
        $this->assertDoesNotMatchRegularExpression('/data-reveal-when="[^"]*"[^>]*\bhidden\b/', $html);
    }

    /**
     * The page used to reopen every choice on « Non précisé » — the select
     * partial ignores `value` and only reads each option's own `selected` — so
     * an answer saved earlier looked lost, and the next save erased it.
     */
    public function testASavedAnswerIsSelectedAgainWhenThePageReopens(): void
    {
        $html = $this->page([
            'participation' => 'no', 'swimming_level' => 'fair', 'tetanus_vaccinated' => 'yes',
            'allergic' => 'no', 'follows_diet' => 'yes', 'takes_treatment' => 'yes', 'treatment_autonomy' => 'no',
        ]);

        foreach ([
            'participation' => 'no', 'swimming-level' => 'fair', 'tetanus' => 'yes', 'allergic' => 'no',
            'follows-diet' => 'yes', 'takes-treatment' => 'yes', 'treatment-autonomy' => 'no',
        ] as $id => $value) {
            $this->assertMatchesRegularExpression(
                '/<select[^>]*id="' . $id . '"[^>]*>(?:(?!<\/select>).)*<option value="' . $value . '" selected/s',
                $html,
                $id
            );
        }
        // And exactly one option is selected per choice — never the saved one AND « Non précisé ».
        $this->assertSame(7, substr_count($html, ' selected'));
    }

    /** A question nobody answered reopens on « Non précisé », as before. */
    public function testAnUnansweredChoiceStaysOnNonPrecise(): void
    {
        $html = $this->page();

        $this->assertSame(7, substr_count($html, ' selected'));
        $this->assertSame(7, substr_count($html, '<option value="" selected'));
    }

    /** « Est-elle autonome… » is asked only when a treatment is declared. */
    public function testTheAutonomyDependsOnTheTreatmentChoice(): void
    {
        $html = $this->page();

        $wrapper = strpos($html, 'data-reveal-when="takes_treatment=yes"');
        $autonomy = strpos($html, 'name="treatment_autonomy"');
        $this->assertNotFalse($wrapper);
        $this->assertNotFalse($autonomy);
        $this->assertGreaterThan($wrapper, $autonomy, 'the autonomy sits inside the treatment block');
        $this->assertSame(0, substr_count(substr($html, $wrapper, $autonomy - $wrapper), '<h3'), 'same rubric');
    }

    /** The exceptions stay reachable: the restrictions note and the booster date. */
    public function testTheParticipationDetailAndTheBoosterDateDependOnNothing(): void
    {
        $html = $this->page(['participation' => 'no', 'tetanus_vaccinated' => 'no']);

        $this->assertStringNotContainsString('data-reveal-when="participation', $html);
        $this->assertStringNotContainsString('data-reveal-when="tetanus_vaccinated', $html);
        $this->assertStringContainsString('name="participation_details"', $html);
        $this->assertStringContainsString('name="tetanus_last_booster"', $html);
    }

    /**
     * The identity as it WILL be printed: ten lines read from the same
     * source as the PDF, the legal name rather than the totem, and the
     * first address that carries a street.
     */
    public function testTheIdentityIsTheOneThePdfPrints(): void
    {
        $html = $this->page();

        foreach ([
            'Nom :' => 'Dubois', 'Prénom :' => 'Loup', 'Date de naissance :' => '17/04/2013',
            'Rue :' => 'Rue de la Station', 'N° :' => '148', 'Bte :' => 'B3',
            'Code postal :' => '4000', 'Localité :' => 'Liège',
            'Tél. / GSM :' => '0470 12 34 56', 'Email :' => 'loup@example.be',
        ] as $label => $value) {
            $this->assertMatchesRegularExpression(
                '/<dt[^>]*>\s*' . preg_quote($label, '/') . '\s*<\/dt>\s*<dd[^>]*>\s*' . preg_quote($value, '/') . '\s*<\/dd>/',
                $html,
                $label
            );
        }
        $this->assertStringNotContainsString('Bruxelles', $html, 'the first address WITHOUT a street is not the one printed');
        $this->assertStringNotContainsString('Akéla', substr($html, (int) strpos($html, 'section-identity'), 1200), 'the totem is not in the identity recap');
    }

    public function testTheGeneralInstructionsOfTheFormAreShown(): void
    {
        $html = html_entity_decode($this->page(), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $this->assertStringContainsString(
            'À compléter par les parents, représentant légal ou membre majeur au début de chaque année scoute, '
                . 'avant le camp, la formation et le début des activités scoutes.',
            $html
        );
        $this->assertStringContainsString('Merci de fournir des informations complètes, exactes et à jour.', $html);
    }

    /**
     * The paper's explanations sit under « Télécharger la fiche », the place a
     * parent is looking when about to print — and create no consent box and
     * no electronic signature.
     */
    public function testThePaperInstructionsComeAfterTheDownloadButton(): void
    {
        $html = html_entity_decode($this->page(), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $download = strpos($html, 'Télécharger la fiche à imprimer');
        $this->assertNotFalse($download);
        $after = substr($html, $download);

        foreach ([
            'Insérez ici une vignette de mutuelle',
            'Date et signature du représentant·e légal·e',
            'Date et signature de la personne concernée (dès 13 ans)',
            'Tout médicament fourni doit être accompagné d\'une prescription médicale. Un modèle est disponible sur lesscouts.be.',
            'Les animateurs et animatrices disposent d\'une trousse de secours et peuvent administrer, si nécessaire, '
                . 'du paracétamol, un antiseptique ou une pommade apaisante (sur avis médical ou du pharmacien).',
            'Je marque mon accord pour que toute prise en charge et tout soin jugés nécessaires soient mis en œuvre',
            'J\'autorise les animateurs et les animatrices à utiliser la carte d\'identité ou la carte ISI+',
        ] as $text) {
            $this->assertStringContainsString($text, $after, $text);
        }
        $this->assertStringNotContainsString('name="consent', $html);
        $this->assertStringNotContainsString('signature_pad', $html);
    }

    public function testTheSiteOwnNotesOnAccessAndRetentionAreKept(): void
    {
        $html = $this->page();

        $this->assertStringContainsString('Le document imprimé n\'a aucune valeur tant qu\'il n\'est pas signé', html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $this->assertStringContainsString('Enregistrez d', $html);
        $this->assertStringContainsString('/fiche-sante/effacer', $html);
    }
}
