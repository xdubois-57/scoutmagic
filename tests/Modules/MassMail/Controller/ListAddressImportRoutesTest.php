<?php

declare(strict_types=1);

namespace Tests\Modules\MassMail\Controller;

use Core\Badge\MemberBadgeRepository;
use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Http\Request;
use Core\Import\FunctionRepository;
use Core\Import\MemberYearRepository;
use Core\Journal\JournalService;
use Core\Member\SectionService;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\EncryptionService;
use Modules\MassMail\Controller\MailingListController;
use Modules\MassMail\Repository\ListAddressRepository;
use Modules\MassMail\Repository\MailingListRepository;
use Modules\MassMail\Repository\MemberResolutionRepository;
use Modules\MassMail\Service\ListAddressImportService;
use Modules\MassMail\Service\ListAddressService;
use Modules\MassMail\Service\MailingListService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\MassMail\MassMailTestHelper;
use Twig\Environment;

/**
 * The three routes of the Excel round trip, and above all the one
 * guarantee that lives in the controller rather than in the service:
 * **the uploaded file is deleted the moment the analysis returns, success
 * or failure** — the `finally` (SECURITY.md §5, the same rule as the Desk
 * CSV and the mail-merge audience).
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class ListAddressImportRoutesTest extends TestCase
{
    private \PDO $pdo;
    private MailingListController $controller;
    private ListAddressService $addressService;
    private ListAddressRepository $repository;
    private int $listId;
    /** @var string[] */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        MassMailTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->repository = new ListAddressRepository($this->pdo, $encryption);
        $listRepository = new MailingListRepository($this->pdo);
        $settings = new SettingService(new SettingRepository($this->pdo));
        $this->addressService = new ListAddressService(
            $this->repository,
            $listRepository,
            $settings,
            $this->createMock(JournalService::class)
        );

        $this->controller = new MailingListController(
            $this->createMock(Environment::class),
            new MailingListService(
                $listRepository,
                new MemberResolutionRepository($this->pdo, $encryption),
                new SectionService($this->connection(), $encryption, new MemberBadgeRepository($this->pdo)),
                new FunctionRepository($this->pdo),
                null,
                $this->repository
            ),
            new ScoutYearResolver(
                new ScoutYearService($this->pdo),
                $settings,
                new MemberYearRepository($this->pdo)
            ),
            $this->addressService,
            new ListAddressImportService(
                $this->repository,
                $this->addressService,
                $this->createMock(JournalService::class)
            )
        );

        $stmt = $this->pdo->prepare('INSERT INTO mass_mail_lists (name, description) VALUES (?, ?)');
        $stmt->execute(['Ma liste', 'Description.']);
        $this->listId = (int) $this->pdo->lastInsertId();

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

    private function connection(): Connection
    {
        return Connection::withPdo($this->pdo);
    }

    public function testTheExportIsAnXlsxDownloadAndNothingIsStored(): void
    {
        $this->addressService->add($this->listId, 'Commune', 'jeunesse@wavre.be');

        $response = $this->controller->exportAddresses(
            new Request('GET', '/x', [], [], [], []),
            ['id' => (string) $this->listId]
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->getHeaders()['Content-Type'] ?? null
        );
        $this->assertStringContainsString(
            'adresses-liste-' . $this->listId . '.xlsx',
            (string) ($response->getHeaders()['Content-Disposition'] ?? '')
        );
        // Nothing was registered as a file of the site: there is nothing
        // for FileAccessGuard to guard because nothing was stored.
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM files')->fetchColumn());
    }

    public function testTheExportOfAnUnknownListIsA404(): void
    {
        $response = $this->controller->exportAddresses(
            new Request('GET', '/x', [], [], [], []),
            ['id' => '9999']
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testTheUploadedFileIsDeletedAfterASuccessfulAnalysis(): void
    {
        $upload = $this->upload([['Nom', 'Adresse'], ['Commune', 'jeunesse@wavre.be']]);

        $response = $this->controller->importAddresses($this->uploadRequest(), ['id' => (string) $this->listId]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFileDoesNotExist($upload, 'the uploaded file must be gone the moment the analysis returns');
    }

    /**
     * The half a `finally` exists for: the analysis threw, and the file is
     * gone all the same.
     */
    public function testTheUploadedFileIsDeletedWhenTheAnalysisRefusesTheFile(): void
    {
        $upload = $this->upload([['Nom', 'Téléphone'], ['Commune', '010 22 33 44']]);

        $response = $this->controller->importAddresses($this->uploadRequest(), ['id' => (string) $this->listId]);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertFileDoesNotExist($upload, 'a refused file must be deleted too');
    }

    public function testTheUploadedFileIsDeletedWhenItIsNotASpreadsheetAtAll(): void
    {
        $upload = (string) tempnam(sys_get_temp_dir(), 'sm-upload-') . '.xlsx';
        $this->tempFiles[] = $upload;
        file_put_contents($upload, 'ceci n\'est pas un classeur');
        $_FILES['file'] = [
            'name' => 'adresses.xlsx',
            'tmp_name' => $upload,
            'error' => \UPLOAD_ERR_OK,
            'size' => filesize($upload),
            'type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];

        $response = $this->controller->importAddresses($this->uploadRequest(), ['id' => (string) $this->listId]);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertFileDoesNotExist($upload);
    }

    public function testTheAnalysisAnswersTheCountsAndWritesNothing(): void
    {
        $this->addressService->add($this->listId, 'Part', 'part@test.be');
        $this->upload([['Nom', 'Adresse'], ['Nouvelle', 'nouvelle@test.be']]);

        $response = $this->controller->importAddresses($this->uploadRequest(), ['id' => (string) $this->listId]);
        $payload = json_decode($response->getBody(), true);

        $this->assertIsArray($payload);
        $this->assertTrue($payload['success']);
        $this->assertSame(
            ['added' => 1, 'unchanged' => 0, 'removed' => 1, 'kept_unsubscribed' => 0],
            $payload['summary']
        );
        $this->assertSame([['name' => 'Nouvelle', 'email' => 'nouvelle@test.be']], $payload['addresses']);
        // Still exactly what it was: an analysis writes nothing.
        $this->assertSame(
            ['part@test.be'],
            array_column($this->repository->findForList($this->listId), 'email')
        );
    }

    public function testAnUploadWithoutACsrfTokenIsRefusedBeforeTheFileIsEvenLookedAt(): void
    {
        $upload = $this->upload([['Nom', 'Adresse'], ['Commune', 'jeunesse@wavre.be']]);

        $response = $this->controller->importAddresses(
            new Request('POST', '/x', [], ['_csrf_token' => 'invalide'], [], []),
            ['id' => (string) $this->listId]
        );

        $this->assertSame(400, $response->getStatusCode());
        // Refused before the upload was touched — so it is still there for
        // PHP's own end-of-request cleanup, which is what would happen to
        // any request that never reached a handler.
        $this->assertFileExists($upload);
    }

    public function testAFileThatIsNotAnXlsxIsRefusedOnItsName(): void
    {
        $_FILES['file'] = [
            'name' => 'adresses.csv',
            'tmp_name' => '/tmp/whatever',
            'error' => \UPLOAD_ERR_OK,
            'size' => 10,
            'type' => 'text/csv',
        ];

        $response = $this->controller->importAddresses($this->uploadRequest(), ['id' => (string) $this->listId]);

        $this->assertSame(422, $response->getStatusCode());
    }

    public function testAnUploadWithNoFileAtAllIsRefused(): void
    {
        $response = $this->controller->importAddresses($this->uploadRequest(), ['id' => (string) $this->listId]);

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testConfirmingReplacesTheListAndAnswersItsNewCounts(): void
    {
        $this->addressService->add($this->listId, 'Part', 'part@test.be');

        $response = $this->controller->confirmAddressImport(
            $this->jsonRequest([
                'addresses' => [['name' => 'Nouvelle', 'email' => 'nouvelle@test.be']],
                '_csrf_token' => CsrfGuard::generateToken(),
            ]),
            ['id' => (string) $this->listId]
        );

        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode($response->getBody(), true);
        $this->assertIsArray($payload);
        $this->assertSame(
            ['added' => 1, 'unchanged' => 0, 'removed' => 1, 'kept_unsubscribed' => 0],
            $payload['summary']
        );
        $this->assertSame(['total' => 1, 'unsubscribed' => 0], $payload['counts']);
        $this->assertSame(
            ['nouvelle@test.be'],
            array_column($this->repository->findForList($this->listId), 'email')
        );
    }

    public function testConfirmingWithAnInvalidCsrfTokenWritesNothing(): void
    {
        $this->addressService->add($this->listId, 'Part', 'part@test.be');

        $response = $this->controller->confirmAddressImport(
            $this->jsonRequest([
                'addresses' => [['name' => 'Nouvelle', 'email' => 'nouvelle@test.be']],
                '_csrf_token' => 'invalide',
            ]),
            ['id' => (string) $this->listId]
        );

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(1, $this->repository->countForList($this->listId)['total']);
    }

    public function testConfirmingAgainstAnUnknownListIsRefused(): void
    {
        $response = $this->controller->confirmAddressImport(
            $this->jsonRequest([
                'addresses' => [['name' => 'Nouvelle', 'email' => 'nouvelle@test.be']],
                '_csrf_token' => CsrfGuard::generateToken(),
            ]),
            ['id' => '9999']
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testConfirmingAnAddressTheServiceRefusesLeavesTheListAlone(): void
    {
        $this->addressService->add($this->listId, 'Reste', 'reste@test.be');

        $response = $this->controller->confirmAddressImport(
            $this->jsonRequest([
                'addresses' => [['name' => 'Cassé', 'email' => 'pas-une-adresse']],
                '_csrf_token' => CsrfGuard::generateToken(),
            ]),
            ['id' => (string) $this->listId]
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(1, $this->repository->countForList($this->listId)['total']);
    }

    /**
     * A replacement by NOTHING is legitimate — a chief may re-upload a
     * file with only its header row — so the guard cannot simply refuse
     * an empty list. What it refuses is the array not being THERE: a
     * truncated body or a hand-edited request would otherwise read as
     * « replace by nothing » and wipe the list with a 200, past the very
     * two-step confirmation this feature is built around.
     */
    public function testAConfirmationWithNoAddressesFieldAtAllWipesNothing(): void
    {
        $this->addressService->add($this->listId, 'Reste', 'reste@test.be');

        $response = $this->controller->confirmAddressImport(
            $this->jsonRequest(['_csrf_token' => CsrfGuard::generateToken()]),
            ['id' => (string) $this->listId]
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(1, $this->repository->countForList($this->listId)['total']);
    }

    public function testAConfirmationWhoseEntriesAreNotObjectsWipesNothing(): void
    {
        $this->addressService->add($this->listId, 'Reste', 'reste@test.be');

        $response = $this->controller->confirmAddressImport(
            $this->jsonRequest([
                // The same garbage as the refused case above, one nesting
                // level flatter — dropped in silence, it became « replace
                // by nothing ».
                'addresses' => ['pas-une-adresse'],
                '_csrf_token' => CsrfGuard::generateToken(),
            ]),
            ['id' => (string) $this->listId]
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(1, $this->repository->countForList($this->listId)['total']);
    }

    /** And the legitimate empty replacement still goes through. */
    public function testAConfirmationOfAnEmptyListIsStillAllowed(): void
    {
        $this->addressService->add($this->listId, 'Part', 'part@test.be');

        $response = $this->controller->confirmAddressImport(
            $this->jsonRequest([
                'addresses' => [],
                '_csrf_token' => CsrfGuard::generateToken(),
            ]),
            ['id' => (string) $this->listId]
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, $this->repository->countForList($this->listId)['total']);
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
            'name' => 'adresses.xlsx',
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

    /**
     * @param array<string, mixed> $body
     */
    private function jsonRequest(array $body): Request
    {
        $request = $this->getMockBuilder(Request::class)
            ->setConstructorArgs(['POST', '/x', [], [], [], []])
            ->onlyMethods(['getRawBody'])
            ->getMock();
        $request->method('getRawBody')->willReturn((string) json_encode($body));

        return $request;
    }
}
