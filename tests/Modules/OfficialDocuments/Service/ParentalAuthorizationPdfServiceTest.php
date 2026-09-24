<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\OfficialDocuments\Service;

use Core\Member\MemberFunctionInfo;
use Core\Member\MemberProfile;
use Modules\OfficialDocuments\Api\OfficialDocumentsException;
use Modules\OfficialDocuments\Pdf\TemplateLibrary;
use Modules\OfficialDocuments\Service\ParentalAuthorizationInput;
use Modules\OfficialDocuments\Service\ParentalAuthorizationPdfService;
use Modules\OfficialDocuments\Service\SignatoryCapacity;
use PHPUnit\Framework\TestCase;

/**
 * The assembly: FPDI really does import the shipped template, tFPDF really
 * does write UTF-8 over it, and the result really does come back as bytes
 * rather than as a file somewhere.
 *
 * What the drawing SAYS is `ParentalAuthorizationFillingTest`'s business;
 * this is about the engine. Two of these checks would each have cost a
 * season: a template FPDI cannot parse (the whole reason the files are
 * converted before being committed) and a name outside cp1252, which is
 * exactly what FPDF would have mangled and what tFPDF is here for.
 */
final class ParentalAuthorizationPdfServiceTest extends TestCase
{
    private static function member(string $firstName = 'Loup', string $lastName = 'Dubois'): MemberProfile
    {
        return new MemberProfile(
            memberYearId: 7,
            memberId: 42,
            deskId: 'D42',
            firstName: $firstName,
            lastName: $lastName,
            totem: null,
            quali: null,
            gender: null,
            birthDate: null,
            phone: null,
            mobile: null,
            email: null,
            patrol: null,
            formationLevel: null,
            federationMailConsent: false,
            unitMailConsent: false,
            addresses: [],
            functions: [new MemberFunctionInfo('Animé', 'identified', 'Louveteaux', 'Meute', 'MEU', true, null, null)],
            scoutYearLabel: '2026-2027'
        );
    }

    private static function input(string $signatory = 'Xavier Dubois', string $place = 'Verviers'): ParentalAuthorizationInput
    {
        return ParentalAuthorizationInput::of(
            $signatory,
            SignatoryCapacity::Father,
            new \DateTimeImmutable('2026-11-14'),
            new \DateTimeImmutable('2026-11-16'),
            $place
        );
    }

    private static function service(): ParentalAuthorizationPdfService
    {
        return new ParentalAuthorizationPdfService(TemplateLibrary::shipped());
    }

    public function testItProducesAPdfFromTheShippedTemplate(): void
    {
        $result = self::service()->render(
            self::member(),
            null,
            'LgVI/25 — 25e SV',
            self::input(),
            new \DateTimeImmutable('2026-09-20')
        );

        $this->assertStringStartsWith('%PDF-', $result['pdf']);
        // The federation's own page is in there, so the output is
        // substantially larger than the few hundred bytes an empty document
        // would be — a template that failed to import would still produce a
        // valid, and entirely blank, PDF.
        $this->assertGreaterThan(100_000, strlen($result['pdf']));
        $this->assertSame([], $result['overflowing']);
    }

    /**
     * The one reason this module uses tFPDF rather than the FPDF the
     * attestations module splits pages with: a name outside cp1252 must not
     * come out mangled — or take the generation down — on a document a
     * family is asked to sign.
     */
    public function testANameOutsideCp1252IsWrittenRatherThanMangled(): void
    {
        $result = self::service()->render(
            self::member('Bartosz', 'Wiśniewski'),
            null,
            '25e SV',
            self::input('Ayşe Gül Çetin', 'Wavre-Sainte-Cathérine'),
            new \DateTimeImmutable('2026-09-20')
        );

        $this->assertStringStartsWith('%PDF-', $result['pdf']);
        $this->assertSame([], $result['overflowing']);
    }

    /**
     * Nothing is written to disk — the chantier's rule, and the one that
     * matters on shared hosting where a temporary file is a file somebody
     * else's process can read.
     *
     * What is asserted is that no entry APPEARED. The temporary directory
     * is shared with every other process on the machine, and comparing it
     * whole failed the CI whenever the runner removed one of its own files
     * (`runc-process…`) while this test ran: a file somebody else deleted
     * says nothing about what the renderer wrote.
     */
    public function testNothingIsWrittenToDisk(): void
    {
        $before = self::temporaryFiles();

        self::service()->render(
            self::member(),
            null,
            '25e SV',
            self::input(),
            new \DateTimeImmutable('2026-09-20')
        );

        $this->assertSame([], array_values(array_diff(self::temporaryFiles(), $before)));
    }

    /**
     * A value too long for its printed line is still written — a cramped
     * line beats a blank one — and the caller is TOLD, so the screen can say
     * so instead of letting the parent find out on paper.
     */
    public function testAValueTooLongForItsLineIsReportedRatherThanSilentlyCut(): void
    {
        $result = self::service()->render(
            self::member(),
            null,
            '25e SV',
            self::input(place: str_repeat('Braine-l\'Alleud ', 20)),
            new \DateTimeImmutable('2026-09-20')
        );

        $this->assertContains('place', $result['overflowing']);
        $this->assertStringStartsWith('%PDF-', $result['pdf'], 'the document must still be produced');
    }

    /**
     * The premise `ParentalAuthorizationControllerTest` rests on: a value
     * the site supplies overflows through the very same path as one the
     * parent typed, and is reported by its own name.
     *
     * Without this, the controller test that proves a site-derived overflow
     * still yields a PDF could pass for the wrong reason — because nothing
     * overflowed at all.
     */
    public function testAValueTheSiteSuppliesOverflowsUnderItsOwnName(): void
    {
        $result = self::service()->render(
            self::member(),
            null,
            str_repeat('Unité de Braine-l\'Alleud ', 10),
            self::input(),
            new \DateTimeImmutable('2026-09-20')
        );

        $this->assertContains('unit', $result['overflowing']);
        $this->assertNotContains(
            'unit',
            \Modules\OfficialDocuments\Service\ParentalAuthorizationFilling::PARENT_EDITABLE,
            'a unit code is not something a parent can shorten'
        );
    }

    /**
     * A missing template is a French sentence, never an FPDI stack trace
     * about a path — this message is shown to a parent verbatim.
     */
    public function testAMissingTemplateIsAFrenchSentence(): void
    {
        $service = new ParentalAuthorizationPdfService(
            new TemplateLibrary(sys_get_temp_dir() . '/official-documents-absent-' . uniqid())
        );

        $this->expectException(OfficialDocumentsException::class);
        $this->expectExceptionMessage('Le formulaire officiel est introuvable sur ce site.');

        $service->render(
            self::member(),
            null,
            '25e SV',
            self::input(),
            new \DateTimeImmutable('2026-09-20')
        );
    }

    /**
     * And a file that is there but is not a PDF FPDI can read — which is
     * what a template replaced without being converted looks like — is the
     * same kind of sentence, with the library's own message left in
     * `$previous` where it belongs.
     */
    public function testAnUnreadableTemplateIsAFrenchSentenceToo(): void
    {
        $directory = sys_get_temp_dir() . '/official-documents-broken-' . uniqid();
        mkdir($directory);
        file_put_contents($directory . '/' . TemplateLibrary::PARENTAL_AUTHORIZATION, 'ceci n\'est pas un PDF');

        try {
            $service = new ParentalAuthorizationPdfService(new TemplateLibrary($directory));

            $this->expectException(OfficialDocumentsException::class);
            $this->expectExceptionMessage('Le formulaire officiel n\'a pas pu être ouvert.');

            $service->render(
                self::member(),
                null,
                '25e SV',
                self::input(),
                new \DateTimeImmutable('2026-09-20')
            );
        } finally {
            @unlink($directory . '/' . TemplateLibrary::PARENTAL_AUTHORIZATION);
            @rmdir($directory);
        }
    }

    /**
     * @return list<string>
     */
    private static function temporaryFiles(): array
    {
        $entries = scandir(sys_get_temp_dir());
        $entries = $entries === false ? [] : $entries;
        sort($entries);

        return array_values($entries);
    }
}
