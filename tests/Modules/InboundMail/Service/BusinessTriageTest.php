<?php

declare(strict_types=1);

namespace Tests\Modules\InboundMail\Service;

use Core\File\FileRepository;
use Core\Security\EncryptionService;
use Modules\InboundMail\Api\InboundMessage;
use Modules\InboundMail\Api\LinkOrigin;
use Modules\InboundMail\Api\MessageCandidate;
use Modules\InboundMail\Mailbox\ProviderType;
use Modules\InboundMail\Repository\InboundMailboxRepository;
use Modules\InboundMail\Repository\InboundMessageRepository;
use Modules\InboundMail\Service\InboundMailService;
use Modules\InboundMail\Service\MailboxScopeService;
use Modules\InboundMail\Service\MessageConsumerRegistry;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\InboundMail\FakeMessageConsumer;
use Tests\Modules\InboundMail\InboundMailTestHelper;

/**
 * The business triage list: what a consumer's own users may sort.
 *
 * The one method on `Api\InboundMailInterface` that returns messages the
 * caller did not name a reference for — so what this file pins is that it
 * is still not a general read. Everything it adds comes from the mailbox
 * configuration, and a consumer cannot widen its own scope by calling it.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class BusinessTriageTest extends TestCase
{
    private \PDO $pdo;
    private InboundMessageRepository $messages;
    private InboundMailboxRepository $mailboxes;
    private MailboxScopeService $scopes;
    private InboundMailService $service;
    private int $sharedBox;
    private int $dedicatedBox;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        InboundMailTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->messages = new InboundMessageRepository($this->pdo, $encryption);
        $this->mailboxes = new InboundMailboxRepository($this->pdo, $encryption);

        $registry = new MessageConsumerRegistry();
        $registry->register(new FakeMessageConsumer('rental'));
        $registry->register(new FakeMessageConsumer('camps'));

        $this->scopes = new MailboxScopeService($this->mailboxes, $registry);
        $this->service = new InboundMailService(
            $this->messages,
            $this->mailboxes,
            new FileRepository($this->pdo),
            $registry
        );

        $this->sharedBox = $this->createMailbox('Unité');
        $this->dedicatedBox = $this->createMailbox('Camps');
    }

    // ── What the list contains ──────────────────────────────────────────

    public function testTheListCarriesWhatThisConsumerAttachedToAReferenceTheUserManages(): void
    {
        $mine = $this->store('mine@x', $this->sharedBox);
        $someoneElses = $this->store('theirs@x', $this->sharedBox);
        $this->messages->addLink($mine, 'rental', 'LOC-1', LinkOrigin::REFERENCE);
        $this->messages->addLink($someoneElses, 'rental', 'LOC-9', LinkOrigin::REFERENCE);

        $this->assertSame(
            [$mine],
            $this->ids($this->service->findForTriage('rental', ['LOC-1']))
        );
    }

    public function testTheListCarriesPropositionsToo(): void
    {
        // A proposition exists to be confirmed or dismissed by somebody who
        // knows. A list showing only what the module was already sure of
        // would hide exactly the messages that need a human.
        $proposed = $this->store('proposed@x', $this->sharedBox);
        $this->addCandidate($proposed, 'rental', 'LOC-1');

        $this->assertSame(
            [$proposed],
            $this->ids($this->service->findForTriage('rental', ['LOC-1']))
        );
    }

    public function testAPropositionSomebodySetAsideLeavesTheList(): void
    {
        $proposed = $this->store('proposed@x', $this->sharedBox);
        $this->addCandidate($proposed, 'rental', 'LOC-1');

        $this->assertTrue($this->service->dismissCandidate('rental', ['LOC-1'], $proposed, $this->candidateId($proposed)));

        $this->assertSame([], $this->service->findForTriage('rental', ['LOC-1']));
    }

    public function testAnotherModulesBusinessIsNeverInThisModulesList(): void
    {
        $theirs = $this->store('theirs@x', $this->sharedBox);
        $this->messages->addLink($theirs, 'camps', 'camp-1', LinkOrigin::REFERENCE);
        $this->addCandidate($theirs, 'camps', 'camp-2');

        $this->assertSame([], $this->service->findForTriage('rental', ['camp-1', 'camp-2']));
    }

    public function testAManagerWhoManagesNothingSeesNothing(): void
    {
        $linked = $this->store('linked@x', $this->sharedBox);
        $this->messages->addLink($linked, 'rental', 'LOC-1', LinkOrigin::REFERENCE);

        $this->assertSame([], $this->service->findForTriage('rental', []));
    }

    // ── What the configuration adds, and only the configuration ─────────

    public function testADedicatedBoxContributesEverythingItHolds(): void
    {
        $this->scopes->saveDedicated($this->dedicatedBox, 'camps');
        $unattached = $this->store('anything@x', $this->dedicatedBox);

        $this->assertSame([$unattached], $this->ids($this->service->findForTriage('camps', [])));
    }

    public function testASharedBoxContributesNothingOnItsOwn(): void
    {
        // Even to a module that analyses it: « analyser » is not « lire ».
        $this->scopes->saveSharedScopes($this->sharedBox, [
            'rental' => ['analyze' => true, 'read' => 'relevant'],
        ]);
        $this->store('anything@x', $this->sharedBox);

        $this->assertSame([], $this->service->findForTriage('rental', []));
    }

    public function testASharedBoxOpenedInFullDoesContributeEverything(): void
    {
        $this->scopes->saveSharedScopes($this->sharedBox, [
            'rental' => ['analyze' => true, 'read' => 'all'],
        ]);
        $anything = $this->store('anything@x', $this->sharedBox);

        $this->assertSame([$anything], $this->ids($this->service->findForTriage('rental', [])));
    }

    public function testAConsumerCannotWidenItsOwnScopeByAskingForOtherPeoplesReferences(): void
    {
        // The references it passes are the ones it may reach. Naming
        // somebody else's does not conjure a message it was never shown —
        // but naming another CONSUMER's reference must not either.
        $theirs = $this->store('theirs@x', $this->sharedBox);
        $this->messages->addLink($theirs, 'camps', 'camp-1', LinkOrigin::REFERENCE);

        $this->assertSame([], $this->service->findForTriage('rental', ['camp-1']));
    }

    // ── Confirming and dismissing, scoped ───────────────────────────────

    public function testConfirmingAPropositionRecordsAManualAssociation(): void
    {
        $id = $this->store('m@x', $this->sharedBox);
        $this->addCandidate($id, 'rental', 'LOC-1');

        $this->assertTrue($this->service->confirmCandidate('rental', ['LOC-1'], $id, $this->candidateId($id), 7));

        $links = $this->messages->findLinksForMessage($id);
        $this->assertCount(1, $links);
        $this->assertSame(LinkOrigin::MANUAL, $links[0]->origin);
        $this->assertSame('LOC-1', $links[0]->businessReference);
        $this->assertSame([], $this->messages->findActiveCandidates($id), 'and it stops being asked');
    }

    public function testAPropositionTargetingSomethingTheUserCannotReachIsRefused(): void
    {
        // The screen offering the button is a convenience; this is the
        // guard. Otherwise a forged candidate id would file a message under
        // an object its user has no business touching.
        $id = $this->store('m@x', $this->sharedBox);
        $this->addCandidate($id, 'rental', 'LOC-9');

        $this->assertFalse($this->service->confirmCandidate('rental', ['LOC-1'], $id, $this->candidateId($id)));
        $this->assertSame([], $this->messages->findLinksForMessage($id));
    }

    public function testAnotherConsumersPropositionIsNeverConfirmedFromHere(): void
    {
        $id = $this->store('m@x', $this->sharedBox);
        $this->addCandidate($id, 'camps', 'camp-1');

        $this->assertFalse($this->service->confirmCandidate('rental', ['camp-1'], $id, $this->candidateId($id)));
        $this->assertSame([], $this->messages->findLinksForMessage($id));
    }

    // ── attach() ────────────────────────────────────────────────────────

    public function testAttachingIsManualAndIdempotent(): void
    {
        $id = $this->store('m@x', $this->sharedBox);

        $this->assertTrue($this->service->attach('rental', 'LOC-1', $id, 7));
        $this->assertFalse($this->service->attach('rental', 'LOC-1', $id, 7), 'twice is once');

        $links = $this->messages->findLinksForMessage($id);
        $this->assertCount(1, $links);
        $this->assertSame(LinkOrigin::MANUAL, $links[0]->origin);
    }

    public function testAttachingSomewhereElseDoesNotRemoveWhatIsAlreadyThere(): void
    {
        // « rattacher ici » is not « retirer de là » — a message can
        // legitimately be a booking's correspondence and an invoice.
        $id = $this->store('m@x', $this->sharedBox);
        $this->messages->addLink($id, 'camps', 'camp-1', LinkOrigin::REFERENCE);

        $this->assertTrue($this->service->attach('rental', 'LOC-1', $id));

        $this->assertCount(2, $this->messages->findLinksForMessage($id));
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    /**
     * @param InboundMessage[] $messages
     * @return int[]
     */
    // ── One module's propositions, and nobody else's ────────────────────

    public function testAScreenSeesItsOwnModulesPropositionsAndNotAnothersOnTheSameMessage(): void
    {
        // The isolation this method exists for. One message can carry a
        // proposition from two modules at once; showing camps' guess on the
        // rental triage screen would leak one module's reasoning into
        // another's audience, and that audience is a different set of
        // people.
        $id = $this->store('deux@x', $this->sharedBox);
        $this->addCandidate($id, 'rental', 'LOC-1');
        $this->addCandidate($id, 'camps', 'camp-1');

        $mine = $this->service->findCandidatesFor('rental', [$id]);

        $this->assertCount(1, $mine[$id]);
        $this->assertSame('LOC-1', $mine[$id][0]->businessReference);
    }

    public function testThePropositionsComeBackGroupedByMessage(): void
    {
        // Grouped here so a triage screen can render a whole page without
        // querying inside its own loop.
        $first = $this->store('un@x', $this->sharedBox);
        $second = $this->store('deux@x', $this->sharedBox);
        $this->addCandidate($first, 'rental', 'LOC-1');
        $this->addCandidate($second, 'rental', 'LOC-2');

        $found = $this->service->findCandidatesFor('rental', [$first, $second]);

        $this->assertSame('LOC-1', $found[$first][0]->businessReference);
        $this->assertSame('LOC-2', $found[$second][0]->businessReference);
    }

    public function testAPropositionSomebodySetAsideIsNotOfferedAgain(): void
    {
        // `dismissed_at` is final (A3 / D10): a proposition that reappeared
        // after somebody rejected it would make the screen argue with its
        // own user.
        $id = $this->store('ecarte@x', $this->sharedBox);
        $this->addCandidate($id, 'rental', 'LOC-1');
        $this->service->dismissCandidate('rental', ['LOC-1'], $id, $this->candidateId($id));

        $this->assertSame([], $this->service->findCandidatesFor('rental', [$id]));
    }

    public function testAskingAboutNoMessageAtAllAsksTheDatabaseNothing(): void
    {
        // An empty page is a real case — the screen renders it — and an
        // `IN ()` would be a syntax error rather than an empty answer.
        $this->assertSame([], $this->service->findCandidatesFor('rental', []));
    }

    private function ids(array $messages): array
    {
        return array_map(static fn(InboundMessage $message) => $message->id, $messages);
    }

    private function candidateId(int $messageId): int
    {
        return $this->messages->findActiveCandidates($messageId)[0]->id;
    }

    private function addCandidate(int $messageId, string $consumerId, string $reference): void
    {
        $this->messages->addCandidate($messageId, $consumerId, new MessageCandidate(
            businessReference: $reference,
            label: 'Quelque chose de reconnaissable',
            evidenceType: 'sender_window',
            explanation: "L'expéditeur correspond, et la période aussi."
        ));
    }

    private function store(string $messageId, int $mailboxId): int
    {
        static $uid = 100;

        return $this->messages->create(
            mailboxId: $mailboxId,
            folder: 'INBOX',
            uidValidity: 1,
            imapUid: ++$uid,
            messageId: $messageId,
            inReplyTo: null,
            subject: 'Sujet',
            fromEmail: 'jeanne@example.be',
            fromName: null,
            bodyText: 'Bonjour',
            bodyHtml: '',
            sentAt: new \DateTimeImmutable('2027-07-12 09:30:00')
        );
    }

    private function createMailbox(string $name): int
    {
        return $this->mailboxes->create($name, ProviderType::FAKE, 'h', 993, 'ssl', 'a@b.be', 's', [], true);
    }
}
