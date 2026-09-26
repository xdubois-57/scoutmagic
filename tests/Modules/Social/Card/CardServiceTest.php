<?php

declare(strict_types=1);

namespace Tests\Modules\Social\Card;

use Core\Journal\JournalService;
use Modules\Social\Card\CardRenderer;
use Modules\Social\Card\CardService;
use Modules\Social\Repository\CardRepository;
use PHPUnit\Framework\TestCase;
use Tests\Core\Http\Controller\RecordingJournalRepository;
use Tests\Core\Http\Controller\RemoteBackupSettingsDouble;
use Tests\DatabaseTestHelper;
use Tests\Modules\Social\SocialTestHelper as H;

/**
 * The ephemeral card: blurred whatever the setting says, reachable for an
 * hour by its token and never after, every access journalled without the
 * token.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class CardServiceTest extends TestCase
{
    private \PDO $pdo;
    private string $directory;
    private RecordingJournalRepository $journal;
    private RemoteBackupSettingsDouble $settings;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        H::createTables($this->pdo);
        $this->directory = sys_get_temp_dir() . '/social-cards-' . bin2hex(random_bytes(4));
        $this->journal = new RecordingJournalRepository();
        $this->settings = new RemoteBackupSettingsDouble([]);
        $this->now = new \DateTimeImmutable('2026-09-26 10:00:00');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function testAGalleryCardIsBlurredEvenIfTheSettingSaysNotTo(): void
    {
        $this->settings->values = [CardService::BLUR_SETTING => '0'];
        $service = $this->service();

        $this->assertSame(CardService::MIN_BLUR_RATIO, $service->blurRatio());

        $card = $service->issue(H::groupPhoto(), 'Camp', 'a.be', true, $this->now);
        $path = $service->open($this->token($card->path), $this->now);
        $this->assertNotNull($path);

        $sharp = H::sharpness((new CardRenderer())->render(H::groupPhoto(), 'Camp', 'a.be', false, 0.05));
        $this->assertLessThan($sharp / 4, H::sharpness((string) file_get_contents($path)));
    }

    public function testASettingCanOnlyMakeTheBlurStronger(): void
    {
        $this->settings->values = [CardService::BLUR_SETTING => '0.08'];

        $this->assertSame(0.08, $this->service()->blurRatio());
    }

    public function testTheCardIsServedWithinTheHourAndJournalledWithoutItsToken(): void
    {
        $service = $this->service();
        $card = $service->issue(H::groupPhoto(), 'Camp', 'a.be', true, $this->now);
        $token = $this->token($card->path);

        $this->assertMatchesRegularExpression('#^/partage/carte/[a-f0-9]{64}$#', $card->path);
        $this->assertEquals($this->now->modify('+60 minutes'), $card->expiresAt);
        $this->assertNotNull($service->open($token, $this->now->modify('+59 minutes')));

        $this->assertSame(1, $this->journal->countOf('card_served'));
        $this->assertStringNotContainsString($token, $this->journal->textOf('card_served'));
        $stored = (string) $this->scalar('SELECT token_hash FROM social_cards');
        $this->assertNotSame($token, $stored, 'Only the hash is stored.');
    }

    public function testTheTokenExpiresAndTheCardIsNeverServedAfter(): void
    {
        $service = $this->service();
        $card = $service->issue(H::groupPhoto(), 'Camp', 'a.be', true, $this->now);

        $this->assertNull($service->open($this->token($card->path), $this->now->modify('+60 minutes')));
        $this->assertNull($service->open($this->token($card->path), $this->now->modify('+2 days')));
        $this->assertSame(0, $this->journal->countOf('card_served'));
    }

    public function testAMalformedOrUnknownTokenOpensNothing(): void
    {
        $service = $this->service();
        $service->issue(H::groupPhoto(), 'Camp', 'a.be', true, $this->now);

        $this->assertNull($service->open('../../etc/passwd', $this->now));
        $this->assertNull($service->open(str_repeat('a', 64), $this->now));
    }

    public function testThePurgeDeletesExpiredCardsFilesAndRows(): void
    {
        $service = $this->service();
        $old = $service->issue(H::groupPhoto(), 'Old', 'a.be', true, $this->now->modify('-2 hours'));
        $live = $service->issue(H::groupPhoto(), 'Live', 'a.be', true, $this->now);

        $this->assertSame(1, $service->purgeExpired($this->now));

        $this->assertCount(1, glob($this->directory . '/*.jpg') ?: []);
        $this->assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM social_cards'));
        $this->assertNull($service->open($this->token($old->path), $this->now->modify('-90 minutes')));
        $this->assertNotNull($service->open($this->token($live->path), $this->now));
    }

    private function service(): CardService
    {
        return new CardService(
            new CardRepository($this->pdo),
            new CardRenderer(),
            $this->settings,
            new JournalService($this->journal),
            $this->directory
        );
    }

    private function scalar(string $sql): mixed
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();

        return $stmt->fetchColumn();
    }

    private function token(string $path): string
    {
        return substr($path, strlen(CardService::ROUTE_PREFIX));
    }
}
