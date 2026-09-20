<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Document;

use Core\View\EditableContentService;

/**
 * The conditions a renter accepts on the public request form (§22.5).
 *
 * **The default used to be worse than "not documented".** The text is
 * per-asset rich content, and nothing shipped with the module — so while
 * nobody had written any, the request form showed no conditions at all and
 * still presented the mandatory « J'accepte les conditions de location »
 * tick-box. The renter accepted nothing, and `conditions_hash` faithfully
 * attested to nothing: a proof mechanism working perfectly over an empty
 * string. Worse, the only way to write that text was the generic
 * configuration mode on the asset's PUBLIC page, which needs a superadmin —
 * so the one person who could fix it was not the person who lets the hall.
 *
 * Three levels now, exactly like the contract (§22.6):
 *
 * 1. a standard Belgian body ships with the module
 *    (`StandardTemplates::conditions()`), so the text is never empty and a
 *    unit that has written nothing still produces complete conditions;
 * 2. the asset's managers edit it in rich text from the asset's own
 *    settings page — not from the configuration mode, not from the public
 *    page;
 * 3. the existing hash versioning does not change: it still proves what was
 *    accepted, and it now has something to prove.
 *
 * **The storage key is unchanged on purpose.** It is the same
 * `rental_asset_{id}_conditions` entry of the generic editable-content
 * store that the public page has always read, so every unit that already
 * wrote its conditions keeps them and nothing has to be migrated. What
 * changes is who may write it and from where.
 *
 * Static, and taking the store as an argument rather than holding it: every
 * caller already has one, and a service here would mean a constructor
 * parameter in three controllers and both composition roots for a class
 * with no state.
 */
final class AssetConditions
{
    /** Content key an asset's conditions live under. */
    public static function key(int $assetId): string
    {
        return 'rental_asset_' . $assetId . '_conditions';
    }

    /**
     * The conditions that are actually in force: the asset's own wording,
     * or the shipped standard while nobody has written any.
     *
     * This is what makes the shipped body a real DEFAULT rather than a
     * button, and it is the single source both the public page and the
     * acceptance hash read — so what a renter ticks is literally what they
     * were shown.
     */
    public static function textFor(EditableContentService $store, int $assetId): string
    {
        $stored = (string) ($store->get(self::key($assetId), '') ?? '');

        return self::isBlank($stored) ? StandardTemplates::conditions() : $stored;
    }

    /**
     * Whether $html would render as nothing at all.
     *
     * **`trim()` on the raw HTML is not this question**, and the difference
     * is the whole defect §22.5 exists to close. `<p>&nbsp;</p>` is a
     * non-empty string, carries a tag, and shows a visitor an empty box
     * above a mandatory « J'accepte les conditions de location » — which is
     * the case that used to arise from nobody having written anything, and
     * would arise again from a manager emptying the editor. A rich-text
     * surface leaves exactly that behind: `&nbsp;` is what a
     * `contenteditable` puts in a paragraph somebody blanked, and `trim()`
     * removes neither the entity nor the U+00A0 it decodes to.
     *
     * Same test, and for the same reason, as
     * `Modules\InboundMail\Service\MessageContentSanitizer::rendersNothing()`
     * — with one difference. There the images are already gone by the time
     * the question is asked; here they are not, and `strip_tags()` would
     * erase a conditions block made of one scanned page, silently replacing
     * somebody's own terms with the shipped standard. An image is content.
     */
    public static function isBlank(string $html): bool
    {
        if (preg_match('/<img\b/i', $html) === 1) {
            return false;
        }

        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // U+00A0 explicitly: trim() does not remove it, and it is exactly
        // what `&nbsp;` just became.
        return preg_replace('/[\s\x{00A0}]+/u', '', $text) === '';
    }

    /**
     * Whether $html is still the shipped standard, whitespace aside.
     *
     * The sanitizer may reflow markup on the way into storage, so an exact
     * comparison would misread a freshly reset text as a customised one.
     * Comparing without whitespace errs the other way — at worst the
     * « réinitialiser » action shows on a standard text and resetting is a
     * no-op, which is the harmless direction.
     */
    public static function isStandard(string $html): bool
    {
        $strip = static fn(string $value): string => (string) preg_replace('/\s+/u', '', $value);

        return $strip($html) === $strip(StandardTemplates::conditions());
    }
}
