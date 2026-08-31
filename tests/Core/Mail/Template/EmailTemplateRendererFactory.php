<?php

declare(strict_types=1);

namespace Tests\Core\Mail\Template;

use Core\Mail\Template\EmailTemplateOverrideRepository;
use Core\Mail\Template\EmailTemplateRegistry;
use Core\Mail\Template\EmailTemplateRenderer;
use Core\Module\ModuleManifest;
use Twig\Environment;

/**
 * The renderer a test needs when its subject is the SENDER rather than the
 * rendering — an AuthService, a MemberEmailService, a NotificationMailer.
 *
 * Deliberately the real registry and the real repository over the test
 * database, never a mock: with no row in `email_template_overrides` the
 * renderer takes the shipped-template path, which is exactly what those
 * tests were exercising when they passed a Twig instance directly. A
 * mocked renderer would let a sender stop rendering at all and nobody
 * would notice.
 *
 * Not a TestCase — it holds no assertions and runs no tests; it exists so
 * eleven test files do not each grow the same four lines of wiring.
 */
final class EmailTemplateRendererFactory
{
    public static function overTestDatabase(\PDO $pdo, Environment $twig): EmailTemplateRenderer
    {
        return new EmailTemplateRenderer($twig, new EmailTemplateRegistry(), new EmailTemplateOverrideRepository($pdo));
    }

    /**
     * The same thing for a MODULE's e-mails, and for a test that has no
     * database of its own — a service test built entirely from mocks,
     * which is most of them.
     *
     * The store is an empty in-memory SQLite holding only
     * `email_template_overrides`: no row, so every render takes the
     * shipped-template path. That is exactly the property these tests
     * were asserting before the switch — the module's rendering is
     * unchanged — and a renderer that could not reach a store at all
     * would prove it by accident rather than on purpose.
     *
     * @param string $moduleId the module whose module.json declares the
     *                         e-mails, e.g. 'sos_staff'
     */
    public static function shippedOnlyForModule(Environment $twig, string $moduleId): EmailTemplateRenderer
    {
        $registry = new EmailTemplateRegistry();
        $registry->registerModuleManifest(
            ModuleManifest::fromFile(dirname(__DIR__, 4) . '/modules/' . $moduleId . '/module.json')
        );

        return new EmailTemplateRenderer($twig, $registry, new EmailTemplateOverrideRepository(self::emptyStore()));
    }

    private static function emptyStore(): \PDO
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE email_template_overrides (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            template_id TEXT NOT NULL UNIQUE,
            subject TEXT NOT NULL,
            body_html TEXT NOT NULL,
            updated_at TEXT,
            updated_by INTEGER
        )');

        return $pdo;
    }
}
