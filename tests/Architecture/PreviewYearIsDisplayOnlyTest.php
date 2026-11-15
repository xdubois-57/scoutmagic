<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * The year a chief previews decides what is DISPLAYED, never who may see or
 * write anything (issue #1124).
 *
 * A preview is chosen by the very person it would authorise, and is
 * accepted for any year that ever existed: a decision taken in it hands
 * back the rights of that year — the sections animated then, the children
 * one was the parent of then. `ScoutYearResolver::getAuthorizationYear()`
 * is the year an access decision is taken in.
 *
 * Every `getEffectiveYear(ScoutYearSession::getPreviewId(), …)` in core and
 * the modules is therefore listed below with why it is display only, and a
 * new one fails this test until somebody has said so. The list is exact:
 * a call that goes away must leave it too, so it never vouches for code
 * that is not there.
 *
 * What it cannot see: a preview id passed along under another name (the
 * groups context factory took one as `$yearPreviewId` until #1124). Code
 * review covers that; this covers the common shape.
 */
class PreviewYearIsDisplayOnlyTest extends TestCase
{
    /**
     * Relative path => [number of preview-aware calls, why they only
     * choose what is shown]. The access each page grants is decided
     * elsewhere: by its route's role_min, or in the authorization year.
     */
    private const DISPLAY_ONLY = [
        'core/Http/Controller/BadgeConfigurationController.php' => [1, 'a tab label'],
        'core/Http/Controller/BadgeHoldersController.php' => [1, 'admin page listing the holders of the year shown'],
        'core/Http/Controller/ScoutYearController.php' => [1, 'the preview banner itself'],
        'core/Http/Controller/SectionDocumentController.php' => [
            1,
            'which years the Staffs page offers documents for; the section is checked in the authorization year',
        ],
        'core/Http/Controller/SectionRosterController.php' => [
            3,
            'roster data; contact access only reads linked members below chief, where there is no preview',
        ],
        'core/Http/Controller/StaffsController.php' => [
            1,
            'the staff listing; which sections may be edited is decided in the authorization year',
        ],
        'core/Member/Controller/MemberSearchController.php' => [3, 'admin search, record and export of the year shown'],
        'core/Offline/LinkedMemberPages.php' => [1, 'the URLs to precache; each page re-checks access when fetched'],
        'modules/calendar/src/Controller/CalendarChiefController.php' => [
            1,
            'the events shown; staffed sections and « Mes évènements » are decided in the authorization year',
        ],
        'modules/calendar/src/Controller/CalendarPublicController.php' => [
            1,
            'the events shown; « Mes évènements » is decided in the authorization year',
        ],
        'modules/camps/src/Controller/CampsChiefController.php' => [1, 'autocomplete suggestions for « réservé par »'],
        'modules/fees/src/Controller/FeeAccuracyController.php' => [1, 'admin report of the year shown'],
        'modules/fees/src/Controller/FeesController.php' => [1, 'admin labels and counts'],
        'modules/fees/src/Controller/InvoiceController.php' => [1, 'admin season shown and imported into'],
        'modules/fees/src/Controller/InvoiceReportController.php' => [1, 'admin report of the year shown'],
        'modules/finance/src/Controller/DashboardController.php' => [
            1,
            'household lookups for counts; account access is decided without it',
        ],
        'modules/finance/src/Controller/ReconciliationController.php' => [
            1,
            'household and name lookups; account access is decided without it',
        ],
        'modules/finance/src/Service/FamilyPaymentService.php' => [
            1,
            "the caller's own members' payment demands, shown, never granted",
        ],
        'modules/gallery/src/Controller/GalleryChiefController.php' => [
            1,
            "a new album's default year; managing an album does not depend on it",
        ],
        'modules/leadership/src/Controller/LeadershipController.php' => [1, 'admin pages, staff rows of the year shown'],
        'modules/mass_mail/src/Controller/MailingListController.php' => [1, 'admin recipient counts and payloads'],
        'modules/member_stats/src/Controller/MemberStatsController.php' => [1, 'statistics of the year shown'],
        'modules/presences/src/Controller/PresencesController.php' => [
            6,
            'the register shown; every section check passes the authorization year',
        ],
        'modules/retro/src/Controller/RetroChiefController.php' => [
            1,
            'the default section of the event picker; the event is re-checked by role',
        ],
        'modules/sos_staff/src/Controller/SosAdminController.php' => [
            1,
            'only compared with the year the roster is built in, to say the preview does not apply (#1102)',
        ],
        'modules/trombinoscope/src/Controller/TrombinoscopeController.php' => [2, 'the staff wall and its PDF'],
    ];

    public function testEveryPreviewAwareYearIsListedAsDisplayOnly(): void
    {
        $found = self::previewAwareCalls();

        $unlisted = [];
        foreach ($found as $path => $count) {
            $allowed = self::DISPLAY_ONLY[$path][0] ?? 0;
            if ($count > $allowed) {
                $unlisted[] = "{$path} ({$count} call(s), {$allowed} listed)";
            }
        }

        $this->assertSame(
            [],
            $unlisted,
            "These ask getEffectiveYear() with the session preview and are not listed as display only:\n  "
            . implode("\n  ", $unlisted)
            . "\nAn access decision or a write is taken in ScoutYearResolver::getAuthorizationYear(). If the year"
            . " only chooses what is shown, list the call in " . self::class . '::DISPLAY_ONLY with why.'
        );
    }

    public function testTheListVouchesForNoCallThatIsGone(): void
    {
        $found = self::previewAwareCalls();

        $stale = [];
        foreach (self::DISPLAY_ONLY as $path => [$allowed]) {
            if (($found[$path] ?? 0) !== $allowed) {
                $stale[] = "{$path} (listed {$allowed}, found " . ($found[$path] ?? 0) . ')';
            }
        }

        $this->assertSame(
            [],
            $stale,
            "These entries of DISPLAY_ONLY no longer match the code:\n  " . implode("\n  ", $stale)
        );
    }

    /**
     * Calls in real code — comments and docblocks stripped first, since
     * several files explain in prose why they do NOT take the preview.
     *
     * @return array<string, int> relative path => count
     */
    private static function previewAwareCalls(): array
    {
        $root = dirname(__DIR__, 2);
        $found = [];

        foreach (['core', 'modules'] as $top) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
                $root . '/' . $top,
                \FilesystemIterator::SKIP_DOTS
            ));
            foreach ($files as $file) {
                $path = $file->getPathname();
                if (!str_ends_with($path, '.php')) {
                    continue;
                }

                $code = '';
                foreach (token_get_all((string) file_get_contents($path)) as $token) {
                    if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                        continue;
                    }
                    $code .= is_array($token) ? $token[1] : $token;
                }

                $count = preg_match_all(
                    '#getEffectiveYear\(\s*(?:\\\\?Core\\\\ScoutYear\\\\)?ScoutYearSession::getPreviewId\(\)#',
                    $code
                );
                if ($count > 0) {
                    $found[substr($path, strlen($root) + 1)] = $count;
                }
            }
        }

        ksort($found);

        return $found;
    }
}
