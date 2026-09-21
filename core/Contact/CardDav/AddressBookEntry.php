<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Contact\CardDav;

/**
 * One card in the collection, as the listing knows it: which member, the
 * row their card is built from, and the entity tag a client compares
 * against the copy it already holds.
 *
 * **It carries no card.** A `PROPFIND Depth: 1` over a unit of forty
 * leaders answers with forty of these and decrypts nothing; the card
 * itself is built only when a client actually asks for one.
 */
final class AddressBookEntry
{
    public function __construct(
        public readonly int $memberId,
        public readonly int $memberYearId,
        public readonly string $etag
    ) {
    }

    /**
     * The path this card answers at — what goes in a `<D:href>`, and what
     * a client sends back in an `addressbook-multiget`.
     */
    public function href(): string
    {
        return AddressBookService::COLLECTION_PATH . $this->memberId . '.vcf';
    }
}
