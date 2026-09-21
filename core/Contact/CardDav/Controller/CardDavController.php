<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Contact\CardDav\Controller;

use Core\Contact\CardDav\AddressBookEntry;
use Core\Contact\CardDav\AddressBookService;
use Core\Contact\CardDav\DavRequestParser;
use Core\Contact\CardDav\DavXml;
use Core\Contact\Device\AuthenticatedDevice;
use Core\Contact\Device\DeviceAuthenticator;
use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Http\Response;
use Twig\Environment;

/**
 * The read-only CardDAV server: what a phone's address-book application
 * talks to (`ARCHITECTURE.md` §8.118).
 *
 * **Every route here is `role_min: public` and none of them calls
 * `guardCsrf()`**, which is the second deliberate exception to
 * `SECURITY.md` §4 after the GitHub webhook — written down there, with
 * its scope, rather than left as a precedent. A CardDAV client has no
 * session to bind a token to and speaks only HTTP Basic; the credential
 * is a device credential (`Core\Contact\Device`), checked here on
 * **every single request**, and with it the account's role, re-resolved
 * every time. Nothing in this class trusts anything it was told last
 * time.
 *
 * It extends `AbstractController` because `FrontController` registers
 * nothing else, and it uses none of what that base class offers: no
 * template is rendered here and `guardCsrf()` is never called. The Twig
 * environment it is handed is the framework's contract, not a
 * collaborator of this controller.
 *
 * `PUT` and `DELETE` answer 403. Desk is the source of truth and nothing
 * ever travels back up — a client that tried and was silently ignored
 * would show its user a change that does not exist.
 */
class CardDavController extends AbstractController
{
    /**
     * What `OPTIONS` announces, and what `Allow` lists. `addressbook` is
     * the CardDAV compliance class (RFC 6352 §6.1); `1` and `3` are the
     * WebDAV ones. Class `2` — locking — is absent because nothing here
     * is writable, and announcing it would invite `LOCK` requests this
     * server would then have to refuse.
     */
    private const DAV_COMPLIANCE = '1, 3, addressbook';
    private const ALLOWED_METHODS = 'OPTIONS, GET, HEAD, PROPFIND, REPORT';

    public function __construct(
        protected Environment $twig,
        private DeviceAuthenticator $authenticator,
        private AddressBookService $addressBook,
        private DavRequestParser $parser
    ) {
        parent::__construct($twig);
    }

    /**
     * `/.well-known/carddav` — the autodiscovery entry point (RFC 6764
     * §6). A client is given a bare domain by its user and looks here.
     *
     * A 301 rather than a 302: the mapping is permanent, and a client
     * that remembers it stops asking. Answered **without**
     * authentication on purpose — it hands out a path, which is public
     * knowledge, and a client has not been asked for a credential yet at
     * the point where it follows this.
     *
     * @param array<string, string> $params
     */
    public function wellKnown(Request $request, array $params = []): Response
    {
        return (new Response('', 301))
            ->setHeader('Location', AddressBookService::ROOT_PATH)
            ->setHeader('DAV', self::DAV_COMPLIANCE);
    }

    /**
     * `OPTIONS` on any of the three paths. The `DAV` header is what tells
     * a client this is an address book server at all.
     *
     * Named `announce()` rather than `options()` because
     * `AbstractController::options()` already exists and builds the
     * `<option>` list of a `<select>` — an unrelated helper this
     * controller never uses, whose name the framework got to first.
     *
     * @param array<string, string> $params
     */
    public function announce(Request $request, array $params = []): Response
    {
        $device = $this->authenticator->authenticate(
            $this->authorizationHeader($request),
            $this->sourceIp($request)
        );
        if ($device === null) {
            return $this->unauthorized();
        }

        return (new Response('', 204))
            ->setHeader('DAV', self::DAV_COMPLIANCE)
            ->setHeader('Allow', self::ALLOWED_METHODS)
            // Windows and some older clients look for this before they
            // will speak WebDAV at all.
            ->setHeader('MS-Author-Via', 'DAV');
    }

    /**
     * `PROPFIND` on the root, the collection, or one card.
     *
     * The three are one action because the protocol makes them one
     * shape: a `Depth` header, a list of requested properties, and a
     * `207 Multi-Status` carrying one `<D:response>` per resource in
     * scope.
     *
     * @param array<string, string> $params
     */
    public function propfind(Request $request, array $params = []): Response
    {
        $device = $this->authenticator->authenticate(
            $this->authorizationHeader($request),
            $this->sourceIp($request)
        );
        if ($device === null) {
            return $this->unauthorized();
        }

        $scoutYearId = $this->addressBook->currentScoutYearId();
        $requested = $this->parser->propfindProperties($request->getRawBody());
        $depth = $this->depth($request);
        $path = $request->getPath();

        if ($path === AddressBookService::ROOT_PATH) {
            $responses = [$this->rootResponse($requested)];
            if ($depth !== '0') {
                $responses[] = $this->collectionResponse($requested, $scoutYearId);
            }

            return $this->multistatus($responses, $device);
        }

        if ($path === AddressBookService::COLLECTION_PATH) {
            $responses = [$this->collectionResponse($requested, $scoutYearId)];
            if ($depth !== '0') {
                foreach ($this->addressBook->entries($scoutYearId) as $entry) {
                    $responses[] = $this->cardResponse($entry, $requested, $scoutYearId, withBody: false);
                }
            }

            return $this->multistatus($responses, $device);
        }

        $memberId = (int) ($params['member_id'] ?? 0);
        $entry = $this->entryFor($memberId, $scoutYearId);
        if ($entry === null) {
            return $this->missing();
        }

        return $this->multistatus(
            [$this->cardResponse($entry, $requested, $scoutYearId, withBody: false)],
            $device
        );
    }

    /**
     * `REPORT` on the collection: `addressbook-multiget` (« give me
     * these cards ») and `addressbook-query` (« give me the cards
     * matching this »), the two reports RFC 6352 requires.
     *
     * **`addressbook-query` answers with the whole collection**, whatever
     * filter it carries. The filter is not evaluated, and that is a
     * decision rather than an omission: this collection is one unit's
     * leaders, a client that runs a query is enumerating rather than
     * searching, and a filter silently mis-evaluated would hide cards a
     * client believes it has. Returning more than was asked for is the
     * safe direction — a client keeps what matches — and it is what the
     * collection would have answered to the `PROPFIND Depth: 1` the same
     * client could have sent instead.
     *
     * @param array<string, string> $params
     */
    public function report(Request $request, array $params = []): Response
    {
        $device = $this->authenticator->authenticate(
            $this->authorizationHeader($request),
            $this->sourceIp($request)
        );
        if ($device === null) {
            return $this->unauthorized();
        }

        $scoutYearId = $this->addressBook->currentScoutYearId();
        $report = $this->parser->report($request->getRawBody());

        if ($report['report'] === 'addressbook-multiget') {
            $responses = [];
            foreach ($report['hrefs'] as $href) {
                $memberId = $this->addressBook->memberIdForHref($href);
                $entry = $memberId === null ? null : $this->entryFor($memberId, $scoutYearId);
                if ($entry === null) {
                    // A href this collection does not publish gets its
                    // own 404 response rather than failing the report:
                    // RFC 6352 §8.7 says so, and a client asking for one
                    // card that has since gone must still receive the
                    // others.
                    $responses[] = [
                        'href' => $this->canonicalHref($href, $memberId),
                        'found' => [],
                        'missing' => [],
                        'status' => 'HTTP/1.1 404 Not Found',
                    ];
                    continue;
                }
                $responses[] = $this->cardResponse($entry, $report['properties'], $scoutYearId, withBody: true);
            }

            return $this->multistatus($responses, $device);
        }

        if ($report['report'] === 'addressbook-query') {
            $responses = [];
            foreach ($this->addressBook->entries($scoutYearId) as $entry) {
                $responses[] = $this->cardResponse($entry, $report['properties'], $scoutYearId, withBody: true);
            }

            return $this->multistatus($responses, $device);
        }

        // A report this server does not implement. 403 with the
        // `supported-report` precondition is what RFC 3253 §3.6 asks
        // for, and it is what tells a client to stop trying rather than
        // to retry.
        return $this->error(403, 'supported-report');
    }

    /**
     * `GET` on one card — the plain `text/vcard` body, for a client that
     * fetches cards one at a time rather than through a report.
     *
     * @param array<string, string> $params
     */
    public function card(Request $request, array $params = []): Response
    {
        $device = $this->authenticator->authenticate(
            $this->authorizationHeader($request),
            $this->sourceIp($request)
        );
        if ($device === null) {
            return $this->unauthorized();
        }

        $scoutYearId = $this->addressBook->currentScoutYearId();
        $memberId = (int) ($params['member_id'] ?? 0);
        $card = $this->addressBook->card($memberId, $scoutYearId);
        if ($card === null) {
            return $this->missing();
        }

        $this->authenticator->recordSync($device);

        return (new Response($card['body']))
            ->setHeader('Content-Type', 'text/vcard; charset=utf-8')
            ->setHeader('ETag', $card['etag'])
            // Somebody's home address and telephone number: never in a
            // shared cache, same rule as the download in §8.115.
            ->setHeader('Cache-Control', 'private, no-store');
    }

    /**
     * `PUT` and `DELETE`: 403, always.
     *
     * Not 405. A 405 says « not here », which invites a client to look
     * for the method somewhere else in the collection; a 403 with the
     * `need-privileges` precondition says « you may read this and you
     * may not write it », which is exactly true and which clients
     * surface to their user as a read-only address book.
     *
     * @param array<string, string> $params
     */
    public function refuseWrite(Request $request, array $params = []): Response
    {
        $device = $this->authenticator->authenticate(
            $this->authorizationHeader($request),
            $this->sourceIp($request)
        );
        if ($device === null) {
            return $this->unauthorized();
        }

        return $this->error(403, 'need-privileges');
    }

    /**
     * The root: the principal, and the address-book home set. One path
     * plays all three parts, so a client's discovery walk —
     * `current-user-principal`, then `addressbook-home-set`, then the
     * collection — resolves in two round trips instead of four.
     *
     * @param list<string>|string $requested
     * @return array{href: string, found: array<string, string>, missing: list<string>}
     */
    private function rootResponse(array|string $requested): array
    {
        return $this->respond(AddressBookService::ROOT_PATH, $requested, [
            '{DAV:}resourcetype' => ['D:resourcetype', '<D:collection />'],
            '{DAV:}displayname' => ['D:displayname', DavXml::escape('ScoutMagic')],
            '{DAV:}current-user-principal' => [
                'D:current-user-principal',
                DavXml::href(AddressBookService::ROOT_PATH),
            ],
            '{DAV:}principal-URL' => ['D:principal-URL', DavXml::href(AddressBookService::ROOT_PATH)],
            '{' . DavXml::NS_CARDDAV . '}addressbook-home-set' => [
                'C:addressbook-home-set',
                DavXml::href(AddressBookService::ROOT_PATH),
            ],
        ]);
    }

    /**
     * The collection itself: what it is, what it is called, whether it
     * has changed, and what a client is allowed to do to it.
     *
     * @param list<string>|string $requested
     * @return array{href: string, found: array<string, string>, missing: list<string>}
     */
    private function collectionResponse(array|string $requested, int $scoutYearId): array
    {
        return $this->respond(AddressBookService::COLLECTION_PATH, $requested, [
            '{DAV:}resourcetype' => ['D:resourcetype', '<D:collection /><C:addressbook />'],
            '{DAV:}displayname' => ['D:displayname', DavXml::escape('Animateurs')],
            '{DAV:}current-user-principal' => [
                'D:current-user-principal',
                DavXml::href(AddressBookService::ROOT_PATH),
            ],
            '{DAV:}owner' => ['D:owner', DavXml::href(AddressBookService::ROOT_PATH)],
            // Read, and nothing else. A client that reads this stops
            // offering its user an « add contact » button for this
            // address book, which is a better answer than a 403 after
            // they have typed one in.
            '{DAV:}current-user-privilege-set' => [
                'D:current-user-privilege-set',
                '<D:privilege><D:read /></D:privilege>',
            ],
            '{DAV:}supported-report-set' => [
                'D:supported-report-set',
                '<D:supported-report><D:report><C:addressbook-multiget /></D:report></D:supported-report>'
                . '<D:supported-report><D:report><C:addressbook-query /></D:report></D:supported-report>',
            ],
            '{' . DavXml::NS_CALENDARSERVER . '}getctag' => [
                'CS:getctag',
                DavXml::escape($this->addressBook->collectionTag($scoutYearId)),
            ],
            '{' . DavXml::NS_CARDDAV . '}supported-address-data' => [
                'C:supported-address-data',
                '<C:address-data-type content-type="text/vcard" version="3.0" />',
            ],
            '{' . DavXml::NS_CARDDAV . '}addressbook-description' => [
                'C:addressbook-description',
                DavXml::escape('Les animateurs et le staff d\'unité'),
            ],
        ]);
    }

    /**
     * One card. `withBody` is what separates a listing from a fetch: a
     * `PROPFIND` over the collection answers a tag per card and decrypts
     * nothing, while a `REPORT` that asked for `address-data` gets the
     * card built.
     *
     * @param list<string>|string $requested
     * @return array{href: string, found: array<string, string>, missing: list<string>}
     */
    private function cardResponse(
        AddressBookEntry $entry,
        array|string $requested,
        int $scoutYearId,
        bool $withBody
    ): array {
        $properties = [
            '{DAV:}resourcetype' => ['D:resourcetype', ''],
            '{DAV:}getetag' => ['D:getetag', DavXml::escape($entry->etag)],
            '{DAV:}getcontenttype' => ['D:getcontenttype', DavXml::escape('text/vcard; charset=utf-8')],
        ];

        $wantsBody = $withBody
            && ($requested === DavRequestParser::ALLPROP
                || in_array('{' . DavXml::NS_CARDDAV . '}address-data', (array) $requested, true));

        if ($wantsBody) {
            $card = $this->addressBook->cardFor($entry, $scoutYearId);
            if ($card !== null) {
                $properties['{' . DavXml::NS_CARDDAV . '}address-data'] =
                    ['C:address-data', DavXml::escape($card['body'])];
            }
        }

        return $this->respond($entry->href(), $requested, $properties);
    }

    /**
     * Split what was asked for into what this resource has and what it
     * does not, which is the shape {@see DavXml::multistatus()} needs.
     *
     * `allprop` answers with everything this resource carries and
     * nothing missing — by definition, a request for « all » cannot
     * name a property that is absent.
     *
     * @param list<string>|string $requested
     * @param array<string, array{0: string, 1: string}> $available clark name => [qualified name, inner XML]
     * @return array{href: string, found: array<string, string>, missing: list<string>}
     */
    private function respond(string $href, array|string $requested, array $available): array
    {
        if ($requested === DavRequestParser::ALLPROP) {
            $found = [];
            foreach ($available as [$name, $inner]) {
                $found[$name] = $inner;
            }

            return ['href' => $href, 'found' => $found, 'missing' => []];
        }

        $found = [];
        $missing = [];
        foreach ($requested as $clark) {
            if (isset($available[$clark])) {
                [$name, $inner] = $available[$clark];
                $found[$name] = $inner;
                continue;
            }
            $missing[] = $this->qualify($clark);
        }

        return ['href' => $href, 'found' => $found, 'missing' => $missing];
    }

    /**
     * `{DAV:}getetag` → `D:getetag`, so a property this server does not
     * implement can be echoed back inside its own 404 propstat.
     *
     * A namespace this server declares no prefix for is echoed with its
     * own inline declaration rather than dropped: a client asked for it,
     * and RFC 4918 §9.1 wants every requested property accounted for.
     */
    private function qualify(string $clark): string
    {
        if (preg_match('/^\{([^}]*)\}([A-Za-z_][\w.\-]*)$/', $clark, $matches) !== 1) {
            // Not a name this parser produced. Nothing safe to echo, so
            // it is reported under a neutral element rather than
            // interpolated into markup.
            return 'D:unknown-property';
        }

        [, $namespace, $local] = $matches;

        return match ($namespace) {
            DavXml::NS_DAV => 'D:' . $local,
            DavXml::NS_CARDDAV => 'C:' . $local,
            DavXml::NS_CALENDARSERVER => 'CS:' . $local,
            default => $local . ' xmlns="' . DavXml::escape($namespace) . '"',
        };
    }

    /**
     * The path to echo back for an href a client sent and this
     * collection could not resolve.
     *
     * **Not the raw value.** RFC 4918 lets a client send an absolute URI
     * where it was given a path, and {@see DavXml::href()} encodes what
     * it is handed segment by segment — so echoing
     * `http://host/carddav/staff/9.vcf` verbatim would emit
     * `http%3A//host/…` and leave the client unable to match the 404
     * against the href it asked for, which is the one thing this
     * response exists to let it do.
     *
     * A member id that resolved is rebuilt into this collection's own
     * canonical path; anything else keeps its path component, decoded so
     * the writer's re-encoding round-trips.
     */
    private function canonicalHref(string $href, ?int $memberId): string
    {
        if ($memberId !== null) {
            return AddressBookService::COLLECTION_PATH . $memberId . '.vcf';
        }

        $path = parse_url(trim($href), PHP_URL_PATH);

        return is_string($path) && $path !== '' ? rawurldecode($path) : trim($href);
    }

    /**
     * The listing entry for one member, or null when that member is not
     * in the address book — the lookup both `PROPFIND` on a card and
     * `addressbook-multiget` need, and the one place the two agree on
     * what « does not exist » means.
     */
    private function entryFor(int $memberId, int $scoutYearId): ?AddressBookEntry
    {
        foreach ($this->addressBook->entries($scoutYearId) as $entry) {
            if ($entry->memberId === $memberId) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @param list<array{href: string, found: array<string, string>, missing: list<string>, status?: string}> $responses
     */
    private function multistatus(array $responses, AuthenticatedDevice $device): Response
    {
        $this->authenticator->recordSync($device);

        return (new Response(DavXml::multistatus($responses), 207))
            ->setHeader('Content-Type', 'application/xml; charset=utf-8')
            ->setHeader('DAV', self::DAV_COMPLIANCE)
            ->setHeader('Cache-Control', 'private, no-store');
    }

    /**
     * The one refusal an unauthenticated caller ever sees, identical
     * whether the credential was absent, malformed, revoked, unknown, or
     * belongs to an account that is no longer admin.
     *
     * `Basic` and nothing else in `WWW-Authenticate`: offering `Digest`
     * as well would have clients negotiate down to a scheme this server
     * does not implement. The realm is the site's own name in the sense
     * a client shows it in its password prompt.
     */
    private function unauthorized(): Response
    {
        return (new Response('', 401))
            ->setHeader('WWW-Authenticate', 'Basic realm="ScoutMagic", charset="UTF-8"')
            ->setHeader('DAV', self::DAV_COMPLIANCE);
    }

    /**
     * A bare 404, never the site's HTML error page: the caller is a
     * program, and `AbstractController::notFound()` renders Twig.
     */
    private function missing(): Response
    {
        return new Response('', 404);
    }

    /**
     * A WebDAV error document: the status, and the precondition element
     * that says which rule was broken (RFC 4918 §16).
     */
    private function error(int $status, string $condition): Response
    {
        $body = '<?xml version="1.0" encoding="utf-8"?>' . "\n"
            . '<D:error xmlns:D="' . DavXml::NS_DAV . '" xmlns:C="' . DavXml::NS_CARDDAV . '">'
            . '<D:' . $condition . ' /></D:error>' . "\n";

        return (new Response($body, $status))
            ->setHeader('Content-Type', 'application/xml; charset=utf-8');
    }

    /**
     * `Depth: 0` or `Depth: 1`. Anything else — including `infinity`,
     * which RFC 4918 §9.1 lets a server refuse — is read as 1: this
     * tree is two levels deep, so « everything below here » and « the
     * children of here » are the same set, and refusing would only make
     * a client ask again.
     */
    private function depth(Request $request): string
    {
        return ((string) ($request->getServer('HTTP_DEPTH') ?? '1')) === '0' ? '0' : '1';
    }

    /**
     * The `Authorization` header, from wherever this host left it.
     *
     * Three places, and the reason is that shared hosting is the target
     * of this project (`AGENTS.md` § Hosting). Under mod_php Apache
     * hands PHP the decoded pair in `PHP_AUTH_USER`/`PHP_AUTH_PW` and no
     * header at all; under CGI and FastCGI it strips `Authorization`
     * entirely unless the `.htaccess` copies it into an environment
     * variable, which this project's `.htaccess` files now do — and a
     * copy made by mod_rewrite arrives prefixed with `REDIRECT_`.
     *
     * Reassembled rather than trusted in whichever form it arrives:
     * {@see DeviceAuthenticator::authenticate()} takes one header value
     * and parses it itself, so it is that shape this returns.
     */
    private function authorizationHeader(Request $request): ?string
    {
        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
            $value = $request->getServer($key);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        $user = $request->getServer('PHP_AUTH_USER');
        if (is_string($user) && $user !== '') {
            $password = $request->getServer('PHP_AUTH_PW');

            return 'Basic ' . base64_encode($user . ':' . (is_string($password) ? $password : ''));
        }

        return null;
    }

    private function sourceIp(Request $request): ?string
    {
        $ip = $request->getServer('REMOTE_ADDR');

        return is_string($ip) && $ip !== '' ? $ip : null;
    }
}
