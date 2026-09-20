<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\OfficialDocuments\Service;

use Core\Import\AgeBranchRepository;
use Core\Member\MemberProfile;

/**
 * What goes on the parental authorization, and what gets crossed out — as a
 * decision, with no PDF anywhere near it.
 *
 * Apart from `ParentalAuthorizationPdfService` on purpose. Every
 * interesting rule of this document lives here — which branch is struck,
 * what happens to a member who is in none of the four, whether the
 * out-of-Belgium sentence stays — and all of them are decisions about
 * strings, so they are testable as strings. Reading them back out of
 * rendered PDF bytes would be a test that agrees with whatever the code
 * did.
 */
final class ParentalAuthorizationFilling
{
    /**
     * Age branches, as the form prints them, mapped to the canonical sort
     * order `Core\Import\AgeBranchRepository` gives every branch label.
     *
     * Reading the branch through that function rather than comparing labels
     * is what makes « Éclaireurs », « eclaireurs » and « Éclaireurs (mixte) »
     * one answer. Staff d'U (50), Route (60), Iama (70) and anything unknown
     * (99) are absent on purpose — see strikes().
     */
    public const BRANCH_STRIKE_BY_SORT_ORDER = [
        10 => 'branch_baladins',
        20 => 'branch_louveteaux',
        30 => 'branch_eclaireurs',
        40 => 'branch_pionniers',
    ];

    /**
     * Roughly what the first dotted line of « Adresse complète » holds at
     * the nominal size. A longer address is split over the form's two lines
     * rather than shrunk: two readable lines beat one line of six-point type
     * on a document somebody reads in a hurry.
     */
    private const ADDRESS_ONE_LINE_CHARS = 70;

    /**
     * Every value the site writes, keyed by the layout's field names.
     *
     * @return array<string, string>
     */
    public static function values(
        MemberProfile $member,
        ?MemberProfile $leader,
        string $unit,
        ParentalAuthorizationInput $input,
        \DateTimeImmutable $today
    ): array {
        [$address, $addressOverflow] = self::splitAddress($leader);

        return [
            'leader_first_name' => $leader === null ? '' : $leader->firstName,
            'leader_last_name' => $leader === null ? '' : $leader->lastName,
            'leader_address' => $address,
            'leader_address_overflow' => $addressOverflow,
            'signatory_name' => $input->signatoryName,
            // The member's legal identity, never their totem: this is the
            // person the federation's form names, and « Akéla » is not who
            // an insurer or a hospital is looking for.
            'member_name' => trim($member->firstName . ' ' . $member->lastName),
            'unit' => $unit,
            'start_date' => $input->startDate->format('d/m/Y'),
            'end_date' => $input->endDate->format('d/m/Y'),
            'place' => $input->place,
            'today' => $today->format('d/m/Y'),
        ];
    }

    /**
     * Which printed mentions get a stroke.
     *
     * @return list<string>
     */
    public static function strikes(MemberProfile $member, ParentalAuthorizationInput $input): array
    {
        $strikes = [];

        // Three of the four capacities: the one chosen stays.
        foreach (SignatoryCapacity::all() as $capacity) {
            if ($capacity !== $input->capacity) {
                $strikes[] = $capacity->strikeZone();
            }
        }

        // The branches the member is not in — and NOTHING when they are in
        // none of the four. A Staff d'U member, an Iama, or a unit that
        // invented a branch of its own would otherwise come out with all
        // four struck, which says « none of these » on a form whose sentence
        // needs one of them to stand.
        $own = self::branchStrikeFor($member);
        if ($own !== null) {
            foreach (self::BRANCH_STRIKE_BY_SORT_ORDER as $zone) {
                if ($zone !== $own) {
                    $strikes[] = $zone;
                }
            }
        }

        // Note (1): the last sentence is struck for activities in Belgium,
        // which is the ordinary case and therefore the default.
        if (!$input->abroad) {
            $strikes[] = 'abroad_line_1';
            $strikes[] = 'abroad_line_2';
        }

        return $strikes;
    }

    /**
     * The strike zone of the member's own branch, or null when their branch
     * is not one of the four the form prints.
     */
    public static function branchStrikeFor(MemberProfile $member): ?string
    {
        $branch = $member->getMainFunction()?->branchName;
        if ($branch === null || trim($branch) === '') {
            return null;
        }

        return self::BRANCH_STRIKE_BY_SORT_ORDER[AgeBranchRepository::canonicalSortOrder($branch)] ?? null;
    }

    /**
     * The leader's postal address over the form's two dotted lines.
     *
     * One line where it fits; split on the last comma before the cut where
     * it does not — an address is written « rue, complément, code localité »,
     * so a comma is where a reader would break it too.
     *
     * @return array{string, string}
     */
    public static function splitAddress(?MemberProfile $leader): array
    {
        $address = '';
        foreach ($leader === null ? [] : $leader->addresses as $candidate) {
            $formatted = $candidate->format();
            if ($formatted !== '') {
                $address = $formatted;
                break;
            }
        }

        if ($address === '' || mb_strlen($address) <= self::ADDRESS_ONE_LINE_CHARS) {
            return [$address, ''];
        }

        $cut = mb_strrpos(mb_substr($address, 0, self::ADDRESS_ONE_LINE_CHARS), ', ');
        if ($cut === false) {
            return [$address, ''];
        }

        return [mb_substr($address, 0, $cut), trim(mb_substr($address, $cut + 1))];
    }
}
