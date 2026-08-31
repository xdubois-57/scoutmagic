<?php

declare(strict_types=1);

namespace Tests\Security;

use Core\Module\ModuleManifest;
use PHPUnit\Framework\TestCase;

/**
 * The rentals hardening pass, as assertions rather than a checklist
 * (roadmap iteration 13, Travail C).
 *
 * These are source- and manifest-level guards for rules that hold across
 * the whole module and would otherwise be re-checked by hand at every
 * change — the same precedent the rest of `tests/Security/` sets. Each one
 * exists because getting it wrong is invisible in a diff and expensive in
 * production; the behavioural half of each rule is tested in the module's
 * own suites.
 */
class RentalHardeningAuditTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function manifest(string $module): ModuleManifest
    {
        return ModuleManifest::fromFile(self::root() . '/modules/' . $module . '/module.json');
    }

    /**
     * @return string[]
     */
    private static function sourceFiles(string $module): array
    {
        $files = [];
        $directory = new \RecursiveDirectoryIterator(self::root() . '/modules/' . $module . '/src');
        foreach (new \RecursiveIteratorIterator($directory) as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * A file's code with comments stripped, so an assertion about what the
     * code does is not defeated by a docblock explaining what it avoids.
     */
    private static function code(string $file): string
    {
        $code = '';
        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    // ── Every route declares its floor (§12) ────────────────────────────

    public function testEveryRentalRouteDeclaresARoleMin(): void
    {
        foreach (['rental', 'inbound_mail'] as $module) {
            foreach (self::manifest($module)->routes as $route) {
                $this->assertArrayHasKey('role_min', $route, $module . ' ' . ($route['path'] ?? '?'));
                $this->assertNotSame('', $route['role_min']);
            }
        }
    }

    public function testEveryStateChangingRentalRouteIsPost(): void
    {
        // A GET that changes something can be triggered by a crawler, a
        // prefetch or an <img src>, and carries no CSRF token.
        $writeVerbs = ['save', 'create', 'delete', 'add', 'update', 'move', 'detach', 'set', 'toggle', 'record', 'send', 'generate', 'attach', 'reclassify', 'archive', 'restore', 'decide', 'validate'];

        foreach (['rental', 'inbound_mail'] as $module) {
            foreach (self::manifest($module)->routes as $route) {
                $action = (string) ($route['action'] ?? '');
                foreach ($writeVerbs as $verb) {
                    // The verb must be the action's own first WORD, not
                    // merely its first letters: actions are camelCase, so
                    // "saveGrid" starts with the verb and "settings" —
                    // which happens to begin with "set" — does not. Matching
                    // on a bare prefix made a read-only page fail this audit
                    // for the sound of its name.
                    if (!self::actionStartsWithVerb($action, $verb)) {
                        continue;
                    }

                    $this->assertSame(
                        'POST',
                        $route['method'],
                        $module . ' ' . $route['path'] . ' (' . $route['action'] . ') changes state.'
                    );
                }
            }
        }
    }

    /**
     * Whether $action's first camelCase word is $verb — "saveGrid" for
     * "save", "delete" for "delete", but never "settings" for "set".
     */
    private static function actionStartsWithVerb(string $action, string $verb): bool
    {
        if (!str_starts_with(strtolower($action), $verb)) {
            return false;
        }

        $rest = substr($action, strlen($verb));

        return $rest === '' || ctype_upper($rest[0]);
    }

    /**
     * The renter's surfaces are the only public ones, and each is gated by
     * a capability token in its own path (§13).
     */
    public function testEveryPublicRenterRouteCarriesATokenInItsPath(): void
    {
        foreach (self::manifest('rental')->routes as $route) {
            if ($route['role_min'] !== 'public' || !str_contains($route['path'], '/suivi/')) {
                continue;
            }

            $this->assertStringContainsString(
                '{token}',
                $route['path'],
                $route['path'] . ' reaches a booking and must be gated by its token.'
            );
        }
    }

    public function testTheInboundMailConfigurationIsSuperadminOnly(): void
    {
        // A Staff d'U may use a configured mailbox; they must never see the
        // host, the account or anything that would let them reach it (§7.4).
        //
        // Scoped to the CONFIGURATION routes. `/courrier` is the Chef
        // d'Unité's general mailbox (§8.58): it shows messages, never a
        // host or an account, and it is admin by design — it is the third
        // of the three guarantees that make storing every message
        // defensible. Its own floor is pinned in
        // Tests\Modules\InboundMail\ModuleManifestTest, which also
        // forbids anything below admin there.
        foreach (self::manifest('inbound_mail')->routes as $route) {
            if (!str_starts_with((string) $route['path'], '/config/')) {
                continue;
            }

            $this->assertSame('superadmin', $route['role_min'], $route['path']);
        }
    }

    // ── Capability tokens (§13) ─────────────────────────────────────────

    /**
     * The token was a `password_hash()` until the module started emailing
     * the renter its decisions — a hash can only ever answer "is this the
     * token?", and an email has to carry the link itself. What this pins
     * is what did NOT change: the column is still unreadable to anyone
     * holding a copy of the database without the application key, which is
     * where every other identity column in that table already stands
     * (SECURITY.md §5).
     */
    public function testTheTrackingTokenIsNeverStoredInTheClear(): void
    {
        $schema = (string) file_get_contents(self::root() . '/modules/rental/schema.sql');

        $this->assertMatchesRegularExpression('/tracking_token_encrypted\s+BLOB/i', $schema);
        $this->assertDoesNotMatchRegularExpression(
            '/tracking_token\s+(VARCHAR|CHAR|TEXT)/i',
            $schema,
            'A tracking token is never a plain column.'
        );
    }

    /**
     * The one call that hands a credential back out
     * (`RentalBookingRepository::trackingTokenOf()`), and the short list of
     * places allowed to make it.
     *
     * A token reaching a template, a flash message or a JSON response
     * would be a capability handed to whoever is looking at the manager's
     * screen — including over their shoulder, and including in a support
     * screenshot. It belongs in a URL inside an email addressed to the
     * renter, and in the controller line that fetches it for one.
     */
    public function testOnlyTheEmailPathEverReadsATokenBack(): void
    {
        $callers = [];
        foreach (self::sourceFiles('rental') as $file) {
            if (str_contains(self::code($file), 'trackingTokenOf(')) {
                $callers[] = basename($file);
            }
        }
        sort($callers);

        $this->assertSame(
            [
                'RentalBookingRepository.php',
                'RentalBookingService.php',
                'RentalManagementController.php',
            ],
            $callers,
            'Reading a tracking token back is for building a renter email and nothing else.'
        );
    }

    public function testNoRentalCodeEverJournalsAToken(): void
    {
        // Possession of the token IS the authorisation, so a journal entry
        // carrying one is a permanent, readable credential.
        foreach (self::sourceFiles('rental') as $file) {
            $code = self::code($file);

            $this->assertDoesNotMatchRegularExpression(
                '/log\([^;]*\$(tracking)?[Tt]oken\b/s',
                $code,
                basename($file) . ' must never write a token to the journal.'
            );
        }
    }

    public function testTokensAreCryptographicallyRandomEverywhereTheyAreMinted(): void
    {
        foreach (array_merge(self::sourceFiles('rental'), self::sourceFiles('inbound_mail')) as $file) {
            $code = self::code($file);

            foreach (['mt_rand(', 'rand(', 'uniqid('] as $weak) {
                $this->assertStringNotContainsString(
                    $weak,
                    $code,
                    basename($file) . ' must not mint anything with ' . $weak
                );
            }
        }
    }

    // ── Calendar and ICS privacy (§14) ──────────────────────────────────

    public function testThePrivilegedRenderingIsBuiltSeparatelyRatherThanMaskedLater(): void
    {
        // §14: never build one detailed description and rely on a template
        // to hide it. Privacy applies before any HTML, JSON or ICS
        // serialisation.
        $provider = self::code(self::root() . '/modules/rental/src/Calendar/RentalVirtualEventProvider.php');

        $this->assertStringContainsString('private function publicBookingEvent(', $provider);
        $this->assertStringContainsString('private function detailedBookingEvent(', $provider);
    }

    public function testTheCalendarModuleNeverLearnsARentersName(): void
    {
        // The rental provider decides what a reader may see; the calendar
        // module renders what it is given and has no vocabulary for a
        // renter at all.
        foreach (self::sourceFiles('calendar') as $file) {
            $code = self::code($file);

            foreach (['renterName', 'renterEmail', 'renterOrganisation'] as $field) {
                $this->assertStringNotContainsString($field, $code, basename($file));
            }
        }
    }

    // ── Files (§6.24, §7.9) ─────────────────────────────────────────────

    public function testNoRentalFileIsEverDeclaredUnderPublic(): void
    {
        foreach (['rental', 'inbound_mail'] as $module) {
            foreach (self::manifest($module)->storage as $path => $rules) {
                $this->assertStringNotContainsString('public', $path, $module . ' storage ' . $path);
                $this->assertNotSame(
                    'public',
                    $rules['role_min'] ?? null,
                    $module . ' storage ' . $path . ' must never be world-readable.'
                );
            }
        }
    }

    public function testEveryUploadGoesThroughTheHandlerRatherThanMovingFilesByHand(): void
    {
        // UploadHandler is what sniffs the real MIME, regenerates the name,
        // strips EXIF and keeps the file out of public/.
        foreach (array_merge(self::sourceFiles('rental'), self::sourceFiles('inbound_mail')) as $file) {
            $code = self::code($file);

            foreach (['move_uploaded_file(', 'copy($_FILES'] as $raw) {
                $this->assertStringNotContainsString(
                    $raw,
                    $code,
                    basename($file) . ' must upload through Core\\File\\UploadHandler.'
                );
            }
        }
    }

    // ── Superglobals stay out of the domain (roadmap §2) ────────────────

    public function testNoServiceOrRepositoryEverReadsASuperglobal(): void
    {
        foreach (array_merge(self::sourceFiles('rental'), self::sourceFiles('inbound_mail')) as $file) {
            if (!str_contains($file, '/Service/') && !str_contains($file, '/Repository/')) {
                continue;
            }

            $code = self::code($file);

            foreach (['$_POST', '$_GET', '$_SESSION', '$_COOKIE', '$_FILES', '$_REQUEST'] as $superglobal) {
                $this->assertStringNotContainsString(
                    $superglobal,
                    $code,
                    basename($file) . ' must be handed its inputs, not read them.'
                );
            }
        }
    }

    public function testNoControllerBuildsSqlOfItsOwn(): void
    {
        foreach (array_merge(self::sourceFiles('rental'), self::sourceFiles('inbound_mail')) as $file) {
            if (!str_contains($file, '/Controller/')) {
                continue;
            }

            $code = self::code($file);

            foreach (['SELECT ', 'INSERT INTO', 'UPDATE ', 'DELETE FROM'] as $sql) {
                $this->assertStringNotContainsString(
                    $sql,
                    $code,
                    basename($file) . ' must go through a repository.'
                );
            }
        }
    }

    // ── Offline caching never touches a private page (§6.23) ────────────

    public function testNeitherModuleWhitelistsAPrivatePageForOfflineUse(): void
    {
        // The offline layer caches reads onto a device. A cached booking
        // file is a renter's data sitting in somebody's browser storage.
        foreach (['rental', 'inbound_mail'] as $module) {
            $this->assertSame([], self::manifest($module)->offline, $module);
        }
    }

    // ── Scheduled tasks are idempotent (§6.29, §6.35) ───────────────────

    public function testEveryDeclaredTaskHandlerExistsAndCanBeConstructedWithoutArguments(): void
    {
        // SchedulerRunner auto-resolves a module handler with `new $class()`.
        // One that needs a constructor argument fails at run time, in a
        // background job nobody is watching.
        foreach (['rental', 'inbound_mail'] as $module) {
            foreach (self::manifest($module)->scheduledTasks as $task) {
                $class = $task['handler'];
                $this->assertTrue(class_exists($class), $class);

                $constructor = (new \ReflectionClass($class))->getConstructor();
                $required = $constructor?->getNumberOfRequiredParameters() ?? 0;

                $this->assertSame(0, $required, $class . ' must be constructible with no arguments.');
            }
        }
    }

    public function testEveryRepeatingTaskReschedulesItself(): void
    {
        // Core\Scheduler has no recurring-task concept: a handler that does
        // not re-arm runs exactly once, ever.
        foreach (['rental', 'inbound_mail'] as $module) {
            foreach (self::manifest($module)->scheduledTasks as $task) {
                $file = (new \ReflectionClass($task['handler']))->getFileName();
                $this->assertIsString($file);

                $this->assertStringContainsString(
                    'scheduleAfter(',
                    self::code($file),
                    basename($file) . ' must re-arm its own chain.'
                );
            }
        }
    }

    // ── Encryption at rest (SECURITY.md §5) ─────────────────────────────

    public function testEveryPersonalColumnIsABlob(): void
    {
        $schema = (string) file_get_contents(self::root() . '/modules/rental/schema.sql');

        foreach (['renter_name', 'renter_email', 'renter_phone', 'renter_organisation'] as $column) {
            $this->assertMatchesRegularExpression(
                '/' . $column . '_encrypted\s+BLOB/i',
                $schema,
                $column . ' holds personal data and must be an encrypted BLOB.'
            );
        }
    }

    public function testASearchableAddressIsABlindIndexRatherThanAPlainColumn(): void
    {
        $schema = (string) file_get_contents(self::root() . '/modules/rental/schema.sql');

        $this->assertMatchesRegularExpression('/renter_email_blind_index\s+CHAR\(64\)/i', $schema);
    }
}
