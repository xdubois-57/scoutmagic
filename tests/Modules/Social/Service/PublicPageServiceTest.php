<?php

declare(strict_types=1);

namespace Tests\Modules\Social\Service;

use Core\Journal\JournalService;
use Modules\Social\Repository\Communication;
use Modules\Social\Repository\CommunicationRepository;
use Modules\Social\Service\PublicPageService;
use Modules\Social\Service\ShareSource;
use PHPUnit\Framework\TestCase;
use Tests\Core\Http\Controller\RecordingJournalRepository;
use Tests\Core\Http\Controller\RemoteBackupSettingsDouble;
use Tests\DatabaseTestHelper;
use Tests\Modules\Social\SocialTestHelper as H;

/**
 * When a communication's public page comes into being, and when it must
 * not (issue #706, IT-04).
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class PublicPageServiceTest extends TestCase
{
    private \PDO $pdo;
    private CommunicationRepository $communications;
    private RecordingJournalRepository $journal;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        H::createTables($this->pdo);
        $this->communications = new CommunicationRepository($this->pdo);
        $this->journal = new RecordingJournalRepository();
    }

    /**
     * **Minted once.** Facebook caches the preview of a link a chief has
     * already posted and comes back to re-read the page days later, so a
     * second token would orphan a page nobody can edit any more.
     */
    public function testThePageIsMintedOnceAndTheSameAddressComesBack(): void
    {
        $id = $this->communication();
        $service = $this->service();

        $first = $service->ensureUrlFor($id, new \DateTimeImmutable());
        $second = $service->ensureUrlFor($id, new \DateTimeImmutable('+1 day'));

        $this->assertSame($first, $second);
        $token = (string) $this->communications->find($id)?->publicToken;
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token, '256 random bits, in hexadecimal.');
        $this->assertSame('https://unite.example/partage/' . $token, $first);
        $this->assertSame(1, $this->journal->countOf('public_page_created'), 'One page, one journal line.');
    }

    /**
     * **Reading never mints**, which is what lets every screen showing a
     * communication ask for its address: a page that exists stays online
     * for good, so one must not appear because a draft was opened.
     */
    public function testReadingAnAddressNeverCreatesOne(): void
    {
        $id = $this->communication();
        $communication = $this->communications->find($id);
        self::assertNotNull($communication);

        $this->assertNull($this->service()->urlFor($communication));
        $this->assertNull($this->service()->imageUrlFor($communication));
        $this->assertNull($this->communications->find($id)?->publicToken);
        $this->assertSame(0, $this->journal->countOf('public_page_created'));
    }

    /**
     * The image's address is the page's, under the same token: one
     * capability, two things it opens.
     */
    public function testTheImageLivesUnderThePagesOwnAddress(): void
    {
        $id = $this->communication();
        $url = (string) $this->service()->ensureUrlFor($id, new \DateTimeImmutable());
        $communication = $this->communications->find($id);
        self::assertNotNull($communication);

        $this->assertSame($url, $this->service()->urlFor($communication));
        $this->assertSame($url . '/carte.jpg', $this->service()->imageUrlFor($communication));
    }

    /**
     * **No address, no page, and no token either.** What goes to Facebook
     * is an absolute URL and nothing can invent one from a request, so a
     * token minted here would authorise nothing and would only be in the
     * way the day `base_url` is filled in.
     */
    public function testWithoutTheSitesOwnAddressNothingIsMinted(): void
    {
        $id = $this->communication();
        $service = new PublicPageService(
            $this->communications,
            new RemoteBackupSettingsDouble([]),
            new JournalService($this->journal)
        );

        $this->assertFalse($service->isAvailable());
        $this->assertNull($service->ensureUrlFor($id, new \DateTimeImmutable()));
        $this->assertNull($this->communications->find($id)?->publicToken);
    }

    public function testACommunicationThatIsNotThereHasNoPage(): void
    {
        $this->assertNull($this->service()->ensureUrlFor(4242, new \DateTimeImmutable()));
    }

    /**
     * **A malformed token is refused before the database is asked**, like
     * the card route's. The repository double below fails the test if it
     * is reached, which is the only way to see a check that otherwise
     * answers null either way.
     */
    public function testAMalformedTokenIsRefusedWithoutAskingTheDatabase(): void
    {
        $service = new PublicPageService(
            new class ($this->pdo) extends CommunicationRepository {
                public function findByPublicToken(string $token): ?Communication
                {
                    throw new \LogicException('The database was asked about ' . $token . '.');
                }
            },
            new RemoteBackupSettingsDouble(['base_url' => 'https://unite.example']),
            new JournalService($this->journal)
        );

        $this->assertNull($service->findByToken('abc'));
        $this->assertNull($service->findByToken(''));
        // Uppercase is not the shape this site mints, and the 64
        // characters are not enough on their own.
        $this->assertNull($service->findByToken(str_repeat('A', 64)));
        $this->assertNull($service->findByToken(str_repeat('z', 64)));
    }

    /** A well-formed token the database has never seen answers null too. */
    public function testAWellFormedButUnknownTokenAnswersNull(): void
    {
        $this->assertNull($this->service()->findByToken(str_repeat('0', 64)));
    }

    public function testATokenOpensItsOwnCommunicationAndNoOther(): void
    {
        $mine = $this->communication();
        // A different text, because since IT-05 the same source with the
        // same text and the same chief IS the same communication, and
        // `create()` hands the first row back
        // (`uq_social_communications_twin`, issue #793). Two rows need
        // two messages.
        $other = $this->communication('Et il y en a d\'autres');
        $url = (string) $this->service()->ensureUrlFor($mine, new \DateTimeImmutable());
        $token = substr($url, -64);

        $found = $this->service()->findByToken($token);

        $this->assertSame($mine, $found?->id);
        $this->assertNotSame($other, $found?->id);
    }

    /**
     * **Two publications racing to publish the same communication mint at
     * most one page.** The UPDATE writes `WHERE public_token IS NULL`, so
     * the second one changes nothing and reads the first one's token
     * back.
     */
    public function testASecondMintCannotReplaceTheFirst(): void
    {
        $id = $this->communication();
        $now = new \DateTimeImmutable();

        $this->communications->assignPublicToken($id, str_repeat('a', 64), $now);
        $this->communications->assignPublicToken($id, str_repeat('b', 64), $now);

        $this->assertSame(str_repeat('a', 64), $this->communications->find($id)?->publicToken);
    }

    /**
     * **One page, one journal line** — even when a second caller runs the
     * mint and reads the first one's token back (CodeRabbit, on the pull
     * request for IT-04; carried here because IT-05 is what touches this
     * file next).
     *
     * `claim()` allows a confirmed retry of a stale pending attempt, so
     * two callers CAN overlap on one communication. The row written first
     * wins, and the loser must not claim to have created anything: a
     * duplicate « Page publique créée » at `security` level misleads an
     * audit, which is the only reason that line exists.
     *
     * **Simulated where the race actually is**, which a pre-set token
     * cannot do: with one already stored, `ensureUrlFor()` returns before
     * it ever mints, so that version of this test would have proved the
     * early return and called it the guard. The repository double below
     * answers « no token » to the first read and the winner's token to
     * the second — the exact state a loser finds, between its own read
     * and its own write.
     */
    public function testASecondCallerThatLosesTheRaceJournalsNothing(): void
    {
        $id = $this->communication();
        $winner = str_repeat('a', 64);
        $repository = new class ($this->pdo, $id, $winner) extends CommunicationRepository {
            private int $reads = 0;

            public function __construct(\PDO $pdo, private readonly int $id, private readonly string $winner)
            {
                parent::__construct($pdo);
            }

            public function find(int $id): ?Communication
            {
                $this->reads++;

                // First read: nothing there yet, so this caller mints.
                // Second read: the winner's row, written in between.
                return new Communication(
                    $this->id,
                    '',
                    'Les photos sont en ligne',
                    null,
                    null,
                    7,
                    new \DateTimeImmutable(),
                    null,
                    null,
                    null,
                    null,
                    $this->reads === 1 ? null : $this->winner
                );
            }

            /** The winner already wrote; `WHERE public_token IS NULL` matches nothing. */
            public function assignPublicToken(int $id, string $token, \DateTimeImmutable $now): void
            {
            }
        };

        $url = (new PublicPageService(
            $repository,
            new RemoteBackupSettingsDouble(['base_url' => 'https://unite.example']),
            new JournalService($this->journal)
        ))->ensureUrlFor($id, new \DateTimeImmutable());

        // The address handed back is the one that was STORED, never the
        // token this caller generated and lost.
        $this->assertSame('https://unite.example/partage/' . $winner, $url);
        $this->assertSame(0, $this->journal->countOf('public_page_created'), 'it created nothing to announce');
    }

    private function service(): PublicPageService
    {
        return new PublicPageService(
            $this->communications,
            new RemoteBackupSettingsDouble(['base_url' => 'https://unite.example/']),
            new JournalService($this->journal)
        );
    }

    private function communication(string $body = 'Les photos sont en ligne'): int
    {
        return $this->communications->create(
            '',
            $body,
            7,
            new \DateTimeImmutable(),
            ShareSource::KIND_ALBUM,
            3
        );
    }
}
