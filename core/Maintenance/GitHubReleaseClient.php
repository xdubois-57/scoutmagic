<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance;

use Core\Http\StreamResponseHeaders;

/**
 * Thin, unauthenticated GitHub REST client used by
 * Core\Maintenance\GitHubWebhookService (composerLockChanged(), when a
 * "release published" webhook event arrives) — only the two read-only
 * endpoints it needs. No composer dependency: same file_get_contents() +
 * stream_context_create() approach as Modules\LlmConnector\Provider\
 * AnthropicProvider, the only other outbound-HTTP precedent in this
 * codebase. The owner/repo are configurable (Core\Config\SettingService
 * keys update_github_owner/update_github_repo) so a fork can point at its
 * own repository — never hardcoded here.
 */
final class GitHubReleaseClient implements GitHubReleaseClientInterface
{
    private const TIMEOUT = 15;
    private const USER_AGENT = 'ScoutMagic-UpdateChecker';

    public function __construct(
        private string $owner,
        private string $repo
    ) {
    }

    public function getLatestRelease(): ?ReleaseInfo
    {
        return $this->fetchRelease('releases/latest');
    }

    public function getReleaseByTag(string $tag): ?ReleaseInfo
    {
        return $this->fetchRelease('releases/tags/' . rawurlencode($tag));
    }

    private function fetchRelease(string $path): ?ReleaseInfo
    {
        [$status, $body] = $this->httpGet(
            "https://api.github.com/repos/{$this->owner}/{$this->repo}/{$path}"
        );

        if ($status === 404) {
            return null;
        }
        if ($status < 200 || $status >= 300) {
            throw new UpdateException("L'API GitHub a répondu avec le statut {$status}.");
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded) || !isset($decoded['tag_name'])) {
            throw new UpdateException('Réponse GitHub invalide (' . $path . ').');
        }

        $assets = is_array($decoded['assets'] ?? null) ? $decoded['assets'] : [];
        $downloadUrl = self::selectZipAssetUrl($assets)
            ?? (!empty($decoded['zipball_url']) ? (string) $decoded['zipball_url'] : null);

        return new ReleaseInfo(
            tagName: (string) $decoded['tag_name'],
            body: (string) ($decoded['body'] ?? ''),
            htmlUrl: (string) ($decoded['html_url'] ?? ''),
            downloadUrl: $downloadUrl
        );
    }

    /**
     * Selected by filename (.zip suffix), never by array position: GitHub
     * does not preserve upload order in the assets array (a release built
     * by scripts/release.sh also ships bootstrap.php as a second asset —
     * observed ordering puts it before the zip at assets[0]), so picking
     * assets[0] unconditionally can resolve to the wrong file entirely.
     * Extracted as a pure static method so it's testable without a real
     * network call — the rest of this class has no such seam.
     *
     * @param array<int, mixed> $assets
     */
    public static function selectZipAssetUrl(array $assets): ?string
    {
        foreach ($assets as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $name = strtolower((string) ($asset['name'] ?? ''));
            if (str_ends_with($name, '.zip') && !empty($asset['browser_download_url'])) {
                return (string) $asset['browser_download_url'];
            }
        }

        return null;
    }

    public function getLatestCommit(string $branch): ?CommitInfo
    {
        return $this->fetchCommit($branch);
    }

    public function getCommit(string $sha): ?CommitInfo
    {
        return $this->fetchCommit($sha);
    }

    /**
     * GitHub's commits endpoint accepts any git ref — a branch name or a
     * sha — as the same {ref} path segment, so getLatestCommit(branch) and
     * getCommit(sha) are really the same request shape.
     */
    private function fetchCommit(string $ref): ?CommitInfo
    {
        [$status, $body] = $this->httpGet(
            "https://api.github.com/repos/{$this->owner}/{$this->repo}/commits/" . rawurlencode($ref)
        );

        if ($status === 404) {
            return null;
        }
        if ($status < 200 || $status >= 300) {
            throw new UpdateException("L'API GitHub a répondu avec le statut {$status} (commits).");
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded) || !isset($decoded['sha'])) {
            throw new UpdateException('Réponse GitHub invalide (commits).');
        }

        return new CommitInfo(
            sha: (string) $decoded['sha'],
            message: (string) ($decoded['commit']['message'] ?? ''),
            htmlUrl: (string) ($decoded['html_url'] ?? '')
        );
    }

    public function composerLockChanged(string $base, string $head): bool
    {
        [$status, $body] = $this->httpGet(
            "https://api.github.com/repos/{$this->owner}/{$this->repo}/compare/{$base}...{$head}"
        );

        if ($status < 200 || $status >= 300) {
            throw new UpdateException("L'API GitHub a répondu avec le statut {$status} (compare).");
        }

        $decoded = json_decode($body, true);
        $files = is_array($decoded['files'] ?? null) ? $decoded['files'] : [];
        foreach ($files as $file) {
            if (($file['filename'] ?? '') === 'composer.lock') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function httpGet(string $url): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: " . self::USER_AGENT . "\r\nAccept: application/vnd.github+json\r\n",
                'timeout' => self::TIMEOUT,
                'ignore_errors' => true,
            ],
        ]);

        StreamResponseHeaders::clear();
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            throw new UpdateException("Impossible de contacter l'API GitHub.");
        }

        $status = 0;
        foreach (StreamResponseHeaders::last() as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $header, $matches)) {
                $status = (int) $matches[1];
            }
        }

        return [$status, $body];
    }
}
