<?php

declare(strict_types=1);

namespace Tests\Modules\Registration\Service;

use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\EncryptionService;
use Core\View\EditableContentRepository;
use Core\View\EditableContentService;
use Modules\Registration\Repository\RegistrationRequest;
use Modules\Registration\Repository\RegistrationRequestRepository;
use Modules\Registration\Repository\SlotCapacityRepository;
use Modules\Registration\Service\Recommendation;
use Modules\Registration\Service\RegistrationException;
use Modules\Registration\Service\RequestDecisionService;
use Modules\Registration\Service\RequestEmailService;
use Modules\Registration\Service\RequestStatusService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Registration\RegistrationTestHelper;

/**
 * The decision dialog's service (issue #843, IT-04): what the « Demandes »
 * list needs to offer it, and the decision it posts — the existing
 * transition of RequestStatusService, then the note — with everything that
 * can refuse checked before anything is written.
 */
#[Group('database')]
class RequestDecisionServiceTest extends TestCase
{
    private const REASON_REQUIRED = RequestStatusService::MESSAGE_REFUSAL_REASON_REQUIRED;

    private \PDO $pdo;
    private RegistrationRequestRepository $requests;
    private EditableContentService $content;
    private SettingService $settings;
    private RequestDecisionService $service;
    private int $pastYearId;
    private int $currentYearId;
    private int $targetYearId;
    private int $baladinsId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RegistrationTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->settings = new SettingService(new SettingRepository($this->pdo));
        RegistrationTestHelper::registerManifestSettings($this->settings);
        $yearSettings = [
            ScoutYearResolver::SETTING_PUBLIC_YEAR => 'Public',
            ScoutYearResolver::SETTING_STAFF_YEAR => 'Staff',
        ];
        foreach ($yearSettings as $key => $label) {
            $this->settings->register($key, '0', 'number', $label, 'desc', null, '^[0-9]+$', null, false);
        }
        $this->settings->set('registration_waitlist_enabled', '1', 'registration');

        $years = new ScoutYearService($this->pdo);
        $this->pastYearId = $years->ensureYear('2025-2026');
        $this->currentYearId = $years->ensureYear('2026-2027');
        $this->targetYearId = $years->ensureYear('2027-2028');
        $this->settings->setInternal(ScoutYearResolver::SETTING_PUBLIC_YEAR, (string) $this->currentYearId);

        $this->baladinsId = RegistrationTestHelper::insertAgeBranch($this->pdo, 'BALA', 'Baladins', 10);
        $capacities = new SlotCapacityRepository($this->pdo);
        $capacities->upsert($this->baladinsId, 1, 10);
        $capacities->upsert($this->baladinsId, 2, 10);

        $this->requests = new RegistrationRequestRepository($this->pdo, $encryption);
        $journal = new JournalService(new JournalRepository($this->pdo));
        $this->content = new EditableContentService(new EditableContentRepository($this->pdo));
        $this->service = RegistrationTestHelper::decisionService(
            $this->pdo,
            $encryption,
            $this->settings,
            new RequestStatusService($this->requests, $journal),
            new RequestEmailService(
                $this->requests,
                $this->createStub(MailService::class),
                $this->content,
                $journal,
                'https://example.com',
                'Unité Test',
                new \Modules\Registration\Service\DecisionEmailService(
                    new \Modules\Registration\Repository\DecisionEmailRepository($this->pdo),
                    $journal
                )
            )
        );
    }

    private function file(
        int $scoutYearId,
        string $firstName = 'Léa',
        string $birthDate = '2020-06-01'
    ): RegistrationRequest
    {
        $created = $this->requests->create($scoutYearId, [
            'parent_name' => 'Marie Dupont', 'child_last_name' => 'Dupont', 'child_first_name' => $firstName,
            'gender' => 'F', 'birth_date' => $birthDate, 'street' => 'S', 'number' => '1',
            'postal_code' => '1000', 'city' => 'V', 'email' => 'marie@example.com',
            'phone1' => '000', 'phone2' => null, 'remarks' => null,
        ], null, []);

        $request = $this->requests->findById($created['id']);
        $this->assertNotNull($request);

        return $request;
    }

    /**
     * @return array<int, array{event_type: string, description: string, context: string}>
     */
    private function journal(): array
    {
        return $this->pdo->query('SELECT event_type, description, context FROM event_log ORDER BY id')
            ->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function testTheCurrentAndTheTargetYearAreDecidableAPastOneIsNot(): void
    {
        $this->assertTrue($this->service->isDecidableYear($this->currentYearId));
        $this->assertTrue($this->service->isDecidableYear($this->targetYearId));
        $this->assertFalse($this->service->isDecidableYear($this->pastYearId));
        $this->assertFalse($this->service->isDecidableYear(999));
    }

    public function testTheDialogContextCarriesEachPendingRequestsRecommendation(): void
    {
        $pending = $this->file($this->targetYearId);
        $accepted = $this->file($this->targetYearId, 'Noa', '2020-03-03');
        $this->requests->updateStatus($accepted->id, 'accepted', null);

        $context = $this->service->dialogContext($this->targetYearId);

        $this->assertTrue($context['decidable']);
        $this->assertTrue($context['show_recommendation']);
        $this->assertSame([$pending->id], array_keys($context['recommendations']));
        $this->assertInstanceOf(Recommendation::class, $context['recommendations'][$pending->id]);
        $this->assertSame('yes', $context['recommendations'][$pending->id]->level);
        $this->assertFalse($context['accepted_email_ready']);
        $this->assertFalse($context['refused_email_ready']);
    }

    public function testNoRecommendationWhileWaitlistsAreNotManagedButTheYearStaysDecidable(): void
    {
        $this->file($this->targetYearId);
        $this->settings->set('registration_waitlist_enabled', '0', 'registration');

        $context = $this->service->dialogContext($this->targetYearId);

        $this->assertTrue($context['decidable']);
        $this->assertFalse($context['show_recommendation']);
        $this->assertSame([], $context['recommendations']);
    }

    public function testAPastYearIsNeitherDecidableNorRecommended(): void
    {
        $this->file($this->pastYearId);

        $context = $this->service->dialogContext($this->pastYearId);

        $this->assertFalse($context['decidable']);
        $this->assertFalse($context['show_recommendation']);
        $this->assertSame([], $context['recommendations']);
    }

    public function testTheEmailFlagsFollowTheTemplates(): void
    {
        $this->content->set('registration_email_refused_body', '<p>Désolés</p>', 'rich_text', 1);

        $context = $this->service->dialogContext($this->targetYearId);

        $this->assertFalse($context['accepted_email_ready']);
        $this->assertTrue($context['refused_email_ready']);
        $this->assertFalse($this->service->isEmailReady(RequestDecisionService::DECISION_ACCEPT));
        $this->assertTrue($this->service->isEmailReady(RequestDecisionService::DECISION_REFUSE));
    }

    public function testAcceptingStoresTheNoteAndJournalsWithoutPersonalData(): void
    {
        $request = $this->file($this->targetYearId);

        $this->service->decide($request, 'accept', "  Appeler les parents d'abord.  ", null);

        $decided = $this->requests->findById($request->id);
        $this->assertSame('accepted', $decided->status);
        $this->assertSame("Appeler les parents d'abord.", $decided->internalNotes);
        $this->assertNull($decided->refusalReason);
        // Encrypted at rest, like the fiche's own note.
        $stored = (string) $this->pdo->query(
            'SELECT internal_notes_encrypted FROM registration_requests WHERE id = ' . $request->id
        )->fetchColumn();
        $this->assertStringNotContainsString('Appeler', $stored);

        $journal = $this->journal();
        $this->assertSame(
            ['registration_request_status_changed', 'registration_request_decided'],
            array_column($journal, 'event_type')
        );
        $this->assertSame(
            ['request_id' => $request->id, 'decision' => 'accept', 'note_changed' => true],
            json_decode($journal[1]['context'], true)
        );
        foreach ($journal as $row) {
            foreach (['Léa', 'Dupont', 'Appeler'] as $personal) {
                $this->assertStringNotContainsString($personal, $row['description'] . $row['context']);
            }
        }
    }

    public function testRefusingStoresItsReason(): void
    {
        $request = $this->file($this->targetYearId);

        $this->service->decide($request, 'refuse', '', RegistrationRequest::REFUSAL_NO_ROOM);

        $decided = $this->requests->findById($request->id);
        $this->assertSame('refused', $decided->status);
        $this->assertSame('no_room', $decided->refusalReason);
        $this->assertNotNull($decided->finalAt);
    }

    /**
     * @return array<string, array{string, ?string, string}>
     */
    public static function refusedDecisionProvider(): array
    {
        return [
            'refusal without a reason' => ['refuse', null, self::REASON_REQUIRED],
            'refusal with an empty reason' => ['refuse', '', self::REASON_REQUIRED],
            'refusal with an unknown reason' => ['refuse', 'full', self::REASON_REQUIRED],
            'unknown decision' => ['withdraw', null, 'Décision inconnue : choisissez « Accepter » ou « Refuser ».'],
        ];
    }

    /**
     * Refused before anything is written: the status, the reason, the note
     * and the journal all stay as they were.
     */
    #[DataProvider('refusedDecisionProvider')]
    public function testARefusedDecisionChangesNothingNotEvenTheNote(
        string $decision,
        ?string $reason,
        string $message
    ): void {
        $request = $this->file($this->targetYearId);
        $this->requests->updateInternalNotes($request->id, 'Note avant');
        $request = $this->requests->findById($request->id);

        try {
            $this->service->decide($request, $decision, 'Note après', $reason);
            $this->fail('the decision was taken');
        } catch (RegistrationException $e) {
            $this->assertSame($message, $e->getMessage());
        }

        $after = $this->requests->findById($request->id);
        $this->assertSame('pending', $after->status);
        $this->assertSame('Note avant', $after->internalNotes);
        $this->assertSame([], $this->journal());
    }

    public function testARequestOfAPastYearCannotBeDecided(): void
    {
        $request = $this->file($this->pastYearId);

        $this->expectException(RegistrationException::class);
        $this->expectExceptionMessage(
            "Cette demande appartient à une année passée, qui se consulte seulement : rien n'a été modifié."
        );

        try {
            $this->service->decide($request, 'accept', 'Note', null);
        } finally {
            $this->assertSame('pending', $this->requests->findById($request->id)->status);
            $this->assertNull($this->requests->findById($request->id)->internalNotes);
        }
    }

    /**
     * The dialog decides a pending request. One decided meanwhile — another
     * chief, another tab — is not decided again, and keeps its note.
     */
    public function testARequestNoLongerPendingCannotBeDecided(): void
    {
        $request = $this->file($this->targetYearId);
        $this->requests->updateStatus($request->id, 'accepted', null);
        $accepted = $this->requests->findById($request->id);

        try {
            $this->service->decide($accepted, 'refuse', 'Note', RegistrationRequest::REFUSAL_OTHER);
            $this->fail('a request no longer pending was decided');
        } catch (RegistrationException $e) {
            $this->assertStringStartsWith("Cette demande n'est plus en attente", $e->getMessage());
        }

        $this->assertSame('accepted', $this->requests->findById($request->id)->status);
        $this->assertNull($this->requests->findById($request->id)->internalNotes);
    }

    /**
     * The page was loaded with note « A »; a colleague then completed it on
     * the fiche. Accepting from that dialog without touching the note keeps
     * the colleague's text — and an edited note still replaces it.
     */
    public function testAnUntouchedNoteNeverOverwritesOneSavedSinceThePageWasLoaded(): void
    {
        $untouched = $this->file($this->targetYearId);
        $edited = $this->file($this->targetYearId, 'Noa', '2020-03-03');
        foreach ([$untouched, $edited] as $request) {
            $this->requests->updateInternalNotes($request->id, 'A');
            $this->requests->updateInternalNotes($request->id, 'A + appel du papa');
        }

        $this->service->decide($this->requests->findById($untouched->id), 'accept', 'A', null, 'A');
        $this->service->decide($this->requests->findById($edited->id), 'accept', 'A, rappeler', null, 'A');

        $this->assertSame('A + appel du papa', $this->requests->findById($untouched->id)->internalNotes);
        $this->assertSame('A, rappeler', $this->requests->findById($edited->id)->internalNotes);
    }

    /**
     * A browser posts a textarea with CRLF line endings: a note stored with
     * LF ones, posted back untouched, has not changed.
     */
    public function testLineEndingsAloneAreNoChangeOfTheNote(): void
    {
        $request = $this->file($this->targetYearId);
        $this->requests->updateInternalNotes($request->id, "ligne 1\nligne 2");

        $this->service->decide($this->requests->findById($request->id), 'accept', "ligne 1\r\nligne 2", null);

        $this->assertSame("ligne 1\nligne 2", $this->requests->findById($request->id)->internalNotes);
        $decided = array_values(array_filter(
            $this->journal(),
            static fn(array $row) => $row['event_type'] === 'registration_request_decided'
        ));
        $this->assertFalse(json_decode($decided[0]['context'], true)['note_changed']);
    }

    /**
     * The status and the note land together: a note that cannot be written
     * takes the decision — and its journal entries — back with it.
     */
    public function testAFailedNoteWriteUndoesTheDecision(): void
    {
        $request = $this->file($this->targetYearId);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $failing = new class ($this->pdo, $encryption) extends RegistrationRequestRepository {
            public function updateInternalNotes(int $id, ?string $notes): void
            {
                throw new \RuntimeException('the note could not be written');
            }
        };
        $journal = new JournalService(new JournalRepository($this->pdo));
        $service = RegistrationTestHelper::decisionService(
            $this->pdo,
            $encryption,
            $this->settings,
            new RequestStatusService($failing, $journal),
            new RequestEmailService(
                $failing,
                $this->createStub(MailService::class),
                $this->content,
                $journal,
                'https://example.com',
                'Unité Test',
                new \Modules\Registration\Service\DecisionEmailService(
                    new \Modules\Registration\Repository\DecisionEmailRepository($this->pdo),
                    $journal
                )
            ),
            $failing
        );

        try {
            $service->decide($request, 'accept', 'Une note', null);
            $this->fail('the failed note write was swallowed');
        } catch (\RuntimeException $e) {
            $this->assertSame('the note could not be written', $e->getMessage());
        }

        $this->assertSame('pending', $this->requests->findById($request->id)->status);
        $this->assertSame([], $this->journal());
    }

    /**
     * The dialog shows the stored note: left untouched it is not rewritten,
     * and emptied it is cleared.
     */
    public function testAnUntouchedNoteIsNotRewrittenAndAnEmptiedOneIsCleared(): void
    {
        $kept = $this->file($this->targetYearId);
        $this->requests->updateInternalNotes($kept->id, 'Garder');
        $cleared = $this->file($this->targetYearId, 'Noa', '2020-03-03');
        $this->requests->updateInternalNotes($cleared->id, 'Effacer');

        $this->service->decide($this->requests->findById($kept->id), 'accept', 'Garder', null);
        $this->service->decide($this->requests->findById($cleared->id), 'accept', '   ', null);

        $this->assertSame('Garder', $this->requests->findById($kept->id)->internalNotes);
        $this->assertNull($this->requests->findById($cleared->id)->internalNotes);
        $decided = array_values(array_filter(
            $this->journal(),
            static fn(array $row) => $row['event_type'] === 'registration_request_decided'
        ));
        $this->assertFalse(json_decode($decided[0]['context'], true)['note_changed']);
        $this->assertTrue(json_decode($decided[1]['context'], true)['note_changed']);
    }
}
