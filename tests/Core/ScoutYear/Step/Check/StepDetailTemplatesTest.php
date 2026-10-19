<?php

declare(strict_types=1);

namespace Tests\Core\ScoutYear\Step\Check;

use Core\ScoutYear\Step\StepNature;
use Core\ScoutYear\Step\StepState;
use Core\ScoutYear\Step\TransitionStep;
use PHPUnit\Framework\TestCase;
use Tests\TestTwig;

/**
 * The detail templates admin/scout_year/steps/{key}.html.twig render the
 * data their check hands them — through the site's real Twig environment,
 * so a filter or a key that does not exist fails here rather than in the
 * Détails dialog. The shapes below are the ones each Step\Check\* class
 * documents.
 */
class StepDetailTemplatesTest extends TestCase
{
    /**
     * @param array<string, mixed> $details
     */
    private function render(string $key, array $details, ?string $pending = null): string
    {
        $step = new TransitionStep(
            $key, 1, 'site', StepNature::HUMAN, StepState::TODO, 'Titre', 'Description', [], null, [], null, null,
            $pending, null, $details, CheckFixtures::TARGET_ID
        );

        return TestTwig::create()->render($step->detailTemplate(), ['step' => $step]);
    }

    public function testEveryCheckWaitingForTheImportSaysSoAndNothingElse(): void
    {
        $message = 'Le contrôle sera disponible après un import réussi de 2026-2027.';
        foreach (['desk_staff', 'desk_members', 'fees', 'ephemerides', 'badges', 'trombinoscope', 'staff_photos'] as $key) {
            $html = $this->render($key, $key === 'ephemerides' ? ['event_count' => 4] : [], $message);

            $this->assertStringContainsString($message, $html, $key);
            $this->assertStringNotContainsString('<table', $html, $key);
        }
    }

    public function testDepartures(): void
    {
        $html = $this->render('departures', [
            'campaign' => ['state' => 'open', 'opens_on' => '01/05/2026', 'closes_on' => '15/06/2026'],
            'leavers' => [['name' => 'Tom Renard', 'section' => 'Louveteaux', 'url' => '/admin/members/70']],
            'target_label' => '2026-2027',
        ]);

        $this->assertStringContainsString('Campagne en cours', $html);
        $this->assertStringContainsString('fermeture le 15/06/2026', $html);
        $this->assertStringContainsString('1 animé annoncé partant', $html);
        $this->assertStringContainsString('<a href="/admin/members/70">Tom Renard</a> (Louveteaux)', $html);
    }

    public function testPassage(): void
    {
        $this->assertStringContainsString('Aucun passage à effectuer.', $this->render(
            'passage',
            ['passage_count' => 0, 'unassigned_count' => 0, 'unassigned' => []]
        ));

        $html = $this->render('passage', [
            'passage_count' => 9,
            'unassigned_count' => 1,
            'unassigned' => [['name' => 'Léa Martin', 'section' => null, 'branch_year' => 'Louveteau 4e année']],
        ]);
        $this->assertStringContainsString('9 passages attendus', $html);
        $this->assertStringContainsString("8 avec une section d'arrivée", $html);
        $this->assertStringContainsString('Louveteau 4e année', $html);
        $this->assertStringContainsString('Non choisie', $html);
    }

    public function testConfirmRegistrations(): void
    {
        $html = $this->render('confirm_registrations', [
            'blocking_request_count' => 7, 'pending' => 3, 'accepted' => 4, 'accepted_without_section' => 2,
        ]);

        $this->assertStringContainsString('3 en attente', $html);
        $this->assertStringContainsString('(dont 2 sans section prévue)', $html);
        $this->assertStringContainsString("demandes d'inscription ne sont pas clôturées", $html);
    }

    public function testDeskStaff(): void
    {
        $sections = [
            ['label' => 'Baladins', 'chief_count' => 6, 'anime_count' => 30, 'responsable' => ['name' => 'Camille Leroy', 'url' => '/admin/members/51']],
            ['label' => 'Pionniers', 'chief_count' => 4, 'anime_count' => 20, 'responsable' => null],
        ];
        $importedAt = new \DateTimeImmutable('2026-07-18 21:14:00');

        $html = $this->render('desk_staff', ['sections' => $sections, 'responsable_checked' => true, 'imported_at' => $importedAt]);
        $this->assertStringContainsString('<a href="/admin/members/51">Camille Leroy</a>', $html);
        $this->assertStringContainsString('Aucun', $html);
        $this->assertStringContainsString("Données de l'import du 18/07/2026", $html);

        $html = $this->render('desk_staff', ['sections' => $sections, 'responsable_checked' => false, 'imported_at' => $importedAt]);
        $this->assertStringContainsString('Non vérifiable', $html);
        $this->assertStringContainsString('Responsable non vérifiable sans le module Trombinoscope', $html);
    }

    public function testDeskMembers(): void
    {
        $html = $this->render('desk_members', [
            'gaps' => [['kind' => 'request', 'label' => "Inscription acceptée, absente de l'import", 'name' => 'Zoé Henry', 'url' => '/config/inscriptions/demandes/77']],
            'imported_at' => new \DateTimeImmutable('2026-07-18'),
        ]);

        $this->assertStringContainsString('<a href="/config/inscriptions/demandes/77">Zoé Henry</a>', $html);
        $this->assertStringContainsString('Une aide, pas une preuve', $html);
    }

    public function testFees(): void
    {
        $html = $this->render('fees', [
            'households_checked' => 48, 'households_to_correct' => 3, 'members_without_address' => 4,
            'households_set_aside' => 2, 'imported_at' => new \DateTimeImmutable('2026-07-18'),
        ]);

        $this->assertStringContainsString('48 foyers contrôlés', $html);
        $this->assertStringContainsString('3 à corriger', $html);
        $this->assertStringContainsString('Factures et paiements ne sont pas vérifiés ici', $html);
    }

    public function testEphemerides(): void
    {
        $html = $this->render('ephemerides', [
            'event_count' => 17,
            'sections' => [['label' => 'Baladins', 'event_count' => 17], ['label' => 'Pionniers', 'event_count' => 0]],
        ]);

        $this->assertStringContainsString('Pionniers', $html);
        $this->assertStringContainsString('17 événements encodés', $html);
    }

    public function testBadges(): void
    {
        $html = $this->render('badges', [
            'sections' => [
                ['label' => 'Baladins', 'badges' => [['name' => 'Infirmier·e', 'holders' => [['name' => 'Camille Leroy', 'url' => '/admin/members/11']]]]],
                ['label' => 'Pionniers', 'badges' => []],
            ],
            'unworn' => ['Référent·e Pionniers'],
            'gaps' => [['badge' => 'Infirmier·e', 'without' => ['Pionniers']]],
        ]);

        $this->assertStringContainsString('<a href="/admin/members/11">Camille Leroy</a>', $html);
        $this->assertStringContainsString('Référent·e Pionniers', $html);
        $this->assertStringContainsString('Infirmier·e (absent en Pionniers)', $html);
    }

    public function testTrombinoscope(): void
    {
        $html = $this->render('trombinoscope', [
            'sections' => [['label' => 'Louveteaux', 'total' => 2, 'exact' => 0, 'earlier' => 1, 'none' => 1]],
            'people' => [
                ['name' => 'Max Collin', 'section' => 'Louveteaux', 'url' => '/admin/members/103', 'origin' => 'none', 'photo_year' => null],
                ['name' => 'Hugo Petit', 'section' => 'Louveteaux', 'url' => '/admin/members/102', 'origin' => 'earlier', 'photo_year' => '2024-2025'],
            ],
        ]);

        $this->assertStringContainsString('Personnes à corriger', $html);
        $this->assertStringContainsString('<a href="/admin/members/103">Max Collin</a> (Louveteaux) —', $html);
        $this->assertStringContainsString('aucune photo', $html);
        $this->assertStringContainsString('photo de 2024-2025', $html);
    }

    public function testStaffPhotos(): void
    {
        $html = $this->render('staff_photos', ['section_count' => 5, 'with_photo_count' => 3, 'missing' => ['Éclaireurs', 'Pionniers']]);

        $this->assertStringContainsString('ont une photo de groupe de', $html);
        $this->assertStringContainsString('Manquantes : Éclaireurs, Pionniers.', $html);
    }
}
