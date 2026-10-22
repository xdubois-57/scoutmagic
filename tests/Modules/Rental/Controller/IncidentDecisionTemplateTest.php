<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Rental\Controller;

use Modules\Rental\Stay\Incident;
use Modules\Rental\Stay\IncidentDecision;
use PHPUnit\Framework\TestCase;
use Tests\TestTwig;

/**
 * « Trancher » as `views/management/_incidents.html.twig` renders it
 * (#980, chantier §2.1), through the real Twig environment.
 */
final class IncidentDecisionTemplateTest extends TestCase
{
    private function render(Incident ...$incidents): string
    {
        $twig = TestTwig::create(['rental'], ['csrf_field' => static fn(): string => '']);

        return $twig->render('@rental/management/_incidents.html.twig', [
            'incidents' => $incidents,
            'incident_decisions' => IncidentDecision::decidable(),
            'incidents_open' => true,
            'asset' => ['id' => 3],
            'booking' => ['id' => 9],
        ]);
    }

    private function incident(
        int $id,
        IncidentDecision $decision,
        ?string $awaiting = null,
        ?string $reference = null,
        ?string $waiveReason = null
    ): Incident {
        return new Incident(
            $id,
            9,
            'Vitre cassée',
            5000,
            $decision,
            null,
            $decision === IncidentDecision::PENDING ? null : new \DateTimeImmutable('2027-07-21 10:00:00'),
            null,
            null,
            new \DateTimeImmutable('2027-07-20 10:00:00'),
            $awaiting,
            $reference,
            $waiveReason
        );
    }

    /** @return array<string, array{hidden: bool}> one entry per field, keyed by the values it serves */
    private static function fields(string $html, int $incidentId): array
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
        $xpath = new \DOMXPath($dom);
        $fields = [];
        foreach ($xpath->query('//li[@id="incident-' . $incidentId . '"]//*[@data-incident-field]') ?: [] as $node) {
            \assert($node instanceof \DOMElement);
            $name = $node->getAttribute('data-incident-field') . ':' . count($fields);
            $fields[$name] = ['hidden' => $node->hasAttribute('hidden')];
        }

        return $fields;
    }

    public function testAWaivedIncidentShowsItsReasonAndHidesTheAssessmentsFields(): void
    {
        $html = $this->render($this->incident(1, IncidentDecision::WAIVE, null, null, 'Geste envers le groupe'));

        $this->assertSame([
            'pending:0' => ['hidden' => true],
            'pending:1' => ['hidden' => true],
            'pending charge:2' => ['hidden' => true],
            'waive:3' => ['hidden' => false],
        ], self::fields($html, 1));
        $this->assertStringContainsString('Motif : Geste envers le groupe', $html);
        $this->assertMatchesRegularExpression(
            '/value="waive"\s+aria-describedby="decision-1-waive-help"\s+checked/',
            $html
        );
    }

    public function testAChargedIncidentShowsOnlyItsAmount(): void
    {
        $html = $this->render($this->incident(2, IncidentDecision::CHARGE, 'Devis', 'D-1'));

        $this->assertSame([
            'pending:0' => ['hidden' => true],
            'pending:1' => ['hidden' => true],
            'pending charge:2' => ['hidden' => false],
            'waive:3' => ['hidden' => true],
        ], self::fields($html, 2));
        // Kept with the incident whatever its state, and said beside it.
        $this->assertStringContainsString('En attente de : Devis', $html);
        $this->assertStringContainsString('Référence : D-1', $html);
    }

    public function testOnlyAChargedIncidentShowsAnAmountBesideItsState(): void
    {
        // A pending or waived incident keeps its amount for a later
        // « Facturer », but showing it beside the state would read as owed.
        $this->assertStringContainsString(
            'data-incident-amount',
            $this->render($this->incident(4, IncidentDecision::CHARGE))
        );
        $this->assertStringNotContainsString(
            'data-incident-amount',
            $this->render($this->incident(5, IncidentDecision::PENDING))
        );
        $this->assertStringNotContainsString(
            'data-incident-amount',
            $this->render($this->incident(6, IncidentDecision::WAIVE))
        );
    }

    public function testEveryChoiceIsARadioWithItsConsequenceAndTheFormCarriesNoScript(): void
    {
        $html = $this->render($this->incident(4, IncidentDecision::PENDING));

        $this->assertSame(3, substr_count($html, 'type="radio" name="decision"'));
        foreach (IncidentDecision::decidable() as $state) {
            $this->assertStringContainsString(htmlspecialchars($state->label(), ENT_QUOTES), $html);
            $this->assertStringContainsString(htmlspecialchars($state->consequence(), ENT_QUOTES), $html);
        }
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('retenir sur la caution', $html);
        $this->assertStringContainsString('data-incident-decision', $html);
    }
}
