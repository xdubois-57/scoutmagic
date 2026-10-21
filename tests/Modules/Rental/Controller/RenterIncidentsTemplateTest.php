<?php

declare(strict_types=1);

namespace Tests\Modules\Rental\Controller;

use Modules\Rental\Stay\RenterIncidents;
use PHPUnit\Framework\TestCase;
use Tests\TestTwig;

/**
 * The sentence `views/public/_incidents.html.twig` shows while a damage is
 * being assessed: it says the deposit stays held only when the unit is
 * actually holding one (#980, §2.7) — on a booking without a deposit, or
 * whose deposit was never paid, that would be false.
 */
final class RenterIncidentsTemplateTest extends TestCase
{
    private function render(bool $deposit): string
    {
        $twig = TestTwig::create(['rental']);

        return $twig->render('@rental/public/_incidents.html.twig', [
            'renter_incidents' => new RenterIncidents([], true),
            'renter_incidents_deposit' => $deposit,
        ]);
    }

    public function testWithADepositTheSentenceSaysItStaysHeld(): void
    {
        $html = $this->render(true);

        $this->assertStringContainsString(
            'Un dégât est en cours d\'évaluation ; la caution reste retenue jusqu\'à son règlement.',
            $html
        );
    }

    public function testWithoutADepositTheSentenceNeverMentionsOne(): void
    {
        $html = $this->render(false);

        $this->assertStringContainsString('Un dégât constaté pendant votre séjour est en cours d\'évaluation.', $html);
        $this->assertStringNotContainsString('caution', $html);
    }
}
