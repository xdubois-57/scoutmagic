<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Http;

use Core\Http\StreamResponseHeaders;
use PHPUnit\Framework\TestCase;

/**
 * The migration off `$http_response_header` (deprecated in PHP 8.5) is a
 * behaviour change, not a rename: the replacement is process-wide and
 * sticky where the old variable was scoped to one call. So what is pinned
 * here is the pairing that makes it safe — and, below, that every call
 * site in the repository actually observes it.
 */
final class StreamResponseHeadersTest extends TestCase
{
    public function testNoRequestMeansNoHeaders(): void
    {
        StreamResponseHeaders::clear();

        $this->assertSame([], StreamResponseHeaders::last());
    }

    /**
     * The failure this class exists to prevent: a fetch that never reaches
     * the network must report "no response", not the headers of whatever
     * succeeded before it. Modules\Gallery\Service\OgScraperService decides
     * whether a remote URL answered on exactly this distinction.
     */
    public function testAFetchThatNeverReachedTheNetworkReportsNoResponse(): void
    {
        StreamResponseHeaders::clear();
        $unreachable = @file_get_contents('http://127.0.0.1:1/nothing-here');

        $this->assertFalse($unreachable, 'the fixture must genuinely fail to connect');
        $this->assertSame(
            [],
            StreamResponseHeaders::last(),
            'a failed connection must not inherit an earlier request\'s headers'
        );
    }

    public function testReadingIsIdempotent(): void
    {
        StreamResponseHeaders::clear();

        $this->assertSame(StreamResponseHeaders::last(), StreamResponseHeaders::last());
    }

    /**
     * Reading a non-HTTP stream leaves the store alone — it is the HTTP
     * wrapper that fills it, so a local file read neither populates nor
     * corrupts what a caller is about to ask about.
     */
    public function testANonHttpReadReportsNoHeaders(): void
    {
        StreamResponseHeaders::clear();
        file_get_contents(__FILE__);

        $this->assertSame([], StreamResponseHeaders::last());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function callSites(): array
    {
        return [
            'statistics transport' => ['core/Statistics/StreamStatisticsTransport.php'],
            'github release client' => ['core/Maintenance/GitHubReleaseClient.php'],
            'update downloader' => ['core/Maintenance/Task/InstallUpdateHandler.php'],
            'llm transport' => ['modules/llm_connector/src/Provider/HttpTransport.php'],
            'og scraper' => ['modules/gallery/src/Service/OgScraperService.php'],
        ];
    }

    /**
     * Reading without clearing first is silent and wrong — it returns a
     * stale success from somewhere else in the process — so it is checked
     * by machine rather than left to review. Every file that reads must
     * also clear, and clear first.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('callSites')]
    public function testEveryCallSiteClearsBeforeItReads(string $relativePath): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/' . $relativePath);
        $this->assertNotFalse($source, "{$relativePath} must be readable");

        $read = strpos($source, 'StreamResponseHeaders::last()');
        $this->assertNotFalse($read, "{$relativePath} is listed as a call site but never reads the headers");

        $clear = strpos($source, 'StreamResponseHeaders::clear()');
        $this->assertNotFalse($clear, "{$relativePath} reads the response headers without clearing them first");
        $this->assertLessThan(
            $read,
            $clear,
            "{$relativePath} must clear the header store before the request, not after reading it"
        );
    }

    /**
     * The deprecated variable is gone and must stay gone: PHP 8.5 emits a
     * notice for every use, which on the live site was 230 of the 233
     * lines in 48 hours of error log — enough to bury a real fatal.
     */
    public function testTheDeprecatedMagicVariableIsUsedNowhere(): void
    {
        $root = dirname(__DIR__, 3);
        $offenders = [];

        foreach (['core', 'modules', 'public'] as $tree) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root . '/' . $tree, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }
                $path = $file->getPathname();
                // The replacement's own docblock names the variable it
                // replaces, which is the one place saying it is fine.
                if (str_ends_with($path, 'core/Http/StreamResponseHeaders.php')) {
                    continue;
                }
                $contents = (string) file_get_contents($path);
                if (str_contains($contents, '$http_response_header')) {
                    $offenders[] = substr($path, strlen($root) + 1);
                }
            }
        }

        $this->assertSame([], $offenders, 'Use Core\Http\StreamResponseHeaders instead of $http_response_header.');
    }
}
