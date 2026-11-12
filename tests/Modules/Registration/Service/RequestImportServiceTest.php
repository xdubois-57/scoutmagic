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
use Modules\Registration\Service\DecisionEmailPanel;
use Modules\Registration\Service\RequestImportService;
use Modules\Registration\Service\RequestStatusService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Registration\RegistrationTestHelper;

/**
 * The import write contract of the registration module (chantier #1142,
 * lot H): a request of an old site is filed with its status, its links and
 * its history, through the module's own repositories, and nothing is sent.
 *
 * « Nothing is sent » is held three ways: the service is not even built
 * with a mail service, so it cannot reach one; no decision e-mail is left
 * to send (nothing scheduled, nothing due, nothing in play); no secondary
 * address carries a confirmation token or a « sent » date.
 */
#[Group('database')]
class RequestImportServiceTest extends TestCase
{
    private \PDO $pdo;
    private RegistrationRequestRepository $requests;
    private DecisionEmailRepository $decisionEmails;
    private RegistrationSecondaryEmailRepository $secondaryEmails;
    private RequestImportService $service;
    private int $yearId;
    private int $sectionId;
    private int $feeCategoryId;
    private int $siblingId;
    private int $memberId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RegistrationTestHelper::createTables($this->pdo);
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
        $branch = RegistrationTestHelper::insertAgeBranch($this->pdo, 'LOUV', 'Louveteaux', 2);
        $this->pdo->prepare('INSERT INTO sections (age_branch_id, desk_code, name) VALUES (?, ?, ?)')
            ->execute([$branch, 'LOUV-A', 'Louveteaux A']);
        $this->sectionId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO fee_categories (desk_code, label) VALUES ('T1', 'Tarif 1')");
        $this->feeCategoryId = (int) $this->pdo->lastInsertId();
        $this->siblingId = RegistrationTestHelper::insertMember($this->pdo, '1001');
        $this->memberId = RegistrationTestHelper::insertMember($this->pdo, '1002');
    }

    /**
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $more ImportedRequest's other arguments, by name
     */
    private function request(string $status, array $fields = [], array $more = []): ImportedRequest
    {
        return new ImportedRequest(...array_merge([
            'scoutYearId' => $this->yearId,
            'receivedAt' => new \DateTimeImmutable('2026-10-12 19:04:00'),
            'status' => $status,
            'fields' => array_replace([
                'parent_name' => 'Paul Martin', 'child_last_name' => 'Martin', 'child_first_name' => 'Emma',
                'gender' => 'F', 'birth_date' => '2019-05-06', 'street' => 'Rue Haute', 'number' => '3',
                'postal_code' => '5000', 'city' => 'Namur', 'email' => 'paul@example.org', 'phone1' => '0471111111',
            ], $fields),
        ], $more));
    }

    public function testAPendingRequestIsFiledWithItsHistoryAndLinks(): void
    {
        $id = $this->service->write($this->request('pending', ['remarks' => '  Allergie  '], [
            'desiredSectionId' => $this->sectionId,
            'intendedSectionId' => $this->sectionId,
            'feeCategoryId' => $this->feeCategoryId,
            'internalNotes' => "Première\r\nnote",
            'siblingMemberIds' => [$this->siblingId, $this->siblingId],
        ]));

        $request = $this->requests->findById($id) ?? $this->fail('Not filed.');
        $this->assertSame('pending', $request->status);
        $this->assertSame('2026-10-12 19:04:00', $request->receivedAt->format('Y-m-d H:i:s'));
        $this->assertSame('Emma', $request->childFirstName);
        $this->assertSame('Allergie', $request->remarks);
        $this->assertSame($this->sectionId, $request->desiredSectionId);
        $this->assertSame($this->sectionId, $request->intendedSectionId);
        $this->assertSame($this->feeCategoryId, $request->feeCategoryId);
        $this->assertSame("Première\nnote", $request->internalNotes);
        $this->assertSame([$this->siblingId], $this->requests->findSiblingMemberIds($id));
        $this->assertNull($request->finalAt);
        // A native token exists — the request has a tracking link like any
        // other — and nothing has sent it.
        $this->assertNotNull($this->requests->trackingLinkOf($id));
        $this->assertSame([], $this->decisionEmails->findForRequest($id));
    }

    public function testADecisionOfTheOldSiteIsCommunicatedElsewhereAndVisibleAtOnce(): void
    {
        $decidedAt = new \DateTimeImmutable('2026-11-03 10:00:00');
        $accepted = $this->service->write($this->request('accepted', [], ['decidedAt' => $decidedAt]));
        $refused = $this->service->write($this->request('refused', ['child_first_name' => 'Lina'], [
            'refusalReason' => 'no_room',
            'decidedAt' => $decidedAt,
        ]));
        $encoded = $this->service->write($this->request('encoded', ['child_first_name' => 'Zoé'], [
            'linkedMemberId' => $this->memberId,
        ]));

        $status = new RequestStatusService($this->requests, new JournalService(new JournalRepository($this->pdo)));
        foreach ([$accepted => 'accepted', $refused => 'refused', $encoded => 'encoded'] as $id => $expected) {
            $request = $this->requests->findById($id) ?? $this->fail('Not filed.');
            $this->assertSame($expected, $request->status);
            $this->assertSame($expected, $status->visibleStatus($request), 'The family must see the decision.');
            $this->assertNull($request->decisionEmailSentAt, 'No e-mail of this site went out.');

            $rows = $this->decisionEmails->findForRequest($id);
            $this->assertCount(1, $rows);
            $this->assertSame(DecisionEmail::STATE_COMMUNICATED_ELSEWHERE, $rows[0]->state);
            $this->assertNull($rows[0]->sentAt);
            $this->assertNull($rows[0]->attemptedAt);
            $this->assertNull($rows[0]->dueAt);
            $this->assertNull($this->decisionEmails->findActiveForRequest($id));

            $panel = DecisionEmailPanel::build($request, $rows, new \DateTimeImmutable());
            $this->assertSame(DecisionEmailPanel::STATE_COMMUNICATED_ELSEWHERE, $panel?->state);
            $this->assertSame([], $panel->actions, 'No e-mail is owed, so none is offered.');
        }

        $refusedRequest = $this->requests->findById($refused) ?? $this->fail('Not filed.');
        $this->assertSame('no_room', $refusedRequest->refusalReason);
        $this->assertSame('2026-11-03 10:00:00', $refusedRequest->finalAt?->format('Y-m-d H:i:s'));
        $this->assertSame($this->memberId, $this->requests->findById($encoded)?->linkedMemberId);

        // Nothing for any sender to pick up, and nothing on the dashboard.
        $this->assertSame([], $this->decisionEmails->findDue(new \DateTimeImmutable('+10 years'), 50));
        $this->assertSame(0, $this->decisionEmails->countScheduled());
        $this->assertSame(0, $this->requests->countDecisionEmailsToSendForYears([$this->yearId]));
    }

    public function testAChangeOfDecisionAfterTheImportFollowsTheNormalPath(): void
    {
        $id = $this->service->write($this->request('accepted'));
        $this->requests->updateStatus($id, 'refused', new \DateTimeImmutable(), 'other');

        $request = $this->requests->findById($id) ?? $this->fail('Not filed.');
        $status = new RequestStatusService($this->requests, new JournalService(new JournalRepository($this->pdo)));
        $this->assertFalse($request->decisionCommunicatedElsewhere);
        $this->assertSame('pending', $status->visibleStatus($request), 'The new refusal waits for its own e-mail.');
        $this->assertSame(1, $this->requests->countDecisionEmailsToSendForYears([$this->yearId]));
    }

    public function testSecondaryAddressesKeepTheirStateAndGetNoConfirmation(): void
    {
        $id = $this->service->write($this->request('pending', [], ['secondaryEmails' => [
            new ImportedSecondaryEmail('mamie@example.org', 'valid'),
            new ImportedSecondaryEmail('oncle@example.org', 'pending'),
            new ImportedSecondaryEmail('pas-une-adresse', 'valid'),
            new ImportedSecondaryEmail('cousin@example.org', 'inactive'),
            new ImportedSecondaryEmail('PAUL@example.org', 'valid'),
            new ImportedSecondaryEmail('mamie@example.org', 'pending'),
        ]]));

        $rows = $this->secondaryEmails->findByRequest($id);
        $kept = array_map(static fn($row): array => [$row->email, $row->status], $rows);
        sort($kept);
        $this->assertSame([['mamie@example.org', 'valid'], ['oncle@example.org', 'pending']], $kept);
        foreach ($rows as $row) {
            $this->assertNull($row->confirmationTokenHash);
            $this->assertNull($row->lastConfirmationSentAt);
        }
    }

    public function testAnIncompleteRequestIsRefusedWithTheFieldItMisses(): void
    {
        $this->assertSame(
            ['Champ obligatoire manquant : téléphone'],
            $this->service->problems($this->request('pending', ['phone1' => '  ']))
        );
        $this->assertSame(
            ['Valeur non reconnue : code postal'],
            $this->service->problems($this->request('pending', ['postal_code' => 5000]))
        );
        $this->assertSame(['Statut non reconnu'], $this->service->problems($this->request('en_cours')));
        $this->assertSame(
            ['Motif de refus non reconnu'],
            $this->service->problems($this->request('refused', [], ['refusalReason' => 'peut-être']))
        );
        $this->assertSame(
            ['Demande encodée sans membre Desk reconnu'],
            $this->service->problems($this->request('encoded'))
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->service->write($this->request('pending', ['birth_date' => '']));
    }

    public function testAMemberAlreadyLinkedToAnotherRequestCannotBeLinkedAgain(): void
    {
        $this->service->write($this->request('encoded', [], ['linkedMemberId' => $this->memberId]));

        $this->assertSame(
            ['Membre Desk déjà lié à une autre demande'],
            $this->service->problems(
                $this->request('encoded', ['child_first_name' => 'Autre'], ['linkedMemberId' => $this->memberId])
            )
        );
    }

    public function testAPossibleDuplicateIsAnnouncedNeverRefused(): void
    {
        $this->assertFalse($this->service->looksLikeExistingRequest($this->request('pending')));
        $this->service->write($this->request('pending'));

        $this->assertTrue(
            $this->service->looksLikeExistingRequest($this->request('pending', ['child_first_name' => ' emma ']))
        );
        $this->assertSame([], $this->service->problems($this->request('pending')));
    }

    public function testTheJournalSaysAnIdAndAStatusAndNoName(): void
    {
        $id = $this->service->write($this->request('pending'));

        $row = $this->pdo->query(
            "SELECT description, context FROM event_log WHERE event_type = 'registration_request_imported'"
        )->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($row);
        $this->assertSame(['request_id' => $id, 'status' => 'pending'], json_decode((string) $row['context'], true));
        $everything = (string) json_encode($this->pdo->query('SELECT * FROM event_log')->fetchAll(\PDO::FETCH_ASSOC));
        foreach (['Emma', 'Martin', 'paul@example.org', 'Namur'] as $personal) {
            $this->assertStringNotContainsString($personal, $everything);
        }
    }
}
