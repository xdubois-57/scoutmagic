<?php

declare(strict_types=1);

namespace Tests\Bootstrap;

use Core\Maintenance\Portable\PortableManifest;
use Core\Security\BootstrapHandoff;
use Core\Security\SetupTokenAttempts;
use PHPUnit\Framework\TestCase;

if (!defined('BOOTSTRAP_TEST')) {
    define('BOOTSTRAP_TEST', true);
}
require_once dirname(__DIR__, 2) . '/bootstrap/bootstrap.php';

/**
 * The bootstrap and the setup wizard agree (#719, D).
 *
 * bootstrap/bootstrap.php runs before vendor/ exists, so it cannot load
 * Core\Security\BootstrapHandoff: it carries a copy of every value the two
 * exchange. A copy drifts in silence — this pins it to the original, and
 * computes the proof cookie on both sides.
 */
final class BootstrapHandoffContractTest extends TestCase
{
    public function testEveryHandedOverValueIsTheWizardsOwn(): void
    {
        $this->assertSame(BootstrapHandoff::PROOF_COOKIE, \BOOTSTRAP_PROOF_COOKIE);
        $this->assertSame(BootstrapHandoff::PROOF_LIFETIME_SECONDS, \BOOTSTRAP_PROOF_LIFETIME_SECONDS);
        $this->assertSame(BootstrapHandoff::ARCHIVE_PATH, \BOOTSTRAP_ARCHIVE_PATH);
        $this->assertSame(BootstrapHandoff::INCOMING_DIR, \BOOTSTRAP_INCOMING_DIR);
        $this->assertSame(BootstrapHandoff::RESTORE_MODE_URL, \BOOTSTRAP_RESTORE_MODE_URL);
    }

    /**
     * The wizard counts wrong tokens in the bootstrap's own file (#1270):
     * the names, the keys and the ladder are copied on both sides, and a
     * count written by one is read by the other.
     */
    public function testTheWizardCountsItsTokenAttemptsInTheBootstrapsFile(): void
    {
        $this->assertSame(SetupTokenAttempts::ACCESS_FILE, \BOOTSTRAP_ACCESS_FILE);
        $this->assertSame(SetupTokenAttempts::LOCK_FILE, \BOOTSTRAP_ACCESS_LOCK_FILE);

        $dir = sys_get_temp_dir() . '/contract_access_' . uniqid('', true);
        mkdir($dir, 0700, true);
        $token = str_repeat('1f', 32);
        $now = 1_800_000_000;

        try {
            // Three wrong guesses typed at the bootstrap, one at the wizard: that is four.
            for ($i = 0; $i < 3; $i++) {
                \bootstrapVerifyToken($dir, 'wrong', $now);
            }
            $wizard = new SetupTokenAttempts($dir);
            $fourth = $wizard->verify('wrong', $token, $now);

            $this->assertSame(4, $fourth['attempts'] ?? null);
            $access = \bootstrapReadAccess($dir);
            $this->assertSame(4, $access[SetupTokenAttempts::KEY_ATTEMPTS] ?? null);
            $this->assertSame($now + 60, $access[SetupTokenAttempts::KEY_LOCKED_UNTIL] ?? null);
            $this->assertSame($now + 60, $wizard->lockedUntil($now));

            // The ladder is the same on both sides, step by step.
            foreach ([1, 3, 4, 5, 6, 7, 8, 9, 10, 11] as $attempts) {
                $this->assertSame(\bootstrapTokenLockSeconds($attempts), $this->wizardLockSeconds($attempts), "after {$attempts}");
            }
        } finally {
            self::removeDirectory($dir);
        }
    }

    /**
     * What is a token in token.php is decided the same way on both sides —
     * and a 65-character value is none, not its first 64 characters.
     */
    public function testBothSidesReadTheSameTokenFromTokenPhp(): void
    {
        $dir = sys_get_temp_dir() . '/contract_token_' . uniqid('', true);
        mkdir($dir, 0700, true);
        $good = str_repeat('1f', 32);
        $contents = [
            'exact' => "<?php /* TOKEN: {$good} */\n",
            'upper-case' => '<?php /* TOKEN: ' . strtoupper($good) . " */\n",
            'no space before the closing' => "<?php /* TOKEN: {$good}*/\n",
            'one too long' => "<?php /* TOKEN: {$good}a */\n",
            'one too short' => '<?php /* TOKEN: ' . substr($good, 1) . " */\n",
            'empty' => '',
        ];

        try {
            foreach ($contents as $label => $content) {
                file_put_contents($dir . '/' . \BOOTSTRAP_TOKEN_FILE, $content);
                $this->assertSame(
                    \bootstrapReadTokenValue($dir),
                    SetupTokenAttempts::tokenFrom($content),
                    $label
                );
            }
            file_put_contents($dir . '/' . \BOOTSTRAP_TOKEN_FILE, $contents['one too long']);
            $this->assertSame('', \bootstrapReadTokenValue($dir));
        } finally {
            self::removeDirectory($dir);
        }
    }

    private static function removeDirectory(string $dir): void
    {
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $entry) {
            unlink($dir . '/' . $entry);
        }
        rmdir($dir);
    }

    /** The seconds the wizard locks for after the given number of wrong guesses. */
    private function wizardLockSeconds(int $attempts): int
    {
        $dir = sys_get_temp_dir() . '/contract_ladder_' . uniqid('', true);
        mkdir($dir, 0700, true);
        $now = 1_800_000_000;
        file_put_contents(
            $dir . '/' . SetupTokenAttempts::ACCESS_FILE,
            "<?php\n/*\n" . json_encode([SetupTokenAttempts::KEY_ATTEMPTS => $attempts - 1]) . "\n*/\n"
        );

        try {
            $result = (new SetupTokenAttempts($dir))->verify('wrong', str_repeat('1f', 32), $now);

            return ($result['locked_until'] ?? $now) - $now;
        } finally {
            self::removeDirectory($dir);
        }
    }

    public function testTheArchiveCommentFormatIsTheOneTheWizardWrites(): void
    {
        $this->assertSame(PortableManifest::FORMAT, \BOOTSTRAP_PORTABLE_FORMAT);
        $this->assertSame(PortableManifest::FORMAT_VERSION, \BOOTSTRAP_PORTABLE_FORMAT_VERSION);
    }

    /** A cookie the bootstrap sets is one the wizard accepts, and the reverse. */
    public function testBothSidesComputeAndAcceptTheSameProof(): void
    {
        $token = str_repeat('e', 64);
        $now = 1_800_000_000;
        $expires = $now + BootstrapHandoff::PROOF_LIFETIME_SECONDS;

        $this->assertSame(BootstrapHandoff::proofValue($token, $expires), \bootstrapProofValue($token, $expires));
        $this->assertTrue(BootstrapHandoff::proofIsValid(\bootstrapProofValue($token, $expires), $token, $now));
        $this->assertTrue(\bootstrapProofIsValid(BootstrapHandoff::proofValue($token, $expires), $token, $now));
        $this->assertFalse(\bootstrapProofIsValid(BootstrapHandoff::proofValue($token, $expires + 1), $token, $now));
    }

    /** token.php's content is what the wizard's own reader extracts. */
    public function testTheTokenFileIsTheFormatTheWizardReads(): void
    {
        $content = \bootstrapTokenFileContent(str_repeat('a', 64));

        $this->assertMatchesRegularExpression('/TOKEN:\s*([0-9a-f]+)/i', $content);
    }
}
