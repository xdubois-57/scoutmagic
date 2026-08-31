<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Help\Assistant;

use Core\Help\Assistant\AssistantCatalog;
use Core\Help\HelpRegistry;
use Core\Help\HelpService;
use Core\Security\Role;
use PHPUnit\Framework\TestCase;
use Tests\Core\Help\HelpTopicFileFixtures;

/**
 * The catalogue the selection call reads. Its whole job is to be the role
 * filter — a topic above the reader is absent from the text rather than
 * mentioned and forbidden — and to survive a title containing a pipe.
 */
final class AssistantCatalogTest extends TestCase
{
    use HelpTopicFileFixtures;

    protected function tearDown(): void
    {
        $this->cleanupTopicDirs();
    }

    private function catalog(string $dir): AssistantCatalog
    {
        return new AssistantCatalog(new HelpService(new HelpRegistry($dir)));
    }

    public function testOneLinePerTopicWithItsQuestions(): void
    {
        $dir = $this->makeTopicDir();
        $this->writeTopic($dir, 'calendrier', [
            'title' => 'Consulter le calendrier',
            'summary' => 'Les activités, mois par mois.',
            'question' => ['Où voir les dates des prochaines activités ?', 'Comment ajouter le calendrier ?'],
        ]);

        $this->assertSame(
            'calendrier | Consulter le calendrier | Les activités, mois par mois. | '
            . 'Où voir les dates des prochaines activités ? Comment ajouter le calendrier ?',
            $this->catalog($dir)->forRole(Role::PUBLIC)
        );
    }

    public function testATopicAboveTheRoleIsAbsentFromTheTextEntirely(): void
    {
        $dir = $this->makeTopicDir();
        $this->writeTopic($dir, 'public-topic');
        $this->writeTopic($dir, 'chief-topic', ['role_min' => 'chief']);

        $publicCatalogue = $this->catalog($dir)->forRole(Role::PUBLIC);

        $this->assertStringContainsString('public-topic', $publicCatalogue);
        $this->assertStringNotContainsString('chief-topic', $publicCatalogue);
        $this->assertStringContainsString('chief-topic', $this->catalog($dir)->forRole(Role::CHIEF));
    }

    public function testAPipeInATitleCannotMakeOneTopicReadAsTwo(): void
    {
        $dir = $this->makeTopicDir();
        $this->writeTopic($dir, 'bizarre', ['title' => 'Import | export des membres']);

        $line = $this->catalog($dir)->forRole(Role::PUBLIC);

        $this->assertSame(1, substr_count($line, "\n") + 1);
        $this->assertSame(3, substr_count($line, '|'), 'exactly the three field separators');
    }

    public function testIdsForRoleMatchesTheLinesItWouldWrite(): void
    {
        $dir = $this->makeTopicDir();
        $this->writeTopic($dir, 'un');
        $this->writeTopic($dir, 'deux', ['role_min' => 'admin']);

        $this->assertSame(['un'], $this->catalog($dir)->idsForRole(Role::PUBLIC));
        $this->assertSame(['deux', 'un'], $this->catalog($dir)->idsForRole(Role::ADMIN));
    }
}
