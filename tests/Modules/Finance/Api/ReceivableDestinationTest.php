<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Api;

use Modules\Finance\Api\ReceivableDestination;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A destination is printed as a link on a finance page from whatever a
 * source module answered: it must be a path on this site and nothing else
 * (issue #836).
 */
class ReceivableDestinationTest extends TestCase
{
    public function testAPathOnThisSiteIsAccepted(): void
    {
        $destination = new ReceivableDestination('Ouvrir la campagne', '/finance/campaigns/3');

        $this->assertSame('/finance/campaigns/3', $destination->url);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function addressesOffTheSite(): array
    {
        return [
            'absolute URL' => ['https://example.org/x'],
            'scheme-relative URL' => ['//example.org/x'],
            'javascript' => ['javascript:alert(1)'],
            'backslash trick' => ['/\\example.org'],
            'relative path' => ['finance/campaigns/3'],
        ];
    }

    #[DataProvider('addressesOffTheSite')]
    public function testAnythingButAPathOnThisSiteIsRefused(string $url): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ReceivableDestination('Ouvrir', $url);
    }

    public function testALinkWithoutWordsIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ReceivableDestination('  ', '/finance');
    }
}
