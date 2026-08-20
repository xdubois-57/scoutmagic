<?php

declare(strict_types=1);

namespace Tests\Core\Maintenance;

use Core\Maintenance\GitHubUrlValidator;
use PHPUnit\Framework\TestCase;

class GitHubUrlValidatorTest extends TestCase
{
    /**
     * Every host a legitimate GitHub release/zipball download resolves
     * through (the initial API/asset URL plus the hosts those redirect to).
     *
     * @return array<string, array{string}>
     */
    public static function allowedUrls(): array
    {
        return [
            'release page host' => ['https://github.com/owner/repo/releases/download/v1.2.3/scoutmagic.zip'],
            'api zipball' => ['https://api.github.com/repos/owner/repo/zipball/v1.2.3'],
            'codeload archive' => ['https://codeload.github.com/owner/repo/zip/refs/heads/main'],
            'release asset redirect target' => ['https://objects.githubusercontent.com/github-production-release-asset/1/2'],
            'newer asset host' => ['https://release-assets.githubusercontent.com/github-production-release-asset/1/2'],
            'host casing is irrelevant' => ['https://API.GitHub.com/repos/owner/repo/zipball/v1.2.3'],
        ];
    }

    /**
     * @dataProvider allowedUrls
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('allowedUrls')]
    public function testAllowsGenuineGitHubHttpsUrls(string $url): void
    {
        $this->assertTrue(GitHubUrlValidator::isAllowed($url));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function refusedUrls(): array
    {
        return [
            'arbitrary host' => ['https://evil.example/artifact.zip'],
            'http (not https)' => ['http://github.com/owner/repo/releases/download/v1/x.zip'],
            'lookalike subdomain suffix' => ['https://github.com.evil.example/x.zip'],
            'lookalike prefix' => ['https://evilgithub.com/x.zip'],
            'userinfo smuggling the real host' => ['https://github.com@evil.example/x.zip'],
            'file scheme' => ['file:///etc/passwd'],
            'empty string' => [''],
            'no scheme' => ['github.com/owner/repo'],
            'raw githubusercontent (not an allowlisted download host)' => ['https://raw.githubusercontent.com/owner/repo/main/x'],
        ];
    }

    /**
     * @dataProvider refusedUrls
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('refusedUrls')]
    public function testRefusesEverythingElse(string $url): void
    {
        $this->assertFalse(GitHubUrlValidator::isAllowed($url));
    }
}
