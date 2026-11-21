<?php

declare(strict_types=1);

namespace Tests\Modules\Groups\View;

use PHPUnit\Framework\TestCase;
use Tests\TestTwig;
use Twig\Environment;

/**
 * One invited member = one compact entry and ONE actions menu (#1351).
 *
 * The partial is rendered on its own with made-up rows, because what it has
 * to prove — the menu names the right login, a member without an account is
 * offered only the removal, nothing leaks to somebody who may not moderate —
 * depends on rows the controller test's stubbed resolver cannot produce
 * (several addresses behind one member, none at all).
 */
final class GroupMemberRowPartialTest extends TestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        $this->twig = TestTwig::create(['groups']);
    }

    /**
     * @param list<array{id: int, label: string, email: string, is_moderator: bool}> $logins
     * @return array<string, mixed>
     */
    private function row(array $logins, bool $isModerator = false, bool $unbound = false, bool $named = true): array
    {
        return [
            'member_id' => 7,
            'identity' => $named
                ? ['account_name' => 'Camille Martin', 'member_names' => ['Akéla']]
                : null,
            'is_moderator' => $isModerator,
            'moderation_unbound' => $unbound,
            'logins' => $logins,
        ];
    }

    private function login(int $id, string $email, bool $moderator = false): array
    {
        return ['id' => $id, 'label' => $email, 'email' => $email, 'is_moderator' => $moderator];
    }

    private function render(array $row, bool $canModerate = true): string
    {
        return $this->twig->render('@groups/partials/member_row.html.twig', [
            'row' => $row,
            'group' => (object) ['id' => 42],
            'can_moderate' => $canModerate,
        ]);
    }

    public function testOneMenuReplacesTheBigButtonsAndTheCross(): void
    {
        $html = $this->render($this->row([$this->login(1, 'camille@example.test')]));

        $this->assertSame(1, substr_count($html, 'data-bs-toggle="dropdown"'), 'exactly one menu per member');
        $this->assertStringContainsString('bi-three-dots-vertical', $html);
        $this->assertStringContainsString('aria-label="Actions pour Camille Martin"', $html);
        $this->assertStringNotContainsString('bi-x-lg', $html);
        $this->assertStringNotContainsString('btn-outline-danger', $html);
        $this->assertStringNotContainsString('btn-outline-secondary', $html);
    }

    public function testASingleAddressIsShownUnderTheNameAndTheMenuOffersTheTwoActions(): void
    {
        $html = $this->render($this->row([$this->login(1, 'camille@example.test')]));

        $this->assertStringContainsString('Camille Martin', $html);
        $this->assertStringContainsString('(Akéla)', $html);
        $this->assertStringContainsString('camille@example.test', $html);
        $this->assertStringContainsString('Nommer modérateur', $html);
        $this->assertStringContainsString('Retirer du groupe', $html);
        $this->assertStringContainsString('dropdown-item text-danger', $html, 'the usual destructive style');
        $this->assertStringContainsString('data-confirm="Retirer ce membre du groupe ?"', $html);
        $this->assertStringContainsString('action="/groups/42/remove-member"', $html);
        $this->assertStringContainsString('action="/groups/42/moderator"', $html);
    }

    public function testAModeratorShowsTheBadgeOnTheIdentityLineAndAsksBeforeRevoking(): void
    {
        $html = $this->render($this->row([$this->login(1, 'camille@example.test', true)], isModerator: true));

        $this->assertSame(1, substr_count($html, 'Modérateur</span>'), 'one badge, on the identity line');
        $this->assertStringContainsString('Retirer la modération', $html);
        $this->assertStringContainsString('data-confirm="Retirer la modération à Camille Martin ?"', $html);
        $this->assertStringContainsString('name="is_moderator" value="0"', $html);
    }

    /**
     * Several addresses behind one member: the menu says WHICH one is being
     * empowered or stripped, and each form carries its own account.
     */
    public function testSeveralAddressesGetOneModerationEntryEach(): void
    {
        $html = $this->render($this->row([
            $this->login(11, 'camille@example.test', true),
            $this->login(12, 'camille.pro@example.test'),
        ], isModerator: true));

        $this->assertStringContainsString('Retirer la modération — camille@example.test', $html);
        $this->assertStringContainsString('Nommer modérateur — camille.pro@example.test', $html);
        $this->assertStringContainsString('name="user_account_id" value="11"', $html);
        $this->assertStringContainsString('name="user_account_id" value="12"', $html);
        // The flag is a login's, so the badge sits on that address, and not on the identity line.
        $this->assertSame(1, substr_count($html, 'Modérateur</span>'));
        $this->assertMatchesRegularExpression('/camille@example\.test[^<]*<span[^>]*>Modérateur<\/span>/s', $html);
        $this->assertSame(1, substr_count($html, 'data-bs-toggle="dropdown"'), 'still one menu for the member');
    }

    public function testAMemberWithoutAnAccountOnlyOffersTheRemoval(): void
    {
        $html = $this->render($this->row([]));

        $this->assertStringNotContainsString('Nommer modérateur', $html);
        $this->assertStringNotContainsString('Retirer la modération', $html);
        $this->assertStringContainsString('Retirer du groupe', $html);
        $this->assertStringContainsString('Aucun compte ne peut se connecter', $html);
        $this->assertStringContainsString('small text-body-secondary', $html, 'the explanation is a secondary line');
    }

    public function testAModerationWithoutAnAddressSaysItMustBeReassigned(): void
    {
        $html = $this->render($this->row([$this->login(1, 'camille@example.test')], isModerator: true, unbound: true));

        $this->assertStringContainsString('Modération à réattribuer', $html);
        $this->assertStringContainsString('Modérateur</span>', $html);
    }

    public function testAMemberNobodyCanNameIsStillAnEntryWithItsNumber(): void
    {
        $html = $this->render($this->row([], named: false));

        $this->assertStringContainsString('Membre #7', $html);
        $this->assertStringContainsString('aria-label="Actions pour Membre #7"', $html);
    }

    /** What a moderator sees and nobody else: the addresses, and every control. */
    public function testSomebodyWhoMayNotModerateSeesNoAddressAndNoMenu(): void
    {
        $html = $this->render($this->row([$this->login(1, 'camille@example.test', true)], isModerator: true), canModerate: false);

        $this->assertStringContainsString('Camille Martin', $html);
        $this->assertStringContainsString('Modérateur', $html);
        $this->assertStringNotContainsString('camille@example.test', $html);
        $this->assertStringNotContainsString('dropdown', $html);
        $this->assertStringNotContainsString('<form', $html);
    }

    public function testNoHorizontalOverflowForAVeryLongNameOrAddress(): void
    {
        $html = $this->render($this->row([$this->login(1, str_repeat('tres.long', 12) . '@example.test')]));

        $this->assertStringContainsString('text-break', $html, 'a long address wraps rather than overflowing');
        $this->assertStringContainsString('min-w-0', $html, 'the identity block may shrink next to the menu');
    }
}
