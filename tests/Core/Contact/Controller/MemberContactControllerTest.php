<?php

declare(strict_types=1);

namespace Tests\Core\Contact\Controller;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Contact\ContactCardService;
use Core\Contact\ContactQrCodeBuilder;
use Core\Contact\Controller\MemberContactController;
use Core\Contact\Repository\ContactCardRepository;
use Core\Contact\VCardBuilder;
use Core\Database\Connection;
use Core\Http\Request;
use Core\Import\MemberYearRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Member\MemberEmailRepository;
use Core\Member\MemberService;
use Core\Security\EncryptionService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * The two routes behind « Ajouter à mes contacts »: what they answer, what
 * they put in the journal, and what they refuse.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class MemberContactControllerTest extends TestCase
{
    private \PDO $pdo;
    private MemberContactController $controller;
    private int $memberId;
    private int $memberYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $enc = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $connection = Connection::withPdo($this->pdo);

        $settingService = new SettingService(new SettingRepository($this->pdo));
        $settingService->register('site_name', '15e Unité Saint-Michel', 'text', 'Nom', 'Nom de l\'unité');

        $twig = new Environment(new ArrayLoader(['errors/404.html.twig' => 'Introuvable']));

        $this->controller = new MemberContactController(
            $twig,
            new MemberService(new MemberYearRepository($this->pdo), $enc, $connection),
            new ContactCardService(
                new ContactCardRepository($connection),
                $settingService,
                new MemberEmailRepository($this->pdo, $enc)
            ),
            new VCardBuilder(),
            new ContactQrCodeBuilder(),
            new JournalService(new JournalRepository($this->pdo))
        );

        [$label, $start, $end] = DatabaseTestHelper::scoutYear();
        $stmt = $this->pdo->prepare('INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES (?, ?, ?, 1)');
        $stmt->execute([$label, $start, $end]);
        $yearId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO age_branches (desk_code, label) VALUES ('LOUV', 'Louveteaux')");
        $branchId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO sections (age_branch_id, desk_code, name) VALUES ($branchId, 'LOUV1', 'Les Loups Gris')");
        $sectionId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO functions (desk_code, label, role) VALUES ('ANIM', 'Animateur', 'chief')");
        $functionId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('D-1')");
        $this->memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted,
                phone_encrypted, mobile_encrypted, email_encrypted, handicap_encrypted)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $this->memberId,
            $yearId,
            $enc->encrypt('JEAN-LOUIS', 'member_years.first_name'),
            $enc->encrypt('DE LA CROIX', 'member_years.last_name'),
            $enc->encrypt('081/12.34.56', 'member_years.phone'),
            $enc->encrypt('0475 12 34 56', 'member_years.mobile'),
            $enc->encrypt('jean.dupont@example.org', 'member_years.email'),
            $enc->encrypt('Allergie sévère aux arachides', 'member_years.handicap'),
        ]);
        $this->memberYearId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, section_id, is_main_function) VALUES (?, ?, ?, 1)'
        );
        $stmt->execute([$this->memberYearId, $functionId, $sectionId]);
    }

    private function request(): Request
    {
        return new Request('GET', '/admin/members/' . $this->memberYearId . '/contact-vcard', [], [], [], []);
    }

    /** @return list<array<string, mixed>> */
    private function journalEntries(): array
    {
        return $this->pdo->query('SELECT * FROM event_log ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function testTheVcardIsServedAsADownloadNamedAfterTheMember(): void
    {
        $response = $this->controller->vcard($this->request(), ['id' => (string) $this->memberYearId]);

        $this->assertSame(200, $response->getStatusCode());
        $headers = $response->getHeaders();
        $this->assertSame('text/vcard; charset=utf-8', $headers['Content-Type']);
        $this->assertSame('attachment; filename="de-la-croix-jean-louis.vcf"', $headers['Content-Disposition']);
        $this->assertStringStartsWith("BEGIN:VCARD\r\n", $response->getBody());
    }

    /**
     * A contact card is somebody's home address and telephone number: it
     * must not sit in a shared cache or in a browser's history.
     */
    public function testNeitherPayloadIsCacheable(): void
    {
        foreach ([
            $this->controller->vcard($this->request(), ['id' => (string) $this->memberYearId]),
            $this->controller->qrCode($this->request(), ['id' => (string) $this->memberYearId]),
        ] as $response) {
            $this->assertSame('private, no-store', $response->getHeaders()['Cache-Control']);
        }
    }

    public function testTheQrCodeIsAPngCarryingTheCard(): void
    {
        $response = $this->controller->qrCode($this->request(), ['id' => (string) $this->memberYearId]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('image/png', $response->getHeaders()['Content-Type']);
        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $response->getBody());
    }

    /**
     * The whole feature's rule, at the last layer that could break it: the
     * health datum sitting in the same MemberProfile never reaches a
     * payload.
     */
    public function testTheHandicapNeverReachesTheServedCard(): void
    {
        $body = $this->controller->vcard($this->request(), ['id' => (string) $this->memberYearId])->getBody();

        $this->assertStringNotContainsString('Allergie', $body);
        $this->assertStringNotContainsString('arachides', $body);
    }

    /**
     * An export of somebody's contact details is a sensitive action, so it
     * is journaled — with the member's IDENTIFIER and nothing else. Not
     * their name, not their address, not a telephone number: a journal
     * entry is read on screen, travels in the support archive, and
     * outlives the reason the card was produced.
     */
    public function testEachExportIsJournaledWithTheIdentifierAndNothingElse(): void
    {
        $this->controller->vcard($this->request(), ['id' => (string) $this->memberYearId]);
        $this->controller->qrCode($this->request(), ['id' => (string) $this->memberYearId]);

        $entries = $this->journalEntries();
        $this->assertCount(2, $entries);
        $this->assertSame(
            ['member_contact_vcard_downloaded', 'member_contact_qr_served'],
            array_column($entries, 'event_type')
        );

        foreach ($entries as $entry) {
            $this->assertSame('security', $entry['level']);
            $this->assertSame(['member_id' => $this->memberId], json_decode((string) $entry['context'], true));
            $row = (string) $entry['context'] . '|' . (string) $entry['description'];
            foreach (['JEAN', 'CROIX', 'Jean', 'Croix', 'example.org', '0475', '81 12'] as $personal) {
                $this->assertStringNotContainsString($personal, $row);
            }
        }
    }

    public function testAnUnknownMemberYearIsNotFoundAndIsNotJournaled(): void
    {
        foreach (['999999', '0', 'abc'] as $id) {
            $this->assertSame(404, $this->controller->vcard($this->request(), ['id' => $id])->getStatusCode());
            $this->assertSame(404, $this->controller->qrCode($this->request(), ['id' => $id])->getStatusCode());
        }

        $this->assertSame([], $this->journalEntries());
    }

    /**
     * A card whose payload cannot fit in a symbol answers in French,
     * naming the member nowhere, and the download stays available.
     */
    public function testAnOversizedCardIsRefusedInWordsThatNameNobody(): void
    {
        $builder = new ContactQrCodeBuilder();

        try {
            $builder->build(str_repeat('x', ContactQrCodeBuilder::MAX_PAYLOAD_BYTES + 1));
            $this->fail('An oversized payload must be refused.');
        } catch (\Core\Contact\ContactCardException $e) {
            $this->assertStringContainsString('code QR', $e->getMessage());
            $this->assertStringNotContainsString('\\', $e->getMessage());
        }
    }
}
