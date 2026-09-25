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
    /**
     * Every file allowed to change the key pair.
     *
     * `OutboundMailController` joined the list when the regeneration moved
     * there (issue #336), and **this test is how that move was sequenced**:
     * taking the checkbox off « Installation & serveur » dropped the count
     * from four sites to three, and the floor below said, in as many words,
     * « Move the test, do not delete it ». It was right.
     *
     * A list rather than a scan of `core/`: these two controllers are where
     * the key is allowed to change at all, and a third file appearing
     * should be a decision somebody writes down here — not something a
     * wildcard absorbs in silence.
     */
    private const CONTROLLERS = [
        'core/Http/Controller/SetupController.php',
        'core/Http/Controller/OutboundMailController.php',
    ];

    /** Mutations of the key pair. `hasKey()` and `getPublicKey()` read. */
    private const MUTATORS = ['generateKey', 'deleteKey'];

    /**
     * The two spellings of « forget the reading », both of which really do.
     *
     * `SetupController` wraps it in a private method that swallows its own
     * failure, because two of its call sites run while the installation is
     * being built or torn down. `OutboundMailController` calls
     * `DnsCheckMemory::forget()` straight, exactly as its own `checkDns()`
     * does one method below. Accepting only the first spelling would have
     * made this guard demand a wrapper for its own sake.
     */
    private const FORGETS = ['$this->forgetDnsReading()', 'DnsCheckMemory::forget('];

    public function testEveryPlaceThatChangesTheDkimKeyAlsoForgetsTheDnsReading(): void
    {
        $found = 0;

        foreach (self::CONTROLLERS as $controller) {
            $lines = $this->controllerLines($controller);

            foreach ($lines as $number => $line) {
                if (!$this->isAMutation($line)) {
                    continue;
                }

                $found++;
                $this->assertTrue(
                    $this->forgetsWithin($lines, $number),
                    sprintf(
                        '%s:%d changes the DKIM key pair and nothing in that method forgets the remembered '
                            . 'DNS reading — before the change or after it. That reading then keeps reporting '
                            . 'the PREVIOUS key as published, and the dashboard shows a green tick over mail '
                            . 'that every receiver rejects.',
                        $controller,
                        $number + 1
                    )
                );
            }
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
            // Two property names for one dependency: `dkimManager` in
            // SetupController, `dkim` in OutboundMailController. Matching
            // the METHOD and requiring a `->` before it keeps this from
            // reading `DkimManager::generateKey` in a docblock.
            if (
                str_contains($line, '$this->dkimManager->' . $mutator . '(')
                || str_contains($line, '$this->dkim->' . $mutator . '(')
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Anywhere in the SAME METHOD, before the mutation or after it.
     *
     * This began as a three-line lookahead, which reads « just after » —
     * and a reviewer moved the forget deliberately to BEFORE the
     * generation, so that a generation which throws still drops a reading
     * describing the key `deleteKey()` had already removed. That ordering
     * serves this rule better than the one the window enforced, and the
     * window failed it. A rule its own guard punishes you for obeying
     * properly is a guard measuring the wrong thing.
     *
     * The method is the right unit: it is the block that owns the change,
     * it survives reformatting, and it still fails the file that changes
     * the key in one method and forgets in another — which is the mistake
     * this test exists for.
     *
     * @param array<int, string> $lines
     */
    private function forgetsWithin(array $lines, int $from): bool
    {
        [$start, $end] = $this->methodAround($lines, $from);

        for ($i = $start; $i <= $end; $i++) {
            foreach (self::FORGETS as $forgets) {
                if (str_contains($lines[$i], $forgets)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The bounds of the method holding a line: from its own declaration to
     * the line before the next one, or to the end of the file.
     *
     * @param array<int, string> $lines
     * @return array{int, int}
     */
    private function methodAround(array $lines, int $at): array
    {
        $declaration = '/^\s{4}(?:final\s+|abstract\s+|static\s+)*(?:public|protected|private)\s'
            . '(?:[\w\s]*\s)?function\s/';

        $start = 0;
        for ($i = $at; $i >= 0; $i--) {
            if (preg_match($declaration, $lines[$i]) === 1) {
                $start = $i;
                break;
            }
        }

        $end = count($lines) - 1;
        for ($i = $at + 1; $i < count($lines); $i++) {
            if (preg_match($declaration, $lines[$i]) === 1) {
                $end = $i - 1;
                break;
            }
        }

        return [$start, $end];
    }

    /**
     * The reader, on literal source — because the sweep cannot hold it.
     *
     * Every key-changing site in this repository forgets the reading, so a
     * reader that answered `true` to everything would pass the sweep above
     * without a murmur. Only a fixture whose answer is known in advance
     * says whether this one still refuses.
     */
    public function testTheReaderRefusesAMethodThatForgetsNothing(): void
    {
        $forgetsAfter = <<<'FIXTURE'
            class X
            {
                public function rotate(): void
                {
                    $this->dkim->deleteKey();
                    $this->dkim->generateKey();
                    DnsCheckMemory::forget($this->settings);
                }
            }
            FIXTURE;
        $forgetsBefore = <<<'FIXTURE'
            class X
            {
                public function rotate(): void
                {
                    $this->dkim->deleteKey();
                    DnsCheckMemory::forget($this->settings);

                    try {
                        $this->dkim->generateKey();
                    } catch (\Throwable $e) {
                        return;
                    }
                }
            }
            FIXTURE;
        $forgetsInAnotherMethod = <<<'FIXTURE'
            class X
            {
                public function rotate(): void
                {
                    $this->dkim->deleteKey();
                    $this->dkim->generateKey();
                }

                public function somethingElse(): void
                {
                    DnsCheckMemory::forget($this->settings);
                }
            }
            FIXTURE;

        $this->assertTrue($this->firstMutationForgets($forgetsAfter), 'the original shape still passes');
        $this->assertTrue(
            $this->firstMutationForgets($forgetsBefore),
            'forgetting BEFORE a generation that may throw is the better order, not a violation'
        );
        $this->assertFalse(
            $this->firstMutationForgets($forgetsInAnotherMethod),
            'a forget in a neighbouring method answers for nothing — that is the mistake this test exists for'
        );
    }

    /** Runs the real readers over a literal snippet. */
    private function firstMutationForgets(string $source): bool
    {
        $lines = explode("\n", $source);
        foreach ($lines as $number => $line) {
            if ($this->isAMutation($line)) {
                return $this->forgetsWithin($lines, $number);
            }
        }

        $this->fail('the fixture holds no key mutation at all');
    }

    /** @return array<int, string> */
    private function controllerLines(string $controller): array
    {
        $path = dirname(__DIR__, 2) . '/' . $controller;
        $contents = file_get_contents($path);
        $this->assertIsString($contents, $controller . ' could not be read.');

        return explode("\n", $contents);
    }
}
