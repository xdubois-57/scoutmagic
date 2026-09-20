<?php

declare(strict_types=1);

namespace Tests\Core\Contact\Device;

use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Contact\Device\DeviceAuthenticator;
use Core\Contact\Device\DeviceCredentialRepository;
use Core\Contact\Device\DeviceCredentialService;
use Core\Import\MemberYearRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\ScoutYear\AuthorizationYearService;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\EncryptionService;
use Core\Security\HumanCheck\HumanCheckRateLimitRepository;
use Core\Security\Role;
use Core\Security\RoleResolver;
use Core\Security\UserAccountRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The rule that does not bend: a device token never authorises anything
 * by itself, and the account's role is recomputed on every request.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class DeviceAuthenticatorTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $enc;
    private DeviceAuthenticator $authenticator;
    private DeviceCredentialService $service;
    private DeviceCredentialRepository $repository;
    private int $accountId;
    private int $memberYearId;
    private int $yearId;
    private string $secret = '';
    private const EMAIL = 'chef@example.org';

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->enc = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->repository = new DeviceCredentialRepository($this->pdo);

        $settings = new SettingService(new SettingRepository($this->pdo));
        $settings->register(
            DeviceCredentialService::SETTING_SYNC_ENABLED,
            '1',
            'boolean',
            'Synchronisation',
            'Le coupe-circuit.',
            null,
            null,
            null,
            false
        );
        $settings->register(ScoutYearResolver::SETTING_PUBLIC_YEAR, '0', 'number', 'P', 'P', null, '^[0-9]+$', null, false);
        $settings->register(ScoutYearResolver::SETTING_STAFF_YEAR, '0', 'number', 'S', 'S', null, '^[0-9]+$', null, false);

        $scoutYearService = new ScoutYearService($this->pdo);
        [$label] = DatabaseTestHelper::scoutYear();
        $this->yearId = $scoutYearService->ensureYear($label);
        $settings->setInternal(ScoutYearResolver::SETTING_PUBLIC_YEAR, (string) $this->yearId);
        $settings->setInternal(ScoutYearResolver::SETTING_STAFF_YEAR, (string) $this->yearId);

        $this->service = new DeviceCredentialService(
            $this->repository,
            $settings,
            new JournalService(new JournalRepository($this->pdo))
        );

        $this->authenticator = new DeviceAuthenticator(
            $this->repository,
            $this->service,
            new UserAccountRepository($this->pdo, $this->enc),
            new RoleResolver(new MemberYearRepository($this->pdo), $this->enc, $this->pdo),
            new AuthorizationYearService($scoutYearService, $settings),
            $this->enc,
            new JournalService(new JournalRepository($this->pdo)),
            new HumanCheckRateLimitRepository($this->pdo)
        );

        $this->accountId = (new UserAccountRepository($this->pdo, $this->enc))->create(self::EMAIL)->id;
        $this->giveTheAccountAFunctionOfRole('admin');
        $this->secret = $this->service->create($this->accountId, 'Téléphone', $this->accountId)->secret;
    }

    /**
     * The account's role comes from its member functions, exactly as it
     * does for a browser session — which is the whole point of this test
     * file.
     */
    private function giveTheAccountAFunctionOfRole(string $role): void
    {
        $this->pdo->exec("INSERT INTO age_branches (desk_code, label) VALUES ('LOUV', 'Louveteaux')");
        $branchId = (int) $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare('INSERT INTO sections (age_branch_id, desk_code, name) VALUES (?, ?, ?)');
        $stmt->execute([$branchId, 'LOUV1', 'Les Loups']);
        $sectionId = (int) $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare('INSERT INTO functions (desk_code, label, role) VALUES (?, ?, ?)');
        $stmt->execute(['FN-' . $role, 'Fonction ' . $role, $role]);
        $functionId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('D-1')");
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted,
                email_encrypted, email_blind_index, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)'
        );
        $stmt->execute([
            $memberId,
            $this->yearId,
            $this->enc->encrypt('Camille', 'member_years.first_name'),
            $this->enc->encrypt('Dupont', 'member_years.last_name'),
            $this->enc->encrypt(self::EMAIL, 'member_years.email'),
            $this->enc->blindIndex(self::EMAIL, 'email'),
        ]);
        $this->memberYearId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, section_id, is_main_function) VALUES (?, ?, ?, 1)'
        );
        $stmt->execute([$this->memberYearId, $functionId, $sectionId]);
    }

    private function header(?string $email = null, ?string $secret = null): string
    {
        return 'Basic ' . base64_encode(($email ?? self::EMAIL) . ':' . ($secret ?? $this->secret));
    }

    /** @return list<array<string, mixed>> */
    private function failures(): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM event_log WHERE event_type = ? ORDER BY id');
        $stmt->execute(['device_credential_auth_failed']);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function testAValidCredentialOfAnAdminAuthenticates(): void
    {
        $device = $this->authenticator->authenticate($this->header(), '198.51.100.7');

        $this->assertNotNull($device);
        $this->assertSame($this->accountId, $device->userAccountId);
        $this->assertSame(Role::ADMIN, $device->role);
        $this->assertSame([], $this->failures());
    }

    /**
     * **The rule of IT-02.** The credential is untouched and still valid;
     * the account simply is not admin any more. A chief who leaves the
     * staff loses the site at the next Desk import, and their device dies
     * the same way — at the next poll, without waiting for anybody to
     * remember to revoke it.
     */
    public function testTheRoleIsResolvedAgainOnEveryRequestAndAnUnchangedTokenStopsWorking(): void
    {
        $this->assertNotNull($this->authenticator->authenticate($this->header()));

        // The very thing a Desk import does: the function's role changes.
        $this->pdo->exec("UPDATE functions SET role = 'chief'");

        $this->assertNull($this->authenticator->authenticate($this->header(), '198.51.100.7'));
        $this->assertSame(
            ['role_too_low'],
            array_map(
                static fn(array $e): string => json_decode((string) $e['context'], true)['reason'],
                $this->failures()
            )
        );
    }

    public function testAnAccountThatIsNoLongerAMemberOfTheUnitStopsSynchronising(): void
    {
        $this->pdo->exec('UPDATE member_years SET is_active = 0');

        $this->assertNull($this->authenticator->authenticate($this->header()));
    }

    public function testADeactivatedAccountStopsSynchronising(): void
    {
        $this->pdo->exec('UPDATE user_accounts SET is_active = 0');

        $this->assertNull($this->authenticator->authenticate($this->header()));
    }

    /**
     * A super-admin's role depends on no scout year at all, so their
     * device keeps working through a season with no roster.
     */
    public function testASuperAdminAuthenticatesWithoutAnyMemberFunction(): void
    {
        $this->pdo->exec('DELETE FROM member_functions');
        $this->pdo->exec('UPDATE user_accounts SET is_super_admin = 1');

        $this->assertSame(Role::SUPERADMIN, $this->authenticator->authenticate($this->header())?->role);
    }

    public function testTheSiteWideCutOutRefusesEverybodyAtOnce(): void
    {
        $this->service->setSyncEnabled(false, null);

        $this->assertNull($this->authenticator->authenticate($this->header()));

        $this->service->setSyncEnabled(true, null);
        $this->assertNotNull($this->authenticator->authenticate($this->header()));
    }

    /**
     * Cutting the site off is one act by one superadmin, already in the
     * journal. Every client on the site retrying every few minutes must
     * not write that line again forever.
     */
    public function testTheCutOutIsNotJournaledOncePerRefusedRequest(): void
    {
        $this->service->setSyncEnabled(false, null);

        $this->authenticator->authenticate($this->header(), '198.51.100.7');
        $this->authenticator->authenticate($this->header(), '198.51.100.7');

        $this->assertSame([], $this->failures());
    }

    public function testARevokedCredentialIsRefused(): void
    {
        $this->repository->revoke($this->service->listForAccount($this->accountId)[0]->id);

        $this->assertNull($this->authenticator->authenticate($this->header()));
    }

    public function testAWrongSecretAndAnUnknownAddressAreBothRefusedAndJournaled(): void
    {
        $this->assertNull($this->authenticator->authenticate($this->header(secret: 'nope'), '198.51.100.7'));
        $this->assertNull($this->authenticator->authenticate($this->header(email: 'inconnu@example.org'), '198.51.100.7'));

        $reasons = array_map(
            static fn(array $e): string => json_decode((string) $e['context'], true)['reason'],
            $this->failures()
        );
        $this->assertSame(['bad_secret', 'unknown_account'], $reasons);
    }

    /**
     * A journal entry carries a reason and an account id at most. Never
     * the address that was offered, never the secret — and never a copy
     * of the source address, which the journal's own `ip_address` column
     * already holds.
     */
    public function testAFailureEntryNamesNobody(): void
    {
        $this->authenticator->authenticate($this->header(secret: 'nope'), '198.51.100.7');

        $entry = $this->failures()[0];
        $this->assertSame('security', $entry['level']);
        $this->assertSame(
            ['reason' => 'bad_secret', 'user_account_id' => $this->accountId],
            json_decode((string) $entry['context'], true)
        );
        $row = (string) $entry['context'] . '|' . (string) $entry['description'];
        foreach ([self::EMAIL, 'nope', '198.51.100.7', 'Camille', 'Dupont'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $row);
        }
    }

    /**
     * A misconfigured client retries forever. The refusals stay
     * identical; the journal entries stop, so one bad password cannot
     * bury everything else in the journal — nor be used to bury a real
     * attempt.
     */
    public function testFailureJournallingIsBoundedPerSourceAddress(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->assertNull($this->authenticator->authenticate($this->header(secret: 'nope'), '198.51.100.7'));
        }

        $this->assertLessThanOrEqual(5, count($this->failures()));
        $this->assertNotSame([], $this->failures());
    }

    /**
     * Whatever the reason, the caller gets exactly the same nothing: no
     * client — and nobody holding the URL — can learn from the answer
     * whether an address has an account, whether a credential exists, or
     * whether a role changed.
     */
    public function testEveryRefusalIsTheSameRefusal(): void
    {
        $this->assertNull($this->authenticator->authenticate(null));
        $this->assertNull($this->authenticator->authenticate('Bearer something'));
        $this->assertNull($this->authenticator->authenticate('Basic ' . base64_encode('no-colon')));
        $this->assertNull($this->authenticator->authenticate($this->header(email: 'inconnu@example.org')));
        $this->assertNull($this->authenticator->authenticate($this->header(secret: 'nope')));
    }

    /**
     * @dataProvider basicHeaders
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('basicHeaders')]
    public function testBasicHeaderParsing(?string $header, ?array $expected): void
    {
        $this->assertSame($expected, DeviceAuthenticator::parseBasic($header));
    }

    /**
     * @return array<string, array{0: ?string, 1: ?array{0: string, 1: string}}>
     */
    public static function basicHeaders(): array
    {
        return [
            'absent' => [null, null],
            'another scheme' => ['Bearer abc', null],
            'not base64' => ['Basic ***', null],
            'no separator' => ['Basic ' . base64_encode('user'), null],
            'empty user' => ['Basic ' . base64_encode(':secret'), null],
            'empty secret' => ['Basic ' . base64_encode('user:'), null],
            'ordinary' => ['Basic ' . base64_encode('a@b.c:s3cret'), ['a@b.c', 's3cret']],
            // A hex secret carries no colon, but an address book is free
            // to send one in the password field and the split must be on
            // the FIRST colon, never the last.
            'colon in the secret' => ['Basic ' . base64_encode('a@b.c:s3:cret'), ['a@b.c', 's3:cret']],
            'leading whitespace' => ['  Basic ' . base64_encode('a@b.c:x') . '  ', ['a@b.c', 'x']],
        ];
    }

    public function testRecordingASynchronisationStampsTheCredential(): void
    {
        $device = $this->authenticator->authenticate($this->header());
        $this->assertNotNull($device);
        $this->assertTrue($device->credential->hasNeverSynchronised());

        $this->authenticator->recordSync($device);

        $this->assertFalse($this->repository->findById($device->credential->id)?->hasNeverSynchronised());
    }
}
