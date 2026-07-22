<?php

declare(strict_types=1);

namespace Core\View;

use Core\Config\SettingService;
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
     * Get the last time the default RGPD content actually changed, in UTC.
     * Used as the "last updated" date when mode is "default" — this file
     * is static, so its filesystem mtime reflects the last real content
     * change (deploy), unlike recomputing "today" on every page load.
     */
    public function getDefaultContentLastModified(): \DateTimeImmutable
    {
        $path = __DIR__ . '/rgpd_default.html';
        $timestamp = @filemtime($path);
        if ($timestamp === false) {
            $timestamp = time();
        }

        return (new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone('UTC'));
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
        $phoneProvider = $this->getPhoneProviderInfo();

        $systemPrompt = $this->buildSystemPrompt($baseContent, $activeModules, $providerInfo, $modelsInfo, $phoneProvider, $userPrompt);

        $request = new LlmRequest(
            prompt: "Génère le contenu RGPD complet en HTML selon la structure imposée dans le prompt système.",
            tier: LlmTier::CAPABLE,
            systemPrompt: $systemPrompt,
            timeoutSeconds: 90
        );

        // The RGPD system prompt is unusually large (full default content +
        // detailed rules), so the provider can take longer to respond than
        // PHP's default 30s max_execution_time. That limit is a hard script
        // timeout — unlike the provider's own HTTP timeout, it is NOT
        // catchable and would otherwise produce a raw fatal error page
        // instead of a normal LlmException. Raise it just for this call.
        $previousLimit = ini_get('max_execution_time');
        set_time_limit(120);

        try {
            $response = $this->llmConnector->complete($request);
        } finally {
            set_time_limit((int) $previousLimit);
        }

        try {
            return $this->sanitizeHtmlOutput($response->content);
        } catch (\RuntimeException $e) {
            // Log the raw LLM response to help diagnose the issue
            error_log('RGPD AI Generation Error: ' . $e->getMessage());
            error_log('Raw LLM Response (first 1000 chars): ' . substr($response->content, 0, 1000));
            throw $e;
        }
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

Contenu RGPD de référence (couvre TOUS les modules possibles, version la plus complète) :
{$baseContent}

Instructions de l'administrateur :
{$userPrompt}

PRINCIPE DE PRIORITÉ (essentiel) :
En cas de contradiction entre le contenu de référence ci-dessus et les instructions de l'administrateur, **les instructions de l'administrateur prévalent toujours**. Le contenu de référence décrit une situation générique et par défaut ; les instructions de l'administrateur décrivent la situation RÉELLE de cette unité. Si une phrase du contenu de référence (localisation, sous-traitant, absence de transfert hors UE, etc.) ne correspond plus à la réalité décrite par l'administrateur, tu DOIS la modifier, la reformuler ou la supprimer plutôt que de la conserver telle quelle.

Tâche :
Personnalise le contenu de référence ci-dessus selon le contexte réel du site, tel que décrit par les instructions de l'administrateur en priorité. Le document final doit être juridiquement correct, exhaustif et conforme au RGPD (Règlement UE 2016/679).

Structure OBLIGATOIRE (respecter scrupuleusement) :
1. Qui sommes-nous et objet de cette politique
   - 1.1 Identité du responsable du traitement (délai raisonnable, bénévoles)
   - 1.2 Cadre légal et fédération
   - 1.3 Acceptation de cette politique (participation = acceptation)
   - 1.4 Formation des animateurs (Code Qualité des Adultes)
   - 1.5 Logiciel open source
2. Quelles données collectons-nous et pourquoi
   - 2.1 Gestion des comptes et authentification
   - 2.2 Gestion des membres de l'unité
   - 2.3 Photos et droit à l'image (consentement explicite par participation, pas d'usage promotionnel, pas de partage fédération sans consentement)
   - 2.4 Fonctionnalités optionnelles (modules actifs uniquement)
   - 2.5 Sécurité et traçabilité
3. Combien de temps conservons-nous vos données (conservation active, archivage 5 ans après départ, journaux, suppression sur demande)
4. Avec qui partageons-nous vos données (sous-traitants essentiels sans mention localisation précise, modules, garanties art. 28 RGPD)
5. Où sont stockées vos données et transferts internationaux (localisation générique, transferts hors UE avec mécanismes art. 46)
6. Comment protégeons-nous vos données (mesures techniques détaillées : chiffrement AES-256, bcrypt, CSP, RBAC, plan incident)
7. Vos droits sur vos données personnelles (accès, rectification, effacement, portabilité, opposition, limitation, retrait, réclamation APD)
8. Cookies et technologies similaires (référence à /cookies pour liste et gestion)
9. Politique de la fédération Les Scouts (référence Les Scouts ASBL BE0409580916)
10. Modifications de cette politique (uniquement changement date)

RÈGLES CRITIQUES (ne JAMAIS déroger) :
1. **Date et notification** : Inclure en haut `<span id="rgpd-last-updated">` et bandeau "modifications = changement date uniquement"
2. **Acceptation par participation** : Section 1.3 doit mentionner que participation aux activités = acceptation RGPD
3. **Formation animateurs** : Section 1.4 doit mentionner Code Qualité des Adultes avec lien
4. **Photos et consentement** : Section 2.3 complète : participation = consentement explicite photos, partage parents uniquement, pas promotionnel, pas fédération sans consentement, droit retrait
5. **Modules actifs uniquement** : Retirer les sections des modules INACTIFS (comparer avec liste modules actifs)
6. **Personnalisation obligatoire** : Remplacer {$unitName} et {$contactEmail} partout. Ne JAMAIS laisser de placeholder générique
7. **Délai raisonnable bénévoles** : Section 1.1 doit mentionner "délai raisonnable" car organisation bénévole, visant 1 mois art. 12.3
8. **Hébergeur générique** : NE PAS demander à l'admin de remplir. Écrire "La localisation dépend de l'hébergeur sélectionné. Pour toute question, contacter le responsable."
9. **IA provider** : Utiliser les infos exactes du fournisseur actif ({$providerInfo}, {$modelsInfo}) avec localisation et privacy policy
10. **Téléphonie** : Si sos_staff actif, utiliser {$phoneProvider} (OVH Télécom ou autre)
10bis. **Envoi de mails** : Si mass_mail actif, conserver explicitement dans la section 2.4 : (a) que l'adresse email de chaque destinataire est copiée depuis la fiche membre chiffrée au moment précis du lancement de l'envoi (et non relue à chaque envoi individuel) puis conservée chiffrée dans une table dédiée aux destinataires ; (b) que l'adresse d'expédition (From) utilisée est celle de la section expéditrice choisie pour l'email, et non l'adresse d'expédition générale du site ; (c) qu'il n'existe à ce jour aucune purge automatique dédiée aux anciens envois — ils sont conservés au même titre que le reste des données actives de l'unité (section 3.1)
11. **Cookies** : Section 8 doit référencer la page /cookies pour consulter la liste et gérer les préférences, pas de tableau dans le RGPD
12. **Sécurité technique** : Garder détails précis (AES-256-CBC, bcrypt, CSP, RBAC, 6 rôles, WebAuthn, PHPStan niveau 6)
13. **Conservation 5 ans** : Mention obligatoire "5 ans après départ membre" pour archivage
14. **Base légale** : Chaque traitement doit avoir sa base légale (art. 6 RGPD)
15. **Transferts hors UE** : Si Anthropic ou hébergeur hors UE, mentionner SCC (art. 46.2.c RGPD)
16. **Open source** : Garder section 1.5 (PHP, Twig, PHPMailer, Bootstrap, licence AGPL-3.0)
17. **Obligation APD** : Conserver mentions notification 72h (art. 33), information personnes (art. 34), réclamation APD
18. **Instructions admin — intégration INTÉGRALE obligatoire** : {$userPrompt}
    Chaque instruction ci-dessus doit être traitée INDIVIDUELLEMENT et intégrée dans le document final, sans exception :
    - Découpe mentalement les instructions de l'administrateur en une liste de points distincts (chaque phrase ou idée séparée par un point, une virgule de juxtaposition, ou une nouvelle ligne compte comme un point à part).
    - Pour CHAQUE point, identifie la section existante la plus pertinente (ex : sous-traitants, données collectées, finalités, cookies, sécurité) et intègre l'information à cet endroit. S'il n'existe aucune section pertinente, crée une nouvelle sous-section plutôt que d'omettre l'information.
    - Ne résume JAMAIS plusieurs points administrateur en une seule phrase vague qui en perd le sens : reste fidèle à chaque détail fourni (noms de services, formats, usages, limitations mentionnées, etc.).
    - Une instruction qui semble mineure, redondante ou hors-sujet à première vue doit tout de même être traitée : NE JAMAIS l'ignorer silencieusement.
    - Applique ces instructions SANS compromettre la conformité légale ni retirer les éléments obligatoires listés dans les autres règles ci-dessus.
    - Rappel (voir PRINCIPE DE PRIORITÉ ci-dessus) : si une instruction contredit une phrase du contenu de référence, l'instruction de l'administrateur gagne toujours — modifie ou supprime la phrase du contenu de référence en conséquence.
19. **HTML pur** : Pas de ```html, pas de <html>/<body>, uniquement contenu direct
20. **Précision factuelle** : Ne JAMAIS inventer de données, modules ou sous-traitants qui ne sont mentionnés ni dans le contenu de référence, ni dans les instructions de l'administrateur. Cette règle ne s'applique PAS aux éléments explicitement déclarés par l'administrateur : ceux-ci doivent toujours être inclus (voir PRINCIPE DE PRIORITÉ et règle 18).
21. **Outils tiers déclarés par l'administrateur = sous-traitants à part entière** : Le contenu de référence structure la section 4 (sous-traitants) et la section 5.2 (transferts hors UE) uniquement autour des modules techniques du site (IA, téléphonie). Si les instructions de l'administrateur mentionnent un service tiers utilisé par l'unité EN DEHORS du site (ex : Google Workspace / Google for Nonprofits, Microsoft 365, réseau social, etc.), tu DOIS (conformément au PRINCIPE DE PRIORITÉ) :
    - L'ajouter comme sous-traitant à part entière dans la section 4, même s'il ne correspond à aucun module technique existant.
    - S'il stocke ou traite des données hors UE/EEE (ex : USA), l'ajouter explicitement à la liste des transferts hors UE en section 5.2, avec le mécanisme de garantie applicable (clauses contractuelles types de la Commission européenne, art. 46 RGPD, ou cadre de protection des données UE-USA (Data Privacy Framework) si le fournisseur y est certifié).
    - NE JAMAIS recopier tel quel la phrase de clôture du contenu de référence affirmant qu'« aucun autre transfert hors UE n'est effectué » si l'administrateur a déclaré un service basé hors UE : reformule cette phrase pour refléter fidèlement TOUS les transferts réels (modules actifs + outils tiers déclarés par l'administrateur).
    - Ce point est CRITIQUE lorsque l'administrateur signale explicitement un stockage aux USA ou hors UE : une omission constituerait une non-conformité RGPD grave (défaut d'information sur les transferts internationaux, art. 13.1.f et 44 à 49 RGPD).

Rappel final — instructions de l'administrateur à intégrer intégralement, point par point (voir règle 18) :
{$userPrompt}

Avant de répondre, vérifie mentalement que chaque point de ces instructions apparaît bien quelque part dans le document généré, et en particulier que la section 5.2 (transferts hors UE) et la section 4 (sous-traitants) reflètent bien tout service tiers hors UE mentionné ci-dessus (voir règle 21).

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
        $result = preg_replace('/^```html\s*\n/', '', $html);
        if ($result === null) {
            throw new \RuntimeException('Erreur regex lors du nettoyage du HTML généré (code fence début)');
        }
        $html = $result;
        
        $result = preg_replace('/\n```\s*$/', '', $html);
        if ($result === null) {
            throw new \RuntimeException('Erreur regex lors du nettoyage du HTML généré (code fence fin)');
        }
        $html = $result;

        // Remove full document wrappers if present
        $result = preg_replace('/<\?xml[^>]*>\s*/', '', $html);
        if ($result === null) {
            throw new \RuntimeException('Erreur regex lors du nettoyage du HTML généré (XML)');
        }
        $html = $result;
        
        $result = preg_replace('/<!DOCTYPE[^>]*>\s*/', '', $html);
        if ($result === null) {
            throw new \RuntimeException('Erreur regex lors du nettoyage du HTML généré (DOCTYPE)');
        }
        $html = $result;
        
        $result = preg_replace('/<html[^>]*>/', '', $html);
        if ($result === null) {
            throw new \RuntimeException('Erreur regex lors du nettoyage du HTML généré (html tag)');
        }
        $html = $result;
        
        $result = preg_replace('/<\/html>/', '', $html);
        if ($result === null) {
            throw new \RuntimeException('Erreur regex lors du nettoyage du HTML généré (html close)');
        }
        $html = $result;
        
        $result = preg_replace('/<head>.*?<\/head>/s', '', $html);
        if ($result === null) {
            throw new \RuntimeException('Erreur regex lors du nettoyage du HTML généré (head)');
        }
        $html = $result;
        
        $result = preg_replace('/<body[^>]*>/', '', $html);
        if ($result === null) {
            throw new \RuntimeException('Erreur regex lors du nettoyage du HTML généré (body tag)');
        }
        $html = $result;
        
        $result = preg_replace('/<\/body>/', '', $html);
        if ($result === null) {
            throw new \RuntimeException('Erreur regex lors du nettoyage du HTML généré (body close)');
        }
        $html = $result;

        return trim($html);
    }
}
