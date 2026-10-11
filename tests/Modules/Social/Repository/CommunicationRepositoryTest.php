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
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        H::createTables($pdo);
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

    private function create(): int
    {
        return $this->repository->create('Week-end', 'Les photos sont en ligne', 7, $this->now);
    }
}
