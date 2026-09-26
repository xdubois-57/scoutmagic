<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * A core table that keeps something encrypted has said, once, what it keeps
 * — and the RGPD page was told when that changed.
 *
 * **The rule this stands under, and it has no other net.** `AGENTS.md`
 * § RGPD page maintenance: « When adding a new data field to any table that
 * stores personal data → update the "Données collectées" section », and it
 * ends « This is not optional. A PR that adds personal data processing
 * without updating the RGPD documentation is incomplete. » Until this test,
 * that rule was held by nothing at all: it was caught on pull request #562
 * by a reviewer who happened to remember it, three columns after the fact.
 *
 * **What this test is NOT, and cannot be.** It does not read the RGPD page
 * and decide whether the prose covers a column — prose is not checkable, and
 * a guard that demanded phrases would be satisfied by pasting them. It is a
 * tripwire: the column count of every core table holding an encrypted value
 * is written down here, so adding or removing one fails, and the failure
 * arrives with the name of the section to go and read. The author still
 * decides; they simply cannot forget to.
 *
 * **`*_encrypted` is not a marker of personal data, and that is the first
 * thing this inventory had to settle.** Encryption at rest is applied to
 * secrets as much as to people: `storage_locations.secret_encrypted` is a
 * remote storage credential, `mail_seed_copies.seed_address_encrypted` is a
 * witness mailbox the unit rents from a measurement service, and
 * `mail_return_probes.address_encrypted` is the site's own address. A guard
 * keyed on the suffix alone would demand an RGPD section for a Drive
 * password.
 *
 * It fails in the other direction too, which is why the inventory is written
 * by hand and can never be derived: `mail_dmarc_reports` holds no encrypted
 * column at all and still has a section of its own (§4undecies), whose whole
 * point is to say that what it holds is **not** the reader's personal data.
 * Deciding which of these is which is a judgement, made once per table, and
 * this file is where it is recorded.
 *
 * **Core only, deliberately.** Fifteen module schemas carry encrypted
 * columns; none of them belongs here. `AGENTS.md` routes a module's data
 * processing elsewhere — to `RgpdContentService::buildSystemPrompt()` and to
 * the `SubProcessorProvider` hook — and the page's own §5 is
 * « Modules actifs uniquement ». Extending this list to modules would assert
 * a rule that does not exist.
 */
final class EncryptedCoreColumnsAreAccountedForTest extends TestCase
{
    private const SCHEMA = 'schema/core.sql';
    private const RGPD = 'core/View/RgpdContentService.php';

    /**
     * Every `schema/core.sql` table holding an encrypted column: how many
     * columns it has, and what the RGPD page says about it.
     *
     * The count is the tripwire. The note is the judgement — either the
     * numbered section that describes this table's contents, or the reason
     * there is none to describe.
     *
     * @var array<string, array{0: int, 1: string}>
     */
    private const ACCOUNTED_FOR = [
        // The three mail features the page describes one by one, each in its
        // own numbered section. These are the entries a new column will most
        // often land in, and the ones whose section is unambiguous.
        'mail_deferred_messages' => [11, '§4octies « Messages en attente d\'envoi »'],
        'mail_probes' => [9, '§4nonies « Sonde de délivrabilité »'],
        'mail_bounce_states' => [12, '§4decies « Rebonds d\'adresses »'],

        // The member data itself. The page describes it in its opening
        // sections rather than in a numbered « fonctionnalité core » one, and
        // those sections enumerate no table — so no marker is claimed here
        // rather than inventing one. A column added to any of these three is
        // a change to what the unit collects about its members, which is the
        // heart of the document.
        'member_years' => [27, 'les sections d\'ouverture (données collectées sur un membre)'],
        'member_addresses' => [11, 'les sections d\'ouverture (données collectées sur un membre)'],
        'member_emails' => [12, 'les sections d\'ouverture (données collectées sur un membre)'],
        'user_accounts' => [17, 'les sections d\'ouverture (compte d\'un membre ou d\'un responsable)'],

        // Encrypted, and NOT the reader's personal data. Each of these is a
        // secret or an address the unit itself owns, and the reason is the
        // decision this list exists to record.
        'storage_locations' => [10, 'aucune : `secret_encrypted` est un identifiant de stockage distant'],
        'mail_return_probes' => [8, 'aucune : l\'adresse est celle du site, pas celle d\'un membre'],
        'mail_seed_copies' => [9, 'aucune : une boîte témoin louée à un service de mesure'],

        // A shortened link's target. Encrypted because a target can name a
        // person (a member page, a document), never because the link itself
        // is one.
        'short_urls' => [5, 'aucune section dédiée : la cible est chiffrée parce qu\'elle peut nommer une page de membre'],
    ];

    public function testEveryEncryptedCoreTableIsInTheInventory(): void
    {
        $missing = array_diff(array_keys($this->encryptedTablesInSchema()), array_keys(self::ACCOUNTED_FOR));

        $this->assertSame(
            [],
            array_values($missing),
            "A core table keeps something encrypted and this inventory has never said what.\n"
                . "Add it to ACCOUNTED_FOR with its column count and one of two things: the RGPD\n"
                . "section that describes what it holds, or the reason there is none — a secret, an\n"
                . "address the site owns. AGENTS.md § RGPD page maintenance is what this stands\n"
                . 'under, and it says « This is not optional ».'
        );
    }

    public function testTheInventoryNamesNoTableThatIsGone(): void
    {
        $gone = array_diff(array_keys(self::ACCOUNTED_FOR), array_keys($this->encryptedTablesInSchema()));

        $this->assertSame(
            [],
            array_values($gone),
            'This inventory names a table that schema/core.sql no longer has, or that no longer '
                . 'keeps anything encrypted. Take the line out — a list that outlives its subject '
                . 'is the kind of documentation this test exists to prevent.'
        );
    }

    /**
     * The tripwire itself.
     *
     * A count is a blunt instrument on purpose: it cannot tell a column that
     * needs an RGPD sentence from one that does not, and it does not try.
     * What it does is make the decision unavoidable at the moment the column
     * is added, with the section named in the failure — instead of three
     * columns later, in review, by luck.
     */
    public function testAColumnAddedOrRemovedForcesTheRgpdDecision(): void
    {
        $actual = $this->encryptedTablesInSchema();

        foreach (self::ACCOUNTED_FOR as $table => [$expected, $where]) {
            if (!isset($actual[$table])) {
                continue; // The test above is the one that reports this.
            }

            $this->assertSame(
                $expected,
                $actual[$table],
                sprintf(
                    "`%s` no longer has %d columns but %d.\n\n"
                        . "Go and read %s, decide whether what you changed alters what the unit\n"
                        . "tells its members it keeps, update that section if it does — then correct\n"
                        . "the count here. AGENTS.md: « This is not optional. A PR that adds personal\n"
                        . 'data processing without updating the RGPD documentation is incomplete. »',
                    $table,
                    $expected,
                    $actual[$table],
                    $where
                )
            );
        }
    }

    /**
     * The section markers this inventory points at are really in the page.
     *
     * Cheap, and it catches the failure mode the rest of this file cannot: a
     * section renumbered or renamed, leaving every note above pointing at
     * nothing. Only the entries that claim a `§` marker are checked — the
     * others say in words that there is no section, which is not a reference
     * to resolve.
     */
    public function testEverySectionThisInventoryPointsAtExists(): void
    {
        $page = $this->read(self::RGPD);
        $claimed = 0;

        foreach (self::ACCOUNTED_FOR as $table => [, $where]) {
            if (preg_match('/§(\d+[a-z]*)/u', $where, $marker) !== 1) {
                continue;
            }

            $claimed++;
            $this->assertStringContainsString(
                $marker[1] . '. **',
                $page,
                sprintf('`%s` points at §%s, which %s does not define.', $table, $marker[1], self::RGPD)
            );
        }

        // A floor, so a sweep that stopped finding markers cannot pass for
        // agreement: three tables name a section today.
        $this->assertGreaterThanOrEqual(3, $claimed, 'No inventory entry names a section any more.');
    }

    /**
     * The reader, on a literal fixture whose answer is known.
     *
     * Every count above agrees with the schema, so a reader that returned an
     * empty list — or counted index and constraint lines as columns — would
     * pass all three sweeps without a murmur.
     */
    public function testTheReaderCountsColumnsAndNotWhatSurroundsThem(): void
    {
        $fixture = <<<'SQL'
            CREATE TABLE IF NOT EXISTS with_a_secret (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                -- A comment line, and a column name inside it: decoy_encrypted BLOB
                label VARCHAR(50) NOT NULL,
                secret_encrypted BLOB NULL,
                UNIQUE INDEX idx_one (label),
                CONSTRAINT fk_two FOREIGN KEY (id) REFERENCES elsewhere(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

            CREATE TABLE plain_as_day (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                note VARCHAR(50) NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            SQL;

        // Three columns: id, label, secret_encrypted. Not the comment, not
        // the index, not the constraint.
        $this->assertSame(['with_a_secret' => 3], $this->encryptedTablesIn($fixture));
    }

    /** @return array<string, int> table => column count, encrypted tables only */
    private function encryptedTablesInSchema(): array
    {
        return $this->encryptedTablesIn($this->read(self::SCHEMA));
    }

    /**
     * @return array<string, int>
     */
    private function encryptedTablesIn(string $sql): array
    {
        preg_match_all(
            '/CREATE TABLE (?:IF NOT EXISTS )?(\w+)\s*\((.*?)\n\s*\)\s*ENGINE/s',
            $sql,
            $tables,
            PREG_SET_ORDER
        );

        $found = [];
        foreach ($tables as [, $name, $body]) {
            $columns = [];
            foreach (explode("\n", $body) as $line) {
                $line = trim($line);
                if (preg_match('/^(?:PRIMARY|UNIQUE|INDEX|KEY|CONSTRAINT|FOREIGN)\b/i', $line) === 1) {
                    continue;
                }
                // **Anchored, and that anchor is the only thing standing
                // between a comment and the column count.** A `--` line
                // cannot match `^(\w+)`, so an explicit « skip comments »
                // branch beside this one would be a second mechanism for one
                // boundary — and two defences that each hide the other's
                // absence are one defence and one ornament. (`schema/core.sql`
                // uses no `/* */` and no `#`; a block comment's CONTINUATION
                // line could start with a word, and would need real handling
                // rather than a prefix test, so the day one appears this is
                // where to look.)
                if (preg_match('/^(\w+)\s+/', $line, $column) === 1) {
                    $columns[] = $column[1];
                }
            }

            foreach ($columns as $column) {
                if (str_ends_with($column, '_encrypted')) {
                    $found[$name] = count($columns);
                    break;
                }
            }
        }

        return $found;
    }

    private function read(string $relativePath): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
        $this->assertIsString($contents, $relativePath . ' could not be read.');

        return $contents;
    }
}
