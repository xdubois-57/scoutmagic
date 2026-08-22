<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\View;

use Core\View\TwigFactory;
use PHPUnit\Framework\TestCase;
use Twig\Environment;

/**
 * The short French format filters (|date_fr, |datetime_fr, |money,
 * |money_cents) — one canonical rendering each, replacing the hand-written
 * |date('d/m/Y…') and number_format() chains that had drifted into five
 * date formats and three money spellings across the templates.
 *
 * Exercised through the real TwigFactory, never a re-declared copy: the
 * point is to pin what the shipped filter produces.
 */
final class FormatFiltersTest extends TestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        $this->twig = TwigFactory::create(dirname(__DIR__, 3) . '/core/View/templates');
    }

    private function render(string $expression, array $context = []): string
    {
        $template = $this->twig->createTemplate('{{ ' . $expression . ' }}');

        return $template->render($context);
    }

    public function testDateFrRendersDayMonthYear(): void
    {
        $this->assertSame('05/09/2026', $this->render("'2026-09-05'|date_fr"));
        $this->assertSame('05/09/2026', $this->render("'2026-09-05 14:30:00'|date_fr"));
    }

    public function testDatetimeFrRendersTheCanonicalFrenchForm(): void
    {
        $this->assertSame('05/09/2026 à 14:30', $this->render("'2026-09-05 14:30:00'|datetime_fr"));
    }

    public function testDateFiltersAcceptDateTimeObjectsAndRenderNothingForNull(): void
    {
        $this->assertSame('05/09/2026', $this->render('value|date_fr', ['value' => new \DateTimeImmutable('2026-09-05')]));
        $this->assertSame('', $this->render('value|date_fr', ['value' => null]));
        $this->assertSame('', $this->render('value|datetime_fr', ['value' => null]));
    }

    public function testMoneyRendersBelgianFrenchAmounts(): void
    {
        $this->assertSame('1 234,56 €', $this->render('1234.56|money'));
        $this->assertSame('0,00 €', $this->render('0|money'));
        $this->assertSame('-12,50 €', $this->render('(-12.5)|money'));
        // An absent amount is not 0,00 € — it renders as nothing.
        $this->assertSame('', $this->render('value|money', ['value' => null]));
    }

    public function testMoneyCentsDividesOnce(): void
    {
        $this->assertSame('1 234,56 €', $this->render('123456|money_cents'));
        $this->assertSame('0,05 €', $this->render('5|money_cents'));
        $this->assertSame('', $this->render('value|money_cents', ['value' => null]));
    }
}
