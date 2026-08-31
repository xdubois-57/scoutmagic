<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Help;

use Core\Help\HelpPageLinkResolver;
use Core\Help\HelpRegistry;
use Core\Help\HelpService;
use Core\Http\Controller\HelpController;
use Core\Http\Router;
use Core\Security\Role;
use PHPUnit\Framework\TestCase;

/**
 * The « aller sur la page » link a topic carries — the one direction the
 * help system did not have: `paths` says which pages a topic covers,
 * nothing said which page a topic sends you to.
 */
final class HelpPageLinkResolverTest extends TestCase
{
    use HelpTopicFileFixtures;

    protected function tearDown(): void
    {
        $this->cleanupTopicDirs();
    }

    private function router(): Router
    {
        $router = new Router();
        $router->addRoute('GET', '/admin/journal', HelpController::class, 'index', 'admin', ['label' => 'Journal', 'parents' => []]);
        $router->addRoute('GET', '/aide', HelpController::class, 'index', 'public', ['label' => 'Aide', 'parents' => []]);
        // No breadcrumb: an API endpoint, never a page to send anyone to.
        $router->addRoute('GET', '/api/version', HelpController::class, 'index', 'public');
        // A pattern: matched textually, so a topic covering it gets no link.
        $router->addRoute('GET', '/members/{id}', HelpController::class, 'show', 'identified', ['label' => 'Membre', 'parents' => []]);

        return $router;
    }

    /**
     * @param array<string, string|string[]|null> $frontMatter
     */
    private function topic(string $dir, string $id, array $frontMatter = []): \Core\Help\HelpTopic
    {
        $this->writeTopic($dir, $id, $frontMatter);
        $topic = (new HelpService(new HelpRegistry($dir)))->findById($id, Role::SUPERADMIN);
        self::assertNotNull($topic);

        return $topic;
    }

    public function testResolvesTheFirstExactPathToItsLabelledRoute(): void
    {
        $dir = $this->makeTopicDir();
        $topic = $this->topic($dir, 'journal', ['paths' => '/admin/journal', 'role_min' => 'admin']);

        $this->assertSame(
            ['path' => '/admin/journal', 'label' => 'Journal'],
            (new HelpPageLinkResolver($this->router()))->resolve($topic, Role::ADMIN)
        );
    }

    public function testTheRoleCheckedIsTheTargetRoutesNotTheTopics(): void
    {
        // The topic is readable by anyone; the page it documents is not.
        // A link the visitor cannot follow is a link to a 403, so it
        // disappears — exactly like a breadcrumb ancestor.
        $dir = $this->makeTopicDir();
        $topic = $this->topic($dir, 'journal', ['paths' => '/admin/journal', 'role_min' => 'public']);
        $resolver = new HelpPageLinkResolver($this->router());

        $this->assertNull($resolver->resolve($topic, Role::CHIEF));
        $this->assertNotNull($resolver->resolve($topic, Role::ADMIN));
    }

    public function testAChildRuleOrASegmentPatternIsNeverALink(): void
    {
        // Both stand for a family of pages; the topic names no member of
        // it, and guessing one would be inventing a URL.
        $dir = $this->makeTopicDir();
        $resolver = new HelpPageLinkResolver($this->router());

        $child = $this->topic($dir, 'membres', ['paths' => '/members/*']);
        $pattern = $this->topic($dir, 'profond', ['paths' => '/members/*/emails']);
        $none = $this->topic($dir, 'documentaire');

        $this->assertNull($resolver->resolve($child, Role::SUPERADMIN));
        $this->assertNull($resolver->resolve($pattern, Role::SUPERADMIN));
        $this->assertNull($resolver->resolve($none, Role::SUPERADMIN));
    }

    public function testAPathNoLabelledGetRouteServesIsNotALink(): void
    {
        $dir = $this->makeTopicDir();
        $resolver = new HelpPageLinkResolver($this->router());

        $apiOnly = $this->topic($dir, 'version', ['paths' => '/api/version']);
        $unknown = $this->topic($dir, 'fantome', ['paths' => '/nowhere']);

        $this->assertNull($resolver->resolve($apiOnly, Role::SUPERADMIN));
        $this->assertNull($resolver->resolve($unknown, Role::SUPERADMIN));
    }

    public function testThePageYouAreAlreadyOnIsSkippedAndTheNextOneOffered(): void
    {
        $dir = $this->makeTopicDir();
        $topic = $this->topic($dir, 'deux-pages', ['paths' => '/admin/journal, /aide']);
        $resolver = new HelpPageLinkResolver($this->router());

        // Reading it on /aide/deux-pages: the first page still applies.
        $this->assertSame('/admin/journal', $resolver->resolve($topic, Role::ADMIN, '/aide/deux-pages')['path']);
        // Standing on the first page: the second one is offered instead.
        $this->assertSame('/aide', $resolver->resolve($topic, Role::ADMIN, '/admin/journal')['path']);
        // Standing on the only page a role can reach: nothing to offer.
        $this->assertNull($resolver->resolve($topic, Role::CHIEF, '/aide'));
    }
}
