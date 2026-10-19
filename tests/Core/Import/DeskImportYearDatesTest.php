<?php

declare(strict_types=1);

namespace Tests\Core\Import;

use Core\Import\DeskImportYearDates;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The two MM-JJ days of the import policy, checked as a pair: the sentence
 * the settings page answers with, and the fallback a stored incoherent
 * pair gets.
 */
class DeskImportYearDatesTest extends TestCase
{
    public function testTheDefaultsHoldTogether(): void
    {
        $this->assertNull(DeskImportYearDates::coherenceError(
            DeskImportYearDates::DEFAULT_NEXT_START,
            DeskImportYearDates::DEFAULT_ENCODING
        ));
        $dates = DeskImportYearDates::defaults();
        $this->assertSame('07-01', $dates->nextStart);
        $this->assertSame('08-15', $dates->encoding);
        $this->assertFalse($dates->fellBack);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function coherentPairs(): iterable
    {
        yield 'early summer' => ['06-01', '06-02'];
        yield 'last possible days' => ['08-30', '08-31'];
        yield 'a leap day' => ['02-29', '03-01'];
        yield 'in the winter' => ['01-01', '05-31'];
    }

    #[DataProvider('coherentPairs')]
    public function testACoherentPairIsKept(string $nextStart, string $encoding): void
    {
        $this->assertNull(DeskImportYearDates::coherenceError($nextStart, $encoding));

        $dates = DeskImportYearDates::fromValues($nextStart, $encoding);
        $this->assertSame($nextStart, $dates->nextStart);
        $this->assertSame($encoding, $dates->encoding);
        $this->assertFalse($dates->fellBack);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function refusedPairs(): iterable
    {
        yield 'opening after encoding' => ['08-20', '08-15', "L'ouverture de l'import pour l'année suivante (08-20) doit précéder la date d'encodage Desk (08-15)."];
        yield 'same day' => ['08-15', '08-15', "L'ouverture de l'import pour l'année suivante (08-15) doit précéder la date d'encodage Desk (08-15)."];
        yield 'encoding on 1 September' => ['07-01', '09-01', 'avant le 1er septembre'];
        yield 'opening in October' => ['10-01', '08-15', 'avant le 1er septembre'];
        yield 'not MM-JJ' => ['1er juillet', '08-15', 'au format MM-JJ'];
        yield 'day and month swapped' => ['15-08', '08-20', 'au format MM-JJ'];
        yield 'a day that never exists' => ['04-31', '08-15', 'au format MM-JJ'];
    }

    #[DataProvider('refusedPairs')]
    public function testAnIncoherentPairIsRefusedNamingBothValues(string $nextStart, string $encoding, string $expected): void
    {
        $error = DeskImportYearDates::coherenceError($nextStart, $encoding);

        $this->assertNotNull($error);
        $this->assertStringContainsString($expected, $error);
        $this->assertStringContainsString($nextStart, $error);
        $this->assertStringContainsString($encoding, $error);

        $dates = DeskImportYearDates::fromValues($nextStart, $encoding);
        $this->assertTrue($dates->fellBack);
        $this->assertSame('07-01', $dates->nextStart);
        $this->assertSame('08-15', $dates->encoding);
    }
}
