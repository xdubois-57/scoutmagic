<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\View;

use Core\Config\SettingService;
use Core\Module\ModuleManager;
use Modules\Gallery\Repository\StorageLocationRepository;
use Modules\LlmConnector\Api\LlmConnectorInterface;
use Modules\LlmConnector\Api\LlmRequest;
use Modules\LlmConnector\Api\LlmTier;
use Modules\LlmConnector\Repository\ProviderModelRepository;
use Modules\LlmConnector\Repository\ProviderRepository;

class RgpdContentService
{
    // How many extra "continue where you stopped" calls generateWithAi()
    // will make if the model's response comes back truncated — see
    // completeWithContinuation(). 2 extra calls (3 total) comfortably
    // covers a full RGPD document even if a single call's real ceiling
    // turns out to be much lower than the requested max_tokens.
    private const MAX_CONTINUATIONS = 2;

    // Set after construction (public/index.php builds this service before
    // the gallery module's own block, which is where its repository is
    // built) — nullable either way, since the gallery module itself is
    // optional (ARCHITECTURE.md §7.5 pattern, same as $llmConnector below).
    private ?StorageLocationRepository $galleryStorageLocationRepository = null;

    public function __construct(
        private ModuleManager $moduleManager,
        private SettingService $settingService,
        private ?LlmConnectorInterface $llmConnector = null,
        private ?ProviderRepository $llmProviderRepo = null,
        private ?ProviderModelRepository $llmModelRepo = null
    ) {
    }

    public function setGalleryStorageLocationRepository(StorageLocationRepository $repository): void
    {
        $this->galleryStorageLocationRepository = $repository;
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
     * Whether AI generation can actually run — the CAPABLE tier specifically,
     * since that is the only tier this document is ever generated on.
     * isAvailable() alone only says "some tier is configured", so a provider
     * offering only small models passed it and then failed inside complete()
     * with "Aucun modèle assigné au tier « capable »" after the button had
     * already been offered.
     */
    public function isAvailable(): bool
    {
        return $this->llmConnector !== null
            && $this->llmConnector->isTierAvailable(LlmTier::CAPABLE);
    }

    /**
     * Generate RGPD content via AI based on active modules and user prompt
     */
    public function generateWithAi(string $userPrompt): string
    {
        if (!$this->isAvailable()) {
            throw new \RuntimeException('Service IA non disponible.');
        }

        $baseContent = $this->getDefaultContent();
        $activeModules = $this->moduleManager->getEnabledModuleIds();
        $providerInfo = $this->getActiveProviderInfo();
        $modelsInfo = $this->getActiveModelsInfo();
        $phoneProvider = $this->getPhoneProviderInfo();
        $galleryStorage = $this->getGalleryStorageInfo();

        $systemPrompt = $this->buildSystemPrompt($baseContent, $activeModules, $providerInfo, $modelsInfo, $phoneProvider, $galleryStorage, $userPrompt);

        $request = new LlmRequest(
            prompt: "Génère le contenu RGPD complet en HTML selon la structure imposée dans le prompt système.",
            tier: LlmTier::CAPABLE,
            systemPrompt: $systemPrompt,
            timeoutSeconds: 90,
            // The full 10-section document this prompt demands runs well
            // past LlmConnectorService::DEFAULT_MAX_TOKENS (4096, tuned for
            // short replies) — without this override, every provider was
            // either capped at that default or (Scaleway/Mistral, before
            // they always sent an explicit max_tokens) capped even lower by
            // their own server-side default, silently truncating the
            // response with no way to detect it. Even so, some models cap a
            // single response well below this (or below whatever a very
            // detailed document plus custom instructions needs) — see
            // completeWithContinuation() below, which is what actually
            // guarantees the full document regardless of that per-call
            // ceiling.
            maxTokens: 8192
        );

        // The RGPD system prompt is unusually large (full default content +
        // detailed rules) and completeWithContinuation() below may issue
        // several sequential calls, so the provider can take much longer to
        // respond than PHP's default 30s max_execution_time. That limit is a
        // hard script timeout — unlike the provider's own HTTP timeout, it
        // is NOT catchable and would otherwise produce a raw fatal error
        // page instead of a normal exception. Raise it just for this call.
        $previousLimit = ini_get('max_execution_time');
        set_time_limit(300);

        try {
            $content = $this->completeWithContinuation($request);
        } finally {
            set_time_limit((int) $previousLimit);
        }

        try {
            $sanitized = $this->sanitizeHtmlOutput($content);
        } catch (\RuntimeException $e) {
            // Log the raw LLM response to help diagnose the issue
            error_log('RGPD AI Generation Error: ' . $e->getMessage());
            error_log('Raw LLM Response (first 1000 chars): ' . substr($content, 0, 1000));
            throw $e;
        }

        $unitName = $this->settingService->get('site_name') ?: 'Unité scoute';
        if (!$this->hasClearControllerDesignation($sanitized, $unitName)) {
            // The system prompt requires the deploying unit to be named as
            // data controller (never ScoutMagic or its author) — see rule
            // 6bis in buildSystemPrompt(). If that didn't happen, the text
            // must not be silently auto-saved and published (see save flow
            // in RgpdConfigController::generate()); surfacing this as an
            // exception routes it through that controller's existing
            // catch-all error handling instead.
            throw new \RuntimeException(
                "Le contenu généré ne désigne pas clairement « {$unitName} » comme responsable du traitement — il n'a pas été enregistré. Réessayez, ou complétez les instructions personnalisées pour préciser le nom de l'unité."
            );
        }

        return $sanitized;
    }

    /**
     * Simple post-generation sanity check: does the generated text actually
     * name the deploying unit as data controller? Not a full legal review —
     * just a guard against the model silently omitting or genericizing the
     * one thing rule 6bis of buildSystemPrompt() requires.
     */
    private function hasClearControllerDesignation(string $content, string $unitName): bool
    {
        $plainText = strip_tags($content);
        if ($unitName === '' || stripos($plainText, $unitName) === false) {
            return false;
        }

        return stripos($plainText, 'responsable du traitement') !== false;
    }

    /**
     * A single call's response can come back truncated (finish_reason
     * "length"/stop_reason "max_tokens") well before the requested
     * max_tokens ceiling if the model/provider enforces a lower per-call
     * cap of its own — no max_tokens value chosen up front can be trusted
     * to always be high enough. This is what actually guarantees the whole
     * document gets generated regardless of that: on truncation, it asks
     * the model (as a plain follow-up single-turn request, same system
     * prompt) to continue writing exactly where it stopped, and
     * concatenates the results, up to self::MAX_CONTINUATIONS extra calls.
     *
     * @throws \RuntimeException if still truncated after every continuation
     */
    private function completeWithContinuation(LlmRequest $request): string
    {
        $response = $this->llmConnector->complete($request);
        $accumulated = $response->content;

        $attempts = 0;
        while ($response->truncated && $attempts < self::MAX_CONTINUATIONS) {
            $attempts++;

            $continuationRequest = new LlmRequest(
                tier: $request->tier,
                prompt: "Voici le document HTML généré jusqu'ici, interrompu en cours de génération (peut-être en plein milieu d'une balise ou d'un mot) :\n\n"
                    . $accumulated
                    . "\n\nContinue directement l'écriture EXACTEMENT à partir de là où elle s'est arrêtée, jusqu'à la fin du document (section 10 incluse). Ne répète rien de ce qui précède, ne réécris pas depuis le début, n'ajoute aucun préambule ni commentaire — uniquement la suite du HTML.",
                systemPrompt: $request->systemPrompt,
                timeoutSeconds: $request->timeoutSeconds,
                maxTokens: $request->maxTokens
            );

            $response = $this->llmConnector->complete($continuationRequest);
            $accumulated .= $response->content;
        }

        if ($response->truncated) {
            throw new \RuntimeException(
                'La réponse générée a été tronquée malgré ' . (self::MAX_CONTINUATIONS + 1) . ' tentatives. Réessayez, ou raccourcissez les instructions personnalisées.'
            );
        }

        return $accumulated;
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
        string $galleryStorage,
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
- Stockage galerie : {$galleryStorage}

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
   - 2.6 Consultation hors ligne (uniquement avec consentement fonctionnel)
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
4bis. **Espace animés (page membre)** : Section 2.2 doit conserver explicitement : (a) que l'espace personnel d'un membre peut afficher des documents qui lui sont propres (documents privés, ex. future attestation fiscale), chiffrés au repos et accessibles uniquement aux comptes explicitement liés à ce membre — jamais à un chef ou administrateur non lié, même avec un rôle plus élevé ; (b) que le nom complet et l'adresse postale du chef désigné responsable d'une section sont affichés, sur la page de chaque membre de cette section, aux comptes qui lui sont liés (le membre, ses parents) — jamais publiquement ; (c) que le site conserve un historique des sections auxquelles chaque membre a appartenu au fil des années (utilisé uniquement pour déterminer l'accès aux documents de section ci-dessous) ; (d) que les responsables d'une section peuvent y déposer des documents (carnets de camp, feuilles d'activité, etc.), chiffrés au repos, consultables par tout membre ayant appartenu à cette section — y compris les années passées, même si la section a depuis été masquée — et que les PDF peuvent être compressés automatiquement en arrière-plan, entièrement sur le serveur, sans aucun envoi à un service externe ; (e) qu'un chef ou animateur peut marquer qu'un animé ne reviendra probablement pas l'année suivante, avec un motif optionnel chiffré au repos, jamais visible dans le journal d'audit, et propre à l'année scoute en cours (il ne se reporte jamais d'une année à l'autre) ; (f) qu'un index aveugle est calculé sur une forme normalisée non lisible de l'adresse postale, utilisé uniquement pour suggérer une catégorie de cotisation selon le nombre de personnes au même foyer — jamais pour afficher ou reconstituer l'adresse elle-même
4ter. **Consultation hors ligne (page membre et Mon compte incluses)** : Section 2.6 doit conserver explicitement : (a) que la copie locale hors ligne, réservée à l'application installée avec consentement fonctionnel, couvre désormais aussi votre propre page personnelle et « Mon compte » — pas uniquement les pages publiques et le calendrier/trombinoscope ; (b) que la page personnelle peut inclure, dans cette copie locale, le nom complet et l'adresse postale du chef responsable de section ainsi que vos fonctions, dans les mêmes conditions d'accès que la version en ligne (jamais publiquement) ; (c) que documents privés, données financières et contenu des emails groupés ne sont JAMAIS inclus dans cette copie locale, quel que soit l'appareil ; (d) que seule l'application installée écrit ou met à jour cette copie — une simple visite depuis un onglet de navigateur ordinaire ne fait qu'éventuellement la lire, jamais l'écrire ; (e) qu'elle est effacée intégralement à la déconnexion et au retrait de ce consentement
5. **Modules actifs uniquement** : Retirer les sections des modules INACTIFS (comparer avec liste modules actifs)
6. **Personnalisation obligatoire** : Remplacer {$unitName} et {$contactEmail} partout. Ne JAMAIS laisser de placeholder générique
6bis. **Responsable du traitement — jamais ScoutMagic** : Le responsable du traitement décrit en section 1.1 est TOUJOURS {$unitName} (l'unité qui déploie ce site), jamais « ScoutMagic », son auteur ou ses contributeurs. Le contenu de référence l'illustre déjà (« le chef d'unité de notre unité scoute ») : personnalise cette désignation avec {$unitName} sans jamais la remplacer par le nom du logiciel ou de son éditeur. Le logiciel ScoutMagic n'est mentionné, le cas échéant, qu'à titre d'outil technique utilisé par l'unité (section 1.5), jamais comme partie responsable d'un traitement de données. Cette règle n'admet aucune exception, y compris si les instructions de l'administrateur ne précisent rien à ce sujet.
7. **Délai raisonnable bénévoles** : Section 1.1 doit mentionner "délai raisonnable" car organisation bénévole, visant 1 mois art. 12.3
8. **Hébergeur générique** : NE PAS demander à l'admin de remplir. Écrire "La localisation dépend de l'hébergeur sélectionné. Pour toute question, contacter le responsable."
9. **IA provider** : Utiliser les infos exactes du fournisseur actif ({$providerInfo}, {$modelsInfo}) avec localisation et privacy policy
10. **Téléphonie** : Si sos_staff actif, utiliser {$phoneProvider} (OVH Télécom ou autre)
10bis. **Envoi de mails** : Si mass_mail actif, conserver explicitement dans la section 2.4 : (a) que l'adresse email de chaque destinataire est copiée depuis la fiche membre chiffrée au moment précis du lancement de l'envoi (et non relue à chaque envoi individuel) puis conservée chiffrée dans une table dédiée aux destinataires ; (b) que l'adresse d'expédition (From) utilisée est celle de la section expéditrice choisie pour l'email, et non l'adresse d'expédition générale du site ; (c) qu'il n'existe à ce jour aucune purge automatique dédiée aux anciens envois — ils sont conservés au même titre que le reste des données actives de l'unité (section 3.1)
10ter. **Actualités et formulaires** : Si news actif, conserver explicitement dans la section 2.4 : (a) que chaque article peut inclure un formulaire collectant une adresse email de contact (toujours obligatoire) et les réponses aux champs configurés par l'auteur de l'article (qui peuvent inclure nom, téléphone, email, texte libre) ; (b) que ces données servent à l'inscription à des activités, des sondages, ou le suivi de paiements liés à l'unité ; (c) que toutes les réponses sont conservées aussi longtemps que l'article existe et sont supprimées automatiquement avec lui (aucune purge automatique séparée) ; (d) si le module finance est également actif, qu'un paiement lié à un formulaire (place payante) génère une communication structurée bancaire belge et un QR code SEPA, sans qu'aucune donnée de carte bancaire ne soit jamais collectée ni stockée — ceci n'introduit aucun nouveau sous-traitant, la génération étant entièrement locale au site
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
22. **Stockage galerie (module gallery)** : Le contenu de référence liste TOUS les fournisseurs de stockage objet possibles (Hetzner, Cloudflare R2, Scaleway, OVHcloud) en section 4.2 et le module galerie en section 2.4. Le module gallery permet de configurer PLUSIEURS emplacements de stockage à la fois (disque local et/ou un ou plusieurs buckets S3, chaque album restant rattaché à celui utilisé lors de sa création) — {$galleryStorage} liste TOUS les emplacements réellement configurés. Tu dois adapter ces sections à cette configuration RÉELLE :
    - Si le module gallery n'est pas dans la liste des modules actifs : retire entièrement la section 2.4 "Module Galerie photos et vidéos" et le paragraphe "Fournisseurs de stockage objet" de la section 4.2.
    - Si {$galleryStorage} ne liste AUCUN emplacement S3 (uniquement du stockage local, ou aucun emplacement configuré) : conserve la section 2.4 du module galerie, mais retire complètement le paragraphe "Fournisseurs de stockage objet" de la section 4.2 (aucun sous-traitant externe, les fichiers restent chez l'hébergeur déjà couvert en 4.1) et ne mentionne aucun fournisseur de stockage objet.
    - Si {$galleryStorage} liste un ou plusieurs emplacements S3 : conserve dans la section 4.2 UNIQUEMENT les fournisseurs effectivement listés (retire ceux qui n'y figurent pas — il peut en rester un seul, ou plusieurs si plusieurs emplacements S3 de fournisseurs différents sont configurés), avec leurs informations exactes (localisation, lien vers leur politique de confidentialité) telles que fournies dans le contenu de référence. Adapte la phrase d'introduction du paragraphe si plusieurs fournisseurs restent (au pluriel) plutôt qu'un seul.
    - Si {$galleryStorage} indique qu'au moins un emplacement Cloudflare R2 a une région hors UE : conserve la mention du transfert hors UE correspondante en section 5.2 et dans la phrase de clôture de cette section ; sinon (aucun emplacement Cloudflare R2 hors UE) retire cette mention et n'ajoute aucun transfert hors UE lié à la galerie.
23. **Notifications push (fonctionnalité core, PAS un module)** : Contrairement aux sections 2.4/4.2 qui dépendent des modules actifs, les mentions des notifications push (souscription en section 2.1, service de push du navigateur en section 4.1, transfert hors UE en section 5.2) concernent une fonctionnalité du cœur du site, disponible sur toute installation ScoutMagic indépendamment des modules activés ou désactivés. Conserve-les TOUJOURS intégralement, quelle que soit la liste de modules actifs — ne les retire et ne les conditionne jamais à {$modulesText}. Elles restent optionnelles seulement au sens où chaque utilisateur individuel choisit ou non d'activer le bouton dans « Mon compte », pas au sens où l'administrateur pourrait désactiver la fonctionnalité elle-même.
24. **Vérification des mises à jour via GitHub (fonctionnalité core, PAS un module)** : Comme pour les notifications push (règle 23), la section 1.6 "Vérification des mises à jour" (le site reçoit une notification de GitHub — un webhook, requête entrante depuis github.com — lors de la publication d'une nouvelle version ou d'un envoi de code sur la branche de développement, aucune donnée personnelle transmise dans un sens ni dans l'autre, GitHub n'est PAS un sous-traitant) concerne une fonctionnalité du cœur du site, présente sur toute installation ScoutMagic. Conserve-la TOUJOURS intégralement, indépendamment de {$modulesText} — ne la place jamais en section 4 (sous-traitants) ni en section 5.2 (transferts hors UE), puisqu'aucune donnée personnelle n'y transite.
25. **Module Rétrospectives (module retro)** : Si "retro" ne figure PAS dans la liste des modules actifs ({$modulesText}), retire entièrement la sous-section "Module Rétrospectives" de la section 2.4. Si "retro" est actif, conserve-la intégralement — c'est un module conçu pour minimiser au maximum les données personnelles traitées (aucun commentaire ni vote n'est jamais lié à une personne), le contenu de référence l'explique déjà correctement, il n'y a rien à personnaliser au-delà de la présence ou non du module. Le paragraphe sur la modération/synthèse par IA optionnelle ne fait référence à aucun nouveau sous-traitant : si "llm_connector" est également actif, ce paragraphe reste ; sinon, retire uniquement ce paragraphe (mais garde le reste de la sous-section).
26. **Module Groupes de discussion (module groups)** : Si "groups" ne figure PAS dans la liste des modules actifs ({$modulesText}), retire entièrement la sous-section "Module Groupes de discussion" de la section 2.4 ainsi que la ligne correspondante en section 3.1. Si "groups" est actif, conserve-la intégralement et sans l'édulcorer — c'est, avec le module Envoi de mails, le module dont les membres alimentent eux-mêmes le contenu, et l'unité ne maîtrise pas ce qu'ils y écrivent. Conserve en particulier : (a) que le texte des messages et des réponses est écrit librement par les membres, n'est pas chiffré au repos, et peut donc contenir n'importe quelle donnée personnelle qu'un membre choisit d'y mettre, y compris au sujet de quelqu'un d'autre ; (b) qu'un groupe est privé et invisible aux non-membres (aucun annuaire, aucun groupe public, aucune demande d'adhésion) et que la liste de ses membres est recalculée à chaque consultation, de sorte qu'un membre quittant une section perd immédiatement l'accès sans qu'aucune suppression ne soit nécessaire ; (c) que les photos et vidéos d'un groupe ne sont accessibles qu'à ses membres et jamais depuis la galerie générale ; (d) qu'un signalement n'est jamais révélé à personne, pas même dans le journal d'audit ; (e) que le masquage automatique après plusieurs signalements est la seule conséquence automatique et que rien n'est jamais supprimé sans décision humaine ; (f) les trois durées de conservation (clôture pour inactivité, suppression d'un message après sa dernière activité avec l'exemption des messages épinglés, suppression intégrale d'un groupe clôturé), le fait qu'elles soient configurables par l'administrateur mais jamais groupe par groupe, et le fait que la suppression efface les fichiers eux-mêmes et pas seulement les données ; (g) qu'aucun email n'est envoyé pour les notifications de ce module. **Vérification IA** : si "llm_connector" est également actif, conserve le paragraphe sur la vérification automatique avant publication et **traite explicitement le fournisseur IA comme un sous-traitant à part entière pour le texte des messages qui lui est soumis** — il reçoit du contenu rédigé par les membres, ce qui suffit à en faire un sous-traitant, indépendamment du fait que ce soit un autre module qui déclenche l'appel : il doit donc apparaître en section 4 et, s'il traite hors UE/EEE, en section 5.2, avec le mécanisme de garantie applicable (voir aussi la règle 21). Si "llm_connector" n'est PAS actif, retire uniquement ce paragraphe et garde le reste de la sous-section.
27. **Module Inscriptions (module registration)** : Si "registration" ne figure PAS dans la liste des modules actifs ({$modulesText}), retire entièrement la sous-section "Module Inscriptions" de la section 2.4. Si "registration" est actif, conserve-la intégralement — le contenu de référence l'explique déjà correctement, il n'y a rien à personnaliser au-delà de la présence ou non du module. Conserve en particulier, sans les édulcorer : (a) que les index de recherche chiffrés (email, nom+prénom+date de naissance de l'enfant, adresse postale normalisée) ne servent jamais à bloquer ou refuser une demande, uniquement à la retrouver, à la rapprocher automatiquement d'un membre importé de Desk, ou à suggérer une catégorie de cotisation ; (b) qu'une adresse email secondaire de suivi confirmée par son propriétaire donne accès au suivi complet de la demande SANS validation par le staff — c'est une exposition volontaire, jamais un contrôle d'accès délégué à l'unité ; (c) que la page de suivi de la famille n'affiche une acceptation ou un refus qu'une fois l'email correspondant réellement envoyé, jamais au moment de la décision elle-même ; (d) que le rapprochement à l'import Desk (automatique) et le rapprochement manuel par numéro de tiers suivent tous deux exactement le même traitement, ne migrent jamais les données d'identité elles-mêmes (Desk reste la source faisant foi) mais reportent les adresses email secondaires confirmées sur la fiche du membre réel, et qu'un cas ambigu (plusieurs demandes ou plusieurs membres partageant les mêmes nom/prénom/date de naissance) n'est jamais rapproché automatiquement ; (e) que les notes internes du staff sont chiffrées, jamais visibles par la famille et jamais consignées dans le journal d'audit ; (f) que deux délais de conservation distincts, tous deux configurables par l'administrateur et comptés depuis la clôture de la demande (jamais depuis son dépôt), s'appliquent une fois la demande acceptée dans Desk, refusée ou retirée : disparition de l'espace personnel de la famille (3 mois par défaut) puis suppression définitive (2 ans par défaut) — une demande encore en attente ou acceptée n'est, elle, jamais supprimée automatiquement ; (g) que la page « Départs » est réservée, pour un chef ou animateur, aux seules sections dont il a la charge (l'administrateur voit toute l'unité), tandis que la page « Passage » n'a aucune restriction de ce type ; (h) que la « section de destination » choisie sur la page « Passage » pour un animé changeant de branche est une donnée de planification propre au module, jamais écrite dans les données Desk du membre ; (i) que le regroupement par adresse commune affiché sur la page « Passage » (même technique que la suggestion de catégorie de cotisation) reflète une adresse partagée, jamais une fratrie déclarée ; (j) que la liste de diffusion supplémentaire proposée au module Envoi de mails est nommée d'après l'année scoute ciblée, recomposée à chaque envoi, et ne contient jamais que des demandes acceptées et effectivement encodées dans Desk ; (k) que la page « Prévisions » n'affiche que des chiffres agrégés (effectifs par section, équilibre filles/garçons, pyramide des âges) — jamais un nom ni une donnée individuelle — et que le blocage de la bascule d'année scoute tant que des demandes restent ouvertes ne repose que sur un nombre de demandes, jamais sur leur contenu.

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
     * Get info about the gallery module's configured storage location(s)
     * for RGPD disclosure — several can coexist (local disk and/or one or
     * more S3 buckets), so this lists every one actually configured
     * instead of assuming a single active backend.
     */
    private function getGalleryStorageInfo(): string
    {
        if (!in_array('gallery', $this->moduleManager->getEnabledModuleIds(), true)) {
            return 'Aucun (module galerie inactif)';
        }
        if ($this->galleryStorageLocationRepository === null) {
            return 'Stockage local (disque du serveur, pas de sous-traitant externe)';
        }

        $locations = $this->galleryStorageLocationRepository->findAll();
        if ($locations === []) {
            return 'Stockage local (disque du serveur, pas de sous-traitant externe)';
        }

        $descriptions = [];
        foreach ($locations as $location) {
            if (!$location->isS3()) {
                $descriptions[] = 'Stockage local (disque du serveur, pas de sous-traitant externe)';
                continue;
            }
            $descriptions[] = match ($location->s3Provider) {
                'hetzner' => 'Hetzner Object Storage (Allemagne/Finlande, UE)',
                'cloudflare_r2' => 'Cloudflare R2 (réseau mondial, région selon configuration du bucket : ' . ($location->s3Region !== null && $location->s3Region !== '' ? $location->s3Region : 'non précisée') . ')',
                'scaleway' => 'Scaleway Object Storage (France/Pays-Bas, UE)',
                'ovhcloud' => 'OVHcloud Object Storage (France/Allemagne/Pologne, UE)',
                default => 'Fournisseur S3-compatible personnalisé (localisation selon configuration)',
            };
        }

        return implode(' ET ', array_unique($descriptions));
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
