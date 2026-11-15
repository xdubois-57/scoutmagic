<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\InboundMail\Repository;

use Core\Security\EncryptionService;
use Modules\InboundMail\Api\LinkOrigin;
use Modules\InboundMail\Api\MessageCandidate;
use Modules\InboundMail\Mailbox\ProviderType;
use Modules\InboundMail\Repository\InboundMailboxRepository;
use Modules\InboundMail\Repository\InboundMessageRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\UsesProductionEngine;

/**
 * The two check-then-insert writes of the message repository, raced by a
 * second session on the engine production runs.
 *
 * `addLink()` and `addCandidate()` look for the row, then insert it. Two
 * people orienting the same message at the same moment both pass the look,
 * and the unique index is what turns the second insert away — a refusal
 * the repository reads as « already there », because that is the state the
 * caller asked for. SQLite in memory has one connection and no interleaving
 * to offer, so the window is opened here for real: this session reads from
 * a snapshot taken before the other one commits, exactly as it would had the
 * other commit landed between its SELECT and its INSERT.
 */
#[Group('database')]
final class InboundMessageRepositoryOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private \PDO $pdo;
    private EncryptionService $encryption;
    private InboundMessageRepository $messages;
    private int $messageId;

    protected function setUp(): void
    {
        $this->pdo = $this->productionEngine();
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->messages = new InboundMessageRepository($this->pdo, $this->encryption);

        $mailboxId = (new InboundMailboxRepository($this->pdo, $this->encryption))->create(
            'Unité',
            ProviderType::FAKE,
            'imap.test',
            993,
            'ssl',
            'contact@unite.be',
            'secret',
            ['INBOX'],
            true
        );
        $this->messageId = $this->messages->create(
            mailboxId: $mailboxId,
            folder: 'INBOX',
            uidValidity: 1,
            imapUid: 7,
            messageId: 'course@example.be',
            inReplyTo: null,
            subject: 'Sujet',
            fromEmail: 'jeanne@example.be',
            fromName: null,
            bodyText: 'Bonjour',
            bodyHtml: '',
            sentAt: new \DateTimeImmutable('2027-07-12 09:30:00')
        );
    }

    protected function tearDown(): void
    {
        // Unset when productionEngine() skipped the test.
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    /**
     * The premise: a snapshot read here does not see a row another session
     * committed after it was taken. Were it not so, the races below would
     * be decided by the look and never reach the insert.
     */
    public function testASnapshotTakenBeforeAnotherSessionCommitsDoesNotSeeItsRow(): void
    {
        $this->openSnapshot();
        $this->secondSession()->prepare(
            'INSERT INTO inbound_message_links (message_id, consumer_id, business_reference, attachment_id, link_origin)
             VALUES (?, ?, ?, 0, ?)'
        )->execute([$this->messageId, 'camps', 'camp-1', LinkOrigin::MANUAL->value]);

        $this->assertFalse($this->messages->hasLink($this->messageId, 'camps', 'camp-1'));
    }

    /**
     * Two chiefs file the same message under the same camp. The other one
     * (an unattended pass here, so it names nobody) got there first: this
     * one is told nothing new happened — no error, no second row — and the
     * association that stands is the other's, not re-authored by this one.
     */
    public function testTheSecondOfTwoConcurrentAssociationsIsReadAsAlreadyThere(): void
    {
        $this->openSnapshot();
        $this->secondSession()->prepare(
            'INSERT INTO inbound_message_links
                (message_id, consumer_id, business_reference, attachment_id, link_origin, created_by_user_account_id)
             VALUES (?, ?, ?, 0, ?, NULL)'
        )->execute([$this->messageId, 'camps', 'camp-1', LinkOrigin::MANUAL->value]);

        $created = $this->messages->addLink($this->messageId, 'camps', 'camp-1', LinkOrigin::MANUAL, 0, 42);
        $this->pdo->commit();

        $this->assertFalse($created);
        $links = $this->messages->findLinksForMessage($this->messageId);
        $this->assertCount(1, $links);
        $this->assertNull($links[0]->createdByUserAccountId, 'the association that stands is the first one');
    }

    /**
     * The same race on a proposition: two analysis passes offering the
     * same object. One proposition, and the second pass is told it was
     * already there.
     */
    public function testTheSecondOfTwoConcurrentPropositionsIsReadAsAlreadyThere(): void
    {
        $this->openSnapshot();
        $this->secondSession()->prepare(
            'INSERT INTO inbound_message_candidates
                (message_id, attachment_id, consumer_id, business_reference,
                 evidence_type, evidence_label_encrypted, evidence_explanation_encrypted)
             VALUES (?, 0, ?, ?, ?, ?, ?)'
        )->execute([
            $this->messageId,
            'rental',
            'LOC-1',
            'sender_window',
            $this->encryption->encrypt('La Grange', 'inbound_message_candidates.evidence_label'),
            $this->encryption->encrypt('Le premier passage', 'inbound_message_candidates.evidence_explanation'),
        ]);

        $created = $this->messages->addCandidate($this->messageId, 'rental', new MessageCandidate(
            businessReference: 'LOC-1',
            label: 'La Grange — 12 au 15 septembre',
            evidenceType: 'sender_window',
            explanation: 'Le second passage'
        ));
        $this->pdo->commit();

        $this->assertFalse($created);
        $candidates = $this->messages->findActiveCandidates($this->messageId);
        $this->assertCount(1, $candidates);
        $this->assertSame('Le premier passage', $candidates[0]->explanation, 'the first proposition stands');
    }

    /** A consistent snapshot, taken now, that this session reads from until it commits. */
    private function openSnapshot(): void
    {
        $this->pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
    }

    private function secondSession(): \PDO
    {
        $database = (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn();

        return self::productionEngineConnection($database)->getPdo();
    }
}
