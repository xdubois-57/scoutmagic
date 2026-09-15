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
 * @phpstan-type WebDavTransport \Closure(string, string, array<string, string>, ?string, int): WebDavResponse
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
     * The ceiling for a transfer whose size the caller cannot know —
     * a `GET` of a whole object. Ten minutes, against the stall guard
     * doing the real work: anything moving slower than
     * {@see MIN_BYTES_PER_SECOND} is cut within {@see STALL_SECONDS}.
     */
    private const UNSIZED_CEILING = 600;

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
        // Null: the length of a whole object is what this request is
        // going to find out, so there is nothing to size a ceiling on.
        $response = $this->send('GET', $url, ['Authorization' => $auth], null, null);
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
        ], null, $length);

        if ($response['status'] === 200) {
            throw WebDavAccessException::of(
                'Ce partage ne sait pas envoyer un extrait de fichier, donc les vidéos ne pourraient pas '
                . 'être parcourues depuis cet emplacement.'
            );
        }
        if ($response['status'] !== 206) {
            throw self::errorFor($response['status'], 'L\'extrait demandé n\'a pas pu être lu sur le partage.');
        }

        self::assertSliceIsTheOneAskedFor($response, $offset, $last);

        return $response['body'];
    }

    /**
     * That a 206 carries the bytes that were asked for, and only those.
     *
     * **The status alone is not the answer.** A share — or something
     * between this server and it — can answer 206 with another part of the
     * file, or with the whole of it, and those bytes go straight into the
     * gallery's own 206, under a `Content-Range` this site computed from
     * the object's size. The visitor's player would then be handed a range
     * header that does not describe its payload, and the failure shows up
     * as a video that stutters or refuses to seek, nowhere near here.
     *
     * The end is allowed to fall SHORT of what was asked: RFC 9110 has a
     * server clamp a range that runs past the end of the object, and a
     * caller reading the tail of a file legitimately meets that. What is
     * refused is a start that is not the one requested, an end beyond it,
     * a body whose length disagrees with the range announced, and a
     * missing `Content-Range` — which a 206 must carry.
     *
     * @param array{status: int, body: string, headers: array<string, string>} $response
     * @throws WebDavAccessException
     */
    private static function assertSliceIsTheOneAskedFor(array $response, int $offset, int $last): void
    {
        $announced = $response['headers']['content-range'] ?? '';
        if (preg_match('#^bytes\s+(\d+)-(\d+)/#i', trim($announced), $matches) !== 1) {
            throw WebDavAccessException::of(self::WRONG_SLICE);
        }

        $from = (int) $matches[1];
        $to = (int) $matches[2];
        if ($from !== $offset || $to > $last || $to < $from) {
            throw WebDavAccessException::of(self::WRONG_SLICE);
        }
        if (strlen($response['body']) !== $to - $from + 1) {
            throw WebDavAccessException::of(self::WRONG_SLICE);
        }
    }

    /**
     * One sentence for every shape of a mis-answered range, deliberately.
     * The operator's move is the same in all of them — this share cannot
     * be trusted to serve video — and the shapes differ only in ways that
     * would name protocol internals on a configuration screen.
     */
    private const WRONG_SLICE = 'Ce partage a renvoyé un extrait qui ne correspond pas à celui demandé : '
        . 'les vidéos ne peuvent pas être lues depuis cet emplacement de façon fiable.';

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
        . '<d:propfind xmlns:d="DAV:" xmlns:oc="' . WebDavResource::OWNCLOUD_NS . '"><d:prop>'
        . '<d:resourcetype/><d:getcontentlength/><d:getetag/><d:getlastmodified/>'
        . '<d:quota-available-bytes/><d:quota-used-bytes/>'
        // The one property here that is a digest of the CONTENT. A server
        // that does not know it answers a second propstat at 404 for it,
        // which the parser already steps over.
        . '<oc:checksums/>'
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
            return WebDavAccessException::ofStatus(
                $status,
                'Le partage a refusé ces identifiants. Vérifiez le nom d\'utilisateur et le mot de passe — '
                . 'certains hébergeurs demandent ici un mot de passe d\'application et non celui du compte.'
            );
        }
        if ($status === 404 || $status === 409) {
            return WebDavAccessException::ofStatus(
                $status,
                'Le chemin indiqué n\'existe pas sur ce partage. Vérifiez l\'adresse et le dossier de '
                . 'destination.'
            );
        }
        if ($status === 507) {
            return WebDavAccessException::ofStatus($status, 'Le partage n\'a plus de place disponible.');
        }

        return WebDavAccessException::ofStatus($status, $fallback);
    }

    /**
     * @param array<string, string> $headers
     * @param null|int $expectedResponseBytes how many bytes the ANSWER is
     *        expected to carry. A ranged read knows; a status-only verb
     *        answers nothing, which is the 0 default; **null means the
     *        caller cannot know**, which is a `GET` of a whole object and
     *        the one case with no figure to size a ceiling on.
     * @return WebDavResponse
     */
    private function send(
        string $method,
        string $url,
        array $headers,
        ?string $body = null,
        ?int $expectedResponseBytes = 0
    ): array {
        $transport = $this->transport ?? self::defaultTransport();
        $moved = $expectedResponseBytes === null
            ? null
            : max(strlen($body ?? ''), $expectedResponseBytes);

        return $transport($method, $url, $headers, $body, self::transferCeilingSeconds($moved));
    }

    /**
     * How long one request may take in total, sized on how many bytes it
     * is expected to move rather than fixed: a flat number is a bet on the
     * site's upstream, and this one is meant to run on a unit hall's ADSL.
     *
     * **It sizes reads too, not only writes.** It used to read the request
     * body alone, so every download got the flat 30-second floor whatever
     * it carried — and the stall guard does not cover that, because a
     * transfer holding steady at the acceptable floor never stalls by that
     * definition. An 8 MiB slice, which is what a video seek asks for,
     * would have needed 279 kB/s sustained to finish inside 30 seconds, on
     * the connection this whole type exists to be usable over.
     *
     * A `GET` of a whole object is the one case with no figure to size on:
     * the caller learns the length from the answer. That gets
     * {@see UNSIZED_CEILING} — finite, because an unbounded cURL in a
     * request would hold a worker for as long as a slow server cared to
     * keep it, and generous, because the stall guard is the real bound
     * there.
     */
    public static function transferCeilingSeconds(?int $expectedBytes): int
    {
        if ($expectedBytes === null) {
            return self::UNSIZED_CEILING;
        }

        return max(self::TIMEOUT, (int) ceil($expectedBytes / self::MIN_BYTES_PER_SECOND));
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
        return static function (
            string $method,
            string $url,
            array $headers,
            ?string $body,
            int $ceilingSeconds
        ): array {
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
                CURLOPT_TIMEOUT => $ceilingSeconds,
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
