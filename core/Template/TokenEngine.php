<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Template;

/**
 * The `{{ … }}` substitution engine, shared by every catalogue.
 *
 * Two modules substituted `{{ … }}` with the same rules and nothing in
 * common: `Modules\MassMail\Service\MergeRenderer` (publipostage, tokens
 * are spreadsheet column headers) and `Modules\Rental\Document\
 * DocumentKeywords` (contracts and invoices, a closed catalogue). Both
 * substitute AFTER sanitizing, both always escape the value, both leave an
 * unknown token visible. Those four rules live here now; what stays with
 * each module is its own catalogue, and — for the publipostage — the
 * `{{#Colonne}} … {{/Colonne}}` sections, which mean nothing anywhere else.
 *
 * **Nothing here decides what a token may be called**: `TokenSyntax` does,
 * and it is a constructor argument precisely because the two vocabularies
 * disagree.
 *
 * ORDER OF OPERATIONS, AND WHY IT IS NOT NEGOTIABLE
 * --------------------------------------------------------------------
 * sanitize → decode → repair → substitute.
 *
 * Sanitizing last would run a sanitizer over a document that already
 * carries renter-supplied values, and a sanitizer's job is to decide what
 * markup is allowed, not whether a value should have been markup at all.
 * Decoding and repairing before substitution is what makes the two rescue
 * passes below have any effect: after substitution there is no token left
 * to rescue.
 */
final class TokenEngine
{
    /**
     * Inline markup a rich-text surface can drop INSIDE a token while
     * somebody types, and that the repair pass therefore removes from
     * between the braces.
     *
     * Block-level tags are deliberately absent. A `{{` in one paragraph
     * and a `}}` in the next is not a token somebody broke, it is two
     * stray braces in prose, and welding the paragraphs together to
     * "repair" it would rewrite the author's document.
     */
    private const INLINE_TAGS = [
        'a', 'b', 'big', 'br', 'code', 'em', 'font', 'i', 'mark', 's',
        'small', 'span', 'strike', 'strong', 'sub', 'sup', 'tt', 'u', 'wbr',
    ];

    public function __construct(private readonly TokenSyntax $syntax)
    {
    }

    /**
     * Whether this template personalises anything at all.
     *
     * A publipostage's subject very often carries no variable — the
     * variables live in the body — and then every recipient's subject is
     * the same string. Callers that must not handle per-recipient values
     * use this to tell the two cases apart instead of assuming a merge
     * always personalises.
     */
    public function containsToken(string $template): bool
    {
        return preg_match($this->syntax->pattern(), $template) === 1
            || preg_match($this->syntax->encodedPattern(), $template) === 1;
    }

    /**
     * Rewrites `%7B%7BNom%20de%20colonne%7D%7D` back to
     * `{{Nom de colonne}}` so everything downstream sees one shape.
     *
     * See `TokenSyntax::encodedPattern()` for how a token ends up encoded
     * in the first place.
     */
    public function decodeEncodedTokens(string $template): string
    {
        return (string) preg_replace_callback(
            $this->syntax->encodedPattern(),
            static fn(array $matches): string => '{{' . rawurldecode($matches[1]) . '}}',
            $template
        );
    }

    /**
     * Removes inline markup a rich-text surface left INSIDE a token.
     *
     * A `contenteditable` surface splits a run of text across elements as
     * it is edited, so `{{ prix_total }}` becomes `{{ pri<b>x</b>_total }}`
     * the moment somebody bolds a word that happens to overlap it — still
     * readable to a human, no longer a keyword to anything that
     * substitutes, and noticed only once a contract has gone out with
     * visible braces in it. That hazard is the whole reason the document
     * editors used to be `<textarea>`s.
     *
     * **A region is only rewritten once what it would become is a real
     * token.** The markup is stripped on a trial basis and the result is
     * matched against the syntax's own name pattern; anything else is put
     * back untouched. So `{{ le <strong>prix</strong> à payer }}` — prose
     * that happens to sit between braces — survives a repair pass
     * unchanged under the identifier syntax, and the existing "mots-clés
     * non reconnus" warning stays the net it was meant to be.
     *
     * Two shapes are handled, in this order:
     *
     * 1. a brace pair broken apart — `{<b>{` or `}</b>}` — which is what a
     *    caret parked between the two braces produces;
     * 2. markup between an intact `{{` and the next `}}`.
     *
     * Spacing is left exactly as the author had it — `{{ prix_total }}`
     * stays spaced — because the pattern tolerates it anyway and rewriting
     * it would be a second, invisible edit to somebody's document.
     */
    public function repairTokensSplitByMarkup(string $html): string
    {
        if (!str_contains($html, '<')) {
            return $html;
        }

        $inlineTag = '(?:<\/?(?:' . implode('|', self::INLINE_TAGS) . ')\b[^>]*>)+';

        // 1. The braces themselves, welded back together.
        $html = (string) preg_replace('/\{' . $inlineTag . '\{/i', '{{', $html);
        $html = (string) preg_replace('/\}' . $inlineTag . '\}/i', '}}', $html);

        // 2. Markup between an intact pair of braces. The inner part may
        //    not itself contain a brace pair, so a token is never merged
        //    with its neighbour.
        //
        //    `?? $html` rather than a cast: on invalid UTF-8 the `u`
        //    modifier makes the whole call return null, and handing back
        //    an empty string would delete somebody's document rather than
        //    decline to repair it.
        $repaired = preg_replace_callback(
            '/\{\{((?:(?!\{\{|\}\}).)*)\}\}/us',
            function (array $matches): string {
                if (!str_contains($matches[1], '<')) {
                    return $matches[0];
                }

                $stripped = (string) preg_replace(
                    '/<\/?(?:' . implode('|', self::INLINE_TAGS) . ')\b[^>]*>/i',
                    '',
                    $matches[1]
                );

                // The trial: only a region that spells a real token's name
                // once the markup is gone is rewritten.
                if (preg_match($this->syntax->nameOnlyPattern(), $stripped) !== 1) {
                    return $matches[0];
                }

                return '{{' . $stripped . '}}';
            },
            $html
        );

        return $repaired ?? $html;
    }

    /**
     * Every token the template carries, exactly as the pattern captured
     * it — untrimmed, duplicates kept, in order.
     *
     * The raw shape is what a caller needs when it has its own idea of
     * what the captured text means: the publipostage's section markers
     * name their column with a leading `#` or `/`, and a column header
     * that is whitespace is still a column header.
     *
     * @return string[]
     */
    public function rawTokenNames(string $template): array
    {
        if (preg_match_all($this->syntax->pattern(), $template, $matches) < 1) {
            return [];
        }

        return array_map(static fn($name): string => (string) $name, $matches[1]);
    }

    /**
     * The token names this template uses that nothing recognises — a typo
     * in a variable name would otherwise reach every recipient, or every
     * signature, as literal `{{Prénon}}` text.
     *
     * Reported to the author **while editing**, which is the only moment
     * anybody can still fix it.
     *
     * `$normalise` maps a captured name to the name to look up and to
     * report; returning an empty string drops the token from the report
     * entirely. It exists for the publipostage, whose section markers
     * spell their column with a leading `#` or `/` — the column is what a
     * typo is about, so the column is what gets reported.
     *
     * @param callable(string): bool        $isKnown
     * @param (callable(string): string)|null $normalise
     * @return string[] Distinct, in order of first appearance.
     */
    public function unknownTokens(string $template, callable $isKnown, ?callable $normalise = null): array
    {
        $unknown = [];
        foreach ($this->rawTokenNames($template) as $raw) {
            $name = trim($raw);
            if ($normalise !== null) {
                $name = $normalise($name);
            }
            if ($name === '' || $isKnown($name)) {
                continue;
            }
            $unknown[$name] = true;
        }

        return array_keys($unknown);
    }

    /**
     * Substitutes every token the resolver recognises.
     *
     * `$resolve` receives the token's name exactly as the template spells
     * it and answers with the RAW, unescaped value — or `null`, which
     * leaves the token exactly where it is. Leaving it is the deliberate
     * behaviour for a name nobody recognises: a contract with a visible
     * `{{ prix_ttc }}` in it is obviously wrong to whoever reads it,
     * whereas a silently emptied one reads as a clause that simply says
     * nothing.
     *
     * **`$escapeHtml` is true wherever the result is HTML**, and that is
     * the second of this engine's two safety rules. The values come from a
     * form an anonymous visitor filled in, or from a spreadsheet a chief
     * uploaded; without escaping, an organisation named
     * `Scouts <de> Nulle Part` is interpreted as markup by dompdf or by a
     * mail client and silently reshapes the document. It is false only in
     * a plain-text context — an e-mail subject — where the value is used
     * verbatim as text.
     *
     * @param callable(string): (string|null) $resolve
     */
    public function substitute(string $template, callable $resolve, bool $escapeHtml): string
    {
        $substituted = preg_replace_callback(
            $this->syntax->pattern(),
            static function (array $matches) use ($resolve, $escapeHtml): string {
                $value = $resolve(trim((string) $matches[1]));
                if ($value === null) {
                    return $matches[0];
                }

                return $escapeHtml ? htmlspecialchars($value, ENT_QUOTES, 'UTF-8') : $value;
            },
            $template
        );

        return $substituted ?? $template;
    }
}
