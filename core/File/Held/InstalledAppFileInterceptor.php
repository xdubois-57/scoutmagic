<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\File\Held;

use Core\Http\Request;
use Core\Http\Response;
use Twig\Environment;

/**
 * In the installed application, a navigation never ends on a file: the
 * file is put aside and the window gets a viewer page (issue #502).
 *
 * **Why here, and not in the templates.** Three guards used to exist and
 * all three looked at LINKS: `partials/file_link.html.twig`, the net of
 * `public/assets/js/file-viewer.js`, and FileController's `attachment`.
 * Forms, links to routes that GENERATE a file (every export, every PDF),
 * addresses built in JavaScript — none of them are a link to `/files/…`,
 * and each new route was one more thing to remember. And on iOS neither
 * `download` nor `target="_blank"` keeps the installed window still: both
 * end on Safari's own download screen, inside it, with no way back.
 *
 * So the decision is taken once, on the response, whatever produced it.
 * Called from the tail of public/index.php, just before the response is
 * sent, and it acts only when all three are true:
 *
 *  1. the request is a **navigation** — `Sec-Fetch-Dest: document`, or,
 *     from a browser too old to send it, an `Accept` asking for HTML;
 *  2. the page said it is the **installed application** — the `sm_display`
 *     cookie public/assets/js/display-mode.js writes on every page;
 *  3. the response is a **file** — `Content-Disposition: attachment`, or a
 *     type that is not HTML.
 *
 * A `fetch()` (`Sec-Fetch-Dest: empty`), an `<img>` and a browser tab are
 * never touched. Neither are the held document's own two routes, which
 * are what the viewer's buttons open.
 */
class InstalledAppFileInterceptor
{
    public const COOKIE = 'sm_display';
    public const COOKIE_STANDALONE = 'standalone';

    public function __construct(
        private readonly HeldDocumentService $documents,
        private readonly Environment $twig
    ) {
    }

    /**
     * Conditions 1 and 2, read from the raw request. Static so that
     * public/index.php can ask before the request object exists — it drops
     * the conditional headers of such a request, because a 304 would hand
     * the window a file from the browser's cache without this class ever
     * seeing it.
     *
     * @param array<string, mixed> $server
     * @param array<string, mixed> $cookies
     */
    public static function isInstalledAppNavigation(array $server, array $cookies): bool
    {
        if (($cookies[self::COOKIE] ?? null) !== self::COOKIE_STANDALONE) {
            return false;
        }

        $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
        if ($method !== 'GET' && $method !== 'POST') {
            return false;
        }

        $path = (string) (parse_url((string) ($server['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
        if (str_starts_with($path, HeldDocumentService::BROWSER_ROUTE_PREFIX)) {
            return false;
        }

        $destination = $server['HTTP_SEC_FETCH_DEST'] ?? null;
        if (is_string($destination) && $destination !== '') {
            return $destination === 'document';
        }

        return str_contains((string) ($server['HTTP_ACCEPT'] ?? ''), 'text/html');
    }

    /**
     * Condition 3: a successful answer that is a file rather than a page.
     */
    public static function isFile(Response $response): bool
    {
        if ($response->getStatusCode() !== 200) {
            return false;
        }

        $disposition = strtolower(trim(self::header($response, 'Content-Disposition')));
        if (str_starts_with($disposition, 'attachment')) {
            return true;
        }

        $type = strtolower(trim(self::header($response, 'Content-Type')));

        // No declared type is PHP's own default, text/html: a page. JSON is
        // an API's answer, never a document somebody opens.
        return $type !== ''
            && !str_starts_with($type, 'text/html')
            && !str_starts_with($type, 'application/json');
    }

    /**
     * The response to send: $response itself, or the viewer page that
     * replaces it.
     */
    public function intercept(
        Request $request,
        Response $response,
        string $sessionId,
        ?int $userAccountId,
        \DateTimeImmutable $now
    ): Response {
        $cookies = [self::COOKIE => $request->getCookie(self::COOKIE)];
        if (!self::isInstalledAppNavigation(self::serverOf($request), $cookies) || !self::isFile($response)) {
            return $response;
        }

        $mimeType = self::mimeTypeOf($response);
        $name = self::fileNameOf($response, $request->getPath());
        $size = $response->getBodyFilePath() !== null
            ? (int) @filesize($response->getBodyFilePath())
            : strlen($response->getBody());

        // **Only a signed-in session puts anything aside.** The cookie and
        // the navigation header are the client's own to send, so without
        // this any visitor could make every public file answer write an
        // encrypted file, a row and a journal line, as fast as they can
        // ask. A visitor who is not signed in needs no aside anyway: the
        // route that answered them is public, so the phone's browser can
        // ask for it again itself — `direct_url` — whenever it was a GET.
        // A signed-in session is bounded too
        // (HeldDocumentService::MAX_LIVE_PER_SESSION).
        $document = null;
        $directUrl = null;
        $reason = 'too_large';
        if ($userAccountId === null) {
            $directUrl = $request->getMethod() === 'GET' ? self::requestUri($request) : null;
            $reason = 'unavailable';
        } elseif ($size > HeldDocumentService::MAX_BYTES) {
            $reason = 'too_large';
        } elseif (!$this->documents->canHold($sessionId, $now)) {
            $reason = 'unavailable';
        } else {
            $document = $this->documents->hold(
                $response->getBody(),
                $mimeType,
                $name,
                $sessionId,
                $userAccountId,
                $now
            );
        }

        $html = $this->twig->render('document_viewer.html.twig', [
            'document' => $document,
            'direct_url' => $directUrl,
            'reason' => $reason,
            'name' => $name,
            'type_label' => self::typeLabel($mimeType),
            'size_bytes' => $size,
            'back_url' => self::backUrl($request),
        ]);

        // A file streamed from a temporary file (an archive, a
        // spreadsheet) would have been deleted once sent. It is not sent
        // now, so it is deleted here — only once the viewer rendered, so
        // that a failure above still falls back to the file itself
        // (public/index.php sends $response when this method throws).
        if ($response->getBodyFilePath() !== null && $response->deletesBodyFileAfterSend()) {
            @unlink($response->getBodyFilePath());
        }

        return (new Response($html))
            ->setHeader('Content-Type', 'text/html; charset=utf-8')
            // The keys on this page die within the half hour: nothing may
            // keep it, not the browser and not the service worker.
            ->setHeader('Cache-Control', 'no-store')
            ->setHeader('X-Robots-Tag', 'noindex');
    }

    /**
     * What a reader calls the file, in French.
     */
    public static function typeLabel(string $mimeType): string
    {
        return match (true) {
            $mimeType === 'application/pdf' => 'Document PDF',
            str_starts_with($mimeType, 'image/') => 'Image',
            $mimeType === 'text/csv' => 'Fichier CSV',
            $mimeType === 'text/calendar' => 'Évènement d\'agenda',
            $mimeType === 'text/vcard', $mimeType === 'text/x-vcard' => 'Fiche de contact',
            $mimeType === 'application/zip' => 'Archive ZIP',
            str_contains($mimeType, 'spreadsheet'), $mimeType === 'application/vnd.ms-excel' => 'Tableur',
            str_contains($mimeType, 'wordprocessing'), $mimeType === 'application/msword',
            $mimeType === 'application/vnd.oasis.opendocument.text' => 'Document texte',
            default => 'Fichier',
        };
    }

    /**
     * The file name the response announced, or the last segment of the
     * path. `filename*` (RFC 6266) wins over `filename` when both are there.
     */
    public static function fileNameOf(Response $response, string $path): string
    {
        $disposition = self::header($response, 'Content-Disposition');

        if (preg_match("/filename\\*\\s*=\\s*(?:UTF-8|utf-8)''([^;]+)/", $disposition, $matches) === 1) {
            $name = rawurldecode(trim($matches[1], " \t\""));
        } elseif (preg_match('/filename\s*=\s*"((?:[^"\\\\]|\\\\.)*)"/', $disposition, $matches) === 1) {
            $name = stripslashes($matches[1]);
        } elseif (preg_match('/filename\s*=\s*([^;\s]+)/', $disposition, $matches) === 1) {
            $name = $matches[1];
        } else {
            $name = basename($path);
        }

        // A name is shown and offered back as a file name: no path, no
        // control character, and never empty.
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', ' ', $name));

        return $name !== '' ? mb_substr($name, 0, 200) : 'document';
    }

    private static function mimeTypeOf(Response $response): string
    {
        $type = strtolower(trim(explode(';', self::header($response, 'Content-Type'))[0]));

        return preg_match('~^[a-z0-9.+-]+/[a-z0-9.+-]+$~', $type) === 1 ? $type : 'application/octet-stream';
    }

    /**
     * Where « Retour » goes without JavaScript: the page the request came
     * from when it is this site's own, the home page otherwise.
     */
    private static function backUrl(Request $request): string
    {
        $referer = (string) $request->getServer('HTTP_REFERER', '');
        $host = (string) $request->getServer('HTTP_HOST', '');
        $parts = parse_url($referer);
        if (!is_array($parts) || $host === '' || ($parts['host'] ?? null) === null) {
            return '/';
        }

        $refererHost = $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (strcasecmp($refererHost, $host) !== 0) {
            return '/';
        }

        $path = $parts['path'] ?? '/';
        if (!str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return '/';
        }

        return $path . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    /**
     * This request's own address, path and query, for the phone's browser
     * to ask again — or null when it is not a plain same-site path.
     */
    private static function requestUri(Request $request): ?string
    {
        $uri = (string) $request->getServer('REQUEST_URI', '');
        if (!str_starts_with($uri, '/') || str_starts_with($uri, '//')) {
            return null;
        }

        return $uri;
    }

    /**
     * @return array<string, mixed>
     */
    private static function serverOf(Request $request): array
    {
        return [
            'REQUEST_METHOD' => $request->getMethod(),
            'REQUEST_URI' => $request->getPath(),
            'HTTP_SEC_FETCH_DEST' => $request->getServer('HTTP_SEC_FETCH_DEST'),
            'HTTP_ACCEPT' => $request->getServer('HTTP_ACCEPT'),
        ];
    }

    /**
     * A header by name, whatever case the controller wrote it in.
     */
    private static function header(Response $response, string $name): string
    {
        foreach ($response->getHeaders() as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return '';
    }
}
