<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Help;

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
        $this->assertNull($topic->moduleId);
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

    public function testRejectsAnInvalidPathForm(): void
    {
        // '*' anywhere but as a trailing '/*' is neither of the two
        // supported forms (exact / direct child).
        $dir = $this->makeTopicDir();
        $path = $this->writeTopic($dir, 'joker', ['paths' => '/admin/*/deep']);

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
}
