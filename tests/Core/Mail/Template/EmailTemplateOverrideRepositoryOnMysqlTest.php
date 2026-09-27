<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Mail\Template;

use Core\Mail\Template\EmailTemplateOverrideRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\UsesProductionEngine;

/**
 * A customised e-mail's insert-or-update, against the engine an
 * installation runs.
 *
 * `save()` is an upsert written in two steps — look the template up, then
 * UPDATE or INSERT — over the `UNIQUE` index on `template_id`, and it is
 * the only thing standing between « Enregistrer » pressed twice and a
 * duplicate-key error on the second press. On SQLite
 * (`EmailTemplateControllerTest`, `EmailTemplateRendererTest`) the lookup
 * and the index compare bytes; here both are the collation's, and the
 * author column is a real foreign key into `user_accounts` with
 * `ON DELETE SET NULL`, which the in-memory database does not enforce.
 */
#[Group('database')]
final class EmailTemplateOverrideRepositoryOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private \PDO $pdo;
    private EmailTemplateOverrideRepository $overrides;
    private int $authorId = 0;

    protected function setUp(): void
    {
        $this->pdo = $this->productionEngine();
        $this->overrides = new EmailTemplateOverrideRepository($this->pdo);

        // The account `updated_by` points at. Its id is read back rather
        // than assumed: the counter carries on from one test to the next.
        $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)')
            ->execute(['x', str_repeat('a', 64)]);
        $this->authorId = (int) $this->pdo->lastInsertId();
    }

    public function testASecondSaveUpdatesTheOneRowRatherThanCollidingOnTheIndex(): void
    {
        $this->overrides->save('core.password_reset', 'Sujet', '<p>Un</p>', $this->authorId);
        $this->overrides->save('core.password_reset', 'Nouveau sujet', '<p>Deux</p>', null);

        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM email_template_overrides')->fetchColumn());
        $row = $this->overrides->find('core.password_reset');
        $this->assertNotNull($row);
        $this->assertSame('Nouveau sujet', $row['subject']);
        $this->assertSame('<p>Deux</p>', $row['body_html']);
        $this->assertNull($row['updated_by']);
    }

    /**
     * Saved again with nothing changed — the UPDATE changes no row on this
     * engine, and `save()` must not read that as « nothing there » and
     * fall through to an INSERT. It does not look at `rowCount()`; this is
     * what keeps it honest if it ever starts to.
     */
    public function testSavingTheSameWordingTwiceIsNotAnError(): void
    {
        $this->overrides->save('core.password_reset', 'Sujet', '<p>Un</p>', $this->authorId);
        $this->overrides->save('core.password_reset', 'Sujet', '<p>Un</p>', $this->authorId);

        $this->assertSame(['core.password_reset'], $this->overrides->customisedTemplateIds());
    }

    /**
     * The wording outlives the account that last edited it: the foreign
     * key clears the author rather than refusing the deletion or taking
     * the row with it.
     */
    public function testDeletingTheAuthorKeepsTheWording(): void
    {
        $this->overrides->save('core.password_reset', 'Sujet', '<p>Un</p>', $this->authorId);

        $this->pdo->prepare('DELETE FROM user_accounts WHERE id = ?')->execute([$this->authorId]);

        $row = $this->overrides->find('core.password_reset');
        $this->assertNotNull($row);
        $this->assertNull($row['updated_by']);
        $this->assertSame('Sujet', $row['subject']);
    }

    public function testRevertingReportsWhetherThereWasAnythingToRevert(): void
    {
        $this->overrides->save('core.password_reset', 'Sujet', '<p>Un</p>', null);

        $this->assertTrue($this->overrides->delete('core.password_reset'));
        $this->assertFalse($this->overrides->delete('core.password_reset'));
        $this->assertNull($this->overrides->find('core.password_reset'));
    }
}
