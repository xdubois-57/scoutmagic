<?php

declare(strict_types=1);

namespace Tests\Modules\Rental\Service;

use Core\Security\EncryptionService;
use Modules\Rental\Repository\RentalAssetRepository;
use Modules\Rental\Service\RentalSlugGenerator;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Rental\RentalTestHelper;

class RentalSlugGeneratorTest extends TestCase
{
    private RentalAssetRepository $assetRepository;
    private RentalSlugGenerator $generator;

    protected function setUp(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        RentalTestHelper::createTables($pdo);

        $this->assetRepository = new RentalAssetRepository(
            $pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
        $this->generator = new RentalSlugGenerator($this->assetRepository);
    }

    private function createWithSlug(string $slug): int
    {
        return $this->assetRepository->create('Local', $slug, $slug, null, 1, null, null, null, true);
    }

    public function testSlugifyLowercasesAndHyphenates(): void
    {
        $this->assertSame('local-saint-georges', RentalSlugGenerator::slugify('Local Saint-Georges'));
        $this->assertSame('le-local', RentalSlugGenerator::slugify('  Le   Local  '));
    }

    public function testSlugifyTransliteratesAccentsRatherThanStrippingThem(): void
    {
        // Stripping would give "local-saint-gry", which is unreadable and
        // collides with unrelated names far more often.
        $this->assertSame('local-saint-gery', RentalSlugGenerator::slugify('Local Saint-Géry'));
        $this->assertSame('materiel-de-camp', RentalSlugGenerator::slugify('Matériel de camp'));
        $this->assertSame('foret-doree', RentalSlugGenerator::slugify('Forêt dorée'));
    }

    public function testSlugifyDropsPunctuationAndCollapsesSeparators(): void
    {
        $this->assertSame('local-n-2', RentalSlugGenerator::slugify('Local n°2'));
        $this->assertSame('tentes-4-places', RentalSlugGenerator::slugify('Tentes (4 places)'));
        $this->assertSame('a-b', RentalSlugGenerator::slugify('---A---B---'));
    }

    public function testSlugifyFallsBackForANameWithNoUsableCharacter(): void
    {
        // An empty slug would produce a broken URL; the collision loop then
        // makes the fallback unique.
        $this->assertSame('bien', RentalSlugGenerator::slugify('🏕️'));
        $this->assertSame('bien', RentalSlugGenerator::slugify(''));
        $this->assertSame('bien', RentalSlugGenerator::slugify('---'));
    }

    public function testSlugifyTruncatesAVeryLongNameWithoutTrailingSeparator(): void
    {
        $slug = RentalSlugGenerator::slugify(str_repeat('local ', 60));

        $this->assertLessThanOrEqual(150, strlen($slug));
        $this->assertStringEndsNotWith('-', $slug);
    }

    public function testGenerateReturnsTheBaseSlugWhenFree(): void
    {
        $this->assertSame('local-saint-georges', $this->generator->generate('Local Saint-Georges'));
    }

    public function testGenerateDisambiguatesACollisionRatherThanFailing(): void
    {
        // Two units genuinely can own two things called "Le local".
        $this->createWithSlug('le-local');

        $this->assertSame('le-local-2', $this->generator->generate('Le local'));

        $this->createWithSlug('le-local-2');
        $this->assertSame('le-local-3', $this->generator->generate('Le local'));
    }

    public function testGenerateCollidesWithAnArchivedAssetToo(): void
    {
        // An archived asset keeps its slug reserved so an old link is never
        // silently repointed at a different asset.
        $id = $this->createWithSlug('le-local');
        $this->assetRepository->setArchived($id, true);

        $this->assertSame('le-local-2', $this->generator->generate('Le local'));
    }

    public function testGenerateIgnoresTheAssetBeingRenamed(): void
    {
        $id = $this->createWithSlug('le-local');

        $this->assertSame('le-local', $this->generator->generate('Le local', $id));
    }

    public function testGenerateFallsBackToARandomSuffixPastTheBoundedLoop(): void
    {
        // The loop is bounded so a misbehaving uniqueness check can never
        // hang a request; past the bound it still returns a usable slug
        // rather than an error.
        $this->createWithSlug('le-local');
        for ($i = 2; $i <= 200; $i++) {
            $this->createWithSlug('le-local-' . $i);
        }

        $slug = $this->generator->generate('Le local');

        $this->assertStringStartsWith('le-local-', $slug);
        $this->assertFalse($this->assetRepository->slugExists($slug));
        $this->assertMatchesRegularExpression('/^le-local-[0-9a-f]{8}$/', $slug);
    }
}
