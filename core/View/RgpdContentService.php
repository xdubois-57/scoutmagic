<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\View;

use Core\Config\AppClock;
use Core\Config\SettingService;
use Core\Module\ModuleManager;
use Core\Module\SubProcessorProvider;
use Core\Module\SubProcessorView;
use Modules\LlmConnector\Api\LlmConnectorInterface;
use Modules\LlmConnector\Api\LlmRequest;
use Modules\LlmConnector\Api\LlmTier;

class RgpdContentService
{
    // How many extra "continue where you stopped" calls generateWithAi()
    // will make if the model's response comes back truncated — see
    // completeWithContinuation(). 2 extra calls (3 total) comfortably
    // covers a full RGPD document even if a single call's real ceiling
    // turns out to be much lower than the requested max_tokens.
    private const MAX_CONTINUATIONS = 2;

    /**
     * The Core\Module\SubProcessorProvider hooks (§7.4) whose modules are
     * enabled — appended after construction because each module's
     * composition-root block runs after this service is built (the same
     * late-attach reason the gallery repository setter this replaces
     * had). This is how core states each module's effective
     * sub-processors without reading any module's tables.
     *
     * @var list<SubProcessorProvider>
     */
    private array $subProcessorProviders = [];

    public function __construct(
        private ModuleManager $moduleManager,
        private SettingService $settingService,
        private ?LlmConnectorInterface $llmConnector = null
    ) {
    }

    public function addSubProcessorProvider(SubProcessorProvider $provider): void
    {
        $this->subProcessorProviders[] = $provider;
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

        // On the application clock, like the editable_contents.modified_at
        // this alternates with on the RGPD page — the reader is Belgian and
        // the two branches must not render on different clocks.
        return (new \DateTimeImmutable('@' . $timestamp))->setTimezone(AppClock::zone());
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
     * Generate RGPD content via AI based on active modules and user prompt.
     *
     * @throws RgpdGenerationException when the AI service is unavailable,
     *         the answer stays truncated, or the produced text does not
     *         designate the deploying unit as data controller — the three
     *         refusals whose French wording the admin is meant to read
     * @throws \RuntimeException on an internal cleanup failure, whose
     *         message is NOT for the admin (see RgpdGenerationException)
     */
    public function generateWithAi(string $userPrompt): string
    {
        if (!$this->isAvailable()) {
            throw new RgpdGenerationException('Service IA non disponible.');
        }

        $baseContent = $this->getDefaultContent();
        $activeModules = $this->moduleManager->getEnabledModuleIds();

        // Every fact about a module's sub-processors comes from the
        // module itself, through the SubProcessorProvider hook — never
        // from core reading a module's tables. The views are folded back
        // into the prompt's established slots so the generated document's
        // inputs stay exactly what they have always been.
        $subProcessors = $this->collectSubProcessors();
        $providerInfo = $this->aiProviderInfo($subProcessors);
        $modelsInfo = $this->aiModelsInfo($subProcessors);
        $phoneProvider = $this->getPhoneProviderInfo();
        $galleryStorage = $this->galleryStorageInfo($subProcessors);

        $systemPrompt = $this->buildSystemPrompt($baseContent, $activeModules, $providerInfo, $modelsInfo, $phoneProvider, $galleryStorage, $userPrompt);

        $request = new LlmRequest(
            prompt: "Génère le contenu RGPD complet en HTML selon la structure imposée dans le prompt système.",
            tier: LlmTier::CAPABLE,
            systemPrompt: $systemPrompt,
            // Generous, because nothing is waiting on it any more: this
            // runs inside a scheduled task (Core\View\Task\
            // GenerateRgpdContentHandler), not inside a page load. Ninety
            // seconds was the ceiling a browser could stand, and it was
            // the wrong ceiling for the job — a ~38 000-token system
            // prompt asking a mid-sized model for a ten-section HTML
            // document is minutes of work, and « Timeout lors de l'appel
            // au fournisseur IA » was what an administrator saw for it.
            timeoutSeconds: 600,
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

        // The RGPD system prompt is unusually large — the whole 135 KB
        // reference document, around 38 000 tokens, before a single rule
        // is added — and completeWithContinuation() below may issue
        // several sequential calls, so the provider can take much longer
        // to respond than PHP's default 30s max_execution_time. That limit
        // is a hard script timeout — unlike the provider's own HTTP
        // timeout, it is NOT catchable and would otherwise produce a raw
        // fatal error page instead of a normal exception. The caller
        // raises it too (RgpdGenerationRunner); this stays because the
        // method must be safe to call from anywhere.
        $previousLimit = ini_get('max_execution_time');
        set_time_limit(1800);

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
            // catch-all error handling instead. RgpdGenerationException
            // rather than a plain \RuntimeException so this sentence — which
            // is the actionable half of the whole check — survives the
            // Core\Exception\UserFacingMessage gate at the display site.
            throw new RgpdGenerationException(
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
     * @throws RgpdGenerationException if still truncated after every continuation
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
            throw new RgpdGenerationException(
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
4bis-photo. **Photo de compte (fonctionnalité core, PAS un module)** : Section 2.1 doit conserver que chaque personne connectée peut déposer, depuis « Mon compte », une photo qui la représente à côté de son nom (menu, en-tête, messages des groupes de discussion) ; qu'elle est **facultative** — sans elle, ce sont les initiales qui s'affichent — ; qu'elle n'est **modifiable que par la personne elle-même**, jamais par un administrateur depuis le site ; qu'elle n'est visible que des personnes connectées et n'est jamais publiée publiquement ni transmise à un tiers ; et que la retirer **supprime réellement le fichier**. Ne la confonds pas avec la photo de membre de la section 2.2, qui est liée à une année scoute et peut être déposée par l'unité.
4. **Photos et consentement** : Section 2.3 complète : participation = consentement explicite photos, partage parents uniquement, pas promotionnel, pas fédération sans consentement, droit retrait
4bis. **Espace membres (page membre)** : Section 2.2 doit conserver explicitement : (a) que l'espace personnel d'un membre peut afficher des documents qui lui sont propres (documents privés, ex. attestation fiscale), chiffrés au repos et accessibles aux comptes explicitement liés à ce membre **et aux chefs d'unité** — ces derniers depuis la fiche du membre, dans le seul but de répondre à une famille et de lui renvoyer son document, **chaque ouverture et chaque renvoi étant consignés au journal d'audit** (identifiants uniquement) ; un animateur de section n'y a aucun accès. Ne décris jamais ces documents comme inaccessibles au staff : cette garantie a été retirée volontairement, et l'omettre tromperait le lecteur. Mentionne aussi l'accès distinct par substitution temporaire : un administrateur (chef d'unité) peut s'ajouter temporairement un membre à sa propre liste, le temps de sa session uniquement, afin de voir le site comme ce membre le voit et d'agir en son nom (support) ; pendant ce temps il accède aux documents privés de ce membre, à sa photo et à ses adresses email secondaires, chaque activation et chaque retrait étant consignés dans le journal d'audit, rien n'étant conservé au-delà de la session ni copié dans la consultation hors ligne ; (b) que le nom complet et l'adresse postale du chef désigné responsable d'une section sont affichés, sur la page de chaque membre de cette section, aux comptes qui lui sont liés (le membre, ses parents) — jamais publiquement ; (c) que le site conserve un historique des sections auxquelles chaque membre a appartenu au fil des années (utilisé uniquement pour déterminer l'accès aux documents de section ci-dessous) ; (d) que les responsables d'une section peuvent y déposer des documents (carnets de camp, feuilles d'activité, etc.), chiffrés au repos, consultables par tout membre ayant appartenu à cette section — y compris les années passées, même si la section a depuis été masquée — et que les PDF peuvent être compressés automatiquement en arrière-plan, entièrement sur le serveur, sans aucun envoi à un service externe ; (e) qu'un animateur peut marquer qu'un animé ne reviendra probablement pas l'année suivante, avec un motif optionnel chiffré au repos, jamais visible dans le journal d'audit, et propre à l'année scoute en cours (il ne se reporte jamais d'une année à l'autre) ; (f) qu'un index aveugle est calculé sur une forme normalisée non lisible de l'adresse postale, utilisé uniquement pour suggérer une catégorie de cotisation selon le nombre de personnes au même foyer — jamais pour afficher ou reconstituer l'adresse elle-même
4ter. **Consultation hors ligne (page membre et Mon compte incluses)** : Section 2.6 doit conserver explicitement : (a) que la copie locale hors ligne, réservée à l'application installée avec consentement fonctionnel, couvre désormais aussi votre propre page personnelle et « Mon compte » — pas uniquement les pages publiques et le calendrier/trombinoscope ; (b) que la page personnelle peut inclure, dans cette copie locale, le nom complet et l'adresse postale de l'animateur responsable de section ainsi que vos fonctions, dans les mêmes conditions d'accès que la version en ligne (jamais publiquement) ; (c) que documents privés, données financières et contenu des emails groupés ne sont JAMAIS inclus dans cette copie locale, quel que soit l'appareil ; (d) que seule l'application installée écrit ou met à jour cette copie — une simple visite depuis un onglet de navigateur ordinaire ne fait qu'éventuellement la lire, jamais l'écrire ; (e) qu'elle est effacée intégralement à la déconnexion et au retrait de ce consentement
4quater. **Fichier d'import Desk conservé (fonctionnalité core, PAS un module)** : Sections 2.2 et 3.1 doivent conserver explicitement, sans les édulcorer : (a) que chaque import Desk conserve le fichier CSV qui l'a produit, lequel contient en clair à l'intérieur du document toutes les données de membres énumérées en 2.2 pour l'unité entière — c'est l'artefact de données personnelles le plus dense du site ; (b) qu'il est chiffré au repos, jamais écrit en clair de façon durable sur le serveur, accessible aux seuls chefs d'unité, et que **chaque téléchargement est consigné** dans le journal d'audit (identifiants techniques uniquement, jamais de contenu) ; (c) que sa finalité est de pouvoir réexaminer un import douteux en confrontant son rapport au fichier exact qui l'a produit ; (d) que la durée de conservation est exprimée en **années scoutes**, réglable par l'administrateur, 2 par défaut (l'année en cours et la précédente), et que ce décompte est en saisons et non en nombre d'imports afin qu'une photographie du roster prise en novembre reste disponible pour vérifier la facture correspondante ; (e) qu'au terme du délai une saison entière part d'un bloc — ligne d'import, fichier chiffré et photographie du roster ensemble — via une tâche quotidienne qui s'exécute même si l'unité a cessé d'importer ; (f) qu'une demande d'effacement portant sur des données figurant dans un fichier conservé se traduit par la suppression du fichier entier concerné, un CSV ne pouvant être retouché sans perdre sa raison d'être. Ne présente jamais cette conservation comme facultative ni comme propre au module Cotisations : elle est le fait du cœur du site, indépendamment de tout module.
4quinquies. **Fusion de fiches membres (fonctionnalité core, PAS un module)** : Section 2.2 doit conserver que, lorsqu'un membre revient après une absence et a été recréé dans Desk au lieu de voir son ancienne fiche rouverte, un chef d'unité peut réunir les deux fiches ; que **rien n'est supprimé** (l'historique est rattaché à la fiche conservée et l'ancienne fiche est gardée, marquée comme fusionnée) ; que l'ancien numéro de tiers Desk est enregistré comme alias afin qu'un import ultérieur ne recrée pas la scission ; et que chaque fusion est consignée au journal d'audit sous forme d'identifiants numériques uniquement, jamais de noms. Ne présente jamais la fusion comme automatique : le site propose des rapprochements, un humain décide.
4sexies. **Notes internes sur un membre (fonctionnalité core, PAS un module)** : Sections 2.2 et 3.1 doivent conserver explicitement, sans les édulcorer : (a) que les chefs d'unité peuvent consigner sur la fiche d'un membre des notes libres **datées**, chacune portant son auteur et sa date, servant à transmettre d'un staff au suivant ce qu'il faut savoir pour accompagner un enfant ; (b) que ce texte est écrit librement et peut donc contenir des informations sensibles, y compris de santé ou relatives à la situation familiale ; (c) qu'il est **chiffré au repos** et n'apparaît jamais dans le journal d'audit, ni dans un message d'erreur, ni dans un export, ni dans un publipostage ; (d) que ces notes ne sont **jamais visibles par le membre ni par ses parents**, et qu'elles sont réservées aux chefs d'unité — un animateur de section n'y a pas accès ; (e) que chaque ajout, modification et suppression est consigné au journal d'audit sous forme d'identifiants numériques uniquement, jamais de contenu ; (f) que toute personne y ayant accès peut corriger ou supprimer une note, y compris celle d'un autre, afin qu'une note écrite par erreur sur la mauvaise personne puisse disparaître ; (g) qu'elles sont rattachées à la **personne** et non à une année scoute, et sont donc conservées tant que la fiche du membre existe, disparaissant avec elle. Ne les confonds jamais avec les notes internes du module Inscriptions, qui portent sur une demande d'inscription et non sur un membre.
5. **Modules actifs uniquement** : Retirer les sections des modules INACTIFS (comparer avec liste modules actifs)
6. **Personnalisation obligatoire** : Remplacer {$unitName} et {$contactEmail} partout. Ne JAMAIS laisser de placeholder générique
6bis. **Responsable du traitement — jamais ScoutMagic** : Le responsable du traitement décrit en section 1.1 est TOUJOURS {$unitName} (l'unité qui déploie ce site), jamais « ScoutMagic », son auteur ou ses contributeurs. Le contenu de référence l'illustre déjà (« le chef d'unité de notre unité scoute ») : personnalise cette désignation avec {$unitName} sans jamais la remplacer par le nom du logiciel ou de son éditeur. Le logiciel ScoutMagic n'est mentionné, le cas échéant, qu'à titre d'outil technique utilisé par l'unité (section 1.5), jamais comme partie responsable d'un traitement de données. Cette règle n'admet aucune exception, y compris si les instructions de l'administrateur ne précisent rien à ce sujet.
7. **Délai raisonnable bénévoles** : Section 1.1 doit mentionner "délai raisonnable" car organisation bénévole, visant 1 mois art. 12.3
8. **Hébergeur générique** : NE PAS demander à l'admin de remplir. Écrire "La localisation dépend de l'hébergeur sélectionné. Pour toute question, contacter le responsable."
9. **IA provider** : Utiliser les infos exactes du fournisseur actif ({$providerInfo}, {$modelsInfo}) avec localisation et privacy policy
10. **Téléphonie** : Si sos_staff actif, utiliser {$phoneProvider} (OVH Télécom ou autre)
10bis. **Envoi de mails** : Si mass_mail actif, conserver explicitement dans la section 2.4 : (a) que l'adresse email de chaque destinataire est copiée depuis la fiche membre chiffrée au moment précis du lancement de l'envoi (et non relue à chaque envoi individuel) puis conservée chiffrée dans une table dédiée aux destinataires ; (b) que l'adresse d'expédition (From) utilisée est celle de la section expéditrice choisie pour l'email, et non l'adresse d'expédition générale du site ; (c) qu'il n'existe à ce jour aucune purge automatique dédiée aux anciens envois — ils sont conservés au même titre que le reste des données actives de l'unité (section 3.1), à l'exception des données importées d'un publipostage (voir d) ; (d) conserver intégralement le paragraphe « Publipostage depuis un fichier Excel » : un animateur peut importer un fichier Excel dont chaque ligne définit un email (membre identifié par son numéro Desk « Tiers » ou adresse libre de la colonne « Email », y compris hors de l'unité), toutes les valeurs importées sont chiffrées au repos, le fichier est supprimé dès sa lecture, les données importées sont purgées automatiquement et définitivement 18 mois après l'envoi (durée fixe non modifiable), et la désinscription d'un destinataire externe est conservée sous forme d'empreinte irréversible de l'adresse, sans limite de durée
10ter. **Actualités et formulaires** : Si news actif, conserver explicitement dans la section 2.4 : (a) que chaque article peut inclure un formulaire collectant une adresse email de contact (toujours obligatoire) et les réponses aux champs configurés par l'auteur de l'article (qui peuvent inclure nom, téléphone, email, texte libre) ; (b) que ces données servent à l'inscription à des activités, des sondages, ou le suivi de paiements liés à l'unité ; (c) que toutes les réponses sont conservées aussi longtemps que l'article existe et sont supprimées automatiquement avec lui (aucune purge automatique séparée) ; (d) si le module finance est également actif, qu'un paiement lié à un formulaire (place payante) génère une communication structurée bancaire belge et un QR code SEPA, sans qu'aucune donnée de carte bancaire ne soit jamais collectée ni stockée — ceci n'introduit aucun nouveau sous-traitant, la génération étant entièrement locale au site ; (e) qu'un formulaire peut délivrer un billet d'entrée : la réponse reçoit alors une référence aléatoire, un code QR l'encodant est envoyé par email au répondant, et la validation de ce billet à l'entrée de l'évènement enregistre la date et l'heure du passage — une donnée de présence, conservée et supprimée avec la réponse, servant uniquement au contrôle des entrées et au recoupement avec les paiements ; (f) que le code QR d'un billet peut être servi comme image par une adresse web publique portant la référence et une empreinte cryptographique de celle-ci, afin qu'un logiciel de messagerie puisse l'afficher — cette adresse ne révèle que la référence, jamais le nom, le montant ni l'évènement, et aucune page publique du billet n'existe
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
22bis. **Carte et géocodage (module camps)** : Le contenu de référence décrit en section 4.2 le paragraphe "Fond de carte et géocodage" (OpenStreetMap Foundation) et introduit une catégorie de personnes concernées que ce site n'a nulle part ailleurs : des **tiers extérieurs à l'unité** (propriétaires et gestionnaires de terrains de camp), qui n'ont aucun lien avec l'unité et aucun compte sur le site.
    - Si le module camps n'est PAS dans la liste des modules actifs : retire entièrement le paragraphe "Fond de carte et géocodage" de la section 4.2, ainsi que toute mention des lieux de camp, de leurs contacts et de leur carte.
    - Si le module camps EST actif : conserve ce paragraphe ET le paragraphe "Résumé automatique des lieux de camp", et assure-toi que les sections sur les données collectées et sur les durées de conservation disent explicitement que (a) les coordonnées d'un contact de camp (nom, e-mail, téléphone, précisions libres) sont chiffrées en base, (b) un lieu et un séjour sont conservés sans limite de durée — c'est l'objet même du module, transmettre la mémoire d'un staff au suivant — et (c) un tiers extérieur peut demander l'effacement de ses coordonnées, qui sont alors remplacées partout dans le module, y compris dans l'historique des modifications, la trace du changement lui-même étant conservée sans les valeurs. Ne présente JAMAIS le géocodage comme un envoi de données personnelles : c'est l'adresse d'un terrain qui part, jamais celle d'une personne. De même pour le résumé automatique : il ne reçoit ni contacts ni e-mails. Conserve aussi, sans l'adoucir, que la carte est **dépliée par défaut** : les serveurs de tuiles sont contactés dès l'affichage de la liste des lieux, sans action de l'animateur — n'écris jamais l'inverse (la carte a été repliée par défaut par le passé, ce n'est plus le cas) — et que le repli choisi par un animateur n'est mémorisé dans le stockage local de son navigateur qu'avec son consentement aux cookies fonctionnels.
    - Si le module camps est actif mais qu'aucun connecteur IA ne l'est ({$providerInfo} n'indique aucun fournisseur actif) : retire le paragraphe "Résumé automatique des lieux de camp" ET le paragraphe "Nom d'un terrain lu dans un message reçu" — sans connecteur, ces deux fonctionnalités ne peuvent rien envoyer nulle part.
    - Si le module camps ET le module inbound_mail sont tous deux actifs : conserve les trois paragraphes "Création automatique d'un séjour" de la sous-section "Module Courrier entrant". Ils disent trois choses qu'il ne faut ni fusionner ni édulcorer : (a) le NOM D'EXPÉDITEUR du message ne sert JAMAIS à nommer un terrain — uniquement à en reconnaître un déjà enregistré, ce qui n'écrit rien nulle part ; (b) le nom ET L'ADRESSE d'un NOUVEAU terrain (rue, code postal, commune) sont lus dans le CONTENU du message par le modèle IA — son objet, son corps, ET le texte de ses pièces jointes (couche texte d'un PDF ou pièce en texte brut ; et, à défaut de texte lisible, une PAGE SCANNÉE ou une PHOTO du document est envoyée telle quelle au modèle pour transcription — au plus une par message), car un contrat de location arrive presque toujours en pièce jointe avec un corps de message d'un mot. Dis explicitement qu'une image d'un document peut donc être transmise au fournisseur d'IA, et qu'aucune transcription n'a lieu si le texte a pu être lu sans elle. Cela n'a lieu que si un connecteur IA est actif, et signifie que ce contenu — écrit par un tiers extérieur, signature et document contractuel compris, donc éventuellement le nom, l'adresse et le téléphone d'une personne physique tierce figurant dans ce document — est transmis au fournisseur d'IA, lequel a pour consigne de ne jamais renvoyer un nom de personne ni celui de l'expéditeur, de ne renvoyer que l'adresse DU LIEU DU SÉJOUR — jamais celle de l'expéditeur, de sa signature, d'un bureau où renvoyer un contrat, ni de l'unité elle-même — et de ne rien renvoyer en cas de doute ; précise que l'adresse ainsi lue est enregistrée en clair avec le lieu, au même titre que son nom, et qu'un lieu DÉJÀ connu de l'unité n'est jamais réécrit par cette lecture ; dis-le explicitement : la lecture ne se limite pas au corps du message ; (c) sans connecteur IA, en cas d'échec de l'appel ou de doute du modèle, AUCUN terrain n'est créé et rien n'est envoyé nulle part — le message reste rattaché à rien, où un chef d'unité le retrouve et valide lui-même le nom avant qu'il n'entre en base. Ne parle plus d'un « courrier non classé » : ce pseudo-dossier n'existe plus, le message est simplement conservé sans rattachement. Le nom d'un terrain reste stocké en clair comme toute donnée d'un lieu, et un chef d'unité peut le corriger, le fusionner ou l'archiver ; la création automatique est par ailleurs désactivable. Si un connecteur IA est actif, **traite le fournisseur d'IA comme un sous-traitant à part entière pour ce traitement** — il reçoit du texte rédigé par un tiers extérieur à l'unité, ce qui suffit à en faire un sous-traitant, indépendamment du fait que ce soit le module camps qui déclenche l'appel : il doit donc apparaître en section 4 et, s'il traite hors UE/EEE, en section 5.2, avec le mécanisme de garantie applicable (voir aussi les règles 21 et 26). Si AUCUN connecteur IA n'est actif, conserve les paragraphes (a) et (c) et retire le paragraphe (b) : sans connecteur, aucun terrain n'est jamais créé automatiquement. Si l'un des deux modules camps/inbound_mail est absent, retire les trois : sans boîte dédiée, rien n'est jamais créé de cette façon.
23. **Notifications push (fonctionnalité core, PAS un module)** : Contrairement aux sections 2.4/4.2 qui dépendent des modules actifs, les mentions des notifications push (souscription en section 2.1, service de push du navigateur en section 4.1, transfert hors UE en section 5.2) concernent une fonctionnalité du cœur du site, disponible sur toute installation ScoutMagic indépendamment des modules activés ou désactivés. Conserve-les TOUJOURS intégralement, quelle que soit la liste de modules actifs — ne les retire et ne les conditionne jamais à {$modulesText}. Elles restent optionnelles seulement au sens où chaque utilisateur individuel choisit ou non d'activer le bouton dans « Mon compte », pas au sens où l'administrateur pourrait désactiver la fonctionnalité elle-même.
24. **Vérification des mises à jour via GitHub (fonctionnalité core, PAS un module)** : Comme pour les notifications push (règle 23), la section 1.6 "Vérification des mises à jour" (le site reçoit une notification de GitHub — un webhook, requête entrante depuis github.com — lors de la publication d'une nouvelle version ou d'un envoi de code sur la branche de développement, aucune donnée personnelle transmise dans un sens ni dans l'autre, GitHub n'est PAS un sous-traitant) concerne une fonctionnalité du cœur du site, présente sur toute installation ScoutMagic. Conserve-la TOUJOURS intégralement, indépendamment de {$modulesText} — ne la place jamais en section 4 (sous-traitants) ni en section 5.2 (transferts hors UE), puisqu'aucune donnée personnelle n'y transite.
25. **Module Rétrospectives (module retro)** : Si "retro" ne figure PAS dans la liste des modules actifs ({$modulesText}), retire entièrement la sous-section "Module Rétrospectives" de la section 2.4. Si "retro" est actif, conserve-la intégralement — c'est un module conçu pour minimiser au maximum les données personnelles traitées (aucun commentaire ni vote n'est jamais lié à une personne), le contenu de référence l'explique déjà correctement, il n'y a rien à personnaliser au-delà de la présence ou non du module. Le paragraphe sur la modération/synthèse par IA optionnelle ne fait référence à aucun nouveau sous-traitant : si "llm_connector" est également actif, ce paragraphe reste ; sinon, retire uniquement ce paragraphe (mais garde le reste de la sous-section).
26. **Module Groupes de discussion (module groups)** : Si "groups" ne figure PAS dans la liste des modules actifs ({$modulesText}), retire entièrement la sous-section "Module Groupes de discussion" de la section 2.4 ainsi que la ligne correspondante en section 3.1. Si "groups" est actif, conserve-la intégralement et sans l'édulcorer — c'est, avec le module Envoi de mails, le module dont les membres alimentent eux-mêmes le contenu, et l'unité ne maîtrise pas ce qu'ils y écrivent. Conserve en particulier : (a) que le texte des messages et des réponses est écrit librement par les membres, n'est pas chiffré au repos, et peut donc contenir n'importe quelle donnée personnelle qu'un membre choisit d'y mettre, y compris au sujet de quelqu'un d'autre ; (b) qu'un groupe est privé et invisible aux non-membres (aucun annuaire, aucun groupe public, aucune demande d'adhésion) et que la liste de ses membres est recalculée à chaque consultation, de sorte qu'un membre quittant une section perd immédiatement l'accès sans qu'aucune suppression ne soit nécessaire ; (c) que les photos et vidéos d'un groupe ne sont accessibles qu'à ses membres et jamais depuis la galerie générale ; (d) qu'un signalement n'est jamais révélé à personne, pas même dans le journal d'audit ; (e) que le masquage automatique après plusieurs signalements est la seule conséquence automatique et que rien n'est jamais supprimé sans décision humaine ; (f) les trois durées de conservation (clôture pour inactivité, suppression d'un message après sa dernière activité avec l'exemption des messages épinglés, suppression intégrale d'un groupe clôturé), le fait qu'elles soient configurables par l'administrateur mais jamais groupe par groupe, et le fait que la suppression efface les fichiers eux-mêmes et pas seulement les données ; (g) qu'aucun email n'est envoyé pour les notifications de ce module. **Vérification IA** : si "llm_connector" est également actif, conserve le paragraphe sur la vérification automatique avant publication et **traite explicitement le fournisseur IA comme un sous-traitant à part entière pour le texte des messages qui lui est soumis** — il reçoit du contenu rédigé par les membres, ce qui suffit à en faire un sous-traitant, indépendamment du fait que ce soit un autre module qui déclenche l'appel : il doit donc apparaître en section 4 et, s'il traite hors UE/EEE, en section 5.2, avec le mécanisme de garantie applicable (voir aussi la règle 21). Si "llm_connector" n'est PAS actif, retire uniquement ce paragraphe et garde le reste de la sous-section.
27. **Module Inscriptions (module registration)** : Si "registration" ne figure PAS dans la liste des modules actifs ({$modulesText}), retire entièrement la sous-section "Module Inscriptions" de la section 2.4. Si "registration" est actif, conserve-la intégralement — le contenu de référence l'explique déjà correctement, il n'y a rien à personnaliser au-delà de la présence ou non du module. Conserve en particulier, sans les édulcorer : (a) que les index de recherche chiffrés (email, nom+prénom+date de naissance de l'enfant, adresse postale normalisée) ne servent jamais à bloquer ou refuser une demande, uniquement à la retrouver, à la rapprocher automatiquement d'un membre importé de Desk, ou à suggérer une catégorie de cotisation ; (b) qu'une adresse email secondaire de suivi confirmée par son propriétaire donne accès au suivi complet de la demande SANS validation par le staff — c'est une exposition volontaire, jamais un contrôle d'accès délégué à l'unité ; (c) que la page de suivi de la famille n'affiche une acceptation ou un refus qu'une fois l'email correspondant réellement envoyé, jamais au moment de la décision elle-même ; (d) que le rapprochement à l'import Desk (automatique) et le rapprochement manuel par numéro de tiers suivent tous deux exactement le même traitement, ne migrent jamais les données d'identité elles-mêmes (Desk reste la source faisant foi) mais reportent les adresses email secondaires confirmées sur la fiche du membre réel, et qu'un cas ambigu (plusieurs demandes ou plusieurs membres partageant les mêmes nom/prénom/date de naissance) n'est jamais rapproché automatiquement ; (e) que les notes internes du staff sont chiffrées, jamais visibles par la famille et jamais consignées dans le journal d'audit ; (f) que deux délais de conservation distincts, tous deux configurables par l'administrateur et comptés depuis la clôture de la demande (jamais depuis son dépôt), s'appliquent une fois la demande acceptée dans Desk, refusée ou retirée : disparition de l'espace personnel de la famille (3 mois par défaut) puis suppression définitive (2 ans par défaut) — une demande encore en attente ou acceptée n'est, elle, jamais supprimée automatiquement ; (g) que la page « Départs » est réservée, pour un animateur, aux seules sections dont il a la charge (l'administrateur voit toute l'unité), tandis que la page « Passage » n'a aucune restriction de ce type ; (h) que la « section de destination » choisie sur la page « Passage » pour un animé changeant de branche est une donnée de planification propre au module, jamais écrite dans les données Desk du membre ; (i) que le regroupement par adresse commune affiché sur la page « Passage » (même technique que la suggestion de catégorie de cotisation) reflète une adresse partagée, jamais une fratrie déclarée ; (j) que la liste de diffusion supplémentaire proposée au module Envoi de mails est nommée d'après l'année scoute ciblée, recomposée à chaque envoi, et ne contient jamais que des demandes acceptées et effectivement encodées dans Desk ; (k) que la page « Prévisions » n'affiche que des chiffres agrégés (effectifs par section, équilibre filles/garçons, pyramide des âges) — jamais un nom ni une donnée individuelle — et que le blocage de la bascule d'année scoute tant que des demandes restent ouvertes ne repose que sur un nombre de demandes, jamais sur leur contenu ; (l) que la réponse de réinscription d'une famille comporte trois données personnelles distinctes — la décision (et éventuellement la section souhaitée), un commentaire libre chiffré, et **des prénoms ou noms de TIERS** cités comme souhaits « avec qui » —, que ces noms sont écrits par une famille au sujet d'enfants d'autres familles, sont chiffrés au repos, ne sont jamais affichés à la famille citée ni recherchables par leur texte, et ne servent qu'à tenter sans garantie de placer deux enfants dans la même section ; (m) que l'absence de réponse est un état à part entière (rien n'est enregistré tant qu'une famille n'a pas répondu, et une famille qui a répondu ne reçoit plus de rappel), et que la réponse de la famille positionne l'indication de départ mais n'est ni modifiée ni effacée lorsque le staff la corrige ensuite. (n) que la page « Passage » porte, à côté de la réponse de la famille, une note interne du staff chiffrée au repos, jamais visible par la famille, jamais dans un export qui lui est destiné et jamais journalisée ; et (o) que la relecture facultative des commentaires libres par une IA n'a lieu que sur un geste explicite d'un chef d'unité, n'envoie que le commentaire (sans le nom de l'enfant ni celui de la famille), n'envoie chaque commentaire qu'une fois, conserve le résultat chiffré et présenté « à vérifier », et n'alimente aucun traitement automatique avant validation par un humain. Ne réduis JAMAIS (l) à « des préférences » : le fait que ce champ contienne le nom d'un enfant tiers est précisément ce qu'une politique de confidentialité doit dire. Pour (o), si un connecteur IA est actif, **traite le fournisseur d'IA comme un sous-traitant à part entière pour ce traitement** — il reçoit du texte rédigé par une famille — et fais-le donc apparaître en section 4 et, s'il traite hors UE/EEE, en section 5.2 ; si AUCUN connecteur IA n'est actif, retire entièrement le paragraphe « Relecture facultative des commentaires par une IA », qui décrirait alors une fonctionnalité que cette unité n'a pas.
30. **Rétention et IA du module Locations (module rental)** : Si "rental" est actif, conserve intégralement, sans les édulcorer : (a) que le délai de conservation d'une réservation se compte **depuis la clôture de l'exercice comptable contenant le séjour**, jamais depuis le séjour lui-même, et que la valeur par défaut de 7 ans est une aide au paramétrage et **jamais un conseil juridique** ; (b) que la suppression est **totale** — réservation, lignes de prix, documents, jetons, relevés, état des lieux, incidents, décompte et emails rattachés, fichiers compris — et qu'elle s'applique de la même manière à une demande refusée, annulée ou restée sans suite ; (c) qu'il ne subsiste qu'**une ligne anonyme** (bien, mois, nombre de jours, montant), sans identifiant, sans jeton, sans fichier et sans rien de rattachable à une personne, servant uniquement à ce que les totaux annuels restent justes. **IA** : si "llm_connector" est également actif, conserve les deux paragraphes sur l'assistance par intelligence artificielle et **traite explicitement le fournisseur d'IA comme un sous-traitant à part entière** — il reçoit la photo d'un compteur, un nom de fichier, un objet d'email ou le texte d'un échange, ce qui suffit à en faire un sous-traitant, indépendamment du fait que ce soit un autre module qui déclenche l'appel : il doit donc apparaître en section 4 et, s'il traite hors UE/EEE, en section 5.2, avec le mécanisme de garantie applicable (voir aussi les règles 21 et 26). Conserve en particulier que **l'IA ne décide jamais** : aucune acceptation ou refus de réservation, aucune modification de prix convenu, aucune imputation de dégât, aucune retenue de caution, aucun remboursement, aucun changement de statut financier définitif — chaque proposition est validée ou écartée par un gestionnaire. Si "llm_connector" n'est PAS actif, retire uniquement ces deux paragraphes et garde le reste de la sous-section.
29. **Module Courrier entrant (module inbound_mail)** : Si "inbound_mail" ne figure PAS dans la liste des modules actifs ({$modulesText}), retire entièrement la sous-section "Module Courrier entrant" de la section 2.4 ainsi que la ligne correspondante en section 3.1. Si "inbound_mail" est actif, conserve-la intégralement et sans l'édulcorer. Conserve en particulier : (a) que la boîte distante n'est **jamais** modifiée — aucun message marqué comme lu, déplacé, supprimé ni renommé ; (b) que **tous** les messages lus dans les boîtes configurées sont désormais enregistrés, y compris ceux qu'aucun dossier ne reconnaît — ne réintroduis jamais l'ancienne formulation selon laquelle un message non reconnu serait ignoré ou jamais enregistré, elle est fausse ; (b bis) que cette archive est encadrée par trois garanties indissociables qu'il ne faut ni fusionner ni omettre : une **suppression automatique** au terme d'un délai réglable (90 jours par défaut) des messages que rien ne rattache — ni association, ni proposition encore en attente —, délai **compté depuis la date du message et non depuis son détachement**, avec un plancher de trente jours après un détachement ; un **chef d'unité responsable**, seule personne pouvant consulter un message non rattaché ; et un **plafond de stockage** dont le dépassement supprime par anticipation les plus anciens messages non rattachés et prévient le superadministrateur. Si l'administrateur indique avoir modifié le délai, reprends sa valeur, mais conserve le mécanisme ; (b ter) qu'une pièce jointe non conservée (trop volumineuse, type refusé, plafond atteint) laisse trace de son **nom, type, taille et raison**, sans son contenu, afin qu'un lecteur sache qu'un fichier existe dans la boîte d'origine — tandis qu'un logo de signature est écarté sans trace, n'étant pas une pièce jointe ; (b quater) que le détachement d'un message d'un dossier lui **retire l'accès des gestionnaires de ce dossier** mais ne le supprime pas sur-le-champ ;  (c) qu'aucune image distante n'est jamais chargée, parce qu'une image invisible dans un email est un accusé de lecture ; (d) que le type réel d'une pièce jointe est déterminé à partir de son contenu et jamais de son nom ; (e) que les identifiants de la boîte sont chiffrés, ne sont jamais réaffichés même partiellement, ne sont accessibles qu'au superadministrateur, et n'apparaissent jamais dans un message d'erreur ; (f) qu'un gestionnaire pouvant consulter un dossier ne voit que les messages de CE dossier, sans aucune recherche ni liste générale donnant accès au reste de la boîte, le **chef d'unité** faisant seul exception au titre de la responsabilité décrite en (b bis) — cette exception est la contrepartie assumée de la conservation, ne la présente pas comme un élargissement d'accès des gestionnaires. **Le fournisseur de la boîte mail n'est PAS un nouveau sous-traitant introduit par ce module** : c'est la boîte que l'unité utilise déjà, ScoutMagic s'y connecte en lecture seule et ne lui transmet aucune donnée. Ne l'ajoute donc ni en section 4 ni en section 5.2 à ce titre — sauf, conformément à la règle 21, si l'administrateur déclare par ailleurs utiliser ce fournisseur comme outil de l'unité.
28. **Statistiques d'utilisation et archive de diagnostic (fonctionnalité core, PAS un module)** : Comme pour les notifications push (règle 23) et la vérification des mises à jour (règle 24), les deux paragraphes de la section 4.1 consacrés aux « Statistiques d'utilisation » et à l'« Archive de diagnostic » concernent le cœur du site, présent sur toute installation ScoutMagic. Conserve-les TOUJOURS, indépendamment de {$modulesText}, et sans les édulcorer. Conserve en particulier : (a) que l'envoi des statistiques est optionnel, activable et désactivable par l'unité depuis la page Configuration > Support ; (b) que ce rapport **n'est pas anonyme**, puisqu'il contient l'adresse du site — ne le décris jamais comme anonyme ou anonymisé ; (c) qu'il ne contient aucune donnée de membre (ni nom, ni email, ni photo, ni contenu), uniquement des compteurs agrégés et des informations techniques sur le logiciel et l'hébergement ; (d) que l'archive de diagnostic reste sur le serveur et n'est **jamais transmise automatiquement** (ni tâche planifiée, ni courriel, ni envoi décidé par le site), mais qu'un administrateur peut la transmettre lui-même au support ScoutMagic en la joignant à un ticket, après avoir vu son contenu et sa taille et coché explicitement qu'il l'accepte — et que cette transmission fait de l'équipe ScoutMagic une **sous-traitante** au sens de l'article 28 RGPD pour les adresses IP et identifiants internes que l'archive contient ; ne présente jamais cette transmission comme automatique, ni comme impossible ; (e) qu'elle peut contenir des adresses IP issues des journaux du serveur web et des identifiants internes de membres, mais aucun nom, adresse email ni contenu de membre. (f) que **l'ouverture d'un ticket de support transmet toujours un rapport d'utilisation**, même sur une installation où l'envoi quotidien est désactivé : c'est ce qui permet au mainteneur de savoir quelle version et quel hébergement ont produit le problème signalé, et cela ne réactive pas l'envoi quotidien pour autant — le rapport part parce qu'un administrateur a cliqué, jamais parce qu'une tâche s'est exécutée. Dis-le explicitement plutôt que de laisser croire qu'un refus des statistiques empêche toute transmission de ces compteurs. Si l'administrateur indique que l'envoi de statistiques est désactivé chez lui, tu peux le préciser, mais conserve la description du traitement (la fonctionnalité reste présente et réactivable), y compris ce point (f).

33. **Tickets de support reçus (module support_dashboard, installation receveuse uniquement)** : Ce module ne se charge que sur l'installation qui **reçoit** les tickets des autres — il porte `visible_when: ["statistics_receiver"]` et n'existe sur aucune installation d'unité. S'il ne figure PAS dans la liste des modules actifs ({$modulesText}), n'ajoute rien à ce titre : une unité ordinaire n'est pas concernée, et décrire ce traitement chez elle serait faux. S'il est actif, dis que cette installation **reçoit et conserve les tickets de support envoyés par d'autres installations ScoutMagic**, ce qui en fait la **responsable du traitement** pour ces données et non un simple hébergeur. Conserve en particulier : (a) que chaque ticket contient une description écrite par un administrateur au sujet de sa propre installation et une adresse de contact pour lui répondre, toutes deux chiffrées au repos ; (b) que l'archive de diagnostic éventuellement jointe peut contenir des adresses IP et des identifiants internes de membres de l'unité émettrice, qu'elle est chiffrée au repos et réservée au super-administrateur, et qu'elle est supprimée 90 jours après la clôture du ticket et au plus tard un an après sa réception — fichier compris, jamais seulement la référence ; (c) que le ticket lui-même, ses métadonnées et la note de résolution sont conservés deux ans ; (d) que rien ne repart vers l'instance émettrice : le ticket est à sens unique et la réponse se fait par courriel ordinaire. **Analyse transversale par IA** : si "llm_connector" est également actif, conserve le paragraphe correspondant et **traite explicitement le fournisseur d'IA comme un sous-traitant à part entière pour ce traitement** — il reçoit la catégorie, la description et la note de résolution de tickets rédigés par des personnes identifiables chez d'autres unités, ce qui suffit à en faire un sous-traitant, indépendamment du fait que l'appel passe par l'API d'un autre module : il doit donc apparaître en section 4 et, s'il traite hors UE/EEE, en section 5.2, avec le mécanisme de garantie applicable (voir aussi les règles 21, 26 et 32). Dis aussi que **l'adresse de contact, l'URL de l'instance et l'identifiant d'installation ne sont jamais transmis** au fournisseur, et que l'analyse ne se déclenche que sur un geste explicite d'un super-administrateur, jamais à l'affichage d'une page. Si "llm_connector" n'est PAS actif, retire uniquement ce paragraphe : sans connecteur, le bouton n'est pas proposé et aucune donnée ne sort.

31. **Module Encadrement (module leadership)** : Si "leadership" ne figure PAS dans la liste des modules actifs ({$modulesText}), retire entièrement la sous-section "Module Encadrement" de la section 2.4. Si "leadership" est actif, conserve-la intégralement et sans l'édulcorer. Ce module **ne collecte aucune donnée nouvelle** : il relit ce que l'import Desk a déjà enregistré et le présente autrement, et sa seule table stocke la correspondance entre une formulation de niveau de formation et une étape normalisée — une information sur un mot, jamais sur une personne. Conserve en particulier, car ce sont les points sur lesquels une omission serait trompeuse : (a) qu'aucune information relative à un CQA ou à un extrait de casier judiciaire n'est stockée sous quelque forme que ce soit, et qu'aucune page n'affiche jamais qu'un tel document serait en ordre, manquant, valide ou expiré — le site se borne à répercuter le signalement « candidat » de Desk et l'approche des 20 ans, la vérification se faisant dans Desk ; (b) que la note d'unité, unique pour toute l'unité, **n'est pas chiffrée au repos** (comme tout le contenu éditable du site) et que la page qui la porte invite explicitement à n'y consigner aucune information sensible ; (c) que les quatre pages du module sont réservées aux chefs d'unité, tandis que le parcours de formation affiché sur la page personnelle d'un membre n'est visible **que par ce membre lui-même**, jamais par un chef consultant sa page. Ce module n'introduit **aucun sous-traitant** et ne fait **aucun appel à une IA** : ne l'ajoute ni en section 4 ni en section 5.2.

31ter. **Campagnes de paiement (module finance)** : Si "finance" est actif, conserver dans la sous-section "Module Finances" de la section 2.4 : (a) qu'une campagne de paiement facture un montant à chaque membre d'une liste, avec **une créance et une communication structurée par membre** — une famille de trois enfants reçoit trois demandes ; (b) que le fichier Excel chargé pour créer la campagne est conservé chiffré au repos, avec une copie de ses colonnes servant à personnaliser les rappels, et que ces deux éléments sont supprimés lorsque l'année scoute de la campagne sort de la fenêtre de conservation des imports Desk (2 années scoutes par défaut) — la campagne, ses montants et ses créances étant, eux, conservés comme données comptables ; (c) que la note interne écrite par un trésorier au sujet d'une créance est du texte libre chiffré au repos, visible des seuls trésoriers autorisés sur le compte, **jamais** communiquée à la famille ni reprise dans un rappel, et jamais inscrite dans le journal d'audit ; (d) que le membre facturé est désigné par son identifiant interne et jamais par un nom réenregistré dans la campagne ; (e) qu'aucune donnée de carte bancaire n'est jamais collectée — les paiements se font par virement ; (f) que lorsque le trésorier prévient les familles, **une seule notification agrégée par compte** est créée pour chaque compte lié aux membres concernés (parent comme animé), portant le montant restant dû et le prénom des membres, et que le mode discrétion des réglages de notifications retire ce montant de la notification poussée sur l'appareil sans toucher au centre de notifications ; (g) que le code QR de paiement transmis dans un rappel ou affiché sur la page d'un membre est servi par une adresse portant un jeton dérivé de l'identifiant de la créance, ne donnant accès qu'au montant et à la communication de cette seule créance — aucun nom, aucune autre créance — et que le code n'est jamais conservé mais régénéré à chaque affichage. Cette fonctionnalité n'introduit **aucun sous-traitant** et ne fait **aucun appel à une IA** : ne l'ajoute ni en section 4 ni en section 5.2.

32. **Module Cotisations (module fees)** : Si "fees" ne figure PAS dans la liste des modules actifs ({$modulesText}), retire entièrement la sous-section "Module Cotisations" de la section 2.4. Si "fees" est actif, conserve-la intégralement et sans l'édulcorer. Conserve en particulier : (a) que la photographie du roster prise à chaque import Desk ne contient **aucun nom ni aucune date de naissance**, uniquement un identifiant interne de membre et des codes (catégorie tarifaire, section, rôle de la fonction, niveau de formation, départ annoncé) ; (b) que cette photographie est désormais prise par le cœur du site à chaque import, et non par ce module — elle existe donc même si le module n'a jamais été activé — et qu'elle suit la durée de conservation des imports Desk décrite en section 3.1 (années scoutes, 2 par défaut), parce qu'elle est le seul état passé du roster que le site garde ; (c) que le module **n'écrit jamais dans Desk** et n'adresse **aucune demande de paiement à une famille**. Conserve aussi (d) que le motif écrit par un chef d'unité pour écarter un foyer de la vérification est du texte libre pouvant décrire une situation familiale, qu'il est chiffré au repos, qu'il n'apparaît jamais dans le journal d'audit, et que l'adresse d'un foyer écarté n'est enregistrée que sous forme d'index de recherche chiffré, jamais en clair ; (e) que les factures importées sont conservées sans aucun nom — les personnes qu'une ligne facture nommément sont rapprochées des fiches annuelles à l'import et **seul l'identifiant interne du membre reconnu est enregistré**, une personne non reconnue devenant une ligne anonyme qui préserve le compte ; (f) que le PDF de la facture, qui contient lui des noms et des dates de naissance, n'est conservé que si le chef d'unité le demande explicitement ET que le module Finances est actif — il devient alors un justificatif de dépense ordinaire de ce module, chiffré au repos et soumis à SA durée de conservation ; sans cette demande, ou sans le module Finances, aucun PDF n'est conservé et la vérification est identique. Ne présente jamais cette conservation du PDF comme automatique. Conserve enfin (g) la recherche facultative des montants du barème : le module n'introduit **aucun sous-traitant de son propre fait** et ne traite **aucune donnée personnelle** dans cette fonction, mais elle produit deux flux sortants qu'il faut dire. Si "llm_connector" est actif, conserve le paragraphe correspondant et dis explicitement : (1) que le serveur consulte en lecture seule une page publique du site de la fédération, dont l'adresse est un réglage du site — requête sortante vers un tiers, sans aucune donnée personnelle ni aucune donnée de l'unité transmise, ce tiers n'étant PAS un sous-traitant (même raisonnement que la règle 24 pour GitHub : ne l'ajoute ni en section 4 ni en section 5.2) ; (2) que le texte de cette page publique est ensuite transmis au fournisseur d'IA configuré, ce qui **fait de ce fournisseur un sous-traitant à part entière pour ce traitement**, indépendamment du fait que ce soit le module Cotisations qui déclenche l'appel — il doit donc apparaître en section 4 et, s'il traite hors UE/EEE, en section 5.2, avec le mécanisme de garantie applicable (voir aussi les règles 21 et 26) ; (3) qu'aucune donnée de membre, de foyer ou de facture n'est jamais envoyée lors de cette recherche, et que le résultat n'est jamais enregistré automatiquement — les montants sont pré-remplis à l'écran et validés à la main par un chef d'unité. Si "llm_connector" n'est PAS actif, retire uniquement ce paragraphe : sans connecteur, le bouton n'est pas proposé et aucune requête sortante n'est faite.

33. **Module Attestations (module attestations)** : Si "attestations" ne figure PAS dans la liste des modules actifs ({$modulesText}), retire entièrement la sous-section "Module Attestations" de la section 2.4. Si "attestations" est actif, conserve-la intégralement et sans l'édulcorer, et assure-toi que la section 3.1 dit explicitement qu'il n'y a **aucune purge automatique** des documents privés. Conserve en particulier : (a) que le PDF déposé contient les attestations nominatives de TOUTE l'unité dans un seul document et qu'il n'est **jamais conservé** — il est supprimé aussitôt après le découpage, que celui-ci ait réussi ou échoué ; (b) que le rapprochement se fait **sur le seul nom**, seule information que le document porte, et que le nom tel qu'imprimé est conservé chiffré sur la ligne le temps de la vérification humaine ; (c) que chaque attestation découpée est chiffrée au repos et rattachée à son membre, donc lisible des seuls comptes liés à ce membre **et des chefs d'unité**, ces derniers depuis la fiche du membre et pour lui renvoyer son document — jamais un animateur de section — et que **chaque ouverture et chaque renvoi sont consignés au journal d'audit** ; ne présente jamais ces documents comme inaccessibles au staff ; (d) que l'envoi par e-mail est une **décision explicite du chef d'unité, jamais automatique**, que l'attestation part **en pièce jointe** à la dernière adresse connue du membre dans Desk — ce qui est précisément ce qui permet à une famille ayant quitté l'unité d'être atteinte — et qu'aucune adresse nouvelle n'est collectée ; (e) qu'un envoi refusé n'est **jamais réessayé automatiquement** — une attestation reçue deux fois est pire qu'une attestation reçue une fois — et qu'un membre dont le site ne connaît aucune adresse est simplement compté comme tel, son attestation restant sur sa page ; (f) qu'une seule notification par compte est envoyée, jamais une par enfant, qu'elle ne porte que le libellé du lot, et que son canal e-mail est désactivé puisque l'attestation arrive déjà par e-mail ; (g) qu'un chef d'unité peut reprendre un lot entier, ce qui supprime définitivement les documents que ce lot avait déposés (fichiers compris) sans rattraper les e-mails déjà partis ; (h) que le journal ne contient que des compteurs et des identifiants numériques. Ce module n'introduit **aucun sous-traitant** et ne fait **aucun appel à une IA** : ne l'ajoute ni en section 4 ni en section 5.2.

34. **Assistant d'aide (fonctionnalité du cœur du site, section 2.7)** : Cette section ne dépend d'aucun module — elle dépend uniquement de la présence d'un connecteur IA actif. Si "llm_connector" ne figure PAS dans la liste des modules actifs ({$modulesText}), ou si {$providerInfo} n'indique aucun fournisseur actif, retire entièrement la sous-section « 2.7. Assistant d'aide » : sans fournisseur, l'assistant n'est pas proposé et aucune question ne sort jamais du site. Si un connecteur IA est actif, conserve-la intégralement et sans l'édulcorer, et **traite le fournisseur d'IA comme un sous-traitant à part entière pour ce traitement** — il reçoit du texte écrit librement par un utilisateur, ce qui suffit à en faire un sous-traitant : il doit donc apparaître en section 4 et, s'il traite hors UE/EEE, en section 5.2, avec le mécanisme de garantie applicable (voir aussi les règles 21 et 26). Conserve en particulier, car une omission serait ici trompeuse : (a) que **seule la question écrite par la personne** est transmise, et rien d'autre — ni son nom, ni son adresse email, ni son identifiant ; (b) que **l'assistant ne consulte jamais la base de données**, sous aucune forme, agrégée ou anonymisée, et qu'il n'a aucun moyen technique d'y accéder — ne présente jamais cela comme une simple consigne donnée au modèle ; (c) que la conversation n'est conservée nulle part, disparaît à la déconnexion et après une heure d'inactivité, et que le journal du site n'en retient que des compteurs, jamais le texte des questions ; (d) que la recherche dans l'aide, elle, fonctionne entièrement sur l'appareil de la personne, sans aucun appel extérieur, et reste disponible sans fournisseur d'IA. N'invente aucune durée de conservation pour les conversations : il n'y en a pas, elles ne sont pas stockées.

Rappel final — instructions de l'administrateur à intégrer intégralement, point par point (voir règle 18) :
{$userPrompt}

Avant de répondre, vérifie mentalement que chaque point de ces instructions apparaît bien quelque part dans le document généré, et en particulier que la section 5.2 (transferts hors UE) et la section 4 (sous-traitants) reflètent bien tout service tiers hors UE mentionné ci-dessus (voir règle 21).

Réponds UNIQUEMENT avec le HTML généré, prêt à l'insertion directe dans la page.
PROMPT;
    }

    /**
     * Get info about the active AI provider for RGPD disclosure
     */
    /**
     * @return list<SubProcessorView>
     */
    private function collectSubProcessors(): array
    {
        $views = [];
        foreach ($this->subProcessorProviders as $provider) {
            foreach ($provider->getSubProcessors() as $view) {
                $views[] = $view;
            }
        }

        return $views;
    }

    /**
     * @param list<SubProcessorView> $views
     */
    private function aiProviderInfo(array $views): string
    {
        foreach ($views as $view) {
            if ($view->category === SubProcessorView::CATEGORY_AI) {
                return $view->name;
            }
        }

        return 'Non configuré';
    }

    /**
     * @param list<SubProcessorView> $views
     */
    private function aiModelsInfo(array $views): string
    {
        foreach ($views as $view) {
            if ($view->category === SubProcessorView::CATEGORY_AI) {
                return $view->details ?? 'Non configuré';
            }
        }

        return 'Non configuré';
    }

    /**
     * Get info about the active AI models for RGPD disclosure
     */

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
    /**
     * The gallery slot, rebuilt from the module's declared views. A
     * declared media-storage sub-processor is by contract an EXTERNAL
     * one, so "no view" from an enabled gallery means every byte stays
     * on the unit's own server — the exact sentence the prompt has
     * always carried for that case.
     *
     * @param list<SubProcessorView> $views
     */
    private function galleryStorageInfo(array $views): string
    {
        if (!in_array('gallery', $this->moduleManager->getEnabledModuleIds(), true)) {
            return 'Aucun (module galerie inactif)';
        }

        $names = [];
        foreach ($views as $view) {
            if ($view->category === SubProcessorView::CATEGORY_MEDIA_STORAGE) {
                $names[] = $view->name;
            }
        }

        if ($names === []) {
            return 'Stockage local (disque du serveur, pas de sous-traitant externe)';
        }

        return implode(' ET ', array_unique($names));
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
