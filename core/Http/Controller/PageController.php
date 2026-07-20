<?php

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Config\SettingService;
use Core\Cookie\CookieConsentService;
use Core\Http\Request;
use Core\Http\Response;
use Core\Module\HomeBannerProvider;
use Core\View\EditableContentService;
use Core\View\RgpdContentService;
use Core\View\SectionRepository;
use Twig\Environment;

class PageController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private EditableContentService $editableContentService,
        private SectionRepository $sectionRepository,
        private SettingService $settingService,
        private RgpdContentService $rgpdContentService,
        private ?HomeBannerProvider $bannerProvider = null
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
            'banner_html' => $this->bannerProvider?->getRandomBannerHtml(),
        ]);
    }

    /**
     * GET /contact — contact page.
     *
     * @param array<string, string> $params
     */
    public function contact(Request $request, array $params): Response
    {
        return $this->render('pages/contact.html.twig');
    }

    /**
     * GET /sections — sections list.
     *
     * @param array<string, string> $params
     */
    public function sections(Request $request, array $params): Response
    {
        $groups = $this->sectionRepository->findAllGroupedByBranch();

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
                // Get the last real content-change timestamp from editable_contents
                // (stored in UTC, see EditableContentRepository::upsert()).
                $lastUpdatedRaw = $this->editableContentService->getLastUpdated('rgpd.text');
                $lastUpdated = $lastUpdatedRaw !== null
                    ? new \DateTimeImmutable($lastUpdatedRaw, new \DateTimeZone('UTC'))
                    : $this->rgpdContentService->getDefaultContentLastModified();
            }
        }

        $lastUpdated = $lastUpdated->format('d/m/Y H:i \U\T\C');

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
