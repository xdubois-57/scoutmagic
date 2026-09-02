<?php

declare(strict_types=1);

namespace Tests\Modules\Trombinoscope\Pdf;

use Modules\Trombinoscope\Pdf\SectionView;
use Modules\Trombinoscope\Pdf\StaffView;
use Modules\Trombinoscope\Pdf\TrombinoscopeHtmlBuilder;
use PHPUnit\Framework\TestCase;

/**
 * The printable document's markup, asserted as a string — the layer below
 * dompdf, so what the document says can be checked without rendering a PDF
 * to look at it.
 */
class TrombinoscopeHtmlBuilderTest extends TestCase
{
    private const LONG_ADDRESS = 'louveteaux-de-la-premiere-unite@ottignies-petit-ry.example.be';

    private function staff(string $totem, string $civil, bool $isLead = false, ?string $photo = null): StaffView
    {
        return new StaffView($totem, $civil, 'AG', $photo, '0496 88 41 20', 'antonin@sv025.be', $isLead);
    }

    /** @param StaffView[] $staff */
    private function section(string $name, string $color = '#198754', ?string $email = 'louveteaux1@sv025.be', array $staff = [], bool $withLead = true): SectionView
    {
        $lead = $withLead && $staff !== [] ? $staff[0] : null;

        return new SectionView($name, 'Louveteaux', $color, $email, $lead, $staff);
    }

    private function build(array $sections, bool $showContacts = true): string
    {
        return (new TrombinoscopeHtmlBuilder())
            ->build($sections, 'Unité SV025', '2025-2026', 'www.sv025.be', $showContacts);
    }

    public function testTheDirectoryPageCarriesOneCardPerSection(): void
    {
        $html = $this->build([
            $this->section('Louveteaux 1', staff: [$this->staff('Chacal', 'Antonin Grandjean', true)]),
            $this->section('Baladins 1', '#0d6efd', 'baladins1@sv025.be', [$this->staff('Alouette', 'Lucie Crijns', true)]),
        ]);

        $this->assertStringContainsString('Qui contacter', $html);
        $this->assertStringContainsString('LOUVETEAUX 1', $html);
        $this->assertStringContainsString('BALADINS 1', $html);
        $this->assertStringContainsString('Chacal', $html);
        $this->assertStringContainsString('Antonin Grandjean', $html);
    }

    public function testASectionWithNoDesignatedLeadKeepsItsCard(): void
    {
        // A visible hole pushes the Staff d'Unité to fix it; leaving the
        // section out would suggest it does not exist.
        $html = $this->build([$this->section('Louveteaux 2', withLead: false)]);

        $this->assertStringContainsString('LOUVETEAUX 2', $html);
        $this->assertStringContainsString('Responsable non désigné', $html);
        $this->assertStringContainsString('dashed', $html);
    }

    public function testTheBranchColourIsAFilledBandAndNeverAThinBorder(): void
    {
        // The one thing a browser would drop when printing, and the whole
        // reason this is a PDF.
        $html = $this->build([$this->section('Louveteaux 1', staff: [$this->staff('Chacal', 'Antonin Grandjean', true)])]);

        $this->assertStringContainsString('border-left:3.4mm solid #198754', $html);
    }

    public function testAColourThatIsNotAColourCannotEscapeItsStyleAttribute(): void
    {
        $html = $this->build([$this->section('Louveteaux 1', '#198754" onload="x')]);

        $this->assertStringNotContainsString('onload', $html);
    }

    public function testEveryAddressSurvivesInFull(): void
    {
        $section = new SectionView(
            'Louveteaux 1',
            'Louveteaux',
            '#198754',
            self::LONG_ADDRESS,
            null,
            []
        );

        $html = $this->build([$section]);

        // Twice: once in the directory footer, once on the section's own
        // page. Never shortened in either — a clipped address is an
        // address nobody can write to.
        $this->assertSame(2, substr_count($html, self::LONG_ADDRESS));
        $this->assertStringContainsString('Écrire à une section', $html);
        $this->assertStringContainsString('Écrire aux Louveteaux 1', $html);
    }

    public function testContactsAreAbsentFromTheMarkupWhenTheSettingIsOff(): void
    {
        // Not merely hidden: filtered before the HTML exists, so there is
        // nothing in the PDF's text layer to recover either.
        $staff = new StaffView('Chacal', 'Antonin Grandjean', 'AG', null, null, null, true);
        $html = $this->build([$this->section('Louveteaux 1', staff: [$staff])], false);

        $this->assertStringNotContainsString('0496 88 41 20', $html);
        $this->assertStringNotContainsString('antonin@sv025.be', $html);
        // The section's own address is organizational and stays.
        $this->assertStringContainsString('louveteaux1@sv025.be', $html);
        $this->assertStringContainsString('coordonnées personnelles masquées par le réglage', $html);
    }

    public function testEverySectionStartsItsOwnPage(): void
    {
        $sections = [
            $this->section('Louveteaux 1', staff: [$this->staff('Chacal', 'Antonin Grandjean', true)]),
            $this->section('Baladins 1', '#0d6efd', 'baladins1@sv025.be'),
            $this->section("Staff d'U", '#7EC8E3', 'unite@sv025.be'),
        ];

        // One break per section and not one more: the directory is page
        // one and opens the document.
        $this->assertSame(3, substr_count($this->build($sections), 'class="page-break"'));
    }

    public function testASectionPageNamesItsSectionItsBranchAndItsHeadcount(): void
    {
        $html = $this->build([$this->section('Louveteaux 1', staff: [
            $this->staff('Chacal', 'Antonin Grandjean', true),
            $this->staff('Ocelot', 'Mattis Wergifosse'),
        ])]);

        $this->assertStringContainsString('Louveteaux · 2 animateurs · 2025-2026', $html);
        $this->assertStringContainsString('Responsable', $html);
    }

    public function testAStaffOfOneIsCountedInTheSingular(): void
    {
        $html = $this->build([$this->section('Louveteaux 1', staff: [$this->staff('Chacal', 'Antonin Grandjean', true)])]);

        $this->assertStringContainsString('1 animateur ·', $html);
    }

    public function testAPhotoIsEmbeddedRatherThanLinked(): void
    {
        // dompdf runs with isRemoteEnabled = false: an <img src> pointing
        // at a URL or a path would render nothing at all.
        $photo = 'data:image/jpeg;base64,AAAA';
        $html = $this->build([$this->section('Louveteaux 1', staff: [$this->staff('Chacal', 'Antonin Grandjean', true, $photo)])]);

        $this->assertStringContainsString('src="' . $photo . '"', $html);
        $this->assertStringNotContainsString('src="/files/', $html);
        $this->assertStringNotContainsString('src="http', $html);
    }

    public function testAVeryLongTotemIsShortenedButAnAddressIsNot(): void
    {
        $staff = new StaffView(
            str_repeat('Kételslegers', 6),
            str_repeat('Van der Smissen ', 6),
            'AG',
            null,
            null,
            self::LONG_ADDRESS,
            false
        );
        $html = $this->build([$this->section('Louveteaux 1', staff: [$staff])]);

        $this->assertStringNotContainsString(str_repeat('Kételslegers', 6), $html);
        $this->assertStringContainsString('…', $html);
        $this->assertStringContainsString(self::LONG_ADDRESS, $html);
    }

    public function testASectionWithNoStaffStillGetsItsPage(): void
    {
        $html = $this->build([$this->section('Louveteaux 2', withLead: false)]);

        $this->assertStringContainsString('Aucun animateur pour cette section cette année.', $html);
    }

    public function testTheLayoutUsesNeitherFlexboxNorGrid(): void
    {
        // dompdf supports neither. The mockup showed the target, not the
        // technique; every arrangement here is a table.
        $html = $this->build([$this->section('Louveteaux 1', staff: [$this->staff('Chacal', 'Antonin Grandjean', true)])]);

        $this->assertStringNotContainsString('display:flex', $html);
        $this->assertStringNotContainsString('display:grid', $html);
        $this->assertStringContainsString('<table', $html);
    }
}
