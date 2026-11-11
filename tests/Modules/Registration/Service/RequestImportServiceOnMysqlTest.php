<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Registration\Service;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\EncryptionService;
use Modules\Registration\Api\ImportedRequest;
use Modules\Registration\Api\ImportedSecondaryEmail;
use Modules\Registration\Repository\DecisionEmail;
use Modules\Registration\Repository\DecisionEmailRepository;
use Modules\Registration\Repository\RegistrationRequestRepository;
use Modules\Registration\Repository\RegistrationSecondaryEmailRepository;
use Modules\Registration\Service\RequestImportService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Registration\RegistrationTestHelper;
use Tests\UsesProductionEngine;

/**
 * The import write contract (chantier #1142, lot H) on the engine
 * production runs, where three of its answers are the engine's: a request
 * created inside a transaction somebody else opened (the data migration
 * module writes each item in its own) must join it and roll back with it;
 * the « Communiquée par l'ancien site » line is an INSERT … SELECT that
 * creates nothing for a request not holding that decision; and the ENUM
 * must hold the new state, or MySQL would refuse it in strict mode.
 */
#[Group('database')]
class RequestImportServiceOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private \PDO $pdo;
    private RegistrationRequestRepository $requests;
    private DecisionEmailRepository $decisionEmails;
    private RegistrationSecondaryEmailRepository $secondaryEmails;
    private RequestImportService $service;
    private int $yearId;

    protected function setUp(): void
    {
        $this->pdo = $this->productionEngine();
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->decisionEmails = new DecisionEmailRepository($this->pdo);
        $this->requests = new RegistrationRequestRepository($this->pdo, $encryption, null, $this->decisionEmails);
        $this->secondaryEmails = new RegistrationSecondaryEmailRepository($this->pdo, $encryption);
        $this->service = new RequestImportService(
            $this->requests,
            $this->decisionEmails,
            $this->secondaryEmails,
            new SettingService(new SettingRepository($this->pdo)),
            new JournalService(new JournalRepository($this->pdo))
        );
        $this->yearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2027-2028', '2027-09-01', '2028-08-31');
    }

    private function request(string $status, string $firstName = 'Emma'): ImportedRequest
    {
        return new ImportedRequest(
            scoutYearId: $this->yearId,
            receivedAt: new \DateTimeImmutable('2026-10-12 19:04:00'),
            status: $status,
            fields: [
                'parent_name' => 'Paul Martin', 'child_last_name' => 'Martin', 'child_first_name' => $firstName,
                'gender' => 'F', 'birth_date' => '2019-05-06', 'street' => 'Rue Haute', 'number' => '3',
                'postal_code' => '5000', 'city' => 'Namur', 'email' => 'paul@example.org', 'phone1' => '0471111111',
            ],
            decidedAt: new \DateTimeImmutable('2026-11-03 10:00:00'),
            secondaryEmails: [new ImportedSecondaryEmail('mamie@example.org', 'valid')]
        );
    }

    private function count(string $table): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }

    public function testAWriteJoinsTheCallersTransactionAndRollsBackWithIt(): void
    {
        $this->pdo->beginTransaction();
        $id = $this->service->write($this->request('accepted'));
        $this->assertTrue($this->pdo->inTransaction(), 'The write must not commit the caller\'s transaction.');
        $this->assertSame(1, $this->count('registration_requests'));
        $this->pdo->rollBack();

        $this->assertNull($this->requests->findById($id));
        $this->assertSame(0, $this->count('registration_decision_emails'));
        $this->assertSame(0, $this->count('registration_secondary_emails'));
    }

    public function testTheCommunicatedLineIsWrittenOnlyForTheDecisionTheRequestHolds(): void
    {
        $id = $this->service->write($this->request('accepted'));
        $now = new \DateTimeImmutable();

        $this->assertNull($this->decisionEmails->insertCommunicatedElsewhere($id, 'refused', null, $now));
        $this->assertSame([$id => ['accepted' => true]], $this->decisionEmails->communicatedElsewhereByRequest([$id]));

        $row = $this->decisionEmails->findForRequest($id)[0];
        $this->assertSame(DecisionEmail::STATE_COMMUNICATED_ELSEWHERE, $row->state);
        $this->assertSame('2026-11-03 10:00:00', $row->communicatedAt?->format('Y-m-d H:i:s'));
        // No sender can take it: not scheduled, so never claimed.
        $this->assertFalse($this->decisionEmails->claim($row->id, $now, true));
        $this->assertFalse($this->decisionEmails->markSent($row->id, $now));
        $this->assertSame(0, $this->requests->countDecisionEmailsToSendForYears([$this->yearId]));

        // A later change of decision supersedes it, as any e-mail of the old one.
        $this->requests->updateStatus($id, 'refused', $now, 'other');
        $this->assertSame([], $this->decisionEmails->communicatedElsewhereByRequest([$id]));
        $this->assertSame(DecisionEmail::STATE_COMMUNICATED_ELSEWHERE, $this->decisionEmails->findById($row->id)?->state);
    }

    public function testALiveRequestOfTheSameChildIsFoundOnlyInItsYearAndWhileLive(): void
    {
        $id = $this->service->write($this->request('pending'));

        $this->assertTrue($this->requests->hasLiveRequestForChild($this->yearId, 'MARTIN', 'emma', '2019-05-06'));
        $this->assertFalse($this->requests->hasLiveRequestForChild($this->yearId, 'Martin', 'Emma', '2019-05-07'));
        $this->assertFalse($this->requests->hasLiveRequestForChild($this->yearId + 1, 'Martin', 'Emma', '2019-05-06'));

        $this->requests->updateStatus($id, 'withdrawn', new \DateTimeImmutable());
        $this->assertFalse($this->requests->hasLiveRequestForChild($this->yearId, 'Martin', 'Emma', '2019-05-06'));
    }

    public function testAnImportedSecondaryAddressHasNoTokenAndNoSendDate(): void
    {
        $id = $this->service->write($this->request('pending'));

        $rows = $this->secondaryEmails->findByRequest($id);
        $this->assertCount(1, $rows);
        $this->assertSame('valid', $rows[0]->status);
        $this->assertNull($rows[0]->confirmationTokenHash);
        $this->assertNull($rows[0]->lastConfirmationSentAt);

        $this->expectException(\InvalidArgumentException::class);
        $this->secondaryEmails->createImported($id, 'autre@example.org', 'inactive');
    }
}
