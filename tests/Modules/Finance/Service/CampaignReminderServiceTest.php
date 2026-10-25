<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Service;

use Core\Database\Connection;
use Core\Import\MemberYearRepository;
use Core\Member\MemberService;
use Core\Security\EncryptionService;
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Repository\Campaign;
use Modules\Finance\Repository\CampaignRepository;
use Modules\Finance\Repository\CampaignRowRepository;
use Modules\Finance\Repository\ExpectedReceivableRepository;
use Modules\Finance\Repository\TransactionRepository;
use Modules\Finance\Service\CampaignReminderService;
use Modules\Finance\Service\CampaignService;
use Modules\Finance\Api\FinanceException;
use Modules\Finance\Service\ReceivableQrTokenService;
use Modules\MassMail\Api\MassMailDraftInterface;
use Modules\MassMail\Service\MergeRenderer;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;
use Core\Member\Repository\MemberProfileRepository;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class CampaignReminderServiceTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private CampaignRepository $campaigns;
    private CampaignRowRepository $rows;
    private ExpectedReceivableRepository $receivables;
    private TransactionRepository $transactions;
    private ReceivableQrTokenService $tokens;
    private int $accountId;
    private int $scoutYearId;
    /** @var array<string, int> */
    private array $memberIds = [];
    private RecordingDraftProvider $draft;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FinanceTestHelper::createTables($this->pdo);

        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->campaigns = new CampaignRepository($this->pdo);
        $this->rows = new CampaignRowRepository($this->pdo, $this->encryption);
        $this->receivables = new ExpectedReceivableRepository($this->pdo, $this->encryption);
        $this->transactions = new TransactionRepository($this->pdo, $this->encryption);
        $this->tokens = new ReceivableQrTokenService($this->encryption);
        $this->draft = new RecordingDraftProvider();

        $accountRepository = new AccountRepository($this->pdo, $this->encryption);
        $this->accountId = $accountRepository->create(
            'Compte Unité',
            \Modules\Finance\Repository\Account::TYPE_BANK,
            null,
            'BE71096123456769',
            'Unité SV025 Ottignies',
            'intendant'
        );

        $this->scoutYearId = FinanceTestHelper::createScoutYear($this->pdo, '2025-2026', '2025-09-01', '2026-08-31', true);

        $this->memberIds['Lucie'] = $this->createMember('Lucie', 'famille@test.be');
        $this->memberIds['Antoine'] = $this->createMember('Antoine', 'famille@test.be');
        $this->memberIds['Timeo'] = $this->createMember('Timéo', 'roskam@test.be');
    }

    // ── one row per receivable, attached to its child ──────────────────

    /**
     * The composer's URL travels back untouched. `MassMailDraftInterface`
     * names the screen a draft is written on; finance is one of its
     * callers and rewriting that address here — appending a filter, say —
     * is exactly how one caller ends up repaired and the other not.
     */
    public function testItHandsBackTheComposerUrlTheMailMergeModuleNamed(): void
    {
        $campaign = $this->campaignWith([['Lucie', 4500]]);

        $url = $this->service()->createDraft($campaign, 'intendant', 'tresorier@test.be', 7);

        $this->assertSame('/mass-mail/1', $url);
    }

    /**
     * One row per receivable, each carrying its child's member_id and no
     * address of its own: mass_mail sends it to that child's addresses
     * and files it on that child's page (issue #999).
     */
    public function testEachReceivableIsOneRowAttachedToItsChild(): void
    {
        $campaign = $this->campaignWith([
            ['Lucie', 4500],
            ['Antoine', 4500],
            ['Timeo', 3825],
        ]);

        $this->service()->createDraft($campaign, 'intendant', 'tresorier@test.be', 7);

        $this->assertCount(3, $this->draft->rows, 'one row per receivable, never one per address');
        $this->assertSame(
            [$this->memberIds['Lucie'], $this->memberIds['Antoine'], $this->memberIds['Timeo']],
            array_map(static fn(array $row): ?int => $row['member_id'] ?? null, $this->draft->rows)
        );
        foreach ($this->draft->rows as $row) {
            $this->assertArrayNotHasKey('email', $row, 'the member decides where it goes, not an address');
        }
        $this->assertSame('Timéo', $this->draft->rows[2]['values']['Prénom']);
        $this->assertSame('38,25 €', $this->draft->rows[2]['values']['Montant']);
    }

    /**
     * Lucie and Antoine share their family's address. They used to be one
     * mail with two blocks, which belonged to neither child's page; they
     * are now two rows, each with its own amount and communication.
     */
    public function testTwoChildrenOfOneFamilyAreTwoRows(): void
    {
        $campaign = $this->campaignWith([['Lucie', 4500], ['Antoine', 3000]]);

        $this->service()->createDraft($campaign, 'intendant', 'tresorier@test.be', 7);

        $this->assertCount(2, $this->draft->rows);
        [$lucie, $antoine] = $this->draft->rows;
        $this->assertSame($this->memberIds['Lucie'], $lucie['member_id'] ?? null);
        $this->assertSame($this->memberIds['Antoine'], $antoine['member_id'] ?? null);
        $this->assertSame(['Lucie', '45,00 €'], [$lucie['values']['Prénom'], $lucie['values']['Montant']]);
        $this->assertSame(['Antoine', '30,00 €'], [$antoine['values']['Prénom'], $antoine['values']['Montant']]);
        $this->assertNotSame($lucie['values']['Communication'], $antoine['values']['Communication']);
        $this->assertNotSame($lucie['values']['QR'], $antoine['values']['QR']);
    }

    public function testImportedColumnsBecomeMergeVariablesOfEachChildReminder(): void
    {
        $campaign = $this->campaignWith([
            ['Lucie', 4500, ['Groupe' => 'Lutins', 'Remarque' => 'Premier rappel']],
            ['Antoine', 3000, ['Groupe' => 'Louveteaux', 'Échéance' => '31 octobre']],
        ]);

        $this->service()->createDraft($campaign, 'intendant', 'tresorier@test.be', 7);

        $this->assertSame(
            [...CampaignReminderService::COLUMNS, 'Groupe', 'Remarque', 'Échéance'],
            $this->draft->columns
        );
        [$lucie, $antoine] = $this->draft->rows;
        $this->assertSame('Lutins', $lucie['values']['Groupe']);
        $this->assertSame('Premier rappel', $lucie['values']['Remarque']);
        $this->assertSame('Louveteaux', $antoine['values']['Groupe']);
        $this->assertSame('31 octobre', $antoine['values']['Échéance']);
        $this->assertArrayNotHasKey('Remarque', $antoine['values']);
        $this->assertArrayNotHasKey('Échéance', $lucie['values']);

        $renderer = new MergeRenderer();
        $body = '<p>{{Prénom}} — {{Groupe}} : {{Remarque}}</p>';
        $this->assertSame([], $renderer->findUnknownTokens($body, $this->draft->columns));
        $this->assertSame('<p>Lucie — Lutins : Premier rappel</p>', $renderer->renderHtml($body, $lucie['values']));
    }

    public function testCalculatedPaymentDetailsOverrideImportedColumnsWithTheSameName(): void
    {
        $campaign = $this->campaignWith([
            ['Lucie', 4500, array_fill_keys(CampaignReminderService::COLUMNS, 'Imported override')],
        ]);
        $receivable = $this->receivableOf($campaign, 0);
        $this->transactions->create(
            $this->accountId, $this->scoutYearId, 'REF-1', '2026-02-18',
            'Acompte ' . $receivable->communication, 20.00, null, null, 'import', null
        );

        $this->service()->createDraft($campaign, 'intendant', 'tresorier@test.be', 7);

        $this->assertSame(CampaignReminderService::COLUMNS, $this->draft->columns);
        $this->assertSame([
            'Prénom' => 'Lucie',
            'Montant' => '25,00 €',
            'Communication' => $receivable->communication,
            'QR' => $this->tokens->urlFor($receivable->id, 'https://scoutmagic.test'),
            'Bénéficiaire' => 'Unité SV025 Ottignies',
            'IBAN' => 'BE71 0961 2345 6769',
        ], $this->draft->rows[0]['values']);
    }

    /**
     * The body used to carry at most six blocks, and a seventh child was
     * dropped by a `continue` without a word. One row per receivable has
     * no cap: seven children are seven reminders.
     */
    public function testSevenChildrenAreSevenRowsAndNoneIsLeftOut(): void
    {
        $names = ['Lucie', 'Antoine', 'Zoé', 'Hugo', 'Emma', 'Noah', 'Louise'];
        foreach (array_slice($names, 2) as $name) {
            $this->memberIds[$name] = $this->createMember($name, 'famille@test.be');
        }

        $campaign = $this->campaignWith(array_map(static fn(string $name): array => [$name, 4500], $names));

        $this->service()->createDraft($campaign, 'intendant', 'tresorier@test.be', 7);

        $this->assertCount(7, $this->draft->rows);
        $this->assertSame($names, array_map(static fn(array $row): string => $row['values']['Prénom'], $this->draft->rows));
        $this->assertSame($this->memberIds['Louise'], $this->draft->rows[6]['member_id'] ?? null, 'the seventh child is there');
    }

    /**
     * Every row fills every variable, so nothing in the body is
     * conditional: no section, no numbered column, one payment block.
     */
    public function testTheTemplateHasNoSectionsAndOnePaymentBlock(): void
    {
        $campaign = $this->campaignWith([['Lucie', 4500], ['Antoine', 4500]]);
        $this->service()->createDraft($campaign, 'intendant', 'tresorier@test.be', 7);

        $body = (string) $this->draft->bodyHtml;
        $this->assertStringNotContainsString('{{#', $body);
        $this->assertStringNotContainsString('{{/', $body);
        $this->assertDoesNotMatchRegularExpression('/\{\{[^}]*\d\s*\}\}/u', $body, 'no numbered variable');
        $this->assertSame(CampaignReminderService::COLUMNS, $this->draft->columns);
        $this->assertSame([], (new MergeRenderer())->findUnknownTokens($body, $this->draft->columns));

        foreach ($this->draft->rows as $row) {
            $this->assertSame(CampaignReminderService::COLUMNS, array_keys($row['values']), 'every column, every row');
            $rendered = (new MergeRenderer())->renderHtml($body, $row['values']);
            $this->assertSame(1, substr_count($rendered, '<img'), 'one QR per mail');
            $this->assertStringContainsString($row['values']['Prénom'], $rendered);
        }
    }

    /**
     * Many mail clients block remote images by default: an image that
     * does not load must never leave a parent without a way to pay.
     */
    public function testTheBodyRepeatsEverythingTheQrEncodesInText(): void
    {
        $campaign = $this->campaignWith([['Timeo', 3825]]);
        $service = $this->service();
        $service->createDraft($campaign, 'intendant', 'tresorier@test.be', 7);

        $values = $this->draft->rows[0]['values'];
        $body = (new MergeRenderer())->renderHtml((string) $this->draft->bodyHtml, $values);

        $this->assertStringContainsString('BE71 0961 2345 6769', $body, 'the IBAN in text');
        $this->assertStringContainsString($values['Communication'], $body, 'the communication in text');
        $this->assertStringContainsString('38,25 €', $body, 'the amount in text');
        $this->assertStringContainsString('bloque les images', $body, 'and it says what to do when the code is invisible');
    }

    public function testTheBodySaysWhyItAsksForOneTransferPerChild(): void
    {
        $body = $this->service()->draftBody();

        $this->assertStringContainsString('Un virement par enfant', $body);
        $this->assertStringContainsString("d'identifier chaque paiement", $body);
    }

    // ── the QR travels as a URL ─────────────────────────────────────────

    /**
     * `Core\Security\HtmlSanitizer` allows http/https/mailto/tel and
     * nothing else, so neither `cid:` nor `data:` can carry the image.
     * An absolute https URL is the whole mechanism.
     */
    public function testTheQrIsAnAbsoluteHttpsUrlCarryingItsOwnToken(): void
    {
        $campaign = $this->campaignWith([['Timeo', 3825]]);
        $this->service()->createDraft($campaign, 'intendant', 'tresorier@test.be', 7);

        $url = $this->draft->rows[0]['values']['QR'];
        $receivableId = (int) $this->rows->findByCampaignId($campaign->id)[0]->id;
        $receivable = $this->receivables->findBySource(CampaignService::SOURCE_MODULE, $receivableId)[0];

        $this->assertStringStartsWith('https://scoutmagic.test/finance/qr/', $url);
        $this->assertStringEndsWith($this->tokens->tokenFor($receivable->id), $url);
    }

    /**
     * The whole round trip, which is where this used to break: the
     * starting body is sanitized on the way into the draft, and the
     * sanitizer parses it with DOMDocument, which percent-encodes every
     * URI attribute — so `{{QR}}` is STORED as `%7B%7BQR%7D%7D`.
     * Left at that the variable would never substitute and every parent
     * would get a broken image, silently. MergeRenderer recognises the
     * encoded shape, so what matters is that the URL is right after
     * sanitize-then-render, not that the stored body looks pretty.
     */
    public function testTheQrSurvivesTheSanitizerAndStillSubstitutes(): void
    {
        $campaign = $this->campaignWith([['Timeo', 3825]]);
        $this->service()->createDraft($campaign, 'intendant', 'tresorier@test.be', 7);

        $stored = (new \Core\Security\HtmlSanitizer())->sanitize((string) $this->draft->bodyHtml);
        $this->assertSame(1, substr_count($stored, '<img'), 'the image tag itself survives');

        $rendered = (new MergeRenderer())->renderHtml($stored, $this->draft->rows[0]['values']);
        $this->assertStringContainsString(
            'src="' . htmlspecialchars($this->draft->rows[0]['values']['QR'], ENT_QUOTES) . '"',
            $rendered
        );
        $this->assertStringNotContainsString('%7B%7B', $rendered, 'no token is left unsubstituted');
    }

    public function testTheSameReceivableAlwaysYieldsTheSameUrl(): void
    {
        // Which is what makes the archived copy of a sent mail render
        // exactly what went out, with nothing stored to make it true.
        $this->assertSame($this->tokens->tokenFor(42), $this->tokens->tokenFor(42));
        $this->assertNotSame($this->tokens->tokenFor(42), $this->tokens->tokenFor(43));
    }

    public function testAWrongTokenIsRefused(): void
    {
        $this->assertTrue($this->tokens->isValid(42, $this->tokens->tokenFor(42)));
        $this->assertFalse($this->tokens->isValid(42, $this->tokens->tokenFor(43)));
        $this->assertFalse($this->tokens->isValid(42, ''));
    }

    // ── who is reminded, and who is not ─────────────────────────────────

    public function testASettledReceivableIsNotRemindedAbout(): void
    {
        $campaign = $this->campaignWith([['Lucie', 4500], ['Antoine', 4500]]);
        $lucieReceivable = $this->receivableOf($campaign, 0);
        $this->transactions->create(
            $this->accountId, $this->scoutYearId, 'REF-1', '2026-02-18',
            'Virement ' . $lucieReceivable->communication, 45.00, null, null, 'import', null
        );

        $this->service()->createDraft($campaign, 'intendant', 'tresorier@test.be', 7);

        $this->assertCount(1, $this->draft->rows, 'Lucie has paid and is gone from the reminder');
        $this->assertSame($this->memberIds['Antoine'], $this->draft->rows[0]['member_id'] ?? null);
        $this->assertSame('45,00 €', $this->draft->rows[0]['values']['Montant']);
    }

    /**
     * A partially paid receivable is reminded about for the BALANCE, not
     * for the original amount — the same rule as the QR itself.
     */
    public function testAPartiallyPaidReceivableIsRemindedForTheBalance(): void
    {
        $campaign = $this->campaignWith([['Timeo', 4500]]);
        $receivable = $this->receivableOf($campaign, 0);
        $this->transactions->create(
            $this->accountId, $this->scoutYearId, 'REF-1', '2026-02-18',
            'Acompte ' . $receivable->communication, 20.00, null, null, 'import', null
        );

        $this->service()->createDraft($campaign, 'intendant', 'tresorier@test.be', 7);

        $this->assertSame('25,00 €', $this->draft->rows[0]['values']['Montant']);
    }

    /**
     * WHO is reminded did not change with the attachment to the child: a
     * child without any address in Desk is still left out, and only that
     * child.
     */
    public function testAChildWithoutAnAddressInDeskIsLeftOut(): void
    {
        $this->pdo->prepare(
            'UPDATE member_years SET email_encrypted = NULL, email_blind_index = NULL WHERE member_id = ?'
        )->execute([$this->memberIds['Antoine']]);
        $campaign = $this->campaignWith([['Lucie', 4500], ['Antoine', 4500]]);

        $this->service()->createDraft($campaign, 'intendant', 'tresorier@test.be', 7);

        $this->assertSame(
            [$this->memberIds['Lucie']],
            array_map(static fn(array $row): ?int => $row['member_id'] ?? null, $this->draft->rows)
        );
    }

    public function testACampaignWhereEverythingIsSettledRefusesToBuildADraft(): void
    {
        $campaign = $this->campaignWith([['Timeo', 3825]]);
        $receivable = $this->receivableOf($campaign, 0);
        $this->transactions->create(
            $this->accountId, $this->scoutYearId, 'REF-1', '2026-02-18',
            'Virement ' . $receivable->communication, 38.25, null, null, 'import', null
        );

        $this->expectException(FinanceException::class);
        $this->expectExceptionMessageMatches('/Aucune créance à rappeler/');
        $this->service()->createDraft($campaign, 'intendant', 'tresorier@test.be', 7);
    }

    public function testAnAccountWithoutAnIbanRefusesRatherThanSendingAMailNobodyCanActOn(): void
    {
        $this->pdo->exec("UPDATE finance_accounts SET iban = NULL WHERE id = {$this->accountId}");
        $campaign = $this->campaignWith([['Timeo', 3825]]);

        $this->expectException(FinanceException::class);
        $this->expectExceptionMessageMatches('/IBAN/');
        $this->service()->createDraft($campaign, 'intendant', 'tresorier@test.be', 7);
    }

    // ── mass_mail is optional ───────────────────────────────────────────

    public function testWithoutTheMailMergeModuleTheFeatureIsSimplyNotOffered(): void
    {
        $service = $this->service(withDraftProvider: false);

        $this->assertFalse($service->isAvailable());
    }

    public function testWithoutTheMailMergeModuleBuildingADraftIsARefusalNotAFatal(): void
    {
        $campaign = $this->campaignWith([['Timeo', 3825]]);

        $this->expectException(FinanceException::class);
        $this->expectExceptionMessageMatches('/publipostage/');
        $this->service(withDraftProvider: false)->createDraft($campaign, 'intendant', 'tresorier@test.be', 7);
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private function service(bool $withDraftProvider = true): CampaignReminderService
    {
        return new CampaignReminderService(
            $this->rows,
            $this->receivables,
            FinanceTestHelper::allocationService($this->pdo, $this->encryption, $this->receivables),
            new AccountRepository($this->pdo, $this->encryption),
            new MemberService(
    new MemberYearRepository($this->pdo),
    new MemberProfileRepository(Connection::withPdo($this->pdo), $this->encryption)
),
            $this->tokens,
            'https://scoutmagic.test',
            $withDraftProvider ? $this->draft : null
        );
    }

    /**
     * @param array<int, array{0: string, 1: int, 2?: array<string, string>}> $lines first name, amount in cents, imported columns
     */
    private function campaignWith(array $lines): Campaign
    {
        $campaignId = $this->campaigns->create(
            'Cotisations 2025-2026',
            $this->scoutYearId,
            $this->accountId,
            null,
            'cotisations.xlsx',
            [],
            7
        );

        $sequence = 0;
        foreach ($lines as $line) {
            [$firstName, $amountCents] = $line;
            $sequence++;
            $rowId = $this->rows->create($campaignId, $this->memberIds[$firstName], $amountCents, $sequence, $line[2] ?? []);
            $this->receivables->create(
                CampaignService::SOURCE_MODULE,
                $rowId,
                $this->accountId,
                $amountCents,
                \Modules\Finance\Service\StructuredCommunicationService::format(str_pad((string) (1000000000 + $sequence), 10, '0', STR_PAD_LEFT)),
                null,
                $this->memberIds[$firstName]
            );
        }

        $campaign = $this->campaigns->findById($campaignId);
        self::assertNotNull($campaign);

        return $campaign;
    }

    private function receivableOf(Campaign $campaign, int $index): \Modules\Finance\Repository\ExpectedReceivable
    {
        $rows = $this->rows->findByCampaignId($campaign->id);

        return $this->receivables->findBySource(CampaignService::SOURCE_MODULE, $rows[$index]->id)[0];
    }

    private function createMember(string $firstName, string $email): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO members (desk_id) VALUES (?)');
        $stmt->execute(['D-' . $firstName]);
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, email_encrypted, email_blind_index, is_active)
             VALUES (?, ?, ?, ?, ?, ?, 1)'
        );
        $stmt->execute([
            $memberId,
            $this->scoutYearId,
            $this->encryption->encrypt($firstName, 'member_years.first_name'),
            $this->encryption->encrypt('Vandenbrande', 'member_years.last_name'),
            $this->encryption->encrypt($email, 'member_years.email'),
            $this->encryption->blindIndex(strtolower($email), 'member_years.email'),
        ]);

        return $memberId;
    }
}

/**
 * A stand-in for the mail-merge module that records what it was handed
 * instead of writing a draft — the point of these tests is what finance
 * PUTS in a reminder, not what mass_mail does with it afterwards.
 */
final class RecordingDraftProvider implements MassMailDraftInterface
{
    public string $label = '';
    public string $subject = '';
    /** @var string[] */
    public array $columns = [];
    /** @var list<array{email?: string, member_id?: ?int, values: array<string, string>}> */
    public array $rows = [];
    public ?string $bodyHtml = null;

    public function createMergeDraft(
        string $label,
        string $subject,
        array $columns,
        array $rows,
        string $actorRole,
        string $actorEmail,
        ?int $actorAccountId,
        ?string $bodyHtml = null
    ): string {
        $this->label = $label;
        $this->subject = $subject;
        $this->columns = $columns;
        $this->rows = $rows;
        $this->bodyHtml = $bodyHtml;

        return '/mass-mail/1';
    }
}
