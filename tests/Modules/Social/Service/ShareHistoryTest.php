<?php

declare(strict_types=1);

namespace Tests\Modules\Social\Service;

use Core\Security\UserAccountRepository;
use Modules\Social\Api\SocialPlatform;
use Modules\Social\Repository\CommunicationRepository;
use Modules\Social\Repository\PublicationRepository;
use Modules\Social\Service\ShareHistory;
use Modules\Social\Service\ShareSource;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Social\SocialTestHelper as H;

/**
 * What a SOURCE has already been through (issue #706, IT-05) — read
 * across every communication sharing it, which is the whole point:
 * since IT-05 the same album with a different message is a new
 * communication, so a chief opening the composer for the third time has
 * two earlier ones behind them.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class ShareHistoryTest extends TestCase
{
    private const ALBUM = 42;

    private \PDO $pdo;
    private CommunicationRepository $communications;
    private PublicationRepository $publications;
    private ShareHistory $history;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        H::createTables($this->pdo);
        $this->communications = new CommunicationRepository($this->pdo);
        $this->publications = new PublicationRepository($this->pdo);
        $this->history = new ShareHistory(
            $this->communications,
            $this->publications,
            new UserAccountRepository($this->pdo, H::encryption())
        );
    }

    /**
     * **Across communications, not within one.** Two messages about one
     * album are two rows, and « cet album est déjà parti sur Facebook »
     * is true of the album whichever row carried it. Reading only the row
     * in hand would show an empty history to the one person who needs it
     * most.
     */
    public function testEveryCommunicationSharingTheSourceIsRead(): void
    {
        $first = $this->share('Les photos sont en ligne', 'facebook', '2026-09-12 10:00:00');
        $second = $this->share('Il en reste à voir !', 'instagram', '2026-09-14 10:00:00');
        $this->assertNotSame($first, $second, 'two messages, two communications');

        $lines = $this->history->lines(ShareSource::KIND_ALBUM, self::ALBUM);

        $this->assertCount(2, $lines);
        // Newest first: what a chief is looking for is what just left.
        $this->assertSame('Instagram', $lines[0]['label']);
        $this->assertSame('Page Facebook', $lines[1]['label']);
    }

    /** A share made by hand is in it, named by its gesture. */
    public function testAHandMadeShareAppearsLikeAnyOther(): void
    {
        $this->share('Les photos sont en ligne', 'copy', '2026-09-15 10:00:00');

        $lines = $this->history->lines(ShareSource::KIND_ALBUM, self::ALBUM);

        $this->assertSame('Lien copié', $lines[0]['label']);
    }

    /**
     * A communication with no source has no « already » to show: there is
     * nothing it could be the second share of, and its own destinations
     * already carry their state on the page.
     */
    public function testACommunicationWithNoSourceHasNoHistory(): void
    {
        $this->assertSame([], $this->history->lines(null, null));
    }

    /**
     * **The sentence stops before « avec le même message », and the
     * caption travels beside it** (`Claude review` on PR #868).
     *
     * That clause compares the text about to go, which sits in a
     * textarea the chief can still change after this page was rendered —
     * so a verdict decided here is a verdict about a text that may no
     * longer exist. `social-publish-confirm.js` finishes the sentence
     * against the live field; this answers the two halves it needs.
     */
    public function testTheSentenceStopsBeforeTheVerdictAndCarriesTheCaption(): void
    {
        $this->share('Les photos sont en ligne', 'facebook', '2026-09-12 10:00:00');

        $answer = $this->history->byDestination(ShareSource::KIND_ALBUM, self::ALBUM);

        $this->assertSame(
            [
                'sentence' => 'Cet album est déjà parti sur Facebook le 12 septembre 2026',
                'caption' => 'Les photos sont en ligne',
            ],
            $answer['facebook']
        );
    }

    /**
     * A group is not named inside its own sentence: its name is already
     * the heading of the block the sentence sits in, and the mockup
     * writes it that way.
     */
    public function testAGroupsSentenceDoesNotRepeatItsName(): void
    {
        $this->share('Les photos sont en ligne', 'group:3', '2026-09-14 10:00:00');

        $sentence = $this->history->byDestination(ShareSource::KIND_ALBUM, self::ALBUM)['group:3']['sentence'];

        $this->assertSame('Déjà parti le 14 septembre 2026', $sentence);
    }

    /**
     * **The most recent share speaks for its destination.** It is the
     * date a chief would recognise, and the text they would remember
     * sending — an older one would date the warning wrongly and could
     * call « the same message » a text that has since been superseded.
     */
    public function testTheMostRecentShareSpeaksForADestination(): void
    {
        $this->share('Un texte ancien', 'facebook', '2026-09-01 10:00:00');
        $this->share('Le texte récent', 'facebook', '2026-09-20 10:00:00');

        $answer = $this->history->byDestination(ShareSource::KIND_ALBUM, self::ALBUM);

        $this->assertSame(
            ['sentence' => 'Cet album est déjà parti sur Facebook le 20 septembre 2026', 'caption' => 'Le texte récent'],
            $answer['facebook']
        );
    }

    /**
     * **A failed attempt is not a share** (`Claude review` on PR #868).
     *
     * `claim()` writes the row `pending` before Meta is called and leaves
     * it `failed` when Meta refuses, so the table holds attempts too.
     * « Cet album est déjà parti sur Facebook » built from one of those
     * is false, and it would be said to the one person at that moment
     * deciding whether to send it — turning a block meant to prevent an
     * oversight into one that invents one. The failure's own reason and
     * its « Réessayer » live on « Ce qui est parti », which reads the
     * rows itself.
     */
    public function testAFailedAttemptIsNeitherALineNorASentence(): void
    {
        $this->recordAttempt('Les photos sont en ligne', 'facebook', '2026-09-12 10:00:00', published: false);

        // Both answers in one assertion: PHPUnit stops at the first
        // failure, and dropping the filter breaks them both.
        $this->assertSame(
            ['lines' => [], 'sentences' => []],
            [
                'lines' => $this->history->lines(ShareSource::KIND_ALBUM, self::ALBUM),
                'sentences' => $this->history->byDestination(ShareSource::KIND_ALBUM, self::ALBUM),
            ]
        );
    }

    /**
     * And a destination whose last attempt failed speaks with the share
     * that DID leave, not with the failure: the chief is owed the date
     * recipients actually saw.
     */
    public function testADestinationSpeaksWithItsLastShareAndNotItsLastFailure(): void
    {
        $this->recordAttempt('Le texte parti', 'facebook', '2026-09-12 10:00:00', published: true);
        $this->recordAttempt('Le texte refusé', 'facebook', '2026-09-20 10:00:00', published: false);

        $answer = $this->history->byDestination(ShareSource::KIND_ALBUM, self::ALBUM);

        // The date AND the caption of the share that left: the refused
        // text went nowhere, so it is neither this destination's date
        // nor the text the browser will compare against.
        $this->assertSame(
            ['sentence' => 'Cet album est déjà parti sur Facebook le 12 septembre 2026', 'caption' => 'Le texte parti'],
            $answer['facebook']
        );
    }

    /**
     * **A discussion group reads under its own name**, kept on the
     * publication row, and falls back to « Groupe de discussion » for a
     * row written before that column existed — a group is not a
     * `SocialPlatform` and not a `DirectShare`, so it is the one label
     * the service cannot look up.
     */
    public function testAGroupIsNamedInTheBlockByTheNameItWasPublishedUnder(): void
    {
        $this->share('Les photos sont en ligne', 'group:3', '2026-09-14 10:00:00');

        $this->assertSame(
            'Groupe de discussion',
            $this->history->lines(ShareSource::KIND_ALBUM, self::ALBUM)[0]['label'],
            'the row carries no destination_label, so the generic name stands'
        );
    }

    /**
     * An article is « cette actualité » and not « cet album »: the
     * sentence names what is being shared, and getting it wrong would
     * read as a bug on the one screen that quotes it.
     */
    public function testAnArticleIsNamedAsOneInTheSentence(): void
    {
        $at = new \DateTimeImmutable('2026-09-12 10:00:00');
        $id = $this->communications->create('', 'À lire', 7, $at, ShareSource::KIND_ARTICLE, 8);
        $this->publications->claim(ShareSource::KIND_COMMUNICATION, $id, 'facebook', false, 7, $at, $at->modify('-15 minutes'), 'Rentrée', 'À lire');
        $this->publications->markPublished(ShareSource::KIND_COMMUNICATION, $id, 'facebook', 'remote', $at, $at);

        $sentence = $this->history->byDestination(ShareSource::KIND_ARTICLE, 8)['facebook']['sentence'];

        $this->assertStringStartsWith('Cette actualité est déjà partie', $sentence);
    }

    /** A destination this source never went to is simply absent. */
    public function testADestinationNeverUsedIsAbsent(): void
    {
        $this->share('Les photos sont en ligne', 'facebook', '2026-09-12 10:00:00');

        $answer = $this->history->byDestination(ShareSource::KIND_ALBUM, self::ALBUM);

        $this->assertArrayNotHasKey(SocialPlatform::Instagram->value, $answer);
    }

    /**
     * A communication sharing this album, published to one destination.
     *
     * @return int the communication's id
     */
    private function share(string $body, string $destination, string $when): int
    {
        return $this->recordAttempt($body, $destination, $when, published: true);
    }

    /**
     * The same, with the outcome chosen: a published row, or one Meta
     * refused.
     *
     * @return int the communication's id
     */
    private function recordAttempt(string $body, string $destination, string $when, bool $published): int
    {
        $at = new \DateTimeImmutable($when);
        $id = $this->communications->create(
            '',
            $body,
            7,
            $at,
            ShareSource::KIND_ALBUM,
            self::ALBUM
        );
        $this->publications->claim(
            ShareSource::KIND_COMMUNICATION,
            $id,
            $destination,
            false,
            7,
            $at,
            $at->modify('-15 minutes'),
            'Week-end de rentrée',
            $body
        );
        if ($published) {
            $this->publications->markPublished(
                ShareSource::KIND_COMMUNICATION,
                $id,
                $destination,
                'remote',
                $at,
                $at
            );
        } else {
            $this->publications->markFailed(
                ShareSource::KIND_COMMUNICATION,
                $id,
                $destination,
                'Meta a refusé la publication.',
                $at
            );
        }

        return $id;
    }
}
