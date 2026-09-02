<?php

declare(strict_types=1);

namespace Tests\Modules\MassMail;

use Core\View\TwigFactory;
use Modules\MassMail\Repository\Email;

/**
 * Renders `@mass_mail/compose.html.twig` — the composition page — with a
 * fixture context, for the tests that need the markup rather than the
 * controller behind it.
 *
 * Shared rather than copied because two suites need it from opposite ends
 * of the tree: the module's own rendering assertions, and
 * Tests\Core\View\DecorativeLabelMisuseTest, which walks every template
 * carrying a decorative group heading. When the dialog this page replaces
 * still existed, that second suite rendered it directly — a partial with
 * no context at all. A full page needs one, and needs it in exactly one
 * place.
 */
final class ComposePageRenderer
{
    /**
     * @param array<string, mixed> $overrides context entries to replace
     */
    public static function render(?Email $email = null, array $overrides = []): string
    {
        $root = dirname(__DIR__, 3);
        $twig = TwigFactory::create($root . '/core/View/templates', true, ['mass_mail' => $root . '/modules/mass_mail/views']);
        $twig->addGlobal('site_name', 'Unité Test');
        $twig->addGlobal('menus', null);
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_email', 'chef@example.org');
        $twig->addGlobal('current_user_role', 'admin');
        $twig->addGlobal('current_path', '/mass-mail/new');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('csp_nonce', 'n');
        $twig->addGlobal('effective_scout_year_id', 2);

        return $twig->render('@mass_mail/compose.html.twig', array_merge(self::context($email), $overrides));
    }

    /**
     * A draft, as the repository hands one over.
     */
    public static function draft(string $listType = Email::LIST_TYPE_DEFAULT_SECTION, string $status = Email::STATUS_DRAFT): Email
    {
        return new Email(
            7,
            'Réunion de section',
            '<p>Bonjour.</p>',
            1,
            $listType,
            null,
            $listType === Email::LIST_TYPE_DEFAULT_SECTION ? 1 : null,
            $listType === Email::LIST_TYPE_MAIL_MERGE ? 5 : null,
            $listType === Email::LIST_TYPE_MAIL_MERGE ? [] : [2],
            $status,
            '2026-08-01 10:00:00',
            '2026-08-01 10:00:00',
            null,
            null
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function context(?Email $email): array
    {
        return [
            'email' => $email,
            'form' => [
                'section_id' => 1,
                'list' => $email !== null
                    ? $email->listType . ':' . ($email->listSectionId ?? '')
                    : 'default_section:1',
                'scout_year_ids' => $email !== null ? $email->scoutYearIds : [2],
                'subject' => $email !== null ? $email->subject : '',
                'body_html' => $email !== null ? $email->bodyHtml : '',
                'audience_id' => $email?->audienceId,
            ],
            'submit_error' => null,
            'breadcrumb_current' => $email !== null ? $email->subject : 'Nouvel email',
            'editable' => $email === null || $email->isEditable(),
            'sections' => [['id' => 1, 'name' => 'Louveteaux']],
            'unrestricted' => true,
            'forced_section_id' => null,
            'list_options' => [
                [
                    'value' => 'default_section:1', 'label' => 'Section - Louveteaux',
                    'selected' => true, 'disabled' => false, 'description' => 'La section.',
                    'type' => Email::LIST_TYPE_DEFAULT_SECTION,
                ],
                [
                    'value' => 'external:', 'label' => 'Inscriptions 2025-2026',
                    'selected' => false, 'disabled' => false, 'description' => 'Les inscrits.',
                    'type' => Email::LIST_TYPE_EXTERNAL,
                ],
                [
                    'value' => 'mail_merge:', 'label' => 'Publipostage — fichier Excel',
                    'selected' => false, 'disabled' => false, 'description' => 'Un fichier Excel.',
                    'type' => Email::LIST_TYPE_MAIL_MERGE,
                ],
            ],
            'scout_years' => [
                'previous' => ['id' => 1, 'label' => '2024-2025', 'available' => true, 'warning' => null],
                'current' => ['id' => 2, 'label' => '2025-2026', 'available' => true, 'warning' => null],
                'next' => ['id' => 3, 'label' => '2026-2027', 'available' => false, 'warning' => null],
            ],
            'previous_year_cutoff' => '07-31',
            'audience' => null,
            'audience_sample' => [],
            'attachments' => [],
            'counts' => ['total' => 0, 'sent' => 0, 'pending' => 0, 'error' => 0],
            'current_user_email' => 'chef@example.org',
            'csrf_token' => 'tok',
        ];
    }
}
