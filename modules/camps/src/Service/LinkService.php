<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Camps\Service;

use Core\Audit\AuditService;
use Core\Audit\AuditSource;
use Core\File\UploadException;
use Core\File\UploadHandler;
use Core\Http\LinkPreviewFetcher;
use Modules\Camps\Repository\Link;
use Modules\Camps\Repository\LinkRepository;

/**
 * Web pages kept next to a stay.
 *
 * Never fetches a URL itself. Every outbound request goes through
 * Core\Http\LinkPreviewFetcher, which is the ONLY place in this
 * codebase allowed to reach an address a member typed: it carries
 * Core\Security\SsrfUrlValidator, and a second implementation would not
 * (SECURITY.md §17). A URL box on a chief-only page is still an SSRF
 * vector — "only chiefs can use it" describes who can aim it, not what it
 * can reach.
 *
 * The fetcher is an OPTIONAL dependency: without the gallery module the
 * link is stored and shown as a bare URL, which is still a usable link.
 */
class LinkService
{
    private const MAX_URL_LENGTH = 1000;
    private const IMAGE_ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    private const IMAGE_MAX_BYTES = 2 * 1024 * 1024;

    public function __construct(
        private LinkRepository $links,
        private AuditService $audit,
        private ?LinkPreviewFetcher $previewFetcher = null,
        private ?UploadHandler $uploadHandler = null
    ) {
    }

    public function attach(int $campId, string $url, ?int $actorUserAccountId): int
    {
        $url = $this->validateUrl($url);
        $preview = $this->previewFetcher?->fetch($url);

        $imageFileId = null;
        if ($preview !== null && $preview->imageBytes !== null) {
            $imageFileId = $this->storeImage($campId, $preview->imageBytes, $actorUserAccountId);
        }

        $id = $this->links->create(
            $campId,
            $url,
            $preview?->title,
            $preview?->description,
            $imageFileId,
            self::siteNameFor($url),
            $preview !== null ? date('Y-m-d H:i:s') : null,
        );

        $recorded = $preview !== null && $preview->title !== null ? $preview->title : $url;
        $this->audit->record(
            CampService::ENTITY_TYPE, $campId, 'link', null,
            $recorded, AuditSource::Human, 'Lien ajouté', null, $actorUserAccountId
        );

        return $id;
    }

    public function detach(Link $link, ?int $actorUserAccountId): void
    {
        $this->links->delete($link->id);

        $this->audit->record(
            CampService::ENTITY_TYPE, $link->campId, 'link', $link->heading(), null,
            AuditSource::Human, 'Lien retiré', null, $actorUserAccountId
        );
    }

    /**
     * http(s) only, and a real URL. Restricting the scheme HERE, once, is
     * what makes every later render of this value safe to escape and
     * nothing more — escaping alone does not stop a stored
     * "javascript:..." from being clickable.
     */
    public function validateUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            throw new CampsException('Indiquez l\'adresse de la page à retenir.');
        }
        if (mb_strlen($url) > self::MAX_URL_LENGTH) {
            throw new CampsException('Cette adresse est trop longue pour être enregistrée.');
        }
        // "domainedemozet.be" is what a chief types, so a missing scheme
        // gets https. But ONLY a missing one: prefixing a URL that
        // already declares a scheme turns "file:///etc/passwd" into
        // "https://file:///etc/passwd", which parses as an https URL and
        // sails through every check below. A scheme that is present and
        // is not http(s) is refused, never rewritten.
        if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $url) !== 1) {
            $url = 'https://' . $url;
        }
        if (!in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)
            || filter_var($url, FILTER_VALIDATE_URL) === false
        ) {
            throw new CampsException('Cette adresse n\'est pas une adresse web valide.');
        }

        return $url;
    }

    /**
     * The host, without "www." — what a card shows under its title so a
     * reader can tell a link to the owner's own site from a link to a
     * booking platform.
     */
    public static function siteNameFor(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return null;
        }

        return preg_replace('/^www\./i', '', $host);
    }

    /**
     * The preview image becomes a `files` row of this module rather than
     * a remote URL rendered as-is: re-fetching it on every page view
     * would hand the linked site the IP of every chief who opens the
     * stay.
     */
    private function storeImage(int $campId, string $imageBytes, ?int $actorUserAccountId): ?int
    {
        if ($this->uploadHandler === null) {
            return null;
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'camps_link_preview_');
        if ($tmpPath === false) {
            return null;
        }

        try {
            file_put_contents($tmpPath, $imageBytes);

            return $this->uploadHandler->handle(
                ['tmp_name' => $tmpPath, 'size' => strlen($imageBytes), 'error' => UPLOAD_ERR_OK, 'name' => 'link_preview'],
                "camps/{$campId}/links",
                self::IMAGE_ALLOWED_MIMES,
                self::IMAGE_MAX_BYTES,
                'chief',
                'camps',
                $actorUserAccountId,
                CampFileOwnershipChecker::OWNER_TYPE,
                $campId
            );
        } catch (UploadException) {
            // Best-effort, like gallery's own OG image cache: an
            // unsupported or oversized image leaves the link with a title
            // and no picture. It never blocks saving the link.
            return null;
        } finally {
            @unlink($tmpPath);
        }
    }
}
