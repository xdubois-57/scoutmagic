<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * A DKIM key that changes leaves the remembered DNS reading behind
 * (roadmap IT-03).
 *
 * **Why this one invalidation is a call and not a derivation.**
 * `Core\Mail\DnsCheckMemory::describes()` catches the other three ways a
 * reading goes stale — the SPF domain, the DKIM selector, the DMARC
 * target — because the reading carries all three and can compare them
 * against the identity configured now. It cannot catch a key that moved:
 * the reading holds the key it was taken against, and nothing inside it
 * can name the key in use today. Only the code that changes the key knows
 * that it changed.
 *
 * And the consequence of missing one is the worst kind. After a
 * regeneration the published `p=` holds the OLD public key, so every
 * signature the site makes fails against it — while the stored reading
 * still says `dkim.exists: true`, and the dashboard, the Authentification
 * sub-page and the support package all read that one reading through a
 * single gate. One forgotten call is a green tick on three screens for a
 * site whose mail is being rejected.
 *
 * Which is exactly the shape this chantier already warned about, in
 * `ReturnProbeRepository`'s own docblock: « une remise à zéro écrite comme
 * une méthode est une remise à zéro qu'on oublie d'appeler depuis le
 * deuxième endroit ». There it was avoidable by keying the state on the
 * address. Here it is not, so the second-best thing is a test that fails
 * when somebody adds a fifth key-changing site and forgets.
 *
 * The rule is absolute on purpose — no list of exempt call sites to keep
 * in step. `forgetDnsReading()` swallows its own failure, so the two
 * paths that run while the installation is being built or torn down can
 * call it as safely as the two that run on a live site.
 */
class DkimKeyChangeForgetsDnsTest extends TestCase
{
    private const CONTROLLER = 'core/Http/Controller/SetupController.php';

    /** Mutations of the key pair. `hasKey()` and `getPublicKey()` read. */
    private const MUTATORS = ['generateKey', 'deleteKey'];

    public function testEveryPlaceThatChangesTheDkimKeyAlsoForgetsTheDnsReading(): void
    {
        $lines = $this->controllerLines();
        $found = 0;

        foreach ($lines as $number => $line) {
            if (!$this->isAMutation($line)) {
                continue;
            }

            $found++;
            $this->assertTrue(
                $this->forgetsWithin($lines, $number),
                sprintf(
                    '%s:%d changes the DKIM key pair without calling $this->forgetDnsReading() just after. '
                        . 'The remembered DNS reading then keeps reporting the previous key as published, '
                        . 'and the dashboard shows a green tick over mail that every receiver rejects.',
                    self::CONTROLLER,
                    $number + 1
                )
            );
        }

        // A guard that guards nothing passes for the wrong reason: if the
        // mutations move to another class, this test must fail rather
        // than quietly find none.
        $this->assertGreaterThanOrEqual(
            4,
            $found,
            'The DKIM key mutations are no longer where this test looks. Move the test, do not delete it.'
        );
    }

    /**
     * A mutation, and not the word inside a comment explaining one — the
     * regeneration site has such a comment two lines above it.
     */
    private function isAMutation(string $line): bool
    {
        $trimmed = ltrim($line);
        if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*')) {
            return false;
        }

        foreach (self::MUTATORS as $mutator) {
            if (str_contains($line, '$this->dkimManager->' . $mutator . '(')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Within the same block: a `deleteKey()` immediately followed by a
     * `generateKey()` is one change, and one `forgetDnsReading()` after
     * the pair answers for both.
     *
     * @param array<int, string> $lines
     */
    private function forgetsWithin(array $lines, int $from): bool
    {
        for ($i = $from + 1; $i <= $from + 3; $i++) {
            if (!isset($lines[$i])) {
                return false;
            }
            if (str_contains($lines[$i], '$this->forgetDnsReading()')) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, string> */
    private function controllerLines(): array
    {
        $path = dirname(__DIR__, 2) . '/' . self::CONTROLLER;
        $contents = file_get_contents($path);
        $this->assertIsString($contents, self::CONTROLLER . ' could not be read.');

        return explode("\n", $contents);
    }
}
