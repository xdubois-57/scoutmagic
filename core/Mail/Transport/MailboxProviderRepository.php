<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Transport;

/**
 * Which mailbox provider really hosts a recipient domain — the cache
 * behind `mail_domain_providers` (roadmap IT-07, issue #422).
 *
 * **This class never resolves anything**, and that is the whole reason it
 * lives in `Transport`: the send path reads it, and the send path must
 * never wait on a DNS query — a resolver that hangs would hold every
 * message of a mailing behind it. It reads what a scheduled task
 * (`Core\Mail\Feedback\Seed\Task\ResolveMailboxProvidersHandler`) found,
 * and at most writes down that a domain exists so that task knows to ask.
 * `Tests\Core\Mail\Transport\SendPathResolvesNothingTest` holds the line.
 *
 * **Domains, never addresses** (SECURITY.md §11). One row stands for every
 * family on that domain, and nothing here counts them or names them.
 */
class MailboxProviderRepository
{
    /**
     * How many domains may be noted.
     *
     * The cache is read whole into memory the first time a mailing asks
     * (below), so an unbounded one would turn a lookup into a payload. A
     * unit writes to a few hundred domains; past this ceiling a new domain
     * is simply not attributed, which is what happened to every domain
     * before this table existed.
     */
    public const MAXIMUM = 5000;

    /**
     * How stale a domain's `noted_at` may get before a send refreshes it.
     *
     * Monthly rather than on every send: the column only feeds a retention
     * counted in months, and a write per message would put a database
     * write on every mailing for a date nobody reads to the day.
     */
    public const NOTE_REFRESH_DAYS = 30;

    /**
     * The whole cache, read once per instance: domain => [provider, noted_at].
     *
     * Once, because the send path asks on every message of a mailing and
     * a query per message would cost more than the reordering it serves.
     * Stale within one process is harmless — the task resolves once a day.
     *
     * @var array<string, array{provider: ?string, noted_at: string}>|null
     */
    private ?array $known = null;

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * The provider this domain is known to be hosted by, or null when it
     * is not known — never resolved, resolved to nobody we recognise, or
     * never seen. **No lookup, ever**: a null here is a fallback to the
     * domain itself, which is what the site did before.
     */
    public function providerOf(string $domain): ?string
    {
        return $this->known()[strtolower(trim($domain))]['provider'] ?? null;
    }

    /**
     * Writes down that mail goes to this domain, so the task knows to ask
     * about it. A domain already known costs nothing but, once a month, a
     * date.
     *
     * @return bool whether a new domain was noted
     */
    public function note(string $domain, \DateTimeImmutable $now): bool
    {
        $domain = strtolower(trim($domain));
        if (!DomainPreferences::isPlausibleDomain($domain)) {
            return false;
        }

        $known = $this->known();
        if (isset($known[$domain])) {
            $refreshBefore = $now->modify('-' . self::NOTE_REFRESH_DAYS . ' days')->format('Y-m-d H:i:s');
            if ($known[$domain]['noted_at'] < $refreshBefore) {
                $statement = $this->pdo->prepare('UPDATE mail_domain_providers SET noted_at = ? WHERE domain = ?');
                $statement->execute([$now->format('Y-m-d H:i:s'), $domain]);
                $this->known[$domain]['noted_at'] = $now->format('Y-m-d H:i:s');
            }

            return false;
        }

        if (count($known) >= self::MAXIMUM) {
            return false;
        }

        $statement = $this->pdo->prepare('INSERT INTO mail_domain_providers (domain, noted_at) VALUES (?, ?)');
        try {
            $statement->execute([$domain, $now->format('Y-m-d H:i:s')]);
        } catch (\PDOException $failure) {
            // Another process noted it between our read and our write:
            // that is the outcome we wanted. Anything else is a real
            // refusal and the caller decides what it costs.
            if (!self::isDuplicateKey($failure)) {
                throw $failure;
            }
        }

        $this->known[$domain] = ['provider' => null, 'noted_at' => $now->format('Y-m-d H:i:s')];

        return true;
    }

    /**
     * The domains the task should ask about next: never resolved first,
     * then the stalest, and none still inside its back-off.
     *
     * @return list<array{domain: string, failures: int}>
     */
    public function due(\DateTimeImmutable $now, \DateTimeImmutable $staleBefore, int $limit): array
    {
        $statement = $this->pdo->prepare(
            'SELECT domain, failures FROM mail_domain_providers
              WHERE (resolved_at IS NULL OR resolved_at < :stale)
                AND (retry_after IS NULL OR retry_after <= :now)
              ORDER BY CASE WHEN resolved_at IS NULL THEN 0 ELSE 1 END, resolved_at ASC, noted_at ASC, id ASC
              LIMIT :limit'
        );
        $statement->bindValue(':stale', $staleBefore->format('Y-m-d H:i:s'));
        $statement->bindValue(':now', $now->format('Y-m-d H:i:s'));
        $statement->bindValue(':limit', max(1, $limit), \PDO::PARAM_INT);
        $statement->execute();

        $due = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $due[] = ['domain' => (string) $row['domain'], 'failures' => (int) $row['failures']];
        }

        return $due;
    }

    /** A lookup that answered: the provider it names, or null for nobody known. */
    public function recordResolved(string $domain, ?string $provider, \DateTimeImmutable $now): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE mail_domain_providers
                SET provider = ?, resolved_at = ?, failures = 0, last_error = NULL, retry_after = NULL
              WHERE domain = ?'
        );
        $statement->execute([$provider, $now->format('Y-m-d H:i:s'), $domain]);
        $this->known = null;
    }

    /**
     * A lookup that could not be made. **The last good answer stays**: a
     * resolver that is down today says nothing about where the domain's
     * mail went yesterday, and forgetting it would move that domain's
     * results back under its own name for as long as the outage lasts.
     */
    public function recordFailure(
        string $domain,
        string $code,
        \DateTimeImmutable $retryAfter
    ): void {
        $statement = $this->pdo->prepare(
            'UPDATE mail_domain_providers
                SET failures = failures + 1, last_error = ?, retry_after = ?
              WHERE domain = ?'
        );
        $statement->execute([substr($code, 0, 32), $retryAfter->format('Y-m-d H:i:s'), $domain]);
    }

    /**
     * How many domains are counted under a provider other than their own
     * name — the personal domains the MX records moved. A count, for a
     * screen: which domains they are is nobody's business but the send
     * path's.
     */
    public function countAttributed(): int
    {
        $statement = $this->pdo->query(
            'SELECT COUNT(*) FROM mail_domain_providers WHERE provider IS NOT NULL AND provider <> domain'
        );

        return $statement === false ? 0 : (int) $statement->fetchColumn();
    }

    /** A domain nobody has written to since the cut is forgotten. */
    public function purgeNotedBefore(\DateTimeImmutable $cut): int
    {
        $statement = $this->pdo->prepare('DELETE FROM mail_domain_providers WHERE noted_at < ?');
        $statement->execute([$cut->format('Y-m-d H:i:s')]);
        $this->known = null;

        return $statement->rowCount();
    }

    /** @return array<string, array{provider: ?string, noted_at: string}> */
    private function known(): array
    {
        if ($this->known !== null) {
            return $this->known;
        }

        $known = [];
        $statement = $this->pdo->query('SELECT domain, provider, noted_at FROM mail_domain_providers');
        foreach ($statement === false ? [] : $statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $known[(string) $row['domain']] = [
                'provider' => $row['provider'] === null ? null : (string) $row['provider'],
                'noted_at' => (string) $row['noted_at'],
            ];
        }

        return $this->known = $known;
    }

    /** MySQL/MariaDB 1062, SQLite 19 — the same test `SeedCopyRepository` uses. */
    private static function isDuplicateKey(\PDOException $exception): bool
    {
        if (($exception->errorInfo[0] ?? '') !== '23000') {
            return false;
        }

        return in_array($exception->errorInfo[1] ?? null, [1062, 19, 7], true);
    }
}
