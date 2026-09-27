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
 * tripwire: the column count of every core table whose storage looks like
 * ciphertext is written down here, so adding or removing one fails, and the
 * failure arrives with the name of the rule to go and read. The author still
 * decides; they simply cannot forget to.
 *
 * **Two signals, and the first version had only one.** A column is read as
 * ciphertext if its name ends in `_encrypted` OR its type is a `BLOB`. The
 * suffix alone missed four core tables, `member_notes` among them — the
 * schema calls its `body` « probably the most sensitive free text on the
 * site » — so the very change this guard exists to catch could be made in
 * them silently. On today's schema the two signals agree exactly: all twelve
 * suffixed columns are BLOBs. **They are kept both, and that is not the
 * usual two-mechanisms-for-one-boundary mistake**, because they are two
 * different rules that happen to coincide: one is a naming convention, the
 * other a storage type, and each catches what the other cannot — a
 * `notes_encrypted TEXT` holding base64 is invisible to the type test, and a
 * `body BLOB` is invisible to the name test. Both are shown firing alone, on
 * a fixture, rather than asserted to be useful.
 *
 * **A BLOB is not a promise of ciphertext either**, and one table says so:
 * `webauthn_credentials.public_key` is stored raw and read raw, because a
 * public key is public. It is inventoried all the same — the reader cannot
 * tell one BLOB from another, and the point of writing this list by hand is
 * that somebody has looked.
 *
 * **And a second guard was built for that page and abandoned, on evidence.**
 * The RGPD page has two halves — the numbered rules that say what each
 * section must keep, and the content itself — and nothing holds them
 * together, which is a real gap. The attempt was a vocabulary check: flag a
 * rule whose distinctive words appear nowhere in the section it governs. It
 * was measured against the case that motivated it and **did not fire**: one
 * word shared by accident between a rule and an unrelated paragraph is
 * enough to satisfy it, and there is always one. A guard on section NUMBERS
 * would not have caught that case either: the section it named existed — the
 * page's own « section 2.9 » — and it was that section's CONTENT which had
 * drifted. So the gap stays open and named rather than covered by a check
 * that reports success. A tripwire on a count can be trusted because
 * counting is exact; a tripwire on prose cannot, and one that passes when it
 * should fail is worse than the absence it replaced.
 *
 * **« rule N », never « §N », and the distinction is not cosmetic.** What
 * this file points at are the numbered items under « RÈGLES CRITIQUES » in
 * {@see \Core\View\RgpdContentService}, which are instructions about what the
 * rendered page must contain. The page the reader sees numbers its own
 * sections 1.3, 2.1, 3.1 — so « §4octies » named a section that does not
 * exist, in the sigil this repository reserves for its own documents
 * (`SECURITY.md §5`). An earlier version of this inventory spelled three
 * entries that way, which is the same defect as the false « SECURITY.md §11 »
 * citation this change removes from `KnownSenders`: a reference that resolves
 * to nothing teaches the next reader to distrust the ones that resolve.
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
 * column at all and still has a rule of its own (4undecies), whose whole
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
     * Every `schema/core.sql` table whose storage looks like ciphertext: how
     * many columns it has, and what the RGPD page says about it.
     *
     * The count is the tripwire. The note is the judgement — either the
     * numbered rule that governs what this table holds, or the reason there
     * is none.
     *
     * @var array<string, array{0: int, 1: string}>
     */
    private const ACCOUNTED_FOR = [
        // The three mail features the page describes one by one, each in its
        // own numbered rule. These are the entries a new column will most
        // often land in, and the ones whose rule is unambiguous.
        'mail_deferred_messages' => [11, 'rule 4octies « Messages en attente d\'envoi »'],
        // Twelve since issue #419 added the traced bounce: the category, the
        // enhanced status code and the moment. Rule 4nonies already carries
        // all three, and says in the same breath that the server's diagnostic
        // text is never kept because it recites the address — so the count
        // moved and the prose did not have to.
        'mail_probes' => [12, 'rule 4nonies « Sonde de délivrabilité »'],
        'mail_bounce_states' => [12, 'rule 4decies « Rebonds d\'adresses »'],

        // The member data itself. The page describes it in its opening
        // sections rather than in a numbered « fonctionnalité core » rule, and
        // those sections enumerate no table — so no rule is claimed here
        // rather than inventing one. A column added to any of these three is
        // a change to what the unit collects about its members, which is the
        // heart of the document.
        'member_years' => [27, 'the page\'s opening sections (what the unit collects about a member)'],
        'member_addresses' => [11, 'the page\'s opening sections (what the unit collects about a member)'],
        'member_emails' => [12, 'the page\'s opening sections (what the unit collects about a member)'],
        'user_accounts' => [17, 'the page\'s opening sections (a member\'s or a leader\'s account)'],

        // Encrypted, and NOT the reader's personal data. Each of these is a
        // secret or an address the unit itself owns, and the reason is the
        // decision this list exists to record.
        'storage_locations' => [10, 'none: `secret_encrypted` is a remote storage credential'],
        'mail_return_probes' => [8, 'none: the address is the site\'s own, never a member\'s'],
        // Rule 35 « Boîtes témoins de délivrabilité » documents this table at
        // length — the hosting providers as full sub-processors, the copy
        // deleted once its folder has been read, the option off by default.
        // An earlier note here said « none », which sent a future author to
        // read nothing: the judgement that the address is not a member's is
        // right, and it is rule 35 that says why and bounds it.
        'mail_seed_copies' => [9, 'rule 35 « Boîtes témoins de délivrabilité » (the box is the unit\'s own)'],

        // The domain half of every address the mailing lane writes to, noted
        // by `DomainPreferences::reorder()` so a scheduled task can read its
        // MX records. **Encrypted because it can name a family**: most rows
        // are `gmail.com`, but a household with its own domain is one row of
        // its own, which is exactly the case the encryption is for. The page
        // made this decision when the table arrived (issue #422) — rule 35's
        // paragraph says the site reads the PUBLIC MX records of the domains
        // it writes to, that « seul le nom de domaine est interrogé, jamais
        // l'adresse », and that it keeps only the matching.
        'mail_domain_providers' => [
            9,
            'rule 35, paragraph « Rattachement d\'un domaine à son fournisseur de messagerie »',
        ],

        // A shortened link's target. Encrypted because a target can name a
        // person (a member page, a document), never because the link itself
        // is one.
        'short_urls' => [5, 'no rule of its own: the target is encrypted because it can name a member\'s page'],

        // ── The four the `_encrypted` suffix never reached ────────────────
        //
        // Each of these encrypts through `Core\Security\EncryptionService`
        // with a purpose string naming the column, and none of them advertises
        // it in the column name. They were invisible to this guard until the
        // BLOB signal was added, which is the review finding that added it.

        // `body`, and the schema calls it « probably the most sensitive free
        // text on the site ». Rule 4sexies is explicit: sections 2.2 and 3.1
        // must say such a note exists, who may read it, and that it appears
        // in no audit-journal entry, error message, export or mailing.
        'member_notes' => [6, 'rule 4sexies « Notes internes sur un membre »'],

        // The notification centre. `title` and `body` are encrypted although
        // neither is in SECURITY.md §5's nominative list, because a
        // notification's text quotes what it is about — a member's name, a
        // mailing's personalised subject. Rule 10bis(e) is the one that
        // legislates them, and it does so through section 3.1's retention
        // list: an unread notification is kept without limit, except the one
        // describing an Excel mailing, which dies with the data it quotes.
        'notifications' => [10, 'rule 10bis(e), through the retention list of section 3.1'],

        // A browser's push endpoint and the two keys that go with it —
        // encrypted like any other personal-data field, because an endpoint
        // identifies a device. Rule 23 puts the subscription in section 2.1.
        'push_subscriptions' => [10, 'rule 23 « Notifications push »'],

        // **The audit journal, and the only table here the page never
        // describes as a feature — which is itself the thing to know.** What
        // it does instead, rule after rule, is constrain what may REACH the
        // journal: « chaque ouverture et chaque renvoi étant consignés au
        // journal d'audit (identifiants uniquement) » (rule 4bis),
        // « identifiants techniques uniquement, jamais de contenu »
        // (rule 4quater), a merge logged by identifier (rule 4quinquies), a
        // member note that appears in it never (rule 4sexies). Those promises
        // are about this table's INPUTS, so a column added here is exactly how
        // one of them would quietly stop being true. Its three are encrypted
        // under a single purpose, `entity_changes.value`, because they hold
        // the old and the new value of whatever changed — the journal keeps
        // content, and the rules above bound whose.
        //
        // The note names rule 4quater rather than saying « none », so that
        // this file's own reference check resolves it: a promise about the
        // journal is the nearest thing to a rule governing the journal, and
        // an entry pointing at nothing is the mistake `mail_seed_copies`
        // already made here once.
        'entity_changes' => [
            11,
            'no rule of its own: rule 4quater\'s « identifiants techniques uniquement, jamais de contenu »'
                . ' and its repeats (rules 4bis, 4quinquies, 4sexies) bound what may reach the journal',
        ],

        // ── And one BLOB that is not ciphertext at all ────────────────────
        //
        // A WebAuthn public key is public by construction: stored raw, read
        // raw by `Core\Security\WebAuthnService`, and encrypting it would
        // protect nothing. Inventoried because the reader cannot tell this
        // BLOB from an encrypted one — the judgement is the whole reason this
        // list is written by hand. The page mentions WebAuthn once, in rule 12,
        // as a security measure rather than as data collected; a column added
        // here would still be worth a look, since `device_label` is a name its
        // owner typed.
        'webauthn_credentials' => [8, 'rule 12 « Sécurité technique »: the BLOB is a public key, not ciphertext'],
    ];

    public function testEveryCoreTableThatLooksEncryptedIsInTheInventory(): void
    {
        $missing = array_diff(array_keys($this->ciphertextTablesInSchema()), array_keys(self::ACCOUNTED_FOR));

        $this->assertSame(
            [],
            array_values($missing),
            "A core table keeps something that looks like ciphertext and this inventory has never\n"
                . "said what. Add it to ACCOUNTED_FOR with its column count and one of three things:\n"
                . "the RGPD rule that governs what it holds, the reason there is none — a secret, an\n"
                . "address the site owns — or, if the BLOB is not ciphertext at all, that. AGENTS.md\n"
                . '§ RGPD page maintenance is what this stands under, and it says « This is not optional ».'
        );
    }

    public function testTheInventoryNamesNoTableThatIsGone(): void
    {
        $gone = array_diff(array_keys(self::ACCOUNTED_FOR), array_keys($this->ciphertextTablesInSchema()));

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
     * is added, with the rule named in the failure — instead of three columns
     * later, in review, by luck.
     */
    public function testAColumnAddedOrRemovedForcesTheRgpdDecision(): void
    {
        $actual = $this->ciphertextTablesInSchema();

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
     * The rules this inventory points at are really in the page.
     *
     * Cheap, and it catches the failure mode the rest of this file cannot: a
     * rule renumbered or retitled, leaving every note above pointing at
     * nothing. Only the entries naming a `rule N` are checked — the others
     * say in words that there is no rule, or point at a section of the
     * rendered page, neither of which is a reference to resolve here.
     *
     * **One spelling, and an earlier version had two.** Three entries said
     * « §4octies » and two « rule 35 », for the same kind of target; this
     * method resolved only the first spelling, so the « rule N » entries were
     * skipped in silence — the exact drift it exists to prevent, inside
     * itself.
     */
    public function testEveryRuleThisInventoryPointsAtExists(): void
    {
        $page = $this->read(self::RGPD);
        $claimed = 0;

        foreach (self::ACCOUNTED_FOR as $table => [, $where]) {
            if (preg_match('/\brule (\d+[a-z]*)/u', $where, $marker) !== 1) {
                continue;
            }

            $claimed++;
            $this->assertStringContainsString(
                $marker[1] . '. **',
                $page,
                sprintf('`%s` points at rule %s, which %s does not define.', $table, $marker[1], self::RGPD)
            );
        }

        // A floor, so a sweep that stopped finding rules cannot pass for
        // agreement: ten tables name one today. It reports the drift rather
        // than the absence — one entry respelled is the likely cause, and
        // « no entry names a rule » would send the reader looking for nine.
        $this->assertGreaterThanOrEqual(
            10,
            $claimed,
            $claimed . ' inventory entries name a rule, and ten did. An entry that still points at one '
                . 'has been respelled out of « rule N » and is now skipped in silence — which is the whole '
                . 'failure this method exists to report.'
        );
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
        $this->assertSame(['with_a_secret' => 3], $this->ciphertextTablesIn($fixture));
    }

    /**
     * Each signal fires on its own, and neither fires on prose.
     *
     * Two mechanisms for one boundary is this repository's most repeated
     * mistake, so the case for keeping both has to be shown rather than
     * argued: below, one table is found by its column NAME with no BLOB in
     * sight, another by its column TYPE with no suffix in sight. Remove
     * either half of the check and one of them goes missing. The third table
     * is the anchor's job — a column whose trailing comment says « BLOB » is
     * not a BLOB.
     */
    public function testEachCiphertextSignalFiresOnItsOwn(): void
    {
        $fixture = <<<'SQL'
            CREATE TABLE named_only (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                secret_encrypted TEXT NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            CREATE TABLE typed_only (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                body MEDIUMBLOB NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            CREATE TABLE neither (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                note VARCHAR(50) NULL COMMENT 'was a BLOB once, and is not one now'
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            SQL;

        $this->assertSame(
            ['named_only' => 2, 'typed_only' => 2],
            $this->ciphertextTablesIn($fixture),
            'the two signals are not independent: one of them is doing no work'
        );
    }

    /** @return array<string, int> table => column count, ciphertext-shaped tables only */
    private function ciphertextTablesInSchema(): array
    {
        return $this->ciphertextTablesIn($this->read(self::SCHEMA));
    }

    /**
     * @return array<string, int>
     */
    private function ciphertextTablesIn(string $sql): array
    {
        preg_match_all(
            '/CREATE TABLE (?:IF NOT EXISTS )?(\w+)\s*\((.*?)\n\s*\)\s*ENGINE/s',
            $sql,
            $tables,
            PREG_SET_ORDER
        );

        $found = [];
        foreach ($tables as [, $name, $body]) {
            $columns = 0;
            $ciphertext = false;

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
                if (preg_match('/^(\w+)\s+(.+)$/', $line, $column) !== 1) {
                    continue;
                }

                $columns++;
                // The type is anchored to the start of what follows the name,
                // for the same reason: unanchored, `\bBLOB\b` would find the
                // word in a trailing COMMENT and read a VARCHAR as ciphertext.
                if (
                    str_ends_with($column[1], '_encrypted')
                    || preg_match('/^(?:TINY|MEDIUM|LONG)?BLOB\b/i', $column[2]) === 1
                ) {
                    $ciphertext = true;
                }
            }

            if ($ciphertext) {
                $found[$name] = $columns;
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
