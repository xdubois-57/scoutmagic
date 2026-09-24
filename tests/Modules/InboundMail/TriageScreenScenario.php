<?php

declare(strict_types=1);

namespace Tests\Modules\InboundMail;

/**
 * One scenario, run by every screen built on the shared triage component
 * (`@inbound_mail/partials/triage.html.twig`, issue #462, D9): the camps'
 * and the rentals'.
 *
 * The roadmap's promise is that an attach, a detach and a set-aside end in
 * the same state on both sides. Written once, used by both test classes,
 * this is that promise as a test: a screen that diverged — a filter that
 * counts differently, a set-aside that leaves the row in « À trier » —
 * fails here on its own side, against the same expectations as the other.
 *
 * The using class drives its own controller; the state lives in
 * `InMemoryTriageMail`, which it must have wired in.
 */
trait TriageScreenScenario
{
    /** The rendered screen, under one filter. */
    abstract protected function triageScreen(string $status = ''): string;

    /** Attaches message `$id` to the one object this test's user may reach. */
    abstract protected function triageAttach(int $id): void;

    /** Detaches it from that object again. */
    abstract protected function triageDetach(int $id): void;

    abstract protected function triageSetAside(int $id): void;

    abstract protected function triageRestore(int $id): void;

    /** The subject of message 7, as `InMemoryTriageMail::aMessage()` writes it. */
    private function triageRow(string $html): bool
    {
        return str_contains($html, 'data-triage-message="7"');
    }

    public function testTheSharedScreenIsTheOneRendered(): void
    {
        $html = $this->triageScreen();

        $this->assertStringContainsString('data-mail-triage', $html);
        $this->assertStringContainsString('aria-label="Filtrer le courrier"', $html);
        $this->assertSame(1, substr_count($html, 'id="mail-message-modal"'), 'one dialog for the page');
    }

    public function testAnAttachedMessageLeavesTheWorkListForTheAttachedTab(): void
    {
        $this->assertTrue($this->triageRow($this->triageScreen()), 'the message starts in « À trier »');

        $this->triageAttach(7);

        $this->assertFalse($this->triageRow($this->triageScreen()), 'an attached message is still « à trier »');
        $attached = $this->triageScreen('rattaches');
        $this->assertTrue($this->triageRow($attached));
        $this->assertStringContainsString('Rattaché —', $attached);
        $this->assertStringContainsString('Détacher de « ', $attached);
    }

    public function testADetachedMessageComesBackToTheWorkList(): void
    {
        $this->triageAttach(7);
        $this->triageDetach(7);

        $this->assertTrue($this->triageRow($this->triageScreen()));
        $this->assertFalse($this->triageRow($this->triageScreen('rattaches')));
    }

    public function testASetAsideMessageIsOnlyUnderItsOwnTabAndComesBack(): void
    {
        $this->triageSetAside(7);

        $this->assertFalse($this->triageRow($this->triageScreen()));
        $this->assertFalse($this->triageRow($this->triageScreen('tous')));
        $setAside = $this->triageScreen('ecartes');
        $this->assertTrue($this->triageRow($setAside));
        $this->assertStringContainsString('Remettre dans la liste', $setAside);

        $this->triageRestore(7);

        $this->assertTrue($this->triageRow($this->triageScreen()));
    }
}
