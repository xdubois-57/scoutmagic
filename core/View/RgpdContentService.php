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
        $phoneProvider = $this->getPhoneProviderInfo();

        $systemPrompt = $this->buildSystemPrompt($baseContent, $activeModules, $providerInfo, $modelsInfo, $cookieList, $phoneProvider, $userPrompt);

        $request = new LlmRequest(
            prompt: "Génère le contenu RGPD complet en HTML selon la structure imposée dans le prompt système.",
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
        string $phoneProvider,
        string $userPrompt
    ): string {
        $modulesText = implode(', ', $activeModules);
        $unitName = $this->settingService->get('site_name') ?: 'Unité scoute';
        $contactEmail = $this->settingService->get('contact_email') ?: '(non configuré)';

        return <<<PROMPT
Tu es un assistant juridique spécialisé en conformité RGPD pour des sites web d'unités scoutes belges.

Contexte de l'unité :
- Nom de l'unité : {$unitName}
- Email de contact RGPD : {$contactEmail}
- Responsable du traitement : chef d'unité (responsable du groupe « chefs d'U »)
- Affiliation : Les Scouts ASBL (BE0409580916), politique fédération : https://www.lesscouts.be/fr/ressources-scouts/administratif-1/web-et-vie-privee/protection-des-donnees-personnelles
- Modules actifs : {$modulesText}
- Fournisseur IA : {$providerInfo}
- Modèles IA : {$modelsInfo}
- Fournisseur téléphonie : {$phoneProvider}

Cookies actuellement déclarés :
{$cookieList}

Contenu RGPD de référence (couvre TOUS les modules possibles, version la plus complète) :
{$baseContent}

Instructions de l'administrateur :
{$userPrompt}

Tâche :
Personnalise le contenu de référence ci-dessus selon le contexte réel du site. Le document final doit être juridiquement correct, exhaustif et conforme au RGPD (Règlement UE 2016/679).

Structure OBLIGATOIRE (respecter scrupuleusement) :
1. Qui sommes-nous et objet de cette politique (avec sous-sections : Identité, Cadre légal, Logiciel open source)
2. Quelles données collectons-nous et pourquoi (sous-sections par finalité avec données traitées + base légale)
3. Combien de temps conservons-nous vos données (conservation active, archivage 5 ans, journaux, suppression sur demande)
4. Avec qui partageons-nous vos données (sous-traitants essentiels, modules, garanties art. 28 RGPD)
5. Où sont stockées vos données et transferts internationaux (localisation, transferts hors UE avec mécanismes art. 46)
6. Comment protégeons-nous vos données (mesures techniques détaillées : chiffrement AES-256, bcrypt, CSP, RBAC, plan incident)
7. Vos droits sur vos données personnelles (accès, rectification, effacement, portabilité, opposition, limitation, retrait, réclamation APD)
8. Cookies et technologies similaires (gestion préférences + tableau HTML des cookies)
9. Politique de la fédération Les Scouts (référence Les Scouts ASBL BE0409580916)
10. Modifications de cette politique

RÈGLES CRITIQUES (ne JAMAIS déroger) :
1. **Date et notification** : Inclure en haut `<span id="rgpd-last-updated">` et bandeau notification modifications
2. **Modules actifs uniquement** : Retirer les sections des modules INACTIFS (comparer avec liste modules actifs)
3. **Personnalisation obligatoire** : Remplacer {$unitName} et {$contactEmail} partout. Ne JAMAIS laisser de placeholder générique
4. **Sous-traitants** : Pour CHAQUE sous-traitant actif, indiquer : nom, URL, localisation serveurs, lien politique confidentialité. Chercher sur internet si besoin
5. **Hébergeur** : NE PAS assumer que l'hébergeur est en UE. Écrire "La localisation doit être précisée par l'administrateur" sauf si connue
6. **IA provider** : Utiliser les infos exactes du fournisseur actif ({$providerInfo}, {$modelsInfo}) avec localisation et privacy policy
7. **Téléphonie** : Si sos_staff actif, utiliser {$phoneProvider} (OVH Télécom ou autre)
8. **Cookies tableau** : Créer tableau HTML complet (<table>) avec colonnes : Nom | Catégorie | Finalité | Durée. Utiliser la liste cookies ci-dessus
9. **Sécurité technique** : Garder les détails techniques précis (AES-256-CBC, bcrypt, CSP, RBAC, 6 rôles, WebAuthn, PHPStan niveau 6)
10. **Conservation 5 ans** : Conserver la mention "5 ans après départ membre" pour archivage
11. **Base légale** : Chaque traitement doit avoir sa base légale (art. 6 RGPD : intérêt légitime, contrat, consentement)
12. **Transferts hors UE** : Si Anthropic ou hébergeur hors UE, mentionner SCC (art. 46.2.c RGPD)
13. **Open source** : Garder section 1.3 (PHP, Twig, PHPMailer, Bootstrap, licence AGPL-3.0)
14. **Obligation APD** : Conserver mentions notification 72h (art. 33), information personnes (art. 34), réclamation APD
15. **Délai réponse** : 1 mois pour réponse droits (art. 12.3)
16. **Instructions admin** : Appliquer {$userPrompt} SANS compromettre conformité légale ni retirer éléments obligatoires
17. **HTML pur** : Pas de ```html, pas de <html>/<body>, uniquement contenu <h2> à </p>
18. **Précision factuelle** : Ne JAMAIS inventer données non collectées, modules non actifs, ou sous-traitants non utilisés

Réponds UNIQUEMENT avec le HTML généré, prêt à l'insertion directe dans la page.
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
     * Get info about the phone provider for SOS module
     */
    private function getPhoneProviderInfo(): string
    {
        // Check if sos_staff module is active
        if (!in_array('sos_staff', $this->moduleManager->getEnabledModuleIds(), true)) {
            return 'Aucun (module SOS inactif)';
        }

        // Currently only OVH is implemented
        // In the future, check the active provider from sos_provider_credentials table
        return 'OVH Télécom (France, UE)';
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
