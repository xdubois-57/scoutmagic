<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\OfficialDocuments\Value;

use Modules\OfficialDocuments\Value\HealthSheet;
use PHPUnit\Framework\TestCase;

/**
 * The sheet as answers, and the two failure modes that would cost a family
 * their data without anybody noticing.
 *
 * The first is a round trip that loses a field: the sheet is stored as one
 * JSON document, so a key that `toArray()` writes and `fromArray()` does not
 * read comes back empty on the next visit — silently, with no error
 * anywhere. The second is the forgiving reader taken too far or not far
 * enough: a sheet saved before a field existed must still open, and a field
 * the federation removes must not take the rest of the sheet with it.
 */
final class HealthSheetTest extends TestCase
{
    /**
     * Every field the form carries, filled with a value that could only
     * come from its own key.
     *
     * @return array<string, mixed>
     */
    private static function filled(): array
    {
        $data = [];
        foreach (array_keys(HealthSheet::empty()->toArray()) as $key) {
            $data[$key] = 'valeur-' . $key;
        }

        $data['conditions'] = array_combine(
            HealthSheet::CONDITIONS,
            array_fill(0, count(HealthSheet::CONDITIONS), true)
        );
        // The closed vocabularies: anything else reads as unanswered, so
        // « valeur-swimming_level » would come back empty and make this
        // fixture lie about a round trip.
        $data['swimming_level'] = 'fair';
        $data['participation'] = 'yes';
        $data['tetanus_vaccinated'] = 'no';
        $data['treatment_autonomy'] = 'yes';

        return $data;
    }

    /**
     * The round trip, field by field. A key written but never read is the
     * defect this catches, and it is invisible at every other layer.
     */
    public function testEveryFieldSurvivesAStoreAndReload(): void
    {
        $original = self::filled();

        $reloaded = HealthSheet::fromArray($original)->toArray();

        $this->assertSame($original, $reloaded);
    }

    /**
     * And through real JSON, which is what the Repository actually writes —
     * including the accents and apostrophes a French answer is full of.
     */
    public function testItSurvivesTheJsonTheRepositoryStores(): void
    {
        // The overrides go on the LEFT: `+` keeps the left-hand keys, so
        // the other way round would silently keep the filler values and
        // this test would assert nothing about accents at all.
        $sheet = HealthSheet::fromArray([
            'allergies' => "Arachides, œufs, et l'iode",
            'useful_information' => 'Très mauvaise vue — lunettes à porter en permanence',
        ] + self::filled());

        $json = (string) json_encode($sheet->toArray(), JSON_UNESCAPED_UNICODE);
        $back = HealthSheet::fromArray((array) json_decode($json, true));

        $this->assertSame("Arachides, œufs, et l'iode", $back->allergies);
        $this->assertSame('Très mauvaise vue — lunettes à porter en permanence', $back->usefulInformation);
        $this->assertSame($sheet->toArray(), $back->toArray());
    }

    // --- an empty sheet is a valid sheet ---

    public function testAnEmptySheetIsValidAndSaysSo(): void
    {
        $this->assertTrue(HealthSheet::empty()->isEmpty());
        $this->assertTrue(HealthSheet::fromArray([])->isEmpty());
    }

    /**
     * One ticked box and nothing else is still a started sheet: the member
     * page says « commencée » on the strength of this, and a sheet whose
     * only content is a tick would otherwise read as untouched.
     */
    public function testATickedBoxAloneMakesASheetNonEmpty(): void
    {
        $sheet = HealthSheet::fromArray(['conditions' => ['asthma' => true]]);

        $this->assertFalse($sheet->isEmpty());
    }

    public function testOneTypedCharacterMakesASheetNonEmpty(): void
    {
        $this->assertFalse(HealthSheet::fromArray(['doctor_phone' => '0470 00 00 00'])->isEmpty());
    }

    // --- the forgiving reader, in both directions ---

    /**
     * A sheet saved before a field existed. It opens, and the new field is
     * simply empty — rather than the family losing everything they wrote.
     */
    public function testASheetSavedBeforeAFieldExistedStillOpens(): void
    {
        $sheet = HealthSheet::fromArray(['allergies' => 'Arachides']);

        $this->assertSame('Arachides', $sheet->allergies);
        $this->assertSame('', $sheet->diet);
        $this->assertSame('', $sheet->treatmentAutonomy);
    }

    /**
     * And a key this class no longer knows is dropped rather than throwing:
     * the federation removing a line from its form must not make every
     * stored sheet unreadable.
     */
    public function testAFieldTheFormNoLongerCarriesIsDroppedQuietly(): void
    {
        $sheet = HealthSheet::fromArray([
            'allergies' => 'Arachides',
            'champ_supprime_par_la_federation' => 'quelque chose',
        ]);

        $this->assertSame('Arachides', $sheet->allergies);
        $this->assertArrayNotHasKey('champ_supprime_par_la_federation', $sheet->toArray());
    }

    /**
     * The twelve boxes are always all present, ticked or not. A missing key
     * would make the screen draw eleven boxes and the PDF tick nothing on
     * the twelfth line.
     */
    public function testTheTwelveConditionsAreAlwaysAllThere(): void
    {
        $sheet = HealthSheet::fromArray(['conditions' => ['asthma' => true]]);

        $this->assertCount(12, $sheet->conditions);
        $this->assertSame(HealthSheet::CONDITIONS, array_keys($sheet->conditions));
        $this->assertTrue($sheet->conditions['asthma']);
        $this->assertFalse($sheet->conditions['diabetes']);
    }

    /**
     * The swimming level ticks one of five printed boxes. A value none of
     * them matches reads as unanswered — which leaves the form's own boxes
     * blank for a pen, rather than ticking the wrong one.
     */
    /**
     * The vocabulary is the form's, not one invented here. An earlier
     * version offered « nage 25 mètres » and « nage 50 mètres », which the
     * federation does not print — so a parent could choose a level the PDF
     * had no box to tick. This pins both lists against the template's own
     * wording, which `Pdf\HealthSheetLayout` maps box by box.
     */
    public function testTheVocabularyIsTheOneThePrintedFormUses(): void
    {
        $this->assertSame(
            ['', 'very_good', 'good', 'fair', 'poor', 'not_at_all'],
            HealthSheet::SWIMMING_LEVELS
        );
        $this->assertSame(
            [
                'diabetes', 'car_sickness', 'heart_condition', 'mental_disability',
                'asthma', 'rheumatism', 'skin_condition', 'motor_disability',
                'epilepsy', 'bedwetting', 'sleepwalking', 'headaches',
            ],
            HealthSheet::CONDITIONS,
            'the order is the one the form prints: three rows of four'
        );
    }

    public function testAnUnknownSwimmingLevelReadsAsUnanswered(): void
    {
        $this->assertSame('fair', HealthSheet::fromArray(['swimming_level' => 'fair'])->swimmingLevel);
        $this->assertSame('', HealthSheet::fromArray(['swimming_level' => 'champion olympique'])->swimmingLevel);
        $this->assertSame('', HealthSheet::fromArray([])->swimmingLevel);
    }

    // --- what the browser posts ---

    /**
     * A browser posts only the boxes that are ticked, as a list. Reading
     * that list as booleans is what makes UNTICKING a box actually save:
     * a version that only read what was present would never clear one.
     */
    public function testUntickingABoxIsSaved(): void
    {
        $before = HealthSheet::fromBody(['conditions' => ['asthma', 'diabetes']]);
        $this->assertTrue($before->conditions['asthma']);
        $this->assertTrue($before->conditions['diabetes']);

        // The parent unticks diabetes: the browser now posts asthma alone.
        $after = HealthSheet::fromBody(['conditions' => ['asthma']]);
        $this->assertTrue($after->conditions['asthma']);
        $this->assertFalse($after->conditions['diabetes'], 'unticking must be recorded');
    }

    /**
     * Every box unticked posts no `conditions` key at all, which must read
     * as twelve false rather than as « leave them as they were ».
     */
    public function testUntickingEverythingPostsNothingAndClearsEverything(): void
    {
        $sheet = HealthSheet::fromBody(['allergies' => 'Arachides']);

        $this->assertNotContains(true, $sheet->conditions);
        $this->assertSame('Arachides', $sheet->allergies);
    }

    public function testWhatTheParentTypedIsTrimmedButOtherwiseUntouched(): void
    {
        $sheet = HealthSheet::fromBody(['allergies' => "  Arachides, œufs  "]);

        $this->assertSame('Arachides, œufs', $sheet->allergies);
    }
}
