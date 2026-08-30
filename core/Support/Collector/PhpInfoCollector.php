<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Support\Collector;

use Core\Support\SupportCollectorContext;
use Core\Support\SupportCollectorInterface;

/**
 * `phpinfo.html` — the full PHP configuration report, minus two sections.
 *
 * **`INFO_VARIABLES` is excluded, and that is not negotiable.** It prints
 * `$_SERVER`, `$_ENV` and `$_COOKIE`, which on this page means the live
 * session cookie of the superadmin who just clicked "generate" — still
 * valid, and enough to become them. This archive leaves the installation.
 *
 * **`INFO_ENVIRONMENT` is excluded for the same reason**, and this is worth
 * spelling out because it is easy to get wrong: `~INFO_VARIABLES` alone
 * does **not** remove the process environment. "PHP Variables" (the
 * superglobals) and "Environment" (what `getenv()` sees) are two separate
 * phpinfo sections behind two separate flags, and it is the second one that
 * carries the host's environment credentials — API tokens, proxy
 * credentials, database passwords injected by the platform. Masking only
 * the first would have shipped exactly the leak this exclusion exists to
 * prevent. Verified by test, not by reading the constant's name.
 *
 * Everything else phpinfo() reports — loaded modules, ini directives,
 * extensions, paths, limits — keeps its full diagnostic value and is
 * exactly what a support question about "it works locally but not on my
 * host" needs. Nothing else is redacted, deliberately: guessing at which
 * ini directive might be sensitive would either strip something diagnostic
 * or miss something anyway. That is precisely why the Support page and the
 * README both carry the "check the contents before sending" warning.
 */
class PhpInfoCollector implements SupportCollectorInterface
{
    public function name(): string
    {
        return 'phpinfo';
    }

    public function collect(SupportCollectorContext $context): void
    {
        if (!function_exists('phpinfo')) {
            $context->markUnavailable('phpinfo_disabled');

            return;
        }

        ob_start();
        // Never plain phpinfo(), never INFO_ALL, and never ~INFO_VARIABLES
        // on its own — the Environment section survives that one. See the
        // class docblock.
        phpinfo(INFO_ALL & ~INFO_VARIABLES & ~INFO_ENVIRONMENT);
        $html = (string) ob_get_clean();

        if (trim($html) === '') {
            $context->markUnavailable('phpinfo_produced_no_output');

            return;
        }

        $context->addFileFromContent('phpinfo.html', $html);
    }
}
