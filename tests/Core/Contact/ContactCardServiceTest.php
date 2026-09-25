<?php

declare(strict_types=1);

namespace Tests\Core\Contact;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Contact\ContactCardService;
use Core\Contact\ContactPhotoResolver;
use Core\Contact\Repository\ContactCardRepository;
use Core\Contact\VCardVariant;
use Core\Database\Connection;
use Core\File\FileRepository;
use Core\Member\MemberEmailRepository;
use Core\Member\MemberService;
use Core\Photo\ImageVariantProcessor;
use Core\Photo\ImageVariantService;
use Core\Photo\MemberPhotoRepository;
use Core\Photo\MemberPhotoService;
use Core\Security\EncryptionService;
use Core\Import\MemberYearRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Core\Member\Repository\MemberProfileRepository;

/**
 * What a contact card is assembled from — and the fields of the member
 * record it deliberately never reaches for.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class ContactCardServiceTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $enc;
    private ContactCardService $service;
    private MemberService $memberService;
    private int $memberId;
    private int $memberYearId;
    private int $yearId;
    private MemberPhotoService $photoService;
    private string $storagePath;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->enc = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $connection = Connection::withPdo($this->pdo);

        $settingService = new SettingService(new SettingRepository($this->pdo));
        $settingService->register('site_name', '15e Unité Saint-Michel', 'text', 'Nom', 'Nom de l\'unité');

        $this->memberService = new MemberService(
    new MemberYearRepository($this->pdo),
    new MemberProfileRepository($connection, $this->enc)
);
        // The portrait resolver is wired here, not left null: the Full
        // variant's PHOTO is part of what this class decides, and a test
        // that never gives it a resolver never exercises it.
        $this->storagePath = sys_get_temp_dir() . '/contact_card_' . bin2hex(random_bytes(6));
        mkdir($this->storagePath . '/core/member_photos', 0755, true);
        $fileRepository = new FileRepository($this->pdo);
        $this->photoService = new MemberPhotoService(new MemberPhotoRepository($this->pdo));

        $this->service = new ContactCardService(
            new ContactCardRepository($connection),
            $settingService,
            new MemberEmailRepository($this->pdo, $this->enc),
            new ContactPhotoResolver(
                $this->photoService,
                $fileRepository,
                new ImageVariantService($fileRepository, new ImageVariantProcessor(), $this->storagePath),
                $this->storagePath
            )
        );

        [$label, $start, $end] = DatabaseTestHelper::scoutYear();
        $stmt = $this->pdo->prepare('INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES (?, ?, ?, 1)');
        $stmt->execute([$label, $start, $end]);
        $this->yearId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO age_branches (desk_code, label) VALUES ('LOUV', 'Louveteaux')");
        $branchId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO sections (age_branch_id, desk_code, name) VALUES ($branchId, 'LOUV1', 'Les Loups Gris')");
        $sectionId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO functions (desk_code, label, role) VALUES ('ANIM', 'Animateur', 'chief')");
        $functionId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('D-1')");
        $this->memberId = (int) $this->pdo->lastInsertId();

        // Everything the « ce qui ne sort jamais » list names is stored on
        // this row on purpose, so the assertions below mean something.
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted,
                totem_encrypted, phone_encrypted, mobile_encrypted, email_encrypted, gender_encrypted,
                birth_date_encrypted, patrol_encrypted, handicap_encrypted, formation_level,
                supplementary_insurance, federation_mail_consent, unit_mail_consent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1)'
        );
        $stmt->execute([
            $this->memberId,
            $this->yearId,
            $this->enc->encrypt('JEAN', 'member_years.first_name'),
            $this->enc->encrypt('DUPONT', 'member_years.last_name'),
            $this->enc->encrypt('loutre rieuse', 'member_years.totem'),
            $this->enc->encrypt('081/12.34.56', 'member_years.phone'),
            $this->enc->encrypt('0475 12 34 56', 'member_years.mobile'),
            $this->enc->encrypt('jean.dupont@example.org', 'member_years.email'),
            $this->enc->encrypt('M', 'member_years.gender'),
            $this->enc->encrypt('2005-04-12', 'member_years.birth_date'),
            $this->enc->encrypt('Les Castors', 'member_years.patrol'),
            $this->enc->encrypt('Allergie sévère aux arachides', 'member_years.handicap'),
            'Animateur breveté',
            'Assurance Machin 1234',
        ]);
        $this->memberYearId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, section_id, is_main_function) VALUES (?, ?, ?, 1)'
        );
        $stmt->execute([$this->memberYearId, $functionId, $sectionId]);

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_addresses (member_year_id, address_type, street_encrypted, number_encrypted,
                postal_code_encrypted, city_encrypted) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $this->memberYearId,
            'Adresse principale',
            $this->enc->encrypt('Rue de la Station', 'member_addresses.street'),
            $this->enc->encrypt('12', 'member_addresses.number'),
            $this->enc->encrypt('5000', 'member_addresses.postal_code'),
            $this->enc->encrypt('Namur', 'member_addresses.city'),
        ]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->storagePath . '/core/member_photos/*') ?: [] as $file) {
            unlink($file);
        }
    }

    private function build(VCardVariant $variant = VCardVariant::Full): \Core\Contact\ContactCard
    {
        return $this->service->build(
            $this->memberService->getMemberProfile($this->memberYearId),
            $variant,
            $this->yearId
        );
    }

    public function testTheNameAndTotemAreNormalisedTheWayTheInterfaceNormalisesThem(): void
    {
        $card = $this->build();

        $this->assertSame('Jean', $card->firstName);
        $this->assertSame('Dupont', $card->lastName);
        $this->assertSame('Loutre rieuse', $card->totem);
        $this->assertSame('Jean Dupont', $card->formattedName());
    }

    public function testTheOrganisationIsTheConfiguredUnitNameAndTheMainSection(): void
    {
        $card = $this->build();

        $this->assertSame('15e Unité Saint-Michel', $card->unitName);
        $this->assertSame('Les Loups Gris', $card->sectionName);
        $this->assertSame('Animateur', $card->title);
    }

    public function testBothDeskNumbersAreCarriedInInternationalForm(): void
    {
        $this->assertSame(['+32 81 12 34 56', '+32 475 12 34 56'], $this->build()->phones);
    }

    /**
     * The Desk address first, then every address the member configured and
     * confirmed. A « pending » one is not exported — nothing on the site
     * treats an unconfirmed address as belonging to anybody.
     */
    public function testTheDeskAddressComesFirstAndOnlyConfirmedOnesFollow(): void
    {
        $emails = new MemberEmailRepository($this->pdo, $this->enc);
        $emails->create($this->memberId, 'perso@example.org', 'manual', 'valid', null, null);
        $emails->create($this->memberId, 'jamais-confirmee@example.org', 'manual', 'pending', null, null);

        $this->assertSame(
            ['jean.dupont@example.org', 'perso@example.org'],
            $this->build()->emails
        );
    }

    public function testTheDeskAddressAddedAgainAsASecondaryOneIsNotListedTwice(): void
    {
        (new MemberEmailRepository($this->pdo, $this->enc))
            ->create($this->memberId, 'jean.dupont@example.org', 'manual', 'valid', null, null);

        $this->assertSame(['jean.dupont@example.org'], $this->build()->emails);
    }

    public function testThePostalAddressIsCarriedWhole(): void
    {
        $addresses = $this->build()->addresses;

        $this->assertCount(1, $addresses);
        $this->assertSame('Rue de la Station', $addresses[0]->street);
        $this->assertSame('Namur', $addresses[0]->city);
    }

    /**
     * The card is assembled from ONE definition, and the variant only ever
     * decides the portrait and the length of the history — never what a
     * field holds.
     */
    public function testTheTwoVariantsAgreeOnEveryFieldTheyBothCarry(): void
    {
        $qr = $this->build(VCardVariant::Qr);
        $full = $this->build(VCardVariant::Full);

        $this->assertSame($qr->formattedName(), $full->formattedName());
        $this->assertSame($qr->uid(), $full->uid());
        $this->assertSame($qr->emails, $full->emails);
        $this->assertSame($qr->phones, $full->phones);
        $this->assertSame($qr->title, $full->title);
        $this->assertSame(
            array_map(static fn($a): string => $a->format(), $qr->affiliations),
            array_map(static fn($a): string => $a->format(), $full->affiliations)
        );
    }

    /**
     * The handicap is health data, sitting in the very object this card is
     * built from. Neither it nor anything else on the « ce qui ne sort
     * jamais » list may appear anywhere in an assembled card.
     */
    public function testNothingOnTheNeverLeavesListIsAnywhereInTheCard(): void
    {
        $card = $this->build();
        $serialised = json_encode([
            $card->firstName, $card->lastName, $card->totem, $card->unitName, $card->sectionName,
            $card->title, $card->emails, $card->phones, $card->scoutYearLabel,
            array_map(static fn($a): string => $a->format(), $card->affiliations),
            array_map(static fn($a): string => $a->format(), $card->addresses),
        ], JSON_UNESCAPED_UNICODE);

        foreach (
            ['Allergie sévère aux arachides', 'Assurance Machin 1234', '2005-04-12',
                'Les Castors', 'Animateur breveté', 'D-1'] as $forbidden
        ) {
            $this->assertStringNotContainsString($forbidden, (string) $serialised);
        }
    }

    private function givePhoto(): void
    {
        $image = imagecreatetruecolor(300, 200);
        imagefilledrectangle($image, 0, 0, 300, 200, imagecolorallocate($image, 10, 120, 200));
        ob_start();
        imagejpeg($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        $relativePath = 'core/member_photos/portrait.jpg';
        file_put_contents($this->storagePath . '/' . $relativePath, $bytes);

        $stmt = $this->pdo->prepare(
            'INSERT INTO files (relative_path, original_name, mime_type, size_bytes, role_min, encrypted)
             VALUES (?, ?, ?, ?, ?, 0)'
        );
        $stmt->execute([$relativePath, 'portrait.jpg', 'image/jpeg', strlen($bytes), 'identified']);

        $this->photoService->setPhoto($this->memberId, $this->yearId, (int) $this->pdo->lastInsertId(), null);
    }

    /**
     * The portrait travels in the file and in CardDAV, never in the QR
     * code — a JPEG does not fit in a scannable symbol at all.
     */
    public function testThePortraitIsCarriedByTheFullVariantAndNeverByTheQrOne(): void
    {
        $this->givePhoto();

        $full = $this->build(VCardVariant::Full);
        $this->assertNotNull($full->photoJpeg);
        $this->assertSame('image/jpeg', (string) (getimagesizefromstring($full->photoJpeg)['mime'] ?? ''));

        $this->assertNull($this->build(VCardVariant::Qr)->photoJpeg);
    }

    public function testAMemberWithNoPortraitSimplyCarriesNone(): void
    {
        $this->assertNull($this->build(VCardVariant::Full)->photoJpeg);
    }

    public function testAMemberWithoutATotemHasNoNickname(): void
    {
        $this->pdo->exec("UPDATE member_years SET totem_encrypted = NULL WHERE id = {$this->memberYearId}");

        $this->assertNull($this->build()->totem);
    }
}
