<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage\Location\Config;

/**
 * Everything a storage location needs to be reached, EXCEPT its secret.
 *
 * One serialised column holds this rather than one column per field, and
 * that is a deliberate change from the shape the gallery's own table had.
 * The old table carried six `s3_*` columns; WebDAV would have added three
 * more and Google Drive four, and half of every row would have been NULL
 * by construction. What a location is configured with varies by type by
 * definition — so it is stored as what it is, a per-type record, and this
 * interface is what keeps that record typed in PHP rather than an
 * untyped array passed around.
 *
 * The secret stays OUT: it lives in its own encrypted BLOB column of the
 * same row, read only by the repository (see
 * `Core\Storage\Location\StorageLocationRepository::getSecret()`), so that
 * nothing which renders, journals, exports or serialises a location can
 * ever reach it by accident.
 */
interface LocationConfig
{
    /**
     * The record as it is stored, and as it comes back. Scalars only —
     * this is written to a JSON column and read back by a factory that
     * must be able to trust nothing but its own type check.
     *
     * @return array<string, string|int|bool|null>
     */
    public function toArray(): array;

    /**
     * One line naming WHERE this points, for the administrator's own
     * screen: a folder, a host and a bucket, an account. Never a secret,
     * never an access key — this string is rendered, and it is also the
     * kind of thing that ends up in a support package.
     */
    public function describe(): string;

    /**
     * True when this location hands out URLs that ANYBODY can use, for
     * ever, with nothing checking who is asking.
     *
     * Every type has to answer this, on purpose. It is the one property
     * that decides whether a consumer with its own access control — a
     * discussion group's photos, a delegated album — may be stored here at
     * all: a permanent public URL defeats that control entirely, and the
     * failure is silent and irreversible. A new kind of storage that
     * forgets the question does not compile; one that answers it wrongly
     * publishes somebody's private photographs.
     *
     * A time-limited pre-signed URL is NOT this: it is a grant minted for
     * one visitor and dead minutes later.
     */
    public function servesPubliclyWithoutExpiry(): bool;
}
