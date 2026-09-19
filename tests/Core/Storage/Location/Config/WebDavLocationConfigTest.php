<?php

declare(strict_types=1);

namespace Tests\Core\Storage\Location\Config;

use Core\Storage\Location\Config\WebDavLocationConfig;
use PHPUnit\Framework\TestCase;

/**
 * The record a WebDAV location keeps, and what it does with a column that
 * does not hold what it expected.
 */
class WebDavLocationConfigTest extends TestCase
{
    /**
     * Trailing slashes off, so that appending `/key` is the only join rule
     * this type ever needs: `…/scoutmagic` and `…/scoutmagic/` would
     * otherwise name two different collections.
     */
    public function testATrailingSlashIsRemovedOnTheWayIn(): void
    {
        $config = WebDavLocationConfig::fromArray([
            'base_url' => 'https://cloud.example.org/dav/scoutmagic//',
            'username' => '  unite  ',
        ]);

        $this->assertSame('https://cloud.example.org/dav/scoutmagic', $config->baseUrl);
        $this->assertSame('unite', $config->username);
    }

    /**
     * **A query string or a fragment does not belong in the address of a
     * collection, and does not survive the join.** Every key is appended
     * to this string, so a base of `…/dav#partage` becomes
     * `…/dav#partage/12/med_3.jpg`; cURL drops everything from the `#`
     * before sending, and every key resolves to `…/dav`. The SSRF check
     * reads the scheme, the host and the port, so neither is caught
     * there.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function addressesCarryingMoreThanAPath(): array
    {
        return [
            'a fragment' => ['https://cloud.example.org/dav#partage', 'https://cloud.example.org/dav'],
            'a query string' => ['https://cloud.example.org/dav?x=1', 'https://cloud.example.org/dav'],
            'both' => ['https://cloud.example.org/dav?x=1#y', 'https://cloud.example.org/dav'],
            'a fragment after a slash' => ['https://cloud.example.org/dav/#y', 'https://cloud.example.org/dav'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('addressesCarryingMoreThanAPath')]
    public function testTheAddressKeepsItsPathAndNothingAfterIt(string $given, string $expected): void
    {
        $this->assertSame($expected, WebDavLocationConfig::normaliseBaseUrl($given));
    }

    /**
     * **A `(string)` cast turns a JSON array into the literal « Array »**,
     * with a PHP warning beside it, and the location would then be active
     * and pointed at nothing. This record comes out of a column: a row
     * written by an older version, a restore from somewhere else, a
     * hand-edited configuration. The empty fallback is already the « not
     * configured » state every reader here handles.
     *
     * @return list<array{mixed}>
     */
    public static function valuesThatAreNotStrings(): array
    {
        return [
            'an array' => [[]],
            'a number' => [42],
            'null' => [null],
            'a boolean' => [true],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('valuesThatAreNotStrings')]
    public function testAStoredFieldThatIsNotAStringIsReadAsNotConfigured(mixed $value): void
    {
        $config = WebDavLocationConfig::fromArray(['base_url' => $value, 'username' => $value]);

        $this->assertSame('', $config->baseUrl);
        $this->assertSame('', $config->username);
        $this->assertSame('Partage WebDAV non configuré', $config->describe());
    }

    /**
     * No URL is handed to a visitor from here, whatever the address: a
     * share answers nobody without the credentials this site holds.
     */
    public function testAShareNeverServesAVisitorDirectly(): void
    {
        $config = new WebDavLocationConfig('https://cloud.example.org/dav', 'unite');

        $this->assertFalse($config->servesPubliclyWithoutExpiry());
    }

    /** The host is diagnostic; what follows it names the account. */
    public function testItDescribesItselfByHostAndNotByPath(): void
    {
        $config = new WebDavLocationConfig(
            'https://cloud.example.org/remote.php/dav/files/marie.dupont/scoutmagic',
            'marie.dupont'
        );

        $this->assertSame('Partage WebDAV sur cloud.example.org', $config->describe());
        $this->assertStringNotContainsString('marie.dupont', $config->describe());
    }
}
