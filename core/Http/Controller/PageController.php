<?php

declare(strict_types=1);

namespace Core\Http\Controller;

use Core\Cookie\CookieConsentService;
use Core\Http\Request;
use Core\Http\Response;
use Core\Module\HomeBannerProvider;
use Core\View\EditableContentService;
use Core\View\SectionRepository;
use Twig\Environment;

class PageController extends AbstractController
{
    public function __construct(
        protected Environment $twig,
        private EditableContentService $editableContentService, // @phpstan-ignore property.onlyWritten
        private SectionRepository $sectionRepository,
        private CookieConsentService $cookieConsentService,
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
        $categories = $this->cookieConsentService->getAllDeclaredCookies();

        return $this->render('pages/rgpd.html.twig', [
            'cookie_categories' => $categories,
        ]);
    }
}
