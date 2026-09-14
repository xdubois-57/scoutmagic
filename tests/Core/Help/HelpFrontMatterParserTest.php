<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Help;

use Core\Help\DiscoveryPriority;
use Core\Help\HelpException;
use Core\Help\HelpFrontMatterParser;
use Core\Security\Role;
use PHPUnit\Framework\TestCase;

class HelpFrontMatterParserTest extends TestCase
{
    use HelpTopicFileFixtures;

    private HelpFrontMatterParser $parser;

    protected function setUp(): void
    {
        $this->parser = new HelpFrontMatterParser();
    }

    protected function tearDown(): void
    {
        $this->cleanupTopicDirs();
    }

    public function testParsesAValidTopic(): void
    {
        $dir = $this->makeTopicDir();
        $path = $this->writeTopic($dir, 'import-desk', [
            'title' => 'Importer le fichier Desk',
            'summary' => 'Mettre à jour les membres.',
            'category' => "Espace chefs d'U",
            'role_min' => 'chief',
            'paths' => '/admin/import, /members/*',
            'related' => 'annee-scoute',
        ], "Corps.\n\nSecond paragraphe.");

        $topic = $this->parser->parse($path, 'gallery');

        $this->assertSame('import-desk', $topic->id);
        $this->assertSame('Importer le fichier Desk', $topic->title);
        $this->assertSame(Role::CHIEF, $topic->roleMin);
        $this->assertSame([
            ['path' => '/admin/import', 'match' => 'exact'],
            ['path' => '/members/', 'match' => 'child'],
        ], $topic->paths);
        $this->assertSame(['annee-scoute'], $topic->related);
        $this->assertSame('gallery', $topic->moduleId);
        $this->assertStringContainsString('Second paragraphe.', $topic->body());
    }

    public function testPathsAndRelatedAreOptional(): void
    {
        $dir = $this->makeTopicDir();
        $topic = $this->parser->parse($this->writeTopic($dir, 'doc-pur'));

        $this->assertSame([], $topic->paths);
        $this->assertSame([], $topic->related);
        $this->assertSame([], $topic->questions);
        $this->assertNull($topic->moduleId);
    }

    public function testQuestionIsRepeatableAndKeepsItsCommas(): void
    {
        // The comma separator paths/related use was ruled out for
        // `question` rather than overlooked: a real question contains
        // commas, and splitting on them would cut one in half silently.
        $dir = $this->makeTopicDir();
        $path = $this->writeTopic($dir, 'publipostage', ['question' => [
            'Comment envoyer un mail personnalisé depuis un fichier Excel ?',
            "Comment prévenir les parents, y compris ceux d'une autre section ?",
        ]]);

        $this->assertSame(
            [
                'Comment envoyer un mail personnalisé depuis un fichier Excel ?',
                "Comment prévenir les parents, y compris ceux d'une autre section ?",
            ],
            $this->parser->parse($path)->questions
        );
    }

    public function testRejectsAnEmptyQuestion(): void
    {
        $dir = $this->makeTopicDir();
        $path = $this->writeTopic($dir, 'question-vide', ['question' => ['']]);

        $this->expectException(HelpException::class);
        $this->expectExceptionMessage("declares an empty 'question'");
        $this->parser->parse($path);
    }

    public function testRejectsTheReservedAssistantId(): void
    {
        // /aide/assistant is a route registered before /aide/{topic}, and
        // Router::resolve() keeps the first match — so a topic with this
        // id would be listed, searchable, and unreachable.
        $dir = $this->makeTopicDir();
        $path = $this->writeTopic($dir, 'assistant');

        $this->expectException(HelpException::class);
        $this->expectExceptionMessage("reserved id 'assistant'");
        $this->parser->parse($path);
    }

    public function testRejectsAMissingRequiredField(): void
    {
        $dir = $this->makeTopicDir();
        $path = $this->writeTopic($dir, 'sans-titre', ['title' => null]);

        $this->expectException(HelpException::class);
        $this->expectExceptionMessage("missing the required field 'title'");
        $this->parser->parse($path);
    }

    public function testRejectsAnUnknownRoleMinInsteadOfDowngradingToPublic(): void
    {
        // Role::fromString() silently maps an unknown value to PUBLIC —
        // for a chief-only topic that typo would be a leak, so the parser
        // must refuse it outright.
        $dir = $this->makeTopicDir();
        $path = $this->writeTopic($dir, 'mauvais-role', ['role_min' => 'chef']);

        $this->expectException(HelpException::class);
        $this->expectExceptionMessage("unknown role_min 'chef'");
        $this->parser->parse($path);
    }

    public function testRejectsAnIdThatDoesNotMatchTheFileName(): void
    {
        $dir = $this->makeTopicDir();
        $path = $this->writeTopic($dir, 'vrai-id', [], "Corps.\n", 'autre-nom.md');

        $this->expectException(HelpException::class);
        $this->expectExceptionMessage("is not named 'vrai-id.md'");
        $this->parser->parse($path);
    }

    public function testRejectsAnEmptyBody(): void
    {
        $dir = $this->makeTopicDir();
        $path = $this->writeTopic($dir, 'vide', [], '');

        $this->expectException(HelpException::class);
        $this->expectExceptionMessage('empty body');
        $this->parser->parse($path);
    }

    public function testRejectsAnUnknownFrontMatterKey(): void
    {
        $dir = $this->makeTopicDir();
        $path = $this->writeTopic($dir, 'typo', ['role-min' => 'chief']);

        $this->expectException(HelpException::class);
        $this->expectExceptionMessage("unknown front-matter key 'role-min'");
        $this->parser->parse($path);
    }

    public function testRejectsAFileWithoutAFrontMatterBlock(): void
    {
        $dir = $this->makeTopicDir();
        $path = $dir . '/brut.md';
        file_put_contents($path, "Pas de front matter du tout.\n");

        $this->expectException(HelpException::class);
        $this->parser->parse($path);
    }

    public function testAStarStandsForOneWholeSegmentAnywhereInThePath(): void
    {
        // Half of the rental module's screens are
        // /mes-locations/{slug}/reglages and half of camps' are
        // /chefs/camps/sejours/{id}/documents. With only the exact and
        // direct-child forms no rule could name any of them, so those
        // pages could never carry a contextual help button however many
        // topics were written for them.
        $dir = $this->makeTopicDir();
        $path = $this->writeTopic($dir, 'joker', ['paths' => '/admin/*/deep']);

        $this->assertSame(
            [['path' => '/admin/*/deep', 'match' => 'pattern']],
            $this->parser->parse($path)->paths
        );
    }

    public function testRejectsAStarThatIsNotAWholeSegment(): void
    {
        $dir = $this->makeTopicDir();
        $path = $this->writeTopic($dir, 'joker-partiel', ['paths' => '/admin/loc*/deep']);

        $this->expectException(HelpException::class);
        $this->parser->parse($path);
    }

    public function testExtractBodyStripsExactlyTheFrontMatterBlock(): void
    {
        $dir = $this->makeTopicDir();
        $path = $this->writeTopic($dir, 'corps', [], "## Section\n\nTexte --- avec des tirets.");

        $body = HelpFrontMatterParser::extractBody($path);

        $this->assertStringStartsWith('## Section', $body);
        $this->assertStringContainsString('Texte --- avec des tirets.', $body);
        $this->assertStringNotContainsString('role_min', $body);
    }

    public function testDiscoveryDefaultsToTheOrdinaryRankWhenTheKeyIsAbsent(): void
    {
        $dir = $this->makeTopicDir();
        $path = $this->writeTopic($dir, 'sans-cle');

        $discovery = $this->parser->parse($path)->discovery;

        $this->assertFalse($discovery->isOff());
        $this->assertSame(DiscoveryPriority::DEFAULT_RANK, $discovery->rank());
    }

    /**
     * The key takes a WHOLE NUMBER, not one of three cases — which is
     * what lets a topic be placed before every other without an enum
     * growing a name for it (ARCHITECTURE.md §8.64). `0` is the value
     * `installer-application` carries in the shipped corpus, and it used
     * to be the example of a refused typo.
     *
     * @return array<string, array{0: string, 1: int}>
     */
    public static function discoveryRanks(): array
    {
        return [
            'en tête' => ['0', 0],
            'haute' => ['1', 1],
            'ordinaire, écrite en toutes lettres' => ['2', 2],
            'basse' => ['3', 3],
            'intercalée' => ['15', 15],
            'très basse' => ['900', 900],
            'sous zéro' => ['-5', -5],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('discoveryRanks')]
    public function testDiscoveryReadsAnyWholeNumberAsARank(string $declared, int $expected): void
    {
        $dir = $this->makeTopicDir();
        $path = $this->writeTopic($dir, 'valeur-' . str_replace('-', 'moins', $declared), [
            'discovery' => $declared,
        ]);

        $discovery = $this->parser->parse($path)->discovery;

        $this->assertFalse($discovery->isOff());
        $this->assertSame($expected, $discovery->rank());
    }

    public function testDiscoveryOffIsTheAbsenceOfARankRatherThanALargeOne(): void
    {
        $dir = $this->makeTopicDir();
        $path = $this->writeTopic($dir, 'valeur-off', ['discovery' => 'off']);

        $this->assertTrue($this->parser->parse($path)->discovery->isOff());
    }

    /**
     * The reason the parser throws rather than falling back on the
     * default, and the reason it is worth a test of its own: a silent
     * downgrade turns a typo into a topic that never leads a batch, and
     * nothing anywhere ever says so. Same rule as role_min.
     *
     * What is refused is a SPELLING, not a range: every whole number is a
     * legitimate rank, so these are the ways of writing something that is
     * not one.
     *
     * @return array<string, array{0: string}>
     */
    public static function unreadableDiscoveryValues(): array
    {
        return [
            'un rang décimal' => ['1.5'],
            'un ordinal' => ['1er'],
            'un mot' => ['premier'],
            'une valeur vide' => [''],
            'off mal orthographié' => ['OFF'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unreadableDiscoveryValues')]
    public function testAnUnreadableDiscoveryValueThrowsAndNamesTheFile(string $declared): void
    {
        $dir = $this->makeTopicDir();
        $path = $this->writeTopic($dir, 'valeur-refusee', ['discovery' => $declared]);

        $this->expectException(HelpException::class);
        $this->expectExceptionMessage($path);
        $this->parser->parse($path);
    }
}
