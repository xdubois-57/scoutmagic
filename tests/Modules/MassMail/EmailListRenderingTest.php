<?php

declare(strict_types=1);

namespace Tests\Modules\MassMail;

use Core\View\TwigFactory;
use Modules\MassMail\Repository\Email;
use PHPUnit\Framework\TestCase;

/**
 * Full-page render of modules/mass_mail/views/list.html.twig — same
 * precedent as Tests\Modules\Finance\MovementsListRenderingTest.
 *
 * What it defends: the "Année" column only means something for a list
 * that is actually scout-year-scoped. A mail merge takes its recipients
 * from the uploaded file, and an external list (Email::LIST_TYPE_EXTERNAL,
 * contributed by another module through its own
 * Api\ExternalMailingListProvider) always targets that module's fixed
 * year — Service\MailingListService::resolveMembersForYears() ignores the
 * compose dialog's year selector for it entirely. The column used to
 * print the stored scout years for an external email anyway, naming a
 * year that had never been used to resolve a single recipient.
 *
 * It also pins the compose dialog's explanatory note, which is what the
 * dialog shows in place of the year checkboxes for that same list type.
 */
class EmailListRenderingTest extends TestCase
{
    private const YEAR_LABELS = [1 => '2024-2025', 2 => '2025-2026'];

    /**
     * @param int[] $scoutYearIds
     */
    private function email(int $id, string $subject, string $listType, array $scoutYearIds): Email
    {
        return new Email(
            $id,
            $subject,
            '<p>Bonjour.</p>',
            1,
            $listType,
            null,
            $listType === Email::LIST_TYPE_DEFAULT_SECTION ? 1 : null,
            $listType === Email::LIST_TYPE_MAIL_MERGE ? 5 : null,
            $scoutYearIds,
            Email::STATUS_DRAFT,
            '2026-08-01 10:00:00',
            '2026-08-01 10:00:00',
            null,
            null
        );
    }

    private function render(): string
    {
        $root = dirname(__DIR__, 3);
        $twig = TwigFactory::create($root . '/core/View/templates', true, ['mass_mail' => $root . '/modules/mass_mail/views']);
        $twig->addGlobal('site_name', 'Unité Test');
        $twig->addGlobal('menus', null);
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_email', 'chef@example.org');
        $twig->addGlobal('current_user_role', 'admin');
        $twig->addGlobal('current_path', '/mass-mail');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('csp_nonce', 'n');
        $twig->addGlobal('effective_scout_year_id', 2);

        $emails = [
            $this->email(1, 'Réunion de section', Email::LIST_TYPE_DEFAULT_SECTION, [1, 2]),
            $this->email(2, 'Publipostage annuel', Email::LIST_TYPE_MAIL_MERGE, []),
            // An external email carries stored scout years like any other row
            // — that is exactly why the column has to decide, rather than
            // rely on the value being empty.
            $this->email(3, 'Bienvenue aux inscrits', Email::LIST_TYPE_EXTERNAL, [2]),
        ];

        return $twig->render('@mass_mail/list.html.twig', [
            'emails' => $emails,
            'recipient_counts' => [1 => 12, 2 => 4, 3 => 7],
            'scout_years_by_id' => self::YEAR_LABELS,
            'total' => 3,
            'per_page' => 20,
            'page' => 1,
            'total_pages' => 1,
            'search' => '',
            'status' => null,
            'section_id' => null,
            'statuses' => Email::STATUSES,
            'sections' => [['id' => 1, 'name' => 'Louveteaux']],
            'sections_by_id' => [1 => 'Louveteaux'],
            'custom_lists' => [],
            'custom_lists_by_id' => [],
            'default_lists' => [],
            'scout_years' => [
                'previous' => ['id' => 1, 'label' => '2024-2025', 'available' => true],
                'current' => ['id' => 2, 'label' => '2025-2026', 'available' => true],
                'next' => ['id' => 3, 'label' => '2026-2027', 'available' => false],
            ],
            'current_user_email' => 'chef@example.org',
            'unrestricted' => true,
            'user_section_ids' => [1],
            'forced_section_id' => null,
            'previous_year_cutoff' => '10-15',
            'csrf_token' => 'tok',
        ]);
    }

    /**
     * The row for one email, as a single string — enough to assert which
     * cell carries what without depending on the whole table's order.
     */
    private function rowFor(string $html, string $subject): string
    {
        $rows = preg_split('~<tr[^>]*>~', $html) ?: [];
        foreach ($rows as $row) {
            // Cut at the row's own end: the last chunk of the split would
            // otherwise run on into the page's scripts, JSON island included
            // — where every scout-year label legitimately appears.
            $row = explode('</tr>', $row)[0];
            if (str_contains($row, $subject)) {
                return $row;
            }
        }

        self::fail("No table row found for the email « {$subject} ».");
    }

    public function testAScoutYearScopedEmailStillNamesItsYears(): void
    {
        $row = $this->rowFor($this->render(), 'Réunion de section');

        $this->assertStringContainsString('2024-2025, 2025-2026', $row);
    }

    public function testAMailMergeEmailShowsNoYear(): void
    {
        $row = $this->rowFor($this->render(), 'Publipostage annuel');

        $this->assertStringContainsString('<td>—</td>', $row);
        $this->assertStringNotContainsString('2025-2026', $row);
    }

    public function testAnExternalListEmailShowsNoYearEither(): void
    {
        // The stored scout year exists but was never what resolved the
        // recipients — the provider's own target year was.
        $row = $this->rowFor($this->render(), 'Bienvenue aux inscrits');

        $this->assertStringContainsString('<td>—</td>', $row);
        $this->assertStringNotContainsString('2025-2026', $row);
    }

    public function testComposeDialogCarriesTheExternalListNote(): void
    {
        $html = $this->render();

        // Hidden until mass-mail-list.js's updateListTypeUi() selects the
        // list type, exactly like the mail-merge note it is modelled on.
        $this->assertMatchesRegularExpression(
            '~<p class="form-text small mb-0 d-none" id="mm-external-list-note">\s*Cette liste vise toujours l\'année d\'inscription\.~u',
            $html
        );
        $this->assertStringContainsString('id="mm-merge-list-note"', $html);
    }
}
