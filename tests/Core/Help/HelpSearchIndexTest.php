<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Help;

use Core\Help\HelpPageLinkResolver;
use Core\Help\HelpRegistry;
use Core\Help\HelpSearchIndex;
use Core\Help\HelpService;
use Core\Http\Controller\HelpController;
use Core\Http\Router;
use Core\Security\Role;
use PHPUnit\Framework\TestCase;

/**
 * The corpus blob that ships inside every page for the instant search.
 *
 * The invariant worth a test is the role filter: this data travels to the
 * browser in the page source, so a topic above the visitor's role must
 * not be in it at all — being filtered out by the script afterwards would
 * be no protection whatsoever.
 */
final class HelpSearchIndexTest extends TestCase
{
    use HelpTopicFileFixtures;

    protected function tearDown(): void
    {
        $this->cleanupTopicDirs();
    }

    private function index(string $dir): HelpSearchIndex
    {
        $router = new Router();
        $router->addRoute('GET', '/admin/journal', HelpController::class, 'index', 'admin', ['label' => 'Journal', 'parents' => []]);

        return new HelpSearchIndex(
            new HelpService(new HelpRegistry($dir)),
            new HelpPageLinkResolver($router)
        );
    }

    public function testCarriesTheSearchableFieldsAndThePageLink(): void
    {
        $dir = $this->makeTopicDir();
        $this->writeTopic($dir, 'journal', [
            'title' => 'Consulter le journal',
            'summary' => 'Qui a fait quoi, et quand.',
            'category' => "Espace chefs d'U",
            'role_min' => 'admin',
            'paths' => '/admin/journal',
            'question' => ['Comment savoir qui a changé une section ?', 'Où voir les erreurs du site ?'],
        ]);

        $entries = $this->index($dir)->forRole(Role::ADMIN);

        $this->assertSame([[
            'id' => 'journal',
            'title' => 'Consulter le journal',
            'summary' => 'Qui a fait quoi, et quand.',
            'category' => "Espace chefs d'U",
            'questions' => ['Comment savoir qui a changé une section ?', 'Où voir les erreurs du site ?'],
            'link' => ['path' => '/admin/journal', 'label' => 'Journal'],
        ]], $entries);
    }

    public function testATopicAboveTheVisitorsRoleNeverReachesTheirBrowser(): void
    {
        $dir = $this->makeTopicDir();
        $this->writeTopic($dir, 'public-topic');
        $this->writeTopic($dir, 'chief-topic', ['role_min' => 'chief']);

        $this->assertSame(
            ['public-topic'],
            array_column($this->index($dir)->forRole(Role::PUBLIC), 'id')
        );
        $this->assertSame(
            ['chief-topic', 'public-topic'],
            array_column($this->index($dir)->forRole(Role::CHIEF), 'id')
        );
    }

    public function testTheLinkIsNullWhenNoSinglePageIsNamed(): void
    {
        $dir = $this->makeTopicDir();
        $this->writeTopic($dir, 'famille', ['paths' => '/admin/journal/*']);

        $this->assertNull($this->index($dir)->forRole(Role::SUPERADMIN)[0]['link']);
    }

    public function testNoBodyTravelsWithTheIndex(): void
    {
        // A body is a file read per topic and would take the blob from
        // ~15 KB to several hundred. The search ranks front matter; the
        // body lives at /aide/{id}.
        $dir = $this->makeTopicDir();
        $this->writeTopic($dir, 'sujet', [], "Un corps qui ne doit pas voyager.\n");

        $encoded = (string) json_encode($this->index($dir)->forRole(Role::PUBLIC));

        $this->assertStringNotContainsString('ne doit pas voyager', $encoded);
    }
}
