<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\MassMail\Repository;

use Core\Security\EncryptionService;

/**
 * mass_mail_block_contacts — the contacts of a contact group, people who
 * are nobody in the members table.
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
 * how large the set may get (the `mass_mail_list_addresses_max` setting,
 * per group). What IS answerable in SQL is a count, on the blind index,
 * which is why the count methods exist: a page that only says how many
 * contacts a list carries never pays the decryption of a screen it does
 * not show.
 *
 * A group's contacts are written by one operation only, the wholesale
 * replacement from a file (replaceForBlock()), plus the unsubscribe.
 */
class ContactRepository
{
    /** The blind-index purpose. Its own, never reused from another column's. */
    private const BLIND_INDEX_PURPOSE = 'mass_mail_block_contact_email';

    private const ENCRYPTION_CONTEXT_NAME = 'mass_mail_block_contacts.name';
    private const ENCRYPTION_CONTEXT_EMAIL = 'mass_mail_block_contacts.email';

    /** Contacts and unsubscribed contacts of one block — see countByBlock(). */
    private const COUNT_BY_BLOCK_FOR_BLOCK =
        'SELECT c.block_id, COUNT(*) AS total,
                SUM(CASE WHEN c.unsubscribed_at IS NULL THEN 0 ELSE 1 END) AS unsubscribed
         FROM mass_mail_block_contacts c
         WHERE c.block_id = ? GROUP BY c.block_id';

    /** The same, per block, for every block of one list. */
    private const COUNT_BY_BLOCK_FOR_LIST =
        'SELECT c.block_id, COUNT(*) AS total,
                SUM(CASE WHEN c.unsubscribed_at IS NULL THEN 0 ELSE 1 END) AS unsubscribed
         FROM mass_mail_block_contacts c
         JOIN mass_mail_list_blocks b ON b.id = c.block_id
         WHERE b.list_id = ? GROUP BY c.block_id';

    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption
    ) {
    }

    /**
     * How many contacts a group holds, and how many of them are
     * unsubscribed — a plain COUNT, decrypting nothing.
     *
     * @return array{total: int, unsubscribed: int}
     */
    public function countForBlock(int $blockId): array
    {
        return $this->countByBlock(self::COUNT_BY_BLOCK_FOR_BLOCK, [$blockId])[$blockId]
            ?? ['total' => 0, 'unsubscribed' => 0];
    }

    /**
     * The same two counts for every contact group of a list, in one
     * query — the list's page prints one line per group.
     *
     * @return array<int, array{total: int, unsubscribed: int}> block id => counts
     */
    public function countByBlockForList(int $listId): array
    {
        return $this->countByBlock(self::COUNT_BY_BLOCK_FOR_LIST, [$listId]);
    }

    /**
     * How many DISTINCT contacts the list's contact groups hold together,
     * and how many of those are unsubscribed — counted on the blind index,
     * so an address carried by two groups is one contact, the way the
     * send writes to it once. The unsubscribe being global, an address is
     * unsubscribed in every group or in none.
     *
     * @return array{total: int, unsubscribed: int}
     */
    public function countDistinctForList(int $listId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(DISTINCT c.email_blind_index) AS total,
                    COUNT(DISTINCT CASE WHEN c.unsubscribed_at IS NULL THEN NULL ELSE c.email_blind_index END)
                        AS unsubscribed
             FROM mass_mail_block_contacts c
             JOIN mass_mail_list_blocks b ON b.id = c.block_id
             WHERE b.list_id = ?'
        );
        $stmt->execute([$listId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return [
            'total' => (int) ($row['total'] ?? 0),
            'unsubscribed' => (int) ($row['unsubscribed'] ?? 0),
        ];
    }

    /**
     * Every contact of a group, decrypted, sorted in PHP by name then
     * address — a single call, because the screen loads the whole set once
     * and does its searching and paging in the browser afterwards.
     *
     * @return Contact[]
     */
    public function findForBlock(int $blockId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM mass_mail_block_contacts WHERE block_id = ?');
        $stmt->execute([$blockId]);

        return $this->sorted(array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC)));
    }

    /**
     * The contacts that may actually be written to, across every contact
     * group of a list — what the list's contact half resolves to. The
     * unsubscribed ones are excluded here rather than filtered by the
     * caller, so no future caller can forget. An address carried by two
     * groups comes back twice, each with its own block id: the caller is
     * the one that knows which blocks bring whom.
     *
     * @return Contact[]
     */
    public function findActiveForList(int $listId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.* FROM mass_mail_block_contacts c
             JOIN mass_mail_list_blocks b ON b.id = c.block_id
             WHERE b.list_id = ? AND c.unsubscribed_at IS NULL'
        );
        $stmt->execute([$listId]);

        return $this->sorted(array_map([$this, 'hydrate'], $stmt->fetchAll(\PDO::FETCH_ASSOC)));
    }

    public function create(int $blockId, ?string $name, string $email): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO mass_mail_block_contacts (block_id, name_encrypted, email_encrypted, email_blind_index)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            $blockId,
            $name !== null && $name !== '' ? $this->encryption->encrypt($name, self::ENCRYPTION_CONTEXT_NAME) : null,
            $this->encryption->encrypt($email, self::ENCRYPTION_CONTEXT_EMAIL),
            $this->blindIndex($email),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function reactivateEverywhere(string $email): int
    {
        $stmt = $this->pdo->prepare(
            'UPDATE mass_mail_block_contacts SET unsubscribed_at = NULL
             WHERE email_blind_index = ? AND unsubscribed_at IS NOT NULL'
        );
        $stmt->execute([$this->blindIndex($email)]);
        return $stmt->rowCount();
    }

    /**
     * The unsubscribe, and the reason the blind index carries an index of
     * its own: **every** row holding this address, in **every** group of
     * every list, in one statement. Somebody who asks that the unit stop
     * writing to them is addressing the unit, not a list — and this
     * creates no shared entity, being a WHERE clause rather than a table.
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
            'UPDATE mass_mail_block_contacts SET unsubscribed_at = ?
             WHERE email_blind_index = ? AND unsubscribed_at IS NULL'
        );
        $stmt->execute([(new \DateTimeImmutable())->format('Y-m-d H:i:s'), $this->blindIndex($email)]);

        return $stmt->rowCount();
    }

    /**
     * Replaces a group's contacts wholesale — what the Excel round trip
     * needs, and the one operation of this module with no way back.
     *
     * **An unsubscribed row is never touched.** It is neither deleted for
     * being absent from the file nor re-subscribed for being present in
     * it: an unsubscribe a spreadsheet can undo is not an unsubscribe.
     * Everything else in the group that the file does not name is deleted.
     *
     * @param array<int, array{name: ?string, email: string}> $contacts
     * @return array{added: int, unchanged: int, removed: int, kept_unsubscribed: int}
     */
    public function replaceForBlock(int $blockId, array $contacts): array
    {
        // One transaction, because this is the one operation of this
        // module with no way back: N inserts followed by M deletes, and a
        // failure between the two halves would leave the group holding
        // both what the file brought AND what it removed — over the cap
        // that was just checked, and with no journal entry, since the
        // caller only logs once this returns. Same shape as
        // Modules\Attestations\Service\BatchResetService. Joined rather
        // than opened when the caller already holds one: creating a group
        // is the block and its contacts, together.
        if ($this->pdo->inTransaction()) {
            return $this->replaceForBlockInTransaction($blockId, $contacts);
        }

        $this->pdo->beginTransaction();
        try {
            $summary = $this->replaceForBlockInTransaction($blockId, $contacts);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }

        return $summary;
    }

    /**
     * How many of this group's unsubscribed rows the given file does NOT
     * carry — the only ones a wholesale replacement adds to what the file
     * brings, since a row the file names again is kept in place rather
     * than duplicated.
     *
     * Compared on the blind index and never decrypted: the answer is a
     * count, and the export writes the unsubscribed rows into the file in
     * the first place, so re-importing an untouched export must not read
     * as the group growing.
     *
     * @param string[] $emails the addresses the file carries
     */
    public function countUnsubscribedNotIn(int $blockId, array $emails): int
    {
        $carried = [];
        foreach ($emails as $email) {
            $carried[$this->blindIndex($email)] = true;
        }

        $stmt = $this->pdo->prepare(
            'SELECT email_blind_index FROM mass_mail_block_contacts
             WHERE block_id = ? AND unsubscribed_at IS NOT NULL'
        );
        $stmt->execute([$blockId]);

        $count = 0;
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $index) {
            if (!isset($carried[(string) $index])) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param array<int, array{name: ?string, email: string}> $contacts
     * @return array{added: int, unchanged: int, removed: int, kept_unsubscribed: int}
     */
    private function replaceForBlockInTransaction(int $blockId, array $contacts): array
    {
        $existing = [];
        foreach ($this->findForBlock($blockId) as $contact) {
            $existing[$this->blindIndex($contact->email)] = $contact;
        }

        $seen = [];
        $added = 0;
        $unchanged = 0;

        foreach ($contacts as $contact) {
            $index = $this->blindIndex($contact['email']);
            if (isset($seen[$index])) {
                continue;
            }
            $seen[$index] = true;

            $current = $existing[$index] ?? null;
            if ($current === null) {
                $this->create($blockId, $contact['name'], $contact['email']);
                $added++;
                continue;
            }

            $unchanged++;
            // A name may be corrected in the file; an unsubscribed row's
            // may not, since nothing about it is editable any more.
            if (!$current->isUnsubscribed() && ($current->name ?? '') !== ($contact['name'] ?? '')) {
                $this->update($current->id, $contact['name'], $contact['email']);
            }
        }

        $removed = 0;
        $keptUnsubscribed = 0;
        foreach ($existing as $index => $contact) {
            if (isset($seen[$index])) {
                continue;
            }
            if ($contact->isUnsubscribed()) {
                $keptUnsubscribed++;
                continue;
            }
            $this->pdo->prepare('DELETE FROM mass_mail_block_contacts WHERE id = ?')->execute([$contact->id]);
            $removed++;
        }

        return [
            'added' => $added,
            'unchanged' => $unchanged,
            'removed' => $removed,
            'kept_unsubscribed' => $keptUnsubscribed,
        ];
    }

    private function update(int $id, ?string $name, string $email): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE mass_mail_block_contacts
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

    /**
     * @param string $sql one of the COUNT_BY_BLOCK_* constants, written out
     *        whole so no SQL is ever assembled (SECURITY.md)
     * @param array<int, int> $parameters
     * @return array<int, array{total: int, unsubscribed: int}>
     */
    private function countByBlock(string $sql, array $parameters): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($parameters);

        $counts = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $counts[(int) $row['block_id']] = [
                'total' => (int) $row['total'],
                'unsubscribed' => (int) $row['unsubscribed'],
            ];
        }

        return $counts;
    }

    /**
     * @param Contact[] $contacts
     * @return Contact[]
     */
    private function sorted(array $contacts): array
    {
        usort($contacts, static function (Contact $a, Contact $b): int {
            return [mb_strtolower($a->name ?? ''), mb_strtolower($a->email), $a->blockId]
                <=> [mb_strtolower($b->name ?? ''), mb_strtolower($b->email), $b->blockId];
        });

        return $contacts;
    }

    private function blindIndex(string $email): string
    {
        return $this->encryption->blindIndex(mb_strtolower(trim($email)), self::BLIND_INDEX_PURPOSE);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Contact
    {
        return new Contact(
            id: (int) $row['id'],
            blockId: (int) $row['block_id'],
            name: $row['name_encrypted'] !== null
                ? $this->encryption->decrypt($row['name_encrypted'], self::ENCRYPTION_CONTEXT_NAME)
                : null,
            email: $this->encryption->decrypt($row['email_encrypted'], self::ENCRYPTION_CONTEXT_EMAIL),
            unsubscribedAt: $row['unsubscribed_at'] !== null ? (string) $row['unsubscribed_at'] : null,
            createdAt: (string) $row['created_at']
        );
    }
}
