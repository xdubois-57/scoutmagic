<?php

declare(strict_types=1);

namespace Tests\Modules\SosStaff\Service;

use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Member\MemberFunctionInfo;
use Core\Member\MemberProfile;
use Core\Member\MemberService;
use Core\Notification\NotificationService;
use Core\Security\UserAccount;
use Core\Security\UserAccountRepository;
use Modules\SosStaff\Provider\ForwardingState;
use Modules\SosStaff\Provider\PhoneProviderInterface;
use Modules\SosStaff\Provider\ProviderException;
use Modules\SosStaff\Service\ProviderConfigService;
use Modules\SosStaff\Service\RedirectService;
use Modules\SosStaff\Service\SosException;
use Modules\SosStaff\Service\SosSettingsService;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

class RedirectServiceTest extends TestCase
{
    private ProviderConfigService $providerConfigService;
    private SosSettingsService $settingsService;
    private MemberService $memberService;
    private UserAccountRepository $userAccountRepository;
    private MailService $mailService;
    private JournalService $journalService;
    private NotificationService $notificationService;
    private Environment $twig;

    protected function setUp(): void
    {
        $this->providerConfigService = $this->createMock(ProviderConfigService::class);
        $this->settingsService = $this->createMock(SosSettingsService::class);
        $this->memberService = $this->createMock(MemberService::class);
        $this->userAccountRepository = $this->createMock(UserAccountRepository::class);
        $this->mailService = $this->createMock(MailService::class);
        $this->journalService = $this->createMock(JournalService::class);
        $this->notificationService = $this->createMock(NotificationService::class);

        $loader = new FilesystemLoader(dirname(__DIR__, 4) . '/core/View/templates');
        $loader->addPath(dirname(__DIR__, 4) . '/modules/sos_staff/views', 'sos_staff');
        $this->twig = new Environment($loader, ['cache' => false, 'autoescape' => 'html']);
        // asset() is what base.html.twig references every static file through
        // (Core\View\TwigFactory); the bare path is enough for a test render.
        $this->twig->addFunction(new \Twig\TwigFunction('asset', static fn (string $path): string => $path));
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
            $this->twig,
            $notifications ?? $this->notificationService
        );
    }

    /**
     * A signed-up account for a member's address — what turns a handover
     * into a recipient. A member with none is simply not one.
     */
    private function accountFor(string $email): UserAccount
    {
        return new UserAccount(42, $email, null, null, null, false, null);
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
        $this->userAccountRepository->method('findFirstSuperAdmin')->willReturn(null);

        $this->journalService->expects($this->once())->method('log')
            ->with('sos_staff', 'redirect_failure', 'info', $this->anything(), $this->anything());

        $this->expectException(SosException::class);
        $this->service()->apply(1, null, 100);
    }

    public function testApplyThrowsWhenNoNumberResolvable(): void
    {
        $provider = $this->createMock(PhoneProviderInterface::class);
        $this->providerConfigService->method('getActiveProvider')->willReturn($provider);
        $this->memberService->method('findProfileByMemberAndYear')->willReturn(null);
        $this->settingsService->method('getDefaultNumber')->willReturn(null);
        $this->userAccountRepository->method('findFirstSuperAdmin')->willReturn(null);

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

    public function testApplyThrowsWhenPostChangeVerificationFails(): void
    {
        $provider = $this->createMock(PhoneProviderInterface::class);
        $provider->method('readForwardingState')->willReturnOnConsecutiveCalls(
            new ForwardingState(true, '+32470000000'),
            new ForwardingState(true, '+32470000000') // unchanged after setForwarding — verification fails
        );

        $this->providerConfigService->method('getActiveProvider')->willReturn($provider);
        $this->memberService->method('findProfileByMemberAndYear')
            ->willReturn($this->profile(1, 'Akela', '+32470000001', 'akela@test.be'));
        $this->userAccountRepository->method('findFirstSuperAdmin')->willReturn(null);

        $this->journalService->expects($this->once())->method('log')
            ->with('sos_staff', 'redirect_failure', 'info', $this->anything(), $this->anything());

        $this->expectException(SosException::class);
        $this->service()->apply(1, null, 100);
    }

    public function testApplyWrapsProviderExceptionAsSosException(): void
    {
        $provider = $this->createMock(PhoneProviderInterface::class);
        $provider->method('readForwardingState')->willThrowException(new ProviderException('OVH indisponible'));

        $this->providerConfigService->method('getActiveProvider')->willReturn($provider);
        $this->memberService->method('findProfileByMemberAndYear')
            ->willReturn($this->profile(1, 'Akela', '+32470000001', 'akela@test.be'));
        $this->userAccountRepository->method('findFirstSuperAdmin')->willReturn(null);

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

    public function testApplySendsAdminAlertEmailOnFailure(): void
    {
        $this->providerConfigService->method('getActiveProvider')->willReturn(null);
        $admin = new UserAccount(1, 'admin@test.be', null, null, null, true, null);
        $this->userAccountRepository->method('findFirstSuperAdmin')->willReturn($admin);

        $this->mailService->expects($this->once())->method('send')
            ->with('admin@test.be', $this->anything(), $this->anything(), $this->anything());

        try {
            $this->service()->apply(1, null, 100);
        } catch (SosException $e) {
            // Expected — assertions are on the mail mock above.
        }
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
        $provider = $this->createMock(PhoneProviderInterface::class);
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
        $this->userAccountRepository->method('findByEmail')
            ->willReturnCallback(fn(string $email) => $this->accountFor($email));

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
        $provider = $this->createMock(PhoneProviderInterface::class);
        $provider->method('readForwardingState')->willReturnOnConsecutiveCalls(
            new ForwardingState(true, '+32470000000'),
            new ForwardingState(true, '+32470000001')
        );

        $this->providerConfigService->method('getActiveProvider')->willReturn($provider);
        $this->settingsService->method('isEmailNotificationsEnabled')->willReturn(true);
        $this->memberService->method('findProfileByMemberAndYear')
            ->willReturn($this->profile(1, 'Akela', '+32470000001', 'akela@test.be'));
        $this->userAccountRepository->method('findByEmail')->willReturn($this->accountFor('akela@test.be'));

        $this->notificationService->expects($this->once())->method('dispatch')
            ->willReturnCallback(function (string $typeId, array $recipients, array $payload): void {
                self::assertStringNotContainsString('+32470000001', $payload['title'] . ' ' . $payload['body']);
                self::assertSame('/admin/sos', $payload['url']);
            });

        $this->service()->apply(1, null, 100);
    }

    public function testApplySkipsTheNotificationWhenMemberHasNoEmail(): void
    {
        $provider = $this->createMock(PhoneProviderInterface::class);
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
        $provider = $this->createMock(PhoneProviderInterface::class);
        $provider->method('readForwardingState')->willReturnOnConsecutiveCalls(
            new ForwardingState(true, '+32470000000'),
            new ForwardingState(true, '+32470000001')
        );

        $this->providerConfigService->method('getActiveProvider')->willReturn($provider);
        $this->settingsService->method('isEmailNotificationsEnabled')->willReturn(true);
        $this->memberService->method('findProfileByMemberAndYear')
            ->willReturn($this->profile(1, 'Akela', '+32470000001', 'akela@test.be'));
        $this->userAccountRepository->method('findByEmail')->willReturn(null);

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
        $provider = $this->createMock(PhoneProviderInterface::class);
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
        $provider = $this->createMock(PhoneProviderInterface::class);
        $provider->method('readForwardingState')->willReturnOnConsecutiveCalls(
            new ForwardingState(true, '+32470000000'),
            new ForwardingState(true, '+32470000001')
        );

        $this->providerConfigService->method('getActiveProvider')->willReturn($provider);
        $this->settingsService->method('isEmailNotificationsEnabled')->willReturn(true);
        $this->memberService->method('findProfileByMemberAndYear')
            ->willReturn($this->profile(1, 'Akela', '+32470000001', 'akela@test.be'));
        $this->userAccountRepository->method('findByEmail')->willReturn($this->accountFor('akela@test.be'));

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
            $this->twig,
            null
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
        $provider = $this->createMock(PhoneProviderInterface::class);
        $provider->method('readForwardingState')->willReturnOnConsecutiveCalls(
            new ForwardingState(true, '+32470000000'),
            new ForwardingState(true, '+32470000001')
        );

        $this->providerConfigService->method('getActiveProvider')->willReturn($provider);
        $this->settingsService->method('isEmailNotificationsEnabled')->willReturn(true);
        $this->memberService->method('findProfileByMemberAndYear')
            ->willReturn($this->profile(1, 'Akela', '+32470000001', 'akela@test.be'));
        $this->userAccountRepository->method('findByEmail')->willReturn($this->accountFor('akela@test.be'));
        $this->notificationService->method('dispatch')
            ->willThrowException(new \RuntimeException('push refusé pour akela@test.be'));

        $descriptions = [];
        $this->journalService->method('log')
            ->willReturnCallback(function (string $module, string $type, string $level, string $description) use (&$descriptions): void {
                $descriptions[] = $description;
            });

        $this->service()->apply(1, null, 100);

        $notificationFailures = array_values(array_filter(
            $descriptions,
            static fn(string $d) => str_contains($d, 'notification de changement de garde')
        ));
        self::assertCount(1, $notificationFailures);
        self::assertStringNotContainsString('akela@test.be', $notificationFailures[0]);
        self::assertStringContainsString('[adresse]', $notificationFailures[0]);
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
}
