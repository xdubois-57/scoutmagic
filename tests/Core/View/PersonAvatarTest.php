<?php

declare(strict_types=1);

namespace Tests\Core\View;

use Core\View\PersonAvatar;
use PHPUnit\Framework\TestCase;

/**
 * The one way this site draws a person (Core\View\PersonAvatar) — the
 * initials rule and the shape of what it renders, without a Twig
 * environment or a database.
 */
class PersonAvatarTest extends TestCase
{
    public function testAFirstNameAndASurnameGiveOneLetterEach(): void
    {
        $this->assertSame('MD', PersonAvatar::initials('Marie Dupont'));
    }

    /**
     * A totem is one word and is what most animés are called — two
     * letters of it, rather than one letter and a blank.
     */
    public function testASingleWordGivesItsFirstTwoLetters(): void
    {
        $this->assertSame('AK', PersonAvatar::initials('Akéla'));
    }

    public function testAMiddleNameIsSkippedInFavourOfTheSurname(): void
    {
        $this->assertSame('MD', PersonAvatar::initials('Marie Claire Dupont'));
    }

    /**
     * Accents are the normal case here, not an edge one: a non-multibyte
     * upper-casing mangles them into something nobody writes.
     */
    public function testAccentsSurviveTheUpperCasing(): void
    {
        $this->assertSame('ÉD', PersonAvatar::initials('Éloïse Dupont'));
    }

    public function testAnEmptyNameStillDrawsSomething(): void
    {
        $this->assertSame('?', PersonAvatar::initials('   '));
    }

    public function testWithoutAPhotoItDrawsInitialsInACircle(): void
    {
        $html = PersonAvatar::render('Marie Dupont', null, 40);

        $this->assertStringContainsString('person-avatar-initials', $html);
        $this->assertStringContainsString('MD', $html);
        $this->assertStringContainsString('width:40px;height:40px;', $html);
        $this->assertStringNotContainsString('<img', $html);
    }

    public function testWithAPhotoItDrawsTheThumbnailAndNamesItForScreenReaders(): void
    {
        $html = PersonAvatar::render('Marie Dupont', 77, 32);

        $this->assertStringContainsString('src="/files/77/thumb"', $html);
        $this->assertStringContainsString('alt="Marie Dupont"', $html);
        $this->assertStringContainsString('rounded-circle', $html);
    }

    /**
     * The name is somebody's own, and it lands in an attribute: escaped,
     * like every other piece of personal data this codebase renders.
     */
    public function testTheNameIsEscapedIntoTheMarkup(): void
    {
        $html = PersonAvatar::render('Marie "La Grande" <Dupont>', 5);

        $this->assertStringNotContainsString('<Dupont>', $html);
        $this->assertStringContainsString('&lt;Dupont&gt;', $html);
    }

    /**
     * The overlay is what editable.js binds to — the same wrapper
     * member_photo()/section_photo() already use, so a new avatar needed
     * no JavaScript of its own.
     */
    public function testTheEditableVariantCarriesTheUploadContextAndKey(): void
    {
        $html = PersonAvatar::render('Marie Dupont', null, 96, ['context' => 'account_photo', 'key' => '12']);

        $this->assertStringContainsString('class="editable-image', $html);
        $this->assertStringContainsString('data-context="account_photo"', $html);
        $this->assertStringContainsString('data-key="12"', $html);
        $this->assertStringContainsString('editable-edit-btn', $html);
    }

    public function testTheDiameterIsBoundedRatherThanTrusted(): void
    {
        $this->assertStringContainsString('width:16px', PersonAvatar::render('Marie Dupont', null, -40));
        $this->assertStringContainsString('width:256px', PersonAvatar::render('Marie Dupont', null, 4000));
    }

    /**
     * The two letters fill the circle the same way at every size — a
     * fixed font size looks lost in a page header and overflows a menu
     * row.
     */
    public function testTheFontSizeScalesWithTheCircle(): void
    {
        $this->assertStringContainsString('font-size:0.80rem', PersonAvatar::render('Marie Dupont', null, 32));
        $this->assertStringContainsString('font-size:2.40rem', PersonAvatar::render('Marie Dupont', null, 96));
    }
}
