<?php

declare(strict_types=1);

namespace Tests\Modules\Social\Repository;

use Modules\Social\Repository\CommunicationRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Social\SocialTestHelper as H;

/**
 * The two columns issue #706, IT-02 adds, as the database holds them:
 * the blur the chief chose and the card the browser drew.
 *
 * `AGENTS.md` § Tests asks for every repository method to be tested
 * against a test database, and these two had nothing — found in the
 * review of #850, on the headline feature of the iteration. The
 * controller exercises them too, but through a POST; this says what the
 * columns do on their own, including the state they start in.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class CommunicationRepositoryTest extends TestCase
{
    private CommunicationRepository $repository;
    private \PDO $pdo;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        H::createTables($pdo);
        $this->pdo = $pdo;
        $this->repository = new CommunicationRepository($pdo);
        $this->now = new \DateTimeImmutable('2026-10-10 21:00:00');
    }

    /**
     * **NULL, not zero**, and the difference carries meaning: a
     * communication nobody has moved the slider on has no chosen blur,
     * and the publication then uses the site's own starting position. A
     * stored zero means « Net », which a chief asked for.
     */
    public function testACommunicationStartsWithNeitherABlurNorACard(): void
    {
        $communication = $this->repository->find($this->create());

        $this->assertNotNull($communication);
        $this->assertNull($communication->blurRatio, 'a fresh share must not read as « Net »');
        $this->assertNull($communication->cardFileId);
    }

    public function testTheChosenBlurIsStoredAndReadBack(): void
    {
        $id = $this->create();

        $this->repository->updateBlurRatio($id, 0.075, $this->now);

        $this->assertEqualsWithDelta(0.075, (float) $this->repository->find($id)?->blurRatio, 0.0001);
    }

    /** « Net » is a value, and has to survive the round trip as one. */
    public function testNetIsStoredAsZeroRatherThanAsNothing(): void
    {
        $id = $this->create();

        $this->repository->updateBlurRatio($id, 0.0, $this->now);

        $stored = $this->repository->find($id)?->blurRatio;
        $this->assertNotNull($stored, '« Net » came back as « never chosen »');
        $this->assertEqualsWithDelta(0.0, (float) $stored, 0.0001);
    }

    public function testTheCardFileIsStoredAndReadBack(): void
    {
        $id = $this->create();

        $this->repository->updateCardFile($id, 4242, $this->now);

        $this->assertSame(4242, $this->repository->find($id)?->cardFileId);
    }

    /**
     * Null is a write like any other, and the way a card that no longer
     * shows what the share would publish stops being named — the chief
     * raised the blur and posted no new card, so the publication must
     * compose a current one rather than reuse that file
     * (`CommunicationController::dropStoredCard()`). Covered here
     * because the column is `NOT NULL`-looking in every other test.
     */
    public function testACardCanBeForgottenAgain(): void
    {
        $id = $this->create();
        $this->repository->updateCardFile($id, 4242, $this->now);

        $this->repository->updateCardFile($id, null, $this->now);

        $this->assertNull($this->repository->find($id)?->cardFileId);
    }

    /**
     * Each writes its own column and leaves the other alone: they are
     * set at different moments — the blur as the slider moves, the card
     * at « Publier » — and a write that cleared its neighbour would lose
     * one of them without a sound.
     */
    public function testEachWriteLeavesTheOtherColumnAlone(): void
    {
        $id = $this->create();

        $this->repository->updateBlurRatio($id, 0.05, $this->now);
        $this->repository->updateCardFile($id, 7, $this->now);
        $this->repository->updateBlurRatio($id, 0.15, $this->now);

        $communication = $this->repository->find($id);
        $this->assertEqualsWithDelta(0.15, (float) $communication?->blurRatio, 0.0001);
        $this->assertSame(7, $communication?->cardFileId, 'the card was lost when the blur was written again');
    }

    /**
     * The public page's token, written once and read back by it (issue
     * #706, IT-04).
     *
     * **The empty token is refused before the query**, not matched
     * against the column. Every row written before IT-04 carries NULL
     * there, which `= ''` would never match anyway — but a caller asking
     * for nothing should get an answer that does not depend on that
     * coincidence, and this is the layer where « nothing » arrives.
     */
    public function testAPublicTokenIsAssignedOnceAndOpensItsOwnRow(): void
    {
        $mine = $this->create();
        $other = $this->create();

        $this->repository->assignPublicToken($mine, str_repeat('a', 64), $this->now);
        // The second write finds the column already set and changes
        // nothing: re-minting would orphan a page already published.
        $this->repository->assignPublicToken($mine, str_repeat('b', 64), $this->now);

        $this->assertSame(str_repeat('a', 64), $this->repository->find($mine)?->publicToken);
        $this->assertNull($this->repository->find($other)?->publicToken);
        $this->assertSame($mine, $this->repository->findByPublicToken(str_repeat('a', 64))?->id);
        $this->assertNull($this->repository->findByPublicToken(str_repeat('b', 64)));
        $this->assertNull($this->repository->findByPublicToken(''), 'an empty token must not be asked of the column');
    }

    /**
     * **The same source, the same text and the same chief are the same
     * communication** (issue #793, #706 IT-05), and `create()` answers
     * the row already there rather than making a second one.
     *
     * The duplicate key IS the signal: no SELECT runs before the INSERT,
     * so there is no window two simultaneous POSTs can both pass —
     * which is what the 120-second read this replaces could not close.
     */
    public function testTheSameShareTwiceAnswersTheRowAlreadyThere(): void
    {
        $first = $this->repository->create('', 'Les photos sont en ligne', 7, $this->now, 'album', 3);
        $again = $this->repository->create('', 'Les photos sont en ligne', 7, $this->now, 'album', 3);

        $this->assertSame($first, $again);
        $this->assertSame(1, $this->rows());
        // Nothing of the first row is touched: its text is what the first
        // share settled.
        $this->assertSame('Les photos sont en ligne', $this->repository->find($first)?->body);
    }

    /**
     * **Four things make a twin**, and changing any one of them makes a
     * new communication: the text (« permis, avec avertissement » is the
     * chantier's rule for it), the source, the album, and the chief —
     * two animateurs sharing the same album with the same words are two
     * people who each decided to.
     */
    public function testChangingAnyOneOfTheFourMakesANewCommunication(): void
    {
        $this->repository->create('', 'Les photos sont en ligne', 7, $this->now, 'album', 3);

        $this->repository->create('', 'Il en reste à voir !', 7, $this->now, 'album', 3);
        $this->repository->create('', 'Les photos sont en ligne', 7, $this->now, 'album', 4);
        $this->repository->create('', 'Les photos sont en ligne', 7, $this->now, 'article', 3);
        $this->repository->create('', 'Les photos sont en ligne', 8, $this->now, 'album', 3);

        $this->assertSame(5, $this->rows());
    }

    /**
     * **A communication written from nothing is never a twin.** Its
     * `body_hash` stays null, so the key matches no row — and that is the
     * behaviour wanted, not a loophole: without a source there is
     * nothing to be a duplicate OF, and two « Bonne année » posts are two
     * posts somebody wrote twice on purpose.
     */
    public function testTwoFreeCommunicationsWithTheSameTextAreTwoCommunications(): void
    {
        $first = $this->repository->create('Bonne année', 'Meilleurs vœux', 7, $this->now);
        $second = $this->repository->create('Bonne année', 'Meilleurs vœux', 7, $this->now);

        $this->assertNotSame($first, $second);
        $this->assertSame(2, $this->rows());
    }

    private function rows(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM social_communications')->fetchColumn();
    }

    private function create(): int
    {
        return $this->repository->create('Week-end', 'Les photos sont en ligne', 7, $this->now);
    }
}
