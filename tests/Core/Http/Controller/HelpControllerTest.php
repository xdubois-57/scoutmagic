<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Help\HelpRegistry;
use Core\Help\HelpService;
use Core\Http\Controller\HelpController;
use Core\Http\Request;
use Core\Security\AuthSession;
use PHPUnit\Framework\TestCase;
use Tests\Core\Help\HelpTopicFileFixtures;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

class HelpControllerTest extends TestCase
{
    use HelpTopicFileFixtures;

    private Environment $twig;

    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $_SESSION = [];

        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $this->twig = new Environment(new FilesystemLoader($templateDir), [
            'cache' => false,
            'autoescape' => 'html',
        ]);
        $this->twig->addGlobal('site_name', 'Test');
        $this->twig->addGlobal('is_authenticated', false);
        $this->twig->addGlobal('menus', null);
        $this->twig->addGlobal('cookie_consent_given', true);
        $this->twig->addGlobal('csp_nonce', 'test-nonce');
        $this->twig->addFunction(new \Twig\TwigFunction('csrf_field', fn (): string => '', ['is_safe' => ['html']]));
        $this->twig->addFunction(new \Twig\TwigFunction('csrf_token', fn (): string => 'tok'));
        $this->twig->addFunction(new \Twig\TwigFunction('get_flash', fn (): ?array => null));
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $this->cleanupTopicDirs();
    }

    private function controller(string $coreDir): HelpController
    {
        return new HelpController($this->twig, new HelpService(new HelpRegistry($coreDir)));
    }

    private function loginAs(string $role): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_cookies', '0');
            ini_set('session.cache_limiter', '');
            session_start();
        }
        AuthSession::login(1, 'test@test.test', $role);
    }

    // --- /aide ---

    public function testIndexListsOnlyTheTopicsTheVisitorRoleMaySee(): void
    {
        $dir = $this->makeTopicDir();
        $this->writeTopic($dir, 'sujet-public', ['title' => 'Sujet public']);
        $this->writeTopic($dir, 'sujet-chefs', ['title' => 'Sujet animateurs', 'role_min' => 'chief']);

        $response = $this->controller($dir)->index(new Request('GET', '/aide', [], [], [], []), []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Sujet public', $response->getBody());
        $this->assertStringNotContainsString('Sujet animateurs', $response->getBody());
    }

    public function testIndexSearchFiltersAndAnEmptyResultShowsTheEmptyState(): void
    {
        $dir = $this->makeTopicDir();
        $this->writeTopic($dir, 'photo', ['title' => 'Changer une photo']);
        $this->writeTopic($dir, 'annee', ['title' => "Préparer l'année"]);
        $controller = $this->controller($dir);

        $filtered = $controller->index(new Request('GET', '/aide', ['q' => 'photo'], [], [], []), []);
        $this->assertStringContainsString('Changer une photo', $filtered->getBody());
        $this->assertStringNotContainsString('Préparer', $filtered->getBody());

        $empty = $controller->index(new Request('GET', '/aide', ['q' => 'zzzz'], [], [], []), []);
        $this->assertStringContainsString('Aucun sujet ne correspond', $empty->getBody());
    }

    public function testIndexBadgesAModuleTopic(): void
    {
        $dir = $this->makeTopicDir();
        $this->writeTopic($dir, 'sujet-core');
        $moduleDir = $this->makeTopicDir();
        $this->writeTopic($moduleDir, 'sujet-module');

        $registry = new HelpRegistry($dir);
        $registry->registerModuleTopics('calendar', $moduleDir);
        $controller = new HelpController($this->twig, new HelpService($registry));

        $body = $controller->index(new Request('GET', '/aide', [], [], [], []), [])->getBody();
        $this->assertStringContainsString('>Module<', $body);
    }

    /**
     * A dropped topic is invisible by construction — it is not in the
     * list, because it could not be read. /aide is the one screen that
     * says so, and only to the person who can go and fix the file.
     */
    public function testIndexNamesUnreadableTopicsForASuperadmin(): void
    {
        $dir = $this->makeTopicDir();
        $this->writeTopic($dir, 'sujet-sain');
        $this->writeTopic($dir, 'sujet-casse', ['role_min' => 'inexistant']);
        $this->loginAs('superadmin');

        $body = $this->controller($dir)->index(new Request('GET', '/aide', [], [], [], []), [])->getBody();

        $this->assertStringContainsString("sujet(s) d'aide n'ont pas pu être lus", $body);
        $this->assertStringContainsString('sujet-casse', $body);
    }

    public function testIndexNeverShowsAServerPathToAnyoneElse(): void
    {
        $dir = $this->makeTopicDir();
        $this->writeTopic($dir, 'sujet-sain');
        $this->writeTopic($dir, 'sujet-casse', ['role_min' => 'inexistant']);
        $this->loginAs('chief');

        $body = $this->controller($dir)->index(new Request('GET', '/aide', [], [], [], []), [])->getBody();

        // The topic is missing for them too — but the reason, which names
        // a path on the server, is not theirs to read.
        $this->assertStringNotContainsString("n'ont pas pu être lus", $body);
        $this->assertStringNotContainsString($dir, $body);
    }

    public function testIndexSaysNothingWhenEveryTopicLoads(): void
    {
        $dir = $this->makeTopicDir();
        $this->writeTopic($dir, 'sujet-sain');
        $this->loginAs('superadmin');

        $body = $this->controller($dir)->index(new Request('GET', '/aide', [], [], [], []), [])->getBody();

        $this->assertStringNotContainsString("n'ont pas pu être lus", $body);
    }

    // --- /aide/{id} ---

    public function testShowRendersTheTopicBodyAndRelatedLinks(): void
    {
        $dir = $this->makeTopicDir();
        $this->writeTopic($dir, 'principal', ['related' => 'annexe'], "## Étapes\n\nFaites ceci.");
        $this->writeTopic($dir, 'annexe', ['title' => 'Sujet annexe']);

        $response = $this->controller($dir)->show(new Request('GET', '/aide/principal', [], [], [], []), ['id' => 'principal']);

        $this->assertSame(200, $response->getStatusCode());
        // '## Étapes' renders as the <h2> it reads as (base level 1 —
        // HelpController::RENDER_OPTIONS), under the page's own <h1>.
        $this->assertStringContainsString('<h2 class="fw-semibold mt-2 mb-1">Étapes</h2>', $response->getBody());
        $this->assertStringContainsString('Faites ceci.', $response->getBody());
        $this->assertStringContainsString('/aide/annexe', $response->getBody());
    }

    public function testShowAnswers404ForAnUnknownId(): void
    {
        $dir = $this->makeTopicDir();

        $response = $this->controller($dir)->show(new Request('GET', '/aide/inconnu', [], [], [], []), ['id' => 'inconnu']);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testShowAnswers404NeverAContentPageForATopicBelowTheVisitorsRole(): void
    {
        // The RBAC boundary of this feature: /aide/{id} is role_min:
        // public, so the gate is HelpService's role filter — an intendant
        // asking for a chief topic gets the same 404 as an unknown id
        // (a 403 would confirm the topic exists).
        $dir = $this->makeTopicDir();
        $this->writeTopic($dir, 'pour-chefs', ['title' => 'Contenu réservé', 'role_min' => 'chief']);
        $controller = $this->controller($dir);
        $request = new Request('GET', '/aide/pour-chefs', [], [], [], []);

        $this->loginAs('intendant');
        $denied = $controller->show($request, ['id' => 'pour-chefs']);
        $this->assertSame(404, $denied->getStatusCode());
        $this->assertStringNotContainsString('Contenu réservé', $denied->getBody());

        // At the boundary role, the very same request serves the topic.
        $this->loginAs('chief');
        $allowed = $controller->show($request, ['id' => 'pour-chefs']);
        $this->assertSame(200, $allowed->getStatusCode());
        $this->assertStringContainsString('Contenu réservé', $allowed->getBody());
    }
}
