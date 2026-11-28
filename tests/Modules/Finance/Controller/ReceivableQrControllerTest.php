<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Controller;

use Core\Http\Request;
use Core\Security\EncryptionService;
use Modules\Finance\Controller\ReceivableQrController;
use Modules\Finance\Repository\Account;
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Repository\Campaign;
use Modules\Finance\Repository\CampaignRepository;
use Modules\Finance\Repository\CampaignRowRepository;
use Modules\Finance\Repository\ExpectedReceivableRepository;
use Modules\Finance\Repository\TransactionRepository;
use Modules\Finance\Service\ReceivableCampaigns;
use Modules\Finance\Service\ReceivableQrTokenService;
use Modules\Finance\Service\SepaQrCodeService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class ReceivableQrControllerTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private ReceivableQrController $controller;
    private ReceivableQrTokenService $tokens;
    private ExpectedReceivableRepository $receivables;
    private TransactionRepository $transactions;
    private CampaignRepository $campaigns;
    private CampaignRowRepository $campaignRows;
    private int $accountId;
    private int $scoutYearId;
    private int $receivableId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FinanceTestHelper::createTables($this->pdo);

        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->tokens = new ReceivableQrTokenService($this->encryption);
        $this->receivables = new ExpectedReceivableRepository($this->pdo, $this->encryption);
        $this->transactions = new TransactionRepository($this->pdo, $this->encryption);
        $this->campaigns = new CampaignRepository($this->pdo);
        $this->campaignRows = new CampaignRowRepository($this->pdo, $this->encryption);

        $accounts = new AccountRepository($this->pdo, $this->encryption);
        $this->accountId = $accounts->create('Compte Unité', Account::TYPE_BANK, null, 'BE71096123456769', 'Unité SV025', 'intendant');

        $this->scoutYearId = FinanceTestHelper::createScoutYear($this->pdo, '2025-2026', '2025-09-01', '2026-08-31', true);

        $this->controller = new ReceivableQrController(
            new Environment(new ArrayLoader([])),
            $this->receivables,
            $accounts,
            FinanceTestHelper::allocationService($this->pdo, $this->encryption, $this->receivables),
            $this->tokens,
            new ReceivableCampaigns($this->campaignRows, $this->campaigns),
            new SepaQrCodeService()
        );

        $this->receivableId = $this->receivables->create(
            'finance',
            1,
            $this->accountId,
            4500,
            '+++123/4567/89012+++',
            null,
            null
        );
    }

    public function testAValidTokenServesThePng(): void
    {
        $response = $this->fetch($this->receivableId, $this->tokens->tokenFor($this->receivableId));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('image/png', $response->getHeaders()['Content-Type'] ?? null);
        $this->assertStringStartsWith("\x89PNG", $response->getBody());
    }

    /**
     * The token is the whole authorization: a mail client has no session
     * and never will.
     */
    public function testAWrongTokenIsRefused(): void
    {
        $this->assertSame(404, $this->fetch($this->receivableId, 'nope')->getStatusCode());
        $this->assertSame(404, $this->fetch($this->receivableId, $this->tokens->tokenFor($this->receivableId + 1))->getStatusCode());
    }

    /**
     * The same answer as an id that does not exist: a wrong token must
     * not tell a prober which receivables are real.
     */
    public function testAnUnknownReceivableAnswersExactlyLikeAWrongToken(): void
    {
        $unknown = $this->fetch(9999, $this->tokens->tokenFor(9999));
        $wrongToken = $this->fetch($this->receivableId, 'nope');

        $this->assertSame($wrongToken->getStatusCode(), $unknown->getStatusCode());
        $this->assertSame($wrongToken->getBody(), $unknown->getBody());
    }

    /**
     * The QR encodes what is STILL DUE. Asking the original amount again
     * after a partial payment would manufacture a surplus.
     */
    public function testTheEncodedAmountIsWhatIsStillDue(): void
    {
        $this->transactions->create(
            $this->accountId, $this->scoutYearId, 'REF-1', '2026-02-18',
            'Acompte +++123/4567/89012+++', 20.00, null, null, 'import', null
        );

        // The PNG's own payload cannot be read back without a decoder, so
        // the amount is asserted through the generator the controller
        // uses: same beneficiary, same IBAN, same communication, 25 €.
        $expected = (new SepaQrCodeService())->generatePng('Unité SV025', 'BE71096123456769', null, 2500, '+++123/4567/89012+++');
        $unexpected = (new SepaQrCodeService())->generatePng('Unité SV025', 'BE71096123456769', null, 4500, '+++123/4567/89012+++');

        $body = $this->fetch($this->receivableId, $this->tokens->tokenFor($this->receivableId))->getBody();

        $this->assertSame($expected, $body);
        $this->assertNotSame($unexpected, $body);
    }

    public function testASettledReceivableHasNoImageToServe(): void
    {
        $this->transactions->create(
            $this->accountId, $this->scoutYearId, 'REF-1', '2026-02-18',
            'Virement +++123/4567/89012+++', 45.00, null, null, 'import', null
        );

        $this->assertSame(404, $this->fetch($this->receivableId, $this->tokens->tokenFor($this->receivableId))->getStatusCode());
    }

    public function testAnAccountWithoutAnIbanServesNothingRatherThanAnUnusableCode(): void
    {
        $this->pdo->exec("UPDATE finance_accounts SET iban = NULL WHERE id = {$this->accountId}");

        $this->assertSame(404, $this->fetch($this->receivableId, $this->tokens->tokenFor($this->receivableId))->getStatusCode());
    }

    /**
     * Issue #1436: the member page drops a closed campaign's demand, and
     * this is the code that page shows — so the image goes too, answering
     * like a settled receivable. Reopening brings it back.
     */
    public function testAClosedCampaignsReceivableHasNoImageUntilItReopens(): void
    {
        $campaignId = $this->campaigns->create('Camp', $this->scoutYearId, $this->accountId, null, 'camp.xlsx', [], 7);
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('D-1')");
        $memberId = (int) $this->pdo->lastInsertId();
        $rowId = $this->campaignRows->create($campaignId, $memberId, 6000, 1, []);
        $receivableId = $this->receivables->create('finance', $rowId, $this->accountId, 6000, '+++111/2222/33344+++', null, $memberId);
        $token = $this->tokens->tokenFor($receivableId);

        $this->assertSame(200, $this->fetch($receivableId, $token)->getStatusCode());

        $this->campaigns->setStatus($campaignId, Campaign::STATUS_CLOSED, '2026-03-01 10:00:00');
        $closed = $this->fetch($receivableId, $token);
        $this->assertSame(404, $closed->getStatusCode());
        $this->assertSame($this->fetch(9999, $this->tokens->tokenFor(9999))->getBody(), $closed->getBody());

        $this->campaigns->setStatus($campaignId, Campaign::STATUS_OPEN, null);
        $this->assertSame(200, $this->fetch($receivableId, $token)->getStatusCode());
    }

    /**
     * The image is one family's payment request and changes the moment
     * they pay part of it: never a shared cache.
     */
    public function testTheImageIsNeverPubliclyCacheable(): void
    {
        $headers = $this->fetch($this->receivableId, $this->tokens->tokenFor($this->receivableId))->getHeaders();

        $this->assertStringContainsString('private', $headers['Cache-Control'] ?? '');
        $this->assertStringNotContainsString('public', $headers['Cache-Control'] ?? '');
    }

    private function fetch(int $receivableId, string $token): \Core\Http\Response
    {
        return $this->controller->png(
            new Request('GET', '/finance/qr/' . $receivableId . '/' . $token, [], [], [], []),
            ['id' => (string) $receivableId, 'token' => $token]
        );
    }
}
