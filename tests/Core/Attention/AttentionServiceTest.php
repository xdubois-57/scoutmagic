<?php

declare(strict_types=1);

namespace Tests\Core\Attention;

use Core\Attention\AttentionPoint;
use Core\Attention\AttentionPointProvider;
use Core\Attention\AttentionService;
use Core\Config\AppClock;
use PHPUnit\Framework\TestCase;

/**
 * The attention-points collection: a provider that fails is reported, not
 * hidden, and never takes the page down with it.
 */
class AttentionServiceTest extends TestCase
{
    public function testItStampsEachPointWithTheProviderThatProducedIt(): void
    {
        $service = new AttentionService([
            new FakeProvider('Cotisations', [new AttentionPoint('Un foyer', 'Parce que')]),
            new FakeProvider('Encadrement', [new AttentionPoint('Une section', 'Parce que')]),
        ]);

        $report = $service->collect(1);

        $this->assertSame(2, $report->count());
        $sources = array_map(static fn(AttentionPoint $p): string => $p->source, $report->points);
        $this->assertContains('Cotisations', $sources);
        $this->assertContains('Encadrement', $sources);
    }

    public function testAProviderCannotMislabelItsOwnPoints(): void
    {
        // The source is stamped by the service, never trusted from the
        // point: a module claiming to be « Cœur » would be lying to the
        // reader about where the information came from.
        $service = new AttentionService([
            new FakeProvider('Encadrement', [new AttentionPoint('Titre', 'Pourquoi', source: 'Cœur')]),
        ]);

        $this->assertSame('Encadrement', $service->collect(1)->points[0]->source);
    }

    public function testAFailingProviderIsNamedAndTheOthersStillRender(): void
    {
        $service = new AttentionService([
            new FakeProvider('Cotisations', [new AttentionPoint('Un foyer', 'Parce que')]),
            new ThrowingProvider('Camps'),
            new FakeProvider('Encadrement', [new AttentionPoint('Une section', 'Parce que')]),
        ]);

        $report = $service->collect(1);

        $this->assertSame(2, $report->count());
        $this->assertSame(['Camps'], $report->degradedSources);
    }

    public function testAProviderThatFailsEvenToNameItselfIsStillReported(): void
    {
        $service = new AttentionService([new TotallyBrokenProvider()]);

        $report = $service->collect(1);

        $this->assertTrue($report->isEmpty());
        $this->assertSame(['Un module'], $report->degradedSources);
    }

    public function testNothingFromAFailingProviderReachesThePage(): void
    {
        // The exception message could carry anything, personal data
        // included. Only the label crosses.
        $service = new AttentionService([new ThrowingProvider('Camps', 'Pierre Grosjean introuvable')]);

        $report = $service->collect(1);

        $this->assertSame(['Camps'], $report->degradedSources);
        $this->assertStringNotContainsString('Grosjean', implode(' ', $report->degradedSources));
    }

    public function testPointsWithADeadlineComeFirstSoonestFirst(): void
    {
        $today = AppClock::now();
        $service = new AttentionService([
            new FakeProvider('Cœur', [
                new AttentionPoint('Sans échéance', 'x'),
                new AttentionPoint('Dans 20 jours', 'x', dueDate: $today->modify('+20 days')),
                new AttentionPoint('Urgent sans échéance', 'x', severity: AttentionPoint::SEVERITY_URGENT),
                new AttentionPoint('Dans 5 jours', 'x', dueDate: $today->modify('+5 days')),
            ]),
        ]);

        $titles = array_map(static fn(AttentionPoint $p): string => $p->title, $service->collect(1)->points);

        $this->assertSame(
            ['Dans 5 jours', 'Dans 20 jours', 'Urgent sans échéance', 'Sans échéance'],
            $titles
        );
    }

    public function testAnEmptyUnitProducesAnEmptyReportRatherThanAFailure(): void
    {
        $report = (new AttentionService([new FakeProvider('Cœur', [])]))->collect(1);

        $this->assertTrue($report->isEmpty());
        $this->assertSame([], $report->degradedSources);
    }

    public function testAServiceWithNoProviderAtAllStillAnswers(): void
    {
        // Every module disabled, which is a legitimate installation.
        $report = (new AttentionService())->collect(1);

        $this->assertTrue($report->isEmpty());
    }

    public function testDaysUntilDueCountsWholeDaysInBothDirections(): void
    {
        $today = new \DateTimeImmutable('2026-09-25 14:32:00');

        $this->assertSame(5, (new AttentionPoint('x', 'y', dueDate: new \DateTimeImmutable('2026-09-30 08:00:00')))->daysUntilDue($today));
        $this->assertSame(-2, (new AttentionPoint('x', 'y', dueDate: new \DateTimeImmutable('2026-09-23 23:00:00')))->daysUntilDue($today));
        $this->assertNull((new AttentionPoint('x', 'y'))->daysUntilDue($today));
    }
}

final class FakeProvider implements AttentionPointProvider
{
    /** @param AttentionPoint[] $points */
    public function __construct(private string $label, private array $points)
    {
    }

    public function sourceLabel(): string
    {
        return $this->label;
    }

    public function collect(int $scoutYearId): array
    {
        return $this->points;
    }
}

final class ThrowingProvider implements AttentionPointProvider
{
    public function __construct(private string $label, private string $message = 'boom')
    {
    }

    public function sourceLabel(): string
    {
        return $this->label;
    }

    public function collect(int $scoutYearId): array
    {
        throw new \RuntimeException($this->message);
    }
}

final class TotallyBrokenProvider implements AttentionPointProvider
{
    public function sourceLabel(): string
    {
        throw new \RuntimeException('even the label is broken');
    }

    public function collect(int $scoutYearId): array
    {
        throw new \RuntimeException('boom');
    }
}
