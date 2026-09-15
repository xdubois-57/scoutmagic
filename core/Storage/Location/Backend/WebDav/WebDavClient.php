<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage\Location\Backend\WebDav;

/**
 * The WebDAV verbs this application needs, over cURL.
 *
 * **Hand-written, like the other HTTP clients here, and deliberately
 * not a library.** WebDAV's surface is enormous — locking, properties,
 * versioning, ACLs — and this uses five verbs of it: `PROPFIND` to ask
 * what is there, `PUT` to write, `GET` to read (whole or by range),
 * `DELETE` to remove, `MKCOL` to create a folder. A dependency would
 * bring the other ninety per cent, its own transitive tree and its own
 * release cadence, to save perhaps two hundred lines that are exercised
 * by every test in this suite.
 *
 * **What makes WebDAV worth the trouble is what Drive cannot do.** A
 * `GET` with a `Range:` header returns a slice, so a film plays and a
 * viewer can seek in it; Nextcloud, kDrive, a Hetzner Storage Box and
 * Koofr all answer it. And getting there costs an address, a username
 * and a password — no developer console, no OAuth, no consent screen to
 * publish.
 *
 * The transport is injectable for the same reason it is on
 * {@see \Core\Storage\Location\Backend\Drive\GoogleDriveClient}: the
 * closure that talks to the network is exercised nowhere, so everything
 * above it can be.
 *
 * @phpstan-type WebDavResponse array{status: int, body: string, headers: array<string, string>}
 * @phpstan-type WebDavTransport \Closure(string, string, array<string, string>, ?string): WebDavResponse
 */
final class WebDavClient
{
    /** Seconds to establish the connection before giving up. */
    private const CONNECT_TIMEOUT = 10;

    /** Floor on how long one request may take, whatever its size. */
    private const TIMEOUT = 30;

    /**
     * The slowest a transfer may move before it counts as dead, in bytes
     * per second, and for how long. Slow is tolerated — this runs on unit
     * halls' ADSL — but stalled is not.
     */
    private const MIN_BYTES_PER_SECOND = 2048;

    private const STALL_SECONDS = 30;

    /**
     * @param null|WebDavTransport $transport
     */
    public function __construct(
        private readonly ?\Closure $transport = null
    ) {
    }

    /**
     * Writes $contents at $url, creating nothing: the caller has already
     * made sure the collection exists.
     *
     * @throws WebDavAccessException
     */
    public function put(string $url, string $auth, string $contents, string $mimeType): void
    {
        $response = $this->send('PUT', $url, [
            'Authorization' => $auth,
            'Content-Type' => $mimeType,
        ], $contents);

        // 201 for a file that was not there, 204 for one that was, 200
        // from servers that answer the write with the resource. All three
        // mean the bytes are stored.
        if (!in_array($response['status'], [200, 201, 204], true)) {
            throw self::errorFor($response['status'], 'Le fichier n\'a pas pu être écrit sur le partage.');
        }
    }

    /**
     * The whole object.
     *
     * @throws WebDavAccessException
     */
    public function get(string $url, string $auth): string
    {
        $response = $this->send('GET', $url, ['Authorization' => $auth]);
        if ($response['status'] !== 200) {
            throw self::errorFor($response['status'], 'Le fichier n\'a pas pu être lu sur le partage.');
        }

        return $response['body'];
    }

    /**
     * $length bytes from $offset — what makes a video seekable.
     *
     * **206 is the answer that means the range was honoured.** A server
     * that ignores `Range:` replies 200 with the WHOLE object, and a
     * caller that trusted the status would hand a browser a film where it
     * asked for a second of one. Read as a refusal rather than silently
     * truncated here: truncating would make an unseekable share look like
     * a working one, which is the failure this capability exists to rule
     * out.
     *
     * @throws WebDavAccessException
     */
    public function getRange(string $url, string $auth, int $offset, int $length): string
    {
        $last = $offset + $length - 1;
        $response = $this->send('GET', $url, [
            'Authorization' => $auth,
            'Range' => sprintf('bytes=%d-%d', $offset, $last),
        ]);

        if ($response['status'] === 200) {
            throw WebDavAccessException::of(
                'Ce partage ne sait pas envoyer un extrait de fichier, donc les vidéos ne pourraient pas '
                . 'être parcourues depuis cet emplacement.'
            );
        }
        if ($response['status'] !== 206) {
            throw self::errorFor($response['status'], 'L\'extrait demandé n\'a pas pu être lu sur le partage.');
        }

        return $response['body'];
    }

    /** @throws WebDavAccessException */
    public function delete(string $url, string $auth): void
    {
        $response = $this->send('DELETE', $url, ['Authorization' => $auth]);

        // 404 is a success: every caller derives its keys from something
        // that can be older than the share, so « already gone » is the
        // ordinary case and the desired end state.
        if (!in_array($response['status'], [200, 202, 204, 404], true)) {
            throw self::errorFor($response['status'], 'Le fichier n\'a pas pu être supprimé du partage.');
        }
    }

    /**
     * Creates one collection, tolerating one that is already there.
     *
     * @throws WebDavAccessException
     */
    public function makeCollection(string $url, string $auth): void
    {
        $response = $this->send('MKCOL', $url, ['Authorization' => $auth]);

        // 405 « Method Not Allowed » is what a server answers for a
        // collection that exists, and 301 what some answer for one
        // reached without its trailing slash. Neither is a failure of the
        // thing asked for, which is « make sure this folder is there ».
        if (!in_array($response['status'], [201, 301, 405], true)) {
            throw self::errorFor($response['status'], 'Le dossier n\'a pas pu être créé sur le partage.');
        }
    }

    /**
     * One `PROPFIND`, parsed.
     *
     * `Depth: 1` lists a collection's children; `Depth: 0` describes the
     * collection itself, which is how the quota is asked for.
     *
     * @return list<WebDavResource>
     * @throws WebDavAccessException
     */
    public function propfind(string $url, string $auth, int $depth): array
    {
        $response = $this->send('PROPFIND', $url, [
            'Authorization' => $auth,
            'Depth' => (string) $depth,
            'Content-Type' => 'application/xml; charset="utf-8"',
        ], self::PROPFIND_BODY);

        if ($response['status'] !== 207) {
            throw self::errorFor($response['status'], 'Le contenu du partage n\'a pas pu être lu.');
        }

        return WebDavResource::parseMultiStatus($response['body']);
    }

    /**
     * The properties asked for, and only those.
     *
     * An `<allprop/>` would work on every server and is a bad idea on a
     * large collection: Nextcloud answers it with dozens of fields per
     * entry, including share state and comment counts, so listing a
     * folder of ten thousand renditions would carry megabytes of things
     * nothing here reads.
     */
    private const PROPFIND_BODY = '<?xml version="1.0" encoding="utf-8"?>'
        . '<d:propfind xmlns:d="DAV:"><d:prop>'
        . '<d:resourcetype/><d:getcontentlength/><d:getetag/><d:getlastmodified/>'
        . '<d:quota-available-bytes/><d:quota-used-bytes/>'
        . '</d:prop></d:propfind>';

    /**
     * A status turned into a sentence the operator can act on.
     *
     * **The status is what distinguishes the three failures the roadmap
     * asks to be told apart**: wrong credentials, a path that is not
     * there, and everything else. A single « the share refused » would
     * send somebody to check their password over a folder they mistyped.
     */
    private static function errorFor(int $status, string $fallback): WebDavAccessException
    {
        if ($status === 401 || $status === 403) {
            return WebDavAccessException::of(
                'Le partage a refusé ces identifiants. Vérifiez le nom d\'utilisateur et le mot de passe — '
                . 'certains hébergeurs demandent ici un mot de passe d\'application et non celui du compte.'
            );
        }
        if ($status === 404 || $status === 409) {
            return WebDavAccessException::of(
                'Le chemin indiqué n\'existe pas sur ce partage. Vérifiez l\'adresse et le dossier de '
                . 'destination.'
            );
        }
        if ($status === 507) {
            return WebDavAccessException::of('Le partage n\'a plus de place disponible.');
        }

        return WebDavAccessException::of($fallback);
    }

    /**
     * @param array<string, string> $headers
     * @return WebDavResponse
     */
    private function send(string $method, string $url, array $headers, ?string $body = null): array
    {
        $transport = $this->transport ?? self::defaultTransport();

        return $transport($method, $url, $headers, $body);
    }

    /**
     * How long one request may take in total, sized on what it carries
     * rather than fixed: a flat number is a bet on the site's upstream,
     * and this one is meant to run on a unit hall's ADSL.
     */
    public static function transferCeilingSeconds(?string $body): int
    {
        if ($body === null) {
            return self::TIMEOUT;
        }

        return max(self::TIMEOUT, (int) ceil(strlen($body) / self::MIN_BYTES_PER_SECOND));
    }

    /**
     * The real network, used whenever no transport was injected.
     *
     * **`CURLOPT_SSL_VERIFYPEER` is never lowered here**, and the roadmap
     * asks for an invalid certificate to be a named error: it arrives as
     * a cURL failure below, becomes a French sentence, and carries the
     * library's own words to the journal as the cause. A share whose
     * certificate cannot be verified is refused, not accepted with a
     * warning — the password travels on this connection.
     *
     * @return WebDavTransport
     */
    public static function defaultTransport(): \Closure
    {
        return static function (string $method, string $url, array $headers, ?string $body): array {
            $handle = curl_init($url);
            if ($handle === false) {
                throw WebDavAccessException::of('La requête vers le partage n\'a pas pu être préparée.');
            }

            $headerLines = [];
            foreach ($headers as $name => $value) {
                $headerLines[] = $name . ': ' . $value;
            }

            $received = [];
            $options = [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $headerLines,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
                CURLOPT_TIMEOUT => self::transferCeilingSeconds($body),
                CURLOPT_LOW_SPEED_LIMIT => self::MIN_BYTES_PER_SECOND,
                CURLOPT_LOW_SPEED_TIME => self::STALL_SECONDS,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HEADERFUNCTION => static function ($_handle, string $header) use (&$received): int {
                    $parts = explode(':', $header, 2);
                    if (count($parts) === 2) {
                        $received[strtolower(trim($parts[0]))] = trim($parts[1]);
                    }

                    return strlen($header);
                },
            ];
            if ($body !== null) {
                $options[CURLOPT_POSTFIELDS] = $body;
            }
            curl_setopt_array($handle, $options);

            $responseBody = curl_exec($handle);
            if ($responseBody === false) {
                $error = curl_error($handle);
                curl_close($handle);

                throw WebDavAccessException::of(
                    'Le partage n\'a pas pu être contacté. Vérifiez l\'adresse, et que son certificat est '
                    . 'valide et reconnu par ce serveur.',
                    new \RuntimeException($error)
                );
            }
            \assert(is_string($responseBody));

            $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
            curl_close($handle);

            return ['status' => $status, 'body' => $responseBody, 'headers' => $received];
        };
    }
}
