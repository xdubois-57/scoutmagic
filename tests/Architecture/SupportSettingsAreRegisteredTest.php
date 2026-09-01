<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Every settings row the support senders write must be declared in the
 * composition root.
 *
 * **The defect this exists for was silent and permanent.** IT-26 and
 * IT-27 each added a sender that records what it did in `settings`, and
 * neither setting was ever registered. `SettingService::setInternal()`
 * throws on a key it does not know; both senders catch that throw on
 * purpose, so that bookkeeping can never turn a transmission that DID
 * happen into a failure an administrator would repeat. The two rules are
 * each right and together they produced a lie: the archive left, the
 * confirmation said so, and Configuration > Support went on showing
 * « Archive non transmise » for ever, because the reference it compares
 * against was never written. The probe's own « dernière sonde » line had
 * the same fault and nobody noticed, because its failure mode is a line
 * that simply never appears.
 *
 * A unit test could not catch it: every one of them registers the
 * settings it needs in `setUp()`, which is exactly the assumption that
 * was wrong in production. Only a check against the real composition root
 * can.
 */
class SupportSettingsAreRegisteredTest extends TestCase
{
    /**
     * The classes whose `*_SETTING` constants name a row they write.
     *
     * @var list<class-string>
     */
    private const SENDERS = [
        \Core\Support\Ticket\SupportTicketSender::class,
        \Core\Support\Ticket\SupportArchiveSender::class,
        \Core\Support\Ticket\MailProbeSender::class,
    ];

    public function testEverySupportSenderSettingIsRegisteredInTheCompositionRoot(): void
    {
        $index = (string) file_get_contents(dirname(__DIR__, 2) . '/public/index.php');
        $missing = [];

        foreach (self::declaredSettings() as $class => $keys) {
            foreach ($keys as $constant => $key) {
                if (!str_contains($index, "register('" . $key . "'")) {
                    $missing[] = sprintf('%s::%s (%s)', self::shortName($class), $constant, $key);
                }
            }
        }

        $this->assertSame(
            [],
            $missing,
            "These settings are written by a support sender but never registered in public/index.php.\n"
            . "setInternal() throws on an unknown key and the senders swallow that throw, so the write\n"
            . "fails silently and the page reads a value that is never written:\n  "
            . implode("\n  ", $missing)
        );
    }

    /**
     * The constants exist to be read by name, so the guard reads them the
     * same way rather than repeating their values here — a copy would be
     * one more place to forget.
     */
    public function testTheGuardActuallyFoundSomeSettingsToCheck(): void
    {
        $count = 0;
        foreach (self::declaredSettings() as $keys) {
            $count += count($keys);
        }

        // Seven today. The assertion is a floor, not a pin: adding a
        // setting must not have to touch this file, but a reflection that
        // silently found nothing would make the test above vacuous.
        $this->assertGreaterThanOrEqual(7, $count);
    }

    /**
     * @return array<class-string, array<string, string>>
     */
    private static function declaredSettings(): array
    {
        $found = [];

        foreach (self::SENDERS as $class) {
            $constants = (new \ReflectionClass($class))->getConstants();
            $settings = [];

            foreach ($constants as $name => $value) {
                if (str_ends_with($name, '_SETTING') && is_string($value) && $value !== '') {
                    $settings[$name] = $value;
                }
            }

            $found[$class] = $settings;
        }

        return $found;
    }

    private static function shortName(string $class): string
    {
        return substr((string) strrchr($class, '\\'), 1);
    }
}
