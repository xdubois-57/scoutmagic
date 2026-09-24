<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\OfficialDocuments\Value;

use Modules\OfficialDocuments\Pdf\HealthSheetLayout;
use Modules\OfficialDocuments\Value\HealthSheet;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The words a parent reads when something does not fit — and the reason
 * they cannot become English identifiers.
 *
 * When a value overruns the federation's printed line, the health sheet
 * screen names the box to shorten. It resolves that name through
 * `HealthSheet::LABELS`, and an answer missing from that map used to reach
 * the page as its raw key: a bullet reading literally `contact2_email`, in
 * front of a family. CLAUDE.md calls that what it is — « an English UI
 * label is a bug, never a detail » — and this file is what keeps the two
 * lists from drifting apart the next time a field is added.
 *
 * **Both directions matter, for different reasons.** A missing label is
 * the defect above. A label for an answer that no longer exists is dead
 * French nobody will ever see, and it is the thing that makes the first
 * check stop being trustworthy.
 */
final class HealthSheetLabelsTest extends TestCase
{
    /**
     * Every answer the sheet stores has a label, and every label names an
     * answer that exists.
     *
     * `conditions` is the one exception, written out rather than filtered
     * silently: it is twelve tick-boxes with no line of their own to
     * overflow, and the screen labels them where it draws them.
     */
    public function testTheLabelsAndTheAnswersAreTheSameList(): void
    {
        $answers = array_keys(HealthSheet::empty()->toArray());
        $expected = array_values(array_diff($answers, ['conditions']));

        $this->assertSame(
            $expected,
            array_keys(HealthSheet::LABELS),
            'an answer with no label would come out on the page as is, in English'
        );
    }

    /**
     * **The invariant the screen actually depends on.** Every printed line
     * of the form either belongs to the site — the ten identity lines a
     * parent cannot reach, which the screen never mentions — or resolves to
     * an answer with a French label.
     *
     * Nothing in between, which is the whole point: a field added to the
     * layout without either is one the screen would silently drop from a
     * warning the parent needs.
     */
    #[DataProvider('layoutFieldProvider')]
    public function testEveryPrintedLineIsEitherTheSitesOrLabelled(string $field): void
    {
        if (in_array($field, HealthSheetLayout::siteSuppliedNames(), true)) {
            $this->assertArrayNotHasKey(
                $field,
                HealthSheet::LABELS,
                $field . ' is a line the site supplies: the parent cannot shorten it, '
                . 'and the screen must not name it to them.'
            );

            return;
        }

        $this->assertArrayHasKey(
            HealthSheetLayout::answerFor($field),
            HealthSheet::LABELS,
            $field . ' can overflow and has no French label, so the page would show its internal name.'
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function layoutFieldProvider(): array
    {
        $cases = [];
        foreach (array_keys(HealthSheetLayout::textFields()) as $field) {
            $cases[$field] = [$field];
        }

        return $cases;
    }

    /**
     * And the labels are French sentences rather than identifiers that
     * happen to have been copied across.
     *
     * Crude on purpose — no test settles whether a French label is a GOOD
     * one — but it catches the mechanical failure: a key pasted in as its
     * own value, which is exactly the shape of the bug this map exists to
     * prevent.
     */
    #[DataProvider('labelProvider')]
    public function testALabelIsProseAndNotAnIdentifier(string $answer, string $label): void
    {
        $this->assertNotSame('', trim($label), $answer);
        $this->assertStringNotContainsString('_', $label, $answer . ' — its label is a field name.');
        $this->assertMatchesRegularExpression(
            '/^\p{Lu}/u',
            $label,
            $answer . ' — a label shown to a parent starts with a capital letter.'
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function labelProvider(): array
    {
        $cases = [];
        foreach (HealthSheet::LABELS as $answer => $label) {
            $cases[$answer] = [$answer, $label];
        }

        return $cases;
    }
}
