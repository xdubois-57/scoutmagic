<?php

declare(strict_types=1);

namespace Tests\Core\View;

use Core\Config\AppClock;
use Core\View\EditableContentService;
use Core\View\TwigFactory;
use PHPUnit\Framework\TestCase;
use Twig\Environment;

class TwigFactoryTest extends TestCase
{
    public function testFrenchDateFormatsAStringDate(): void
    {
        $twig = TwigFactory::create(dirname(__DIR__, 3) . '/core/View/templates');
        $twig->getLoader()->exists('base.html.twig');

        $env = $twig;
        $filter = null;
        foreach ($env->getFilters() as $f) {
            if ($f->getName() === 'french_date') {
                $filter = $f;
            }
        }

        $this->assertNotNull($filter);
        $callable = $filter->getCallable();
        $this->assertSame('12 juillet 2026', $callable('2026-07-12'));
        $this->assertSame('1 janvier 2026', $callable(new \DateTimeImmutable('2026-01-01')));
        $this->assertSame('', $callable(null));
        $this->assertSame('31 décembre 2026', $callable('2026-12-31 10:30:00'));
    }

    private function relativeDateFilter(Environment $twig): callable
    {
        foreach ($twig->getFilters() as $f) {
            if ($f->getName() === 'relative_date') {
                return $f->getCallable();
            }
        }
        $this->fail('relative_date filter not registered.');
    }

    /**
     * A stored timestamp is a naive DATETIME on the application clock
     * (Core\Config\AppClock) — both the PHP writers and the database's own
     * CURRENT_TIMESTAMP put it there. Reading it under any other zone
     * (this filter used to force UTC, from when the whole app ran on UTC)
     * puts every age out by the offset, which is what this pins down: a
     * value written two hours ago must read "il y a 2 heures" and not
     * "il y a 4 heures".
     */
    public function testRelativeDateReadsStoredTimestampsOnTheApplicationClock(): void
    {
        $twig = TwigFactory::create(dirname(__DIR__, 3) . '/core/View/templates');
        $filter = $this->relativeDateFilter($twig);
        $twoHoursAgo = AppClock::now()->modify('-2 hours')->format('Y-m-d H:i:s');

        $this->assertSame('il y a 2 heures', $filter($twoHoursAgo));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function relativeAges(): iterable
    {
        yield 'seconds' => ['-30 seconds', "à l'instant"];
        yield 'one minute' => ['-1 minute', 'il y a 1 minute'];
        yield 'minutes' => ['-5 minutes', 'il y a 5 minutes'];
        yield 'one hour' => ['-1 hour', 'il y a 1 heure'];
        yield 'one day' => ['-1 day', 'il y a 1 jour'];
        yield 'days' => ['-3 days', 'il y a 3 jours'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('relativeAges')]
    public function testRelativeDateRendersCoarseFrenchAges(string $offset, string $expected): void
    {
        $twig = TwigFactory::create(dirname(__DIR__, 3) . '/core/View/templates');
        $stored = AppClock::now()->modify($offset)->format('Y-m-d H:i:s');

        $this->assertSame($expected, $this->relativeDateFilter($twig)($stored));
    }

    /**
     * Past a week "il y a 23 jours" stops being easier to read than the
     * date itself, so the filter hands over to french_date.
     */
    public function testRelativeDateFallsBackToTheAbsoluteDatePastAWeek(): void
    {
        $twig = TwigFactory::create(dirname(__DIR__, 3) . '/core/View/templates');

        $this->assertSame('le 12 juillet 2020', $this->relativeDateFilter($twig)('2020-07-12 08:00:00'));
    }

    /**
     * A clock skew (or a timestamp a second into the future) must read as
     * "just now", never as a negative age.
     */
    public function testRelativeDateHandlesAFutureTimestampWithoutGoingNegative(): void
    {
        $twig = TwigFactory::create(dirname(__DIR__, 3) . '/core/View/templates');
        $future = AppClock::now()->modify('+2 minutes')->format('Y-m-d H:i:s');

        $this->assertSame("à l'instant", $this->relativeDateFilter($twig)($future));
    }

    public function testRelativeDateIsEmptyForNoDate(): void
    {
        $twig = TwigFactory::create(dirname(__DIR__, 3) . '/core/View/templates');

        $this->assertSame('', $this->relativeDateFilter($twig)(null));
        $this->assertSame('', $this->relativeDateFilter($twig)(''));
    }

    private function fullNameFilter(Environment $twig): callable
    {
        foreach ($twig->getFilters() as $f) {
            if ($f->getName() === 'full_name') {
                return $f->getCallable();
            }
        }
        $this->fail('full_name filter not registered.');
    }

    /**
     * full_name deliberately ignores totem, unlike display_name — it exists
     * for the rare case (member page responsable) where the site must show
     * a person's legal first+last name.
     */
    public function testFullNameUsesFirstAndLastNameNeverTotem(): void
    {
        $twig = TwigFactory::create(dirname(__DIR__, 3) . '/core/View/templates');
        $member = new \Core\Member\MemberProfile(
            memberYearId: 1,
            memberId: 1,
            deskId: 'x',
            firstName: 'jean-pierre',
            lastName: 'DE SMET',
            totem: 'Aigle',
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
            functions: [],
            scoutYearLabel: '2025-2026'
        );

        $result = ($this->fullNameFilter($twig))($member);

        $this->assertSame('Jean-Pierre De Smet', $result);
        $this->assertStringNotContainsString('Aigle', $result);
    }

    public function testFullNameHandlesArrayInput(): void
    {
        $twig = TwigFactory::create(dirname(__DIR__, 3) . '/core/View/templates');

        $result = ($this->fullNameFilter($twig))(['first_name' => 'marie', 'last_name' => 'dupont']);

        $this->assertSame('Marie Dupont', $result);
    }

    private function displayNameFullFilter(Environment $twig): callable
    {
        foreach ($twig->getFilters() as $f) {
            if ($f->getName() === 'display_name_full') {
                return $f->getCallable();
            }
        }
        $this->fail('display_name_full filter not registered.');
    }

    public function testDisplayNameFullShowsTotemWithFullNameInParentheses(): void
    {
        $twig = TwigFactory::create(dirname(__DIR__, 3) . '/core/View/templates');
        $member = new \Core\Member\MemberProfile(
            memberYearId: 1, memberId: 1, deskId: 'x', firstName: 'jean', lastName: 'dupont',
            totem: 'baloo', quali: null, gender: null, birthDate: null, phone: null, mobile: null,
            email: null, patrol: null, formationLevel: null, federationMailConsent: false,
            unitMailConsent: false, addresses: [], functions: [], scoutYearLabel: '2025-2026'
        );

        $result = ($this->displayNameFullFilter($twig))($member);

        $this->assertSame('Baloo (Jean Dupont)', $result);
    }

    public function testDisplayNameFullFallsBackToFullNameWhenNoTotem(): void
    {
        $twig = TwigFactory::create(dirname(__DIR__, 3) . '/core/View/templates');
        $member = new \Core\Member\MemberProfile(
            memberYearId: 1, memberId: 1, deskId: 'x', firstName: 'jean', lastName: 'dupont',
            totem: null, quali: null, gender: null, birthDate: null, phone: null, mobile: null,
            email: null, patrol: null, formationLevel: null, federationMailConsent: false,
            unitMailConsent: false, addresses: [], functions: [], scoutYearLabel: '2025-2026'
        );

        $result = ($this->displayNameFullFilter($twig))($member);

        $this->assertSame('Jean Dupont', $result);
    }

    private function memberPhotoFunction(Environment $twig): callable
    {
        foreach ($twig->getFunctions() as $f) {
            if ($f->getName() === 'member_photo') {
                return $f->getCallable();
            }
        }
        $this->fail('member_photo() function not registered.');
    }

    /**
     * A member_photo() upload must always land on the CURRENT scout year,
     * even when the displayed photo came from MemberPhotoService's own
     * fallback to an earlier year — the editable overlay's data-key is
     * built from effective_scout_year_id (the site's current year), never
     * from whatever year the fallback actually resolved the photo from.
     */
    public function testMemberPhotoEditableKeyUsesCurrentYearEvenWithAnEarlierYearFallback(): void
    {
        $twig = TwigFactory::create(dirname(__DIR__, 3) . '/core/View/templates');
        $service = $this->createMock(\Core\Photo\MemberPhotoService::class);
        // Simulates a fallback: the service is only ever asked for the
        // current year (999) but returns a file that actually lives in an
        // earlier year internally — the point is that the *key* must still
        // use 999, not whatever year the file was originally uploaded in.
        $service->method('resolveFileId')->with(42, 999)->willReturn(7);
        $twig->addGlobal('_member_photo_service', $service);
        $twig->addGlobal('effective_scout_year_id', 999);
        $twig->addGlobal('config_mode', false);

        $html = ($this->memberPhotoFunction($twig))(42, 'Jean', 'rounded-circle', true);

        $this->assertStringContainsString('data-key="42:999"', $html);
    }

    public function testMemberPhotoIsNotEditableWithoutTheEditableFlagOutsideConfigMode(): void
    {
        $twig = TwigFactory::create(dirname(__DIR__, 3) . '/core/View/templates');
        $service = $this->createMock(\Core\Photo\MemberPhotoService::class);
        $service->method('resolveFileId')->willReturn(7);
        $twig->addGlobal('_member_photo_service', $service);
        $twig->addGlobal('effective_scout_year_id', 999);
        $twig->addGlobal('config_mode', false);

        $html = ($this->memberPhotoFunction($twig))(42, 'Jean', 'rounded-circle', false);

        $this->assertStringNotContainsString('editable-image', $html);
    }

    /**
     * The trombinoscope and rosters render one member_photo() per member,
     * each /files/… request a full application boot — only the visible
     * ones should fire.
     */
    public function testMemberPhotoIsLazyLoaded(): void
    {
        $twig = TwigFactory::create(dirname(__DIR__, 3) . '/core/View/templates');
        $service = $this->createMock(\Core\Photo\MemberPhotoService::class);
        $service->method('resolveFileId')->willReturn(7);
        $twig->addGlobal('_member_photo_service', $service);
        $twig->addGlobal('effective_scout_year_id', 999);
        $twig->addGlobal('config_mode', false);

        $html = ($this->memberPhotoFunction($twig))(42, 'Jean');

        $this->assertStringContainsString('loading="lazy"', $html);
        $this->assertStringContainsString('decoding="async"', $html);
    }

    private function editableImageFunction(Environment $twig): callable
    {
        foreach ($twig->getFunctions() as $f) {
            if ($f->getName() === 'editable_image') {
                return $f->getCallable();
            }
        }
        $this->fail('editable_image() function not registered.');
    }

    /**
     * Reproduces a real bug: with no image set yet, editable_image()
     * rendered a bare placeholder box with no .editable-edit-btn at all —
     * editable.js only wires up clicks on that class, so clicking the
     * placeholder did nothing. It must render the same overlay/button as
     * the "already has an image" case (and as member_photo()/
     * section_photo()'s own empty states already correctly do).
     */
    public function testEditableImageRendersAClickableButtonWhenNoImageIsSetYet(): void
    {
        $twig = TwigFactory::create(dirname(__DIR__, 3) . '/core/View/templates');
        $service = $this->createMock(EditableContentService::class);
        $service->method('get')->willReturn(null);
        $twig->addGlobal('_editable_content_service', $service);
        $twig->addGlobal('config_mode', true);

        $html = ($this->editableImageFunction($twig))('home.hero', 'Photo d\'accueil');

        $this->assertStringContainsString('editable-edit-btn', $html);
        $this->assertStringContainsString('Ajouter', $html);
        $this->assertStringContainsString('data-key="home.hero"', $html);
    }

    public function testEditableImageRendersAChangerButtonWhenAnImageAlreadyExists(): void
    {
        $twig = TwigFactory::create(dirname(__DIR__, 3) . '/core/View/templates');
        $service = $this->createMock(EditableContentService::class);
        $service->method('get')->willReturn('42');
        $twig->addGlobal('_editable_content_service', $service);
        $twig->addGlobal('config_mode', true);

        $html = ($this->editableImageFunction($twig))('home.hero', 'Photo d\'accueil');

        $this->assertStringContainsString('editable-edit-btn', $html);
        $this->assertStringContainsString('Changer', $html);
        $this->assertStringContainsString('/files/42', $html);
    }

    public function testEditableImageRendersNothingOutsideConfigModeWithNoImage(): void
    {
        $twig = TwigFactory::create(dirname(__DIR__, 3) . '/core/View/templates');
        $service = $this->createMock(EditableContentService::class);
        $service->method('get')->willReturn(null);
        $twig->addGlobal('_editable_content_service', $service);
        $twig->addGlobal('config_mode', false);

        $html = ($this->editableImageFunction($twig))('home.hero');

        $this->assertSame('', $html);
    }
}
