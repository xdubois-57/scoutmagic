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
                $action = strtolower((string) ($route['action'] ?? ''));
                foreach ($writeVerbs as $verb) {
                    if (str_starts_with($action, $verb)) {
                        $this->assertSame(
                            'POST',
                            $route['method'],
                            $module . ' ' . $route['path'] . ' (' . $route['action'] . ') changes state.'
                        );
                    }
                }
            }
        }
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
        foreach (self::manifest('inbound_mail')->routes as $route) {
            $this->assertSame('superadmin', $route['role_min'], $route['path']);
        }
    }

    // ── Capability tokens (§13) ─────────────────────────────────────────

    public function testTheTrackingTokenIsStoredHashedAndNeverInTheClear(): void
    {
        $schema = (string) file_get_contents(self::root() . '/modules/rental/schema.sql');

        $this->assertMatchesRegularExpression('/tracking_token_hash/', $schema);
        $this->assertDoesNotMatchRegularExpression(
            '/tracking_token\s+(VARCHAR|CHAR|TEXT)/i',
            $schema,
            'A tracking token must exist only as a hash.'
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
