<?php

declare(strict_types=1);

namespace Tests\Modules\MassMail\Controller;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Http\Controller\AbstractController;
use Core\Http\Request;
use Core\Journal\JournalService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\EncryptionService;
use Modules\MassMail\Controller\ContactGroupController;
use Modules\MassMail\Repository\ContactRepository;
use Modules\MassMail\Repository\ListBlock;
use Modules\MassMail\Repository\ListBlockRepository;
use Modules\MassMail\Repository\MailingListRepository;
use Modules\MassMail\Repository\SuppressedAddressRepository;
use Modules\MassMail\Service\ContactGroupImportService;
use Modules\MassMail\Service\ListBlockService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\MassMail\MassMailTestHelper;
use Tests\RequestWithInput;
use Twig\Environment;

/**
 * The contact group dialog's routes, and above all the guarantees that
 * live in the controller rather than in the service:
 *
 * - **the uploaded file is deleted the moment the analysis returns,
 *   success or failure** — the `finally` (SECURITY.md §5);
 * - **the analysis writes nothing**;
 * - **a save without its `contacts` array, or with an entry that is not
 *   an object, wipes nothing** — while the legitimate empty replacement
 *   still goes through.
 *
 * Ported from the removed `ListAddressImportRoutesTest` onto the contact
 * group routes (CHANTIER-listes-de-diffusion-blocs.md, lot C).
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class ContactGroupRoutesTest extends TestCase
{
    private \PDO $pdo;
    private ContactGroupController $controller;
    private ContactRepository $contacts;
    private ListBlockRepository $blocks;
    private SettingService $settings;
    private int $listId;
    private int $blockId;
    /** @var string[] */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        MassMailTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->contacts = new ContactRepository($this->pdo, $encryption);
        $this->blocks = new ListBlockRepository($this->pdo);
        $listRepository = new MailingListRepository($this->pdo);
        $this->settings = new SettingService(new SettingRepository($this->pdo));
        $blockService = new ListBlockService(
            $this->blocks,
            $this->contacts,
            $listRepository,
            $this->createStub(JournalService::class)
        );

        $this->controller = new ContactGroupController(
            $this->createStub(Environment::class),
            $blockService,
            new ContactGroupImportService(
                $this->blocks,
                $this->contacts,
                $blockService,
                $this->settings,
                $this->createStub(JournalService::class),
                new SuppressedAddressRepository($this->pdo)
            )
        );

        $this->listId = $listRepository->create('Ma liste', 'Description.', null);
        $this->blockId = $this->blocks->create($this->listId, ListBlock::KIND_CONTACT_GROUP, 'Partenaires', null);

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        AuthSession::login(0, 'cu@test.com', 'admin');
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
        $_FILES = [];
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }
    }

    // --- Reading --------------------------------------------------------

    public function testTheContactsOfAGroupArriveInOneCallWithTheGroupsCounts(): void
    {
        $this->contacts->create($this->blockId, 'Commune', 'jeunesse@wavre.be');
        $this->contacts->create($this->blockId, null, 'cure@paroisse.be');
        $this->contacts->unsubscribeEverywhere('cure@paroisse.be');
        $this->blocks->markFileReplaced($this->blockId, new \DateTimeImmutable('2026-09-12 10:00:00'));

        $payload = $this->payload($this->controller->contacts($this->get(), ['id' => (string) $this->blockId]));

        $this->assertTrue($payload['success']);
        $this->assertSame('Partenaires', $payload['group']['name']);
        $this->assertSame($this->listId, $payload['group']['list_id']);
        $this->assertSame(2, $payload['group']['count']);
        $this->assertSame(1, $payload['group']['unsubscribed']);
        $this->assertSame('12 septembre 2026', $payload['group']['file_replaced_label']);
        $this->assertSame(['cure@paroisse.be', 'jeunesse@wavre.be'], array_column($payload['contacts'], 'email'));
        $this->assertNotNull($payload['contacts'][0]['unsubscribed_at']);
    }

    public function testTheContactsOfAnUnknownGroupAreA404(): void
    {
        $response = $this->controller->contacts($this->get(), ['id' => '9999']);

        $this->assertSame(404, $response->getStatusCode());
    }

    /** To these routes a member group is no contact group at all. */
    public function testAMemberGroupIsNotAContactGroup(): void
    {
        $memberGroup = $this->blocks->create($this->listId, ListBlock::KIND_MEMBER_GROUP, null, null);

        $this->assertSame(
            404,
            $this->controller->contacts($this->get(), ['id' => (string) $memberGroup])->getStatusCode()
        );
        $this->assertSame(
            404,
            $this->controller->export($this->get(), ['id' => (string) $memberGroup])->getStatusCode()
        );
    }

    public function testTheExportIsAnXlsxDownloadAndNothingIsStored(): void
    {
        $this->contacts->create($this->blockId, 'Commune', 'jeunesse@wavre.be');

        $response = $this->controller->export($this->get(), ['id' => (string) $this->blockId]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->getHeaders()['Content-Type'] ?? null
        );
        $this->assertStringContainsString(
            'groupe-de-contacts-' . $this->blockId . '.xlsx',
            (string) ($response->getHeaders()['Content-Disposition'] ?? '')
        );
        // Nothing registered as a file of the site: nothing for
        // FileAccessGuard to guard because nothing was stored.
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM files')->fetchColumn());
    }

    public function testTheExportOfAnUnknownGroupIsA404(): void
    {
        $response = $this->controller->export($this->get(), ['id' => '9999']);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testTheEmptyTemplateIsAnXlsxDownload(): void
    {
        $response = $this->controller->template($this->get(), []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString(
            'modele-groupe-de-contacts.xlsx',
            (string) ($response->getHeaders()['Content-Disposition'] ?? '')
        );
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM files')->fetchColumn());
    }

    // --- The analysis ---------------------------------------------------

    public function testTheUploadedFileIsDeletedAfterASuccessfulAnalysis(): void
    {
        $upload = $this->upload([['Nom', 'Adresse'], ['Commune', 'jeunesse@wavre.be']]);

        $response = $this->controller->analyseExisting($this->uploadRequest(), ['id' => (string) $this->blockId]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFileDoesNotExist($upload, 'the uploaded file must be gone the moment the analysis returns');
    }

    public function testTheUploadedFileIsDeletedAfterTheAnalysisOfANewGroup(): void
    {
        $upload = $this->upload([['Nom', 'Adresse'], ['Commune', 'jeunesse@wavre.be']]);

        $response = $this->controller->analyseNew($this->uploadRequest(), ['id' => (string) $this->listId]);
        $payload = $this->payload($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFileDoesNotExist($upload);
        // Nothing exists yet, so every line is an addition.
        $this->assertSame(
            ['added' => 1, 'unchanged' => 0, 'removed' => 0, 'kept_unsubscribed' => 0],
            $payload['summary']
        );
        $this->assertCount(1, $this->blocks->findForList($this->listId), 'an analysis creates no group');
    }

    /** The half a `finally` exists for: the analysis threw, and the file is gone all the same. */
    public function testTheUploadedFileIsDeletedWhenTheAnalysisRefusesTheFile(): void
    {
        $upload = $this->upload([['Nom', 'Téléphone'], ['Commune', '010 22 33 44']]);

        $response = $this->controller->analyseExisting($this->uploadRequest(), ['id' => (string) $this->blockId]);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertNotEmpty($this->payload($response)['errors']);
        $this->assertFileDoesNotExist($upload, 'a refused file must be deleted too');
    }

    public function testTheUploadedFileIsDeletedWhenItIsNotASpreadsheetAtAll(): void
    {
        $upload = (string) tempnam(sys_get_temp_dir(), 'sm-upload-') . '.xlsx';
        $this->tempFiles[] = $upload;
        file_put_contents($upload, 'ceci n\'est pas un classeur');
        $_FILES['file'] = [
            'name' => 'contacts.xlsx',
            'tmp_name' => $upload,
            'error' => \UPLOAD_ERR_OK,
            'size' => filesize($upload),
            'type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];

        $response = $this->controller->analyseNew($this->uploadRequest(), ['id' => (string) $this->listId]);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertFileDoesNotExist($upload);
    }

    public function testTheAnalysisAnswersTheCountsAndWritesNothing(): void
    {
        $this->contacts->create($this->blockId, 'Part', 'part@test.be');
        $this->upload([['Nom', 'Adresse'], ['Nouvelle', 'nouvelle@test.be'], ['Double', 'NOUVELLE@test.be']]);

        $payload = $this->payload(
            $this->controller->analyseExisting($this->uploadRequest(), ['id' => (string) $this->blockId])
        );

        $this->assertTrue($payload['success']);
        $this->assertSame(
            ['added' => 1, 'unchanged' => 0, 'removed' => 1, 'kept_unsubscribed' => 0],
            $payload['summary']
        );
        $this->assertSame([['name' => 'Nouvelle', 'email' => 'nouvelle@test.be']], $payload['contacts']);
        $this->assertSame(1, $payload['duplicates']);
        $this->assertSame([], $payload['errors']);
        // Still exactly what it was: an analysis writes nothing.
        $this->assertSame(['part@test.be'], array_column($this->contacts->findForBlock($this->blockId), 'email'));
        $this->assertNull($this->blocks->findById($this->blockId)?->fileReplacedAt);
    }

    public function testAnUploadWithoutACsrfTokenIsRefusedBeforeTheFileIsEvenLookedAt(): void
    {
        $upload = $this->upload([['Nom', 'Adresse'], ['Commune', 'jeunesse@wavre.be']]);
        $refused = new Request('POST', '/x', [], ['_csrf_token' => 'invalide'], [], []);

        foreach ([
            $this->controller->analyseExisting($refused, ['id' => (string) $this->blockId]),
            $this->controller->analyseNew($refused, ['id' => (string) $this->listId]),
        ] as $response) {
            $this->assertSame(403, $response->getStatusCode());
            $this->assertSame(AbstractController::SESSION_EXPIRED_MESSAGE, $this->payload($response)['error']);
        }
        // Refused before the upload was touched — so it is still there for
        // PHP's own end-of-request cleanup.
        $this->assertFileExists($upload);
    }

    public function testAFileThatIsNotAnXlsxIsRefusedOnItsName(): void
    {
        $_FILES['file'] = [
            'name' => 'contacts.csv',
            'tmp_name' => '/tmp/whatever',
            'error' => \UPLOAD_ERR_OK,
            'size' => 10,
            'type' => 'text/csv',
        ];

        $response = $this->controller->analyseNew($this->uploadRequest(), ['id' => (string) $this->listId]);

        $this->assertSame(422, $response->getStatusCode());
    }

    public function testAFileOverFiveMegabytesIsRefused(): void
    {
        $_FILES['file'] = [
            'name' => 'contacts.xlsx',
            'tmp_name' => '/tmp/whatever',
            'error' => \UPLOAD_ERR_OK,
            'size' => 5 * 1024 * 1024 + 1,
            'type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];

        $response = $this->controller->analyseNew($this->uploadRequest(), ['id' => (string) $this->listId]);

        $this->assertSame(422, $response->getStatusCode());
    }

    public function testAnUploadWithNoFileAtAllIsRefused(): void
    {
        $response = $this->controller->analyseNew($this->uploadRequest(), ['id' => (string) $this->listId]);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testAnAnalysisAgainstAnUnknownListOrGroupIsA404(): void
    {
        $upload = $this->upload([['Nom', 'Adresse'], ['Commune', 'jeunesse@wavre.be']]);

        $this->assertSame(404, $this->controller->analyseNew($this->uploadRequest(), ['id' => '9999'])->getStatusCode());
        $this->assertSame(
            404,
            $this->controller->analyseExisting($this->uploadRequest(), ['id' => '9999'])->getStatusCode()
        );
        $this->assertFileExists($upload, 'refused before the file was read');
    }

    // --- Creating -------------------------------------------------------

    public function testCreatingWritesTheGroupAndItsContactsTogether(): void
    {
        $response = $this->controller->create(
            $this->json([
                'name' => 'Commune',
                'contacts' => [['name' => 'Jeunesse', 'email' => 'jeunesse@wavre.be']],
                '_csrf_token' => CsrfGuard::generateToken(),
            ]),
            ['id' => (string) $this->listId]
        );
        $payload = $this->payload($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($payload['success']);
        $this->assertSame('Commune', $payload['group']['name']);
        $this->assertSame(ListBlock::KIND_CONTACT_GROUP, $payload['group']['kind']);
        $this->assertSame(
            ['jeunesse@wavre.be'],
            array_column($this->contacts->findForBlock((int) $payload['group']['id']), 'email')
        );
    }

    public function testCreatingWithoutANameWritesNothing(): void
    {
        $response = $this->controller->create(
            $this->json([
                'name' => '  ',
                'contacts' => [['name' => 'Jeunesse', 'email' => 'jeunesse@wavre.be']],
                '_csrf_token' => CsrfGuard::generateToken(),
            ]),
            ['id' => (string) $this->listId]
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertCount(1, $this->blocks->findForList($this->listId));
    }

    public function testCreatingWithAnInvalidCsrfTokenWritesNothing(): void
    {
        $response = $this->controller->create(
            $this->json(['name' => 'Commune', 'contacts' => [], '_csrf_token' => 'invalide']),
            ['id' => (string) $this->listId]
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(AbstractController::SESSION_EXPIRED_MESSAGE, $this->payload($response)['error']);
        $this->assertCount(1, $this->blocks->findForList($this->listId));
    }

    public function testCreatingInAnUnknownListIsA404(): void
    {
        $response = $this->controller->create(
            $this->json(['name' => 'Commune', 'contacts' => [], '_csrf_token' => CsrfGuard::generateToken()]),
            ['id' => '9999']
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    /** A group is never created without its file — the array must be there. */
    public function testCreatingWithNoContactsFieldAtAllIsRefused(): void
    {
        $response = $this->controller->create(
            $this->json(['name' => 'Commune', '_csrf_token' => CsrfGuard::generateToken()]),
            ['id' => (string) $this->listId]
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertCount(1, $this->blocks->findForList($this->listId));
    }

    public function testCreatingWhoseEntriesAreNotObjectsIsRefused(): void
    {
        $response = $this->controller->create(
            $this->json([
                'name' => 'Commune',
                'contacts' => ['pas-une-adresse'],
                '_csrf_token' => CsrfGuard::generateToken(),
            ]),
            ['id' => (string) $this->listId]
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertCount(1, $this->blocks->findForList($this->listId));
    }

    /** The save re-validates what the analysis returned: it cannot trust an earlier request. */
    public function testCreatingWithAnAddressTheServiceRefusesLeavesNoGroupBehind(): void
    {
        $response = $this->controller->create(
            $this->json([
                'name' => 'Commune',
                'contacts' => [['name' => 'Cassé', 'email' => 'pas-une-adresse']],
                '_csrf_token' => CsrfGuard::generateToken(),
            ]),
            ['id' => (string) $this->listId]
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertCount(1, $this->blocks->findForList($this->listId));
    }

    public function testCreatingIsRefusedOverTheCap(): void
    {
        $this->settings->register(
            ContactGroupImportService::SETTING_MAX_CONTACTS,
            '1',
            'number',
            'Contacts par groupe (maximum)',
            'Plafond.',
            'mass_mail'
        );
        $this->settings->clearCache();

        $response = $this->controller->create(
            $this->json([
                'name' => 'Commune',
                'contacts' => [
                    ['name' => 'Un', 'email' => 'un@test.be'],
                    ['name' => 'Deux', 'email' => 'deux@test.be'],
                ],
                '_csrf_token' => CsrfGuard::generateToken(),
            ]),
            ['id' => (string) $this->listId]
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertCount(1, $this->blocks->findForList($this->listId));
    }

    /** A header-only file is a previewed, legitimate group of nobody. */
    public function testCreatingAnEmptyGroupIsAllowed(): void
    {
        $response = $this->controller->create(
            $this->json(['name' => 'Commune', 'contacts' => [], '_csrf_token' => CsrfGuard::generateToken()]),
            ['id' => (string) $this->listId]
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(2, $this->blocks->findForList($this->listId));
    }

    // --- Updating -------------------------------------------------------

    public function testUpdatingReplacesTheContactsAndRenamesTogether(): void
    {
        $this->contacts->create($this->blockId, 'Part', 'part@test.be');

        $response = $this->controller->update(
            $this->json([
                'name' => 'Partenaires de la fête',
                'contacts' => [['name' => 'Nouvelle', 'email' => 'nouvelle@test.be']],
                '_csrf_token' => CsrfGuard::generateToken(),
            ], 'PATCH'),
            ['id' => (string) $this->blockId]
        );
        $payload = $this->payload($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            ['added' => 1, 'unchanged' => 0, 'removed' => 1, 'kept_unsubscribed' => 0],
            $payload['summary']
        );
        $this->assertSame('Partenaires de la fête', $payload['group']['name']);
        $this->assertNotNull($payload['group']['file_replaced_at']);
        $this->assertSame(['nouvelle@test.be'], array_column($this->contacts->findForBlock($this->blockId), 'email'));
    }

    /** No `contacts` key: the name only, and the contacts are left alone. */
    public function testUpdatingTheNameOnlyLeavesTheContactsAlone(): void
    {
        $this->contacts->create($this->blockId, 'Reste', 'reste@test.be');

        $response = $this->controller->update(
            $this->json(['name' => 'Renommé', '_csrf_token' => CsrfGuard::generateToken()], 'PATCH'),
            ['id' => (string) $this->blockId]
        );
        $payload = $this->payload($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull($payload['summary']);
        $this->assertSame('Renommé', $this->blocks->findById($this->blockId)?->name);
        $this->assertSame(1, $this->contacts->countForBlock($this->blockId)['total']);
    }

    public function testUpdatingWithAnInvalidCsrfTokenWritesNothing(): void
    {
        $this->contacts->create($this->blockId, 'Part', 'part@test.be');

        $response = $this->controller->update(
            $this->json(['name' => 'Renommé', 'contacts' => [], '_csrf_token' => 'invalide'], 'PATCH'),
            ['id' => (string) $this->blockId]
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(AbstractController::SESSION_EXPIRED_MESSAGE, $this->payload($response)['error']);
        $this->assertSame('Partenaires', $this->blocks->findById($this->blockId)?->name);
        $this->assertSame(1, $this->contacts->countForBlock($this->blockId)['total']);
    }

    public function testUpdatingAnUnknownGroupIsA404(): void
    {
        $response = $this->controller->update(
            $this->json(['name' => 'Renommé', 'contacts' => [], '_csrf_token' => CsrfGuard::generateToken()], 'PATCH'),
            ['id' => '9999']
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    /**
     * A `contacts` key that is not an array is a garbled body, never
     * « replace by nothing » — and the name is not written either.
     */
    public function testUpdatingWithContactsThatAreNotAnArrayWipesNothing(): void
    {
        $this->contacts->create($this->blockId, 'Reste', 'reste@test.be');

        $response = $this->controller->update(
            $this->json(['name' => 'Renommé', 'contacts' => 'rien', '_csrf_token' => CsrfGuard::generateToken()], 'PATCH'),
            ['id' => (string) $this->blockId]
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('Partenaires', $this->blocks->findById($this->blockId)?->name);
        $this->assertSame(1, $this->contacts->countForBlock($this->blockId)['total']);
    }

    public function testUpdatingWhoseEntriesAreNotObjectsWipesNothing(): void
    {
        $this->contacts->create($this->blockId, 'Reste', 'reste@test.be');

        $response = $this->controller->update(
            $this->json([
                'name' => 'Renommé',
                // The same garbage, one nesting level flatter — dropped in
                // silence, it became « replace by nothing ».
                'contacts' => ['pas-une-adresse'],
                '_csrf_token' => CsrfGuard::generateToken(),
            ], 'PATCH'),
            ['id' => (string) $this->blockId]
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('Partenaires', $this->blocks->findById($this->blockId)?->name);
        $this->assertSame(1, $this->contacts->countForBlock($this->blockId)['total']);
    }

    public function testUpdatingWithAnAddressTheServiceRefusesLeavesTheGroupAlone(): void
    {
        $this->contacts->create($this->blockId, 'Reste', 'reste@test.be');

        $response = $this->controller->update(
            $this->json([
                'name' => 'Renommé',
                'contacts' => [['name' => 'Cassé', 'email' => 'pas-une-adresse']],
                '_csrf_token' => CsrfGuard::generateToken(),
            ], 'PATCH'),
            ['id' => (string) $this->blockId]
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('Partenaires', $this->blocks->findById($this->blockId)?->name);
        $this->assertSame(1, $this->contacts->countForBlock($this->blockId)['total']);
    }

    /** And the legitimate empty replacement still goes through. */
    public function testUpdatingToAnEmptyGroupIsStillAllowed(): void
    {
        $this->contacts->create($this->blockId, 'Part', 'part@test.be');

        $response = $this->controller->update(
            $this->json(['name' => 'Partenaires', 'contacts' => [], '_csrf_token' => CsrfGuard::generateToken()], 'PATCH'),
            ['id' => (string) $this->blockId]
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, $this->contacts->countForBlock($this->blockId)['total']);
    }

    // --- Helpers --------------------------------------------------------

    /**
     * Writes a real .xlsx and presents it in $_FILES the way PHP would.
     *
     * @param array<int, array<int, string>> $rows
     * @return string the temporary path the controller is expected to delete
     */
    private function upload(array $rows): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        foreach ($rows as $rowIndex => $row) {
            foreach ($row as $columnIndex => $value) {
                $sheet->setCellValue([$columnIndex + 1, $rowIndex + 1], $value);
            }
        }

        $path = (string) tempnam(sys_get_temp_dir(), 'sm-upload-') . '.xlsx';
        $this->tempFiles[] = $path;
        (new XlsxWriter($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $_FILES['file'] = [
            'name' => 'contacts.xlsx',
            'tmp_name' => $path,
            'error' => \UPLOAD_ERR_OK,
            'size' => (int) filesize($path),
            'type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];

        return $path;
    }

    private function uploadRequest(): Request
    {
        return new Request('POST', '/x', [], ['_csrf_token' => CsrfGuard::generateToken()], [], []);
    }

    private function get(): Request
    {
        return new Request('GET', '/x', [], [], [], []);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function json(array $body, string $method = 'POST'): Request
    {
        return new RequestWithInput($method, '/x', [], [], [], [], (string) json_encode($body));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(\Core\Http\Response $response): array
    {
        $payload = json_decode($response->getBody(), true);
        $this->assertIsArray($payload);

        return $payload;
    }
}
