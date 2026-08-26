<?php

declare(strict_types=1);

namespace Tests\Core\Member;

use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Member\MemberNoteException;
use Core\Member\MemberNoteRepository;
use Core\Member\MemberNoteService;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * Dated staff notes about a member.
 *
 * The cases that matter most here are the ones about what must NOT
 * happen: this is probably the most sensitive free text on the site
 * ("allergie signalée par la maman", "parents séparés"), so the journal,
 * the error messages and the storage are each pinned separately.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class MemberNoteServiceTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $enc;
    private MemberNoteService $service;
    private MemberNoteRepository $repository;
    private JournalRepository $journalRepository;
    private int $memberId;
    private int $otherMemberId;
    private int $accountId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->enc = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index, first_name_encrypted, last_name_encrypted) VALUES (?, ?, ?, ?)');
        $stmt->execute([
            $this->enc->encrypt('chef@test.be', 'user_accounts.email'),
            'blind-1',
            $this->enc->encrypt('Xavier', 'user_accounts.first_name'),
            $this->enc->encrypt('Dubois', 'user_accounts.last_name'),
        ]);
        $this->accountId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('D1')");
        $this->memberId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('D2')");
        $this->otherMemberId = (int) $this->pdo->lastInsertId();

        $this->journalRepository = new JournalRepository($this->pdo);
        $this->repository = new MemberNoteRepository(
            $this->pdo,
            $this->enc,
            new UserAccountRepository($this->pdo, $this->enc)
        );
        $this->service = new MemberNoteService($this->repository, new JournalService($this->journalRepository));
    }

    public function testANoteIsStoredWithItsAuthorAndItsDate(): void
    {
        $note = $this->service->add($this->memberId, 'Allergie aux arachides.', $this->accountId);

        $this->assertSame('Allergie aux arachides.', $note->body);
        $this->assertSame($this->accountId, $note->createdBy);
        $this->assertSame('Xavier Dubois', $note->authorName);
        $this->assertFalse($note->wasEdited());
    }

    /**
     * SECURITY.md §5: the body is a BLOB, encrypted, and the ciphertext
     * never resembles the text. A grep of the table must find nothing.
     */
    public function testTheBodyIsEncryptedAtRest(): void
    {
        $this->service->add($this->memberId, 'Parents séparés.', $this->accountId);

        $stored = (string) $this->pdo->query('SELECT body FROM member_notes')->fetchColumn();

        $this->assertNotSame('', $stored);
        $this->assertStringNotContainsString('Parents séparés', $stored);
    }

    /**
     * The whole feature exists to protect this text. The member id and
     * the note id are enough for an audit trail.
     */
    public function testTheJournalRecordsIdentifiersAndNeverTheNoteItself(): void
    {
        $note = $this->service->add($this->memberId, 'À ne pas laisser seul avec X.', $this->accountId);
        $this->service->update($this->memberId, $note->id, 'Texte corrigé, toujours sensible.', $this->accountId);
        $this->service->delete($this->memberId, $note->id, $this->accountId);

        $entries = $this->journalRepository->search('core');
        $serialized = json_encode($entries, JSON_UNESCAPED_UNICODE);
        $this->assertIsString($serialized);

        $this->assertStringNotContainsString('À ne pas laisser seul', $serialized);
        $this->assertStringNotContainsString('Texte corrigé', $serialized);

        $actions = array_column($entries, 'event_type');
        $this->assertContains('member_note_added', $actions);
        $this->assertContains('member_note_updated', $actions);
        $this->assertContains('member_note_deleted', $actions);

        foreach ($entries as $entry) {
            $context = json_decode((string) $entry['context'], true);
            $this->assertSame(['member_id', 'note_id'], array_keys($context));
        }
    }

    public function testNotesComeBackMostRecentFirst(): void
    {
        $first = $this->service->add($this->memberId, 'La plus ancienne.', $this->accountId);
        $this->pdo->exec("UPDATE member_notes SET created_at = '2024-01-01 10:00:00' WHERE id = {$first->id}");
        $this->service->add($this->memberId, 'La plus récente.', $this->accountId);

        $notes = $this->service->listForMember($this->memberId);

        $this->assertSame(['La plus récente.', 'La plus ancienne.'], array_map(fn($n) => $n->body, $notes));
    }

    public function testNotesOfOneMemberNeverLeakIntoAnother(): void
    {
        $this->service->add($this->memberId, 'Sur le premier.', $this->accountId);

        $this->assertCount(1, $this->service->listForMember($this->memberId));
        $this->assertSame([], $this->service->listForMember($this->otherMemberId));
    }

    /**
     * Everyone who reads these is a chef d'unité, so an edit is not
     * restricted to its author. What an edit must never do is rewrite the
     * history: the author and the creation date are what give it meaning.
     */
    public function testAnEditKeepsTheOriginalAuthorAndDateAndRecordsThatItHappened(): void
    {
        $note = $this->service->add($this->memberId, 'Première version.', $this->accountId);
        $originalDate = $note->createdAt->format('Y-m-d H:i:s');

        $updated = $this->service->update($this->memberId, $note->id, 'Seconde version.', null);

        $this->assertSame('Seconde version.', $updated->body);
        $this->assertSame($this->accountId, $updated->createdBy);
        $this->assertSame($originalDate, $updated->createdAt->format('Y-m-d H:i:s'));
        $this->assertTrue($updated->wasEdited());
    }

    /**
     * A note written by mistake on the wrong person has to be able to
     * disappear, or somebody works around it by appending "ignorer la
     * note ci-dessus".
     */
    public function testAnyReaderMayDeleteAnyEntryNotOnlyItsAuthor(): void
    {
        $note = $this->service->add($this->memberId, 'Erreur de personne.', $this->accountId);

        $this->service->delete($this->memberId, $note->id, 999);

        $this->assertSame([], $this->service->listForMember($this->memberId));
    }

    /**
     * A note id from a URL is not a claim about which member it belongs
     * to — writing on the wrong person's record is exactly the mistake
     * the delete control exists to undo.
     */
    public function testANoteOfAnotherMemberCannotBeEditedOrDeletedThroughThisOne(): void
    {
        $note = $this->service->add($this->otherMemberId, 'Sur le second.', $this->accountId);

        try {
            $this->service->update($this->memberId, $note->id, 'Détournée.', $this->accountId);
            $this->fail('Expected a MemberNoteException.');
        } catch (MemberNoteException) {
            // expected
        }

        try {
            $this->service->delete($this->memberId, $note->id, $this->accountId);
            $this->fail('Expected a MemberNoteException.');
        } catch (MemberNoteException) {
            // expected
        }

        $this->assertCount(1, $this->service->listForMember($this->otherMemberId));
    }

    public function testAnEmptyNoteIsRefused(): void
    {
        $this->expectException(MemberNoteException::class);
        $this->service->add($this->memberId, "   \n  ", $this->accountId);
    }

    public function testAnOverLongNoteIsRefused(): void
    {
        $this->expectException(MemberNoteException::class);
        $this->service->add($this->memberId, str_repeat('a', MemberNoteService::MAX_LENGTH + 1), $this->accountId);
    }

    /**
     * AGENTS.md § Exception messages: MemberNoteException is marked
     * user-facing, so every message it carries is shown verbatim. None of
     * them may quote the note.
     */
    public function testARefusalMessageNeverQuotesTheNote(): void
    {
        try {
            $this->service->add($this->memberId, str_repeat('secret ', 400), $this->accountId);
            $this->fail('Expected a MemberNoteException.');
        } catch (MemberNoteException $e) {
            $this->assertStringNotContainsString('secret', $e->getMessage());
        }
    }

    /**
     * Losing the author must never lose the note: it is a fact about the
     * member, not about whoever typed it.
     */
    public function testANoteSurvivesTheDeletionOfItsAuthorsAccount(): void
    {
        $this->service->add($this->memberId, 'Écrite par un compte disparu.', $this->accountId);

        $this->pdo->exec("DELETE FROM user_accounts WHERE id = {$this->accountId}");

        $notes = $this->service->listForMember($this->memberId);
        $this->assertCount(1, $notes);
        $this->assertSame('Écrite par un compte disparu.', $notes[0]->body);
        $this->assertNull($notes[0]->authorName);
    }

    /**
     * Notes are keyed on members.id, the persistent identity — a note
     * about a person outlives the scout year that saw it written. Deleting
     * the member removes them; nothing else does.
     */
    public function testNotesAreRemovedWithTheMember(): void
    {
        $this->service->add($this->memberId, 'Sur ce membre.', $this->accountId);
        $this->service->add($this->otherMemberId, 'Sur un autre.', $this->accountId);

        // SQLite only honours a foreign key when asked to; the cascade
        // being tested is declared in schema/core.sql and mirrored in
        // Tests\DatabaseTestHelper. Enabled here rather than globally so
        // this case does not quietly change every other test's behaviour.
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec("DELETE FROM members WHERE id = {$this->memberId}");

        $remaining = $this->service->listForMember($this->otherMemberId);
        $this->assertSame([], $this->service->listForMember($this->memberId));
        $this->assertCount(1, $remaining, 'Another member\'s notes must be untouched.');
    }
}
