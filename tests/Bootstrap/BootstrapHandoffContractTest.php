<?php

declare(strict_types=1);

namespace Tests\Bootstrap;

use Core\Maintenance\Portable\PortableManifest;
use Core\Security\BootstrapHandoff;
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
