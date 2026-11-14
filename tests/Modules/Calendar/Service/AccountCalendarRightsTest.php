<?php

declare(strict_types=1);

namespace Tests\Modules\Calendar\Service;

use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Import\MemberYearRepository;
use Core\Member\SectionStaffAuthorizationService;
use Core\ScoutYear\AuthorizationYears;
use Core\ScoutYear\AuthorizationYearService;
use Core\ScoutYear\ScoutYearResolver;
use Core\ScoutYear\StaffYearEligibility;
use Core\Security\Role;
use Core\Security\RoleResolver;
use Core\Security\UserAccount;
use Core\Security\UserAccountRepository;
use Modules\Calendar\Service\AccountCalendarRights;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * Issue #1165: an import keeps re-checking the rights of the account that
 * started it, in later requests that somebody else may be driving. The
 * scout year its staffed sections are read in has to be THAT account's —
 * whether it is served the staff year is a question about it, not about
 * whoever is signed in, nor about nobody.
 *
 * The real ScoutYearResolver and StaffYearEligibility, over real settings
 * and years; the account's Desk standing is answered by a RoleResolver
 * double, and the staffed-sections lookup records the year it was asked
 * about — the one value this test is about.
 *
 * @group database
 */
#[Group('database')]
final class AccountCalendarRightsTest extends TestCase
{
    private const ACCOUNT = 7;
    private const EMAIL = 'animateur@exemple.test';

    private ScoutYearResolver $resolver;
    private int $publicYear;
    private int $staffYear;
    /** @var list<int> the years the staffed sections were asked in */
    private array $askedYears = [];
    private bool $intendantInStaffYear = false;

    protected function setUp(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        $scoutYears = new ScoutYearService($pdo);
        $settings = new SettingService(new SettingRepository($pdo));
        foreach ([ScoutYearResolver::SETTING_PUBLIC_YEAR, ScoutYearResolver::SETTING_STAFF_YEAR] as $key) {
            $settings->register($key, '0', 'number', 'Année', 'Identifiant', null, '^[0-9]+$', null, false);
        }
        $this->publicYear = $scoutYears->ensureYear('2025-2026');
        $this->staffYear = $scoutYears->ensureYear('2026-2027');
        $settings->setInternal(ScoutYearResolver::SETTING_PUBLIC_YEAR, (string) $this->publicYear);
        $settings->setInternal(ScoutYearResolver::SETTING_STAFF_YEAR, (string) $this->staffYear);

        $this->resolver = new ScoutYearResolver($scoutYears, $settings, new MemberYearRepository($pdo));
    }

    /**
     * The ticket's reproduction: the account lost its administration rights
     * mid-import and is an animateur in the public year only, while the
     * super-administrator driving the import IS served the staff year. The
     * sections are still read in the account's year, the public one.
     */
    public function testAnotherSessionsStaffYearIsNotTheAccounts(): void
    {
        $this->sessionEligibleForStaffYear(true);
        $this->intendantInStaffYear = false;

        $this->rights()->of(self::ACCOUNT);

        $this->assertSame([$this->publicYear], $this->askedYears);
    }

    /** And the other way round: the account's own staff year, whoever is signed in. */
    public function testTheAccountsOwnStaffYearIsServedWhateverTheSession(): void
    {
        $this->sessionEligibleForStaffYear(false);
        $this->intendantInStaffYear = true;

        $this->rights()->of(self::ACCOUNT);

        $this->assertSame([$this->staffYear], $this->askedYears);
    }

    /**
     * The guarantee itself: the same sections whether the session is the
     * account's, another super-administrator's, or nobody's — a background
     * root never wires the session's eligibility at all.
     */
    public function testTheYearDoesNotDependOnWhoIsSignedIn(): void
    {
        foreach ([false, true] as $accountEligible) {
            $this->intendantInStaffYear = $accountEligible;
            $expected = $accountEligible ? $this->staffYear : $this->publicYear;

            foreach ([null, true, false] as $session) {
                $this->askedYears = [];
                $this->resolver = $this->freshResolver($session);

                $this->rights()->of(self::ACCOUNT);

                $this->assertSame(
                    [$expected],
                    $this->askedYears,
                    'account ' . ($accountEligible ? 'eligible' : 'not eligible') . ', session '
                        . var_export($session, true)
                );
            }
        }
    }

    /** The session's own wiring is untouched for everybody else. */
    public function testTheSessionsEligibilityStillAnswersWhenNoAccountIsNamed(): void
    {
        $this->sessionEligibleForStaffYear(true);

        $this->assertSame($this->staffYear, $this->resolver->getEffectiveYear(null, Role::CHIEF)->id);
    }

    private function sessionEligibleForStaffYear(bool $eligible): void
    {
        $this->resolver->setStaffYearEligibility(static fn (int $yearId): bool => $eligible);
    }

    private function freshResolver(?bool $session): ScoutYearResolver
    {
        $resolver = clone $this->resolver;
        if ($session !== null) {
            $resolver->setStaffYearEligibility(static fn (int $yearId): bool => $session);
        }

        return $resolver;
    }

    private function rights(): AccountCalendarRights
    {
        $accounts = $this->createStub(UserAccountRepository::class);
        $accounts->method('findById')->willReturn(
            new UserAccount(self::ACCOUNT, self::EMAIL, 'Anna', 'Dupont', null, false, null)
        );

        $roles = $this->createStub(RoleResolver::class);
        $roles->method('resolveAcrossYears')->willReturn('chief');
        $roles->method('resolve')->willReturnCallback(
            fn (string $email, int $yearId): string => $email === self::EMAIL
                && $yearId === $this->staffYear
                && $this->intendantInStaffYear ? 'intendant' : 'identified'
        );

        $authorizationYears = $this->createStub(AuthorizationYearService::class);
        $authorizationYears->method('resolve')
            ->willReturn(new AuthorizationYears($this->publicYear, [$this->publicYear]));

        $staff = $this->createStub(SectionStaffAuthorizationService::class);
        $staff->method('getStaffedSections')->willReturnCallback(
            function (string $email, string $role, int $yearId): array {
                $this->askedYears[] = $yearId;

                return [['id' => 3]];
            }
        );

        return new AccountCalendarRights(
            $accounts,
            $roles,
            $authorizationYears,
            $staff,
            $this->resolver,
            new StaffYearEligibility($roles)
        );
    }
}
