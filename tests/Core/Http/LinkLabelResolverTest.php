<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Http;

use Core\Http\LinkLabelFetchLogRepository;
use Core\Http\LinkLabelResolver;
use Core\Http\LinkPreviewCacheRepository;
use Core\Http\LinkPreviewService;
use Core\Http\UrlMetadata;
use Core\Http\UrlMetadataFetcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The « baguette magique » of the link dialog (#840): the order it reads a
 * page in, the file name it falls back on, and the budget every request
 * spends.
 *
 * @group database
 */
#[Group('database')]
final class LinkLabelResolverTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
    }

    private function resolver(?UrlMetadata $page): LinkLabelResolver
    {
        $fetcher = $this->createStub(UrlMetadataFetcher::class);
        $fetcher->method('fetch')->willReturn($page);

        return new LinkLabelResolver(
            new LinkPreviewService($fetcher, new LinkPreviewCacheRepository($this->pdo)),
            new LinkLabelFetchLogRepository($this->pdo)
        );
    }

    public function testTheOpenGraphTitleComesFirst(): void
    {
        $label = $this->resolver(new UrlMetadata('Camp d\'été 2026', 'Accueil — Unité', null, null))
            ->labelFor('https://example.be/camp');

        $this->assertSame('Camp d\'été 2026', $label);
    }

    public function testThePageTitleIsTheFallback(): void
    {
        $label = $this->resolver(new UrlMetadata(null, 'Agenda — Les Scouts', null, null))
            ->labelFor('https://lesscouts.be/agenda');

        $this->assertSame('Agenda — Les Scouts', $label);
    }

    /**
     * A PDF is not an HTML page, so the fetcher reads nothing — and the
     * name of the file is exactly what such a link deserves.
     */
    public function testAFileThatIsNotAPageIsNamedByItsFileName(): void
    {
        $label = $this->resolver(null)->labelFor('https://example.be/docs/Programme%20du%20camp.pdf?v=2');

        $this->assertSame('Programme du camp.pdf', $label);
    }

    public function testAPageWithNoTitleAndNoFileNameProposesNothing(): void
    {
        $this->assertNull($this->resolver(new UrlMetadata(null, '   ', null, null))->labelFor('https://example.be/agenda'));
        $this->assertNull($this->resolver(null)->labelFor('https://example.be/'));
    }

    public function testATitleIsOneLineAndOfReasonableLength(): void
    {
        $label = $this->resolver(new UrlMetadata("Ligne 1\n\tLigne 2  " . str_repeat('x', 300), null, null, null))
            ->labelFor('https://example.be/long');

        $this->assertStringStartsWith('Ligne 1 Ligne 2 x', (string) $label);
        $this->assertSame(200, mb_strlen((string) $label));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function addresses(): iterable
    {
        yield 'https' => ['https://lesscouts.be/agenda', true];
        yield 'http' => ['http://example.be/', true];
        yield 'mailto has no page' => ['mailto:unite@example.be', false];
        yield 'a path on this site' => ['/contact', false];
        yield 'javascript' => ['javascript:alert(1)', false];
        yield 'empty' => ['', false];
        yield 'too long' => ['https://example.be/' . str_repeat('a', 2100), false];
    }

    #[DataProvider('addresses')]
    public function testOnlyAnAbsoluteWebAddressIsLookedAt(string $url, bool $fetchable): void
    {
        $this->assertSame($fetchable, LinkLabelResolver::isFetchable($url));
    }

    /**
     * SECURITY.md §17: every outbound fetch made on somebody's behalf is
     * throttled per person. Thirty in ten minutes, then « no » until the
     * window moves — for that account only.
     */
    public function testEachAccountHasItsOwnBudget(): void
    {
        $resolver = $this->resolver(null);

        for ($i = 0; $i < 30; $i++) {
            $this->assertTrue($resolver->allow(7), "request {$i} should be within the budget");
        }

        $this->assertFalse($resolver->allow(7));
        $this->assertTrue($resolver->allow(8), 'another account is not charged for the first one');
    }

    public function testTheBudgetComesBackOnceTheWindowHasMoved(): void
    {
        $resolver = $this->resolver(null);
        for ($i = 0; $i < 30; $i++) {
            $resolver->allow(7);
        }
        $this->pdo->exec("UPDATE link_label_fetch_log SET created_at = '"
            . (new \DateTimeImmutable('-11 minutes'))->format('Y-m-d H:i:s') . "'");

        $this->assertTrue($resolver->allow(7));
    }

    public function testRowsPastTheRetentionArePurgedOnTheNextRequest(): void
    {
        $resolver = $this->resolver(null);
        $resolver->allow(7);
        $this->pdo->exec("UPDATE link_label_fetch_log SET created_at = '"
            . (new \DateTimeImmutable('-2 hours'))->format('Y-m-d H:i:s') . "'");

        $resolver->allow(8);

        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM link_label_fetch_log')->fetchColumn());
    }
}
