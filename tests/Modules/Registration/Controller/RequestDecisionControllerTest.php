<?php

declare(strict_types=1);

namespace Tests\Modules\Registration\Controller;

use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Http\FlashMessage;
use Core\Http\Request;
use Core\Http\Response;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\CsrfGuard;
use Core\Security\EncryptionService;
use Core\View\EditableContentRepository;
use Core\View\EditableContentService;
use Modules\Registration\Controller\RequestDecisionController;
use Modules\Registration\Repository\RegistrationRequestRepository;
use Modules\Registration\Repository\SlotCapacityRepository;
use Modules\Registration\Service\RequestEmailService;
use Modules\Registration\Service\RequestStatusService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Registration\RegistrationTestHelper;
use Tests\TestTwig;

/**
 * POST /config/inscriptions/demandes/{id}/decision — what the decision
 * dialog of the « Demandes » list posts (issue #843, IT-04), through the
 * real controller and service: accept, refuse with its reason, a refusal
 * without one refused, the note saved, the way back to the list with its
 * filters, and a confirmation that says no e-mail left. Its RBAC boundary
 * is RegistrationRbacTest's.
 */
#[Group('database')]
class RequestDecisionControllerTest extends TestCase
{
    private \PDO $pdo;
    private RegistrationRequestRepository $requests;
    private EditableContentService $content;
    private RequestDecisionController $controller;
    private int $pastYearId;
    private int $targetYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RegistrationTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $settings = new SettingService(new SettingRepository($this->pdo));
        RegistrationTestHelper::registerManifestSettings($settings);
        $yearSettings = [
            ScoutYearResolver::SETTING_PUBLIC_YEAR => 'Public',
            ScoutYearResolver::SETTING_STAFF_YEAR => 'Staff',
        ];
        foreach ($yearSettings as $key => $label) {
            $settings->register($key, '0', 'number', $label, 'desc', null, '^[0-9]+$', null, false);
        }
        $years = new ScoutYearService($this->pdo);
        $this->pastYearId = $years->ensureYear('2025-2026');
        $currentYearId = $years->ensureYear('2026-2027');
        $this->targetYearId = $years->ensureYear('2027-2028');
        $settings->setInternal(ScoutYearResolver::SETTING_PUBLIC_YEAR, (string) $currentYearId);

        $baladins = RegistrationTestHelper::insertAgeBranch($this->pdo, 'BALA', 'Baladins', 10);
        (new SlotCapacityRepository($this->pdo))->upsert($baladins, 2, 10);

        $this->requests = new RegistrationRequestRepository($this->pdo, $encryption);
        $journal = new JournalService(new JournalRepository($this->pdo));
        $this->content = new EditableContentService(new EditableContentRepository($this->pdo));
        $decisions = RegistrationTestHelper::decisionService(
            $this->pdo,
            $encryption,
            $settings,
            new RequestStatusService($this->requests, $journal),
            new RequestEmailService(
                $this->requests,
                $this->createStub(MailService::class),
                $this->content,
                $journal,
                'https://example.com',
                'Unité Test',
                new \Modules\Registration\Service\DecisionEmailService(
                    new \Modules\Registration\Repository\DecisionEmailRepository($this->pdo),
                    $journal
                )
            )
        );
        $this->controller = new RequestDecisionController(
            TestTwig::create(['registration']),
            $this->requests,
            $decisions
        );

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
    }

    private function file(?int $scoutYearId = null): int
    {
        return $this->requests->create($scoutYearId ?? $this->targetYearId, [
            'parent_name' => 'Marie Dupont', 'child_last_name' => 'Dupont', 'child_first_name' => 'Léa',
            'gender' => 'F', 'birth_date' => '2020-06-01', 'street' => 'S', 'number' => '1',
            'postal_code' => '1000', 'city' => 'V', 'email' => 'marie@example.com',
            'phone1' => '000', 'phone2' => null, 'remarks' => null,
        ], null, [])['id'];
    }

    /**
     * @param array<string, string> $body
     */
    private function post(int $id, array $body, ?string $token = null): Response
    {
        return $this->controller->decide(
            new Request(
                'POST',
                "/config/inscriptions/demandes/{$id}/decision",
                [],
                $body + ['_csrf_token' => $token ?? CsrfGuard::generateToken()],
                [],
                []
            ),
            ['id' => (string) $id]
        );
    }

    public function testAcceptingSavesTheNoteAndGoesBackToTheList(): void
    {
        $id = $this->file();

        $response = $this->post($id, ['decision' => 'accept', 'internal_notes' => 'Fratrie confirmée.']);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(
            '/config/inscriptions/demandes?year=' . $this->targetYearId,
            $response->getHeaders()['Location'] ?? null
        );
        $request = $this->requests->findById($id);
        $this->assertSame('accepted', $request->status);
        $this->assertSame('Fratrie confirmée.', $request->internalNotes);
        $this->assertSame(
            [
                'type' => 'success',
                'message' => "Demande acceptée. Aucun e-mail n'est parti : le gabarit d'acceptation n'est pas encore "
                    . "rédigé. La famille voit « en attente » jusqu'à son envoi.",
            ],
            FlashMessage::get()
        );
    }

    public function testRefusingWithItsReasonSaysNoEmailLeft(): void
    {
        $id = $this->file();
        $this->content->set('registration_email_refused_body', '<p>Désolés</p>', 'rich_text', 1);

        $this->post($id, ['decision' => 'refuse', 'refusal_reason' => 'no_room', 'internal_notes' => '']);

        $request = $this->requests->findById($id);
        $this->assertSame('refused', $request->status);
        $this->assertSame('no_room', $request->refusalReason);
        $this->assertSame(
            [
                'type' => 'success',
                'message' => "Demande refusée (Pas de place). Aucun e-mail n'est parti : envoyez-le depuis la fiche "
                    . 'de la demande. La famille voit « en attente » jusque-là.',
            ],
            FlashMessage::get()
        );
    }

    /**
     * @return array<string, array{array<string, string>}>
     */
    public static function refusalWithoutAValidReasonProvider(): array
    {
        return [
            'no reason' => [['decision' => 'refuse']],
            'empty reason' => [['decision' => 'refuse', 'refusal_reason' => '']],
            'blank reason' => [['decision' => 'refuse', 'refusal_reason' => '   ']],
            'unknown reason' => [['decision' => 'refuse', 'refusal_reason' => 'complet']],
        ];
    }

    /**
     * @param array<string, string> $body
     */
    #[DataProvider('refusalWithoutAValidReasonProvider')]
    public function testARefusalWithoutAValidReasonChangesNothing(array $body): void
    {
        $id = $this->file();

        $response = $this->post($id, $body + ['internal_notes' => 'Ne pas garder']);

        $this->assertSame(302, $response->getStatusCode());
        $request = $this->requests->findById($id);
        $this->assertSame('pending', $request->status);
        $this->assertNull($request->refusalReason);
        $this->assertNull($request->internalNotes);
        $this->assertSame(
            ['type' => 'error', 'message' => RequestStatusService::MESSAGE_REFUSAL_REASON_REQUIRED],
            FlashMessage::get()
        );
    }

    /**
     * The dialog posts the note it displayed beside the one it sends: left
     * untouched, a note completed on the fiche since the list was loaded
     * is kept.
     */
    public function testTheNoteTheDialogShowedTellsAnUntouchedNoteFromAnEditedOne(): void
    {
        $id = $this->file();
        $this->requests->updateInternalNotes($id, 'Complétée sur la fiche');

        $this->post($id, ['decision' => 'accept', 'internal_notes' => 'Avant', 'internal_notes_shown' => 'Avant']);

        $this->assertSame('accepted', $this->requests->findById($id)->status);
        $this->assertSame('Complétée sur la fiche', $this->requests->findById($id)->internalNotes);
    }

    public function testNoDecisionIsTakenWithoutAValidToken(): void
    {
        $id = $this->file();

        $response = $this->post($id, ['decision' => 'accept', 'internal_notes' => 'x'], 'invalid');

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(
            '/config/inscriptions/demandes?year=' . $this->targetYearId,
            $response->getHeaders()['Location'] ?? null
        );
        $this->assertSame('pending', $this->requests->findById($id)->status);
        $this->assertNull($this->requests->findById($id)->internalNotes);
    }

    public function testARequestOfAPastYearIsNotDecided(): void
    {
        $id = $this->file($this->pastYearId);

        $response = $this->post($id, ['decision' => 'accept']);

        $this->assertSame('pending', $this->requests->findById($id)->status);
        $this->assertSame(
            '/config/inscriptions/demandes?year=' . $this->pastYearId,
            $response->getHeaders()['Location'] ?? null
        );
        $this->assertSame('error', FlashMessage::get()['type'] ?? null);
    }

    public function testAnUnknownRequestIsNotFound(): void
    {
        $this->assertSame(404, $this->post(4242, ['decision' => 'accept'])->getStatusCode());
        $this->assertSame(404, $this->post(0, ['decision' => 'accept'])->getStatusCode());
    }

    /**
     * Back to the list as it was filtered — and only with a filter the list
     * understands, encoded.
     */
    public function testTheWayBackKeepsTheListsFilters(): void
    {
        $id = $this->file();
        $other = $this->file();

        $kept = $this->post($id, ['decision' => 'accept', 'list_status' => 'pending', 'list_q' => ' Léa & co ']);
        $dropped = $this->post($other, ['decision' => 'accept', 'list_status' => 'everything', 'list_q' => '']);

        $this->assertSame(
            '/config/inscriptions/demandes?year=' . $this->targetYearId . '&status=pending&q=L%C3%A9a+%26+co',
            $kept->getHeaders()['Location'] ?? null
        );
        $this->assertSame(
            '/config/inscriptions/demandes?year=' . $this->targetYearId,
            $dropped->getHeaders()['Location'] ?? null
        );
    }
}
