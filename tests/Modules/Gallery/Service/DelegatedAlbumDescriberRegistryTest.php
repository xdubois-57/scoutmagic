<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Gallery\Service;

use Modules\Gallery\Api\DelegatedAlbumDescriber;
use Modules\Gallery\Service\DelegatedAlbumDescriberRegistry;
use PHPUnit\Framework\TestCase;

/**
 * The registry that names a delegated album for an administrator.
 *
 * Its whole point is the opposite posture to its sibling:
 * Service\DelegatedAlbumAccessRegistry is FAIL-CLOSED, because an
 * owner_type nothing claims must not be granted access nobody confirmed.
 * This one is fail-OPEN, because the question is only "what do I call this
 * row" — and an album nobody can see listed is an album whose storage bill
 * nobody can explain.
 */
class DelegatedAlbumDescriberRegistryTest extends TestCase
{
    private function describer(string $ownerType, ?string $label): DelegatedAlbumDescriber
    {
        return new class ($ownerType, $label) implements DelegatedAlbumDescriber {
            public function __construct(private string $ownerType, private ?string $label)
            {
            }

            public function supports(string $ownerType): bool
            {
                return $ownerType === $this->ownerType;
            }

            public function describe(int $ownerId): ?string
            {
                return $this->label;
            }
        };
    }

    public function testTheOwningModuleGetsToNameItsOwnAlbum(): void
    {
        $registry = new DelegatedAlbumDescriberRegistry([
            $this->describer('discussion_group', "Groupe Chefs d'unité"),
        ]);

        $this->assertSame("Groupe Chefs d'unité", $registry->describe('discussion_group', 7));
    }

    public function testAnUnclaimedOwnerTypeStillGetsALabelRatherThanDisappearing(): void
    {
        // Fail-open, deliberately: the owning module may simply be disabled,
        // and the album still occupies a storage location somebody has to
        // be able to find and move.
        $registry = new DelegatedAlbumDescriberRegistry([]);

        $this->assertSame('discussion_group #7', $registry->describe('discussion_group', 7));
    }

    public function testAnAlbumThatOutlivedItsOwnerIsSaidToHaveDoneSo(): void
    {
        // The case an administrator most needs to see: the group is gone and
        // its photos are still paying rent.
        $registry = new DelegatedAlbumDescriberRegistry([
            $this->describer('discussion_group', null),
        ]);

        $this->assertSame('discussion_group #7 (supprimé)', $registry->describe('discussion_group', 7));
    }

    public function testABlankLabelIsTreatedAsNoLabelAtAll(): void
    {
        $registry = new DelegatedAlbumDescriberRegistry([
            $this->describer('discussion_group', '   '),
        ]);

        $this->assertSame('discussion_group #7 (supprimé)', $registry->describe('discussion_group', 7));
    }

    public function testTheFirstDescriberClaimingATypeDecides(): void
    {
        $registry = new DelegatedAlbumDescriberRegistry([
            $this->describer('discussion_group', 'Premier'),
            $this->describer('discussion_group', 'Second'),
        ]);

        $this->assertSame('Premier', $registry->describe('discussion_group', 7));
    }

    public function testADescriberNeverAnswersForATypeItDoesNotClaim(): void
    {
        $registry = new DelegatedAlbumDescriberRegistry([
            $this->describer('discussion_group', "Groupe Chefs d'unité"),
        ]);

        $this->assertSame('rental_asset #3', $registry->describe('rental_asset', 3));
    }
}
