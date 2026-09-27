<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Probe;

use Core\Mail\Feedback\Bounce\BounceCategory;
use Core\Mail\Transport\MailLane;
use Core\Security\EncryptionService;
use Core\Service\DateInput;
use PDO;

/**
 * The probe history — one row per message sent, kept (roadmap IT-04).
 *
 * **A history, not a state.** `mail_return_probes` keeps one row per
 * address because the only useful answer there is the latest one. Here it
 * is the opposite: the roadmap asks for this table precisely so that
 * somebody three months from now does not re-run a test they already ran
 * and forgot the result of. Two lines — same recipient, « réception » via
 * one relay and « indésirables » via another — settle a question that no
 * amount of explaining settles.
 *
 * The destination is encrypted, and deliberately **without a blind
 * index**. Nothing here looks a probe up by address: the screen lists the
 * latest runs and the verdict is recorded by id. A blind index exists to
 * make an encrypted column searchable, and one added « in case » is a
 * deterministic fingerprint of an address kept for no question anybody
 * asks (AGENTS.md point 3, SECURITY.md §11).
 */
final class MailProbeRepository
{
    private const CONTEXT = 'mail_probes.destination';

    /**
     * How many runs the screen shows.
     *
     * Enough for the comparison the history exists for — a handful of
     * relays against a handful of destinations — and short enough that
     * the page stays one glance rather than an archive nobody scrolls.
     */
    public const RECENT_LIMIT = 30;

    public function __construct(
        private PDO $pdo,
        private EncryptionService $encryption
    ) {
    }

    /**
     * Write down a probe that has just left.
     *
     * Called AFTER the send, never before: a row for a message that never
     * went out would sit in the history for ever waiting for a verdict
     * nobody can give, and « jamais reçu » would then mean two different
     * things in the same column.
     *
     * @return int the probe's id
     */
    public function record(
        string $code,
        string $destination,
        ?int $providerId,
        string $providerName,
        MailLane $lane,
        \DateTimeImmutable $sentAt
    ): int {
        $statement = $this->pdo->prepare(
            'INSERT INTO mail_probes
                 (code, destination_encrypted, provider_id, provider_name, lane, sent_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $code,
            $this->encryption->encrypt($destination, self::CONTEXT),
            $providerId,
            $providerName,
            $lane->value,
            $sentAt->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Record where it landed.
     *
     * `WHERE verdict IS NULL` so a second press of a verdict button — a
     * double click, a page reopened from history — cannot overwrite the
     * answer already given. The first answer is the one the operator gave
     * while looking at the mailbox.
     *
     * @return bool whether this call is the one that wrote the verdict
     */
    public function recordVerdict(int $id, MailProbeVerdict $verdict, \DateTimeImmutable $at): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE mail_probes SET verdict = ?, verdict_at = ? WHERE id = ? AND verdict IS NULL'
        );
        $statement->execute([$verdict->value, $at->format('Y-m-d H:i:s'), $id]);

        return $statement->rowCount() === 1;
    }

    /**
     * The probe a bounce quoted, by the code in its subject.
     *
     * **Newest first, because a code can come round again.**
     * MailProbeSender::generateCode() draws six characters from an alphabet
     * of 32 — a billion codes, so a repeat is unlikely rather than
     * impossible, and the history is kept for years. If one ever repeats,
     * the bounce belongs to the run that was still in flight, which is the
     * most recent one; the alternative is attaching a rejection to a probe
     * somebody answered two years ago.
     */
    public function findByCode(string $code): ?MailProbe
    {
        $statement = $this->pdo->prepare($this->selectClause() . ' WHERE code = ? ORDER BY id DESC LIMIT 1');
        $statement->execute([$code]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * Attach what the far end said, once.
     *
     * `AND bounce_at IS NULL` for the same reason as recordVerdict()'s own
     * guard: the first rejection is the one that explains the probe's fate,
     * and a mailbox that keeps bouncing would otherwise rewrite the line
     * every time somebody else's message fails — the row would then carry
     * the date of the last unrelated bounce.
     *
     * The category is stored by its value and not its label: the label is
     * what a screen says today, and storing it would freeze one version's
     * wording into the history.
     *
     * @return bool whether this call is the one that wrote the attachment
     */
    public function recordBounce(
        int $id,
        BounceCategory $category,
        string $statusCode,
        \DateTimeImmutable $at
    ): bool {
        $statement = $this->pdo->prepare(
            'UPDATE mail_probes SET bounce_category = ?, bounce_status_code = ?, bounce_at = ?
              WHERE id = ? AND bounce_at IS NULL'
        );
        $statement->execute([$category->value, $statusCode, $at->format('Y-m-d H:i:s'), $id]);

        return $statement->rowCount() === 1;
    }

    public function find(int $id): ?MailProbe
    {
        $statement = $this->pdo->prepare($this->selectClause() . ' WHERE id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * The probes still waiting for somebody to say where they landed,
     * newest first.
     *
     * Plural on purpose: an operator testing two relays against the same
     * address sends both, then goes and looks once. A screen that could
     * only hold one pending probe would make them do it twice, in a
     * particular order, for no reason.
     *
     * @return list<MailProbe>
     */
    public function pending(): array
    {
        $statement = $this->pdo->prepare($this->selectClause() . ' WHERE verdict IS NULL ORDER BY id DESC');
        $statement->execute();

        return $this->hydrateAll($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * The history, newest first.
     *
     * @return list<MailProbe>
     */
    public function recent(int $limit = self::RECENT_LIMIT): array
    {
        // Bound, not concatenated. The value is an int this class
        // controls, so there is no live injection here — but « prepared
        // unless the value looked safe » is the rule that eventually
        // meets a value somebody else chose, and the repository already
        // binds its limits this way (Core\Notification\
        // NotificationRepository, Core\Scheduler\SchedulerRepository).
        $statement = $this->pdo->prepare($this->selectClause() . ' ORDER BY id DESC LIMIT ?');
        $statement->bindValue(1, max(1, $limit), PDO::PARAM_INT);
        $statement->execute();

        return $this->hydrateAll($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** How many runs are on file, whatever the screen shows of them. */
    public function count(): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM mail_probes');
        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    private function selectClause(): string
    {
        return 'SELECT id, code, destination_encrypted, provider_id, provider_name, lane, sent_at, '
            . 'verdict, verdict_at, bounce_category, bounce_status_code, bounce_at FROM mail_probes';
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return list<MailProbe>
     */
    private function hydrateAll(array $rows): array
    {
        $probes = [];
        foreach ($rows as $row) {
            $probes[] = $this->hydrate($row);
        }

        return $probes;
    }

    /**
     * The attachment, or null when nothing was ever traced to this probe.
     *
     * Keyed on `bounce_at`, not on the category: the date is what says an
     * attachment happened, and a category this version no longer knows must
     * not turn a recorded rejection back into « rien n'est revenu ».
     *
     * @param array<string, mixed> $row
     */
    private function hydrateBounce(array $row): ?ProbeBounce
    {
        $at = DateInput::fromStorage(isset($row['bounce_at']) ? (string) $row['bounce_at'] : null);
        if ($at === null) {
            return null;
        }

        return new ProbeBounce(
            category: isset($row['bounce_category'])
                ? BounceCategory::tryFrom((string) $row['bounce_category'])
                : null,
            statusCode: (string) ($row['bounce_status_code'] ?? ''),
            at: $at
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): MailProbe
    {
        $verdict = $row['verdict'] === null ? null : MailProbeVerdict::tryFrom((string) $row['verdict']);

        return new MailProbe(
            id: (int) $row['id'],
            code: (string) $row['code'],
            destination: $this->encryption->decrypt((string) $row['destination_encrypted'], self::CONTEXT),
            providerId: $row['provider_id'] === null ? null : (int) $row['provider_id'],
            providerName: (string) $row['provider_name'],
            // A lane this version no longer knows is not a reason to lose
            // the line: the destination, the relay and the verdict are
            // what the history is read for.
            lane: MailLane::tryFrom((string) $row['lane']) ?? MailLane::Bulk,
            sentAt: DateInput::requireFromStorage((string) $row['sent_at'], 'mail_probes.sent_at'),
            verdict: $verdict,
            verdictAt: DateInput::fromStorage(
                isset($row['verdict_at']) ? (string) $row['verdict_at'] : null
            ),
            bounce: $this->hydrateBounce($row)
        );
    }
}
