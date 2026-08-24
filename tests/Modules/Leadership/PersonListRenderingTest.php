<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Leadership;

use Core\View\TwigFactory;
use Modules\Leadership\Value\PersonLine;
use PHPUnit\Framework\TestCase;

/**
 * The one list partial all three pages of this module draw
 * (`partials/_person_list.html.twig`), rendered directly.
 *
 * Rendered here rather than through a controller because what is under
 * test is the row itself, and reaching a row with a critical day count
 * through a controller means seeding a fixture whose only purpose is to
 * make the template take one branch.
 */
final class PersonListRenderingTest extends TestCase
{
    /** @param list<PersonLine> $lines */
    private function render(array $lines): string
    {
        $twig = TwigFactory::create(
            dirname(__DIR__, 3) . '/core/View/templates',
            true,
            ['leadership' => dirname(__DIR__, 3) . '/modules/leadership/views']
        );

        return (string) preg_replace('/\s+/', ' ', $twig->render(
            '@leadership/partials/_person_list.html.twig',
            ['lines' => $lines, 'empty_message' => 'Personne.']
        ));
    }

    private function line(int $days, string $direction, string $severity = 'normal'): PersonLine
    {
        return new PersonLine(
            memberYearId: 7,
            totem: null,
            fullName: 'Camille Renard',
            days: $days,
            daysDirection: $direction,
            severity: $severity
        );
    }

    /**
     * « 12 j » beside a name says nothing about which way it runs: on the
     * obligations page it is a birthday still to come, on the stewards
     * page a registration already running. Same chip, same colour,
     * opposite meanings.
     */
    public function testACountdownAndACountUpAreToldApart(): void
    {
        $this->assertStringContainsString('dans 12 j', $this->render([$this->line(12, PersonLine::DAYS_UNTIL)]));
        $this->assertStringContainsString('depuis 12 j', $this->render([$this->line(12, PersonLine::DAYS_SINCE)]));
    }

    /**
     * The colour IS the severity for a sighted reader and nothing at all
     * for anybody else.
     */
    public function testSeverityIsSaidAsWellAsColoured(): void
    {
        $critical = $this->render([$this->line(400, PersonLine::DAYS_SINCE, 'critical')]);
        $this->assertStringContainsString('text-danger', $critical);
        $this->assertStringContainsString('<span class="visually-hidden">Critique :</span>', $critical);

        $warning = $this->render([$this->line(100, PersonLine::DAYS_SINCE, 'warning')]);
        $this->assertStringContainsString('text-warning-emphasis', $warning);
        $this->assertStringContainsString('<span class="visually-hidden">Attention :</span>', $warning);

        $normal = $this->render([$this->line(10, PersonLine::DAYS_SINCE)]);
        $this->assertStringNotContainsString('visually-hidden">Critique', $normal);
        $this->assertStringNotContainsString('visually-hidden">Attention', $normal);
    }

    public function testARowWithNoSectionAndNoDetailDrawsNoEmptyLine(): void
    {
        // The div was rendered unconditionally; with both values null its
        // only visible effect was a gap under the name.
        $bare = $this->render([new PersonLine(memberYearId: 7, totem: null, fullName: 'Camille Renard')]);

        $this->assertStringNotContainsString('<div class="small text-body-secondary mt-1"> </div>', $bare);
        $this->assertStringNotContainsString('text-body-secondary mt-1"> </div>', $bare);

        $withSection = $this->render([new PersonLine(
            memberYearId: 7,
            totem: null,
            fullName: 'Camille Renard',
            sectionName: 'Louveteaux'
        )]);
        $this->assertStringContainsString('Louveteaux', $withSection);
    }
}
