<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\MassMail\Service;

use Core\Badge\Badge;
use Core\Badge\BadgeService;
use Core\Config\ScoutYearService;
use Core\Config\SettingService;
use Core\Import\FunctionRepository;
use Core\Member\MemberEmailService;
use Core\Member\MemberService;
use Core\Member\SectionService;
use Core\ScoutYear\ScoutYearResolver;
use Modules\MassMail\Repository\Contact;
use Modules\MassMail\Repository\ContactRepository;
use Modules\MassMail\Repository\Email;
use Modules\MassMail\Repository\ListBlock;
use Modules\MassMail\Repository\ListBlockRepository;
use Modules\MassMail\Repository\MailingList;
use Modules\MassMail\Repository\MailingListRepository;
use Modules\MassMail\Repository\MemberResolutionRepository;
use Modules\Registration\Api\ExternalMailingListProvider;
use Modules\Registration\Api\ProjectedPopulationProvider;

/**
 * Owns all "kinds" of mailing list (module spec): default lists — one
 * per active section plus "Membres actifs"/"Animateurs uniquement" — are
 * computed here on every call from Core\Member\SectionService, never
 * stored as rows, so a section becoming inactive (or a new one appearing)
 * at the next Desk import is reflected immediately with no sync step of
 * its own to run. Custom lists are the only ones backed by
 * Repository\MailingListRepository, and what one designates is the union
 * of its blocks (Repository\ListBlockRepository): member groups resolved
 * for the scout year(s) asked, contact groups always. The "external" kind is contributed,
 * optionally, by another module's own Api\ExternalMailingListProvider
 * (ARCHITECTURE.md §7.5) — currently only the registration module
 * publishes one; $externalListProvider is null (and the list simply
 * doesn't appear anywhere) whenever that module is disabled.
 */
class MailingListService
{
    public const ACTIVE_MEMBERS_LABEL = 'Membres actifs';
    public const CHIEFS_LABEL = 'Animateurs uniquement';
    public const FORMER_MEMBERS_LABEL = 'Anciens';

    public const SETTING_MIN_SCOUT_YEARS = 'former_members_min_scout_years';
    public const SETTING_MAX_YEARS_SINCE_DEPARTURE = 'former_members_max_years_since_departure';

    private const DEFAULT_MIN_SCOUT_YEARS = 2;
    private const DEFAULT_MAX_YEARS_SINCE_DEPARTURE = 10;

    public function __construct(
        private MailingListRepository $listRepository,
        private MemberResolutionRepository $resolutionRepository,
        private SectionService $sectionService,
        private FunctionRepository $functionRepository,
        /**
         * The third criteria axis. Nullable and defaulted only so the
         * many tests that drive a list by its two original axes keep
         * their constructor call — the composition roots always pass it,
         * and a null one simply offers no badge to choose from.
         */
        private ?BadgeService $badgeService = null,
        /**
         * What a custom list is made of. Nullable and defaulted for the
         * same reason as $badgeService: the many tests that only drive the
         * default lists keep their constructor call, and without it a
         * custom list simply holds no block — it resolves to nobody.
         */
        private ?ListBlockRepository $blockRepository = null,
        /**
         * The contacts of a list's contact groups — the half of a custom
         * list that does not depend on the year. Null means no contact
         * group contributes anybody.
         */
        private ?ContactRepository $contactRepository = null,
        private ?ExternalMailingListProvider $externalListProvider = null,
        /**
         * The projection (ARCHITECTURE.md §7.5, `registration`'s
         * `Api\ProjectedPopulationProvider`) — null when that module is
         * disabled, and every future-year list then falls back to Desk,
         * which is exactly what it used to do.
         */
        private ?ProjectedPopulationProvider $projectedPopulation = null,
        private ?ScoutYearResolver $scoutYearResolver = null,
        private ?ScoutYearService $scoutYearService = null,
        /**
         * The two « Anciens » settings. Nullable for the same reason as
         * the two above it — the composition roots always pass it, and
         * without it the list falls back to its documented defaults.
         */
        private ?SettingService $settingService = null,
        /**
         * Names the people listRecipients() returns. Only that method reads
         * it, and without it the rows simply carry no name — the address
         * still says who they are.
         */
        private ?MemberService $memberService = null,
        /**
         * The addresses a member has beside Desk. Read only by the two
         * methods that say who the list's page reaches — summariseList()'s
         * total and listRecipients() — since the send writes to each of
         * them (MassMailService::freezeListRecipients()). Null counts the
         * Desk address alone.
         */
        private ?MemberEmailService $memberEmailService = null,
        /**
         * The unsubscribed addresses, which the send leaves out of every
         * member's addresses (issue #999, §2.2) — so the list's page leaves
         * them out too. Null shows them, as if nobody had unsubscribed.
         */
        private ?\Modules\MassMail\Repository\SuppressedAddressRepository $suppressedAddresses = null
    ) {
    }

    /** @var array<int, string>|null */
    private ?array $functionLabels = null;
    /** @var array<int, string>|null */
    private ?array $sectionLabels = null;
    /** @var array<int, string>|null */
    private ?array $badgeLabels = null;

    /**
     * Whether `$scoutYearId` is a year the unit has not reached yet.
     *
     * Compared by LABEL, never by id: a scout year row is created the
     * moment something needs it, so next year's id can be lower than this
     * year's (the same trap `SectionMembershipRepository` fell into) and
     * `id > publicId` would answer at random. Labels are `YYYY-YYYY`, so a
     * string comparison is the chronology.
     */
    public function isFutureScoutYear(int $scoutYearId): bool
    {
        if ($this->scoutYearResolver === null || $this->scoutYearService === null) {
            return false;
        }

        $year = $this->scoutYearService->findById($scoutYearId);
        if ($year === null) {
            return false;
        }

        return (string) $year['label'] > (string) $this->scoutYearResolver->getCurrentPublicYear()['label'];
    }

    /**
     * What to tell somebody about to send to a year that has not happened
     * yet — null for the current year and for any past one, because a
     * warning shown on every ordinary send is a warning nobody reads.
     *
     * Two texts, because there are two situations and they are not equally
     * reassuring: with the registration module the list already knows about
     * decided passages, accepted registrations and announced departures and
     * is merely incomplete; without it there is nothing but Desk, which for
     * a year nobody has imported means very little.
     */
    public function futureAudienceWarning(int $scoutYearId): ?string
    {
        if (!$this->isFutureScoutYear($scoutYearId)) {
            return null;
        }

        $label = (string) ($this->scoutYearService?->findById($scoutYearId)['label'] ?? '');

        if ($this->projectedPopulation !== null) {
            return "Cette liste vise l'année {$label}. Elle tient compte des passages décidés, des inscriptions "
                . "acceptées et des départs annoncés. Tant que tout n'est pas encodé dans Desk, elle reste une "
                . 'projection : des destinataires peuvent manquer ou changer de section.';
        }

        return "Cette liste vise l'année {$label}. Le module Inscriptions étant désactivé, elle ne repose que sur "
            . "les données Desk : elle ne sera exacte qu'une fois l'année suivante entièrement encodée dans Desk.";
    }

    /**
     * The same caveat as futureAudienceWarning(), stated where a list is
     * DEFINED rather than where a year is picked — so the configuration
     * page can say what « l'année prochaine » will mean before anybody
     * discovers it in a dialog seconds before sending.
     *
     * Not year-specific and never null: this page has no year to name, and
     * a sentence that appeared and disappeared depending on the calendar
     * would be worse than one that is simply always true.
     */
    public function futureAudienceNotice(): string
    {
        if ($this->projectedPopulation !== null) {
            return 'Pour une année scoute à venir, les listes de section et « tous les membres actifs » '
                . "s'appuient sur la projection du module Inscriptions — passages décidés, inscriptions "
                . "acceptées, départs annoncés — tant que Desk n'est pas encodé.";
        }

        return 'Pour une année scoute à venir, les listes ne reposent que sur les données Desk : elles ne '
            . "seront exactes qu'une fois l'année entièrement encodée dans Desk.";
    }

    /**
     * The default lists, in the fixed order the module spec describes:
     * one "Section - {nom}" per currently-active (and visible) section,
     * then the two unit-wide ones. Every list carries a fixed, generated
     * description — descriptions are mandatory across the module (custom
     * lists enforce this too, see createCustomList()/updateCustomList()),
     * so the picker never shows an undocumented list.
     *
     * @return array<int, array{list_type: string, list_section_id: ?int, label: string, description: string}>
     */
    public function getDefaultLists(): array
    {
        $lists = [];
        foreach ($this->sectionService->getAllWithBranches() as $section) {
            $lists[] = [
                'list_type' => 'default_section',
                'list_section_id' => $section['id'],
                'label' => 'Section - ' . $section['name'],
                'description' => "Tous les membres ayant une fonction dans la section « {$section['name']} » "
                    . '(animateurs, intendants, animés) pour l\'année scoute sélectionnée.',
            ];
        }
        $lists[] = [
            'list_type' => 'default_active_members',
            'list_section_id' => null,
            'label' => self::ACTIVE_MEMBERS_LABEL,
            'description' => "Tous les membres actifs de l'unité, toutes sections confondues, pour l'année scoute "
                . "sélectionnée.",
        ];
        $lists[] = [
            'list_type' => 'default_chiefs',
            'list_section_id' => null,
            'label' => self::CHIEFS_LABEL,
            'description' => "Les membres ayant une fonction de chef ou plus (chef, chef d'unité, "
                . "super-administrateur), "
                . "toutes sections confondues, pour l'année scoute sélectionnée.",
        ];

        $lists[] = [
            'list_type' => Email::LIST_TYPE_DEFAULT_FORMER_MEMBERS,
            'list_section_id' => null,
            'label' => self::FORMER_MEMBERS_LABEL,
            'description' => $this->formerMembersDescription(),
        ];

        if ($this->externalListProvider !== null) {
            $external = $this->externalListProvider->describeMailingList();
            $lists[] = [
                'list_type' => Email::LIST_TYPE_EXTERNAL,
                'list_section_id' => null,
                'label' => $external['label'],
                'description' => $external['description'],
            ];
        }

        return $lists;
    }

    /**
     * The « Anciens » list's description is COMPUTED, not fixed, and that
     * is the point of it: this is the only list whose contents depend on
     * two settings that are invisible from the page choosing it, and on a
     * date nobody chose — the oldest year the unit ever imported, before
     * which the site simply cannot know anybody. Saying that here, next to
     * the list, is the only place a reader will actually see it; a note in
     * a help topic is a note nobody reads at the moment they need it.
     */
    public function formerMembersDescription(): string
    {
        $parts = [];

        $oldest = $this->resolutionRepository->oldestKnownScoutYearLabel();
        $parts[] = $oldest !== null
            ? "Anciens connus depuis {$oldest}"
            : 'Anciens membres de l\'unité';

        $minYears = $this->formerMembersMinScoutYears();
        $parts[] = $minYears > 1
            ? "au moins {$minYears} années scoutes"
            : 'au moins une année scoute';

        $maxYears = $this->formerMembersMaxYearsSinceDeparture();
        $parts[] = $maxYears > 0 ? "partis depuis moins de {$maxYears} ans" : 'sans limite d\'ancienneté';

        return implode(' · ', $parts) . '.';
    }

    /**
     * Two distinct scout years means somebody re-enrolled at least once —
     * the most honest substitute there is for « stayed », `member_years`
     * being an annual snapshot. At 1, the list writes to everybody who
     * ever came to three meetings.
     */
    public function formerMembersMinScoutYears(): int
    {
        $value = (int) ($this->settingService?->get(
            self::SETTING_MIN_SCOUT_YEARS,
            'mass_mail',
            (string) self::DEFAULT_MIN_SCOUT_YEARS
        ) ?? self::DEFAULT_MIN_SCOUT_YEARS);

        return $value > 0 ? $value : self::DEFAULT_MIN_SCOUT_YEARS;
    }

    /** `0` means no bound at all. */
    public function formerMembersMaxYearsSinceDeparture(): int
    {
        $value = (int) ($this->settingService?->get(
            self::SETTING_MAX_YEARS_SINCE_DEPARTURE,
            'mass_mail',
            (string) self::DEFAULT_MAX_YEARS_SINCE_DEPARTURE
        ) ?? self::DEFAULT_MAX_YEARS_SINCE_DEPARTURE);

        return max(0, $value);
    }

    /**
     * @return MailingList[]
     */
    public function getAllCustomLists(): array
    {
        return $this->listRepository->findAllOrdered();
    }

    /**
     * @return MailingList[] active custom lists only — for the compose dialog's list picker
     */
    public function getActiveCustomLists(): array
    {
        return array_values(array_filter($this->listRepository->findAllOrdered(), fn(MailingList $l) => $l->isActive));
    }

    public function getCustomListById(int $id): ?MailingList
    {
        return $this->listRepository->findById($id);
    }

    /**
     * A new custom list is a name and a description, and nothing else: it
     * starts with no block, which is valid and designates nobody — its
     * blocks are added afterwards, one dialog each (Service\
     * ListBlockService, Service\ContactGroupImportService).
     *
     * @throws MailingListException on an invalid name/description
     */
    public function createCustomList(string $name, string $description, ?int $createdBy): MailingList
    {
        $this->validateIdentity($name, $description);

        $id = $this->listRepository->create(trim($name), trim($description), $createdBy);
        $list = $this->listRepository->findById($id);
        \assert($list !== null);
        return $list;
    }

    /**
     * The name and the description, the two things a list is besides its
     * blocks.
     *
     * @throws MailingListException on an invalid name/description, or an unknown list
     */
    public function updateCustomList(int $id, string $name, string $description): MailingList
    {
        if ($this->listRepository->findById($id) === null) {
            throw new MailingListException('Liste introuvable.');
        }
        $this->validateIdentity($name, $description);

        $this->listRepository->update($id, trim($name), trim($description));
        $updated = $this->listRepository->findById($id);
        \assert($updated !== null);
        return $updated;
    }

    /**
     * @throws MailingListException when the list doesn't exist
     */
    public function setActive(int $id, bool $active): void
    {
        if ($this->listRepository->findById($id) === null) {
            throw new MailingListException('Liste introuvable.');
        }
        $this->listRepository->setActive($id, $active);
    }

    /**
     * Same "deactivate instead" precedent as Core\Badge\BadgeService::
     * delete() — a list already used by an email (any status, even a
     * draft) is never actually deletable, since Repository\EmailRepository
     * rows keep a plain FK to it.
     *
     * @throws MailingListException when the list doesn't exist or is referenced
     */
    public function deleteCustomList(int $id): void
    {
        if ($this->listRepository->findById($id) === null) {
            throw new MailingListException('Liste introuvable.');
        }
        if ($this->listRepository->isReferencedByAnyEmail($id)) {
            throw new MailingListException('Cette liste est utilisée par au moins un email — désactivez-la au lieu de '
                . 'la supprimer.');
        }

        $this->listRepository->delete($id);
    }

    /**
     * Resolves any list (default or custom) to its current member set —
     * the single entry point Service\MassMailService uses when freezing
     * recipients at send time.
     *
     * @return array<int, array{member_id: int, email: ?string}>
     * @throws MailingListException on an unknown custom list id
     */
    public function resolveMembers(string $listType, ?int $listId, ?int $listSectionId, int $scoutYearId): array
    {
        // A year the unit has not reached yet has, by definition, little or
        // nothing in Desk. When `registration` is enabled its projection
        // knows who is expected — decided passages, accepted registrations,
        // announced departures — so the two lists that mean something for a
        // population read it instead of an empty Desk year.
        //
        // The other two do not, and are deliberately left alone: a
        // projection is animés only and carries no FUNCTION, so it has
        // nothing to say about « les chefs » or about a custom list built
        // from functions. Answering those from it would be inventing
        // recipients.
        if (
            $this->projectedPopulation !== null
            && in_array($listType, ['default_section', 'default_active_members'], true)
            && $this->isFutureScoutYear($scoutYearId)
        ) {
            return $this->projectedMembers($scoutYearId, $listType === 'default_section' ? $listSectionId : null);
        }

        switch ($listType) {
            case 'default_section':
                \assert($listSectionId !== null);
                return $this->resolutionRepository->resolveSectionMembers($listSectionId, $scoutYearId);
            case 'default_active_members':
                return $this->resolutionRepository->resolveActiveMembers($scoutYearId);
            case 'default_chiefs':
                return $this->resolutionRepository->resolveChiefs($scoutYearId);
            case Email::LIST_TYPE_DEFAULT_FORMER_MEMBERS:
                // Each former member carries their own last active year;
                // the shape this method promises does not, so the caller
                // that needs it is resolveMembersForYears(), which
                // special-cases this list exactly as it does the external
                // one.
                return array_map(
                    fn(array $m) => ['member_id' => $m['member_id'], 'email' => $m['email']],
                    $this->resolveFormerMembers($scoutYearId)
                );
            case Email::LIST_TYPE_EXTERNAL:
                // $scoutYearId is ignored on purpose — the provider
                // resolves its own fixed target year internally (module
                // spec: this list is never re-scoped by the compose
                // dialog's own year selector).
                if ($this->externalListProvider === null) {
                    throw new MailingListException('Liste externe indisponible.');
                }
                return $this->externalListProvider->resolveMailingListMembers();
            case 'custom':
                \assert($listId !== null);
                $list = $this->listRepository->findById($listId);
                if ($list === null) {
                    throw new MailingListException('Liste introuvable.');
                }
                // The member half only: a contact group has no year, and
                // resolveMembersForYears() adds the contacts once, after
                // every year has been resolved.
                return $this->resolveMemberGroups($this->blocksOf($listId), $scoutYearId)['members'];
            default:
                throw new MailingListException("Type de liste inconnu : {$listType}");
        }
    }

    /**
     * The projected audience of a future year, in the shape the rest of
     * this module already speaks: `{member_id, email}`.
     *
     * **Only the people who already have a Desk identity.** An accepted
     * registration that nobody has encoded yet is a real future member and
     * the warning says so, but it has no `member_id` — and everything
     * downstream is keyed on one: `MemberEmailService::
     * resolveValidAddressesForMassMail()`, the per-address recipient rows,
     * and the one-click unsubscribe link. Pushing a request through that
     * pipeline would mean inventing an identity for it, which is a much
     * larger change than showing a warning; a family whose child is not yet
     * encoded is one of the « destinataires [qui] peuvent manquer » the
     * warning names.
     *
     * @return array<int, array{member_id: int, email: ?string}>
     */
    private function projectedMembers(int $scoutYearId, ?int $sectionId): array
    {
        \assert($this->projectedPopulation !== null);

        $emails = [];
        foreach ($this->projectedPopulation->reachableRecipients($scoutYearId) as $recipient) {
            if ($recipient->memberId !== null) {
                $emails[$recipient->memberId] = $recipient->email;
            }
        }

        $members = [];
        foreach ($this->projectedPopulation->projectedPopulation($scoutYearId) as $person) {
            if ($person->memberId === null) {
                continue;
            }
            if ($sectionId !== null && $person->sectionId !== $sectionId) {
                continue;
            }

            $members[] = [
                'member_id' => $person->memberId,
                'email' => $emails[$person->memberId] ?? null,
            ];
        }

        return $members;
    }

    /**
     * The « Anciens » list, resolved against one reference year — the
     * year somebody has to be ABSENT from to be a former member.
     *
     * @return array<int, array{member_id: int, email: ?string, scout_year_id: int}>
     */
    public function resolveFormerMembers(int $referenceScoutYearId): array
    {
        return $this->resolutionRepository->resolveFormerMembers(
            $referenceScoutYearId,
            $this->formerMembersMinScoutYears(),
            $this->formerMembersMaxYearsSinceDeparture()
        );
    }

    /**
     * The member groups of a list, resolved for one year, as ONE set: in
     * block order, a member brought by two groups kept once. Beside it,
     * which groups brought each member — what « Voir les destinataires »
     * names on every line — and how many each group designates on its
     * own, deduplicated the way the send is.
     *
     * Each group is resolved by `resolveCustomList()` with ITS criteria —
     * exactly the AND-between-axes, OR-within semantics of a single set of
     * criteria; the OR between groups is this union.
     *
     * @param ListBlock[] $blocks
     * @return array{
     *     members: array<int, array{member_id: int, email: ?string}>,
     *     blocks_by_member: array<int, int[]>,
     *     counts: array<int, int>
     * }
     */
    private function resolveMemberGroups(array $blocks, int $scoutYearId): array
    {
        $members = [];
        $blocksByMember = [];
        $counts = [];

        foreach ($blocks as $block) {
            if (!$block->isMemberGroup()) {
                continue;
            }

            $resolved = $this->resolutionRepository->resolveCustomList(
                $block->functionIds,
                $block->sectionIds,
                $block->badgeIds,
                $scoutYearId
            );
            $counts[$block->id] = count($this->deduplicateByMember($resolved, $scoutYearId));

            foreach ($resolved as $member) {
                $memberId = $member['member_id'];
                if (!isset($blocksByMember[$memberId])) {
                    $members[] = $member;
                }
                $blocksByMember[$memberId][] = $block->id;
            }
        }

        return ['members' => $members, 'blocks_by_member' => $blocksByMember, 'counts' => $counts];
    }

    /**
     * @return ListBlock[]
     */
    private function blocksOf(int $listId): array
    {
        return $this->blockRepository?->findForList($listId) ?? [];
    }

    /**
     * The contacts of a list's contact groups that may be written to — the
     * unsubscribed ones never leave the repository. An address carried by
     * two groups comes back once per group; the deduplication on the
     * address is the caller's, where the members are known too.
     *
     * @return Contact[]
     */
    private function resolveListContacts(int $listId): array
    {
        return $this->contactRepository?->findActiveForList($listId) ?? [];
    }

    /**
     * Multi-year variant (module addendum: an email can target several
     * scout years at once, e.g. a "Montages dias" retrospective spanning
     * two promotions) — resolves the same list against each selected year
     * and merges the results into the email's TARGETS: one entry per member,
     * the same person matched via more than one year keeping the entry from
     * whichever year comes FIRST in $scoutYearIds. $scoutYearIds must
     * already be ordered most-recent-first by the caller (Service\
     * MassMailService, which resolves real chronological order via
     * Core\Config\ScoutYearService — scout_year_id order alone isn't
     * reliable, since a "previous" year's row can be created, and so get
     * its id, after "current"'s).
     *
     * **Nobody is dropped for sharing an address any more** (issue #999,
     * §2.2). Two siblings on the family's Desk address are two targets:
     * each keeps the communication in their history. Sending once to that
     * address is the freeze's business, which compares addresses across
     * every target and every contact — never this method, which compares
     * members.
     *
     * **A custom list is the UNION of its blocks**: its member groups,
     * resolved for each of $scoutYearIds, and the active contacts of its
     * contact groups, which are included ONCE whatever the years — the
     * years chosen on the email apply to the member groups only. A contact
     * entry carries `member_id` and `scout_year_id` null; a contact present
     * in two groups comes back once. A contact equal to a member's address
     * comes back all the same: the freeze writes that address one message,
     * concerning the member.
     *
     * @param int[] $scoutYearIds Most-recent-first.
     * @return array<int, array{member_id: ?int, email: ?string, scout_year_id: ?int}>
     * @throws MailingListException on an unknown custom list id, or when the external list is unavailable
     */
    public function resolveMembersForYears(
        string $listType,
        ?int $listId,
        ?int $listSectionId,
        array $scoutYearIds
    ): array
    {
        // The external list is never re-scoped by the compose dialog's own
        // year checkboxes (module spec) — resolved exactly once, tagged
        // with the provider's OWN target year rather than whichever of
        // previous/current/next happens to be checked, so a target's
        // scout_year_id always matches where their real member_years
        // profile actually lives.
        if ($listType === Email::LIST_TYPE_EXTERNAL) {
            if ($this->externalListProvider === null) {
                throw new MailingListException('Liste externe indisponible.');
            }

            return $this->deduplicateByMember(
                $this->externalListProvider->resolveMailingListMembers(),
                $this->externalListProvider->targetScoutYearId()
            );
        }

        // « Anciens » is not re-scoped by the compose page's year
        // checkboxes either, and for a stronger reason than the external
        // list's: every former member carries their OWN last active year,
        // which is the only year their profile exists for and therefore
        // the only one their target may be tagged with.
        //
        // **$scoutYearIds is IGNORED, not merely narrowed.** The page
        // hides the year checkboxes for this list and says so in words —
        // « il n'y a donc pas d'année scoute à choisir » — but it hides
        // them with a CSS class: the inputs are still in the form, and a
        // box ticked before the list type was switched is still
        // submitted. Reading it would resolve « everybody absent from
        // NEXT year », which is most of the unit, from a control the
        // page told the chief not to think about. A year nobody can see
        // is not a year anybody chose.
        //
        // The reference year — the one somebody has to be absent from to
        // count as a former member — is therefore the current public
        // year, resolved here. The count shown before sending and the
        // freeze still answer the same question, since both come through
        // this method.
        if ($listType === Email::LIST_TYPE_DEFAULT_FORMER_MEMBERS) {
            $referenceYearId = $this->scoutYearResolver?->getCurrentPublicYear()['id'] ?? null;
            if ($referenceYearId === null) {
                return [];
            }

            return $this->deduplicateFormerMembers($this->resolveFormerMembers((int) $referenceYearId));
        }

        $seenMemberIds = [];
        $merged = [];

        foreach ($scoutYearIds as $scoutYearId) {
            foreach ($this->resolveMembers($listType, $listId, $listSectionId, $scoutYearId) as $member) {
                if (isset($seenMemberIds[$member['member_id']])) {
                    continue;
                }
                $seenMemberIds[$member['member_id']] = true;

                $merged[] = [
                    'member_id' => $member['member_id'],
                    'email' => $member['email'],
                    'scout_year_id' => $scoutYearId
                ];
            }
        }

        // The contact half of a custom list, resolved once rather than per
        // year: a contact group belongs to the list, not to a scout year.
        if ($listType === 'custom' && $listId !== null) {
            $seenContacts = [];
            foreach ($this->resolveListContacts($listId) as $contact) {
                $addressKey = mb_strtolower(trim($contact->email));
                if (isset($seenContacts[$addressKey])) {
                    continue;
                }
                $seenContacts[$addressKey] = true;

                $merged[] = [
                    'member_id' => null,
                    'email' => $contact->email,
                    'scout_year_id' => null,
                ];
            }
        }

        return $merged;
    }

    /**
     * One entry per former member — a couple, or two children who both
     * left, sharing an address are two targets — each keeping the year it
     * was resolved from rather than being given a common one.
     *
     * @param array<int, array{member_id: int, email: ?string, scout_year_id: int}> $members
     * @return array<int, array{member_id: int, email: ?string, scout_year_id: int}>
     */
    private function deduplicateFormerMembers(array $members): array
    {
        $seen = [];
        $merged = [];

        foreach ($members as $member) {
            if (isset($seen[$member['member_id']])) {
                continue;
            }
            $seen[$member['member_id']] = true;
            $merged[] = $member;
        }

        return $merged;
    }

    /**
     * One entry per member, tagged with the year it was resolved for.
     *
     * @param array<int, array{member_id: int, email: ?string}> $members
     * @return array<int, array{member_id: int, email: ?string, scout_year_id: int}>
     */
    private function deduplicateByMember(array $members, int $scoutYearId): array
    {
        $seenMemberIds = [];
        $merged = [];

        foreach ($members as $member) {
            if (isset($seenMemberIds[$member['member_id']])) {
                continue;
            }
            $seenMemberIds[$member['member_id']] = true;
            $merged[] = [
                'member_id' => $member['member_id'],
                'email' => $member['email'],
                'scout_year_id' => $scoutYearId
            ];
        }

        return $merged;
    }

    /**
     * What a member group's criteria come to, in the one French sentence
     * the list page, the member-group dialog and the removal confirmation
     * all show: « Les membres de la section **Baladins** qui portent le
     * badge **Trésorier**. »
     *
     * Returned as segments rather than as a string, so that the names can
     * be bold without the browser ever parsing markup the server built: a
     * function label is data typed into Desk, and `textContent` into a
     * `<b>` is the only way it reaches the page. Built once, here — the
     * dialog asks for it with every live count (previewCount()), so the
     * page and the dialog cannot word the same group two ways.
     *
     * An axis that constrains nothing is absent from the sentence: « qui
     * sont dans n'importe quelle section » would read as a restriction.
     * Three empty axes are no group at all (Service\ListBlockService
     * refuses them) and give no segment.
     *
     * A deactivated badge or a section gone from Desk keeps the suffix
     * `getAllBadges()`/`getAllSections()` give it — « portent le badge
     * Infirmier (désactivé) » is the one place a chief finds out why the
     * group resolves to fewer members than its other axes suggest.
     *
     * @param int[] $functionIds
     * @param int[] $sectionIds
     * @param int[] $badgeIds
     * @return list<array{text: string, strong: bool}>
     */
    public function criteriaSentence(array $functionIds, array $sectionIds, array $badgeIds): array
    {
        $sections = $this->labelsFor($sectionIds, $this->sectionLabels());
        $clauses = array_values(array_filter([
            self::clause('ont la fonction ', $this->labelsFor($functionIds, $this->functionLabels())),
            self::clause('portent le badge ', $this->labelsFor($badgeIds, $this->badgeLabels())),
        ]));
        if ($sections === [] && $clauses === []) {
            return [];
        }

        $segments = [self::plain('Les membres')];
        if ($sections !== []) {
            $segments = [
                ...$segments,
                ...self::clause(count($sections) === 1 ? ' de la section ' : ' des sections ', $sections),
            ];
        }
        foreach ($clauses as $i => $clause) {
            $segments = [...$segments, self::plain($i === 0 ? ' qui ' : ' et '), ...$clause];
        }
        $segments[] = self::plain('.');

        return self::mergePlainSegments($segments);
    }

    /**
     * A member group's short name — the names it chooses, in axis order —
     * for the one place a sentence is too long: the tag that says, on
     * each line of « Voir les destinataires », which group brings that
     * person.
     *
     * @param int[] $functionIds
     * @param int[] $sectionIds
     * @param int[] $badgeIds
     */
    public function criteriaLabel(array $functionIds, array $sectionIds, array $badgeIds): string
    {
        return implode(', ', [
            ...$this->labelsFor($functionIds, $this->functionLabels()),
            ...$this->labelsFor($sectionIds, $this->sectionLabels()),
            ...$this->labelsFor($badgeIds, $this->badgeLabels()),
        ]);
    }

    /**
     * « ont la fonction **A**, **B** ou **C** » — or null when the axis
     * constrains nothing.
     *
     * @param string[] $labels
     * @return list<array{text: string, strong: bool}>|null
     */
    private static function clause(string $lead, array $labels): ?array
    {
        if ($labels === []) {
            return null;
        }

        $segments = [self::plain($lead)];
        $last = count($labels) - 1;
        foreach ($labels as $i => $label) {
            if ($i > 0) {
                $segments[] = self::plain($i === $last ? ' ou ' : ', ');
            }
            $segments[] = ['text' => $label, 'strong' => true];
        }

        return $segments;
    }

    /**
     * @return array{text: string, strong: bool}
     */
    private static function plain(string $text): array
    {
        return ['text' => $text, 'strong' => false];
    }

    /**
     * @param list<array{text: string, strong: bool}> $segments
     * @return list<array{text: string, strong: bool}>
     */
    private static function mergePlainSegments(array $segments): array
    {
        $merged = [];
        foreach ($segments as $segment) {
            $previous = array_key_last($merged);
            if ($previous !== null && !$segment['strong'] && !$merged[$previous]['strong']) {
                $merged[$previous]['text'] .= $segment['text'];
                continue;
            }
            $merged[] = $segment;
        }

        return $merged;
    }

    /**
     * @param int[] $ids
     * @param array<int, string> $vocabulary
     * @return string[] in the vocabulary's own order, not the ids'
     */
    private function labelsFor(array $ids, array $vocabulary): array
    {
        $wanted = array_flip($ids);

        return array_values(array_filter(
            $vocabulary,
            static fn(string $label, int $id): bool => isset($wanted[$id]),
            ARRAY_FILTER_USE_BOTH
        ));
    }

    /**
     * The three vocabularies, read once per request. The page that needs
     * them draws one sentence per custom list, and reading them per list
     * would be three queries per row for three answers that cannot have
     * changed in between.
     *
     * @return array<int, string>
     */
    private function functionLabels(): array
    {
        return $this->functionLabels ??= array_column($this->getAllFunctions(), 'label', 'id');
    }

    /** @return array<int, string> */
    private function sectionLabels(): array
    {
        return $this->sectionLabels ??= array_column($this->getAllSections(), 'name', 'id');
    }

    /** @return array<int, string> */
    private function badgeLabels(): array
    {
        return $this->badgeLabels ??= array_column($this->getAllBadges(), 'name', 'id');
    }

    /**
     * @return array<
     *     int,
     *     array{id: int, label: string, role: string}
     * > every function, for the "Nouvelle liste" multi-select
     */
    public function getAllFunctions(): array
    {
        return array_map(
            fn(array $f) => ['id' => $f['id'], 'label' => $f['label'], 'role' => $f['role']],
            $this->functionRepository->findAll()
        );
    }

    /**
     * The sections offered as a criterion: the active, visible ones —
     * plus any section a member group still names, however it was
     * deactivated or hidden since. See getAllBadges() for why the second half is not
     * optional.
     *
     * @return array<int, array{id: int, name: string}>
     */
    public function getAllSections(): array
    {
        $sections = array_map(
            fn(array $s) => ['id' => $s['id'], 'name' => $s['name']],
            $this->sectionService->getAllWithBranches()
        );

        return [...$sections, ...$this->stillReferencedSections(array_column($sections, 'id'))];
    }

    /**
     * The badges offered as a criterion: the active ones — plus any badge
     * a member group still names, marked as deactivated.
     *
     * The second half is not a courtesy. The three pickers are the only
     * place a member group's criteria round-trip through: the form submits
     * what the pickers hold, and Repository\ListBlockRepository::
     * replaceCriteria() deletes and reinserts from that submission. An id
     * with no item to be selected in is therefore an id that saving ANY
     * edit silently drops. On a group whose badge axis was that one badge,
     * the axis would then stop constraining anything at all and the group
     * would quietly widen to everybody the other axes match, which is the
     * opposite of what deactivating a badge means.
     *
     * They are offered greyed rather than merely preserved, because a
     * criterion nobody can see is a criterion nobody can remove — and the
     * name says why the list resolves to fewer members than its other
     * axes suggest.
     *
     * @return array<int, array{id: int, name: string}>
     */
    public function getAllBadges(): array
    {
        if ($this->badgeService === null) {
            return [];
        }

        $active = array_map(
            fn(Badge $b) => ['id' => $b->id, 'name' => $b->name],
            $this->badgeService->getActive()
        );
        $activeIds = array_column($active, 'id');

        $stillReferenced = [];
        $referencedIds = $this->blockRepository?->findReferencedBadgeIds() ?? [];
        foreach ($this->badgeService->getAll() as $badge) {
            if (in_array($badge->id, $activeIds, true) || !in_array($badge->id, $referencedIds, true)) {
                continue;
            }
            $stillReferenced[] = ['id' => $badge->id, 'name' => $badge->name . ' (désactivé)'];
        }

        return [...$active, ...$stillReferenced];
    }

    /**
     * @param int[] $offeredIds
     * @return array<int, array{id: int, name: string}>
     */
    private function stillReferencedSections(array $offeredIds): array
    {
        $missingIds = array_values(array_filter(
            $this->blockRepository?->findReferencedSectionIds() ?? [],
            static fn(int $id): bool => !in_array($id, $offeredIds, true)
        ));
        if ($missingIds === []) {
            return [];
        }

        $sections = [];
        foreach ($this->sectionService->findByIds($missingIds) as $section) {
            $sections[] = [
                'id' => $section['id'],
                'name' => ($section['name'] ?? $section['desk_code']) . ' (retirée)',
            ];
        }

        return $sections;
    }

    /**
     * How many members the given criteria resolve to right now, for the
     * live counter on the criteria form — the same resolution a send
     * performs, against the same year, so the two can never disagree: the
     * number of TARGETS that group would add (issue #999). Two siblings on
     * one family address are two members, and both keep the communication
     * in their history, so both count.
     *
     * A member with no address at all is still counted: the question this
     * answers is « who does this list designate », and leaving them out
     * would make the number disagree with the list itself — the send makes
     * them a target too, « Non envoyée ».
     *
     * @param int[] $functionIds
     * @param int[] $sectionIds
     * @param int[] $badgeIds
     */
    public function countMembersForCriteria(
        array $functionIds,
        array $sectionIds,
        array $badgeIds,
        int $scoutYearId
    ): int {
        return count($this->deduplicateByMember(
            $this->resolutionRepository->resolveCustomList($functionIds, $sectionIds, $badgeIds, $scoutYearId),
            $scoutYearId
        ));
    }

    /**
     * How many messages a send to this custom list writes for one scout
     * year — one per distinct address (resolveReachable() for that single
     * year, exactly the rows of listRecipients()), which the page of the
     * lists prints as « ≈ N destinataires » on every card and the page of
     * one list as its total.
     *
     * @throws MailingListException on an unknown list
     */
    public function countRecipients(int $listId, int $scoutYearId): int
    {
        return count($this->resolveReachable($listId, $scoutYearId));
    }

    /**
     * What a custom list comes to for one scout year — the numbers its
     * page shows: the total, the split between members and contacts, and
     * one count per block.
     *
     * `total` is how many messages the send writes for that single year,
     * one per distinct address: exactly the rows listRecipients() returns,
     * so the number the page shows and the list behind « Voir les
     * destinataires » can never disagree. `members` is how many members
     * the member groups designate — the send's targets, a member without
     * an address included, two siblings on one family address counting
     * two. `contacts` counts the distinct contacts of every contact group,
     * unsubscribed ones included, beside how many of those are
     * unsubscribed — « 37 contacts, dont 2 désinscrits ». The total is
     * therefore not their sum: an address shared by several people is one
     * message, a member may have several addresses, and an unsubscribed
     * contact receives nothing.
     *
     * In `blocks`, a member group's `count` is what it designates on its
     * own for this year and its `unsubscribed` is always 0; a contact
     * group's are its rows and its unsubscribed rows, whatever the year.
     *
     * @return array{
     *     total: int,
     *     members: int,
     *     contacts: int,
     *     contacts_unsubscribed: int,
     *     blocks: array<int, array{count: int, unsubscribed: int}>
     * }
     * @throws MailingListException on an unknown list
     */
    public function summariseList(int $listId, int $scoutYearId): array
    {
        $this->requireCustomList($listId);

        $blocks = $this->blocksOf($listId);
        $memberGroups = $this->resolveMemberGroups($blocks, $scoutYearId);
        $contactCounts = $this->contactRepository?->countByBlockForList($listId) ?? [];
        $distinctContacts = $this->contactRepository?->countDistinctForList($listId)
            ?? ['total' => 0, 'unsubscribed' => 0];

        $perBlock = [];
        foreach ($blocks as $block) {
            $perBlock[$block->id] = $block->isMemberGroup()
                ? ['count' => $memberGroups['counts'][$block->id] ?? 0, 'unsubscribed' => 0]
                : [
                    'count' => $contactCounts[$block->id]['total'] ?? 0,
                    'unsubscribed' => $contactCounts[$block->id]['unsubscribed'] ?? 0,
                ];
        }

        return [
            'total' => $this->countRecipients($listId, $scoutYearId),
            'members' => count($this->deduplicateByMember($memberGroups['members'], $scoutYearId)),
            'contacts' => $distinctContacts['total'],
            'contacts_unsubscribed' => $distinctContacts['unsubscribed'],
            'blocks' => $perBlock,
        ];
    }

    /**
     * The messages a send to this custom list writes for one scout year,
     * one row per distinct address — « Voir les destinataires » (issue
     * #999: the dialog counts messages, and names the members each one
     * concerns).
     *
     * Resolved as the send resolves the list (resolveMembersForYears() for
     * that year) and narrowed to the addresses it would actually write to
     * (resolveReachable()). A family address shared by two designated
     * siblings is ONE row naming both; a member with three addresses is
     * three rows; a member without any is in no row, and is still counted
     * in `targets` below; a contact equal to a member's address is that
     * member's row. Each row names every block that brings it.
     *
     * `name` is the full name of the member the address concerns, or the
     * names of all of them, joined, when it concerns several; `display_name`
     * their totem or first name when it concerns one. For a contact, `name`
     * is the name the file gave and `display_name` is null. `member_ids`
     * lists the members it concerns, empty for a contact. Sorted by name,
     * then address, in PHP — the names are decrypted.
     *
     * @return array{
     *     rows: array<int, array{
     *         member_ids: int[],
     *         name: ?string,
     *         display_name: ?string,
     *         email: string,
     *         block_ids: int[]
     *     }>,
     *     targets: int
     * }
     * @throws MailingListException on an unknown list
     */
    public function listRecipients(int $listId, int $scoutYearId): array
    {
        $this->requireCustomList($listId);

        $blocks = $this->blocksOf($listId);
        $memberGroups = $this->resolveMemberGroups($blocks, $scoutYearId);
        $contacts = $this->resolveListContacts($listId);

        $blocksByAddress = [];
        $contactNames = [];
        foreach ($contacts as $contact) {
            $key = mb_strtolower(trim($contact->email));
            $blocksByAddress[$key][] = $contact->blockId;
            $contactNames[$key] ??= $contact->name;
        }

        $resolved = $this->resolveReachable($listId, $scoutYearId);

        $memberIds = [];
        foreach ($resolved as $recipient) {
            foreach ($recipient['member_ids'] as $memberId) {
                $memberIds[$memberId] = $memberId;
            }
        }
        $memberIds = array_values($memberIds);
        $fullNames = $memberIds !== [] ? ($this->memberService?->findNamesForMembers($memberIds) ?? []) : [];
        $displayNames = $memberIds !== []
            ? ($this->memberService?->findDisplayNamesByMemberIds($memberIds, $scoutYearId) ?? [])
            : [];

        $rows = [];
        foreach ($resolved as $recipient) {
            $key = $recipient['key'];
            $ids = $recipient['member_ids'];

            $blockIds = $blocksByAddress[$key] ?? [];
            foreach ($ids as $memberId) {
                $blockIds = [...$blockIds, ...($memberGroups['blocks_by_member'][$memberId] ?? [])];
            }

            if ($ids === []) {
                $name = $contactNames[$key] ?? null;
                $displayName = null;
            } elseif (count($ids) === 1) {
                $name = $fullNames[$ids[0]] ?? null;
                $displayName = $displayNames[$ids[0]] ?? null;
            } else {
                // Several members on one address: every one of them named,
                // each with their totem when the full name lacks it — the
                // dialog adds a display name to a single name only.
                $labels = [];
                foreach ($ids as $memberId) {
                    $full = $fullNames[$memberId] ?? null;
                    $short = $displayNames[$memberId] ?? null;
                    $label = $full ?? $short;
                    if ($full !== null && $short !== null && !str_contains($full, $short)) {
                        $label = $full . ' — ' . $short;
                    }
                    if ($label !== null) {
                        $labels[] = $label;
                    }
                }
                sort($labels, SORT_FLAG_CASE | SORT_STRING);
                $name = $labels !== [] ? implode(', ', $labels) : null;
                $displayName = null;
            }

            $rows[] = [
                'member_ids' => $ids,
                'name' => $name,
                'display_name' => $displayName,
                'email' => $recipient['email'],
                'block_ids' => self::inBlockOrder($blockIds, $blocks),
            ];
        }

        usort(
            $rows,
            static fn(array $a, array $b): int =>
                [mb_strtolower($a['name'] ?? ''), mb_strtolower($a['email'])]
                <=> [mb_strtolower($b['name'] ?? ''), mb_strtolower($b['email'])]
        );

        return [
            'rows' => $rows,
            'targets' => count($this->deduplicateByMember($memberGroups['members'], $scoutYearId)),
        ];
    }

    /**
     * The addresses a send to this list for this one year writes to — the
     * resolution resolveMembersForYears() gives it, narrowed the way
     * MassMailService::freezeListRecipients() narrows it, so that
     * summariseList()'s total and listRecipients() count the messages the
     * send writes: one per distinct address across every member and every
     * contact.
     *
     * A member is read at every address the send uses: their Desk address
     * when it is well formed, and their valid personal ones
     * (MemberEmailService::resolveValidAddressesForMassMail(), asked with
     * no Desk address, so reading never creates the Desk override row) —
     * less the unsubscribed ones (mass_mail_suppressed_addresses), which
     * the send leaves out too. A contact is read at its address.
     *
     * One gate of the freeze is deliberately not reproduced: the bounce
     * block (Core\Mail\Feedback\Bounce). A Desk or contact address the far
     * end has refused twice is still shown and counted here, while the send
     * leaves it out or writes it an error message on its tracking page —
     * which is where a blocked address is explained and lifted.
     *
     * @return array<int, array{email: string, key: string, member_ids: int[]}>
     * @throws MailingListException on an unknown list
     */
    private function resolveReachable(int $listId, int $scoutYearId): array
    {
        /** @var array<string, array{email: string, key: string, member_ids: int[]}> $byAddress */
        $byAddress = [];

        foreach ($this->resolveMembersForYears('custom', $listId, null, [$scoutYearId]) as $recipient) {
            $memberId = $recipient['member_id'];

            $addresses = [];
            if ($memberId === null) {
                $addresses[] = (string) $recipient['email'];
            } else {
                $desk = (string) $recipient['email'];
                if (filter_var($desk, FILTER_VALIDATE_EMAIL) !== false) {
                    $addresses[] = $desk;
                }
                foreach ($this->memberEmailService?->resolveValidAddressesForMassMail($memberId, null) ?? [] as $row) {
                    $addresses[] = $row->email;
                }
                $suppressed = $addresses !== []
                    ? array_flip(array_map(
                        static fn(string $a): string => mb_strtolower(trim($a)),
                        $this->suppressedAddresses?->filterSuppressed($addresses) ?? []
                    ))
                    : [];
                $addresses = array_values(array_filter(
                    $addresses,
                    static fn(string $a): bool => !isset($suppressed[mb_strtolower(trim($a))])
                ));
            }

            foreach ($addresses as $address) {
                $key = mb_strtolower(trim($address));
                $byAddress[$key] ??= ['email' => $address, 'key' => $key, 'member_ids' => []];
                if ($memberId !== null && !in_array($memberId, $byAddress[$key]['member_ids'], true)) {
                    $byAddress[$key]['member_ids'][] = $memberId;
                }
            }
        }

        return array_values($byAddress);
    }

    /**
     * @param int[] $blockIds
     * @param ListBlock[] $blocks
     * @return int[] each id once, in the order the page shows the blocks
     */
    private static function inBlockOrder(array $blockIds, array $blocks): array
    {
        $wanted = array_flip($blockIds);

        return array_values(array_map(
            static fn(ListBlock $b): int => $b->id,
            array_filter($blocks, static fn(ListBlock $b): bool => isset($wanted[$b->id]))
        ));
    }

    /**
     * @throws MailingListException
     */
    private function requireCustomList(int $listId): void
    {
        if ($this->listRepository->findById($listId) === null) {
            throw new MailingListException('Liste introuvable.');
        }
    }

    /**
     * The criteria belong to a list's member groups, each validated by
     * Service\ListBlockService, so what is validated here is what a list
     * IS: a name and a description, both mandatory (the description is
     * shown next to the list wherever it is picked).
     *
     * @throws MailingListException
     */
    private function validateIdentity(string $name, string $description): void
    {
        if (trim($name) === '') {
            throw new MailingListException('Le nom de la liste est obligatoire.');
        }
        if (trim($description) === '') {
            throw new MailingListException('La description de la liste est obligatoire.');
        }
    }
}
