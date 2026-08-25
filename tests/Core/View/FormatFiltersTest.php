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

    /**
     * A display filter must never take a page down.
     *
     * `new DateTimeImmutable($v)` throws on a malformed string, so ONE
     * unreadable timestamp anywhere on a page used to 500 the whole
     * render rather than blank out the field it belongs to. Every date
     * filter now reads through Core\Service\DateInput::fromStorage()
     * and answers what it already answered for null: nothing.
     *
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('valuesThatAreNotDates')]
    public function testADateFilterRendersNothingRatherThanTakingThePageDown(string $value): void
    {
        foreach (['date_fr', 'datetime_fr', 'french_date', 'relative_date'] as $filter) {
            $this->assertSame(
                '',
                $this->render('value|' . $filter, ['value' => $value]),
                "|{$filter} on " . var_export($value, true)
            );
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function valuesThatAreNotDates(): array
    {
        return [
            'a word' => ['pas une date'],
            'a traversal payload' => ['../../../../etc/passwd'],
            'an impossible date' => ['2026-99-99'],
            // MySQL's way of writing "no value". PHP reads it as the 30th
            // of November, year -1, and a template would print that.
            "MySQL's zero date" => ['0000-00-00 00:00:00'],
            'a relative expression, which is not a stored moment' => ['tomorrow'],
        ];
    }

    /**
     * The other half: a real stored timestamp still renders, so the
     * guard above cannot be "fixed" by blanking everything.
     */
    public function testARealStoredTimestampStillRenders(): void
    {
        $this->assertSame('05/07/2026', $this->render('v|date_fr', ['v' => '2026-07-05 10:00:00']));
        $this->assertSame('05/07/2026 à 10:00', $this->render('v|datetime_fr', ['v' => '2026-07-05 10:00:00']));
        $this->assertSame('5 juillet 2026', $this->render('v|french_date', ['v' => '2026-07-05']));
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
