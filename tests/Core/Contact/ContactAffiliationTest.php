<?php

declare(strict_types=1);

namespace Tests\Core\Contact;

use Core\Contact\ContactAffiliation;
use PHPUnit\Framework\TestCase;

/**
 * One line of the contact card's history: « année · fonction · section ».
 */
class ContactAffiliationTest extends TestCase
{
    public function testOneFunctionWithItsSection(): void
    {
        $affiliation = new ContactAffiliation('2025-2026', [
            ['function' => 'Animateur', 'section' => 'Louveteaux'],
        ]);

        $this->assertSame('2025-2026 · Animateur · Louveteaux', $affiliation->format());
    }

    /**
     * A Trésorier or an Infirmier has no section, and the line has to stop
     * at the function rather than trail an orphan separator.
     */
    public function testFunctionWithoutSectionStopsAtTheFunction(): void
    {
        $affiliation = new ContactAffiliation('2024-2025', [
            ['function' => 'Trésorier', 'section' => null],
        ]);

        $this->assertSame('2024-2025 · Trésorier', $affiliation->format());
    }

    public function testAnEmptySectionIsTreatedAsNoSection(): void
    {
        $affiliation = new ContactAffiliation('2024-2025', [
            ['function' => 'Infirmier', 'section' => ''],
        ]);

        $this->assertSame('2024-2025 · Infirmier', $affiliation->format());
    }

    /**
     * Several functions in one year share one line — a reader scanning ten
     * years of history reads years, not rows.
     */
    public function testSeveralFunctionsOfOneYearShareTheLine(): void
    {
        $affiliation = new ContactAffiliation('2025-2026', [
            ['function' => 'Animateur', 'section' => 'Louveteaux'],
            ['function' => 'Trésorier', 'section' => null],
        ]);

        $this->assertSame('2025-2026 · Animateur · Louveteaux ; Trésorier', $affiliation->format());
    }

    public function testAYearWithoutAnyFunctionIsJustTheYear(): void
    {
        $this->assertSame('2019-2020', (new ContactAffiliation('2019-2020', []))->format());
    }
}
