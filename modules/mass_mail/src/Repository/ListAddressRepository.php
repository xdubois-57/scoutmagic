<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\MassMail\Repository;

use Core\Security\EncryptionService;

/**
 * mass_mail_list_addresses — the addresses a custom list carries of its
 * own, for people who are nobody in the members table.
 *
 * **The only place the name and the address are encrypted or decrypted**
 * (SECURITY.md §5, AGENTS.md § Security checklist point 3). Nothing above
 * this class ever sees a ciphertext, and nothing below it ever sees a
 * plaintext leaving.
 *
 * There is no `ORDER BY` and no `LIKE` here, and there cannot be: the
 * columns are ciphertext. That is the same constraint
 * `Core\Member\Service\MemberSearchService` documents and answers the same
 * way — read the set, decrypt it in PHP, sort and filter there, and bound
 * how large the set may get (the `mass_mail_list_addresses_max` setting).
 * The one thing that IS answerable in SQL is a count, which is why
 * countForList() exists: a list that is nothing but criteria never pays
 * the decryption cost of a screen it does not use.
 */
class ListAddressRepository
{
    /** The blind-index purpose. Its own, never reused from another column's. */
    private const BLIND_INDEX_PURPOSE = 'mass_mail_list_address_email';

    private const ENCRYPTION_CONTEXT_NAME = 'mass_mail_list_addresses.name';
    private const ENCRYPTION_CONTEXT_EMAIL = 'mass_mail_list_addresses.email';

    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption
    ) {
    }

    /**
     * How many addresses a list holds, and how many of them are
     * unsubscribed — a plain COUNT, so the collapsed section on the
     * criteria page states its own size without decrypting anything.
     *
     * @return array{total: int, unsubscribed: int}
     */
    public function countForList(int $listId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) AS total, SUM(CASE WHEN unsubscribed_at IS NULL THEN 0 ELSE 1 END) AS unsubscribed
             FROM mass_mail_list_addresses WHERE list_id = ?'
        );
        $stmt->execute([$listId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return [
            'total' => (int) ($row['total'] ?? 0),
            'unsubscribed' => (int) ($row['unsubscribed'] ?? 0),
        ];
    }

    /**
     * Every address of a list, decrypted, sorted in PHP by name then
     * address — a single call, because the screen loads the whole set once
     * and does its searching and paging in the browser afterwards.
     *
     * @return ListAddress[]
     */
    public function findForList(int $listId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM mass_mail_list_addresses WHERE list_id = ?');
        $stmt->execute([$listId]);

        $addresses = array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
        usort($addresses, static function (ListAddress $a, ListAddress $b): int {
            return [mb_strtolower($a->name ?? ''), mb_strtolower($a->email)]
                <=> [mb_strtolower($b->name ?? ''), mb_strtolower($b->email)];
        });

        return $addresses;
    }

    /**
     * The addresses of a list that may actually be written to — the
     * unsubscribed ones are excluded here rather than filtered by the
     * caller, so no future caller can forget.
     *
     * @return ListAddress[]
     */
    public function findActiveForList(int $listId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM mass_mail_list_addresses WHERE list_id = ? AND unsubscribed_at IS NULL'
        );
        $stmt->execute([$listId]);

        return array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function findById(int $id): ?ListAddress
    {
        $stmt = $this->pdo->prepare('SELECT * FROM mass_mail_list_addresses WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? $this->hydrate($row) : null;
    }

    /**
     * Whether this list already carries this address — asked on the blind
     * index, the only comparison the ciphertext allows.
     */
    public function existsInList(int $listId, string $email, ?int $exceptId = null): bool
    {
        $sql = 'SELECT 1 FROM mass_mail_list_addresses WHERE list_id = ? AND email_blind_index = ?';
        $parameters = [$listId, $this->blindIndex($email)];
        if ($exceptId !== null) {
            $sql .= ' AND id <> ?';
            $parameters[] = $exceptId;
        }

        $stmt = $this->pdo->prepare($sql . ' LIMIT 1');
        $stmt->execute($parameters);

        return $stmt->fetchColumn() !== false;
    }

    public function create(int $listId, ?string $name, string $email): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO mass_mail_list_addresses (list_id, name_encrypted, email_encrypted, email_blind_index)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            $listId,
            $name !== null && $name !== '' ? $this->encryption->encrypt($name, self::ENCRYPTION_CONTEXT_NAME) : null,
            $this->encryption->encrypt($email, self::ENCRYPTION_CONTEXT_EMAIL),
            $this->blindIndex($email),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, ?string $name, string $email): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE mass_mail_list_addresses
             SET name_encrypted = ?, email_encrypted = ?, email_blind_index = ?
             WHERE id = ?'
        );
        $stmt->execute([
            $name !== null && $name !== '' ? $this->encryption->encrypt($name, self::ENCRYPTION_CONTEXT_NAME) : null,
            $this->encryption->encrypt($email, self::ENCRYPTION_CONTEXT_EMAIL),
            $this->blindIndex($email),
            $id,
        ]);
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM mass_mail_list_addresses WHERE id = ?')->execute([$id]);
    }

    /**
     * The unsubscribe, and the reason the blind index carries an index of
     * its own: **every** row holding this address, in **every** list, in
     * one statement. Somebody who asks that the unit stop writing to them
     * is addressing the unit, not a list — and this creates no shared
     * entity, being a WHERE clause rather than a table.
     *
     * Idempotent, and it never re-stamps a row that is already
     * unsubscribed: the date recorded is the day the person asked, not the
     * day a mailbox prefetched the link a second time.
     *
     * @return int how many rows were newly unsubscribed
     */
    public function unsubscribeEverywhere(string $email): int
    {
        $stmt = $this->pdo->prepare(
            'UPDATE mass_mail_list_addresses SET unsubscribed_at = ?
             WHERE email_blind_index = ? AND unsubscribed_at IS NULL'
        );
        $stmt->execute([(new \DateTimeImmutable())->format('Y-m-d H:i:s'), $this->blindIndex($email)]);

        return $stmt->rowCount();
    }

    /**
     * Replaces a list's addresses wholesale — what the Excel round trip
     * needs, and the one operation of this module with no way back.
     *
     * **An unsubscribed row is never touched.** It is neither deleted for
     * being absent from the file nor re-subscribed for being present in
     * it: an unsubscribe a spreadsheet can undo is not an unsubscribe.
     * Everything else in the list that the file does not name is deleted.
     *
     * @param array<int, array{name: ?string, email: string}> $addresses
     * @return array{added: int, unchanged: int, removed: int, kept_unsubscribed: int}
     */
    public function replaceForList(int $listId, array $addresses): array
    {
        // One transaction, because this is the one operation of this
        // module with no way back: N inserts followed by M deletes, and a
        // failure between the two halves would leave the list holding
        // both what the file brought AND what it removed — over the cap
        // that was just checked, and with no journal entry, since the
        // caller only logs once this returns. Same shape as
        // Modules\Attestations\Service\BatchResetService.
        $this->pdo->beginTransaction();
        try {
            $summary = $this->replaceForListInTransaction($listId, $addresses);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }

        return $summary;
    }

    /**
     * @param array<int, array{name: ?string, email: string}> $addresses
     * @return array{added: int, unchanged: int, removed: int, kept_unsubscribed: int}
     */
    private function replaceForListInTransaction(int $listId, array $addresses): array
    {
        $existing = [];
        foreach ($this->findForList($listId) as $address) {
            $existing[$this->blindIndex($address->email)] = $address;
        }

        $seen = [];
        $added = 0;
        $unchanged = 0;

        foreach ($addresses as $address) {
            $index = $this->blindIndex($address['email']);
            if (isset($seen[$index])) {
                continue;
            }
            $seen[$index] = true;

            $current = $existing[$index] ?? null;
            if ($current === null) {
                $this->create($listId, $address['name'], $address['email']);
                $added++;
                continue;
            }

            $unchanged++;
            // A name may be corrected in the file; an unsubscribed row's
            // may not, since nothing about it is editable any more.
            if (!$current->isUnsubscribed() && ($current->name ?? '') !== ($address['name'] ?? '')) {
                $this->update($current->id, $address['name'], $address['email']);
            }
        }

        $removed = 0;
        $keptUnsubscribed = 0;
        foreach ($existing as $index => $address) {
            if (isset($seen[$index])) {
                continue;
            }
            if ($address->isUnsubscribed()) {
                $keptUnsubscribed++;
                continue;
            }
            $this->delete($address->id);
            $removed++;
        }

        return [
            'added' => $added,
            'unchanged' => $unchanged,
            'removed' => $removed,
            'kept_unsubscribed' => $keptUnsubscribed,
        ];
    }

    /**
     * How many of this list's unsubscribed rows the given file does NOT
     * carry — the only ones a wholesale replacement adds to what the file
     * brings, since a row the file names again is kept in place rather
     * than duplicated.
     *
     * Compared on the blind index and never decrypted: the answer is a
     * count, and `export()` writes the unsubscribed rows into the file in
     * the first place, so re-importing an untouched export must not read
     * as the list growing.
     *
     * @param string[] $emails the addresses the file carries
     */
    public function countUnsubscribedNotIn(int $listId, array $emails): int
    {
        $carried = [];
        foreach ($emails as $email) {
            $carried[$this->blindIndex($email)] = true;
        }

        $stmt = $this->pdo->prepare(
            'SELECT email_blind_index FROM mass_mail_list_addresses
             WHERE list_id = ? AND unsubscribed_at IS NOT NULL'
        );
        $stmt->execute([$listId]);

        $count = 0;
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $index) {
            if (!isset($carried[(string) $index])) {
                $count++;
            }
        }

        return $count;
    }

    private function blindIndex(string $email): string
    {
        return $this->encryption->blindIndex(mb_strtolower(trim($email)), self::BLIND_INDEX_PURPOSE);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): ListAddress
    {
        return new ListAddress(
            id: (int) $row['id'],
            listId: (int) $row['list_id'],
            name: $row['name_encrypted'] !== null
                ? $this->encryption->decrypt($row['name_encrypted'], self::ENCRYPTION_CONTEXT_NAME)
                : null,
            email: $this->encryption->decrypt($row['email_encrypted'], self::ENCRYPTION_CONTEXT_EMAIL),
            unsubscribedAt: $row['unsubscribed_at'] !== null ? (string) $row['unsubscribed_at'] : null,
            createdAt: (string) $row['created_at']
        );
    }
}
