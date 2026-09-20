<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Template;

/**
 * A CLOSED, declared list of `{{ mot_cle }}` substitutions, and the
 * variable palette a rich-text field shows beside the editor.
 *
 * Closed on purpose. An open substitution mechanism is one where a
 * template can reach any value the renderer happens to have in scope;
 * here a template can say exactly these things and nothing else, and
 * anything it asks for that is not on the list is reported to its author
 * at edit time rather than rendered as literal braces in a signed
 * contract.
 *
 * The palette shape — `{keyword, placeholder, description}` — is what
 * `partials/rich_text_form_field.html.twig` reads as its `placeholders`
 * argument: one insert button per entry, and every occurrence in the value
 * rendered as one indivisible chip. Handing that partial a catalogue is
 * therefore the whole wiring; nothing else is needed to give a module's
 * text field a variable palette.
 *
 * **A catalogue is not a spreadsheet's column headers.** The publipostage
 * substitutes `{{Prénom 1}}` from whatever an uploaded file happens to
 * declare — free text, chosen by whoever built the file, different for
 * every mailing. That is data, not a declared list, which is why it has
 * its own insertion control and why nothing here describes it.
 */
final class TokenCatalogue
{
    /**
     * @param array<string, string> $descriptions keyword => French explanation shown beside the editor
     */
    private function __construct(private readonly array $descriptions)
    {
    }

    /**
     * @param array<string, string> $descriptions keyword => French explanation
     */
    public static function of(array $descriptions): self
    {
        return new self($descriptions);
    }

    public function has(string $keyword): bool
    {
        return array_key_exists($keyword, $this->descriptions);
    }

    /**
     * @return string[]
     */
    public function keywords(): array
    {
        return array_keys($this->descriptions);
    }

    public function describe(string $keyword): ?string
    {
        return $this->descriptions[$keyword] ?? null;
    }

    /**
     * The palette, in declaration order.
     *
     * @return array<int, array{keyword: string, placeholder: string, description: string}>
     */
    public function palette(): array
    {
        $entries = [];
        foreach ($this->descriptions as $keyword => $description) {
            $entries[] = [
                'keyword' => $keyword,
                'placeholder' => '{{ ' . $keyword . ' }}',
                'description' => $description,
            ];
        }

        return $entries;
    }
}
