<?php

declare(strict_types=1);

namespace Tests\Modules\Social\File;

use Core\File\FileIndexingPolicyInterface;
use Core\File\FileOwnershipCheckerInterface;
use Core\Security\Role;
use Modules\Social\File\PostedCardOwnershipChecker;
use PHPUnit\Framework\TestCase;

/**
 * The card the browser posted is kept as a `files` row, and this is what
 * keeps it off `/files/{id}` (issue #706, IT-02).
 *
 * Found in the review of #850: stored with `role_min = 'chief'` and no
 * owner, the row fell through to that floor alone, so any chief could
 * read any card by asking for a sequential id — and a card can be a
 * gallery photo with no blur on it, from an album that chief was never
 * granted.
 */
final class PostedCardOwnershipCheckerTest extends TestCase
{
    public function testItIsTheCheckerForItsOwnTypeAndNoOther(): void
    {
        $checker = new PostedCardOwnershipChecker();

        $this->assertTrue($checker->supports('social_card'));
        $this->assertSame('social_card', PostedCardOwnershipChecker::OWNER_TYPE);
        // Not the cards of the token route, not a group's media, not a
        // document: a checker that claimed one of those would answer no
        // for files that have their own rule.
        foreach (['discussion_group', 'section_document', 'gallery_album', 'social'] as $other) {
            $this->assertFalse($checker->supports($other), "{$other} was claimed by the wrong checker");
        }
    }

    /**
     * **No reader, at any role.** The only legitimate read is
     * ShareSourceResolver::frozenCard(), which reads the bytes through
     * the storage service and never over HTTP.
     */
    public function testNoRoleAtAllIsAllowedThroughTheGenericRoute(): void
    {
        $checker = new PostedCardOwnershipChecker();

        foreach (Role::cases() as $role) {
            $this->assertFalse(
                $checker->isAllowed(7, $role, [1, 2, 3]),
                $role->value . ' was served a posted card through /files/{id}'
            );
        }
    }

    /** And nothing a crawler may keep, stated rather than inferred. */
    public function testItIsNotIndexable(): void
    {
        $checker = new PostedCardOwnershipChecker();

        $this->assertInstanceOf(FileIndexingPolicyInterface::class, $checker);
        $this->assertFalse($checker->isIndexable(7));
    }

    public function testItIsAnOwnershipCheckerTheRegistryCanHold(): void
    {
        $this->assertInstanceOf(FileOwnershipCheckerInterface::class, new PostedCardOwnershipChecker());
    }
}
