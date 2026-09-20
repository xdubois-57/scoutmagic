<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Feedback\Seed;

use Core\Security\EncryptionService;
use Core\Service\DateInput;

/**
 * Where the seed copies are kept (roadmap IT-07).
 */
class SeedCopyRepository
{
    private const CONTEXT = 'mail_seed_copies.address';

    /**
     * **Its own purpose, not the shared `'email'` one.**
     *
     * `EncryptionService::blindIndex()` states the rule the other way
     * round — indexes COMPARED across tables must share one purpose — and
     * these never are: a seed box is the unit's own mailbox, never a
     * member, so nothing here is ever matched against `member_emails` or
     * `user_accounts`. Domain separation therefore costs nothing and keeps
     * the shared purpose meaning exactly what that method says it means.
     */
    private const BLIND_INDEX_PURPOSE = 'seed_mailbox';

    public function __construct(private \PDO $pdo, private EncryptionService $encryption)
    {
    }

    /**
     * Writes down that a copy is about to go out, or answers false if this
     * box already has one for this run.
     *
     * **The row is written BEFORE the message is sent**, and the order is
     * the point: the unique index is what stops a retry, a second batch or
     * a re-read from emitting a second copy to the same box, and an index
     * consulted after the send would have already let the duplicate out.
     * A send that then fails leaves a `pending` row that the sweep turns
     * into `missing` — which is the truthful answer anyway, since nothing
     * arrived.
     *
     * @return bool whether this caller may now send the copy
     */
    public function claim(string $runReference, string $address, \DateTimeImmutable $now): bool
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO mail_seed_copies
                (run_reference, seed_address_encrypted, seed_address_blind_index, provider, sent_at, verdict)
             VALUES (?, ?, ?, ?, ?, ?)'
        );

        try {
            $statement->execute([
                $runReference,
                $this->encryption->encrypt($address, self::CONTEXT),
                $this->blindIndex($address),
                SeedCopy::providerOf($address),
                $now->format('Y-m-d H:i:s'),
                SeedVerdict::Pending->value,
            ]);
        } catch (\PDOException $failure) {
            // **Only the unique index answers false.** Everything else is
            // a database refusing to write, which is not « we already had
            // it » — the distinction IT-06 had to learn twice.
            if (self::isDuplicateKey($failure)) {
                return false;
            }

            throw $failure;
        }

        return true;
    }

    /**
     * Records where a copy landed. Ignores a second arrival for the same
     * pair: a mailbox re-read is routine, and the first answer is the one
     * that was true.
     */
    public function recordLanding(
        string $runReference,
        string $address,
        string $folder,
        \DateTimeImmutable $now
    ): bool {
        $statement = $this->pdo->prepare(
            'UPDATE mail_seed_copies
                SET verdict = ?, landed_folder = ?, recorded_at = ?
              WHERE run_reference = ? AND seed_address_blind_index = ? AND verdict = ?'
        );
        $statement->execute([
            SeedVerdict::fromFolder($folder)->value,
            $folder,
            $now->format('Y-m-d H:i:s'),
            $runReference,
            $this->blindIndex($address),
            SeedVerdict::Pending->value,
        ]);

        // **Rows MATCHED, not rows changed**, which is why the guard is in
        // the WHERE rather than read off `rowCount()`: SQLite reports the
        // first and MySQL the second unless PDO::MYSQL_ATTR_FOUND_ROWS is
        // set, which this application does not set
        // (`docs/quality-pipeline.md`). Asking the WHERE clause the
        // question makes the answer the same on both engines.
        return $statement->rowCount() > 0;
    }

    /**
     * Turns copies nobody ever saw into `missing`.
     *
     * **A separate step rather than a computed state**, because « not yet »
     * and « never » differ only by elapsed time and a screen that computed
     * it on the fly would flip its own verdict as somebody watched. Written
     * down once, it stays what it was.
     *
     * @return int how many were given up on
     */
    public function markMissingBefore(\DateTimeImmutable $cut): int
    {
        $statement = $this->pdo->prepare(
            'UPDATE mail_seed_copies SET verdict = ?, recorded_at = ? WHERE verdict = ? AND sent_at < ?'
        );
        $statement->execute([
            SeedVerdict::Missing->value,
            (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            SeedVerdict::Pending->value,
            $cut->format('Y-m-d H:i:s'),
        ]);

        return $statement->rowCount();
    }

    /**
     * Every copy of one run, so the consumer can find which box an arrival
     * belongs to — the one caller that needs the addresses back.
     *
     * @return list<SeedCopy>
     */
    public function forRun(string $runReference): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM mail_seed_copies WHERE run_reference = ? ORDER BY provider ASC, id ASC'
        );
        $statement->execute([$runReference]);

        return $this->hydrateAll($statement);
    }

    /**
     * The runs of the window, most recent first, each with its copies —
     * which is the shape the screen draws: one row per run, one column per
     * provider.
     *
     * @return array<string, list<SeedCopy>> keyed by run reference
     */
    public function runsSince(\DateTimeImmutable $since, int $limit = 50): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM mail_seed_copies
              WHERE run_reference IN (
                    SELECT run_reference FROM mail_seed_copies
                     WHERE sent_at >= :since
                     GROUP BY run_reference
                     ORDER BY MAX(sent_at) DESC
                     LIMIT :limit
              )
              ORDER BY sent_at DESC, provider ASC, id ASC'
        );
        $statement->bindValue(':since', $since->format('Y-m-d H:i:s'));
        $statement->bindValue(':limit', max(1, $limit), \PDO::PARAM_INT);
        $statement->execute();

        $runs = [];
        foreach ($this->hydrateAll($statement) as $copy) {
            $runs[$copy->runReference][] = $copy;
        }

        return $runs;
    }

    /**
     * The counts the support archive carries and the routing reads:
     * per provider, how many landed where.
     *
     * **Uncapped, and separate from the listing above for that reason** —
     * a total summed from a capped list is a total of whatever fitted, the
     * defect IT-06 shipped and had to fix twice.
     *
     * **`runs` counts distinct MAILINGS, not copies**, and the two are
     * very different evidence: five boxes at one provider on one mailing
     * say one thing five times. A sample size read off the copies would
     * cross any threshold on a single observation — which is the noise
     * D13 exists to refuse, and which a test caught this method claiming
     * to guard against while it did not.
     *
     * @return list<array{provider: string, runs: int, inbox: int, spam: int, missing: int, pending: int}>
     */
    public function tallyByProviderSince(\DateTimeImmutable $since): array
    {
        $statement = $this->pdo->prepare(
            'SELECT provider,
                    COUNT(DISTINCT CASE WHEN verdict <> \'pending\' THEN run_reference END) AS runs,
                    SUM(CASE WHEN verdict = \'inbox\'   THEN 1 ELSE 0 END) AS inbox,
                    SUM(CASE WHEN verdict = \'spam\'    THEN 1 ELSE 0 END) AS spam,
                    SUM(CASE WHEN verdict = \'missing\' THEN 1 ELSE 0 END) AS missing,
                    SUM(CASE WHEN verdict = \'pending\' THEN 1 ELSE 0 END) AS pending
               FROM mail_seed_copies
              WHERE sent_at >= :since
              GROUP BY provider
              ORDER BY provider ASC'
        );
        $statement->bindValue(':since', $since->format('Y-m-d H:i:s'));
        $statement->execute();

        $tally = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $tally[] = [
                'provider' => (string) $row['provider'],
                'runs' => (int) $row['runs'],
                'inbox' => (int) $row['inbox'],
                'spam' => (int) $row['spam'],
                'missing' => (int) $row['missing'],
                'pending' => (int) $row['pending'],
            ];
        }

        return $tally;
    }

    /** Operational data, so it purges — on the send, which is its only date. */
    public function purgeBefore(\DateTimeImmutable $cut): int
    {
        $statement = $this->pdo->prepare('DELETE FROM mail_seed_copies WHERE sent_at < ?');
        $statement->execute([$cut->format('Y-m-d H:i:s')]);

        return $statement->rowCount();
    }

    /**
     * @return list<SeedCopy>
     */
    private function hydrateAll(\PDOStatement $statement): array
    {
        $copies = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $copies[] = new SeedCopy(
                (int) $row['id'],
                (string) $row['run_reference'],
                $this->encryption->decrypt((string) $row['seed_address_encrypted'], self::CONTEXT),
                (string) $row['provider'],
                // **Never the raw constructor on a stored moment.** It
                // throws on a malformed string — one bad row and the page
                // is a 500 — and, worse, answers *now* for an empty one,
                // so a copy would silently claim to have been sent today
                // and re-enter every thirty-day window for ever.
                // `sent_at` is `NOT NULL`, hence the `require` form;
                // `recorded_at` is null until a landing is found.
                DateInput::requireFromStorage((string) $row['sent_at'], 'mail_seed_copies.sent_at'),
                SeedVerdict::from((string) $row['verdict']),
                $row['landed_folder'] === null ? null : (string) $row['landed_folder'],
                DateInput::fromStorage($row['recorded_at'] === null ? null : (string) $row['recorded_at'])
            );
        }

        return $copies;
    }

    private function blindIndex(string $address): string
    {
        return $this->encryption->blindIndex(
            EncryptionService::normalizeEmailForIndex($address),
            self::BLIND_INDEX_PURPOSE
        );
    }

    /** The driver's own code, never SQLSTATE 23000 alone (IT-06's lesson). */
    private static function isDuplicateKey(\PDOException $exception): bool
    {
        if (($exception->errorInfo[0] ?? '') !== '23000') {
            return false;
        }

        return in_array($exception->errorInfo[1] ?? null, [1062, 19, 7], true);
    }
}
