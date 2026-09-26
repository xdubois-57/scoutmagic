<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\ExternalSource;

/**
 * The deterministic half of issue #355's watch on external pages: fetch
 * every registered source, and say for each one whether it still is what
 * the site assumes. Claude sits on top of this (the weekly workflow and
 * `.claude/skills/external-sources`), to understand a changed page and
 * draft the ticket; this class only decides, and decides the same way
 * every time — which is what lets the sixth release gate trust it.
 *
 * The rules, per kind:
 *
 * - CONTENT: 200, and every expected string present. A redirect is a note,
 *   except for a source whose consumer refuses redirects, where it is a
 *   divergence. On the federal fees page the three amounts must be
 *   readable, and when a reference scale is given they must equal it.
 * - LINK: alive on 2xx, 3xx, 401 and 403 (a console redirecting to its
 *   sign-in page, or refusing an anonymous visitor, still exists); dead on
 *   anything else — 404, 410, an unknown domain, no answer, a server error.
 *
 * THE REFERENCE SCALE is the federal scale shipped with the site
 * (`modules/fees/data/federal-scale.json`, issue #355), which
 * `scripts/check-external-sources.php` passes in: the page's amounts must
 * equal it. Without one — a caller that passes none — the fees page result
 * says in a note that nothing was compared, rather than pretending a
 * comparison happened.
 */
final class ExternalSourceChecker
{
    public function __construct(
        private readonly PageFetcherInterface $fetcher,
        private readonly ?FederalScale $referenceScale = null,
    ) {
    }

    /**
     * @param list<ExternalSource> $sources
     * @return list<SourceCheckResult>
     */
    public function checkAll(array $sources): array
    {
        return array_map(fn (ExternalSource $source): SourceCheckResult => $this->check($source), $sources);
    }

    public function check(ExternalSource $source): SourceCheckResult
    {
        $page = $this->fetcher->fetch($source->url);

        if ($page->status === 0) {
            return new SourceCheckResult(
                $source,
                0,
                ['no answer (' . ($page->error ?? 'unknown error') . ') — unknown domain, or the host is down']
            );
        }

        return $source->kind === ExternalSourceKind::Link
            ? $this->checkLink($source, $page)
            : $this->checkContent($source, $page);
    }

    /**
     * True when every result conforms — the script's exit code.
     *
     * @param list<SourceCheckResult> $results
     */
    public static function allConform(array $results): bool
    {
        foreach ($results as $result) {
            if (!$result->isConform()) {
                return false;
            }
        }

        return true;
    }

    /**
     * A plain-text report: every divergence first, so a release refusal
     * reads its reason on the first screen, then the conforming sources
     * with any notes.
     *
     * @param list<SourceCheckResult> $results
     */
    public static function report(array $results): string
    {
        $divergent = array_values(array_filter($results, static fn (SourceCheckResult $r): bool => !$r->isConform()));
        $conform = array_values(array_filter($results, static fn (SourceCheckResult $r): bool => $r->isConform()));

        $lines = [];
        foreach ($divergent as $result) {
            $lines[] = self::heading('DIVERGENT', $result);
            foreach ($result->divergences as $divergence) {
                $lines[] = '    ✗ ' . $divergence;
            }
            foreach ($result->notes as $note) {
                $lines[] = '    · ' . $note;
            }
            $lines[] = '    used in: ' . ($result->source->usedIn === [] ? '(nothing yet)'
                : implode(', ', $result->source->usedIn));
            if ($result->source->dependentDefault !== null) {
                $lines[] = '    shipped default: ' . $result->source->dependentDefault;
            }
        }
        foreach ($conform as $result) {
            $lines[] = self::heading('ok', $result);
            foreach ($result->notes as $note) {
                $lines[] = '    · ' . $note;
            }
        }

        $lines[] = '';
        $lines[] = sprintf(
            '%d source(s) checked: %d conform, %d divergent.',
            count($results),
            count($conform),
            count($divergent)
        );

        return implode("\n", $lines) . "\n";
    }

    private function checkLink(ExternalSource $source, FetchedPage $page): SourceCheckResult
    {
        $notes = self::redirectNotes($page);
        $status = $page->status;
        $alive = ($status >= 200 && $status < 400) || $status === 401 || $status === 403;

        if ($alive) {
            return new SourceCheckResult($source, $status, [], $notes);
        }

        return new SourceCheckResult($source, $status, [self::deadReason($status)], $notes);
    }

    private function checkContent(ExternalSource $source, FetchedPage $page): SourceCheckResult
    {
        $divergences = [];
        $notes = [];

        if ($page->redirectedTo !== null && $source->redirectIsDivergence) {
            $divergences[] = 'redirects to ' . $page->redirectedTo . ' — the code reading this page refuses '
                . 'redirects, so the registered URL (and the shipped default) must move to the new address';
        } else {
            $notes = self::redirectNotes($page);
        }

        if ($page->status !== 200) {
            $divergences[] = self::deadReason($page->status);

            return new SourceCheckResult($source, $page->status, $divergences, $notes);
        }

        $text = FederalScaleExtractor::text($page->body);
        foreach ($source->expectedContent as $expected) {
            if (mb_stripos($text, $expected) === false) {
                $divergences[] = 'expected content « ' . $expected . ' » not found — the page was replaced, '
                    . 'or no longer says what the site links it for';
            }
        }

        $scale = null;
        if ($source->id === ExternalSources::FEES_PAGE_ID) {
            $scale = FederalScaleExtractor::extract($page->body);
            if ($scale === null) {
                $divergences[] = 'the normal, couple and family amounts could not all be read off the page';
            } else {
                $notes[] = 'amounts on the page: ' . $scale->describe();
                if ($this->referenceScale === null) {
                    $notes[] = 'no shipped scale to compare them with yet — amounts reported, not compared';
                } elseif (!$scale->sameAmountsAs($this->referenceScale)) {
                    $divergences[] = 'amounts changed: the page says ' . $scale->describe()
                        . '; the shipped scale says ' . $this->referenceScale->describe();
                }
            }
        }

        return new SourceCheckResult($source, $page->status, $divergences, $notes, $scale);
    }

    /**
     * @return list<string>
     */
    private static function redirectNotes(FetchedPage $page): array
    {
        if ($page->redirectedTo === null) {
            return [];
        }

        return [
            ($page->movedPermanently ? 'moved permanently to ' : 'redirects to ') . $page->redirectedTo,
        ];
    }

    private static function deadReason(int $status): string
    {
        return match (true) {
            $status === 404, $status === 410 => 'HTTP ' . $status . ' — the page is gone',
            $status >= 500 => 'HTTP ' . $status . ' — server error',
            default => 'HTTP ' . $status,
        };
    }

    private static function heading(string $verdict, SourceCheckResult $result): string
    {
        return sprintf(
            '[%s] %s (%s, HTTP %s) %s',
            $verdict,
            $result->source->id,
            $result->source->kind->value,
            $result->status === 0 ? '—' : (string) $result->status,
            $result->source->url
        );
    }
}
