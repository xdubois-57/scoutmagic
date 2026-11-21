<?php

declare(strict_types=1);

namespace Tests\Core\Photo;

use Core\Photo\PersonPhotoOwnershipChecker;
use Core\Security\Role;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Who may be served what of a person's photo (#1108, #1265).
 *
 * A stranger — a signed-in account with no link to the person — gets the
 * thumbnail that lists and avatars draw, and nothing else: the list of what
 * is let through is CLOSED, so a rendition added tomorrow (a sharper `md`
 * for a larger display) follows the rule of the original until somebody
 * decides otherwise, instead of being readable by every account by default.
 */
final class PersonPhotoOwnershipCheckerTest extends TestCase
{
    public function testAStrangerMayHaveTheThumbnail(): void
    {
        $this->assertTrue(PersonPhotoOwnershipChecker::forMemberPhotos()->allowsReducedRendition(42, 'thumb'));
        $this->assertTrue(PersonPhotoOwnershipChecker::forAccountPhotos(3)->allowsReducedRendition(99, 'thumb'));
    }

    /** @return iterable<string, array{string}> */
    public static function otherRenditions(): iterable
    {
        yield 'the medium one' => ['md'];
        yield 'a future one' => ['lg'];
        yield 'the original, spelled out' => ['original'];
        yield 'empty' => [''];
        yield 'a different case' => ['THUMB'];
        yield 'with padding' => [' thumb'];
    }

    #[DataProvider('otherRenditions')]
    public function testNothingButTheThumbnailIsLetThroughOnTheFloorAlone(string $rendition): void
    {
        $this->assertFalse(PersonPhotoOwnershipChecker::forMemberPhotos()->allowsReducedRendition(42, $rendition));
        $this->assertFalse(PersonPhotoOwnershipChecker::forAccountPhotos(3)->allowsReducedRendition(99, $rendition));
    }

    /** What a rendition the floor does not let through falls back to: the original's rule. */
    public function testTheOriginalsRuleStillDecidesForEveryoneElse(): void
    {
        $members = PersonPhotoOwnershipChecker::forMemberPhotos();
        $accounts = PersonPhotoOwnershipChecker::forAccountPhotos(99);

        $this->assertTrue($members->isAllowed(42, Role::IDENTIFIED, [42]));
        $this->assertFalse($members->isAllowed(42, Role::IDENTIFIED, [7]));
        $this->assertTrue($members->isAllowed(42, Role::CHIEF, []));
        $this->assertTrue($accounts->isAllowed(99, Role::IDENTIFIED, []));
        $this->assertFalse(PersonPhotoOwnershipChecker::forAccountPhotos(3)->isAllowed(99, Role::IDENTIFIED, []));
    }
}
