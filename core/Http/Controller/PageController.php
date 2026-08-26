<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Config\ScoutYearService;
use Core\Config\SettingService;
use Core\Cookie\CookieConsentService;
use Core\Http\Request;
use Core\Http\Response;
use Core\Member\SectionService;
use Core\Member\UnitStaffSectionService;
use Core\Module\HomeBannerProvider;
use Core\Module\HomeGroupActivityProvider;
use Core\Module\HomePaymentDueProvider;
use Core\Module\HomeNewsProvider;
use Core\Module\SectionResponsableProvider;
use Core\Security\AuthSession;
use Core\Service\DateInput;
use Core\View\EditableContentService;
use Core\View\RgpdContentService;
use Core\View\SectionRepository;
use Twig\Environment;

class PageController extends AbstractController
{
    private const HOME_NEWS_LIMIT = 5;

    public function __construct(
        protected Environment $twig,
        private EditableContentService $editableContentService,
        private SectionRepository $sectionRepository,
        private SettingService $settingService,
        private RgpdContentService $rgpdContentService,
        private SectionService $sectionService,
        private UnitStaffSectionService $unitStaffSectionService,
        private ScoutYearService $scoutYearService,
        private ?HomeBannerProvider $bannerProvider = null,
        private ?HomeNewsProvider $newsProvider = null,
        private ?SectionResponsableProvider $sectionResponsableProvider = null,
        private ?HomeGroupActivityProvider $groupActivityProvider = null,
        private ?HomePaymentDueProvider $paymentDueProvider = null
    ) {
    }

    /**
     * GET / — home page.
     *
     * @param array<string, string> $params
     */
    public function home(Request $request, array $params): Response
    {
        return $this->render('pages/home.html.twig', [
            'banner_html' => $this->bannerProvider?->getRandomBannerHtml(AuthSession::getRole()),
            'news_articles' => $this->newsProvider?->getLatestVisibleArticles(self::HOME_NEWS_LIMIT, AuthSession::getRole()) ?? [],
            // The provider resolves the caller from the session itself and
            // answers null for a visitor with no unread groups (and for
            // an anonymous one), so there is nothing to gate on here.
            'group_activity' => $this->groupActivityProvider?->getHomeActivitySummaryForCurrentUser(),
            'payment_due' => $this->paymentDueProvider?->getHomePaymentSummaryForCurrentUser(),
        ]);
    }

    /**
     * GET /contact — contact page. This route is public (role_min:
     * 'public', no login required) — unlike /trombinoscope, which shows
     * similar chief info but requires at least 'identified'. Shows the
     * Staff d'U's group photo and roster (name + totem) here anyway
     * (module spec), on the theory that a prospective parent/member needs
     * to know who to reach out to before they've even registered.
     *
     * @param array<string, string> $params
     */
    public function contact(Request $request, array $params): Response
    {
        $staffduSectionId = $this->unitStaffSectionService->ensureSection();
        $scoutYearId = $this->scoutYearService->getCurrentYear()['id'];

        return $this->render('pages/contact.html.twig', [
            'staffdu_section_id' => $staffduSectionId,
            'staffdu_staff' => $this->sectionService->getSectionStaff($staffduSectionId, $scoutYearId),
        ]);
    }

    /**
     * GET /sections — sections list. Public route (role_min: 'public'),
     * same precedent as /contact: each card shows the section's staff
     * photo and its designated "responsable" name, already shown publicly
     * elsewhere (/contact, /trombinoscope) for the same audience reason —
     * prospective parents/members browsing before they've registered.
     *
     * @param array<string, string> $params
     */
    public function sections(Request $request, array $params): Response
    {
        $groups = $this->sectionRepository->findAllGroupedByBranch();
        $scoutYearId = $this->scoutYearService->getCurrentYear()['id'];

        foreach ($groups as &$group) {
            foreach ($group['sections'] as &$section) {
                $section['responsable'] = $this->sectionResponsableProvider?->getResponsable($section['id'], $scoutYearId);
            }
            unset($section);
        }
        unset($group);

        return $this->render('pages/sections.html.twig', [
            'section_groups' => $groups,
        ]);
    }

    /**
     * GET /rgpd — GDPR page.
     *
     * @param array<string, string> $params
     */
    public function rgpd(Request $request, array $params): Response
    {
        $mode = $this->settingService->get('rgpd_generation_mode', null, 'default');

        if ($mode === 'default') {
            $content = $this->rgpdContentService->getDefaultContent();
            $lastUpdated = $this->rgpdContentService->getDefaultContentLastModified();
        } else {
            $content = $this->editableContentService->get('rgpd.text', '');
            if ($content === '') {
                $content = $this->rgpdContentService->getDefaultContent();
                $lastUpdated = $this->rgpdContentService->getDefaultContentLastModified();
            } else {
                // Get the last real content-change timestamp from
                // editable_contents — a naive DATETIME on the application
                // clock like every other one (Core\Config\AppClock), so it
                // is parsed under the default timezone, not forced to UTC.
                $lastUpdatedRaw = $this->editableContentService->getLastUpdated('rgpd.text');
                $lastUpdated = DateInput::fromStorage($lastUpdatedRaw)
                    ?? $this->rgpdContentService->getDefaultContentLastModified();
            }
        }

        // No zone suffix: the site runs on Belgian time and every other
        // date it renders is Belgian wall-clock, unlabelled. A "UTC" here
        // (which is what this said while the whole app ran on UTC) would
        // now be both inconsistent and, after the clock change, wrong.
        $lastUpdated = $lastUpdated->format('d/m/Y H:i');

        // Inject the date into the content
        $content = str_replace(
            '<span id="rgpd-last-updated">Date de publication</span>',
            '<span id="rgpd-last-updated">' . $lastUpdated . '</span>',
            $content
        );

        return $this->render('pages/rgpd.html.twig', [
            'rgpd_content' => $content,
        ]);
    }
}
