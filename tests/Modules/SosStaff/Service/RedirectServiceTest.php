<?php

declare(strict_types=1);

namespace Tests\Modules\SosStaff\Service;

use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Member\MemberFunctionInfo;
use Core\Member\MemberProfile;
use Core\Member\MemberService;
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
    private Environment $twig;

    protected function setUp(): void
    {
        $this->providerConfigService = $this->createMock(ProviderConfigService::class);
        $this->settingsService = $this->createMock(SosSettingsService::class);
        $this->memberService = $this->createMock(MemberService::class);
        $this->userAccountRepository = $this->createMock(UserAccountRepository::class);
        $this->mailService = $this->createMock(MailService::class);
        $this->journalService = $this->createMock(JournalService::class);

        $loader = new FilesystemLoader(dirname(__DIR__, 4) . '/core/View/templates');
        $loader->addPath(dirname(__DIR__, 4) . '/modules/sos_staff/views', 'sos_staff');
        $this->twig = new Environment($loader, ['cache' => false, 'autoescape' => 'html']);
    }

    private function service(): RedirectService
    {
        return new RedirectService(
            $this->providerConfigService,
            $this->settingsService,
            $this->memberService,
            $this->userAccountRepository,
            $this->mailService,
            $this->journalService,
            $this->twig
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

        $this->expectException(SosException::class);
        $this->expectExceptionMessage('OVH indisponible');
        $this->service()->apply(1, null, 100);
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

    public function testApplySendsHandoverEmailsToNewAndPreviousMember(): void
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

        $this->mailService->expects($this->exactly(2))->method('send');

        $this->service()->apply(2, 1, 100);
    }

    public function testApplySkipsEmailWhenMemberHasNoEmail(): void
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

        $this->mailService->expects($this->never())->method('send');

        $this->service()->apply(1, null, 100);
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
