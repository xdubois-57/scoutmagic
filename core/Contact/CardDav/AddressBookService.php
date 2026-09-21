<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Contact\CardDav;

use Core\Config\ScoutYearService;
use Core\Contact\ContactCardService;
use Core\Contact\Repository\ContactCardRepository;
use Core\Contact\VCardBuilder;
use Core\Contact\VCardVariant;
use Core\Member\MemberNotFoundException;
use Core\Member\MemberService;

/**
 * The one address book this site publishes over CardDAV: the leaders of
 * the current scout year, as the complete {@see VCardVariant::Full}
 * cards IT-01 already defines.
 *
 * **Nothing is written here and nothing ever will be.** The source of
 * truth is Desk; a client that tries to PUT or DELETE is refused by the
 * controller. This class only ever reads.
 *
 * Its shape is dictated by how CardDAV clients behave rather than by how
 * the data is stored. A client polls the collection every few minutes
 * and is told « nothing has changed » almost every time, so the two
 * cheap answers — the collection tag and the per-card entity tags — are
 * built from identifiers and timestamps alone and decrypt nothing
 * ({@see AddressBookRepository}). Decryption happens only on the path
 * that actually hands over a card.
 */
class AddressBookService
{
    /**
     * Where the collection lives. A constant rather than a value built
     * per request: it is written into every `<D:href>` the XML carries,
     * read back from the hrefs a client sends in an
     * `addressbook-multiget`, and declared in `public/index.php` as a
     * route — three places that must say the same thing.
     */
    public const COLLECTION_PATH = '/carddav/staff/';

    /** The discovery root, and the principal, and the home set: one path. */
    public const ROOT_PATH = '/carddav/';

    public function __construct(
        private AddressBookRepository $repository,
        private ContactCardRepository $contactCardRepository,
        private ContactCardService $contactCardService,
        private VCardBuilder $vcardBuilder,
        private MemberService $memberService,
        private ScoutYearService $scoutYearService
    ) {
    }

    /**
     * The scout year the address book publishes — the site's current
     * one, never the year a member happens to be browsing.
     *
     * A synchronised address book has no « effective year » selector to
     * read: there is one answer, and it is the year the unit is running.
     */
    public function currentScoutYearId(): int
    {
        return $this->scoutYearService->getCurrentYear()['id'];
    }

    /**
     * Every card in the collection, listed but not built.
     *
     * @return list<AddressBookEntry>
     */
    public function entries(int $scoutYearId): array
    {
        $members = $this->repository->findStaffMemberYears($scoutYearId);
        if ($members === []) {
            return [];
        }

        $revisions = $this->contactCardRepository->findRevisionsForMembers(array_keys($members));

        $entries = [];
        foreach ($members as $memberId => $memberYearId) {
            $entries[] = new AddressBookEntry(
                $memberId,
                $memberYearId,
                $this->etag($memberId, $revisions[$memberId] ?? null)
            );
        }

        return $entries;
    }

    /**
     * The collection tag: one opaque string that changes when anything
     * in the collection changes, and does not change when nothing does.
     *
     * Built from the membership and from each member's aggregated
     * revision timestamp — never from the cards themselves, which would
     * mean decrypting the whole unit to answer a poll. A leader joining
     * or leaving changes the membership half; an edit to somebody's
     * details moves their timestamp.
     *
     * **What it cannot see**, written down rather than discovered later:
     * a change that moves none of those timestamps — the unit's name in
     * Paramètres, which rides in every card's `ORG`, or a function
     * relabelled on Correspondances Desk. Those reach a client at the
     * next Desk import, which bumps `import_journal.imported_at` for the
     * year and therefore every member's revision at once. A poll is not
     * a guarantee of freshness in CardDAV and no client treats it as
     * one; a `getctag` that lied in the other direction — never settling
     * — would have every client re-downloading every card every few
     * minutes forever.
     */
    public function collectionTag(int $scoutYearId): string
    {
        $members = $this->repository->findStaffMemberYears($scoutYearId);
        if ($members === []) {
            return '"empty"';
        }

        $revisions = $this->contactCardRepository->findRevisionsForMembers(array_keys($members));

        $material = '';
        foreach ($members as $memberId => $memberYearId) {
            $material .= $memberId . '=' . ($revisions[$memberId] ?? '-') . ';';
        }

        return '"' . hash('sha256', $material) . '"';
    }

    /**
     * One card, or null when that member is not a leader this year —
     * which is the same answer as « no such member », on purpose: a
     * client holding a stale href must not be able to tell the two
     * apart, and neither must anybody who guesses identifiers.
     *
     * @return ?array{body: string, etag: string}
     */
    public function card(int $memberId, int $scoutYearId): ?array
    {
        if ($memberId <= 0) {
            return null;
        }

        $memberYearId = $this->repository->findStaffMemberYear($memberId, $scoutYearId);
        if ($memberYearId === null) {
            return null;
        }

        $revision = $this->contactCardRepository->findRevisionsForMembers([$memberId])[$memberId] ?? null;
        $entry = new AddressBookEntry($memberId, $memberYearId, $this->etag($memberId, $revision));

        return $this->cardFor($entry, $scoutYearId);
    }

    /**
     * The card for an entry the caller already listed — the path a
     * `REPORT` takes, which has the entries in hand and must not re-ask
     * the database for each one's member_year row.
     *
     * @return ?array{body: string, etag: string}
     */
    public function cardFor(AddressBookEntry $entry, int $scoutYearId): ?array
    {
        try {
            $profile = $this->memberService->getMemberProfile($entry->memberYearId);
        } catch (MemberNotFoundException) {
            // The row went away between the listing and the fetch. A
            // client asking for a card that no longer exists is told it
            // does not exist, which is what it already handles.
            return null;
        }

        $card = $this->contactCardService->build($profile, VCardVariant::Full, $scoutYearId);

        return [
            'body' => $this->vcardBuilder->build($card, VCardVariant::Full),
            'etag' => $entry->etag,
        ];
    }

    /**
     * The member id a `<D:href>` points at, or null when it points
     * anywhere else.
     *
     * Parsed strictly rather than with a loose regular expression: a
     * client sends these back verbatim in an `addressbook-multiget`, so
     * this reads a value that came from outside. Anything that is not
     * exactly one of the hrefs this collection publishes — a different
     * collection, a traversal, a trailing query string, an identifier
     * with a leading zero — is not resolved to a member.
     */
    public function memberIdForHref(string $href): ?int
    {
        // A client may send an absolute URL where it was given a path.
        $path = parse_url(trim($href), PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return null;
        }

        $path = rawurldecode($path);
        if (!str_starts_with($path, self::COLLECTION_PATH) || !str_ends_with($path, '.vcf')) {
            return null;
        }

        $identifier = substr($path, strlen(self::COLLECTION_PATH), -strlen('.vcf'));

        return preg_match('/^[1-9][0-9]*$/', $identifier) === 1 ? (int) $identifier : null;
    }

    /**
     * A card's entity tag: opaque to the client, stable for as long as
     * the card is, and derived from the same revision timestamp the
     * card's own `REV` carries.
     *
     * Hashed rather than carrying the timestamp in clear. An etag is
     * echoed in logs and proxies, and « member 412 changed at 21:04 » is
     * a fact about a person that nothing needs to publish to do its job.
     */
    private function etag(int $memberId, ?string $revision): string
    {
        return '"' . hash('sha256', $memberId . '|' . ($revision ?? '-')) . '"';
    }
}
