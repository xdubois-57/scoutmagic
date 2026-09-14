<?php

declare(strict_types=1);

namespace Tests\Core\Mail\Feedback;

use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\Feedback\ReturnPathConsumer;
use Core\Mail\Feedback\ReturnPathVerifier;
use Core\Mail\Feedback\ReturnProbeRepository;
use Core\Mail\Feedback\ReturnState;
use Core\Mail\MailException;
use Core\Mail\MailService;
use Core\Security\EncryptionService;
use Modules\InboundMail\Api\CandidateMessage;
use Modules\InboundMail\Api\InboundMailInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The round trip (roadmap IT-03): the site writes to its own return
 * address and waits to see the message land in a box it reads.
 *
 * Every state the roadmap names is exercised here — réussi, échoué,
 * jamais lancé, adresse modifiée, module désactivé — because each of them
 * is a sentence a volunteer reads and acts on, and a wrong one sends them
 * to the wrong half of the problem.
 */
#[Group('database')]
class ReturnPathVerifierTest extends TestCase
{
    private \PDO $pdo;
    private ReturnProbeRepository $probes;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->probes = new ReturnProbeRepository(
            $this->pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
    }

    // ── the three states ──────────────────────────────────────────────

    /**
     * A probe goes out through a queue-less clone: a diagnostic that the
     * deferral queue silently accepts has told nobody anything, and would
     * read « jamais arrivé » hours later about a transport problem.
     */
    public function testAProbeIsNeverHandedToTheDeferralQueue(): void
    {
        $mail = $this->createStub(MailService::class);
        $withoutQueue = $this->createStub(MailService::class);
        $sent = [];
        $withoutQueue->method('send')->willReturnCallback(
            static function (string $to) use (&$sent): void {
                $sent[] = $to;
            }
        );
        $mail->method('withoutDeferral')->willReturn($withoutQueue);
        // The queue-carrying instance must never be the one asked.
        $mail->method('send')->willThrowException(new \LogicException('the queued instance was used'));

        $this->verifierWith($this->collectingGateway(), $mail)->launch(['info@unite.be']);

        $this->assertSame(['info@unite.be'], $sent);
    }

    public function testAnAddressNobodyHasEverCheckedReadsJamaisVerifie(): void
    {
        $verifier = $this->verifierWith($this->collectingGateway());

        $this->assertSame(ReturnState::NEVER_VERIFIED, $verifier->stateFor('info@unite.be')['state']);
    }

    public function testARoundTripThatComesBackReadsVerifiedWithItsBoxAndItsDate(): void
    {
        $sent = [];
        $verifier = $this->verifierWith($this->collectingGateway(), $this->mailServiceRecording($sent));

        $verifier->launch(['info@unite.be']);
        $this->assertCount(1, $sent);

        // The message lands in box 7 and the sync offers it around.
        $consumer = new ReturnPathConsumer($verifier);
        $consumer->analyze($this->candidate($sent[0]['subject'], 7));

        $state = $verifier->stateFor('info@unite.be');
        $this->assertSame(ReturnState::VERIFIED, $state['state']);
        $this->assertSame('Boîte de l’unité', $state['mailbox']);
        $this->assertNotNull($state['received_at']);
    }

    public function testARoundTripNobodyEverSeesComesBackReadsJamaisArriveOnceTheDelayHasRunOut(): void
    {
        $sent = [];
        $verifier = $this->verifierWith($this->collectingGateway(), $this->mailServiceRecording($sent));

        $launchedAt = new \DateTimeImmutable('2026-09-01 08:00:00');
        $verifier->launch(['info@unite.be'], $launchedAt);

        // Still inside the window: the site is entitled to wait, and
        // calling it broken here would train everybody to ignore the
        // answer.
        $this->assertSame(
            ReturnState::WAITING,
            $verifier->stateFor('info@unite.be', $launchedAt->modify('+1 hour'))['state']
        );

        $this->assertSame(
            ReturnState::NEVER_ARRIVED,
            $verifier->stateFor('info@unite.be', $launchedAt->modify('+7 hours'))['state']
        );
    }

    // ── the reset ─────────────────────────────────────────────────────

    public function testChangingTheAddressPutsTheStateBackToJamaisVerifie(): void
    {
        $sent = [];
        $verifier = $this->verifierWith($this->collectingGateway(), $this->mailServiceRecording($sent));

        $verifier->launch(['info@unite.be']);
        (new ReturnPathConsumer($verifier))->analyze($this->candidate($sent[0]['subject'], 7));
        $this->assertSame(ReturnState::VERIFIED, $verifier->stateFor('info@unite.be')['state']);

        // The operator types a new address. Nothing was reset by hand —
        // the state is looked up BY the address, so a new one simply has
        // no answer yet.
        $this->assertSame(ReturnState::NEVER_VERIFIED, $verifier->stateFor('secretariat@unite.be')['state']);
    }

    public function testAnAddressNoLongerInUseStopsBeingKeptAtAll(): void
    {
        $sent = [];
        $verifier = $this->verifierWith($this->collectingGateway(), $this->mailServiceRecording($sent));
        $verifier->launch(['info@unite.be']);
        $this->assertNotNull($this->probes->findByAddress('info@unite.be'));

        $verifier->forgetAllExcept(['secretariat@unite.be']);

        $this->assertNull($this->probes->findByAddress('info@unite.be'));
    }

    public function testTheAddressesStillInUseSurviveTheCleanup(): void
    {
        $sent = [];
        $verifier = $this->verifierWith($this->collectingGateway(), $this->mailServiceRecording($sent));
        $verifier->launch(['info@unite.be', 'secretariat@unite.be']);

        $verifier->forgetAllExcept(['info@unite.be']);

        $this->assertNotNull($this->probes->findByAddress('info@unite.be'));
        $this->assertNull($this->probes->findByAddress('secretariat@unite.be'));
    }

    // ── without the module ────────────────────────────────────────────

    public function testWithoutTheInboundMailModuleTheCheckAnnouncesItselfImpossibleRatherThanFailing(): void
    {
        $verifier = $this->verifierWith(null);

        $this->assertFalse($verifier->isPossible());
        $this->assertFalse($verifier->isCollectingWithoutScope());
        $this->assertSame(ReturnState::IMPOSSIBLE, $verifier->stateFor('info@unite.be')['state']);

        $result = $verifier->launch(['info@unite.be']);
        $this->assertTrue($result['impossible']);
        $this->assertSame(0, $result['sent']);
        // And nothing was written: a probe nobody sent is not a probe.
        $this->assertNull($this->probes->findByAddress('info@unite.be'));
    }

    public function testAnEnabledBoxOpenToNobodyIsNotAWorkingRoundTrip(): void
    {
        $verifier = $this->verifierWith($this->collectingGatewayWithNoScope());

        $this->assertFalse($verifier->isPossible());
        // Not the same sentence on screen: this one somebody can fix, and
        // the screen has to say so rather than blaming the module.
        $this->assertTrue($verifier->isCollectingWithoutScope());
        $this->assertSame(ReturnState::IMPOSSIBLE, $verifier->stateFor('info@unite.be')['state']);
    }

    public function testNoProbeIsEverSentIntoABoxThatWouldNotOfferItBack(): void
    {
        $sent = [];
        $verifier = $this->verifierWith(
            $this->collectingGatewayWithNoScope(),
            $this->mailServiceRecording($sent)
        );

        $result = $verifier->launch(['info@unite.be']);

        $this->assertTrue($result['impossible']);
        $this->assertSame([], $sent, 'A probe nobody can claim would read « jamais arrivé » six hours later.');
    }

    public function testTheCountTheScreenShowsIsTheBoxesOpenToThisCheckAndNotEveryEnabledBox(): void
    {
        // Two enabled boxes, one open to this consumer: « 1 », never « 2 ».
        $this->assertSame(1, $this->verifierWith($this->collectingGateway())->scopedMailboxCount());
        $this->assertSame(0, $this->verifierWith($this->collectingGatewayWithNoScope())->scopedMailboxCount());
        $this->assertSame(0, $this->verifierWith(null)->scopedMailboxCount());
    }

    // ── a send that never leaves ──────────────────────────────────────

    /**
     * A relay hiccup must not erase what was already known.
     *
     * The row is one per address, so issuing a new probe deletes the
     * previous one — and issuing BEFORE the send meant a transient
     * failure put a « vérifié » address back to « jamais vérifié », with
     * nothing able to restore it. The last answer stands until a new one
     * has actually left.
     */
    public function testAFailedSendLeavesAnEarlierSuccessfulVerificationStanding(): void
    {
        $sent = [];
        $working = $this->mailServiceRecording($sent);
        $verifier = $this->verifierWith($this->collectingGateway(), $working);

        $verifier->launch(['info@unite.be']);
        (new ReturnPathConsumer($verifier))->analyze($this->candidate($sent[0]['subject'], 7));
        $this->assertSame(ReturnState::VERIFIED, $verifier->stateFor('info@unite.be')['state']);

        // The operator presses the button again; this time the relay
        // refuses.
        $refusing = $this->createStub(MailService::class);
        $refusing->method('withoutDeferral')->willReturnSelf();
        $refusing->method('send')->willThrowException(new MailException('relay refused'));

        $result = $this->verifierWith($this->collectingGateway(), $refusing)->launch(['info@unite.be']);

        $this->assertSame(1, $result['failed']);
        $this->assertSame(
            ReturnState::VERIFIED,
            $verifier->stateFor('info@unite.be')['state'],
            'A send that never left must not destroy the last answer.'
        );
    }

    public function testASendThatFailsLeavesNoProbeBehindToReadAsJamaisArrive(): void
    {
        $mail = $this->createStub(MailService::class);
        $mail->method('withoutDeferral')->willReturnSelf();
        $mail->method('send')->willThrowException(new MailException('relay refused'));

        $verifier = $this->verifierWith($this->collectingGateway(), $mail);
        $result = $verifier->launch(['info@unite.be']);

        $this->assertSame(0, $result['sent']);
        $this->assertSame(1, $result['failed']);
        // The distinction that matters: « le message n'est pas parti » is
        // a transport problem, « jamais arrivé » is a return-path one,
        // and they send the operator to opposite pages.
        $this->assertSame(ReturnState::NEVER_VERIFIED, $verifier->stateFor('info@unite.be')['state']);
    }

    // ── what the consumer does and does not claim ─────────────────────

    public function testOrdinaryMailInTheBoxIsLeftAlone(): void
    {
        $verifier = $this->verifierWith($this->collectingGateway());
        $consumer = new ReturnPathConsumer($verifier);

        $result = $consumer->analyze($this->candidate('Re: votre réservation', 7));

        $this->assertSame([], $result->links);
        $this->assertSame([], $result->candidates);
    }

    public function testAMessageCarryingAKeyIsRecordedAndStillClaimedByNobody(): void
    {
        $sent = [];
        $verifier = $this->verifierWith($this->collectingGateway(), $this->mailServiceRecording($sent));
        $verifier->launch(['info@unite.be']);

        $result = (new ReturnPathConsumer($verifier))->analyze($this->candidate($sent[0]['subject'], 7));

        // Recorded…
        $this->assertSame(ReturnState::VERIFIED, $verifier->stateFor('info@unite.be')['state']);
        // …and associated with nothing, so no triage list gains a row for
        // a message the site sent to itself.
        $this->assertSame([], $result->links);
        $this->assertSame([], $result->candidates);
    }

    public function testAnExpiredKeyIsNotClaimedWhenItFinallyTurnsUp(): void
    {
        $sent = [];
        $verifier = $this->verifierWith($this->collectingGateway(), $this->mailServiceRecording($sent));
        $launchedAt = new \DateTimeImmutable('2026-09-01 08:00:00');
        $verifier->launch(['info@unite.be'], $launchedAt);

        $claimed = $verifier->claim(
            $sent[0]['subject'],
            'info@unite.be',
            ['info@unite.be'],
            7,
            $launchedAt->modify('+3 days'),
            $launchedAt->modify('+3 days')
        );

        $this->assertNull($claimed);
        $this->assertSame(
            ReturnState::NEVER_ARRIVED,
            $verifier->stateFor('info@unite.be', $launchedAt->modify('+3 days'))['state']
        );
    }

    // ── a report ABOUT the probe is not the probe ─────────────────────

    /**
     * The failure this whole round trip exists to detect, and it was
     * being announced as a success.
     *
     * When the reply address does not exist, the receiving server sends a
     * non-delivery notification back to the ENVELOPE sender — which on
     * this site is always the expedition address, and therefore very
     * often a watched box. That notification quotes the original subject,
     * key and all, so recognising the key alone marked the undeliverable
     * address « vérifié ».
     */
    public function testANonDeliveryNotificationAboutTheProbeNeverCountsAsItsReturn(): void
    {
        $sent = [];
        $verifier = $this->verifierWith($this->collectingGateway(), $this->mailServiceRecording($sent));
        $verifier->launch(['info@unite.be', 'secretariat@unite.be']);

        $probeToTheReplyAddress = $sent[1];
        $this->assertSame('secretariat@unite.be', $probeToTheReplyAddress['to']);

        // The NDR: addressed to the expedition address, quoting the
        // subject of the message it could not deliver.
        (new ReturnPathConsumer($verifier))->analyze($this->candidate(
            'Undeliverable: ' . $probeToTheReplyAddress['subject'],
            7,
            'MAILER-DAEMON@relais.example',
            ['info@unite.be']
        ));

        $this->assertSame(
            ReturnState::WAITING,
            $verifier->stateFor('secretariat@unite.be')['state'],
            'An address that bounced must never read as verified.'
        );
    }

    /**
     * The second guard, for the relay that does put the failed recipient
     * in its notification's own `To:` — enough to defeat the first check
     * on its own.
     */
    public function testAMessageFromAMailSystemIsRefusedEvenWhenAddressedRight(): void
    {
        $sent = [];
        $verifier = $this->verifierWith($this->collectingGateway(), $this->mailServiceRecording($sent));
        $verifier->launch(['info@unite.be']);

        foreach (['MAILER-DAEMON@relais.example', 'Postmaster@unite.be'] as $system) {
            (new ReturnPathConsumer($verifier))->analyze($this->candidate(
                'Undeliverable: ' . $sent[0]['subject'],
                7,
                $system,
                ['info@unite.be']
            ));

            $this->assertSame(
                ReturnState::WAITING,
                $verifier->stateFor('info@unite.be')['state'],
                $system . ' is a mail system talking to itself, not the message coming back.'
            );
        }
    }

    /**
     * And the guard is not so tight that a real return misses: aliasing
     * rewrites the envelope recipient, never the `To:` header, so a probe
     * delivered into a box called something else still names the address
     * it was sent to — which is the very case this round trip exists for.
     */
    public function testAProbeDeliveredThroughAnAliasStillCounts(): void
    {
        $sent = [];
        $verifier = $this->verifierWith($this->collectingGateway(), $this->mailServiceRecording($sent));
        $verifier->launch(['info@unite.be']);

        // Landed in box 7, whose own address is boite@unite.be.
        (new ReturnPathConsumer($verifier))->analyze($this->candidate(
            $sent[0]['subject'],
            7,
            'info@unite.be',
            ['info@unite.be']
        ));

        $this->assertSame(ReturnState::VERIFIED, $verifier->stateFor('info@unite.be')['state']);
    }

    public function testTheKeyTravelsInTheSubjectAndIsRecognisedThroughASubjectPrefix(): void
    {
        $key = 'RET-ABCDEFGHJK';
        $this->assertSame($key, ReturnPathVerifier::keyIn('[25SV] ' . ReturnPathVerifier::subjectFor($key)));
        $this->assertSame($key, ReturnPathVerifier::keyIn('Re: ' . ReturnPathVerifier::subjectFor($key) . ' (fwd)'));
        $this->assertNull(ReturnPathVerifier::keyIn('Re: votre réservation'));
    }

    public function testOneAddressAskedTwiceProducesOneMessage(): void
    {
        $sent = [];
        $verifier = $this->verifierWith($this->collectingGateway(), $this->mailServiceRecording($sent));

        // What the page passes when the reply address falls back to the
        // From address — the ordinary configuration.
        $verifier->launch(['info@unite.be', 'info@unite.be']);

        $this->assertCount(1, $sent);
    }

    // ── helpers ───────────────────────────────────────────────────────

    private function verifierWith(?InboundMailInterface $gateway, ?MailService $mail = null): ReturnPathVerifier
    {
        return new ReturnPathVerifier(
            $this->probes,
            $mail ?? $this->createStub(MailService::class),
            new JournalService(new JournalRepository($this->pdo)),
            $gateway
        );
    }

    /**
     * A box that is enabled AND open to this consumer — the only shape
     * the real service produces when a round trip can work.
     *
     * `probeAddressesFor()` is stubbed alongside the summaries because
     * the two answer different questions, and the earlier double answered
     * only one of them: it returned « collecting, no summaries », which
     * `Modules\InboundMail\Service\InboundMailService` can never return
     * (`isCollecting()` counts the very boxes the summaries list). A
     * double that cannot exist in production is a double that hides the
     * bug it was written to cover.
     */
    private function collectingGateway(): InboundMailInterface
    {
        $gateway = $this->createStub(InboundMailInterface::class);
        $gateway->method('isCollecting')->willReturn(true);
        $gateway->method('listMailboxSummaries')->willReturn([
            7 => ['name' => 'Boîte de l’unité', 'state' => 'ok', 'is_enabled' => true],
            9 => ['name' => 'Ancienne boîte', 'state' => 'ok', 'is_enabled' => false],
        ]);
        $gateway->method('probeAddressesFor')->willReturn(['boite@unite.be']);

        return $gateway;
    }

    /**
     * The box exists, is enabled, is being collected — and is open to
     * nobody. A scope is `inert` until a superadmin opens a box to a
     * consumer, so this is the ordinary state of a fresh installation.
     *
     * Left unchecked, the site sent a probe into a box that never offers
     * it to this consumer, and reported « jamais arrivé » six hours
     * later: the exact false alarm the round trip exists to avoid, raised
     * by the round trip itself.
     */
    private function collectingGatewayWithNoScope(): InboundMailInterface
    {
        $gateway = $this->createStub(InboundMailInterface::class);
        $gateway->method('isCollecting')->willReturn(true);
        $gateway->method('listMailboxSummaries')->willReturn([
            7 => ['name' => 'Boîte de l’unité', 'state' => 'ok', 'is_enabled' => true],
        ]);
        $gateway->method('probeAddressesFor')->willReturn([]);

        return $gateway;
    }

    /**
     * @param list<array{to: string, subject: string}> $sent
     */
    private function mailServiceRecording(array &$sent): MailService
    {
        $mail = $this->createStub(MailService::class);
        // `willReturnSelf()` and not the auto-generated return stub: a
        // double's `withoutDeferral()` otherwise hands back a FRESH
        // double whose `send()` records nothing, and every assertion
        // below would quietly pass on zero messages. The real clone
        // semantics are asserted in Tests\Core\Mail\
        // MailServiceDeferralTest, against the real class.
        $mail->method('withoutDeferral')->willReturnSelf();
        $mail->method('send')->willReturnCallback(
            static function (string $to, string $subject) use (&$sent): void {
                $sent[] = ['to' => $to, 'subject' => $subject];
            }
        );

        return $mail;
    }

    /**
     * @param list<string> $toEmails
     */
    private function candidate(
        string $subject,
        int $mailboxId,
        string $fromEmail = 'info@unite.be',
        array $toEmails = ['info@unite.be']
    ): CandidateMessage {
        return new CandidateMessage(
            mailboxId: $mailboxId,
            subject: $subject,
            fromEmail: $fromEmail,
            fromName: null,
            messageId: '<abc@unite.be>',
            inReplyTo: null,
            references: [],
            toEmails: $toEmails,
            sentAt: new \DateTimeImmutable('2026-09-01 08:05:00'),
            bodyText: 'peu importe',
            bodyHtml: '<p>peu importe</p>'
        );
    }
}
