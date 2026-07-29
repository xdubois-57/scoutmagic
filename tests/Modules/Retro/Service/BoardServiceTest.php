<?php

declare(strict_types=1);

namespace Tests\Modules\Retro\Service;

use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Member\MemberFunctionInfo;
use Core\Member\MemberProfile;
use Core\Member\MemberService;
use Core\Member\SectionService;
use Core\Member\UnitStaffSectionService;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Security\Role;
use Core\Url\ShortUrlService;
use Modules\Calendar\Api\CalendarEventLookupInterface;
use Modules\Calendar\Api\EventSummary;
use Modules\Retro\Repository\BoardRepository;
use Modules\Retro\Repository\CommentRepository;
use Modules\Retro\Service\BoardService;
use Modules\Retro\Service\RetroException;
use Modules\Retro\Service\SummaryService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Retro\RetroTestHelper;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * @group database
 */
class BoardServiceTest extends TestCase
{
    private \PDO $pdo;
    private BoardRepository $boardRepository;
    private JournalService $journalService;
    private SchedulerService $schedulerService;
    private MemberService $memberService;
    private SectionService $sectionService;
    private Environment $twig;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RetroTestHelper::createTables($this->pdo);

        $this->boardRepository = new BoardRepository($this->pdo);
        $this->journalService = new JournalService(new JournalRepository($this->pdo));
        $this->schedulerService = new SchedulerService(new SchedulerRepository($this->pdo));
        $this->memberService = $this->createMock(MemberService::class);
        $this->sectionService = $this->createMock(SectionService::class);

        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $loader = new FilesystemLoader($templateDir);
        $loader->addPath(dirname(__DIR__, 4) . '/modules/retro/views', 'retro');
        $this->twig = new Environment($loader, ['cache' => false, 'autoescape' => 'html']);
    }

    private function service(
        ?ShortUrlService $shortUrlService = null,
        ?CalendarEventLookupInterface $calendarEventLookup = null,
        ?SummaryService $summaryService = null,
        ?MailService $mailService = null
    ): BoardService {
        return new BoardService(
            $this->boardRepository, new CommentRepository($this->pdo), $this->memberService, $this->sectionService,
            $this->schedulerService, $this->journalService, $mailService ?? $this->createMock(MailService::class),
            $this->twig, 'Test Unit', 'https://example.test',
            $shortUrlService, $calendarEventLookup, $summaryService
        );
    }

    public function testCreateWithoutAnEventUsesTheManualDate(): void
    {
        $board = $this->service()->create(
            'Camp', null, '2026-07-15', true, 'unlimited', 5, true, 'cookie', 140, 'none', Role::CHIEF, 3
        );

        $this->assertSame('2026-07-15', $board->boardDate);
        $this->assertSame('Camp', $board->title);
        $this->assertNotSame('', $board->token);
    }

    public function testCreateLogsToJournal(): void
    {
        $this->service()->create('Camp', null, '2026-07-15', true, 'unlimited', 5, true, 'cookie', 140, 'none', Role::CHIEF, 3);

        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM event_log WHERE category = 'retro' AND event_type = 'board_created'")->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testCreateRejectsBlankTitle(): void
    {
        $this->expectException(RetroException::class);
        $this->service()->create('   ', null, '2026-07-15', true, 'unlimited', 5, true, 'cookie', 140, 'none', Role::CHIEF, 3);
    }

    public function testCreateRejectsMaxCommentLengthOutOfRange(): void
    {
        $this->expectException(RetroException::class);
        $this->service()->create('Camp', null, '2026-07-15', true, 'unlimited', 5, true, 'cookie', 500, 'none', Role::CHIEF, 3);
    }

    public function testCreateSchedulesAutoCloseWhenDelayIsSet(): void
    {
        $board = $this->service()->create('Camp', null, '2026-07-15', true, 'unlimited', 5, true, 'cookie', 140, '24h', Role::CHIEF, 3);

        $scheduled = $this->schedulerService->find('retro', 'auto_close_board', 'board_' . $board->id);
        $this->assertNotNull($scheduled);
    }

    public function testCreateSkipsSchedulingWhenAutoCloseDelayIsNone(): void
    {
        $board = $this->service()->create('Camp', null, '2026-07-15', true, 'unlimited', 5, true, 'cookie', 140, 'none', Role::CHIEF, 3);

        $scheduled = $this->schedulerService->find('retro', 'auto_close_board', 'board_' . $board->id);
        $this->assertNull($scheduled);
    }

    public function testCreateWithAnEventRequiresTheCalendarModule(): void
    {
        $this->expectException(RetroException::class);
        $this->service()->create('Camp', 42, null, true, 'unlimited', 5, true, 'cookie', 140, 'none', Role::CHIEF, 3);
    }

    public function testCreateWithAnEventResolvesTheDateServerSideIgnoringTheClient(): void
    {
        $calendarLookup = $this->createMock(CalendarEventLookupInterface::class);
        $calendarLookup->method('findEventById')->willReturn(new EventSummary(42, 'Camp d\'été', 'Animateurs', '2026-08-05', '2026-08-10'));

        $board = $this->service(null, $calendarLookup)->create(
            'Camp', 42, '2099-01-01', true, 'unlimited', 5, true, 'cookie', 140, 'none', Role::CHIEF, 3
        );

        // Server-resolved end date wins over the client-submitted manual date.
        $this->assertSame('2026-08-10', $board->boardDate);
    }

    public function testCreateThrowsWhenLinkedEventDoesNotExist(): void
    {
        $calendarLookup = $this->createMock(CalendarEventLookupInterface::class);
        $calendarLookup->method('findEventById')->willReturn(null);

        $this->expectException(RetroException::class);
        $this->service(null, $calendarLookup)->create('Camp', 999, null, true, 'unlimited', 5, true, 'cookie', 140, 'none', Role::CHIEF, 3);
    }

    public function testCreateDegradesGracefullyWithoutShortUrlService(): void
    {
        $board = $this->service(null)->create('Camp', null, '2026-07-15', true, 'unlimited', 5, true, 'cookie', 140, 'none', Role::CHIEF, 3);

        $this->assertNull($board->shortCode);
        $this->assertSame('/r/' . $board->token, $this->service()->publicUrl($board));
    }

    public function testCreateUsesTheShortUrlWhenAvailable(): void
    {
        $shortUrlService = $this->createMock(ShortUrlService::class);
        $shortUrlService->method('createShortUrl')->willReturn('abc123');

        $board = $this->service($shortUrlService)->create('Camp', null, '2026-07-15', true, 'unlimited', 5, true, 'cookie', 140, 'none', Role::CHIEF, 3);

        $this->assertSame('abc123', $board->shortCode);
        $this->assertSame('/s/abc123', $this->service($shortUrlService)->publicUrl($board));
    }

    public function testCreateSurvivesAShortUrlServiceFailure(): void
    {
        $shortUrlService = $this->createMock(ShortUrlService::class);
        $shortUrlService->method('createShortUrl')->willThrowException(new \RuntimeException('boom'));

        $board = $this->service($shortUrlService)->create('Camp', null, '2026-07-15', true, 'unlimited', 5, true, 'cookie', 140, 'none', Role::CHIEF, 3);

        $this->assertNull($board->shortCode);
    }

    public function testListEventsForPickerReturnsEmptyWithoutCalendarModule(): void
    {
        $events = $this->service()->listEventsForPicker(1, false, Role::CHIEF);

        $this->assertSame([], $events);
    }

    public function testListEventsForPickerDelegatesToCalendarLookup(): void
    {
        $calendarLookup = $this->createMock(CalendarEventLookupInterface::class);
        $calendarLookup->expects($this->once())->method('findEventsInWindow')->willReturn([
            new EventSummary(1, 'Event', 'Cal', '2026-07-01', '2026-07-01'),
        ]);

        $events = $this->service(null, $calendarLookup)->listEventsForPicker(1, false, Role::CHIEF);

        $this->assertCount(1, $events);
    }

    public function testUpdateChangesFieldsAndReturnsUpdatedBoard(): void
    {
        $board = $this->service()->create('Camp', null, '2026-07-15', true, 'unlimited', 5, true, 'cookie', 140, 'none', Role::CHIEF, 3);

        $updated = $this->service()->update(
            $board->id, 'New title', null, '2026-07-20', false, 'budget', 3, false, 'auth', 160, 'none', Role::CHIEF, 3
        );

        $this->assertSame('New title', $updated->title);
        $this->assertSame('budget', $updated->voteMode);
    }

    public function testUpdateThrowsForUnknownBoard(): void
    {
        $this->expectException(RetroException::class);
        $this->service()->update(999999, 'Title', null, '2026-07-15', true, 'unlimited', 5, true, 'cookie', 140, 'none', Role::CHIEF, 3);
    }

    public function testUpdateReschedulesAutoCloseWhenStillOpen(): void
    {
        $service = $this->service();
        $board = $service->create('Camp', null, '2026-07-15', true, 'unlimited', 5, true, 'cookie', 140, '24h', Role::CHIEF, 3);
        $originalSchedule = $this->schedulerService->find('retro', 'auto_close_board', 'board_' . $board->id);

        $service->update($board->id, 'Camp', null, '2026-07-15', true, 'unlimited', 5, true, 'cookie', 140, '7d', Role::CHIEF, 3);

        $newSchedule = $this->schedulerService->find('retro', 'auto_close_board', 'board_' . $board->id);
        $this->assertNotSame($originalSchedule['run_at'], $newSchedule['run_at']);
    }

    public function testCloseIsIdempotent(): void
    {
        $service = $this->service();
        $board = $service->create('Camp', null, '2026-07-15', true, 'unlimited', 5, true, 'cookie', 140, 'none', Role::CHIEF, 3);

        $service->close($board->id, 3);
        $secondClose = $service->close($board->id, 3);

        $this->assertSame('closed', $secondClose->status);
        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM event_log WHERE event_type = 'board_closed'")->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testCloseCancelsThePendingAutoClose(): void
    {
        $service = $this->service();
        $board = $service->create('Camp', null, '2026-07-15', true, 'unlimited', 5, true, 'cookie', 140, '24h', Role::CHIEF, 3);

        $service->close($board->id, 3);

        $this->assertNull($this->schedulerService->find('retro', 'auto_close_board', 'board_' . $board->id));
    }

    public function testCloseGeneratesAiSummaryWhenAvailable(): void
    {
        $summaryService = $this->createMock(SummaryService::class);
        $summaryService->method('isAvailable')->willReturn(true);
        $summaryService->method('generate')->willReturn('- Thème principal');
        $service = $this->service(null, null, $summaryService);
        $board = $service->create('Camp', null, '2026-07-15', true, 'unlimited', 5, true, 'cookie', 140, 'none', Role::CHIEF, 3);

        $closed = $service->close($board->id, 3);

        $this->assertSame('- Thème principal', $closed->aiSummary);
    }

    public function testCloseSurvivesAiSummaryFailure(): void
    {
        $summaryService = $this->createMock(SummaryService::class);
        $summaryService->method('isAvailable')->willReturn(true);
        $summaryService->method('generate')->willThrowException(new RetroException('AI down'));
        $service = $this->service(null, null, $summaryService);
        $board = $service->create('Camp', null, '2026-07-15', true, 'unlimited', 5, true, 'cookie', 140, 'none', Role::CHIEF, 3);

        $closed = $service->close($board->id, 3);

        $this->assertSame('closed', $closed->status);
        $this->assertNull($closed->aiSummary);
    }

    public function testCloseSendsTheNotificationEmailWhenEnabledAndAddressPresent(): void
    {
        $commentRepository = new CommentRepository($this->pdo);
        $mailService = $this->createMock(MailService::class);
        $mailService->expects($this->once())->method('send')->with(
            'chief@example.com',
            $this->stringContains('Camp'),
            $this->callback(fn(string $html) => str_contains($html, 'Super ambiance') && !str_contains($html, 'Propos injurieux')),
            $this->callback(fn(string $text) => str_contains($text, 'Super ambiance') && !str_contains($text, 'Propos injurieux'))
        );
        $service = $this->service(mailService: $mailService);
        $board = $service->create(
            'Camp', null, '2026-07-15', true, 'unlimited', 5, true, 'cookie', 140, 'none', Role::CHIEF, 3,
            true, 'chief@example.com'
        );
        $commentRepository->create($board->id, 'good', 'Super ambiance');
        $hiddenId = $commentRepository->create($board->id, 'good', 'Propos injurieux');
        $commentRepository->setHidden($hiddenId, true);

        $service->close($board->id, 3);
    }

    public function testCloseIncludesTheAiSummaryInTheEmailWhenGenerated(): void
    {
        $summaryService = $this->createMock(SummaryService::class);
        $summaryService->method('isAvailable')->willReturn(true);
        $summaryService->method('generate')->willReturn('- Résumé IA');
        $mailService = $this->createMock(MailService::class);
        $mailService->expects($this->once())->method('send')->with(
            $this->anything(),
            $this->anything(),
            $this->stringContains('Résumé IA'),
            $this->stringContains('Résumé IA')
        );
        $service = $this->service(null, null, $summaryService, $mailService);
        $board = $service->create(
            'Camp', null, '2026-07-15', true, 'unlimited', 5, true, 'cookie', 140, 'none', Role::CHIEF, 3,
            true, 'chief@example.com'
        );

        $service->close($board->id, 3);
    }

    public function testCloseOmitsTheAiSummarySectionWhenNoneWasGenerated(): void
    {
        $mailService = $this->createMock(MailService::class);
        $mailService->expects($this->once())->method('send')->with(
            $this->anything(),
            $this->anything(),
            $this->logicalNot($this->stringContains('Synthèse')),
            $this->anything()
        );
        $service = $this->service(mailService: $mailService);
        $board = $service->create(
            'Camp', null, '2026-07-15', true, 'unlimited', 5, true, 'cookie', 140, 'none', Role::CHIEF, 3,
            true, 'chief@example.com'
        );

        $service->close($board->id, 3);
    }

    public function testCloseDoesNotSendWhenNotifyIsDisabled(): void
    {
        $mailService = $this->createMock(MailService::class);
        $mailService->expects($this->never())->method('send');
        $service = $this->service(mailService: $mailService);
        $board = $service->create(
            'Camp', null, '2026-07-15', true, 'unlimited', 5, true, 'cookie', 140, 'none', Role::CHIEF, 3,
            false, 'chief@example.com'
        );

        $service->close($board->id, 3);
    }

    public function testCloseDoesNotSendWhenNoAddressIsConfigured(): void
    {
        $mailService = $this->createMock(MailService::class);
        $mailService->expects($this->never())->method('send');
        $service = $this->service(mailService: $mailService);
        $board = $service->create(
            'Camp', null, '2026-07-15', true, 'unlimited', 5, true, 'cookie', 140, 'none', Role::CHIEF, 3,
            true, null
        );

        $service->close($board->id, 3);
    }

    public function testCloseSucceedsEvenWhenTheMailServiceThrows(): void
    {
        $mailService = $this->createMock(MailService::class);
        $mailService->method('send')->willThrowException(new \RuntimeException('SMTP down'));
        $service = $this->service(mailService: $mailService);
        $board = $service->create(
            'Camp', null, '2026-07-15', true, 'unlimited', 5, true, 'cookie', 140, 'none', Role::CHIEF, 3,
            true, 'chief@example.com'
        );

        $closed = $service->close($board->id, 3);

        $this->assertSame('closed', $closed->status);
    }

    public function testCloseDegradesGracefullyWithoutSummaryService(): void
    {
        $service = $this->service();
        $board = $service->create('Camp', null, '2026-07-15', true, 'unlimited', 5, true, 'cookie', 140, 'none', Role::CHIEF, 3);

        $closed = $service->close($board->id, 3);

        $this->assertSame('closed', $closed->status);
        $this->assertNull($closed->aiSummary);
    }

    public function testReopenRestoresOpenStatusAndClearsClosedAt(): void
    {
        $service = $this->service();
        $board = $service->create('Camp', null, '2026-07-15', true, 'unlimited', 5, true, 'cookie', 140, 'none', Role::CHIEF, 3);
        $service->close($board->id, 3);

        $reopened = $service->reopen($board->id, 3);

        $this->assertSame('open', $reopened->status);
        $this->assertNull($reopened->closedAt);
    }

    public function testReopenReschedulesAutoCloseWhenDelayIsSet(): void
    {
        $service = $this->service();
        $board = $service->create('Camp', null, '2026-07-15', true, 'unlimited', 5, true, 'cookie', 140, '24h', Role::CHIEF, 3);
        $service->close($board->id, 3);

        $service->reopen($board->id, 3);

        $this->assertNotNull($this->schedulerService->find('retro', 'auto_close_board', 'board_' . $board->id));
    }

    public function testReopenLogsToJournal(): void
    {
        $service = $this->service();
        $board = $service->create('Camp', null, '2026-07-15', true, 'unlimited', 5, true, 'cookie', 140, 'none', Role::CHIEF, 3);
        $service->close($board->id, 3);

        $service->reopen($board->id, 3);

        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM event_log WHERE event_type = 'board_reopened'")->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testReopenRejectsAnAlreadyOpenBoard(): void
    {
        $service = $this->service();
        $board = $service->create('Camp', null, '2026-07-15', true, 'unlimited', 5, true, 'cookie', 140, 'none', Role::CHIEF, 3);

        $this->expectException(RetroException::class);
        $service->reopen($board->id, 3);
    }

    public function testReopenRejectsAnArchivedBoard(): void
    {
        $service = $this->service();
        $board = $service->create('Camp', null, '2026-07-15', true, 'unlimited', 5, true, 'cookie', 140, 'none', Role::CHIEF, 3);
        $service->close($board->id, 3);
        $service->archive($board->id, 3);

        $this->expectException(RetroException::class);
        $service->reopen($board->id, 3);
    }

    public function testArchiveRequiresAClosedBoard(): void
    {
        $service = $this->service();
        $board = $service->create('Camp', null, '2026-07-15', true, 'unlimited', 5, true, 'cookie', 140, 'none', Role::CHIEF, 3);

        $this->expectException(RetroException::class);
        $service->archive($board->id, 3);
    }

    public function testArchiveSetsStatusAndLogsToJournal(): void
    {
        $service = $this->service();
        $board = $service->create('Camp', null, '2026-07-15', true, 'unlimited', 5, true, 'cookie', 140, 'none', Role::CHIEF, 3);
        $service->close($board->id, 3);

        $archived = $service->archive($board->id, 3);

        $this->assertSame('archived', $archived->status);
        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM event_log WHERE event_type = 'board_archived'")->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testUnarchiveRequiresAnArchivedBoard(): void
    {
        $service = $this->service();
        $board = $service->create('Camp', null, '2026-07-15', true, 'unlimited', 5, true, 'cookie', 140, 'none', Role::CHIEF, 3);
        $service->close($board->id, 3);

        $this->expectException(RetroException::class);
        $service->unarchive($board->id, 3);
    }

    public function testUnarchiveRestoresClosedStatusAndLogsToJournal(): void
    {
        $service = $this->service();
        $board = $service->create('Camp', null, '2026-07-15', true, 'unlimited', 5, true, 'cookie', 140, 'none', Role::CHIEF, 3);
        $service->close($board->id, 3);
        $service->archive($board->id, 3);

        $unarchived = $service->unarchive($board->id, 3);

        $this->assertSame('closed', $unarchived->status);
        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM event_log WHERE event_type = 'board_unarchived'")->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testRegenerateLinkChangesTokenAndLogsToJournal(): void
    {
        $service = $this->service();
        $board = $service->create('Camp', null, '2026-07-15', true, 'unlimited', 5, true, 'cookie', 140, 'none', Role::CHIEF, 3);

        $regenerated = $service->regenerateLink($board->id, 3);

        $this->assertNotSame($board->token, $regenerated->token);
        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM event_log WHERE event_type = 'board_link_regenerated'")->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testResolvePrincipalSectionIdReturnsNullWithoutLinkedMembers(): void
    {
        $this->memberService->method('getLinkedMembers')->willReturn([]);

        $this->assertNull($this->service()->resolvePrincipalSectionId('nobody@example.com', 1));
    }

    public function testIsUnitChiefIsFalseWithoutLinkedMembers(): void
    {
        $this->memberService->method('getLinkedMembers')->willReturn([]);

        $this->assertFalse($this->service()->isUnitChief('nobody@example.com', 1));
    }

    public function testIsUnitChiefIsFalseForAChiefOfAnOrdinarySection(): void
    {
        $this->memberService->method('getLinkedMembers')->willReturn([
            $this->memberProfile('chief', 'chief@example.com'),
        ]);

        $this->assertFalse($this->service()->isUnitChief('chief@example.com', 1));
    }

    public function testIsUnitChiefIsTrueForAStaffDuMember(): void
    {
        $this->memberService->method('getLinkedMembers')->willReturn([
            $this->memberProfile('admin', 'unit-chief@example.com', UnitStaffSectionService::DESK_CODE),
        ]);

        $this->assertTrue($this->service()->isUnitChief('unit-chief@example.com', 1));
    }

    public function testHasLinkedBoardIsFalseWithoutAnyBoard(): void
    {
        $this->assertFalse($this->service()->hasLinkedBoard(999));
    }

    public function testHasLinkedBoardIsTrueOnceABoardIsLinked(): void
    {
        $service = $this->service();
        $board = $service->create('Camp', null, '2026-07-15', true, 'unlimited', 5, true, 'cookie', 140, 'none', Role::CHIEF, 3);
        $this->linkBoardToEvent($board->id, 42);

        $this->assertTrue($service->hasLinkedBoard(42));
    }

    public function testFindLinkedBoardLinkIsNullWithoutAnyLinkedBoard(): void
    {
        $link = $this->service()->findLinkedBoardLink(999, Role::SUPERADMIN, 'admin@example.com', 1);

        $this->assertNull($link);
    }

    public function testFindLinkedBoardLinkIsAbsoluteAndCarriesTheTitle(): void
    {
        $service = $this->service();
        $board = $service->create('Camp', null, '2026-07-15', true, 'unlimited', 5, true, 'cookie', 140, 'none', Role::CHIEF, 3, linkVisibility: 'identified');
        $this->linkBoardToEvent($board->id, 42);

        $link = $service->findLinkedBoardLink(42, Role::IDENTIFIED, 'someone@example.com', 1);

        $this->assertNotNull($link);
        $this->assertSame('https://example.test/r/' . $board->token, $link->url);
        $this->assertSame('Camp', $link->title);
    }

    /**
     * @return array<string, array{0: string, 1: Role, 2: bool}>
     */
    public static function linkVisibilityCases(): array
    {
        return [
            'identified: public viewer does not qualify' => ['identified', Role::PUBLIC, false],
            'identified: identified viewer qualifies' => ['identified', Role::IDENTIFIED, true],
            'chief: identified viewer does not qualify' => ['chief', Role::IDENTIFIED, false],
            'chief: chief viewer qualifies' => ['chief', Role::CHIEF, true],
            'superadmin: admin viewer does not qualify' => ['superadmin', Role::ADMIN, false],
            'superadmin: superadmin viewer qualifies' => ['superadmin', Role::SUPERADMIN, true],
        ];
    }

    /**
     * @dataProvider linkVisibilityCases
     */
    public function testFindLinkedBoardLinkRespectsEachSimpleAudienceLevel(string $linkVisibility, Role $viewerRole, bool $shouldQualify): void
    {
        $service = $this->service();
        $board = $service->create('Camp', null, '2026-07-15', true, 'unlimited', 5, true, 'cookie', 140, 'none', Role::CHIEF, 3, linkVisibility: $linkVisibility);
        $this->linkBoardToEvent($board->id, 42);

        $link = $service->findLinkedBoardLink(42, $viewerRole, 'viewer@example.com', 1);

        $this->assertSame($shouldQualify, $link !== null);
    }

    public function testFindLinkedBoardLinkForUnitChiefAudienceRejectsAPlainChief(): void
    {
        $service = $this->service();
        $board = $service->create('Camp', null, '2026-07-15', true, 'unlimited', 5, true, 'cookie', 140, 'none', Role::CHIEF, 3, linkVisibility: 'unit_chief');
        $this->linkBoardToEvent($board->id, 42);
        $this->memberService->method('getLinkedMembers')->willReturn([
            $this->memberProfile('chief', 'chief@example.com'),
        ]);

        $link = $service->findLinkedBoardLink(42, Role::CHIEF, 'chief@example.com', 1);

        $this->assertNull($link);
    }

    public function testFindLinkedBoardLinkForUnitChiefAudienceAcceptsAStaffDuMember(): void
    {
        $service = $this->service();
        $board = $service->create('Camp', null, '2026-07-15', true, 'unlimited', 5, true, 'cookie', 140, 'none', Role::CHIEF, 3, linkVisibility: 'unit_chief');
        $this->linkBoardToEvent($board->id, 42);
        $this->memberService->method('getLinkedMembers')->willReturn([
            $this->memberProfile('admin', 'unit-chief@example.com', UnitStaffSectionService::DESK_CODE),
        ]);

        $link = $service->findLinkedBoardLink(42, Role::CHIEF, 'unit-chief@example.com', 1);

        $this->assertNotNull($link);
    }

    public function testFindLinkedBoardLinkForUnitChiefAudienceRejectsWithoutAnEmailOrScoutYear(): void
    {
        $service = $this->service();
        $board = $service->create('Camp', null, '2026-07-15', true, 'unlimited', 5, true, 'cookie', 140, 'none', Role::CHIEF, 3, linkVisibility: 'unit_chief');
        $this->linkBoardToEvent($board->id, 42);

        $this->assertNull($service->findLinkedBoardLink(42, Role::CHIEF, null, 1));
        $this->assertNull($service->findLinkedBoardLink(42, Role::CHIEF, 'chief@example.com', null));
    }

    private function linkBoardToEvent(int $boardId, int $eventId): void
    {
        $stmt = $this->pdo->prepare('UPDATE retro_boards SET calendar_event_id = ? WHERE id = ?');
        $stmt->execute([$eventId, $boardId]);
    }

    private function memberProfile(string $functionRole, string $email, ?string $sectionCode = 'MEUTE_A'): MemberProfile
    {
        return new MemberProfile(
            memberYearId: 1,
            memberId: 1,
            deskId: 'DESK_1',
            firstName: 'Jean',
            lastName: 'Test',
            totem: null,
            quali: null,
            gender: null,
            birthDate: null,
            phone: null,
            mobile: null,
            email: $email,
            patrol: null,
            formationLevel: null,
            federationMailConsent: false,
            unitMailConsent: false,
            addresses: [],
            functions: [
                new MemberFunctionInfo(
                    functionLabel: 'Animateur',
                    functionRole: $functionRole,
                    branchName: null,
                    sectionName: null,
                    sectionCode: $sectionCode,
                    isMainFunction: true,
                    startDate: null,
                    endDate: null
                ),
            ],
            scoutYearLabel: '2025-2026'
        );
    }
}
