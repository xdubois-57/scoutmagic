<?php

declare(strict_types=1);

namespace Tests\Core\Support\Ticket;

use Core\Support\SupportPackageFactory;
use Core\Support\Ticket\ArchiveContents;
use PHPUnit\Framework\TestCase;

/**
 * The consent screen has to say what it is about (roadmap IT-26).
 *
 * A collector shipped without a French sentence would appear in the
 * archive and not in the list an administrator ticks a box beside — which
 * is the difference between consent and a formality. That cannot be
 * caught by rendering the page, because a missing entry renders as a
 * shorter list and nothing else, so it is caught here.
 */
final class ArchiveContentsTest extends TestCase
{
    public function testEveryShippedCollectorHasASentenceSomebodyCanRead(): void
    {
        $described = ArchiveContents::all();

        foreach (SupportPackageFactory::collectorNames() as $name) {
            $this->assertArrayHasKey(
                $name,
                $described,
                "The archive collects '{$name}' but Core\\Support\\Ticket\\ArchiveContents has no French "
                . 'description for it — the consent screen would list one line fewer than what actually leaves.'
            );
            $this->assertNotSame('', trim($described[$name]));
        }
    }

    public function testTheListedNamesAreTheOnesTheArchiveIsMadeOf(): void
    {
        $this->assertSame(
            SupportPackageFactory::collectorNames(),
            array_keys(ArchiveContents::all()),
            'the descriptions and the collectors must be the same set, in the same order'
        );
    }

    /**
     * An unknown collector is named rather than hidden: a partial list is
     * a worse failure than an honest « nous ne savons pas décrire ceci ».
     */
    public function testAnUndescribedEntryIsStillListed(): void
    {
        $described = ArchiveContents::describe(['statistics', 'quelque_chose_de_neuf']);

        $this->assertCount(2, $described);
        $this->assertStringContainsString('non décrite', $described[1]['description']);
    }

    /**
     * The sentences are shown to a French-speaking administrator on the
     * page where they decide. An English one would be a bug in the same
     * way a French identifier is (AGENTS.md § Language).
     */
    public function testEverySentenceIsInFrenchAndEndsProperly(): void
    {
        foreach (ArchiveContents::all() as $name => $sentence) {
            $this->assertMatchesRegularExpression('/[.!?]$/u', $sentence, $name . ' must read as a sentence');
            $this->assertGreaterThan(20, mb_strlen($sentence), $name . ' says too little to consent to');
        }
    }
}
