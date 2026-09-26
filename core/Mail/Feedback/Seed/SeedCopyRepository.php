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

    /**
     * Its own purpose again, and for the same reason: what tags a run
     * reference on a header is never compared with what indexes an
     * address, so the two keys stay separate.
     */
    private const STAMP_PURPOSE = 'seed_run_stamp';

    /**
     * The provider a copy is COUNTED under: the one the MX records of its
     * box's domain named (issue #422), or the domain itself.
     *
     * **Folded when read, never when written.** `provider` keeps the
     * box's own domain, so a box first measured before its domain was
     * resolved does not leave its early results in a column of their own:
     * the day the attribution arrives, its whole history moves with it.
     * And an attribution that later changes — a unit moving its mail to
     * another host — moves the history too, which is right: the column
     * says who filters that box, and the site only ever knows the latest
     * answer.
     */
    private const ATTRIBUTED = 'COALESCE(d.provider, c.provider)';

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
     *
     * **`missing` is not one of those answers, and it costs a body to
     * treat it as one.** `markMissingBefore()` does not observe anything;
     * it gives up after two days. A copy the provider held in a queue,
     * or a box polled on a slow schedule, can therefore be found AFTER
     * the sweep has already written « jamais arrivé » on it — and the
     * first version refused that arrival, which cascaded: the write
     * answered false, so {@see SeedConsumer} did not recognise the
     * message, so it was neither deleted from the box nor kept out of
     * `inbound_messages` — leaving the **full mailing body**, members'
     * data included, in the message table that the privacy notice
     * promises it is not in. The verdict stayed `missing` on top, feeding
     * the routing a failure that never happened.
     *
     * So an observation overrides a surrender, and only a surrender: a
     * row already saying `inbox`, `spam` or `elsewhere` was read off a
     * real folder and is left exactly as it was.
     */
    public function recordLanding(
        string $runReference,
        string $address,
        string $folder,
        \DateTimeImmutable $now
    ): bool {
        $landed = SeedVerdict::fromFolder($folder);
        // **A folder that says nothing is not a landing.** `fromFolder()`
        // answers `Pending` for « the relay did not say », and writing
        // that down would stamp `recorded_at` on the row — which is the
        // guard `markMissingBefore()` reads, so the copy would be neither
        // found nor ever given up on: `pending` for ever, in a column the
        // screen shows as « en attente ».
        if ($landed === SeedVerdict::Pending) {
            return false;
        }

        $statement = $this->pdo->prepare(
            'UPDATE mail_seed_copies
                SET verdict = ?, landed_folder = ?, recorded_at = ?
              WHERE run_reference = ? AND seed_address_blind_index = ?
                AND verdict IN (?, ?)'
        );
        $statement->execute([
            $landed->value,
            $folder,
            $now->format('Y-m-d H:i:s'),
            $runReference,
            $this->blindIndex($address),
            SeedVerdict::Pending->value,
            SeedVerdict::Missing->value,
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
        // **`recorded_at IS NULL` is the guard that matters**, and it is
        // belt to the braces of `SeedVerdict::fromFolder()` never
        // answering `Pending` for a folder it was actually told. A row
        // whose landing WAS written down is a copy that demonstrably
        // arrived; flipping it to « jamais arrivé » because its verdict
        // happened to read `pending` is how this screen showed the
        // gravest badge it has beside the folder name the copy was found
        // in — and fed that fabricated `missing` to the routing.
        $statement = $this->pdo->prepare(
            'UPDATE mail_seed_copies
                SET verdict = ?, recorded_at = ?
              WHERE verdict = ? AND recorded_at IS NULL AND sent_at < ?'
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
        // **The inner query is wrapped in a derived table, and that is not
        // a style choice.** MySQL refuses `LIMIT` directly inside
        // `IN (SELECT …)` — « This version of MySQL doesn't yet support
        // 'LIMIT & IN/ALL/ANY/SOME subquery' », error 1235 — while SQLite
        // and MariaDB both accept it. So the first version passed every
        // local run and both database jobs, and would have thrown a
        // `PDOException` the first time this page was opened on the one
        // engine a production site is most likely to be running. The
        // extra `SELECT` around it is what makes the subquery a derived
        // table, which MySQL does allow.
        $statement = $this->pdo->prepare(
            'SELECT c.*, ' . self::ATTRIBUTED . ' AS attributed_provider
               FROM mail_seed_copies c
               LEFT JOIN mail_domain_providers d ON d.domain = c.provider
              WHERE c.run_reference IN (
                    SELECT run_reference FROM (
                        SELECT run_reference, MAX(sent_at) AS latest_sent_at
                          FROM mail_seed_copies
                         WHERE sent_at >= :since
                         GROUP BY run_reference
                         ORDER BY latest_sent_at DESC
                         LIMIT :limit
                    ) AS recent_runs
              )
              ORDER BY c.sent_at DESC, attributed_provider ASC, c.id ASC'
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
     * @return list<array{provider: string, runs: int, inbox: int, spam: int, missing: int,
     *     elsewhere: int, pending: int}>
     */
    public function tallyByProviderSince(\DateTimeImmutable $since): array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::ATTRIBUTED . ' AS provider,
                    COUNT(DISTINCT CASE WHEN c.verdict <> \'pending\' THEN c.run_reference END) AS runs,
                    SUM(CASE WHEN c.verdict = \'inbox\'   THEN 1 ELSE 0 END) AS inbox,
                    SUM(CASE WHEN c.verdict = \'spam\'    THEN 1 ELSE 0 END) AS spam,
                    SUM(CASE WHEN c.verdict = \'missing\' THEN 1 ELSE 0 END) AS missing,
                    SUM(CASE WHEN c.verdict = \'elsewhere\' THEN 1 ELSE 0 END) AS elsewhere,
                    SUM(CASE WHEN c.verdict = \'pending\' THEN 1 ELSE 0 END) AS pending
               FROM mail_seed_copies c
               LEFT JOIN mail_domain_providers d ON d.domain = c.provider
              WHERE c.sent_at >= :since
              GROUP BY ' . self::ATTRIBUTED . '
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
                'elsewhere' => (int) $row['elsewhere'],
                'pending' => (int) $row['pending'],
            ];
        }

        return $tally;
    }

    /**
     * The domains of the boxes that have been measured, so the MX task
     * attributes them too (issue #422). The stored column, never the
     * attributed one: this is the question, not the answer.
     *
     * @return list<string>
     */
    public function measuredDomains(): array
    {
        $statement = $this->pdo->query('SELECT DISTINCT provider FROM mail_seed_copies ORDER BY provider');

        return $statement === false ? [] : array_map('strval', $statement->fetchAll(\PDO::FETCH_COLUMN));
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
                (string) ($row['attributed_provider'] ?? $row['provider']),
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

    /**
     * The value the header actually carries: the run reference plus a
     * keyed tag of it.
     *
     * **A bare run reference on that header is forgeable, and the boxes
     * are not secret.** A reference is `mass_mail:<id>` — a plain
     * auto-increment — and a seed box is an ordinary mailbox whose
     * address anyone may learn. Without this, anybody able to send mail
     * to one could stamp `X-ScoutMagic-Seed: mass_mail:7`, land in
     * whichever folder they like, and have the site record that verdict
     * for a real mailing: `recordLanding()`'s `verdict = 'pending'` guard
     * makes it first-writer-wins, and the forgery arrives before the real
     * copy is polled. With automatic routing on, enough of those move a
     * whole provider's traffic on fabricated evidence.
     *
     * The tag is `EncryptionService::blindIndex()` under its own purpose,
     * so it is keyed by this installation's secret and cannot be computed
     * by anybody who does not hold it. Truncated to 32 hex characters:
     * 128 bits, which is not brute-forceable, and a header that stays a
     * header.
     */
    public function stamp(string $runReference): string
    {
        return $runReference . '.' . substr(
            $this->encryption->blindIndex($runReference, self::STAMP_PURPOSE),
            0,
            32
        );
    }

    /**
     * The run reference a stamp vouches for, or null when it vouches for
     * nothing.
     *
     * Compared with `hash_equals()`: a timing oracle on this would let
     * somebody recover a valid tag one character at a time, and the
     * comparison costs nothing.
     */
    public function referenceFromStamp(string $stamp): ?string
    {
        // From the LAST dot: a run reference may contain one, and
        // splitting from the first would hand the tag a truncated
        // reference to vouch for.
        $cut = strrpos($stamp, '.');
        if ($cut === false || $cut === 0) {
            return null;
        }

        $reference = substr($stamp, 0, $cut);

        return hash_equals($this->stamp($reference), $stamp) ? $reference : null;
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
