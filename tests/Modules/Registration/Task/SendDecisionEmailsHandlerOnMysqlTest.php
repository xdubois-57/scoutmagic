<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Registration\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Core\View\EditableContentRepository;
use Core\View\EditableContentService;
use Modules\Registration\Repository\DecisionEmail;
use Modules\Registration\Repository\DecisionEmailRepository;
use Modules\Registration\Repository\RegistrationRequestRepository;
use Modules\Registration\Service\AutomaticDecisionEmailService;
use Modules\Registration\Service\DecisionEmailService;
use Modules\Registration\Service\RequestEmailService;
use Modules\Registration\Service\RequestStatusService;
use Modules\Registration\Task\SendDecisionEmailsHandler;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Registration\RegistrationTestHelper;
use Tests\UsesProductionEngine;

/**
 * The automatic sending of the decision e-mails (issue #843, IT-09) on the
 * engine production runs, on the schema the real migration builds: the
 * due date compared by the server on a DATETIME, the claim decided by a
 * conditional UPDATE's changed-rows count, and two scheduler passes — two
 * sessions — over the same due e-mails.
 *
 * What it pins is the task's promise: **a pass interrupted mid-batch
 * resumes without sending anything twice**, and two passes that overlap
 * send each due e-mail once.
 */
#[Group('database')]
class SendDecisionEmailsHandlerOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private const DECIDED_AT = '2027-01-10 08:00:00';
    private const DUE_AT = '2027-01-11 08:00:00';

    private \PDO $pdo;
    private \PDO $otherSession;
    private EncryptionService $encryption;
    private DecisionEmailRepository $emails;
    private RegistrationRequestRepository $requests;
    private int $yearId;

    /** @var list<string> every recipient the transport was handed, in order */
    private array $sent = [];

    /** The send (1-based) at which the transport breaks with an unexpected error; 0 for never. */
    private int $dieAtSend = 0;

    protected function setUp(): void
    {
        $this->pdo = $this->productionEngine();
        $this->otherSession = self::productionEngineConnection(self::$productionEngineDatabase)->getPdo();
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->emails = new DecisionEmailRepository($this->pdo);
        $this->requests = new RegistrationRequestRepository($this->pdo, $this->encryption, null, $this->emails);
        $this->yearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2027-2028', '2027-09-01', '2028-08-31');

        $settings = $this->settings($this->pdo);
        RegistrationTestHelper::enableAutomaticDecisionEmails($settings, 24);
        // Written straight into the table: on this schema `modified_by`
        // references a real account, which nothing here needs.
        $content = $this->pdo->prepare(
            "INSERT INTO editable_contents (content_key, content_type, content_value) VALUES (?, 'rich_text', ?)"
        );
        $content->execute(['registration_email_accepted_body', '<p>Bienvenue {{ prenom_enfant }} {{ lien_suivi }}</p>']);
        $content->execute(['registration_email_refused_body', '<p>Désolés. {{ lien_suivi }}</p>']);
    }

    /**
     * The premise of the claim on this engine: an UPDATE whose condition
     * no longer matches reports no row, so of two passes claiming the
     * same e-mail exactly one reads « mine ».
     */
    public function testAClaimIsWonByExactlyOneSessionOnThisEngine(): void
    {
        $email = $this->plan('Alix');

        $this->assertTrue($this->emails->claim($email->id, new \DateTimeImmutable()));
        $other = new DecisionEmailRepository($this->otherSession);
        $this->assertFalse($other->claim($email->id, new \DateTimeImmutable()));
    }

    /**
     * The due date is compared by the server on the DATETIME column: not a
     * second before, then sent.
     */
    public function testTheDueDateIsComparedByTheServer(): void
    {
        $email = $this->plan('Alix');

        $this->pass($this->pdo, '2027-01-11 07:59:59');
        $this->assertSame([], $this->sent);

        $this->pass($this->pdo, self::DUE_AT);
        $this->assertSame(['alix@example.com'], $this->sent);
        $this->assertSame(DecisionEmail::STATE_SENT, $this->emails->findById($email->id)?->state);
    }

    /**
     * Two passes from two sessions: the other session has claimed one due
     * e-mail when this one runs — this pass sends the rest and not that
     * one — and the other pass, coming after, finds nothing left to send.
     */
    public function testTwoPassesFromTwoSessionsSendEachDueEmailOnce(): void
    {
        $alix = $this->plan('Alix');
        $this->plan('Basile');
        $this->plan('Camille');
        // A minute ago: a claim made in the same second as the pass below
        // would change no column, and this engine would answer « no row »
        // for a reason that is not the one under test.
        $this->assertTrue(
            (new DecisionEmailRepository($this->otherSession))->claim($alix->id, new \DateTimeImmutable('-1 minute'))
        );

        $this->pass($this->pdo, self::DUE_AT);
        $this->pass($this->otherSession, self::DUE_AT);

        $this->assertSame(['basile@example.com', 'camille@example.com'], $this->sent);
        $this->assertSame(DecisionEmail::STATE_SENDING, $this->emails->findById($alix->id)?->state);
    }

    /**
     * The transport breaks in the middle of the second send of a batch of
     * three. That e-mail is recorded as uncertain — never as sent, never
     * resent — and the third still leaves. Then the scheduler replays the
     * run, later, as it does after a pass that died: nothing is sent twice.
     * The chain was re-armed all the same.
     */
    public function testAPassInterruptedMidBatchResumesWithoutSendingTwice(): void
    {
        $first = $this->plan('Alix');
        $second = $this->plan('Basile');
        $third = $this->plan('Camille');
        $this->dieAtSend = 2;

        $this->pass($this->pdo, self::DUE_AT);

        $this->assertSame(['alix@example.com', 'camille@example.com'], $this->sent);
        $this->assertSame(DecisionEmail::STATE_FAILED, $this->emails->findById($second->id)?->state);
        $this->assertSame(1, (int) $this->pdo->query(
            "SELECT COUNT(*) FROM scheduled_actions WHERE module_id = 'registration'
               AND task_key = 'send_decision_emails' AND status = 'pending'"
        )->fetchColumn());

        // The replay comes later — the next cron pass — so a claim would
        // change a row if anything allowed it to take a settled one.
        $this->pdo->exec('UPDATE registration_decision_emails SET attempted_at = attempted_at - INTERVAL 2 MINUTE');
        $this->dieAtSend = 0;
        $this->pass($this->pdo, self::DUE_AT);

        $this->assertSame(['alix@example.com', 'camille@example.com'], $this->sent);
        $this->assertSame(DecisionEmail::STATE_SENT, $this->emails->findById($first->id)?->state);
        $this->assertSame(DecisionEmail::STATE_FAILED, $this->emails->findById($second->id)?->state);
        $this->assertSame(DecisionEmail::STATE_SENT, $this->emails->findById($third->id)?->state);
    }

    /**
     * A pass killed outright after its claim — no exception, no outcome —
     * leaves that row `sending`. The replay sends the others, not that one.
     */
    public function testARowLeftClaimedByAKilledPassIsNeverSentAgain(): void
    {
        $killed = $this->plan('Alix');
        $this->plan('Basile');
        $this->assertTrue($this->emails->claim($killed->id, new \DateTimeImmutable('-5 minutes')));

        $this->pass($this->pdo, self::DUE_AT);

        $this->assertSame(['basile@example.com'], $this->sent);
        $this->assertSame(DecisionEmail::STATE_SENDING, $this->emails->findById($killed->id)?->state);
    }

    /**
     * An accepted request whose e-mail the automatic sending planned at
     * DECIDED_AT, for DUE_AT.
     */
    private function plan(string $firstName): DecisionEmail
    {
        $journal = new JournalService(new JournalRepository($this->pdo));
        $id = $this->requests->create($this->yearId, [
            'parent_name' => 'Parent ' . $firstName, 'child_last_name' => 'Dupont', 'child_first_name' => $firstName,
            'gender' => 'F', 'birth_date' => '2019-05-12', 'street' => 'Rue', 'number' => '1',
            'postal_code' => '1000', 'city' => 'Ville', 'email' => strtolower($firstName) . '@example.com',
            'phone1' => '000', 'phone2' => null, 'remarks' => null,
        ], null, [])['id'];
        (new RequestStatusService($this->requests, $journal))->accept($this->requests->findById($id));

        $decisionEmails = new DecisionEmailService($this->emails, $journal);
        $automation = new AutomaticDecisionEmailService(
            $this->settings($this->pdo),
            $decisionEmails
        );
        $planned = $automation->plan(
            $this->requests->findById($id),
            DecisionEmail::DECISION_ACCEPTED,
            new \DateTimeImmutable(self::DECIDED_AT)
        );
        $this->assertNotNull($planned);
        $this->assertSame(self::DUE_AT, $planned->dueAt?->format('Y-m-d H:i:s'));

        return $planned;
    }

    /**
     * One scheduler pass, through $session, at $moment.
     */
    private function pass(\PDO $session, string $moment): void
    {
        $journal = new JournalService(new JournalRepository($session));
        $context = new TaskContext(
            Connection::withPdo($session),
            $this->encryption,
            $this->transport(),
            $journal,
            $this->settings($session),
            new UserAccountRepository($session, $this->encryption),
            sys_get_temp_dir()
        );

        (new SendDecisionEmailsHandler(new \DateTimeImmutable($moment)))->handle([], $context);
    }

    private function settings(\PDO $session): SettingService
    {
        $settings = new SettingService(new SettingRepository($session));
        RegistrationTestHelper::registerManifestSettings($settings);

        return $settings;
    }

    /**
     * Records each recipient; at the send numbered $dieAtSend, breaks with
     * an error that is not the transport's refusal (a MailException), at a
     * point where whether the message left is unknown.
     */
    private function transport(): MailService
    {
        $mail = $this->createStub(MailService::class);
        $calls = 0;
        $mail->method('send')->willReturnCallback(function (string $to) use (&$calls): bool {
            $calls++;
            if ($calls === $this->dieAtSend) {
                throw new \Error('Connection reset while writing DATA');
            }
            $this->sent[] = $to;

            return true;
        });

        return $mail;
    }
}
