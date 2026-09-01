<?php

declare(strict_types=1);

namespace Tests\Modules\SupportDashboard;

use Modules\SupportDashboard\TicketCategory;
use PHPUnit\Framework\TestCase;

/**
 * The vocabulary has two owners, and that is the whole design: this
 * receiver publishes the fixed half for every installation at once, and
 * each installation mints the module half from the modules IT has
 * enabled. This side therefore has to accept a value it never published.
 */
class TicketCategoryTest extends TestCase
{
    public function testTheOfferedListRetiresInstallationAndOffersPrivacy(): void
    {
        $values = array_column(TicketCategory::published(), 'value');

        $this->assertNotContains('installation', $values);
        $this->assertContains('privacy', $values);
    }

    /**
     * A category that leaves the picker still has to name itself on the
     * tickets already filed under it. Two years of « Installation »
     * tickets reading « Non précisée » is what removing the case outright
     * would have cost.
     */
    public function testARetiredCategoryStaysReadableWithoutBeingOffered(): void
    {
        $this->assertNotContains('installation', array_column(TicketCategory::published(), 'value'));
        $this->assertSame('Installation', TicketCategory::of('installation')->label());
    }

    /**
     * « Autre » last: a picker that opens with the escape hatch gets
     * nothing else.
     */
    public function testTheEscapeHatchIsPublishedLast(): void
    {
        $published = TicketCategory::published();

        $this->assertSame(TicketCategory::OTHER, $published[array_key_last($published)]['value']);
    }

    /**
     * The module half never appears in what this receiver publishes: it
     * belongs to the sending installation, and offering a unit this
     * receiver's own modules would be offering it categories for
     * features it does not have.
     */
    public function testNoModuleCategoryIsEverPublished(): void
    {
        foreach (TicketCategory::published() as $entry) {
            $this->assertStringStartsNotWith(TicketCategory::MODULE_PREFIX, $entry['value']);
        }
    }

    public function testAModuleCategoryIsAcceptedOnTheStrengthOfItsShape(): void
    {
        $category = TicketCategory::tryFromValue('module_inbound_mail');

        $this->assertNotNull($category);
        $this->assertSame('module_inbound_mail', $category->value);
        // Named by its id and not by this receiver's own manifests: the
        // human name lives in the SENDER's module.json, and guessing it
        // from here is wrong precisely when it matters — a unit running a
        // module this receiver does not have.
        $this->assertSame('Module : inbound_mail', $category->label());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonsenseProvider(): array
    {
        return [
            'no module id at all' => ['module_'],
            'not a module id' => ['module_Pas Un Id'],
            'a path' => ['module_../../etc/passwd'],
            'plain nonsense' => ['brouette'],
            'empty' => [''],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nonsenseProvider')]
    public function testAnythingElseIsUnknown(string $value): void
    {
        $this->assertNull(TicketCategory::tryFromValue($value));
    }

    /**
     * Instances are interned, so the identity comparisons that were free
     * while this was an enum stay free.
     */
    public function testTheSameValueIsAlwaysTheSameObject(): void
    {
        $this->assertSame(TicketCategory::of('email'), TicketCategory::tryFromValue('email'));
        $this->assertSame(
            TicketCategory::tryFromValue('module_camps'),
            TicketCategory::tryFromValue('module_camps')
        );
    }

    public function testAnUnknownValueCannotBeDemanded(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        TicketCategory::of('brouette');
    }
}
