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
     * Get the default RGPD content as static HTML.
     *
     * Covers all core data processing. Module-specific sections should be
     * added via the custom or AI mode. The cookie list is always rendered
     * dynamically by the public page and must NOT be duplicated here.
     */
    public function getDefaultContent(): string
    {
        $unitName = $this->settingService->get('site_name') ?: 'Unité scoute';
        $contactEmail = $this->settingService->get('contact_email') ?: '(non configuré)';

        return <<<HTML
<h2>Politique de confidentialité</h2>
<p>Ce site est exploité par <strong>{$unitName}</strong>, une unité scoute affiliée à la fédération <a href="https://www.lesscouts.be" target="_blank" rel="noopener">Les Scouts ASBL</a> (Belgique). Il respecte le Règlement Général sur la Protection des Données (RGPD — Règlement UE 2016/679). Cette page décrit les traitements de données personnelles effectués par le site de l'unité.</p>
<p>L'unité est également soumise à la <a href="https://www.lesscouts.be/fr/ressources-scouts/administratif-1/web-et-vie-privee/protection-des-donnees-personnelles" target="_blank" rel="noopener">politique de protection des données personnelles de la fédération Les Scouts</a>, qui s'applique en complément de la présente page.</p>

<h2>Responsable du traitement</h2>
<p>Le responsable du traitement est le chef d'unité (responsable du groupe « chefs d'U ») de <strong>{$unitName}</strong>.</p>
<p>Pour exercer vos droits ou poser une question relative à vos données personnelles, contactez-nous par email : <a href="mailto:{$contactEmail}">{$contactEmail}</a>.</p>

<h2>Données collectées</h2>

<h3>Comptes utilisateurs</h3>
<p>Lors de la création d'un compte, les données suivantes sont collectées :</p>
<ul>
<li><strong>Adresse email</strong> — pour l'authentification et l'envoi de liens magiques. Chiffrée au repos avec index aveugle pour la recherche.</li>
<li><strong>Nom et prénom</strong> — pour l'affichage du profil. Chiffrés au repos.</li>
<li><strong>Mot de passe</strong> — pour l'authentification par mot de passe. Stocké sous forme de hachage irréversible (jamais en clair).</li>
<li><strong>Clés WebAuthn</strong> — pour l'authentification par passkey, si activée par l'utilisateur.</li>
</ul>

<h3>Membres importés depuis Desk</h3>
<p>Les données des membres sont importées depuis la plateforme <a href="https://desk.lesscouts.be" target="_blank" rel="noopener">Desk</a> de la fédération Les Scouts. Toutes les données personnelles suivantes sont chiffrées au repos (AES-256-CBC) et déchiffrées uniquement au moment de l'affichage :</p>
<ul>
<li><strong>Prénom, nom</strong> — affichage des listes de membres.</li>
<li><strong>Genre</strong> — statistiques internes et gestion des branches.</li>
<li><strong>Date de naissance</strong> — gestion des branches d'âge.</li>
<li><strong>Téléphone, GSM</strong> — contact des membres.</li>
<li><strong>Adresse email</strong> — liaison entre le compte utilisateur et la fiche membre. Chiffrée avec index aveugle.</li>
<li><strong>Totem, quali, sizaine</strong> — vie de l'unité.</li>
<li><strong>Adresse postale</strong> — envoi de courrier.</li>
</ul>

<h3>Photos des membres</h3>
<p>Une photo peut être associée à chaque membre pour chaque année scoute (affichage sur les pages du site, par exemple un trombinoscope). Les photos ne sont pas chiffrées mais leur accès est protégé par un rôle minimum. En l'absence de photo, un avatar générique (initiales) est affiché sans traitement de données personnelles.</p>

<h3>Journal d'audit</h3>
<p>Les actions sensibles sont journalisées à des fins de sécurité :</p>
<ul>
<li><strong>Adresse IP</strong> — détection d'abus.</li>
<li><strong>Identifiant du compte auteur</strong> — traçabilité. L'email n'est jamais stocké en clair dans le journal.</li>
</ul>
<p>Le journal ne contient aucune autre donnée personnelle. Base légale : intérêt légitime (sécurité du site).</p>

<h2>Base légale</h2>
<ul>
<li><strong>Intérêt légitime</strong> — gestion de l'unité scoute (membres, fonctions, sections).</li>
<li><strong>Consentement</strong> — cookies non essentiels et envoi d'emails non transactionnels.</li>
</ul>

<h2>Durée de conservation</h2>
<ul>
<li><strong>Données des membres</strong> — durée de l'année scoute en cours.</li>
<li><strong>Comptes utilisateurs</strong> — jusqu'à suppression manuelle par l'administrateur.</li>
<li><strong>Journal d'audit</strong> — durée configurable (par défaut 2 ans).</li>
<li><strong>Liens magiques</strong> — expirés après 15 minutes, nettoyés périodiquement.</li>
</ul>

<h2>Cookies</h2>
<p>La liste complète et à jour des cookies utilisés par le site est affichée ci-dessous, dans la section « Cookies utilisés ». Vous pouvez modifier vos préférences à tout moment sur la page de préférences cookies.</p>

<h2>Sous-traitants</h2>
<ul>
<li><strong>Hébergeur web</strong> — stockage des données et exécution du site.</li>
<li><strong>Relais SMTP</strong> (si configuré) — envoi d'emails transactionnels (liens magiques, notifications).</li>
</ul>

<h2>Vos droits</h2>
<p>Conformément au RGPD, vous disposez des droits suivants :</p>
<ul>
<li><strong>Accès</strong> — demander une copie de vos données.</li>
<li><strong>Rectification</strong> — corriger des données inexactes.</li>
<li><strong>Suppression</strong> — demander l'effacement de vos données.</li>
<li><strong>Portabilité</strong> — recevoir vos données dans un format structuré.</li>
<li><strong>Opposition</strong> — vous opposer au traitement de vos données.</li>
</ul>
<p>Contactez-nous à <a href="mailto:{$contactEmail}">{$contactEmail}</a> pour exercer ces droits. Vous avez également le droit d'introduire une réclamation auprès de l'<a href="https://www.autoriteprotectiondonnees.be/citoyen" target="_blank" rel="noopener">Autorité de protection des données (APD)</a> belge.</p>

<h2>Politique de la fédération</h2>
<p>En tant qu'unité affiliée aux Scouts ASBL, les données de nos membres sont également soumises à la politique de protection des données de la fédération. Consultez la <a href="https://www.lesscouts.be/fr/ressources-scouts/administratif-1/web-et-vie-privee/protection-des-donnees-personnelles" target="_blank" rel="noopener">page dédiée de la fédération Les Scouts</a> ainsi que leur <a href="https://lesscouts.be/api/file/3240" target="_blank" rel="noopener">charte de protection des données personnelles</a>.</p>
HTML;
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

Contenu RGPD de référence (couvre les traitements du noyau du site) :
{$baseContent}

Instructions de l'administrateur :
{$userPrompt}

Tâche :
Génère un document RGPD complet, conforme au règlement européen, en HTML bien formaté.

Règles strictes :
1. Inclure TOUTES les informations du contenu de référence ci-dessus.
2. Ajouter les traitements spécifiques aux modules actifs :
   — llm_connector : données envoyées au fournisseur IA, clé API chiffrée, transfert hors UE si Anthropic.
   — sos_staff : gardes SOS, numéro de repli chiffré, identifiants API téléphonie chiffrés, relecture du mobile membre sans copie.
   — calendar : jeton ICS personnel, email de rappel relu depuis fiche membre chiffrée sans copie.
   — trombinoscope : affiche photos/totem/nom/fonction des chefs, pas de donnée supplémentaire propre.
   — banner, member_stats : pas de donnée personnelle propre.
3. Si llm_connector est actif, ajouter dans la section Sous-traitants le fournisseur IA avec les modèles utilisés. Pour chaque fournisseur/modèle, inclure :
   — la localisation des serveurs (cherche sur internet si nécessaire),
   — un lien vers leur politique de confidentialité officielle.
4. Si sos_staff est actif, ajouter le fournisseur de téléphonie (OVH Télécom) dans Sous-traitants avec localisation et lien vers leur politique de confidentialité.
5. Pour TOUS les sous-traitants (hébergeur, SMTP, IA, téléphonie), mentionner clairement la localisation des serveurs et fournir un lien vers leur politique de confidentialité. Tu PEUX chercher sur internet pour trouver ces informations si elles ne sont pas fournies ci-dessus.
6. Inclure la liste complète des cookies ci-dessus dans une section dédiée (contrairement au contenu par défaut, le texte généré par IA DOIT contenir la liste des cookies puisqu'il est inséré directement).
7. Appliquer les instructions de l'administrateur (ton, ajouts, précisions) SANS jamais retirer d'informations obligatoires RGPD.
8. Utiliser des balises HTML sémantiques : <h2>, <h3>, <p>, <ul>, <li>, <strong>.
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
