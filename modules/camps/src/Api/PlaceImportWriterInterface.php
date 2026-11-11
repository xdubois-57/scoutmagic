<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Camps\Api;

/**
 * Creates camp sites taken over from another site — the data migration
 * module's « Endroits de camp » import — with no side effect.
 *
 * What it applies is the module's own rules: the change history (« Lieu
 * créé »), the website accepted only in http(s), the coordinates only as a
 * pair within bounds. What it never does is look anything up: no
 * geocoding, and no language model to compare places. Coordinates the
 * source gave are written as MANUAL ones, so the automatic geocoding never
 * replaces them; a place without any is left to that geocoding, like a
 * place typed by hand.
 *
 * A created place is an ordinary place afterwards: editable, archivable
 * and mergeable in Camps like any other.
 */
interface PlaceImportWriterInterface
{
    /**
     * The place on this site this one looks like, archived places
     * included, by the module's textual duplicate rules alone — never the
     * language model. A certain match wins over a possible one; null when
     * nothing resembles it.
     */
    public function findExisting(ImportedPlace $place): ?ExistingPlace;

    /**
     * The values {@see create()} would leave out, by their source key
     * (`address`, `postal_code`, `city`, `country`, `website_url`,
     * `coordinates`): too long for the module, a website that is not an
     * http(s) address, a single coordinate or one out of bounds. The place
     * is created without them.
     *
     * @return list<string>
     */
    public function unrecognisedFields(ImportedPlace $place): array;

    /**
     * Creates the place, archived if the source said so, and returns its
     * id. Runs on the caller's connection and transaction, opening none of
     * its own.
     *
     * @throws \InvalidArgumentException when the name is empty or longer
     *         than {@see ImportedPlace::MAX_NAME_LENGTH}
     */
    public function create(ImportedPlace $place, ?int $actorUserAccountId): int;
}
