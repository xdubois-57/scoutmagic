<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Repository;

use Core\Service\DateInput;
use Modules\Rental\Document\ConditionsVersion;

/**
 * The archive of every wording of an asset's conditions (issue #494).
 *
 * Insert-only: nothing here updates or deletes a version, because a version
 * is what some renter accepted, and a booking points at it for as long as
 * the booking exists. Only the asset's own deletion takes its versions with
 * it (`ON DELETE CASCADE`).
 *
 * Every timestamp is computed in PHP, never the server's `NOW()`, so this
 * runs unmodified against the SQLite test database and the date a page
 * shows is the one PHP wrote.
 */
class RentalConditionsVersionRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function findByHash(int $assetId, string $hash): ?ConditionsVersion
    {
        $stmt = $this->pdo->prepare(
            'SELECT asset_id, version, text_hash, body_html, created_at
               FROM rental_conditions_versions
              WHERE asset_id = ? AND text_hash = ?'
        );
        $stmt->execute([$assetId, $hash]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : self::hydrate($row);
    }

    /**
     * The version a booking or a link names.
     *
     * Twelve hex characters leave two texts of one asset colliding on their
     * prefix a theoretical case, not an impossible one; the oldest wins, so
     * a link never changes meaning once it has been sent.
     */
    public function findByVersion(int $assetId, string $version): ?ConditionsVersion
    {
        $stmt = $this->pdo->prepare(
            'SELECT asset_id, version, text_hash, body_html, created_at
               FROM rental_conditions_versions
              WHERE asset_id = ? AND version = ?
              ORDER BY id
              LIMIT 1'
        );
        $stmt->execute([$assetId, $version]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : self::hydrate($row);
    }

    /**
     * Archive a wording, or return the one already archived for it.
     *
     * **Insert first, read back on a duplicate**, rather than read then
     * insert: two visitors opening the conditions page of an asset nobody
     * has archived yet both find nothing, and the second INSERT then meets
     * the unique index on (asset, hash). That is the ordinary outcome of a
     * race here, not an error — SQLSTATE 23000 is caught and the winner's
     * row returned. Any other failure propagates.
     */
    public function archive(
        int $assetId,
        string $hash,
        string $html,
        \DateTimeImmutable $at,
        ?int $userAccountId
    ): ConditionsVersion {
        $existing = $this->findByHash($assetId, $hash);
        if ($existing !== null) {
            return $existing;
        }

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO rental_conditions_versions
                    (asset_id, version, text_hash, body_html, created_at, created_by_user_account_id)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $assetId,
                substr($hash, 0, 12),
                $hash,
                $html,
                $at->format('Y-m-d H:i:s'),
                $userAccountId,
            ]);
        } catch (\PDOException $e) {
            // 23000 is every integrity violation, not only a duplicate: a
            // missing asset's foreign key answers with it too. So the
            // duplicate is recognised by what it leaves behind — the other
            // visitor's row — and anything that left nothing is rethrown
            // as the error it is.
            if ($e->getCode() !== '23000') {
                throw $e;
            }

            return $this->findByHash($assetId, $hash) ?? throw $e;
        }

        return $this->findByHash($assetId, $hash)
            ?? throw new \RuntimeException('A conditions version was archived and could not be read back.');
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): ConditionsVersion
    {
        return new ConditionsVersion(
            assetId: (int) $row['asset_id'],
            version: (string) $row['version'],
            hash: (string) $row['text_hash'],
            html: (string) $row['body_html'],
            createdAt: DateInput::requireFromStorage(
                (string) $row['created_at'],
                'rental_conditions_versions.created_at'
            )
        );
    }
}
