<?php

declare(strict_types=1);

namespace Tests\Core\Contact;

use Core\Contact\ContactAffiliation;
use Core\Contact\ContactCard;
use Core\Contact\VCardBuilder;
use Core\Contact\VCardVariant;
use Core\Member\MemberAddress;
use PHPUnit\Framework\TestCase;

/**
 * The rendered card: its properties, its escaping, its folding, and above
 * all what it never contains.
 */
class VCardBuilderTest extends TestCase
{
    private VCardBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new VCardBuilder();
    }

    /**
     * @param list<ContactAffiliation> $affiliations
     */
    private function card(
        array $affiliations = [],
        ?string $photoJpeg = null,
        ?string $totem = 'Loutre Rieuse'
    ): ContactCard {
        return new ContactCard(
            memberId: 42,
            firstName: 'Jean',
            lastName: 'Dupont',
            totem: $totem,
            unitName: '15e Unité Saint-Michel',
            sectionName: 'Louveteaux',
            title: 'Animateur',
            emails: ['jean.dupont@example.org', 'autre@example.org'],
            phones: ['+32 81 12 34 56', '+32 475 12 34 56'],
            addresses: [new MemberAddress(
                type: 'Adresse principale',
                street: 'Rue de la Station',
                number: '12',
                box: 'B',
                complement: 'Bâtiment A',
                postalCode: '5000',
                city: 'Namur',
                country: 'Belgique'
            )],
            scoutYearLabel: '2025-2026',
            affiliations: $affiliations,
            revision: new \DateTimeImmutable('2026-03-04 10:11:12', new \DateTimeZone('UTC')),
            photoJpeg: $photoJpeg
        );
    }

    /**
     * Unfolded, for assertions: a reader of a vCard strips CRLF + space
     * before it looks at anything, and so does this.
     */
    private function unfold(string $vcard): string
    {
        return str_replace("\r\n ", '', $vcard);
    }

    public function testCarriesTheIdentityProperties(): void
    {
        $vcard = $this->unfold($this->builder->build($this->card(), VCardVariant::Full));

        $this->assertStringStartsWith("BEGIN:VCARD\r\nVERSION:3.0\r\n", $vcard);
        $this->assertStringEndsWith("END:VCARD\r\n", $vcard);
        $this->assertStringContainsString("\r\nUID:scoutmagic-member-42\r\n", $vcard);
        $this->assertStringContainsString("\r\nFN:Jean Dupont\r\n", $vcard);
        $this->assertStringContainsString("\r\nN:Dupont;Jean;;;\r\n", $vcard);
        $this->assertStringContainsString("\r\nNICKNAME:Loutre Rieuse\r\n", $vcard);
        $this->assertStringContainsString("\r\nTITLE:Animateur\r\n", $vcard);
        $this->assertStringContainsString("\r\nREV:2026-03-04T10:11:12Z\r\n", $vcard);
    }

    /**
     * FN is the full name — never the totem alone, which is what
     * MemberProfile::getDisplayName() answers and what a card built from
     * it would have carried. A contact filed as « Loutre Rieuse » is a
     * contact nobody finds.
     */
    public function testFormattedNameIsNeverTheTotem(): void
    {
        $vcard = $this->unfold($this->builder->build($this->card(), VCardVariant::Full));

        $this->assertStringContainsString("\r\nFN:Jean Dupont\r\n", $vcard);
        $this->assertStringNotContainsString("\r\nFN:Loutre Rieuse\r\n", $vcard);
    }

    public function testWithoutATotemThereIsNoNicknameProperty(): void
    {
        $vcard = $this->unfold($this->builder->build($this->card(totem: null), VCardVariant::Full));

        $this->assertStringNotContainsString('NICKNAME', $vcard);
    }

    public function testOrganisationIsTheUnitThenTheSection(): void
    {
        $vcard = $this->unfold($this->builder->build($this->card(), VCardVariant::Full));

        $this->assertStringContainsString("\r\nORG:15e Unité Saint-Michel;Louveteaux\r\n", $vcard);
    }

    public function testEveryEmailAddressIsCarried(): void
    {
        $vcard = $this->unfold($this->builder->build($this->card(), VCardVariant::Full));

        $this->assertStringContainsString("\r\nEMAIL;TYPE=INTERNET:jean.dupont@example.org\r\n", $vcard);
        $this->assertStringContainsString("\r\nEMAIL;TYPE=INTERNET:autre@example.org\r\n", $vcard);
    }

    /**
     * The screen labels the two numbers « Tél. parent 1 » and « Tél. parent
     * 2 »; the card labels them nothing at all. That mismatch is a decision
     * of the requester — this test is what stops somebody "fixing" it.
     */
    public function testBothPhoneNumbersAreUnlabelled(): void
    {
        $vcard = $this->unfold($this->builder->build($this->card(), VCardVariant::Full));

        $this->assertStringContainsString("\r\nTEL:+32 81 12 34 56\r\n", $vcard);
        $this->assertStringContainsString("\r\nTEL:+32 475 12 34 56\r\n", $vcard);
        $this->assertStringNotContainsString('TEL;', $vcard);
        $this->assertStringNotContainsString('parent', $vcard);
    }

    public function testTheAddressIsDecomposedIntoItsVcardComponents(): void
    {
        $vcard = $this->unfold($this->builder->build($this->card(), VCardVariant::Full));

        $this->assertStringContainsString(
            "\r\nADR;TYPE=HOME:;Bâtiment A;Rue de la Station 12 bte B;Namur;;5000;Belgique\r\n",
            $vcard
        );
    }

    public function testTheNoteOpensOnTheCurrentScoutYearThenListsTheHistory(): void
    {
        $vcard = $this->unfold($this->builder->build($this->card([
            new ContactAffiliation('2025-2026', [['function' => 'Animateur', 'section' => 'Louveteaux']]),
            new ContactAffiliation('2024-2025', [['function' => 'Trésorier', 'section' => null]]),
        ]), VCardVariant::Full));

        $this->assertStringContainsString(
            "\r\nNOTE:Année scoute 2025-2026\\n2025-2026 · Animateur · Louveteaux\\n2024-2025 · Trésorier\r\n",
            $vcard
        );
    }

    /**
     * Five affiliations at most in the QR code, all of them in the file —
     * and the QR code says nothing about having been cut, deliberately.
     */
    public function testTheQrVariantKeepsFiveAffiliationsAndSaysNothingAboutIt(): void
    {
        $affiliations = [];
        for ($year = 2026; $year >= 2018; $year--) {
            $affiliations[] = new ContactAffiliation(
                ($year - 1) . '-' . $year,
                [['function' => 'Animateur', 'section' => 'Louveteaux']]
            );
        }

        $qr = $this->unfold($this->builder->build($this->card($affiliations), VCardVariant::Qr));
        $full = $this->unfold($this->builder->build($this->card($affiliations), VCardVariant::Full));

        $this->assertSame(5, substr_count($qr, '· Animateur · Louveteaux'));
        $this->assertSame(9, substr_count($full, '· Animateur · Louveteaux'));
        $this->assertStringNotContainsStringIgnoringCase('tronqu', $qr);
    }

    public function testThePhotoTravelsInTheFileAndNeverInTheQrCode(): void
    {
        $jpeg = "\xFF\xD8\xFF\xE0 not really a jpeg, but bytes are bytes";

        $full = $this->unfold($this->builder->build($this->card(photoJpeg: $jpeg), VCardVariant::Full));
        $qr = $this->unfold($this->builder->build($this->card(photoJpeg: $jpeg), VCardVariant::Qr));

        $this->assertStringContainsString('PHOTO;ENCODING=b;TYPE=JPEG:' . base64_encode($jpeg), $full);
        $this->assertStringNotContainsString('PHOTO', $qr);
    }

    /**
     * UID and REV are the two properties a client uses to recognise a card
     * it already holds, so they must be identical in both variants —
     * otherwise scanning then downloading files the same person twice.
     */
    public function testUidAndRevisionAreIdenticalInBothVariants(): void
    {
        $qr = $this->unfold($this->builder->build($this->card(), VCardVariant::Qr));
        $full = $this->unfold($this->builder->build($this->card(), VCardVariant::Full));

        foreach (["UID:scoutmagic-member-42", 'REV:2026-03-04T10:11:12Z'] as $property) {
            $this->assertStringContainsString($property, $qr);
            $this->assertStringContainsString($property, $full);
        }
    }

    public function testSeparatorsInsideAValueAreEscaped(): void
    {
        $card = new ContactCard(
            memberId: 7,
            firstName: 'Jean, dit "Jeannot"',
            lastName: 'De La Croix; Sr',
            totem: null,
            unitName: 'Unité\\Test',
            sectionName: null,
            title: null,
            emails: [],
            phones: [],
            addresses: [],
            scoutYearLabel: '2025-2026',
            affiliations: [],
            revision: new \DateTimeImmutable('2026-01-01 00:00:00', new \DateTimeZone('UTC'))
        );

        $vcard = $this->unfold($this->builder->build($card, VCardVariant::Full));

        $this->assertStringContainsString('FN:Jean\\, dit "Jeannot" De La Croix\; Sr', $vcard);
        $this->assertStringContainsString('ORG:Unité\\\\Test', $vcard);
    }

    /**
     * RFC 2426 § 2.6. A PHOTO line is thousands of octets long, and an
     * unfolded one is refused outright by several address books.
     */
    public function testLongLinesAreFoldedAndUnfoldBackToTheirValue(): void
    {
        $jpeg = random_bytes(4000);
        $vcard = $this->builder->build($this->card(photoJpeg: $jpeg), VCardVariant::Full);

        foreach (explode("\r\n", $vcard) as $line) {
            $this->assertLessThanOrEqual(75, strlen($line), 'A folded line stays within 75 octets.');
        }
        $this->assertStringContainsString(
            'PHOTO;ENCODING=b;TYPE=JPEG:' . base64_encode($jpeg),
            $this->unfold($vcard)
        );
    }

    /**
     * Folding counts octets, and a continuation that starts in the middle
     * of a UTF-8 sequence is what makes a reader render « Ã© ».
     */
    public function testFoldingNeverSplitsAMultiByteCharacter(): void
    {
        $card = new ContactCard(
            memberId: 1,
            firstName: 'Éléonore',
            lastName: str_repeat('Éàü', 40),
            totem: null,
            unitName: 'Unité',
            sectionName: null,
            title: null,
            emails: [],
            phones: [],
            addresses: [],
            scoutYearLabel: '2025-2026',
            affiliations: [],
            revision: new \DateTimeImmutable('2026-01-01 00:00:00', new \DateTimeZone('UTC'))
        );

        $vcard = $this->builder->build($card, VCardVariant::Full);

        foreach (explode("\r\n", $vcard) as $line) {
            $this->assertTrue(
                mb_check_encoding($line, 'UTF-8'),
                'Every folded line is valid UTF-8 on its own.'
            );
        }
    }

    /**
     * **The test this whole feature exists to keep.** The handicap is
     * health data (GDPR special category) sitting one line away from the
     * phone numbers in the same MemberProfile and on the same screen; the
     * rest of this list is what `docs/chantiers/CHANTIER-contact-membre-
     * carddav.md` § « Ce qui ne sort jamais » enumerates. None of it is a
     * property of ContactCard, so none of it can reach a card — and this
     * is what says so out loud.
     */
    public function testNothingFromTheRestOfTheMemberRecordCanReachACard(): void
    {
        $vcard = $this->builder->build($this->card([
            new ContactAffiliation('2025-2026', [['function' => 'Animateur', 'section' => 'Louveteaux']]),
        ], photoJpeg: 'x'), VCardVariant::Full);

        $this->assertSame([], array_values(array_filter(
            ['HANDICAP', 'GENDER', 'BDAY', 'ANNIVERSARY', 'X-DESK-ID', 'ROLE;PATROL', 'X-CONSENT'],
            static fn(string $property): bool => str_contains($vcard, $property)
        )));

        // The same statement from the other side: the class has no place
        // to put any of them.
        $properties = array_map(
            static fn(\ReflectionProperty $p): string => $p->getName(),
            (new \ReflectionClass(ContactCard::class))->getProperties()
        );
        foreach (
            ['handicap', 'supplementaryInsurance', 'gender', 'birthDate', 'deskId',
                'patrol', 'formationLevel', 'federationMailConsent', 'unitMailConsent'] as $forbidden
        ) {
            $this->assertNotContains($forbidden, $properties);
        }
    }
}
