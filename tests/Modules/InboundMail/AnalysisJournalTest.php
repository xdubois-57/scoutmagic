<?php

declare(strict_types=1);

namespace Tests\Modules\InboundMail;

use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Modules\InboundMail\Api\AnalysisResult;
use Modules\InboundMail\Api\CandidateMessage;
use Modules\InboundMail\Api\InboundMessage;
use Modules\InboundMail\Api\LinkOrigin;
use Modules\InboundMail\Api\MessageCandidate;
use Modules\InboundMail\Api\MessageConsumerInterface;
use Modules\InboundMail\Api\MessageLink;
use Modules\InboundMail\Service\AnalysisJournal;
use Modules\InboundMail\Service\MessageConsumerRegistry;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * An incoming message that produces nothing has to be findable afterwards.
 *
 * The complaint this comes from: mail is sent to `camps@` and to
 * `locations@` to be analysed, nothing comes of it, and the event journal
 * — the one place anybody looks next, and the one thing the diagnostic
 * archive carries — has not a single line about it. A mailbox that never
 * synchronised, a module that was never allowed to look, a module that
 * looked and declined, and a module that crashed all produced exactly the
 * same silence.
 */
class AnalysisJournalTest extends TestCase
{
    private \PDO $pdo;
    private JournalRepository $journalRepository;
    private AnalysisJournal $journal;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->journalRepository = new JournalRepository($this->pdo);
        $this->journal = new AnalysisJournal(new JournalService($this->journalRepository));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function entries(): array
    {
        return $this->journalRepository->search();
    }

    public function testAMessageNobodyRecognisedIsStillWrittenDown(): void
    {
        $this->journal->analysed(
            42,
            2,
            'Locations',
            ['camps', 'rental'],
            [],
            AnalysisJournal::PASS_ARRIVAL
        );

        $entry = $this->entries()[0];
        $this->assertSame('inbound_message_analysed', $entry['event_type']);
        $this->assertStringContainsString('#42', $entry['description']);
        $this->assertStringContainsString('Locations', $entry['description']);
        $this->assertStringContainsString('camps : rien', $entry['description']);
        $this->assertStringContainsString('rental : rien', $entry['description']);
    }

    public function testTheEntryNamesWhatEachModuleAnswered(): void
    {
        $this->journal->analysed(
            7,
            1,
            'Unité',
            ['camps', 'rental', 'finance'],
            [
                'rental' => AnalysisResult::linkedTo('rental', 'LOC-2027-0042', LinkOrigin::SENDER),
                'finance' => AnalysisResult::proposing(new MessageCandidate('mvt-9', 'Un mouvement', 'amount', 'x')),
            ],
            AnalysisJournal::PASS_ARRIVAL
        );

        $entry = $this->entries()[0];
        $context = json_decode((string) $entry['context'], true);
        $this->assertIsArray($context);
        $this->assertSame(
            ['camps' => 'rien', 'rental' => 'rattache', 'finance' => 'propose'],
            $context['outcome']
        );
        $this->assertSame(7, $context['message_id']);
        $this->assertSame(1, $context['mailbox_id']);
    }

    public function testNothingOfTheMessageItselfReachesTheJournal(): void
    {
        // A journal entry is read by a superadmin and travels in a support
        // archive: it may name an internal id and a mailbox, never a
        // sender, a subject or a file (§7.9).
        $this->journal->analysed(3, 1, 'Unité', ['camps'], [], AnalysisJournal::PASS_STORED);

        $entry = $this->entries()[0];
        $whole = $entry['description'] . '|' . (string) $entry['context'];
        $this->assertStringNotContainsString('@', $whole);
    }

    public function testAModuleThatCrashesIsNoLongerSwallowedInSilence(): void
    {
        $this->journal->failed('camps', 4, AnalysisJournal::PASS_STORED, new \RuntimeException('Colonne absente'));

        $entry = $this->entries()[0];
        $this->assertSame('inbound_analysis_failed', $entry['event_type']);
        $this->assertSame('error', $entry['level']);
        $this->assertStringContainsString('camps', $entry['description']);
        $this->assertStringContainsString('Colonne absente', $entry['description']);
        $this->assertSame(\RuntimeException::class, json_decode((string) $entry['context'], true)['error_class']);
    }

    public function testAFailureQuotingAnAddressHasItTakenOut(): void
    {
        // A driver echoing a failing statement can quote a sender back at
        // us, and the journal is not where that belongs.
        $this->journal->failed(
            'rental',
            1,
            AnalysisJournal::PASS_ARRIVAL,
            new \RuntimeException("Doublon pour 'ferme@example.org'")
        );

        $this->assertStringNotContainsString('ferme@example.org', $this->entries()[0]['description']);
        $this->assertStringContainsString('[adresse]', $this->entries()[0]['description']);
    }

    public function testAFailureIsNotAllowedToPasteAWholeStackTraceIntoTheJournal(): void
    {
        $this->journal->failed('camps', 1, AnalysisJournal::PASS_STORED, new \RuntimeException(str_repeat('x', 900)));

        $this->assertLessThan(
            AnalysisJournal::MAX_REASON_CHARS + 100,
            mb_strlen((string) $this->entries()[0]['description'])
        );
    }

    public function testABoxNoModuleMayAnalyseSaysSoRatherThanSayingNothing(): void
    {
        $this->journal->noConsumerAllowed(2, 'Locations');

        $entry = $this->entries()[0];
        $this->assertSame('inbound_no_module_analyses', $entry['event_type']);
        $this->assertSame('warning', $entry['level']);
        $this->assertStringContainsString('Locations', $entry['description']);
    }

    public function testTheDeferredPassSaysNothingWhenItExaminedNothing(): void
    {
        // An hourly task that finds nothing to do must not fill the journal
        // the way the scheduler once did — `scheduler_task_done` already
        // records that it ran.
        $this->journal->storedPassDone(0, 0, 0);

        $this->assertSame([], $this->entries());
    }

    public function testTheDeferredPassReportsWhatItActuallyDid(): void
    {
        $this->journal->storedPassDone(4, 1, 2);

        $entry = $this->entries()[0];
        $this->assertSame('inbound_stored_analysis_done', $entry['event_type']);
        $this->assertStringContainsString('4 message(s)', $entry['description']);
        $this->assertStringContainsString('1 rattachement(s)', $entry['description']);
        $this->assertStringContainsString('2 proposition(s)', $entry['description']);
    }

    // ── Through the registry, which is where the swallowing happened ────

    public function testTheRegistryJournalsTheConsumerItSkips(): void
    {
        $registry = new MessageConsumerRegistry();
        $registry->register($this->throwingConsumer());
        $registry->setAnalysisJournal($this->journal);

        $results = $registry->analyzeAll($this->candidate());

        // Skipped, as it always was — one module's failure must not stop a
        // synchronisation — but no longer invisible.
        $this->assertSame([], $results);
        $this->assertSame('inbound_analysis_failed', $this->entries()[0]['event_type']);
        $this->assertSame('arrivee', json_decode((string) $this->entries()[0]['context'], true)['pass']);
    }

    public function testTheDeferredPassJournalsTheConsumerItSkipsToo(): void
    {
        $registry = new MessageConsumerRegistry();
        $registry->register($this->throwingConsumer());
        $registry->setAnalysisJournal($this->journal);

        $registry->analyzeAllStored($this->stored());

        $this->assertSame('differe', json_decode((string) $this->entries()[0]['context'], true)['pass']);
    }

    public function testARegistryWithNoJournalStillSwallowsTheFailure(): void
    {
        // The read path — « may this person open that attachment » — has no
        // business writing to the journal, and must not start throwing
        // because of it.
        $registry = new MessageConsumerRegistry();
        $registry->register($this->throwingConsumer());

        $this->assertSame([], $registry->analyzeAll($this->candidate()));
        $this->assertSame([], $this->entries());
    }

    private function throwingConsumer(): MessageConsumerInterface
    {
        return new class implements MessageConsumerInterface {
            public function consumerId(): string
            {
                return 'camps';
            }

            public function displayName(): string
            {
                return 'Camps';
            }

            public function analyze(CandidateMessage $message): AnalysisResult
            {
                throw new \RuntimeException('boum');
            }

            public function analyzeStored(InboundMessage $message): AnalysisResult
            {
                throw new \RuntimeException('boum');
            }

            public function describeReference(string $businessReference): ?string
            {
                return null;
            }

            /** @return string[] */
            public function describeEvidence(): array
            {
                return [];
            }

            public function triageAudienceLabel(): string
            {
                return 'le staff';
            }

            public function triageAudienceCount(): int
            {
                return 0;
            }

            /** @param array<int, int> $linkedMemberIds */
            public function canRead(string $businessReference, array $linkedMemberIds, string $role): bool
            {
                return false;
            }

            public function onLinked(InboundMessage $message, MessageLink $link): void
            {
            }

            public function onUnlinked(InboundMessage $message, MessageLink $link): void
            {
            }
        };
    }

    private function candidate(): CandidateMessage
    {
        return new CandidateMessage(
            2,
            'Bonjour',
            'ferme@example.org',
            null,
            '<m@x>',
            null,
            [],
            ['camps@unite.be'],
            new \DateTimeImmutable('2027-01-01'),
            'Corps',
            ''
        );
    }

    private function stored(): InboundMessage
    {
        return new InboundMessage(
            id: 9,
            mailboxId: 2,
            consumerId: '',
            businessReference: '',
            linkOrigin: LinkOrigin::SENDER,
            subject: 'Bonjour',
            fromEmail: 'ferme@example.org',
            fromName: null,
            messageId: '<m@x>',
            inReplyTo: null,
            sentAt: new \DateTimeImmutable('2027-01-01'),
            bodyText: 'Corps',
            bodyHtml: ''
        );
    }
}
