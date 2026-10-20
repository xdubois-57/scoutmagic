<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\MassMail\Repository;

use Core\Security\EncryptionService;
use Modules\MassMail\Repository\ContactRepository;
use Modules\MassMail\Repository\ListBlock;
use Modules\MassMail\Repository\ListBlockRepository;
use Modules\MassMail\Repository\MailingListRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\UsesProductionEngine;

/**
 * A list's blocks on the engine production runs, against the schema as the
 * migration builds it.
 *
 * Three things here are the engine's to decide rather than SQLite's: the
 * rank of the blocks (`kind` is an ENUM, which MySQL and MariaDB sort by
 * declaration index — the query ranks on a CASE so that does not matter,
 * and this proves it), a `created_at` the SERVER fills in, and the
 * `ON DELETE CASCADE` that SQLite's test tables do not enforce: removing a
 * block or a list must take the contacts and criteria under it.
 */
#[Group('database')]
final class ListBlocksOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private \PDO $pdo;
    private ListBlockRepository $blocks;
    private ContactRepository $contacts;
    private MailingListRepository $lists;
    private int $listId;

    protected function setUp(): void
    {
        $this->pdo = $this->productionEngine();
        $this->blocks = new ListBlockRepository($this->pdo);
        $this->contacts = new ContactRepository(
            $this->pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
        $this->lists = new MailingListRepository($this->pdo);
        $this->listId = $this->lists->create('Staff élargi', 'Description.', null);
    }

    public function testBlocksAreRankedByKindThenCreationOnThisEngine(): void
    {
        $this->assertSame(
            'enum',
            strtolower((string) $this->pdo->query(
                "SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mass_mail_list_blocks' AND COLUMN_NAME = 'kind'"
            )->fetchColumn()),
            'the premise: kind is an ENUM here, which this engine would sort by declaration index'
        );

        $contact = $this->blocks->create($this->listId, ListBlock::KIND_CONTACT_GROUP, 'Partenaires', null);
        $member = $this->blocks->create($this->listId, ListBlock::KIND_MEMBER_GROUP, null, null);
        $secondContact = $this->blocks->create($this->listId, ListBlock::KIND_CONTACT_GROUP, 'Commune', null);

        $found = $this->blocks->findForList($this->listId);

        $this->assertSame([$member, $contact, $secondContact], array_map(fn(ListBlock $b) => $b->id, $found));
        $this->assertNotSame('', $found[0]->createdAt, 'filled in by the server');
    }

    public function testRemovingABlockCascadesToItsContactsAndCriteria(): void
    {
        $this->pdo->exec("INSERT INTO functions (desk_code, label, role) VALUES ('ANIM', 'Animateur', 'chief')");
        $functionId = (int) $this->pdo->lastInsertId();
        $member = $this->blocks->create($this->listId, ListBlock::KIND_MEMBER_GROUP, null, null);
        $this->blocks->replaceCriteria($member, [$functionId], [], []);
        $group = $this->blocks->create($this->listId, ListBlock::KIND_CONTACT_GROUP, 'Partenaires', null);
        $this->contacts->create($group, 'Curé', 'cure@paroisse.be');
        $this->contacts->create($group, null, 'jeunesse@wavre.be');
        $this->contacts->unsubscribeEverywhere('cure@paroisse.be');

        $this->assertSame(['total' => 2, 'unsubscribed' => 1], $this->contacts->countDistinctForList($this->listId));
        $this->assertSame(
            [$group => ['total' => 2, 'unsubscribed' => 1]],
            $this->contacts->countByBlockForList($this->listId)
        );

        // A bare DELETE, as something outside the application would issue:
        // the cascade alone has to do the rest.
        $this->pdo->prepare('DELETE FROM mass_mail_list_blocks WHERE id = ?')->execute([$group]);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM mass_mail_block_contacts')->fetchColumn());

        $this->pdo->prepare('DELETE FROM mass_mail_lists WHERE id = ?')->execute([$this->listId]);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM mass_mail_list_blocks')->fetchColumn());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM mass_mail_block_functions')->fetchColumn());
    }

    /** And the repository's own delete, which does it explicitly, agrees. */
    public function testTheRepositoryDeleteLeavesNothingBehind(): void
    {
        $group = $this->blocks->create($this->listId, ListBlock::KIND_CONTACT_GROUP, 'Partenaires', null);
        $this->contacts->replaceForBlock($group, [['name' => null, 'email' => 'cure@paroisse.be']]);

        $this->lists->delete($this->listId);

        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM mass_mail_block_contacts')->fetchColumn());
        $this->assertSame([], $this->blocks->countKindsByList());
    }
}
