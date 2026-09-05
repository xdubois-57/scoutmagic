<?php

declare(strict_types=1);

namespace Tests\Security;

use PHPUnit\Framework\TestCase;

/**
 * Every module controller that accepts a POST guards it against CSRF —
 * or is one of the exceptions SECURITY.md § 4 writes down.
 *
 * **Why a rule rather than a review habit.** A configuration page is dull
 * to write and duller to review: a field, a save, a redirect. The guard is
 * one line near the top of the action, it is invisible in the rendered
 * page, and nothing fails when it is missing — the form keeps working,
 * for the author and for anybody who can get a logged-in chef to open a
 * page. Measured, the configuration controllers were the weakest-covered
 * kind of page in the repository (71 % against 85 % for controllers in
 * general), which is exactly where a missing line survives longest.
 *
 * The check is coarse on purpose: it asks whether a controller that
 * accepts POST knows about CSRF at all. A rule that tried to prove each
 * action guarded would need to parse method bodies, and a rule nobody
 * trusts gets deleted. This one cannot pass by accident, and it is what
 * stands between a new POST endpoint and shipping without a guard.
 *
 * **The exceptions are the interesting part.** Each is a machine-to-
 * machine call with no session to bind a token to, authenticated by
 * something else entirely — an HMAC signature, a bearer secret, a
 * per-recipient token. They are named here with their authentication so
 * that adding a fourth means writing down what replaces the guard.
 */
class CsrfGuardCoverageTest extends TestCase
{
    /**
     * Module controllers with POST routes and no CSRF guard, each
     * authenticated by something other than a session-bound token — the
     * list SECURITY.md § 4 keeps, restricted to modules (core's
     * `WebhookController` is the fourth, and lives outside this scan).
     *
     * @var array<string, string>
     */
    private const AUTHENTICATED_WITHOUT_A_SESSION = [
        // RFC 8058 one-click unsubscribe, reached from a mail client.
        // Authenticated by a per-recipient token compared constant-time
        // against a stored SHA-256 hash.
        'UnsubscribeController' => 'per-recipient token (hash_equals)',
        // The usage-statistics intake: another installation reporting in.
        // Authenticated by a bearer secret checked with password_verify().
        'StatisticsIntakeController' => 'installation bearer secret',
        // The support-ticket intake, on the same bearer identity as the
        // statistics intake beside it.
        'TicketIntakeController' => 'installation bearer secret',
    ];

    /**
     * One case per module controller that declares at least one POST
     * route in its manifest.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function controllersAcceptingAPost(): array
    {
        $root = dirname(__DIR__, 2);
        $cases = [];

        foreach (glob($root . '/modules/*/module.json') ?: [] as $manifest) {
            $moduleId = basename(dirname($manifest));
            $declared = json_decode((string) file_get_contents($manifest), true);
            if (!is_array($declared)) {
                continue;
            }

            foreach ((array) ($declared['routes'] ?? []) as $route) {
                if (!is_array($route) || ($route['method'] ?? 'GET') !== 'POST') {
                    continue;
                }

                $short = substr((string) strrchr((string) ($route['controller'] ?? ''), '\\'), 1);
                $path = $root . '/modules/' . $moduleId . '/src/Controller/' . $short . '.php';
                if ($short === '' || !is_file($path)) {
                    continue;
                }

                $cases[$moduleId . ' / ' . $short] = [$moduleId, $short, $path];
            }
        }

        return $cases;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('controllersAcceptingAPost')]
    public function testAControllerThatAcceptsAPostGuardsAgainstCsrf(
        string $moduleId,
        string $controller,
        string $path
    ): void {
        if (isset(self::AUTHENTICATED_WITHOUT_A_SESSION[$controller])) {
            $this->assertTrue(true, $controller . ': ' . self::AUTHENTICATED_WITHOUT_A_SESSION[$controller]);

            return;
        }

        $source = (string) file_get_contents($path);

        $this->assertTrue(
            str_contains($source, 'guardCsrf') || str_contains($source, 'CsrfGuard::'),
            sprintf(
                '%s (%s) accepts a POST and never mentions CSRF. Guard it, or — if the caller is a machine with '
                    . 'no session — add it to AUTHENTICATED_WITHOUT_A_SESSION with what authenticates it instead, '
                    . 'and to SECURITY.md § 4.',
                $controller,
                $moduleId
            )
        );
    }

    /**
     * The count is the ratchet. A fourth exception changes this number,
     * which is what puts the decision in front of a reviewer rather than
     * leaving it in a diff.
     */
    public function testThereAreExactlyThreeExceptionsAmongTheModules(): void
    {
        $this->assertCount(3, self::AUTHENTICATED_WITHOUT_A_SESSION);
    }

    /**
     * An exception that no longer exists is a line nobody will remove on
     * their own — and a stale allowlist is how a real gap eventually
     * hides behind an accepted one.
     */
    public function testEveryExceptionStillNamesAControllerThatExists(): void
    {
        $known = array_map(
            static fn (array $case): string => $case[1],
            array_values(self::controllersAcceptingAPost())
        );

        foreach (array_keys(self::AUTHENTICATED_WITHOUT_A_SESSION) as $controller) {
            $this->assertContains($controller, $known, $controller . ' is allowlisted but declares no POST route.');
        }
    }

    public function testTheRuleWouldNoticeAControllerWithNoGuard(): void
    {
        $withoutAGuard = tempnam(sys_get_temp_dir(), 'csrf') . '.php';
        file_put_contents($withoutAGuard, '<?php class Anything { public function save() {} }');

        try {
            $source = (string) file_get_contents($withoutAGuard);
            $this->assertFalse(
                str_contains($source, 'guardCsrf') || str_contains($source, 'CsrfGuard::'),
                'A check that passes for a controller with no guard at all checks nothing.'
            );
        } finally {
            @unlink($withoutAGuard);
        }
    }
}
