<?php

declare(strict_types=1);

namespace Tests\Security;

use PHPUnit\Framework\TestCase;

/**
 * `new \DateTimeImmutable($variable)` is banned, with four exceptions
 * named below.
 *
 * The constructor has two edges, and they pull in opposite directions:
 * it THROWS on a malformed string, and it silently answers *the current
 * moment* for `''`, `'now'` and `"a\0b"`. So the same call turns one bad
 * value into a 500 and another into today's date, believed and stored.
 * `Core\Service\DateInput::fromStorage()` answers null for both, and
 * `::requireFromStorage()` answers loudly (SECURITY.md § 35).
 *
 * This started as a ratchet over 161 sites. It is now a ban, because all
 * 161 were converted and the equivalence was pinned test-by-test in
 * Tests\Core\Service\DateInputEquivalenceTest — which is the file to
 * read before doubting that the conversion preserved behaviour.
 *
 * WHY EXCEPTIONS AND NOT ZERO
 *
 * `fromStorage()` refuses a relative expression on purpose: a stored
 * moment that reads differently every time is not a stored moment. But
 * "+1 day" and "Mon, 12 Jul 2027 09:30:00 +0200" are both perfectly
 * legitimate arguments to the constructor — they are simply not values
 * that came out of a column. Forcing those four through a reader built
 * to refuse them would be the wrong kind of tidy: the code would look
 * uniform and do less.
 *
 * So each exception carries its reason here, and the reason is repeated
 * at the call site. A fifth one is a decision somebody has to defend in
 * this file, which is the entire point.
 *
 * BOTH DIRECTIONS, like Tests\Core\View\UxConventionsTest: a new site
 * fails, and so does a stale entry for a file that no longer has one.
 */
class StoredDateReadingRatchetTest extends TestCase
{
    /**
     * The four files allowed to call the constructor with a variable, and
     * why. Permanent — this is not debt, and it is not a budget for the
     * next person to spend.
     *
     * @var array<string, array{count: int, reason: string}>
     */
    private const DELIBERATE = [
        'core/Service/DateInput.php' => [
            'count' => 1,
            'reason' => 'The home. Every other reading in the project goes through this one, '
                . 'inside the try/catch that is the whole point of the class.',
        ],
        'core/Scheduler/SchedulerService.php' => [
            'count' => 1,
            'reason' => 'rearm() takes a strtotime-style expression ("tomorrow 05:00") from a '
                . 'task handler in this repository, never from a request. The one edge worth '
                . 'closing — the empty string, which is *now* — is refused explicitly there.',
        ],
        'core/Maintenance/Task/AutoBackupHandler.php' => [
            'count' => 1,
            'reason' => 'A relative expression looked up in a class constant with a literal '
                . 'fallback, so the value is always one of this class\'s own constants.',
        ],
        'modules/inbound_mail/src/Mime/MimeMessageParser.php' => [
            'count' => 1,
            'reason' => 'An RFC 2822 Date: header, not a stored timestamp. fromStorage() '
                . 'requires the value to open with an ISO calendar date and would refuse every '
                . 'well-formed mail header there is. The blank and the malformed value are both '
                . 'already handled at the call site.',
        ],
    ];

    /**
     * @return array<string, int> file => number of variable-argument calls
     */
    private static function found(): array
    {
        $repoRoot = dirname(__DIR__, 2);
        $found = [];

        foreach (['core', 'modules', 'public', 'bootstrap'] as $directory) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($repoRoot . '/' . $directory, \FilesystemIterator::SKIP_DOTS)
            );
            /** @var \SplFileInfo $file */
            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $relative = substr($file->getPathname(), strlen($repoRoot) + 1);
                $count = 0;

                foreach (file($file->getPathname()) ?: [] as $line) {
                    if (preg_match_all('/new \\\\DateTimeImmutable\(/', $line, $matches, PREG_OFFSET_CAPTURE) === 0) {
                        continue;
                    }
                    foreach ($matches[0] as $match) {
                        $argument = substr($line, $match[1] + strlen($match[0]));
                        // `)` is the no-argument form; a quote opens a
                        // literal. Everything else is a variable.
                        if (preg_match('/^\s*[)\'"]/', $argument) === 1) {
                            continue;
                        }
                        $count++;
                    }
                }

                if ($count > 0) {
                    $found[$relative] = $count;
                }
            }
        }

        ksort($found);

        return $found;
    }

    public function testNoSiteReadsADateWithTheRawConstructor(): void
    {
        $offenders = [];

        foreach (self::found() as $file => $count) {
            $allowed = self::DELIBERATE[$file]['count'] ?? 0;
            if ($count > $allowed) {
                $offenders[] = "{$file}: {$count} (deliberate: {$allowed})";
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Read the value through Core\\Service\\DateInput::fromStorage() — or\n"
            . "::requireFromStorage() where the schema says the value is always there.\n"
            . "`new DateTimeImmutable(\$v)` throws on a malformed string AND answers *now* for an\n"
            . "empty one, so one bad value 500s the page and another silently becomes today's date.\n"
            . "If this really is a relative expression or a mail header rather than a stored\n"
            . "moment, add it to self::DELIBERATE with the reason, and say the same at the call\n"
            . "site:\n" . implode("\n", $offenders)
        );
    }

    /**
     * The other direction. Without it the list becomes a description of
     * the codebase as it was, and a file that was cleaned up keeps its
     * budget for the next person to spend.
     */
    public function testTheListOfDeliberateSitesIsNotStale(): void
    {
        $found = self::found();
        $stale = [];

        foreach (self::DELIBERATE as $file => $exception) {
            $count = $found[$file] ?? 0;
            if ($count < $exception['count']) {
                $stale[] = "{$file} is listed for {$exception['count']} but now has {$count}"
                    . ' — shrink or remove the entry';
            }
        }

        $this->assertSame([], $stale);
    }

    /**
     * An exception with no reason is an allowlist entry wearing a
     * disguise. This is what stops the list growing back.
     */
    public function testEveryDeliberateSiteSaysWhy(): void
    {
        foreach (self::DELIBERATE as $file => $exception) {
            $this->assertGreaterThan(
                80,
                strlen($exception['reason']),
                "{$file} needs a real reason, not a label"
            );
        }
    }

    /**
     * The safe readings exist and are the ones to reach for. Pinned so
     * the failure message above never points at something that was
     * deleted.
     */
    public function testTheReplacementExists(): void
    {
        $this->assertTrue(method_exists(\Core\Service\DateInput::class, 'fromStorage'));
        $this->assertTrue(method_exists(\Core\Service\DateInput::class, 'requireFromStorage'));
        $this->assertNull(\Core\Service\DateInput::fromStorage(''));
        $this->assertNull(\Core\Service\DateInput::fromStorage('0000-00-00 00:00:00'));
    }
}
