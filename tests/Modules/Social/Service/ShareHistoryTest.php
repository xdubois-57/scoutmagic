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
     * **« avec le même message » is the sentence this whole dialog exists
     * for.** Sharing the same album again with a different text is
     * allowed on purpose; the identical text is the case that almost
     * always means somebody forgot they had already shared it.
     */
    public function testTheSentenceSaysWhetherTheMessageIsTheSame(): void
    {
        $this->share('Les photos sont en ligne', 'facebook', '2026-09-12 10:00:00');

        $same = $this->history->byDestination(ShareSource::KIND_ALBUM, self::ALBUM, 'Les photos sont en ligne');
        $other = $this->history->byDestination(ShareSource::KIND_ALBUM, self::ALBUM, 'Il en reste à voir !');

        $this->assertTrue($same['facebook']['same_message']);
        $this->assertSame(
            'Cet album est déjà parti sur Facebook le 12 septembre 2026, avec le même message.',
            $same['facebook']['line']
        );
        $this->assertFalse($other['facebook']['same_message']);
        $this->assertStringContainsString('avec un autre message', $other['facebook']['line']);
    }

    /** Surrounding whitespace is not a different message. */
    public function testWhitespaceAroundTheTextIsNotADifferentMessage(): void
    {
        $this->share('Les photos sont en ligne', 'facebook', '2026-09-12 10:00:00');

        $answer = $this->history->byDestination(
            ShareSource::KIND_ALBUM,
            self::ALBUM,
            "  Les photos sont en ligne\n"
        );

        $this->assertTrue($answer['facebook']['same_message']);
    }

    /**
     * A group is not named inside its own sentence: its name is already
     * the heading of the block the sentence sits in, and the mockup
     * writes it that way.
     */
    public function testAGroupsSentenceDoesNotRepeatItsName(): void
    {
        $this->share('Les photos sont en ligne', 'group:3', '2026-09-14 10:00:00');

        $line = $this->history->byDestination(ShareSource::KIND_ALBUM, self::ALBUM, 'Autre chose')['group:3']['line'];

        $this->assertSame('Déjà parti le 14 septembre 2026, avec un autre message.', $line);
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

        $answer = $this->history->byDestination(ShareSource::KIND_ALBUM, self::ALBUM, 'Le texte récent');

        $this->assertTrue($answer['facebook']['same_message']);
        $this->assertStringContainsString('20 septembre 2026', $answer['facebook']['line']);
    }

    /** A destination this source never went to is simply absent. */
    public function testADestinationNeverUsedIsAbsent(): void
    {
        $this->share('Les photos sont en ligne', 'facebook', '2026-09-12 10:00:00');

        $answer = $this->history->byDestination(ShareSource::KIND_ALBUM, self::ALBUM, 'Les photos sont en ligne');

        $this->assertArrayNotHasKey(SocialPlatform::Instagram->value, $answer);
    }

    /**
     * A communication sharing this album, published to one destination.
     *
     * @return int the communication's id
     */
    private function share(string $body, string $destination, string $when): int
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
        $this->publications->markPublished(
            ShareSource::KIND_COMMUNICATION,
            $id,
            $destination,
            'remote',
            $at,
            $at
        );

        return $id;
    }
}
