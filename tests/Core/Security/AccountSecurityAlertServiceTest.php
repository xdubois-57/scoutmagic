<?php

declare(strict_types=1);

namespace Tests\Core\Security;

use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailException;
use Core\Mail\MailService;
use Core\Mail\Template\EmailTemplateRegistry;
use Core\Notification\NotificationService;
use Core\Security\AccountSecurityAlertService;
use Core\Security\AccountSecurityChange;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Core\Mail\Template\EmailTemplateRendererFactory;
use Tests\DatabaseTestHelper;
use Tests\TestTwig;

/**
 * The alert an account's holder gets when a way into their account changes
 * (issue #1115, audit SEC-018): one e-mail to the account's own address,
 * one in-app notification, one `security` journal entry with identifiers
 * only — and never an exception for the action it reports.
 *
 * Rendered through the real register and the real templates, so a sentence
 * asserted here is a sentence the holder reads.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class AccountSecurityAlertServiceTest extends TestCase
{
    private const ADDRESS = 'titulaire@example.com';

    private \PDO $pdo;
    private UserAccountRepository $userRepo;
    private int $accountId;

    /** @var list<array{to: string, subject: string, html: string, text: string}> */
    private array $sent = [];

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->userRepo = new UserAccountRepository(
            $this->pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
        $this->accountId = $this->userRepo->create(self::ADDRESS)->id;
        $this->sent = [];
    }

    private function service(
        ?MailService $mailService = null,
        ?NotificationService $notifications = null
    ): AccountSecurityAlertService {
        if ($mailService === null) {
            $mailService = $this->createStub(MailService::class);
            $mailService->method('send')->willReturnCallback(
                function (string $to, string $subject, string $bodyHtml, string $bodyText): void {
                    $this->sent[] = ['to' => $to, 'subject' => $subject, 'html' => $bodyHtml, 'text' => $bodyText];
                }
            );
        }

        return new AccountSecurityAlertService(
            $this->userRepo,
            $mailService,
            EmailTemplateRendererFactory::overTestDatabase($this->pdo, TestTwig::create()),
            'Unité Test',
            'https://unite.example/',
            new JournalService(new JournalRepository($this->pdo)),
            $notifications
        );
    }

    /** @return list<array<string, mixed>> */
    private function journalEntries(): array
    {
        return $this->pdo->query('SELECT * FROM event_log ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * @return array<string, array{AccountSecurityChange, ?int, ?string, ?string}>
     */
    public static function changes(): array
    {
        return [
            'first password' => [AccountSecurityChange::PasswordSet, null, null, 'password_set'],
            'password changed' => [AccountSecurityChange::PasswordChanged, null, null, 'password_changed'],
            'passkey added' => [AccountSecurityChange::PasskeyAdded, 7, 'iPhone de Camille', 'passkey_registered'],
            'passkey removed' => [AccountSecurityChange::PasskeyRemoved, 7, 'iPhone de Camille', 'passkey_deleted'],
            // Journaled by DeviceCredentialService itself, never twice.
            'device credential' => [AccountSecurityChange::DeviceCredentialCreated, 3, 'Thunderbird', null],
        ];
    }

    #[DataProvider('changes')]
    public function testEachChangeSendsOneEmailToTheAccountsOwnAddress(
        AccountSecurityChange $change,
        ?int $credentialId,
        ?string $label,
        ?string $journalAction
    ): void {
        $this->service()->report($this->accountId, $change, $credentialId, $label);

        $this->assertCount(1, $this->sent);
        $email = $this->sent[0];
        $this->assertSame(self::ADDRESS, $email['to']);
        $this->assertSame('Alerte de sécurité sur votre compte', $email['subject']);

        foreach (['html' => $email['html'], 'text' => $email['text']] as $half => $body) {
            $body = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $this->assertStringContainsString($change->label(), $body, "The {$half} half must name what changed.");
            $this->assertStringContainsString('Mot de passe oublié', $body, "The {$half} half must say what to do.");
            $this->assertStringContainsString('https://unite.example/login', $body);
            $this->assertStringContainsString('https://unite.example/account', $body);
            $this->assertMatchesRegularExpression('#\d{2}/\d{2}/\d{4} à \d{2}:\d{2}#', $body);
            if ($label !== null) {
                $this->assertStringContainsString($label, $body, "The {$half} half must name the key or device.");
            } else {
                $this->assertStringNotContainsString('Nom indiqué', $body);
            }
        }
    }

    #[DataProvider('changes')]
    public function testEachChangeWritesOneSecurityEntryWithoutPersonalData(
        AccountSecurityChange $change,
        ?int $credentialId,
        ?string $label,
        ?string $journalAction
    ): void {
        $this->service()->report($this->accountId, $change, $credentialId, $label);

        $entries = $this->journalEntries();
        if ($journalAction === null) {
            $this->assertSame([], $entries, 'The action journals itself; the alert must not add a second line.');

            return;
        }

        $this->assertCount(1, $entries);
        $entry = $entries[0];
        $this->assertSame('core', $entry['category']);
        $this->assertSame($journalAction, $entry['event_type']);
        $this->assertSame('security', $entry['level']);
        $this->assertSame($this->accountId, (int) $entry['user_account_id']);
        $this->assertSame(
            $credentialId !== null ? ['credential_id' => $credentialId] : null,
            $entry['context'] !== null ? json_decode((string) $entry['context'], true) : null
        );

        $row = (string) $entry['context'] . '|' . (string) $entry['description'];
        $this->assertStringNotContainsString(self::ADDRESS, $row);
        if ($label !== null) {
            $this->assertStringNotContainsString($label, $row);
        }
    }

    /**
     * The change has already happened: a mail server being down must not
     * turn it into an error page. The failure is journaled — which alert,
     * which channel, nothing personal.
     */
    public function testAMailFailureNeverThrowsAndIsJournaled(): void
    {
        $mailService = $this->createStub(MailService::class);
        $mailService->method('send')->willThrowException(new MailException('SMTP connect() failed'));

        $this->service($mailService)->report($this->accountId, AccountSecurityChange::PasswordChanged);

        $entries = $this->journalEntries();
        $this->assertSame(['password_changed', 'account_security_alert_failed'], array_column($entries, 'event_type'));
        $this->assertSame('warning', $entries[1]['level']);
        $this->assertSame(
            ['change' => 'password_changed', 'channel' => 'email'],
            json_decode((string) $entries[1]['context'], true)
        );
        $this->assertStringNotContainsString(self::ADDRESS, (string) $entries[1]['description']);
    }

    public function testTheHolderIsAlsoNotifiedInApp(): void
    {
        $notifications = $this->createMock(NotificationService::class);
        $notifications->expects($this->once())
            ->method('notify')
            ->with(
                $this->identicalTo($this->accountId),
                $this->identicalTo('Alerte de sécurité'),
                $this->stringContains(AccountSecurityChange::PasskeyAdded->label()),
                $this->identicalTo('/account')
            );

        $this->service(null, $notifications)
            ->report($this->accountId, AccountSecurityChange::PasskeyAdded, 7, 'iPhone de Camille');
    }

    /**
     * A lock screen is read by whoever holds the phone: the notification
     * names what happened, never the key's name.
     */
    public function testTheNotificationNeverCarriesTheCredentialsName(): void
    {
        $notifications = $this->createMock(NotificationService::class);
        $notifications->expects($this->once())
            ->method('notify')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->logicalNot($this->stringContains('Camille')),
                $this->anything()
            );

        $this->service(null, $notifications)
            ->report($this->accountId, AccountSecurityChange::PasskeyAdded, 7, 'iPhone de Camille');
    }

    public function testANotificationFailureNeverThrowsAndTheEmailStillLeaves(): void
    {
        $notifications = $this->createStub(NotificationService::class);
        $notifications->method('notify')->willThrowException(new \RuntimeException('push down'));

        $this->service(null, $notifications)->report($this->accountId, AccountSecurityChange::PasswordSet);

        $this->assertCount(1, $this->sent);
        $this->assertSame(
            ['password_set', 'account_security_alert_failed'],
            array_column($this->journalEntries(), 'event_type')
        );
    }

    /**
     * An account deleted in the meantime has no address to warn: nothing is
     * sent, and nothing throws.
     */
    public function testAnUnknownAccountSendsNothing(): void
    {
        $this->service()->report(999999, AccountSecurityChange::PasswordChanged);

        $this->assertSame([], $this->sent);
    }

    /**
     * Declared in the register, and locked: an alarm a unit could reword
     * into silence would be no alarm.
     */
    public function testTheTemplateIsDeclaredAndNotEditable(): void
    {
        $template = (new EmailTemplateRegistry())->find(AccountSecurityAlertService::TEMPLATE_ID);

        $this->assertNotNull($template);
        $this->assertFalse($template->editable);
        $this->assertNull($template->moduleId());
    }
}
