<?php

declare(strict_types=1);

namespace Tests\Modules\SosStaff\Service;

use Core\Journal\JournalService;
use Tests\Core\Mail\Template\EmailTemplateRendererFactory;
use Core\Mail\MailService;
use Core\Member\MemberFunctionInfo;
use Core\Member\MemberProfile;
use Core\Member\MemberAccountResolver;
use Core\Member\MemberService;
use Core\Notification\NotificationService;
use Core\Security\UserAccount;
use Core\Security\UserAccountRepository;
use Modules\SosStaff\Api\ForwardingState;
use Modules\SosStaff\Api\PhoneProviderInterface;
use Modules\SosStaff\Api\ProviderException;
use Modules\SosStaff\Service\ProviderConfigService;
use Modules\SosStaff\Service\RedirectService;
use Modules\SosStaff\Service\SosException;
use Modules\SosStaff\Service\SosSettingsService;
use PHPUnit\Framework\TestCase;
use Tests\TestTwig;
use Twig\Environment;

// Through `EmailTemplateRendererFactory::shippedOnlyForModule()`, which
// reaches an in-memory database by way of its own `emptyStore()`: this
// class builds none itself and inherits none.
#[\PHPUnit\Framework\Attributes\Group('database')]
// This class builds its doubles once (in setUp() or a shared helper) and
// hands the same ones to every test: some tests set expectations on
// them, the others only need their answers, and PHPUnit would report
// each of those as a mock with no expectation (issue #665).
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
class RedirectServiceTest extends TestCase
{
    private ProviderConfigService $providerConfigService;
    private SosSettingsService $settingsService;
    private MemberService $memberService;
    private UserAccountRepository $userAccountRepository;
    private MailService $mailService;
    private JournalService $journalService;
    private NotificationService $notificationService;
    private MemberAccountResolver $memberAccounts;
    private Environment $twig;

    protected function setUp(): void
    {
        $this->providerConfigService = $this->createStub(ProviderConfigService::class);
        $this->settingsService = $this->createStub(SosSettingsService::class);
        $this->memberService = $this->createStub(MemberService::class);
        $this->userAccountRepository = $this->createStub(UserAccountRepository::class);
        $this->mailService = $this->createMock(MailService::class);
        $this->journalService = $this->createMock(JournalService::class);
        $this->notificationService = $this->createMock(NotificationService::class);
        $this->memberAccounts = $this->createStub(MemberAccountResolver::class);

        $this->twig = TestTwig::create(['sos_staff']);
    }

    private function service(?NotificationService $notifications = null): RedirectService
    {
        return new RedirectService(
            $this->providerConfigService,
            $this->settingsService,
            $this->memberService,
            $this->userAccountRepository,
            $this->mailService,
            $this->journalService,
            EmailTemplateRendererFactory::shippedOnlyForModule($this->twig, 'sos_staff'),
            $notifications ?? $this->notificationService,
            $this->memberAccounts
        );
    }

    private function profile(int $memberId, string $displayName, ?string $mobile, ?string $email): MemberProfile
    {
        return new MemberProfile(
            memberYearId: $memberId * 10,
            memberId: $memberId,
            deskId: "DESK_{$memberId}",
            firstName: $displayName,
            lastName: 'Test',
            totem: $displayName,
            quali: null,
            gender: null,
            birthDate: null,
            phone: null,
            mobile: $mobile,
            email: $email,
            patrol: null,
            formationLevel: null,
            federationMailConsent: false,
            unitMailConsent: false,
            addresses: [],
            functions: [],
            scoutYearLabel: '2025-2026'
        );
    }

    public function testApplyThrowsWhenNoProviderConfigured(): void
    {
        $this->providerConfigService->method('getActiveProvider')->willReturn(null);
        $this->userAccountRepository->method('findStaffAndSuperAdminIds')->willReturn([]);

        $logged = $this->recordJournal();

        try {
            $this->service()->apply(1, null, 100);
            self::fail('Expected a SosException.');
        } catch (SosException) {
        }
        // Issue #1102: a failed redirection is a warning, never an `info`
        // among the routine lines.
        self::assertSame('warning', $logged['redirect_failure'] ?? null);
    }

    public function testApplyThrowsWhenNoNumberResolvable(): void
    {
        $provider = $this->createStub(PhoneProviderInterface::class);
        $this->providerConfigService->method('getActiveProvider')->willReturn($provider);
        $this->memberService->method('findProfileByMemberAndYear')->willReturn(null);
        $this->settingsService->method('getDefaultNumber')->willReturn(null);
        $this->userAccountRepository->method('findStaffAndSuperAdminIds')->willReturn([]);

        $this->expectException(SosException::class);
        $this->service()->apply(1, null, 100);
    }

    public function testApplyIsNoOpWhenAlreadyCorrectlyForwarded(): void
    {
        $provider = $this->createMock(PhoneProviderInterface::class);
        $provider->method('readForwardingState')->willReturn(new ForwardingState(true, '+32470000001'));
        $provider->expects($this->never())->method('setForwarding');

        $this->providerConfigService->method('getActiveProvider')->willReturn($provider);
        $this->memberService->method('findProfileByMemberAndYear')
            ->willReturn($this->profile(1, 'Akela', '+32470000001', 'akela@test.be'));

        $this->journalService->expects($this->once())->method('log')
            ->with('sos_staff', 'redirect_no_change', 'info', $this->anything(), $this->anything());

        $this->service()->apply(1, null, 100);
    }

    public function testApplySetsForwardingAndConfirms(): void
    {
        $provider = $this->createMock(PhoneProviderInterface::class);
        $provider->method('readForwardingState')->willReturnOnConsecutiveCalls(
            new ForwardingState(true, '+32470000000'),
            new ForwardingState(true, '+32470000001')
        );
        $provider->expects($this->once())->method('setForwarding')->with('+32470000001');

        $this->providerConfigService->method('getActiveProvider')->willReturn($provider);
        $this->memberService->method('findProfileByMemberAndYear')
            ->willReturn($this->profile(1, 'Akela', '+32470000001', 'akela@test.be'));
        $this->settingsService->method('isEmailNotificationsEnabled')->willReturn(false);

        $this->journalService->expects($this->once())->method('log')
            ->with('sos_staff', 'redirect_success', 'info', $this->anything(), $this->anything());

        $this->service()->apply(1, null, 100);
    }

    /**
     * Issue #1125: the GSM goes out normalised, whatever Desk holds, and an
     * operator answering in its own spelling is a confirmation, not a
     * failure.
     */
    public function testTheGsmIsSentNormalisedAndConfirmedInTheOperatorsOwnSpelling(): void
    {
        $provider = $this->createMock(PhoneProviderInterface::class);
        $provider->method('readForwardingState')->willReturnOnConsecutiveCalls(
            new ForwardingState(true, '0032470000000'),
            new ForwardingState(true, '0032470123456')
        );
        $provider->expects($this->once())->method('setForwarding')->with('+32470123456');

        $this->providerConfigService->method('getActiveProvider')->willReturn($provider);
        $this->memberService->method('findProfileByMemberAndYear')
            ->willReturn($this->profile(1, 'Akela', '0470/12.34.56', 'akela@test.be'));
        $this->settingsService->method('isEmailNotificationsEnabled')->willReturn(false);

        $logged = $this->recordJournal();
        $this->service()->apply(1, null, 100);

        self::assertSame('info', $logged['redirect_success'] ?? null);
        self::assertArrayNotHasKey('redirect_failure', $logged->getArrayCopy());
    }

    public function testALineAlreadyOnTheNumberInAnotherSpellingIsLeftAlone(): void
    {
        $provider = $this->createMock(PhoneProviderInterface::class);
        $provider->method('readForwardingState')->willReturn(new ForwardingState(true, '0032470123456'));
        $provider->expects($this->never())->method('setForwarding');

        $this->providerConfigService->method('getActiveProvider')->willReturn($provider);
        $this->memberService->method('findProfileByMemberAndYear')
            ->willReturn($this->profile(1, 'Akela', '0470 12 34 56', 'akela@test.be'));

        $logged = $this->recordJournal();
        $this->service()->apply(1, null, 100);

        self::assertSame('info', $logged['redirect_no_change'] ?? null);
    }

    /**
     * Issue #1125: a GSM that is no phone number is refused before the
     * operator is asked anything, and the alert names whose it is — never
     * the number itself.
     */
    public function testAGsmThatIsNoNumberIsRefusedAndNamed(): void
    {
        $provider = $this->createMock(PhoneProviderInterface::class);
        $provider->expects($this->never())->method('setForwarding');
        $this->providerConfigService->method('getActiveProvider')->willReturn($provider);
        $this->memberService->method('findProfileByMemberAndYear')
            ->willReturn($this->profile(1, 'Akela', '0470 12 34', 'akela@test.be'));
        $this->settingsService->method('labelForMember')->willReturn('Akela (Jean Test)');
        $this->userAccountRepository->method('findStaffAndSuperAdminIds')->willReturn([]);

        $messages = new \ArrayObject();
        $this->journalService->method('log')->willReturnCallback(
            static function (string $category, string $type, string $level, string $description) use ($messages): void {
                $messages[$type] = $description;
            }
        );

        try {
            $this->service()->apply(1, null, 100);
            self::fail('Expected a SosException.');
        } catch (SosException $e) {
            self::assertStringContainsString('Akela (Jean Test)', $e->getMessage());
        }
        self::assertStringContainsString("n'est pas un numéro de téléphone valide", $messages['redirect_failure'] ?? '');
        self::assertStringNotContainsString('0470', $messages['redirect_failure'] ?? '');
    }

    /**
     * Issue #1125: falling back from a chosen default number that no
     * longer holds is journaled when the line actually moves to it.
     */
    public function testMovingTheLineToTheFallbackOfAStaleDefaultIsJournaled(): void
    {
        $provider = $this->createStub(PhoneProviderInterface::class);
        $provider->method('readForwardingState')->willReturnOnConsecutiveCalls(
            new ForwardingState(true, '+32470000001'),
            new ForwardingState(true, '+32470000002')
        );
        $this->providerConfigService->method('getActiveProvider')->willReturn($provider);
        $this->settingsService->method('getDefaultNumber')->willReturn('+32470000002');
        $this->settingsService->method('staleDefaultNumberMemberId')->willReturn(7);
        $this->settingsService->method('resolveDefaultNumberMemberId')->willReturn(8);
        $this->settingsService->method('isEmailNotificationsEnabled')->willReturn(false);

        $logged = $this->recordJournal();
        $this->service()->apply(null, null, 100);

        self::assertSame('warning', $logged['default_number_ignored'] ?? null);
        self::assertSame('info', $logged['redirect_success'] ?? null);
    }

    /**
     * Review of #1194: the fallback is journaled only once the line really
     * moved — not when it was already there, not when the operator refused.
     */
    public function testAStaleDefaultIsNotJournaledWhenTheLineDidNotMove(): void
    {
        $provider = $this->createStub(PhoneProviderInterface::class);
        $provider->method('readForwardingState')->willReturn(new ForwardingState(true, '+32470000002'));
        $this->providerConfigService->method('getActiveProvider')->willReturn($provider);
        $this->settingsService->method('getDefaultNumber')->willReturn('+32470000002');
        $this->settingsService->method('staleDefaultNumberMemberId')->willReturn(7);

        $logged = $this->recordJournal();
        $this->service()->apply(null, null, 100);

        self::assertSame('info', $logged['redirect_no_change'] ?? null);
        self::assertArrayNotHasKey('default_number_ignored', $logged->getArrayCopy());
    }

    public function testAStaleDefaultIsNotJournaledWhenTheOperatorRefused(): void
    {
        $provider = $this->createStub(PhoneProviderInterface::class);
        $provider->method('readForwardingState')->willReturn(new ForwardingState(true, '+32470000001'));
        $provider->method('setForwarding')->willThrowException(new ProviderException('OVH indisponible'));
        $this->providerConfigService->method('getActiveProvider')->willReturn($provider);
        $this->settingsService->method('getDefaultNumber')->willReturn('+32470000002');
        $this->settingsService->method('staleDefaultNumberMemberId')->willReturn(7);
        $this->userAccountRepository->method('findStaffAndSuperAdminIds')->willReturn([]);

        $logged = $this->recordJournal();
        try {
            $this->service()->apply(null, null, 100);
            self::fail('Expected a SosException.');
        } catch (SosException) {
        }

        self::assertArrayNotHasKey('default_number_ignored', $logged->getArrayCopy());
    }

    public function testApplyThrowsWhenPostChangeVerificationFails(): void
    {
        $provider = $this->createStub(PhoneProviderInterface::class);
        $provider->method('readForwardingState')->willReturnOnConsecutiveCalls(
            new ForwardingState(true, '+32470000000'),
            new ForwardingState(true, '+32470000000') // unchanged after setForwarding — verification fails
        );

        $this->providerConfigService->method('getActiveProvider')->willReturn($provider);
        $this->memberService->method('findProfileByMemberAndYear')
            ->willReturn($this->profile(1, 'Akela', '+32470000001', 'akela@test.be'));
        $this->userAccountRepository->method('findStaffAndSuperAdminIds')->willReturn([]);

        $logged = $this->recordJournal();

        try {
            $this->service()->apply(1, null, 100);
            self::fail('Expected a SosException.');
        } catch (SosException) {
        }
        self::assertSame('warning', $logged['redirect_failure'] ?? null);
    }

    public function testApplyWrapsProviderExceptionAsSosException(): void
    {
        $provider = $this->createStub(PhoneProviderInterface::class);
        $provider->method('readForwardingState')->willThrowException(new ProviderException('OVH indisponible'));

        $this->providerConfigService->method('getActiveProvider')->willReturn($provider);
        $this->memberService->method('findProfileByMemberAndYear')
            ->willReturn($this->profile(1, 'Akela', '+32470000001', 'akela@test.be'));
        $this->userAccountRepository->method('findStaffAndSuperAdminIds')->willReturn([]);

        try {
            $this->service()->apply(1, null, 100);
            self::fail('Expected a SosException.');
        } catch (SosException $e) {
            // The provider's account of the failure belongs to the journal
            // and the admin alert mail (asserted separately); the exception
            // itself carries only the sentence a page may render, plus the
            // cause.
            self::assertStringNotContainsString('OVH indisponible', $e->getMessage());
            self::assertStringContainsString('redirection du numéro SOS', $e->getMessage());
            self::assertInstanceOf(ProviderException::class, $e->getPrevious());
        }
    }

    /**
     * Issue #1102: the alert reaches every chef d'unité of the year and
     * every super-administrator — it used to reach the first superadmin
     * alone, often a technician outside the unit's staff — by email and by
     * the `sos_staff.redirect_failed` notification.
     */
    public function testApplySendsAdminAlertToEveryChefDUniteAndSuperadmin(): void
    {
        $this->providerConfigService->method('getActiveProvider')->willReturn(null);
        $this->userAccountRepository->method('findStaffAndSuperAdminIds')
            ->willReturnCallback(static function (array $years, array $roles): array {
                // The year of the redirection, the chef d'unité role only.
                return $years === [100] && $roles === ['admin'] ? [1, 2] : [];
            });
        $this->userAccountRepository->method('findById')->willReturnMap([
            [1, new UserAccount(1, 'admin@test.be', null, null, null, true, null)],
            [2, new UserAccount(2, 'chef@test.be', null, null, null, false, null)],
        ]);

        $sent = [];
        $this->mailService->expects($this->exactly(2))->method('send')
            ->willReturnCallback(static function (
                string $to,
                string $subject,
                string $html,
                string $text
            ) use (&$sent): void {
                $sent[$to] = ['html' => $html, 'text' => $text];
            });
        $this->notificationService->expects($this->once())->method('dispatch')
            ->with(
                'sos_staff.redirect_failed',
                [['userAccountId' => 1, 'memberId' => null], ['userAccountId' => 2, 'memberId' => null]],
                $this->callback(static fn(array $payload): bool => ($payload['url'] ?? null) === '/admin/sos')
            );

        try {
            $this->service()->apply(1, null, 100);
            self::fail('Expected a SosException: there is no active provider.');
        } catch (SosException) {
            // The original configuration error still reaches the caller after the alert.
        }

        self::assertSame(['admin@test.be', 'chef@test.be'], array_keys($sent));
        self::assertStringContainsString("(Configuration &gt; Téléphone d'urgence)", $sent['chef@test.be']['html']);
        self::assertStringNotContainsString("(Configuration &gt; SOS Staff d'U)", $sent['chef@test.be']['html']);
        self::assertStringContainsString("(Configuration > Téléphone d'urgence)", $sent['chef@test.be']['text']);
        self::assertStringNotContainsString("(Configuration > SOS Staff d'U)", $sent['chef@test.be']['text']);
    }

    /**
     * One alert per outage: the retries and the hourly check would
     * otherwise mail every chef several times an hour while the operator
     * refuses. The attention point carries the outage meanwhile.
     */
    public function testARefusalWhileAlreadyFailingDoesNotAlertAgain(): void
    {
        $provider = $this->createStub(PhoneProviderInterface::class);
        $provider->method('readForwardingState')->willThrowException(new ProviderException('OVH indisponible'));
        $this->providerConfigService->method('getActiveProvider')->willReturn($provider);
        $this->memberService->method('findProfileByMemberAndYear')
            ->willReturn($this->profile(1, 'Akela', '+32470000001', 'akela@test.be'));
        $this->settingsService->method('lastRedirectFailureAt')
            ->willReturn(new \DateTimeImmutable('-10 minutes'));
        $this->userAccountRepository->method('findStaffAndSuperAdminIds')->willReturn([1]);

        $this->mailService->expects($this->never())->method('send');
        $this->notificationService->expects($this->never())->method('dispatch');

        $this->expectException(SosException::class);
        $this->service()->apply(1, null, 100);
    }

    /**
     * An alert that could not be sent used to vanish in an empty catch:
     * « the chefs were warned » was a belief nobody could check.
     */
    public function testAnAlertThatCannotBeSentIsJournaled(): void
    {
        $this->providerConfigService->method('getActiveProvider')->willReturn(null);
        $this->userAccountRepository->method('findStaffAndSuperAdminIds')->willReturn([1]);
        $this->userAccountRepository->method('findById')
            ->willReturn(new UserAccount(1, 'admin@test.be', null, null, null, true, null));
        $this->mailService->method('send')->willThrowException(new \RuntimeException('smtp down admin@test.be'));
        $logged = $this->recordJournal();

        try {
            $this->service()->apply(1, null, 100);
        } catch (SosException) {
        }

        self::assertSame('warning', $logged['admin_alert_failed'] ?? null);
    }

    /**
     * One recipient whose address cannot be read (a decryption failure)
     * must not cost the chefs after it their alert (review of #1135).
     */
    public function testOneUnreadableRecipientDoesNotStopTheOthers(): void
    {
        $this->providerConfigService->method('getActiveProvider')->willReturn(null);
        $this->userAccountRepository->method('findStaffAndSuperAdminIds')->willReturn([1, 2]);
        $this->userAccountRepository->method('findById')->willReturnCallback(
            static function (int $id): UserAccount {
                if ($id === 1) {
                    throw new \RuntimeException('decryption failed');
                }
                return new UserAccount(2, 'chef@test.be', null, null, null, false, null);
            }
        );
        $sentTo = [];
        $this->mailService->method('send')->willReturnCallback(
            static function (string $to) use (&$sentTo): void {
                $sentTo[] = $to;
            }
        );
        $logged = $this->recordJournal();

        try {
            $this->service()->apply(1, null, 100);
        } catch (SosException) {
        }

        self::assertSame(['chef@test.be'], $sentTo);
        self::assertSame('warning', $logged['admin_alert_failed'] ?? null);
    }

    /**
     * The journal lines apply() writes, type => level.
     *
     * @return \ArrayObject<string, string>
     */
    private function recordJournal(): \ArrayObject
    {
        $logged = new \ArrayObject();
        $this->journalService->method('log')->willReturnCallback(
            static function (string $category, string $type, string $level) use ($logged): void {
                $logged[$type] = $level;
            }
        );
        return $logged;
    }

    /**
     * The handover pair goes through the notification centre (the two
     * types module.json declares), not through a direct mail any more —
     * so it reaches push and /notifications/preferences, which the emails
     * this replaced never did. The RULE is unchanged: the person taking
     * the duty and the person ending it, each once.
     */
    public function testApplyDispatchesAHandoverNotificationToNewAndPreviousMember(): void
    {
        $provider = $this->createStub(PhoneProviderInterface::class);
        $provider->method('readForwardingState')->willReturnOnConsecutiveCalls(
            new ForwardingState(true, '+32470000000'),
            new ForwardingState(true, '+32470000002')
        );

        $this->providerConfigService->method('getActiveProvider')->willReturn($provider);
        $this->settingsService->method('isEmailNotificationsEnabled')->willReturn(true);
        $this->memberService->method('findProfileByMemberAndYear')->willReturnMap([
            [2, 100, $this->profile(2, 'Baloo', '+32470000002', 'baloo@test.be')],
            [1, 100, $this->profile(1, 'Akela', '+32470000001', 'akela@test.be')],
        ]);
        $this->memberAccounts->method('accountIdsForMember')->willReturn([42]);

        $dispatched = [];
        $this->notificationService->expects($this->exactly(2))->method('dispatch')
            ->willReturnCallback(function (string $typeId, array $recipients, array $payload) use (&$dispatched): void {
                $dispatched[] = [$typeId, $recipients, $payload];
            });
        // The redirect itself is the only thing that mails anybody now,
        // and only on failure (the admin alert).
        $this->mailService->expects($this->never())->method('send');

        $this->service()->apply(2, 1, 100);

        self::assertSame('sos_staff.oncall_started', $dispatched[0][0]);
        self::assertSame(2, $dispatched[0][1][0]['memberId']);
        self::assertSame('sos_staff.oncall_ended', $dispatched[1][0]);
        self::assertSame(1, $dispatched[1][1][0]['memberId']);
    }

    /**
     * SECURITY.md §19: a push renders on a lock screen, outside this
     * site's access control. The number adds nothing the recipient does
     * not already know — it is their own mobile — so it never enters the
     * payload, where the email it replaced named it.
     */
    public function testHandoverPayloadCarriesNoPhoneNumber(): void
    {
        $provider = $this->createStub(PhoneProviderInterface::class);
        $provider->method('readForwardingState')->willReturnOnConsecutiveCalls(
            new ForwardingState(true, '+32470000000'),
            new ForwardingState(true, '+32470000001')
        );

        $this->providerConfigService->method('getActiveProvider')->willReturn($provider);
        $this->settingsService->method('isEmailNotificationsEnabled')->willReturn(true);
        $this->memberService->method('findProfileByMemberAndYear')
            ->willReturn($this->profile(1, 'Akela', '+32470000001', 'akela@test.be'));
        $this->memberAccounts->method('accountIdsForMember')->willReturn([42]);

        $this->notificationService->expects($this->once())->method('dispatch')
            ->willReturnCallback(function (string $typeId, array $recipients, array $payload): void {
                self::assertStringNotContainsString('+32470000001', $payload['title'] . ' ' . $payload['body']);
                self::assertSame('/admin/sos', $payload['url']);
            });

        $this->service()->apply(1, null, 100);
    }

    public function testApplySkipsTheNotificationWhenMemberHasNoEmail(): void
    {
        $provider = $this->createStub(PhoneProviderInterface::class);
        $provider->method('readForwardingState')->willReturnOnConsecutiveCalls(
            new ForwardingState(true, '+32470000000'),
            new ForwardingState(true, '+32470000001')
        );

        $this->providerConfigService->method('getActiveProvider')->willReturn($provider);
        $this->settingsService->method('isEmailNotificationsEnabled')->willReturn(true);
        $this->memberService->method('findProfileByMemberAndYear')
            ->willReturn($this->profile(1, 'Akela', '+32470000001', null));

        $this->notificationService->expects($this->never())->method('dispatch');
        $this->mailService->expects($this->never())->method('send');

        $this->service()->apply(1, null, 100);
    }

    /** A member with no account on this site is simply not a recipient. */
    public function testApplySkipsTheNotificationWhenMemberHasNoAccount(): void
    {
        $provider = $this->createStub(PhoneProviderInterface::class);
        $provider->method('readForwardingState')->willReturnOnConsecutiveCalls(
            new ForwardingState(true, '+32470000000'),
            new ForwardingState(true, '+32470000001')
        );

        $this->providerConfigService->method('getActiveProvider')->willReturn($provider);
        $this->settingsService->method('isEmailNotificationsEnabled')->willReturn(true);
        $this->memberService->method('findProfileByMemberAndYear')
            ->willReturn($this->profile(1, 'Akela', '+32470000001', 'akela@test.be'));
        $this->memberAccounts->method('accountIdsForMember')->willReturn([]);

        $this->notificationService->expects($this->never())->method('dispatch');

        $this->service()->apply(1, null, 100);
    }

    /**
     * The module's own setting stays the GLOBAL switch in front of the
     * dispatch — per-user channels are each member's business, but an
     * admin can still silence the whole thing.
     */
    public function testTheModuleSettingStillSilencesEveryHandoverNotification(): void
    {
        $provider = $this->createStub(PhoneProviderInterface::class);
        $provider->method('readForwardingState')->willReturnOnConsecutiveCalls(
            new ForwardingState(true, '+32470000000'),
            new ForwardingState(true, '+32470000001')
        );

        $this->providerConfigService->method('getActiveProvider')->willReturn($provider);
        $this->settingsService->method('isEmailNotificationsEnabled')->willReturn(false);
        $this->memberService->method('findProfileByMemberAndYear')
            ->willReturn($this->profile(1, 'Akela', '+32470000001', 'akela@test.be'));

        $this->notificationService->expects($this->never())->method('dispatch');

        $this->service()->apply(1, null, 100);
    }

    /**
     * Nobody is told when the two sides are the same person, and a null
     * side is nobody at all (the default number governs that day, and a
     * number has no inbox). Unchanged rule, kept under the new channel.
     */
    public function testApplyTellsNobodyWhenTheDutyDidNotChangeHands(): void
    {
        $provider = $this->createStub(PhoneProviderInterface::class);
        $provider->method('readForwardingState')->willReturnOnConsecutiveCalls(
            new ForwardingState(true, '+32470000000'),
            new ForwardingState(true, '+32470000001')
        );

        $this->providerConfigService->method('getActiveProvider')->willReturn($provider);
        $this->settingsService->method('isEmailNotificationsEnabled')->willReturn(true);
        $this->memberService->method('findProfileByMemberAndYear')
            ->willReturn($this->profile(1, 'Akela', '+32470000001', 'akela@test.be'));
        $this->memberAccounts->method('accountIdsForMember')->willReturn([42]);

        $this->notificationService->expects($this->never())->method('dispatch');

        $this->service()->apply(1, 1, 100);
    }

    /**
     * The notification stack is optional (ARCHITECTURE.md §7.5): without
     * it the redirection still applies and simply tells nobody.
     */
    public function testApplyStillRedirectsWithoutANotificationService(): void
    {
        $provider = $this->createMock(PhoneProviderInterface::class);
        $provider->method('readForwardingState')->willReturnOnConsecutiveCalls(
            new ForwardingState(true, '+32470000000'),
            new ForwardingState(true, '+32470000001')
        );
        $provider->expects($this->once())->method('setForwarding')->with('+32470000001');

        $this->providerConfigService->method('getActiveProvider')->willReturn($provider);
        $this->settingsService->method('isEmailNotificationsEnabled')->willReturn(true);
        $this->memberService->method('findProfileByMemberAndYear')
            ->willReturn($this->profile(1, 'Akela', '+32470000001', 'akela@test.be'));

        $service = new RedirectService(
            $this->providerConfigService,
            $this->settingsService,
            $this->memberService,
            $this->userAccountRepository,
            $this->mailService,
            $this->journalService,
            EmailTemplateRendererFactory::shippedOnlyForModule($this->twig, 'sos_staff'),
            null,
            $this->memberAccounts
        );

        $service->apply(1, null, 100);
    }

    /**
     * A notification that cannot be dispatched is journaled, never fatal:
     * the phone forwarding already succeeded (SECURITY.md §19 — a
     * notification never reverses the action that triggered it), and the
     * recipient's address never reaches the journal (§11).
     */
    public function testAFailedNotificationIsJournaledWithoutTheAddress(): void
    {
        $provider = $this->createStub(PhoneProviderInterface::class);
        $provider->method('readForwardingState')->willReturnOnConsecutiveCalls(
            new ForwardingState(true, '+32470000000'),
            new ForwardingState(true, '+32470000001')
        );

        $this->providerConfigService->method('getActiveProvider')->willReturn($provider);
        $this->settingsService->method('isEmailNotificationsEnabled')->willReturn(true);
        $this->memberService->method('findProfileByMemberAndYear')
            ->willReturn($this->profile(1, 'Akela', '+32470000001', 'akela@test.be'));
        $this->memberAccounts->method('accountIdsForMember')->willReturn([42]);
        $this->notificationService->method('dispatch')
            ->willThrowException(new \RuntimeException('push refusé pour akela@test.be, secondary@test.be, +32470000001'));

        $failures = [];
        $this->journalService->method('log')
            ->willReturnCallback(function (string $module, string $type, string $level, string $description, array $context) use (&$failures): void {
                if ($type === 'notification_failed') {
                    $failures[] = [$module, $type, $level, $description, $context];
                }
            });

        $this->service()->apply(1, null, 100);

        self::assertSame([[
            'sos_staff', 'notification_failed', 'info',
            'Échec de la notification de changement de garde.',
            ['member_id' => 1, 'type_id' => 'sos_staff.oncall_started', 'exception_class' => \RuntimeException::class],
        ]], $failures);
    }

    /**
     * Issue #1026: the dashboard asks the SAME question apply() asks —
     * where does a redirection to this member mean? — so that « expected »
     * and « applied » can never be two rules.
     */
    public function testResolveNumberIsTheMembersMobileThenTheDefaultNumberThenNothing(): void
    {
        $this->memberService->method('findProfileByMemberAndYear')->willReturnMap([
            [1, 100, $this->profile(1, 'Akela', '+32470000001', null)],
            [2, 100, $this->profile(2, 'Baloo', null, null)],
            [3, 100, null],
        ]);
        $this->settingsService->method('getDefaultNumber')->willReturn('+32470999999');

        $service = $this->service();

        self::assertSame('+32470000001', $service->resolveNumber(1, 100));
        self::assertSame('+32470999999', $service->resolveNumber(2, 100), 'a member without a mobile');
        self::assertSame('+32470999999', $service->resolveNumber(3, 100), 'a member not on the roster that year');
        self::assertSame('+32470999999', $service->resolveNumber(null, 100), 'nobody on duty');
    }

    public function testResolveNumberIsNullWhenNoDestinationExistsAtAll(): void
    {
        $this->memberService->method('findProfileByMemberAndYear')->willReturn(null);
        $this->settingsService->method('getDefaultNumber')->willReturn(null);

        self::assertNull($this->service()->resolveNumber(1, 100));
        self::assertNull($this->service()->resolveNumber(null, 100));
    }

    public function testApplyFallsBackToDefaultNumberWhenNoOneOnCall(): void
    {
        $provider = $this->createMock(PhoneProviderInterface::class);
        $provider->method('readForwardingState')->willReturnOnConsecutiveCalls(
            new ForwardingState(true, '+32470000001'),
            new ForwardingState(true, '+32470999999')
        );
        $provider->expects($this->once())->method('setForwarding')->with('+32470999999');

        $this->providerConfigService->method('getActiveProvider')->willReturn($provider);
        $this->settingsService->method('getDefaultNumber')->willReturn('+32470999999');
        $this->settingsService->method('isEmailNotificationsEnabled')->willReturn(false);

        $this->service()->apply(null, 1, 100);
    }

    /**
     * An operator's refusal is kept for the attention page (#718 § C
     * point 9), so the page can say the SOS number is stuck…
     */
    public function testAnOperatorRefusalIsKeptForTheAttentionPage(): void
    {
        $provider = $this->createStub(PhoneProviderInterface::class);
        $provider->method('readForwardingState')->willReturn(new ForwardingState(true, '+32470000000'));
        $provider->method('setForwarding')->willThrowException(new ProviderException('Refusé par l\'opérateur.'));
        $this->providerConfigService->method('getActiveProvider')->willReturn($provider);
        $this->memberService->method('findProfileByMemberAndYear')
            ->willReturn($this->profile(1, 'Akela', '+32470000001', 'akela@test.be'));
        $this->userAccountRepository->method('findStaffAndSuperAdminIds')->willReturn([]);
        $settings = $this->createMock(SosSettingsService::class);
        $settings->expects($this->once())->method('recordRedirectOutcome')->with(true, $this->anything());
        $this->settingsService = $settings;

        $this->expectException(SosException::class);
        $this->service()->apply(1, null, 100);
    }

    /**
     * …but not a missing operator: that is « not configured », read from the
     * configuration, and recording it would later read as the operator
     * having refused.
     */
    public function testNoOperatorIsNotRecordedAsARefusal(): void
    {
        $this->providerConfigService->method('getActiveProvider')->willReturn(null);
        $this->userAccountRepository->method('findStaffAndSuperAdminIds')->willReturn([]);
        $settings = $this->createMock(SosSettingsService::class);
        $settings->expects($this->never())->method('recordRedirectOutcome');
        $this->settingsService = $settings;

        $this->expectException(SosException::class);
        $this->service()->apply(1, null, 100);
    }

    /** …and a success, so the next redirect that works clears it. */
    public function testASuccessClearsTheKeptFailure(): void
    {
        $provider = $this->createStub(PhoneProviderInterface::class);
        $provider->method('readForwardingState')->willReturnOnConsecutiveCalls(
            new ForwardingState(true, '+32470000000'),
            new ForwardingState(true, '+32470000001')
        );
        $this->providerConfigService->method('getActiveProvider')->willReturn($provider);
        $this->memberService->method('findProfileByMemberAndYear')
            ->willReturn($this->profile(1, 'Akela', '+32470000001', 'akela@test.be'));
        $settings = $this->createMock(SosSettingsService::class);
        $settings->method('isEmailNotificationsEnabled')->willReturn(false);
        $settings->expects($this->once())->method('recordRedirectOutcome')->with(false, $this->anything());
        $this->settingsService = $settings;

        $this->service()->apply(1, null, 100);
    }
}
