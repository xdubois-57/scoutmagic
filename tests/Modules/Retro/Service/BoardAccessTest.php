<?php

declare(strict_types=1);

namespace Tests\Modules\Retro\Service;

use Core\Security\Role;
use Modules\Retro\Repository\Board;
use Modules\Retro\Service\BoardAccess;
use PHPUnit\Framework\TestCase;

/**
 * Issue #833: the one answer to « may this reader open this board? ».
 */
class BoardAccessTest extends TestCase
{
    private function board(string $accessMode, string $status = 'open'): Board
    {
        return new Board(
            id: 1,
            title: 'Camp',
            boardDate: '2026-07-01',
            calendarEventId: 42,
            token: 'tok',
            shortCode: null,
            status: $status,
            accessMode: $accessMode,
            voteMode: 'unlimited',
            voteBudget: 5,
            votesVisible: true,
            maxCommentLength: 140,
            autoCloseDelay: 'none',
            autoCloseAt: null,
            aiSummary: null,
            createdBy: null,
            closeNotifyEnabled: false,
            closeNotifyEmail: null,
            createdAt: '2026-07-01 10:00:00',
            closedAt: null
        );
    }

    /**
     * @return array<string, array{0: string, 1: Role, 2: bool, 3: string}>
     */
    public static function decisions(): array
    {
        return [
            'animators: anonymous is asked to log in' => ['animators', Role::PUBLIC, false, BoardAccess::LOGIN_REQUIRED],
            'animators: a parent is refused' => ['animators', Role::IDENTIFIED, true, BoardAccess::FORBIDDEN],
            'animators: an intendant enters' => ['animators', Role::INTENDANT, true, BoardAccess::ALLOWED],
            'animators: a superadmin enters' => ['animators', Role::SUPERADMIN, true, BoardAccess::ALLOWED],
            'identified: anonymous is asked to log in' => ['identified', Role::PUBLIC, false, BoardAccess::LOGIN_REQUIRED],
            'identified: a parent enters' => ['identified', Role::IDENTIFIED, true, BoardAccess::ALLOWED],
            'identified: a chief enters' => ['identified', Role::CHIEF, true, BoardAccess::ALLOWED],
            'public: anonymous enters' => ['public', Role::PUBLIC, false, BoardAccess::ALLOWED],
            'public: a parent enters' => ['public', Role::IDENTIFIED, true, BoardAccess::ALLOWED],
            'unknown mode closes like animators' => ['nonsense', Role::IDENTIFIED, true, BoardAccess::FORBIDDEN],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('decisions')]
    public function testDecide(string $mode, Role $role, bool $authenticated, string $expected): void
    {
        $this->assertSame($expected, (new BoardAccess())->decide($this->board($mode), $role, $authenticated));
    }

    public function testTheLinkIsShownOnlyWhileOpenAndNeverForAPublicBoard(): void
    {
        $access = new BoardAccess();

        $this->assertTrue($access->mayShowLinkTo($this->board('animators'), Role::INTENDANT));
        $this->assertFalse($access->mayShowLinkTo($this->board('animators'), Role::IDENTIFIED));
        $this->assertTrue($access->mayShowLinkTo($this->board('identified'), Role::IDENTIFIED));
        $this->assertFalse($access->mayShowLinkTo($this->board('identified'), Role::PUBLIC));
        $this->assertFalse($access->mayShowLinkTo($this->board('public'), Role::SUPERADMIN));
        $this->assertFalse($access->mayShowLinkTo($this->board('animators', 'closed'), Role::SUPERADMIN));
        $this->assertFalse($access->mayShowLinkTo($this->board('identified', 'archived'), Role::SUPERADMIN));
    }

    public function testVotesAreKeyedByAccountExactlyWhenTheBoardNeedsALogin(): void
    {
        $access = new BoardAccess();

        $this->assertTrue($access->keysVotesByAccount($this->board('animators')));
        $this->assertTrue($access->keysVotesByAccount($this->board('identified')));
        $this->assertFalse($access->keysVotesByAccount($this->board('public')));
    }
}
