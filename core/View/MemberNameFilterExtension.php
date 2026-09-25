<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\View;

use Core\Member\MemberProfile;
use Core\Service\TextNormalizerService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Every way a template is allowed to name a person.
 *
 * Three filters, and the difference between them is a decision about
 * privacy and about who can recognise whom — not a formatting taste:
 * `display_name` is the ordinary label (the totem when there is one),
 * `full_name` is the legal identity ALONE (what a postal address is
 * addressed to), and `display_name_full` is the totem with that identity
 * beside it, for the places where a bare totem tells a reader who does
 * not know it nothing at all.
 *
 * **An extension rather than closures inside `TwigFactory`**, for the
 * reason `DateFilterExtension` gives at length: a test that builds its
 * own `Environment` cannot reach a closure registered inside `create()`,
 * so it re-implements the one filter its template happens to need. Six
 * test files carried their own `display_name`, and four of the six had
 * dropped the array branch the menu builder relies on —
 * `Tests\Core\Http\Controller\StaffsControllerTest`'s copy answers
 * `''` for an array where this one answers the totem. Not one of them
 * failed, because the templates they render happen to pass a
 * `MemberProfile`. That is the shape of the problem, not a reprieve: six
 * copies were free to drift from the original and nothing was watching
 * any of them.
 *
 * `Tests\Core\View\DisplayNameFilterTest` is the sharp end of it: a
 * test named after this filter, asserting six behaviours of a copy of it
 * pasted into its own `setUp()`. Returning `'MUTANT'` from the real
 * `display_name` left those six green — and so did the other 2 210 tests
 * in the 77 files that build the real environment (issue #465). One
 * `addExtension(new MemberNameFilterExtension())` is the whole of it
 * now, on both sides.
 */
class MemberNameFilterExtension extends AbstractExtension
{
    /**
     * @return array<int, TwigFilter>
     */
    public function getFilters(): array
    {
        return [
            new TwigFilter('display_name', [self::class, 'displayName']),
            // first name + surname, NEVER the totem. Used where the site
            // must show a person's legal identity alone (e.g. a postal
            // address needs the name it's actually addressed to) — do not
            // use this for ordinary member display.
            new TwigFilter('full_name', [self::class, 'fullName']),
            // "Totem (Prénom Nom)" when a totem is set, else just
            // "Prénom Nom". Used wherever a totem would otherwise be
            // shown on its own (member page: badge/référent holder lists,
            // section responsable) so a reader who doesn't know the totem
            // can still identify the person.
            new TwigFilter('display_name_full', [self::class, 'displayNameFull']),
        ];
    }

    public static function displayName(mixed $member): string
    {
        if ($member instanceof MemberProfile) {
            return $member->getDisplayName();
        }
        // Also handle arrays (from menu builder)
        if (is_array($member)) {
            return (string) ($member['totem'] ?? $member['first_name'] ?? '?');
        }

        return (string) $member;
    }

    public static function fullName(mixed $member): string
    {
        if ($member instanceof MemberProfile) {
            return trim(
                TextNormalizerService::normalizeName($member->firstName)
                . ' ' . TextNormalizerService::normalizeName($member->lastName)
            );
        }
        if (is_array($member)) {
            $first = (string) ($member['first_name'] ?? '');
            $last = (string) ($member['last_name'] ?? '');
            return trim(
                TextNormalizerService::normalizeName($first)
                . ' ' . TextNormalizerService::normalizeName($last)
            );
        }

        return (string) $member;
    }

    public static function displayNameFull(mixed $member): string
    {
        // The rule lives on the model, so a page that assembles its
        // labels in PHP says the same thing as one that assembles them in
        // a template — which is how the re-registration form came to show
        // a bare totem.
        if ($member instanceof MemberProfile) {
            return $member->getDisplayNameFull();
        }

        $full = self::fullName($member);
        $totem = is_array($member) ? ($member['totem'] ?? null) : null;

        if ($totem) {
            return TextNormalizerService::normalizeTotem((string) $totem) . ' (' . $full . ')';
        }

        return $full;
    }
}
