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

    /**
     * @return array<string, array{string}>
     */
    public static function urlsOfTheConfiguredRepository(): array
    {
        return [
            'release asset' => ['https://github.com/owner/repo/releases/download/v1.2.3/scoutmagic.zip'],
            'dev artifact' => ['https://github.com/owner/repo/releases/download/dev-latest/scoutmagic-dev-a1b2c3d.zip'],
            'api zipball' => ['https://api.github.com/repos/owner/repo/zipball/v1.2.3'],
            'repository casing is irrelevant (GitHub names are case-insensitive)' => [
                'https://github.com/Owner/REPO/releases/download/v1.2.3/scoutmagic.zip',
            ],
            'explicit https port' => ['https://github.com:443/owner/repo/releases/download/v1/x.zip'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('urlsOfTheConfiguredRepository')]
    public function testAcceptsAnUrlOfTheConfiguredRepository(string $url): void
    {
        $this->assertTrue(GitHubUrlValidator::isAllowedForRepository($url, 'owner', 'repo'));
    }

    /**
     * Every one of these is on an allowlisted GitHub host, so isAllowed()
     * accepts it; what makes each wrong is the repository it points into.
     *
     * @return array<string, array{string}>
     */
    public static function urlsOfAnotherRepository(): array
    {
        return [
            'release asset of another repository' => ['https://github.com/other/depot/releases/download/v9.9.9/x.zip'],
            'same owner, another repository' => ['https://github.com/owner/other/releases/download/v9.9.9/x.zip'],
            'zipball of another repository' => ['https://api.github.com/repos/other/depot/zipball/v9.9.9'],
            'codeload of another repository' => ['https://codeload.github.com/other/depot/zip/refs/heads/main'],
            'codeload of the configured repository (only ever a redirect hop, never a first URL)' => [
                'https://codeload.github.com/owner/repo/zip/refs/heads/main',
            ],
            'pull request head through the API zipball' => [
                'https://api.github.com/repos/owner/repo/zipball/refs/pull/1/head',
            ],
            'any nested ref through the API zipball' => [
                'https://api.github.com/repos/owner/repo/zipball/refs/heads/main',
            ],
            'API zipball with no ref at all' => ['https://api.github.com/repos/owner/repo/zipball/'],
            'API zipball with an empty ref segment' => ['https://api.github.com/repos/owner/repo/zipball//x'],
            'another API endpoint of the configured repository' => [
                'https://api.github.com/repos/owner/repo/tarball/v1.2.3',
            ],
            'repository name that only starts like the configured one' => [
                'https://github.com/owner/repo-evil/releases/download/v1/x.zip',
            ],
            'owner that only starts like the configured one' => [
                'https://github.com/owner-evil/repo/releases/download/v1/x.zip',
            ],
            'configured repository named only after the release prefix' => [
                'https://github.com/other/depot/releases/download/v1/owner/repo/x.zip',
            ],
            'a GitHub page that is not a download' => ['https://github.com/owner/repo/archive/main.zip'],
            'dot segments walking out of the repository' => [
                'https://github.com/owner/repo/releases/download/../../../../other/depot/releases/download/v1/x.zip',
            ],
            'encoded dot segments' => [
                'https://github.com/owner/repo/releases/download/%2e%2e/%2e%2e/other/depot/x.zip',
            ],
            'encoded slash' => ['https://github.com/owner%2fother/repo/releases/download/v1/x.zip'],
            'asset host that names no repository' => [
                'https://objects.githubusercontent.com/github-production-release-asset/1/2',
            ],
            'newer asset host that names no repository' => [
                'https://release-assets.githubusercontent.com/github-production-release-asset/1/2',
            ],
            'non-default port' => ['https://github.com:8443/owner/repo/releases/download/v1/x.zip'],
            'userinfo' => ['https://user:pass@github.com/owner/repo/releases/download/v1/x.zip'],
            'off GitHub altogether' => ['https://evil.example/owner/repo/releases/download/v1/x.zip'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('urlsOfAnotherRepository')]
    public function testRefusesAnUrlThatIsNotInTheConfiguredRepository(string $url): void
    {
        $this->assertFalse(GitHubUrlValidator::isAllowedForRepository($url, 'owner', 'repo'));
    }

    public function testRefusesEverythingWhenNoRepositoryIsConfigured(): void
    {
        $url = 'https://github.com/owner/repo/releases/download/v1/x.zip';

        $this->assertFalse(GitHubUrlValidator::isAllowedForRepository($url, '', ''));
        $this->assertFalse(GitHubUrlValidator::isAllowedForRepository($url, 'owner', ''));
        $this->assertFalse(GitHubUrlValidator::isAllowedForRepository($url, '', 'repo'));
    }

    public function testAConfiguredNameThatContainsASlashNeverWidensTheMatch(): void
    {
        $url = 'https://github.com/owner/repo/releases/download/v1/x.zip';

        $this->assertFalse(GitHubUrlValidator::isAllowedForRepository($url, 'owner/repo', 'releases'));
        $this->assertFalse(GitHubUrlValidator::isAllowedForRepository($url, 'owner', 'repo/releases'));
    }

    public function testAnArchiveRefMustBeTheReleaseTagWhenOneIsGiven(): void
    {
        $url = 'https://api.github.com/repos/owner/repo/zipball/v1.2.3';

        $this->assertTrue(GitHubUrlValidator::isAllowedForRepository($url, 'owner', 'repo', 'v1.2.3'));
        $this->assertFalse(
            GitHubUrlValidator::isAllowedForRepository($url, 'owner', 'repo', 'v9.9.9'),
            'a ref that is not the release being processed is refused'
        );
        $this->assertFalse(
            GitHubUrlValidator::isAllowedForRepository($url, 'owner', 'repo', 'V1.2.3'),
            'tags are case-sensitive, unlike owners and repositories'
        );
    }

    public function testACommitShaIsNotTheTagOfARelease(): void
    {
        // GitHub resolves a commit sha across the whole fork network, so a
        // sha in the ref position can name a fork's pull request commit.
        $url = 'https://api.github.com/repos/owner/repo/zipball/0123456789abcdef0123456789abcdef01234567';

        $this->assertFalse(GitHubUrlValidator::isAllowedForRepository($url, 'owner', 'repo', 'v1.2.3'));
    }

    public function testTheTagOnlyConstrainsTheApiZipball(): void
    {
        $asset = 'https://github.com/owner/repo/releases/download/asset/scoutmagic.zip';

        $this->assertTrue(GitHubUrlValidator::isAllowedForRepository($asset, 'owner', 'repo', 'v1.2.3'));
    }
}
