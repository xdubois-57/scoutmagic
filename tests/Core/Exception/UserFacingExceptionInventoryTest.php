<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Exception;

use Core\Exception\UserFacingException;
use PHPUnit\Framework\TestCase;

/**
 * Which exception classes are allowed to speak to a visitor, pinned.
 *
 * `UserFacingMessageTest` pins the RULE; this file pins the ANSWER. Marking
 * a class is a claim about every message it is ever constructed with, and
 * that claim is made once, in a docblock, by whoever added the interface —
 * a claim nothing re-checks is a claim that quietly stops being true. So
 * both sides are listed explicitly below: a new exception class fails this
 * test until someone puts it on one of the two lists, which is the moment
 * to actually read its messages.
 *
 * Three of these were on the wrong side when the policy landed:
 * `SettingException` spoke English into the settings dialog,
 * `ModuleException` printed `/var/www/html/modules/…/module.json` onto a
 * configuration page, and `MailException` forwarded PHPMailer's SMTP
 * transcript. None of them looked wrong at the call site.
 */
final class UserFacingExceptionInventoryTest extends TestCase
{
    /**
     * Messages written for the person reading the screen: French, whole
     * sentences, naming only things that visitor can see.
     *
     * @var list<class-string>
     */
    private const USER_FACING = [
        \Core\Badge\BadgeException::class,
        \Core\Config\SettingException::class,
        \Core\Exception\UserFacingException::class,
        \Core\File\UploadException::class,
        \Core\Image\ImageDimensionException::class,
        \Core\Import\ImportException::class,
        \Core\Import\RosterReplacementRefusedException::class,
        \Core\Maintenance\BackupException::class,
        \Core\Maintenance\UpdateException::class,
        \Core\Member\Duplicate\MergeException::class,
        \Core\Member\MemberEmailException::class,
        \Core\Member\SectionDocumentException::class,
        \Core\Member\SectionException::class,
        \Core\Module\ModuleRefusalException::class,
        \Core\Security\SsrfValidationException::class,
        \Core\Support\SupportPackageException::class,
        \Core\View\RgpdGenerationException::class,
        \Modules\Banner\Service\BannerException::class,
        \Modules\Calendar\Service\CalendarException::class,
        \Modules\Finance\Service\FinanceException::class,
        \Modules\Camps\Service\CampsException::class,
        \Modules\Gallery\Service\GalleryException::class,
        \Modules\Groups\Service\GroupsException::class,
        \Modules\InboundMail\Client\MailboxConnectionException::class,
        \Modules\LlmConnector\Api\LlmException::class,
        \Modules\MassMail\Service\AudienceImportException::class,
        \Modules\MassMail\Service\MailingListException::class,
        \Modules\MassMail\Service\MassMailException::class,
        \Modules\News\Service\NewsException::class,
        \Modules\Registration\Service\RegistrationException::class,
        \Modules\Rental\Service\RentalException::class,
        \Modules\Retro\Service\RetroException::class,
        \Modules\SosStaff\Provider\Ovh\OvhApiException::class,
        \Modules\SosStaff\Provider\ProviderException::class,
        \Modules\SosStaff\Service\SosException::class,
    ];

    /**
     * Technical by nature. Each is displayed, if at all, through
     * `UserFacingMessage::from()`, which substitutes the caller's fallback.
     * The comment on each says what would leak.
     *
     * @var array<class-string, string>
     */
    private const INTERNAL = [
        // Library and driver text, or internal identifiers.
        \Core\Cookie\CookieConsentException::class => 'English, names a cookie category and its consent state',
        \Core\Mail\MailException::class => "PHPMailer's ErrorInfo — an SMTP transcript, always English",
        \Core\Member\MemberNotFoundException::class => 'English, names a member_year id',
        \Core\Help\HelpException::class => 'English, and every message names a topic file path or a field name',
        \Core\Module\ModuleException::class => 'about fifty English manifest-validation messages, one with a filesystem path',
        \Core\Security\DecryptionException::class => 'English, and describes the shape of the ciphertext',
        \Core\Statistics\StatisticsException::class => 'English, names an unregistered setting key',
        // Carries structured data instead: the catcher composes the French.
        \Core\ScoutYear\ScoutYearTransitionVetoedException::class => 'by design — the catcher reads getBlockingRequestCount() and writes its own sentence',
    ];

    /**
     * `new SomeMarkedException($e->getMessage())` re-labels whatever `$e`
     * said as user-facing, and nothing downstream can tell. These five were
     * read one by one: each wraps an `ImageDimensionException`, whose
     * message is itself French and user-facing, so the text is safe.
     *
     * @var list<string> repo-relative paths
     */
    private const LAUNDERING_ALLOWLIST = [
        'core/File/UploadHandler.php',
        'core/Photo/ImageVariantProcessor.php',
        'core/Photo/LandscapeImageProcessor.php',
        'core/Photo/SectionPhotoProcessor.php',
        'core/Photo/UnitLogoProcessor.php',
    ];

    private static function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * Every `*Exception.php` under core/ and modules/*'/src/, as a
     * fully-qualified class name. The repo's one-class-per-file rule and
     * composer's PSR-4 map make the path enough to derive it.
     *
     * @return list<class-string>
     */
    private static function declaredExceptionClasses(): array
    {
        $root = self::repoRoot();
        $classes = [];

        foreach (glob($root . '/core/**/*Exception.php') ?: [] as $path) {
            $classes[] = 'Core\\' . str_replace('/', '\\', substr($path, strlen($root . '/core/'), -4));
        }
        foreach (glob($root . '/modules/*/src/**/*Exception.php') ?: [] as $path) {
            $rel = substr($path, strlen($root . '/modules/'), -4);
            [$moduleDir, $rest] = explode('/src/', $rel, 2);
            $namespace = str_replace(' ', '', ucwords(str_replace('_', ' ', $moduleDir)));
            $classes[] = 'Modules\\' . $namespace . '\\' . str_replace('/', '\\', $rest);
        }

        sort($classes);

        return $classes;
    }

    public function testEveryExceptionClassIsOnExactlyOneList(): void
    {
        $listed = array_merge(self::USER_FACING, array_keys(self::INTERNAL));
        $unlisted = [];

        foreach (self::declaredExceptionClasses() as $class) {
            if (!class_exists($class) && !interface_exists($class)) {
                self::fail("Could not resolve {$class} — has the PSR-4 map or the one-class-per-file rule changed?");
            }
            if (!in_array($class, $listed, true)) {
                $unlisted[] = $class;
            }
        }

        self::assertSame(
            [],
            $unlisted,
            "A new exception class has to be put on one of this file's two lists.\n"
            . "Read every `throw new` of it first: is every message French, a whole sentence,\n"
            . "and free of file paths, SQL, class names and library text? If yes, mark the class\n"
            . "with Core\\Exception\\UserFacingException and add it to USER_FACING. If no, add it\n"
            . "to INTERNAL with a note saying what would leak.\n  " . implode("\n  ", $unlisted)
        );
    }

    public function testTheMarkerMatchesTheList(): void
    {
        $wrong = [];

        foreach (self::USER_FACING as $class) {
            if ($class !== UserFacingException::class && !is_a($class, UserFacingException::class, true)) {
                $wrong[] = "{$class} is listed as user-facing but does not implement the marker";
            }
        }
        foreach (array_keys(self::INTERNAL) as $class) {
            if (is_a($class, UserFacingException::class, true)) {
                $wrong[] = "{$class} implements the marker but is listed as internal — one of the two is a mistake";
            }
        }

        self::assertSame([], $wrong, implode("\n", $wrong));
    }

    /**
     * `catch (\Throwable $e) { throw new SomeMarkedException($e->getMessage()) }`
     * re-labels whatever `$e` said as user-facing, and nothing downstream
     * can tell. `RentalException` had six of these: a PDO error laundered
     * into a class whose docblock promises French.
     *
     * The catch clause is what decides. Re-throwing the message of an
     * exception that is ITSELF marked is safe by construction — the wrap
     * only changes which class the caller catches, not what the sentence
     * says. So the fix for a violation is usually to narrow the catch to
     * the class you actually meant, which is worth doing anyway; failing
     * that, write a French sentence and pass the original as `$previous`.
     */
    public function testNoMarkedExceptionIsBuiltFromAnUntrustedMessage(): void
    {
        $shortToFqcn = [];
        foreach (self::USER_FACING as $class) {
            $shortToFqcn[substr((string) strrchr($class, '\\'), 1)] = $class;
        }
        // catch (A | B $e) { … new MarkedException($e->getMessage() … }
        $pattern = '~catch\s*\(\s*([^)]+?)\s*\$(\w+)\s*\)\s*\{[^{}]*?'
            . 'new\s+(' . implode('|', array_keys($shortToFqcn)) . ')\s*\(\s*\$\2->getMessage\(\)~s';

        $found = [];
        foreach (['core', 'modules'] as $base) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(self::repoRoot() . '/' . $base, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $rel = str_replace(self::repoRoot() . '/', '', $file->getPathname());
                if (in_array($rel, self::LAUNDERING_ALLOWLIST, true)) {
                    continue;
                }

                preg_match_all($pattern, (string) file_get_contents($file->getPathname()), $matches, PREG_SET_ORDER);
                foreach ($matches as $m) {
                    foreach (array_filter(array_map('trim', explode('|', $m[1]))) as $type) {
                        $short = ltrim((string) strrchr('\\' . ltrim($type, '\\'), '\\'), '\\');
                        if (!isset($shortToFqcn[$short])) {
                            $found[] = "{$rel} builds a {$m[3]} from the message of a caught {$type}";
                            break;
                        }
                    }
                }
            }
        }
        $found = array_values(array_unique($found));
        sort($found);

        self::assertSame(
            [],
            $found,
            "Narrow the catch to the class you actually meant, or write a French sentence\n"
            . "and pass the original as \$previous — re-labelling a technical message as\n"
            . "user-facing defeats the marker entirely.\n  " . implode("\n  ", $found)
        );
    }
}
