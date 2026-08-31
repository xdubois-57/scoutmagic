<?php

declare(strict_types=1);

namespace Tests\Modules\Leadership\Service;

use Modules\Leadership\Service\CandidateDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The candidate word is the module's primary mechanism, so it has to
 * survive every way a unit or the federation might have typed the rest of
 * the label around it.
 */
class CandidateDetectorTest extends TestCase
{
    /**
     * @return array<string, array{?string}>
     */
    public static function candidateLabels(): array
    {
        return [
            'bare word' => ['Candidat'],
            'prefixed function' => ['Candidat animateur'],
            'uppercase' => ['CANDIDAT ANIMATEUR'],
            'lowercase' => ['candidat animateur'],
            'feminine' => ['Candidate animatrice'],
            'inclusive middle dot' => ['Candidat·e animateur·rice'],
            'inclusive parentheses' => ['Candidat(e) animateur'],
            'the noun' => ['Animateur en candidature'],
            'accented neighbours' => ['Candidat animateur d\'unité'],
            'the other real one' => ['Candidat intendant'],
            'extra whitespace' => ['   Candidat   animateur  '],
            'hyphenated' => ['Candidat-animateur'],
        ];
    }

    #[DataProvider('candidateLabels')]
    public function testRecognisesTheWordHoweverItIsWritten(?string $label): void
    {
        $this->assertTrue((new CandidateDetector())->isCandidateLabel($label));
    }

    /**
     * @return array<string, array{?string}>
     */
    public static function nonCandidateLabels(): array
    {
        return [
            'plain animateur' => ['Animateur'],
            'unit-level animation' => ["Animateur d'unité"],
            'section responsable' => ['Animateur responsable'],
            'intendant' => ['Intendant'],
            'accented but unrelated' => ['Animateur délégué'],
            'empty' => [''],
            'null' => [null],
            'whitespace' => ['   '],
        ];
    }

    #[DataProvider('nonCandidateLabels')]
    public function testLeavesEverythingElseAlone(?string $label): void
    {
        $this->assertFalse((new CandidateDetector())->isCandidateLabel($label));
    }
}
