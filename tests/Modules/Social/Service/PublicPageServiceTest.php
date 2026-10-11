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
        $other = $this->communication();
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

    private function service(): PublicPageService
    {
        return new PublicPageService(
            $this->communications,
            new RemoteBackupSettingsDouble(['base_url' => 'https://unite.example/']),
            new JournalService($this->journal)
        );
    }

    private function communication(): int
    {
        return $this->communications->create(
            '',
            'Les photos sont en ligne',
            7,
            new \DateTimeImmutable(),
            ShareSource::KIND_ALBUM,
            3
        );
    }
}
