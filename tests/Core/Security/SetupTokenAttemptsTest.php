<?php

declare(strict_types=1);

namespace Tests\Core\Security;

use Core\Security\SetupTokenAttempts;
use PHPUnit\Framework\TestCase;

/**
 * The setup wizard's token gate counts its attempts on the server (#1270),
 * in the very file the bootstrap installer uses, under the same rules: the
 * attempt is recorded before the comparison, one that cannot be recorded is
 * not compared, and the count is global — a cleared cookie resets nothing.
 */
final class SetupTokenAttemptsTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private string $dir;
    private string $token;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/setup_attempts_' . uniqid('', true);
        mkdir($this->dir, 0700, true);
        $this->token = str_repeat('ab', 32);
    }

    protected function tearDown(): void
    {
        foreach (array_diff(scandir($this->dir) ?: [], ['.', '..']) as $entry) {
            $path = $this->dir . '/' . $entry;
            is_dir($path) ? rmdir($path) : unlink($path);
        }
        rmdir($this->dir);
    }

    private function gate(): SetupTokenAttempts
    {
        return new SetupTokenAttempts($this->dir);
    }

    public function testTheRightTokenIsAcceptedAndResetsTheCount(): void
    {
        $gate = $this->gate();
        $gate->verify('wrong', $this->token, self::NOW);
        $gate->verify('wrong', $this->token, self::NOW);

        $this->assertTrue($gate->verify($this->token, $this->token, self::NOW)['ok']);
        $this->assertSame(0, $gate->lockedUntil(self::NOW));
        // Three more failures do not lock: the two before the success are forgotten.
        for ($i = 0; $i < 3; $i++) {
            $this->assertArrayNotHasKey('locked_until', $gate->verify('wrong', $this->token, self::NOW));
        }
    }

    public function testAWrongTokenIsRefusedWithTheAttemptCounted(): void
    {
        $result = $this->gate()->verify('wrong', $this->token, self::NOW);

        $this->assertFalse($result['ok']);
        $this->assertSame('Jeton invalide.', $result['error']);
        $this->assertSame(1, $result['attempts']);
    }

    /** @return array<string, array{0: int, 1: int}> attempts => lock seconds */
    public static function ladder(): array
    {
        return [
            '4 failures: one minute' => [4, 60],
            '6 failures: five minutes' => [6, 300],
            '8 failures: thirty minutes' => [8, 1800],
            '10 failures: one day' => [10, 86400],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ladder')]
    public function testTheLockoutLadderIsTheBootstrapOne(int $failures, int $seconds): void
    {
        $gate = $this->gate();
        $last = [];
        for ($i = 0; $i < $failures; $i++) {
            // Each attempt past a lock waits it out, as a real operator would.
            $last = $gate->verify('wrong', $this->token, self::NOW + $i * 100_000);
        }

        $this->assertSame(self::NOW + ($failures - 1) * 100_000 + $seconds, $last['locked_until'] ?? null);
    }

    public function testALockedGateRefusesTheRightTokenUnread(): void
    {
        $gate = $this->gate();
        for ($i = 0; $i < 4; $i++) {
            $gate->verify('wrong', $this->token, self::NOW);
        }

        $result = $gate->verify($this->token, $this->token, self::NOW + 30);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Trop de tentatives', $result['error']);
        $this->assertSame(self::NOW + 60, $gate->lockedUntil(self::NOW + 30));
        $this->assertSame(0, $gate->lockedUntil(self::NOW + 61));
        $this->assertTrue($gate->verify($this->token, $this->token, self::NOW + 61)['ok']);
    }

    /** The point of #1270: no cookie, no session, no new count. */
    public function testTheCountSurvivesAnotherInstanceAsANewSessionWould(): void
    {
        for ($i = 0; $i < 4; $i++) {
            (new SetupTokenAttempts($this->dir))->verify('wrong', $this->token, self::NOW);
        }

        $fresh = new SetupTokenAttempts($this->dir);

        $this->assertSame(self::NOW + 60, $fresh->lockedUntil(self::NOW));
        $this->assertFalse($fresh->verify($this->token, $this->token, self::NOW)['ok']);
    }

    public function testATokenIsNotComparedWhenItsAttemptCannotBeRecorded(): void
    {
        // A directory where the file should be: no write succeeds, root included.
        mkdir($this->dir . '/' . SetupTokenAttempts::ACCESS_FILE);

        $result = $this->gate()->verify($this->token, $this->token, self::NOW);

        rmdir($this->dir . '/' . SetupTokenAttempts::ACCESS_FILE);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('écriture', $result['error']);
    }

    public function testATokenIsNotComparedWhenTheAttemptsCannotBeLocked(): void
    {
        mkdir($this->dir . '/' . SetupTokenAttempts::LOCK_FILE);

        $result = $this->gate()->verify($this->token, $this->token, self::NOW);

        rmdir($this->dir . '/' . SetupTokenAttempts::LOCK_FILE);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('écriture', $result['error']);
    }

    public function testAnAbsentOrShortExpectedTokenIsNeverMatched(): void
    {
        $gate = $this->gate();

        $this->assertFalse($gate->verify('', '', self::NOW)['ok']);
        $this->assertFalse($gate->verify('abc', 'abc', self::NOW)['ok']);
        $this->assertFalse($gate->verify(str_repeat('a', 63), str_repeat('a', 63), self::NOW)['ok']);
        $this->assertFalse($gate->verify(str_repeat('a', 65), str_repeat('a', 65), self::NOW)['ok']);
    }

    public function testTheTypedTokenIsTrimmedAndCaseInsensitive(): void
    {
        $this->assertTrue($this->gate()->verify(' ' . strtoupper($this->token) . "\n", $this->token, self::NOW)['ok']);
    }

    public function testATypedTokenOfAnotherLengthIsNeverCompared(): void
    {
        $result = $this->gate()->verify($this->token . 'ab', $this->token, self::NOW);

        $this->assertFalse($result['ok']);
        $this->assertSame(1, $result['attempts'], 'it is a guess all the same, and counts as one');
    }

    public function testTheExpectedTokenIsReadAs64HexCharactersOnly(): void
    {
        $this->assertSame(
            $this->token,
            SetupTokenAttempts::tokenFrom('<?php /* TOKEN: ' . strtoupper($this->token) . " */\n")
        );
        $this->assertSame('', SetupTokenAttempts::tokenFrom("<?php /* TOKEN: abc123 */\n"));
        $this->assertSame('', SetupTokenAttempts::tokenFrom('<?php /* TOKEN: ' . str_repeat('a', 63) . " */\n"));
        $this->assertSame('', SetupTokenAttempts::tokenFrom(''));
    }

    public function testClearRemovesTheCountAndItsLock(): void
    {
        $gate = $this->gate();
        $gate->verify('wrong', $this->token, self::NOW);

        $gate->clear();

        $this->assertFileDoesNotExist($this->dir . '/' . SetupTokenAttempts::ACCESS_FILE);
        $this->assertFileDoesNotExist($this->dir . '/' . SetupTokenAttempts::LOCK_FILE);
    }
}
