<?php

declare(strict_types=1);

namespace Tests\Security;

use PHPUnit\Framework\TestCase;

/**
 * A ratchet on `new \DateTimeImmutable($variable)`.
 *
 * The constructor has two edges, and they pull in opposite directions:
 * it THROWS on a malformed string, and it silently answers *the current
 * moment* for `''`, `'now'` and `"a\0b"`. So the same call turns one bad
 * value into a 500 and another into today's date, believed and stored.
 * `Core\Service\DateInput::fromStorage()` answers null for both
 * (SECURITY.md § 35).
 *
 * WHY A RATCHET AND NOT A BAN
 *
 * The 161 sites below are not equally exposed, and pretending otherwise
 * would be the wrong kind of tidy. Most read a `DATETIME` column, and
 * MySQL will not let a malformed value into one — that is what makes
 * them low-risk. It is NOT what makes them checked: MySQL's zero date
 * (`0000-00-00`) passes the column and PHP reads it as the 30th of
 * November, year -1, and a nullable column read without a guard gives
 * the empty string, which is *now*.
 *
 * Converting all 161 at once would mean 158 decisions about what a null
 * should do at each call site, in files nobody is otherwise touching —
 * churn with a real chance of introducing the bug it is meant to
 * prevent. So the list is frozen instead: no NEW site may appear, and
 * the ones here come off as their files are touched for other reasons.
 *
 * The most exposed family is the one this does NOT protect on its own:
 * a value from a SETTING, an import or a JSON payload has no column type
 * behind it. When one of those files appears in a diff, that is the
 * moment to convert it.
 *
 * BOTH DIRECTIONS, like Tests\Core\View\UxConventionsTest: a new site
 * fails, and so does a stale entry for a file that has been fixed. A
 * list that only shrinks is a list that stays true; one that is merely
 * "not exceeded" drifts into fiction.
 */
class StoredDateReadingRatchetTest extends TestCase
{
    /**
     * Files calling `new \DateTimeImmutable($variable)`, and how many
     * times. A literal — `new \DateTimeImmutable()`, `'now'`, `'+1 day'`
     * — is not counted: it cannot come from anywhere untrusted.
     *
     * @var array<string, int>
     */
    private const ALLOWLIST = [
        'core/Http/Controller/MaintenanceController.php' => 1,
        'core/Http/Controller/NotificationController.php' => 3,
        'core/Http/Controller/PageController.php' => 1,
        'core/Http/Controller/SupportController.php' => 2,
        'core/Http/FrontController.php' => 1,
        'core/Import/ImportRecord.php' => 1,
        'core/Import/RosterComparisonRepository.php' => 1,
        'core/Import/RosterSnapshotRepository.php' => 1,
        'core/Maintenance/Task/AutoBackupHandler.php' => 1,
        'core/Maintenance/UpdateHistoryRepository.php' => 1,
        'core/Member/DepartureRepository.php' => 1,
        'core/Member/Export/MemberExportService.php' => 1,
        'core/Member/MemberEmailRepository.php' => 5,
        'core/Member/MemberService.php' => 1,
        'core/Member/SectionDocumentRepository.php' => 1,
        'core/Scheduler/SchedulerService.php' => 1,
        'core/Security/LoginThrottler.php' => 1,
        'core/Security/MagicLinkRepository.php' => 3,
        'core/Security/PasswordResetRepository.php' => 2,
        'core/Security/UserAccountRepository.php' => 3,
        'core/Service/DateInput.php' => 1,
        'core/Statistics/InstallationDateService.php' => 1,
        'core/Statistics/StatisticsPayloadBuilder.php' => 1,
        'core/Statistics/StatisticsSender.php' => 1,
        'core/Support/Collector/EventJournalCollector.php' => 1,
        'core/Support/Collector/UpdateHistoryCollector.php' => 3,
        'core/Support/SupportPackageService.php' => 1,
        'core/View/MonthGrid/DayStateGridBuilder.php' => 1,
        'core/View/MonthGrid/MonthGridBuilder.php' => 1,
        'modules/calendar/src/Service/CalendarNotificationService.php' => 2,
        'modules/calendar/src/Service/CalendarRetroAutoCreateService.php' => 2,
        'modules/calendar/src/Service/CalendarService.php' => 1,
        'modules/calendar/src/Service/IcsBuilder.php' => 9,
        'modules/calendar/src/Task/AutoCreateRetroHandler.php' => 1,
        'modules/camps/src/Mail/CampsMessageConsumer.php' => 2,
        'modules/camps/src/Service/CampLabels.php' => 2,
        'modules/fees/src/Repository/IgnoredHouseholdRepository.php' => 1,
        'modules/fees/src/Repository/InvoiceRepository.php' => 1,
        'modules/fees/src/Service/InvoiceVerificationService.php' => 1,
        'modules/finance/src/Controller/MovementController.php' => 5,
        'modules/finance/src/Service/AiCategorizationService.php' => 1,
        'modules/finance/src/Service/FinanceService.php' => 2,
        'modules/finance/src/Service/ImportService.php' => 1,
        'modules/finance/src/Service/ReceiptMatchingService.php' => 2,
        'modules/finance/src/Task/PurgeOldMovementsHandler.php' => 1,
        'modules/groups/src/Support/Timestamps.php' => 1,
        'modules/inbound_mail/src/Client/ImapMailboxClient.php' => 1,
        'modules/inbound_mail/src/Mime/MimeMessageParser.php' => 1,
        'modules/inbound_mail/src/Repository/InboundMailboxRepository.php' => 2,
        'modules/inbound_mail/src/Repository/InboundMessageRepository.php' => 1,
        'modules/registration/src/Repository/RegistrationRequestRepository.php' => 4,
        'modules/registration/src/Repository/RegistrationSecondaryEmailRepository.php' => 5,
        'modules/rental/src/Availability/AvailabilityCalculator.php' => 6,
        'modules/rental/src/Availability/MonthWindow.php' => 1,
        'modules/rental/src/Booking/MilestoneEvidence.php' => 1,
        'modules/rental/src/Compliance/ComplianceItem.php' => 3,
        'modules/rental/src/Controller/RentalPublicController.php' => 4,
        'modules/rental/src/Controller/RentalRequestController.php' => 2,
        'modules/rental/src/Mail/RentalMessageConsumer.php' => 1,
        'modules/rental/src/Pricing/PricingRequest.php' => 4,
        'modules/rental/src/Reminder/ReminderPlanner.php' => 5,
        'modules/rental/src/Repository/RentalBlockRepository.php' => 1,
        'modules/rental/src/Repository/RentalBookingCommentRepository.php' => 1,
        'modules/rental/src/Repository/RentalBookingRepository.php' => 5,
        'modules/rental/src/Repository/RentalChangeRequestRepository.php' => 2,
        'modules/rental/src/Repository/RentalComplianceRepository.php' => 2,
        'modules/rental/src/Repository/RentalDocumentRepository.php' => 2,
        'modules/rental/src/Repository/RentalStayRepository.php' => 5,
        'modules/rental/src/Service/RentalAvailabilityService.php' => 1,
        'modules/rental/src/Service/RentalOperationsService.php' => 4,
        'modules/rental/src/Service/RentalRetentionService.php' => 3,
        'modules/rental/src/Service/RentalStatisticsService.php' => 3,
        'modules/retro/src/Service/BoardService.php' => 2,
        'modules/sos_staff/src/Controller/SosAdminController.php' => 4,
        'modules/sos_staff/src/Service/CalendarSyncService.php' => 1,
        'modules/sos_staff/src/Service/OnCallService.php' => 3,
        'modules/support_dashboard/src/Service/StatisticsIntakeService.php' => 1,
        'modules/support_dashboard/src/Service/SupportDashboardService.php' => 1,
        'modules/test_tools/src/Repository/CapturedEmailRepository.php' => 1,
    ];

    /**
     * @return array<string, int> file => number of variable-argument calls
     */
    private static function found(): array
    {
        $repoRoot = dirname(__DIR__, 2);
        $found = [];

        foreach (['core', 'modules', 'public', 'bootstrap'] as $directory) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($repoRoot . '/' . $directory, \FilesystemIterator::SKIP_DOTS)
            );
            /** @var \SplFileInfo $file */
            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $relative = substr($file->getPathname(), strlen($repoRoot) + 1);
                $count = 0;

                foreach (file($file->getPathname()) ?: [] as $line) {
                    if (preg_match_all('/new \\\\DateTimeImmutable\(/', $line, $matches, PREG_OFFSET_CAPTURE) === 0) {
                        continue;
                    }
                    foreach ($matches[0] as $match) {
                        $argument = substr($line, $match[1] + strlen($match[0]));
                        // `)` is the no-argument form; a quote opens a
                        // literal. Everything else is a variable.
                        if (preg_match('/^\s*[)\'"]/', $argument) === 1) {
                            continue;
                        }
                        $count++;
                    }
                }

                if ($count > 0) {
                    $found[$relative] = $count;
                }
            }
        }

        ksort($found);

        return $found;
    }

    public function testNoNewSiteReadsADateWithTheRawConstructor(): void
    {
        $new = [];

        foreach (self::found() as $file => $count) {
            $allowed = self::ALLOWLIST[$file] ?? 0;
            if ($count > $allowed) {
                $new[] = "{$file}: {$count} (allowlist: {$allowed})";
            }
        }

        $this->assertSame(
            [],
            $new,
            "Read the value through Core\\Service\\DateInput::fromStorage() instead.\n"
            . "`new DateTimeImmutable(\$v)` throws on a malformed string AND answers *now* for an empty\n"
            . "one — so one bad value 500s the page and another silently becomes today's date:\n"
            . implode("\n", $new)
        );
    }

    /**
     * The other direction. Without it the list becomes a description of
     * the codebase as it was, and a file that was cleaned up keeps its
     * budget for the next person to spend.
     */
    public function testTheAllowlistOnlyEverShrinks(): void
    {
        $found = self::found();
        $stale = [];

        foreach (self::ALLOWLIST as $file => $allowed) {
            $count = $found[$file] ?? 0;
            if ($count < $allowed) {
                $stale[] = "{$file} is listed for {$allowed} but now has {$count} — shrink the allowlist";
            }
        }

        $this->assertSame([], $stale);
    }

    /**
     * The safe reading exists and is the one to reach for. Pinned so the
     * failure message above never points at something that was deleted.
     */
    public function testTheReplacementExists(): void
    {
        $this->assertTrue(method_exists(\Core\Service\DateInput::class, 'fromStorage'));
        $this->assertNull(\Core\Service\DateInput::fromStorage(''));
        $this->assertNull(\Core\Service\DateInput::fromStorage('0000-00-00 00:00:00'));
    }
}
