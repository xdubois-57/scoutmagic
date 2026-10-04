<?php

declare(strict_types=1);

namespace Tests\Modules\Gallery\Service;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\System\CronExecutionFacts;
use Modules\Gallery\Service\FfmpegAvailability;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * Whether video can be transcoded is the CRON's answer (#700), read from
 * what public/cron.php stored — never a probe from the web request.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class FfmpegAvailabilityTest extends TestCase
{
    private SettingService $settings;

    protected function setUp(): void
    {
        $this->settings = new SettingService(new SettingRepository(DatabaseTestHelper::createTestDatabase()));
        CronExecutionFacts::register($this->settings);
    }

    private function store(bool $works, ?string $ffmpeg, ?string $ffprobe): void
    {
        $facts = new CronExecutionFacts(time(), 'cli', true, $works, 'exec', 'code 0', $ffmpeg, $ffprobe);
        $this->settings->setInternal(CronExecutionFacts::SETTING, (string) json_encode($facts->toArray()));
    }

    public function testNeverMeasuredIsUnknownAndRefused(): void
    {
        $availability = new FfmpegAvailability($this->settings);

        $this->assertSame(FfmpegAvailability::UNKNOWN, $availability->state());
        $this->assertFalse($availability->check());
    }

    public function testTheCronFindingBothProgramsMakesVideoAvailable(): void
    {
        $this->store(true, '/usr/bin/ffmpeg', '/usr/bin/ffprobe');

        $availability = new FfmpegAvailability($this->settings);
        $this->assertSame(FfmpegAvailability::AVAILABLE, $availability->state());
        $this->assertTrue($availability->check());
    }

    /**
     * @return iterable<string, array{bool, ?string, ?string}>
     */
    public static function missing(): iterable
    {
        yield 'cron cannot run commands' => [false, null, null];
        yield 'ffmpeg absent' => [true, null, '/usr/bin/ffprobe'];
        yield 'ffprobe absent' => [true, '/usr/bin/ffmpeg', null];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('missing')]
    public function testAnythingTheCronLacksIsMissing(bool $works, ?string $ffmpeg, ?string $ffprobe): void
    {
        $this->store($works, $ffmpeg, $ffprobe);

        $this->assertSame(FfmpegAvailability::MISSING, (new FfmpegAvailability($this->settings))->state());
    }

    /**
     * The web PHP's own ability to run a program is not read at all: the
     * class has no probe of its own any more.
     */
    public function testNothingIsProbedFromTheWebRequest(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 4) . '/modules/gallery/src/Service/FfmpegAvailability.php'
        );

        $this->assertStringNotContainsString('exec(', $source);
        $this->assertStringNotContainsString('ShellExecutor', $source);
        $this->assertStringNotContainsString('ExecutableLocator', $source);
    }
}
