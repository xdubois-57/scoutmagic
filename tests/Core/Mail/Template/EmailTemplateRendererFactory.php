<?php

declare(strict_types=1);

namespace Tests\Core\Mail\Template;

use Core\Mail\Template\EmailTemplateOverrideRepository;
use Core\Mail\Template\EmailTemplateRegistry;
use Core\Mail\Template\EmailTemplateRenderer;
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
}
