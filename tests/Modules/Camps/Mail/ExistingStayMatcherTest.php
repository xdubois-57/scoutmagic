<?php

declare(strict_types=1);

namespace Tests\Modules\Camps\Mail;

use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\EncryptionService;
use Modules\Camps\Mail\ExistingStayMatcher;
use Modules\Camps\Mail\MessageReader;
use Modules\Camps\Repository\Camp;
use Modules\Camps\Repository\CampRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Camps\CampsTestHelper;

/**
 * Recognising the stay a message is about, when the unit already booked it.
 *
 * The complaint this was written for: a unit books a field, the contract
 * creates the stay, and a fortnight later the same staff writes to the
 * site asking about the arrival time. That second message carries a new
 * subject, no `References` chain back to the first, and the unit's own
 * address in `From:` — so neither of the module's two identifications sees
 * anything, and « Relancer l'analyse » changed nothing however often it
 * was pressed.
 *
 * What both messages DO carry is the period. It is the evidence a chief
 * would use, and it is the only one there is.
 */
class ExistingStayMatcherTest extends TestCase
{
    private \PDO $pdo;
    private CampRepository $camps;
    private int $campId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        CampsTestHelper::createTables($this->pdo);
        $this->camps = new CampRepository($this->pdo, new EncryptionService(str_repeat('a', 32), str_repeat('b', 32)));

        $this->pdo->exec("INSERT INTO camp_places (name, postal_code, city) VALUES ('Camp de La Fresnaye', '1653', 'Dworp')");
        $this->campId = $this->camps->create(
            1, Camp::STAY_SHORT_CAMP, '2026-09-18', '2026-09-20', null,
            Camp::STATUS_TO_CONFIRM, null, null, null, null, []
        );
    }

    public function testAMessageStatingTheStaysDaysFindsIt(): void
    {
        // The user's own message, subject and all: no thread, no known
        // contact, and the unit itself as sender.
        $found = $this->matcher()->matching(
            "Re: Camp complet du 18 au 20 septembre 2026\n"
            . 'Nous avons réservé la Fresnaye et voulions confirmer notre heure d\'arrivée.'
        );

        $this->assertCount(1, $found);
        $this->assertSame($this->campId, $found[0]->id);
    }

    public function testAContractsOwnArrivalAndDepartureLinesAreEnough(): void
    {
        // The shape a camp site's PDF uses, read by the same
        // Mail\MessageReader the creation path already trusts — which is
        // the point of asking it rather than growing a second reader.
        $found = $this->matcher()->matching(
            'Arrivée : 18-09-26  16:30:00    Départ : 20-09-26  16:00:00'
        );

        $this->assertCount(1, $found);
    }

    public function testAMessageAboutOtherDaysFindsNothing(): void
    {
        // Exact on both ends, deliberately: an overlap test would put a
        // week-long camp and the weekend inside it on the same footing.
        $this->assertSame([], $this->matcher()->matching('Du 18 au 21 septembre 2026'));
        $this->assertSame([], $this->matcher()->matching('Du 18 au 20 septembre 2027'));
    }

    public function testAMessageStatingNoPeriodFindsNothing(): void
    {
        // A lone date is not a range, and MessageReader refuses it. Most
        // mail is this case, so it must cost nothing and claim nothing.
        $this->assertSame([], $this->matcher()->matching('Merci de votre réponse du 18 septembre 2026.'));
    }

    public function testAYearOnlyStayCanNeverBeMatchedByAPeriod(): void
    {
        // It states no day for two read dates to equal, and guessing at
        // « quelque part en 2026 » is the ambiguity this module answers
        // with silence everywhere else.
        $this->camps->create(
            1, Camp::STAY_GRAND_CAMP, null, null, 2026,
            Camp::STATUS_TO_CONFIRM, null, null, null, null, []
        );

        $this->assertCount(1, $this->matcher()->matching('Du 18 au 20 septembre 2026'));
    }

    public function testTwoStaysOverTheSameDaysAreBothReturned(): void
    {
        // Two sections camping the same weekend. The caller turns this
        // into propositions; what matters here is that neither is dropped.
        $this->pdo->exec("INSERT INTO camp_places (name) VALUES ('Ferme du Moulin')");
        $this->camps->create(
            2, Camp::STAY_SHORT_CAMP, '2026-09-18', '2026-09-20', null,
            Camp::STATUS_TO_CONFIRM, null, null, null, null, []
        );

        $this->assertCount(2, $this->matcher()->matching('Du 18 au 20 septembre 2026'));
    }

    // ── narrowedToPlace(): a place separates, it never vetoes ────────────

    public function testAPlaceSeparatesTwoStaysOverTheSameDays(): void
    {
        $this->pdo->exec("INSERT INTO camp_places (name) VALUES ('Ferme du Moulin')");
        $other = $this->camps->create(
            2, Camp::STAY_SHORT_CAMP, '2026-09-18', '2026-09-20', null,
            Camp::STATUS_TO_CONFIRM, null, null, null, null, []
        );

        $narrowed = ExistingStayMatcher::narrowedToPlace($this->matcher()->matching('Du 18 au 20 septembre 2026'), 2);

        $this->assertCount(1, $narrowed);
        $this->assertSame($other, $narrowed[0]->id);
    }

    public function testAPlaceHoldingNoneOfThemLeavesTheListAlone(): void
    {
        // The venue reading is the weaker of the two — it comes from a
        // model — so a covering note mentioning the unit's usual field
        // while booking somewhere else must not silently veto a period
        // read out of the contract itself.
        $this->pdo->exec("INSERT INTO camp_places (name) VALUES ('Ferme du Moulin')");
        $this->camps->create(
            2, Camp::STAY_SHORT_CAMP, '2026-09-18', '2026-09-20', null,
            Camp::STATUS_TO_CONFIRM, null, null, null, null, []
        );

        $found = $this->matcher()->matching('Du 18 au 20 septembre 2026');

        $this->assertCount(2, ExistingStayMatcher::narrowedToPlace($found, 99));
    }

    public function testASingleStayIsNeverNarrowedAway(): void
    {
        $found = $this->matcher()->matching('Du 18 au 20 septembre 2026');

        $this->assertCount(1, ExistingStayMatcher::narrowedToPlace($found, 99));
        $this->assertCount(1, ExistingStayMatcher::narrowedToPlace($found, null));
    }

    // ── The journal: why this message is, or is not, on that camp ────────

    public function testTheJournalNamesThePeriodAndTheStaysWithoutNamingAnybody(): void
    {
        $journal = new JournalService(new JournalRepository($this->pdo));
        $found = $this->matcher($journal)->matching('Du 18 au 20 septembre 2026');

        $this->matcher($journal)->journalMatch(
            55,
            'Du 18 au 20 septembre 2026',
            $found,
            ExistingStayMatcher::OUTCOME_LINKED
        );

        $row = $this->pdo->query(
            "SELECT * FROM event_log WHERE event_type = 'camps_stay_matched_by_period'"
        )->fetch(\PDO::FETCH_ASSOC);

        $this->assertIsArray($row);
        $this->assertStringContainsString('2026-09-18', (string) $row['description']);
        $this->assertStringContainsString('2026-09-20', (string) $row['description']);
        // §7.9: the entry is about a booking, never about a person. No
        // sender, no recipient, no body — a period, a count and ids.
        $this->assertStringNotContainsString('@', (string) $row['description'] . (string) $row['context']);
    }

    public function testWithoutAJournalItStillAnswersAndSaysNothing(): void
    {
        $this->matcher()->journalMatch(55, 'Du 18 au 20 septembre 2026', [], ExistingStayMatcher::OUTCOME_PROPOSED);

        $this->assertSame(
            0,
            (int) $this->pdo->query('SELECT COUNT(*) FROM event_log')->fetchColumn()
        );
    }

    // ── What the privacy policy has to say about it ─────────────────────

    /**
     * A message attached to a stay by its period is a new treatment, and
     * the policy is where a reader finds out it happens.
     *
     * Two documents, because a unit that regenerates its policy from the
     * prompt would otherwise silently lose the paragraph the shipped one
     * carries — the failure this repository has already had once, with the
     * map's own default.
     *
     * The label is read out of the enum rather than written here twice: a
     * test that hardcodes it goes on passing after a rename on one side,
     * which is the whole failure it exists for.
     */
    public function testTheRgpdPageAndItsPromptBothDescribeThisReading(): void
    {
        $root = dirname(__DIR__, 4);
        $page = (string) file_get_contents($root . '/core/View/rgpd_default.html');
        $prompt = (string) preg_replace(
            '/\s+/u',
            ' ',
            (string) file_get_contents($root . '/core/View/RgpdContentService.php')
        );

        foreach (['page' => $page, 'prompt' => $prompt] as $where => $text) {
            $this->assertStringContainsString(
                'Rattachement à un séjour déjà connu',
                $text,
                "the RGPD {$where} does not describe attaching a message to a stay by its period"
            );
            $this->assertStringContainsString(
                \Modules\InboundMail\Api\LinkOrigin::PERIOD->label(),
                $text,
                "the RGPD {$where} does not name the origin a reader sees on the screen"
            );
        }
    }

    private function matcher(?JournalService $journal = null): ExistingStayMatcher
    {
        return new ExistingStayMatcher($this->camps, new MessageReader(), $journal);
    }
}
