<?php

declare(strict_types=1);

namespace Core\View;

use Core\Config\SettingService;
use Core\Cookie\CookieConsentService;
use Core\Module\ModuleManager;
use Modules\LlmConnector\Api\LlmConnectorInterface;
use Modules\LlmConnector\Api\LlmRequest;
use Modules\LlmConnector\Api\LlmTier;
use Modules\LlmConnector\Repository\ProviderModelRepository;
use Modules\LlmConnector\Repository\ProviderRepository;

class RgpdContentService
{
    public function __construct(
        private ModuleManager $moduleManager,
        private SettingService $settingService,
        private CookieConsentService $cookieConsentService,
        private ?LlmConnectorInterface $llmConnector = null,
        private ?ProviderRepository $llmProviderRepo = null,
        private ?ProviderModelRepository $llmModelRepo = null
    ) {
    }

    /**
     * Get the default RGPD content from the static HTML file.
     *
     * This file assumes all modules are active and is the most complete
     * version. It is used as-is when mode is "default" and as reference
     * content in the AI prompt.
     */
    public function getDefaultContent(): string
    {
        $path = __DIR__ . '/rgpd_default.html';
        $content = file_get_contents($path);
        if ($content === false) {
            return '<h2>Politique de confidentialité</h2><p>Contenu par défaut non disponible.</p>';
        }

        return $content;
    }

    /**
     * Generate RGPD content via AI based on active modules and user prompt
     */
    public function generateWithAi(string $userPrompt): string
    {
        if ($this->llmConnector === null || !$this->llmConnector->isAvailable()) {
            throw new \RuntimeException('Service IA non disponible.');
        }

        $baseContent = $this->getDefaultContent();
        $activeModules = $this->moduleManager->getEnabledModuleIds();
        $providerInfo = $this->getActiveProviderInfo();
        $modelsInfo = $this->getActiveModelsInfo();
        $cookieList = $this->getCookieListText();

        $systemPrompt = $this->buildSystemPrompt($baseContent, $activeModules, $providerInfo, $modelsInfo, $cookieList, $userPrompt);

        $request = new LlmRequest(
            prompt: "Génère le contenu RGPD complet en HTML.",
            tier: LlmTier::CAPABLE,
            systemPrompt: $systemPrompt
        );

        $response = $this->llmConnector->complete($request);

        return $this->sanitizeHtmlOutput($response->content);
    }

    /**
     * Build the system prompt for AI generation
     *
     * @param array<int, string> $activeModules
     */
    private function buildSystemPrompt(
        string $baseContent,
        array $activeModules,
        string $providerInfo,
        string $modelsInfo,
        string $cookieList,
        string $userPrompt
    ): string {
        $modulesText = implode(', ', $activeModules);
        $unitName = $this->settingService->get('site_name') ?: 'Unité scoute';
        $contactEmail = $this->settingService->get('contact_email') ?: '(non configuré)';

        return <<<PROMPT
Tu es un assistant spécialisé en conformité RGPD pour des sites web d'unités scoutes belges.

Contexte :
- Nom de l'unité : {$unitName}
- Email de contact : {$contactEmail}
- Le responsable du traitement est le chef d'unité (responsable du groupe « chefs d'U »).
- Le site utilise actuellement les modules suivants (actifs) : {$modulesText}
- Fournisseur IA configuré : {$providerInfo}
- Modèles IA utilisés : {$modelsInfo}
- L'unité est affiliée à la fédération Les Scouts ASBL. Leur politique RGPD s'applique en complément : https://www.lesscouts.be/fr/ressources-scouts/administratif-1/web-et-vie-privee/protection-des-donnees-personnelles

Cookies actuellement déclarés sur le site :
{$cookieList}

Contenu RGPD de référence (couvre TOUS les modules possibles, version la plus complète) :
{$baseContent}

Instructions de l'administrateur :
{$userPrompt}

Tâche :
Retravaille le contenu de référence ci-dessus pour le personnaliser selon le contexte réel du site. Génère un document RGPD complet, conforme au règlement européen, en HTML bien formaté.

Règles strictes :
1. Utiliser le contenu de référence comme base. Il couvre déjà tous les modules — retire les sections relatives aux modules qui ne sont PAS dans la liste des modules actifs ci-dessus.
2. Remplacer les mentions génériques (« unité scoute », « email de contact ») par le nom réel de l'unité et l'adresse email fournis ci-dessus.
3. Si llm_connector est actif, préciser dans la section Sous-traitants le fournisseur IA exact avec les modèles utilisés. Pour chaque fournisseur/modèle, inclure :
   — la localisation des serveurs (cherche sur internet si nécessaire),
   — un lien vers leur politique de confidentialité officielle.
4. Si sos_staff est actif, préciser le fournisseur de téléphonie (OVH Télécom) dans Sous-traitants avec localisation et lien vers leur politique de confidentialité.
5. Pour TOUS les sous-traitants (hébergeur, SMTP, IA, téléphonie), mentionner clairement la localisation des serveurs et fournir un lien vers leur politique de confidentialité. Tu PEUX chercher sur internet pour trouver ces informations si elles ne sont pas fournies ci-dessus.
6. Inclure la liste complète des cookies ci-dessus dans une section dédiée avec un tableau HTML (nom, finalité, durée par cookie).
7. Appliquer les instructions de l'administrateur (ton, ajouts, précisions) SANS jamais retirer d'informations obligatoires RGPD.
8. Utiliser des balises HTML sémantiques : <h2>, <h3>, <p>, <ul>, <li>, <strong>, <table>.
9. Ne JAMAIS inventer de données personnelles non collectées par le site.
10. Le HTML doit être prêt à l'insertion directe (pas de code fence, pas de balise <html> ou <body>).
11. Référencer la politique RGPD de la fédération Les Scouts avec le lien.

Réponds UNIQUEMENT avec le HTML généré, rien d'autre.
PROMPT;
    }

    /**
     * Get info about the active AI provider for RGPD disclosure
     */
    private function getActiveProviderInfo(): string
    {
        if ($this->llmProviderRepo === null) {
            return 'Non configuré';
        }

        $provider = $this->llmProviderRepo->findFirstActive();
        if ($provider === null) {
            return 'Non configuré';
        }

        $driver = $provider['driver'];
        return match ($driver) {
            'anthropic' => 'Anthropic (États-Unis, hors UE)',
            'mistral' => 'Mistral AI (France, UE)',
            'scaleway' => 'Scaleway (France/Pays-Bas, UE)',
            default => $provider['name'],
        };
    }

    /**
     * Get info about the active AI models for RGPD disclosure
     */
    private function getActiveModelsInfo(): string
    {
        if ($this->llmProviderRepo === null || $this->llmModelRepo === null) {
            return 'Non configuré';
        }

        $provider = $this->llmProviderRepo->findFirstActive();
        if ($provider === null) {
            return 'Non configuré';
        }

        $models = $this->llmModelRepo->findByProvider((int) $provider['id']);
        $assigned = [];
        foreach ($models as $model) {
            $tiers = [];
            if ($model['is_tier_cheap']) {
                $tiers[] = 'économique';
            }
            if ($model['is_tier_capable']) {
                $tiers[] = 'performant';
            }
            if ($model['is_tier_ocr']) {
                $tiers[] = 'OCR';
            }
            if (!empty($tiers)) {
                $assigned[] = $model['display_name'] . ' (' . implode(', ', $tiers) . ')';
            }
        }

        if (empty($assigned)) {
            return 'Aucun modèle assigné';
        }

        return implode('; ', $assigned);
    }

    /**
     * Get the cookie list as readable text for the AI prompt
     */
    private function getCookieListText(): string
    {
        $categories = $this->cookieConsentService->getAllDeclaredCookies();
        $lines = [];

        foreach ($categories as $categoryKey => $category) {
            $lines[] = "Catégorie : {$category['label']} — {$category['description']}";
            foreach ($category['cookies'] as $cookie) {
                $lines[] = "  - {$cookie['name']} : {$cookie['purpose']} (durée : {$cookie['duration']})";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Sanitize HTML output from AI
     */
    private function sanitizeHtmlOutput(string $html): string
    {
        // Remove markdown code fences if present
        $html = preg_replace('/^```html\s*\n/', '', $html);
        $html = preg_replace('/\n```\s*$/', '', $html);

        // Remove full document wrappers if present
        $html = preg_replace('/<\?xml[^>]*>\s*/', '', $html);
        $html = preg_replace('/<!DOCTYPE[^>]*>\s*/', '', $html);
        $html = preg_replace('/<html[^>]*>/', '', $html);
        $html = preg_replace('/<\/html>/', '', $html);
        $html = preg_replace('/<head>.*?<\/head>/s', '', $html);
        $html = preg_replace('/<body[^>]*>/', '', $html);
        $html = preg_replace('/<\/body>/', '', $html);

        return trim($html);
    }
}
