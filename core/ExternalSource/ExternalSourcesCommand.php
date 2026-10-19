<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\ExternalSource;

/**
 * What `scripts/check-external-sources.php` does once it has autoloaded:
 * read its arguments, load the shipped reference scale, check every
 * registered source, print the report and answer the exit code (0 conform,
 * 1 divergent, 2 could not run).
 *
 * It lives here, not in the script, so that it is tested: the script keeps
 * only what cannot be — the CLI guard, the autoloader, and the real network
 * and shipped scale plugged in. The one option is `--retry-unreachable`
 * (issue #760): a source that did not answer at all is asked a second time.
 * Only the release gate passes it; the weekly check asks each source once.
 */
final class ExternalSourcesCommand
{
    public const RETRY_OPTION = '--retry-unreachable';
    private const RETRY_ATTEMPTS = 2;

    /**
     * @param \Closure(): FederalScale $loadReferenceScale the scale shipped
     *        with the site, which Core cannot name (Modules\Fees owns it);
     *        throws \UnexpectedValueException when it cannot be read
     */
    public function __construct(
        private readonly PageFetcherInterface $fetcher,
        private readonly \Closure $loadReferenceScale,
    ) {
    }

    /**
     * @param list<string> $arguments the command line after the script name
     * @param resource $out the report
     * @param resource $err what could not be done
     */
    public function run(array $arguments, $out, $err): int
    {
        // Not silently ignored: a mistyped option must not run a check the
        // caller believes is configured otherwise.
        $unknown = array_values(array_diff($arguments, [self::RETRY_OPTION]));
        if ($unknown !== []) {
            fwrite($err, 'Unknown option ' . $unknown[0] . '. The only option is ' . self::RETRY_OPTION . ".\n");

            return 2;
        }

        // The scale shipped with the site is the reference: the fees page's
        // three amounts must equal it, or the fees source diverges and the
        // report names both. A shipped file that cannot be read is a broken
        // checkout, not a finding about the page.
        try {
            $referenceScale = ($this->loadReferenceScale)();
        } catch (\UnexpectedValueException $e) {
            fwrite($err, 'Shipped federal scale unreadable: ' . $e->getMessage() . "\n");

            return 2;
        }

        $attempts = in_array(self::RETRY_OPTION, $arguments, true) ? self::RETRY_ATTEMPTS : 1;
        $results = (new ExternalSourceChecker($this->fetcher, $referenceScale, $attempts))
            ->checkAll(ExternalSources::all());

        fwrite($out, ExternalSourceChecker::report($results));

        return ExternalSourceChecker::allConform($results) ? 0 : 1;
    }
}
