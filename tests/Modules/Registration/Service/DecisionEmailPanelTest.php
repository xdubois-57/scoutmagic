<?php

declare(strict_types=1);

namespace Tests\Modules\Registration\Service;

use Modules\Registration\Repository\DecisionEmail;
use Modules\Registration\Repository\RegistrationRequest;
use Modules\Registration\Service\DecisionEmailEvent;
use Modules\Registration\Service\DecisionEmailPanel;
use PHPUnit\Framework\TestCase;

/**
 * The « E-mail de décision » block's states, buttons and history (issue
 * #843, IT-08), decided from a request's rows alone — no database: the
 * rows are built here exactly as `registration_decision_emails` holds them.
 */
class DecisionEmailPanelTest extends TestCase
{
    private const NOW = '2026-10-12 15:00:00';

    private int $nextId = 1;

    private static function request(string $status): RegistrationRequest
    {
        return new RegistrationRequest(
            7, 1, 'Marie Dupont', 'Dupont', 'Léa', 'F', '2019-01-01', 'Rue', '1', '1000', 'Ville',
            'marie@example.com', '000', null, null, null, $status, new \DateTimeImmutable('2026-09-01')
        );
    }

    /**
     * One row, in the shape the repository hydrates.
     *
     * @param array<string, mixed> $fields
     */
    private function email(string $state, array $fields = []): DecisionEmail
    {
        $at = static fn(?string $value): ?\DateTimeImmutable => $value !== null ? new \DateTimeImmutable($value) : null;

        return new DecisionEmail(
            $this->nextId++,
            7,
            $fields['decision'] ?? DecisionEmail::DECISION_ACCEPTED,
            $state,
            $at($fields['due'] ?? null),
            $fields['attempt'] ?? 1,
            $fields['resend'] ?? false,
            $at($fields['attempted'] ?? ($state === DecisionEmail::STATE_SCHEDULED ? null : '2026-10-10 10:00:00')),
            $at($fields['sent'] ?? ($state === DecisionEmail::STATE_SENT ? '2026-10-10 10:00:05' : null)),
            $at($fields['failed'] ?? ($state === DecisionEmail::STATE_FAILED ? '2026-10-10 10:00:05' : null)),
            $state === DecisionEmail::STATE_FAILED ? DecisionEmail::FAILURE_MAIL_TRANSPORT : null,
            $state === DecisionEmail::STATE_FAILED ? "L'e-mail n'a pas pu être envoyé à la famille." : null,
            $at($fields['cancelled'] ?? null),
            $fields['cancelReason'] ?? null,
            $at($fields['superseded'] ?? null),
            new \DateTimeImmutable($fields['created'] ?? '2026-10-10 10:00:00')
        );
    }

    /**
     * @param array<int, DecisionEmail> $emails
     */
    private static function build(string $status, array $emails, bool $templateReady = true): ?DecisionEmailPanel
    {
        return DecisionEmailPanel::build(
            self::request($status),
            $emails,
            $templateReady,
            new \DateTimeImmutable(self::NOW)
        );
    }

    public function testNoBlockForARequestWithoutADecision(): void
    {
        $this->assertNull(self::build(RegistrationRequest::STATUS_PENDING, []));
        $this->assertNull(
            self::build(RegistrationRequest::STATUS_WITHDRAWN, [$this->email(DecisionEmail::STATE_SENT)])
        );
    }

    public function testNothingYetIsNonPlanifieWithEnvoyerMaintenant(): void
    {
        $panel = self::build(RegistrationRequest::STATUS_ACCEPTED, []);

        $this->assertSame(DecisionEmail::DECISION_ACCEPTED, $panel?->decision);
        $this->assertSame(DecisionEmailPanel::STATE_NONE, $panel->state);
        $this->assertSame([DecisionEmailPanel::ACTION_SEND_NOW], $panel->actions);
        $this->assertSame([], $panel->history);
    }

    /**
     * An encoded request keeps its acceptance — encoding is not a new
     * decision — and a refused one is about its refusal.
     */
    public function testTheDecisionFollowsTheStatus(): void
    {
        $this->assertSame(
            DecisionEmail::DECISION_ACCEPTED,
            self::build(RegistrationRequest::STATUS_ENCODED, [])?->decision
        );
        $this->assertSame(
            DecisionEmail::DECISION_REFUSED,
            self::build(RegistrationRequest::STATUS_REFUSED, [])?->decision
        );
    }

    public function testAnUnwrittenTemplateWinsOverEverythingButASend(): void
    {
        $scheduled = $this->email(DecisionEmail::STATE_SCHEDULED, ['due' => '2026-10-13 09:00:00']);

        $panel = self::build(RegistrationRequest::STATUS_ACCEPTED, [$scheduled], false);

        $this->assertSame(DecisionEmailPanel::STATE_TEMPLATE_MISSING, $panel?->state);
        $this->assertSame([], $panel->actions);
        $this->assertCount(1, $panel->history, 'the history is still shown');
    }

    public function testAPlannedEmailIsPrevuAtItsDueDate(): void
    {
        $panel = self::build(
            RegistrationRequest::STATUS_ACCEPTED,
            [$this->email(DecisionEmail::STATE_SCHEDULED, ['due' => '2026-10-13 09:00:00'])]
        );

        $this->assertSame(DecisionEmailPanel::STATE_SCHEDULED, $panel?->state);
        $this->assertEquals(new \DateTimeImmutable('2026-10-13 09:00:00'), $panel->at);
        $this->assertSame([DecisionEmailPanel::ACTION_SEND_NOW], $panel->actions);
        $this->assertSame([DecisionEmailEvent::PLANNED], array_map(static fn($e) => $e->kind, $panel->history));
    }

    public function testAFailureOffersReessayer(): void
    {
        $panel = self::build(RegistrationRequest::STATUS_REFUSED, [
            $this->email(DecisionEmail::STATE_FAILED, ['decision' => DecisionEmail::DECISION_REFUSED]),
        ]);

        $this->assertSame(DecisionEmailPanel::STATE_FAILED, $panel?->state);
        $this->assertEquals(new \DateTimeImmutable('2026-10-10 10:00:05'), $panel->at);
        $this->assertSame([DecisionEmailPanel::ACTION_RETRY], $panel->actions);
        $this->assertTrue($panel->allows(DecisionEmailPanel::ACTION_RETRY));
        $this->assertFalse($panel->allows(DecisionEmailPanel::ACTION_SEND_NOW));
    }

    /**
     * A send claimed moments ago blocks every button; one claimed longer
     * ago than the stale delay is an interrupted send to retry — or it
     * would block the request for ever. The bound is begin()'s own
     * (`attempted_at < now - 10 min`), to the second: a claim exactly ten
     * minutes old is still in flight there, so it is here too.
     */
    public function testASendInFlightBlocksUntilItIsStale(): void
    {
        $recent = self::build(RegistrationRequest::STATUS_ACCEPTED, [
            $this->email(DecisionEmail::STATE_SENDING, ['attempted' => '2026-10-12 14:55:00']),
        ]);
        $onTheBound = self::build(RegistrationRequest::STATUS_ACCEPTED, [
            $this->email(DecisionEmail::STATE_SENDING, ['attempted' => '2026-10-12 14:50:00']),
        ]);
        $stale = self::build(RegistrationRequest::STATUS_ACCEPTED, [
            $this->email(DecisionEmail::STATE_SENDING, ['attempted' => '2026-10-12 14:49:59']),
        ]);

        $this->assertSame(DecisionEmailPanel::STATE_SENDING, $recent?->state);
        $this->assertSame([], $recent->actions);
        $this->assertSame(DecisionEmailPanel::STATE_SENDING, $onTheBound?->state);
        $this->assertSame([DecisionEmailEvent::SENDING], array_map(static fn($e) => $e->kind, $onTheBound->history));
        $this->assertSame(DecisionEmailPanel::STATE_FAILED, $stale?->state);
        $this->assertTrue($stale->interrupted);
        $this->assertEquals(new \DateTimeImmutable('2026-10-12 14:49:59'), $stale->at);
        $this->assertSame([DecisionEmailPanel::ACTION_RETRY], $stale->actions);
        $this->assertSame(
            [DecisionEmailEvent::INTERRUPTED],
            array_map(static fn($e) => $e->kind, $stale->history),
            'the history says what the state says'
        );
        $this->assertFalse(
            self::build(RegistrationRequest::STATUS_ACCEPTED, [$this->email(DecisionEmail::STATE_FAILED)])?->interrupted
        );
    }

    /**
     * Sent wins: after a success the block says « Envoyé le » the latest
     * send, offers only « Renvoyer l'e-mail », and names a resend that
     * failed since — the family saw the decision all the same.
     */
    public function testASendWinsAndOffersRenvoyer(): void
    {
        $sent = $this->email(DecisionEmail::STATE_SENT, ['sent' => '2026-10-10 10:00:05']);
        $resent = $this->email(DecisionEmail::STATE_SENT, [
            'sent' => '2026-10-11 08:00:00', 'resend' => true, 'attempt' => 2, 'created' => '2026-10-11 08:00:00',
        ]);
        $failedResend = $this->email(DecisionEmail::STATE_FAILED, [
            'resend' => true, 'attempt' => 3, 'created' => '2026-10-12 09:00:00', 'failed' => '2026-10-12 09:00:01',
        ]);

        $panel = self::build(RegistrationRequest::STATUS_ACCEPTED, [$sent, $resent, $failedResend]);

        $this->assertSame(DecisionEmailPanel::STATE_SENT, $panel?->state);
        $this->assertEquals(new \DateTimeImmutable('2026-10-11 08:00:00'), $panel->at);
        $this->assertSame([DecisionEmailPanel::ACTION_RESEND], $panel->actions);
        $this->assertSame($failedResend, $panel->lastFailedResend);
        $this->assertNull(self::build(RegistrationRequest::STATUS_ACCEPTED, [$sent])?->lastFailedResend);
    }

    public function testNoResendWhileAnotherSendIsInPlayOrWithoutATemplate(): void
    {
        $sent = $this->email(DecisionEmail::STATE_SENT);
        $resending = $this->email(DecisionEmail::STATE_SENDING, [
            'resend' => true, 'attempt' => 2, 'created' => '2026-10-12 14:59:00', 'attempted' => '2026-10-12 14:59:00',
        ]);

        $this->assertSame([], self::build(RegistrationRequest::STATUS_ACCEPTED, [$sent, $resending])?->actions);
        $unwritten = self::build(RegistrationRequest::STATUS_ACCEPTED, [$sent], false);
        $this->assertSame(DecisionEmailPanel::STATE_SENT, $unwritten?->state);
        $this->assertSame([], $unwritten->actions);
        $this->assertFalse($unwritten->templateReady);
    }

    /**
     * Only the CURRENT decision's rows decide the state: an acceptance
     * sent before the request was refused leaves the refusal « Non
     * planifié », and an acceptance sent before the request went back to
     * pending and was accepted again does not count as this acceptance's.
     */
    public function testAnEarlierDecisionsEmailsNeverDecideTheState(): void
    {
        $oldAcceptance = $this->email(DecisionEmail::STATE_SENT, ['superseded' => '2026-10-11 09:00:00']);

        $refused = self::build(RegistrationRequest::STATUS_REFUSED, [$oldAcceptance]);
        $acceptedAgain = self::build(RegistrationRequest::STATUS_ACCEPTED, [$oldAcceptance]);

        $this->assertSame(DecisionEmailPanel::STATE_NONE, $refused?->state);
        $this->assertSame(DecisionEmailPanel::STATE_NONE, $acceptedAgain?->state);
        $this->assertSame([DecisionEmailPanel::ACTION_SEND_NOW], $acceptedAgain->actions);
        $this->assertCount(1, $acceptedAgain->history, 'what was sent for the earlier decision still happened');
    }

    /**
     * « Annulé suite à un changement de décision »: the last thing that
     * happened is a change of decision cancelling a planned e-mail, and
     * nothing is planned for the new decision yet.
     */
    public function testAPlannedEmailCancelledByAChangeOfDecisionIsAnnule(): void
    {
        $cancelled = $this->email(DecisionEmail::STATE_CANCELLED, [
            'due' => '2026-10-11 10:00:00',
            'cancelled' => '2026-10-10 18:00:00',
            'cancelReason' => DecisionEmail::CANCEL_DECISION_CHANGED,
            'superseded' => '2026-10-10 18:00:00',
        ]);

        $panel = self::build(RegistrationRequest::STATUS_REFUSED, [$cancelled]);

        $this->assertSame(DecisionEmailPanel::STATE_CANCELLED, $panel?->state);
        $this->assertEquals(new \DateTimeImmutable('2026-10-10 18:00:00'), $panel->at);
        $this->assertSame([DecisionEmailPanel::ACTION_SEND_NOW], $panel->actions);
        $this->assertSame(
            [DecisionEmailEvent::CANCELLED, DecisionEmailEvent::PLANNED],
            array_map(static fn($e) => $e->kind, $panel->history)
        );
    }

    /**
     * The history: every row's events, newest first — a planned e-mail
     * shows its planning, then what became of it.
     */
    public function testTheHistoryIsEveryEventNewestFirst(): void
    {
        $planned = $this->email(DecisionEmail::STATE_FAILED, [
            'due' => '2026-10-11 10:00:00', 'created' => '2026-10-10 10:00:00', 'failed' => '2026-10-11 10:00:00',
        ]);
        $retry = $this->email(DecisionEmail::STATE_SENT, [
            'attempt' => 2, 'created' => '2026-10-12 08:00:00', 'sent' => '2026-10-12 08:00:00',
        ]);

        $panel = self::build(RegistrationRequest::STATUS_ACCEPTED, [$planned, $retry]);

        $this->assertSame(
            [
                [DecisionEmailEvent::SENT, '2026-10-12 08:00:00', $retry->id],
                [DecisionEmailEvent::FAILED, '2026-10-11 10:00:00', $planned->id],
                [DecisionEmailEvent::PLANNED, '2026-10-10 10:00:00', $planned->id],
            ],
            array_map(
                static fn(DecisionEmailEvent $e): array => [$e->kind, $e->at->format('Y-m-d H:i:s'), $e->email->id],
                $panel?->history ?? []
            )
        );
    }
}
