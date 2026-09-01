# Chantier — Recherche dans l'aide et assistant conversationnel

Document de chantier. Il complète, sans les répéter, `ARCHITECTURE.md`
§8.64 (le moteur d'aide existant), `design.md` §7.11 (la charte
rédactionnelle), `SECURITY.md`, `AGENTS.md` et
`docs/module-development.md`. **Lis-les avant de commencer** : ce
document ne restate rien de ce qu'ils contiennent déjà.

Dix itérations, séquentielles, chacune sur sa branche, mergée en `--no-ff`
dans `main` dès que la CI est verte (auto-merge activé sur le dépôt). Ne
pose pas de question sauf changement de design ou ambiguïté fonctionnelle
réelle.

---

## 1. Le besoin

Le site est devenu vaste et les gens s'y perdent. Le corpus d'aide existe
déjà : 120 sujets Markdown, front matter + corps, agrégés par
`Core\Help\HelpRegistry`, filtrés par rôle par `Core\Help\HelpService`.
Ce qui manque, dans cet ordre de priorité :

1. **Une recherche instantanée**, locale, qui marche toujours — sans IA,
   sans réseau, quel que soit le rôle.
2. **Un corpus enrichi et vérifié**, pour que cette recherche trouve.
3. **Un assistant conversationnel**, en dernier recours, quand le
   connecteur IA est actif.

---

## 2. Décisions verrouillées

Elles ont été arbitrées. Ne les rouvre pas ; si l'implémentation les rend
impossibles, arrête-toi et signale-le plutôt que de dévier.

| # | Décision |
|---|---|
| D1 | **Documentation seule.** L'assistant ne lit jamais la base de données. Aucune donnée de l'unité, même agrégée, même anonymisée, n'entre dans un prompt. Pas de tool-calling, pas de requête SQL, jamais. |
| D2 | **La recherche locale ne dépend pas de l'IA.** Elle fonctionne à l'identique connecteur actif ou non, hors ligne, dès `role_min: public`. L'assistant n'est proposé qu'*après* elle, jamais à sa place. |
| D3 | **Deux surfaces, un seul moteur** : un onglet dans le panneau d'aide contextuel et une page `/aide/assistant`. Même partial, même endpoint. |
| D4 | **Assistant : `role_min: chief`.** La recherche locale reste `public`. |
| D5 | **Conversation en session uniquement.** Aucune table de conversation, aucun ajout RGPD. Purge à la déconnexion, expiration après 60 min d'inactivité, 6 derniers échanges conservés. |
| D6 | **Code dans `Core\Help\Assistant`**, avec `Modules\LlmConnector\Api\LlmConnectorInterface` en dépendance **nullable** injectée par `public/index.php` — précédent exact : `Core\View\RgpdContentService` (§7.5). |
| D7 | **Aucun réglage d'activation.** L'assistant est disponible dès que le connecteur est opérationnel (`isTierAvailable()`), point. |
| D8 | **Deux appels LLM** : sélection en tier `CHEAP` sur le catalogue filtré au rôle, puis réponse en tier `CAPABLE` sur les corps retenus. |
| D9 | **Nouveau champ de front matter `question`**, répétable, 2 à 4 par sujet. Une seule source ; il alimente la recherche locale *et* le catalogue de sélection IA. |
| D10 | **Quota par compte en table**, avec tâche de purge, sur le modèle de `human_check_rate_limits`. Plus un cache de réponses. |

---

## 3. Anti-patterns — ne fais jamais ça

- **Ne construis jamais le catalogue depuis `HelpRegistry::all()`.**
  Toujours `HelpService::listForRole()`. Le filtrage par rôle n'est pas
  une consigne donnée au modèle, c'est une liste qu'il ne reçoit pas.
- **Ne rends jamais une réponse du modèle en `|raw`.** Elle passe par
  `Core\View\MarkdownRenderer` avec `HelpController::RENDER_OPTIONS`, qui
  échappe le HTML. Une sortie de LLM est du contenu non fiable.
- **Ne fais jamais confiance à un id de sujet renvoyé par le modèle.**
  Chaque id est revalidé par `HelpService::findById($id, $role)` avant
  d'être affiché ou d'ouvrir un corps. Un id inventé disparaît
  silencieusement.
- **Ne journalise jamais le texte d'une question.** C'est du texte libre
  saisi par un humain : il peut contenir un nom, une adresse, un montant.
  Le journal ne porte que des compteurs (`SECURITY.md` §11).
- **N'ajoute pas de dépendance**, ni Composer ni JavaScript vendorée. La
  recherche locale est ~80 lignes de JS sans dépendance ; MiniSearch et
  consorts ont été évalués et écartés (le gain — BM25, racinisation,
  tolérance aux fautes — ne justifie pas 10 à 30 Ko servis à chaque visite
  et une entrée dans le tableau de `ARCHITECTURE.md` §1).
- **Ne duplique pas la charte rédactionnelle.** `design.md` §7.11 est la
  source de vérité ; le §6 ci-dessous ne fait que la compléter sur la
  *révision*.
- **N'ajoute pas de cookie.** La conversation vit dans la session PHP
  existante ; `Core\Cookie\CookieRegistry` n'a rien à déclarer de neuf.

---

## 4. Pièges concrets de ce dépôt

Vérifiés dans le code, ils ont chacun déjà causé un vrai bug ou en
causeraient un :

1. **`Core\Http\Router::resolve()` retient la première route qui
   matche**, dans l'ordre d'enregistrement. `/aide/assistant` doit être
   enregistrée **avant** `/aide/{topic}` dans `public/index.php`, sinon
   elle est absorbée. Réserve aussi l'id `assistant` dans
   `HelpFrontMatterParser` : un sujet nommé ainsi deviendrait
   inatteignable.
2. **`base.html.twig` n'inclut le panneau que si `route_help` est non
   vide.** Avec une recherche et un assistant toujours disponibles, cette
   condition saute — mais le bouton d'aide
   (`partials/help_button.html.twig`) doit alors ouvrir le panneau sur
   toutes les pages, pas seulement celles couvertes par un sujet.
3. **`HelpFrontMatterParser` rejette toute clé inconnue.** Ajouter
   `question` à ses clés facultatives est donc obligatoire *avant*
   d'enrichir le moindre fichier, sinon `loadErrors()` se remplit et
   `/aide` se vide.
4. **`Core\Http\Router` n'expose pas sa table de routes.** Le lien
   « aller sur la page » en a besoin en production (pas seulement en
   test). Ajoute un accesseur étroit et documenté, pas un getter générique.
   `HelpInvariantsTest` parse `public/index.php` par regex ; ne t'appuie
   pas sur ce mécanisme en production.
5. **Un handler de tâche planifiée doit être enregistré dans
   `Core\Scheduler\CoreTaskHandlers` ET atteignable depuis les deux points
   d'entrée**, `public/index.php` et `public/cron.php`. `ARCHITECTURE.md`
   §8.17/§8.20 documentent deux bugs de production nés d'un enregistrement
   dans un seul des deux.
6. **`schema/core.sql` auto-migre à chaque requête**, aucun bump de
   version n'est nécessaire — la règle de bump de `AGENTS.md` ne vise que
   les `schema.sql` de modules.
7. **`MarkdownRenderer` ne connaît ni tableau, ni bloc de code, ni liste
   imbriquée, ni lien interne.** Le prompt de réponse doit l'interdire
   explicitement au modèle, sinon la réponse s'affiche en Markdown brut.
8. **`/aide/assistant` ne doit PAS entrer dans
   `Core\Offline\OfflineWhitelist`** : elle exige le réseau. `/aide` et
   `/aide/` y sont déjà et doivent y rester — la recherche locale est
   précisément ce qui doit survivre hors ligne.
9. **Les entrées `paths` de type `child` se terminent par un `/`**, et
   `/` (la racine) est un chemin exact parfaitement valide. Une règle
   naïve « ignorer tout ce qui finit par `/` » exclut la page d'accueil à
   tort.

---

## 5. Itérations

### IT-01 — Le champ `question` et le lien vers la page documentée

**Livrer.**

- `Core\Help\HelpFrontMatterParser` accepte une clé **`question`
  répétable** (une par ligne, 2 à 4 par sujet). Le séparateur virgule de
  `paths`/`related` est délibérément écarté : une question en contient.
  Les valeurs s'accumulent dans `HelpTopic::$questions` (`string[]`,
  défaut `[]`).
- `Core\Help\HelpService::search()` cherche aussi dans `questions`, avec
  la même normalisation sans accents que l'existant.
- **Lien vers la page documentée.** Un nouveau `HelpTopic::pageLink()`
  (ou un service dédié si c'est plus propre) résout le premier `paths`
  exact et navigable en `{path, label}` via un accesseur étroit ajouté à
  `Core\Http\Router`. Le lien n'est rendu **que si** le visiteur franchit
  le `role_min` de la route cible — qui n'est pas nécessairement celui du
  sujet. Sur les 120 sujets actuels, 86 obtiennent un lien, 34 non
  (1 sans `paths`, 31 n'ayant que des motifs `*`) : c'est normal, pas un
  bug à contourner.
- Le lien s'affiche sur `/aide/{id}` et dans le panneau contextuel — sauf
  quand on est déjà sur la page en question.
- `HelpInvariantsTest` : format des questions (se termine par `?`,
  ≤ 80 caractères, 2 à 4 par sujet **quand il y en a**) et **unicité de
  chaque question sur tout le corpus**. La règle « tout sujet doit en
  avoir » n'est PAS encore active — elle arrive en IT-07.

**Fait quand** : le parseur et le lien sont testés, `loadErrors()` reste
vide, aucun sujet n'est encore enrichi, la suite passe.

---

### IT-02 — Recherche instantanée, locale et hors ligne

**Livrer.**

- Un index sérialisé dans la page, `<script type="application/json">`,
  construit par `HelpService::listForRole()` — précédent exact :
  `#offline-config-data` dans `base.html.twig`. Champs : `id`, `title`,
  `summary`, `category`, `questions`, `link`. Poids réel : 8 à 16 Ko
  selon le rôle. Pas de nonce CSP nécessaire, le bloc ne s'exécute pas.
- `public/assets/js/help-search.js` : normalisation (`NFD`, retrait des
  diacritiques), découpage, retrait d'une liste courte de mots vides
  français, désuffixage minimal appliqué **symétriquement** à l'index et à
  la requête, pondération `title ×5 / questions ×4 / summary ×2 /
  category ×1`, bonus de préfixe, seuil de couverture, **0 à 5
  résultats**. Aucune dépendance.
- Le champ de recherche apparaît en haut du panneau d'aide **et** sur
  `/aide`. `HelpService::search()` reste le repli sans JS de `?q=` :
  ne le supprime pas, ne le réécris pas.
- Chaque résultat affiche titre, résumé, badge de catégorie et, quand il
  existe, le lien vers la page documentée (IT-01).
- État vide rédigé : ce qui n'a pas marché et quoi faire ensuite, pas une
  humeur.
- Tests Vitest sur le scoring (un cas par règle : accent, pluriel,
  préfixe, couverture insuffisante, mot vide seul). `UxConventionsTest`
  pour les 44 px. Ajout du script à l'app shell de `sw.js`
  (`AppShellCoverageTest` l'exige).

**Fait quand** : la recherche fonctionne hors ligne sur `/aide` en cache,
sans requête réseau, et sans JS via `?q=`.

---

### IT-03 à IT-06 — Enrichissement et audit du corpus

Quatre itérations, une par tranche, **même méthode** (§6 ci-dessous).
Découpage par catégorie, pour que chaque PR reste relisible :

| Itération | Périmètre | Sujets |
|---|---|---|
| IT-03 | Premiers pas + Espace membres | 33 |
| IT-04 | Espace animateurs | 34 |
| IT-05 | Espace chefs d'U | 28 |
| IT-06 | Configuration | 25 |

**Livrer, par itération** : 2 à 4 `question:` sur chaque sujet de la
tranche, les corrections de contenu que l'audit révèle, et un compte rendu
dans `docs/chantiers/aide-recherche-assistant.md` (même format que
`docs/chantiers/aide-contextuelle.md`) listant ce qui a été corrigé et
pourquoi.

**Fait quand** : tous les sujets de la tranche portent leurs questions,
aucune n'est dupliquée dans le corpus entier, et les écarts constatés
entre le sujet et l'écran réel sont soit corrigés, soit consignés avec
leur raison.

---

### IT-07 — Verrouillage des invariants du corpus

**Livrer.**

- `HelpInvariantsTest` : `question` devient **obligatoire**, 2 à 4 par
  sujet, sur tout le corpus.
- **Test de dérive des libellés** : extraire les libellés d'interface
  cités dans les corps (entre guillemets français ou en gras) et vérifier
  qu'ils apparaissent dans les templates Twig du module concerné. Un
  libellé introuvable échoue le test. Prévois une liste d'exceptions
  explicite et courte (`ALLOWLIST`, même forme de cliquet que
  `UxConventionsTest::INLINE_TOUCH_PATCH_ALLOWLIST`) pour les cas
  légitimes — libellé venant d'une donnée, d'un module désactivé, ou
  construit dynamiquement.
- `docs/module-development.md` et `AGENTS.md` : la checklist de création
  de module mentionne le champ `question`.

**Fait quand** : la CI échoue si un nouveau sujet arrive sans questions ou
cite un libellé qui n'existe pas.

---

### IT-08 — Le moteur de l'assistant

**Livrer** — `Core\Help\Assistant\` :

- `AssistantCatalog` : construit le catalogue textuel depuis
  `HelpService::listForRole()`, une ligne par sujet
  (`id | titre | résumé | questions`).
- `AssistantService` : l'enchaînement complet.
  1. **Sélection**, tier `CHEAP`, `responseSchema` pour forcer
     `{"ids": [...]}`. Le prompt porte le catalogue, la question, les 6
     derniers échanges, **et l'ancrage** : le chemin de la page courante
     et les ids de `route_help`.
  2. Revalidation de chaque id par `HelpService::findById()`.
  3. **Réponse**, tier `CAPABLE`, sur les corps des sujets retenus.
     Système en français : répondre uniquement à partir des sujets
     fournis, ne jamais inventer un libellé de bouton ou une étape, dire
     franchement quand ça ne suffit pas, ton et lexique de `design.md`
     §7.11, cinq phrases maximum, et **pas de tableau, pas de bloc de
     code, pas de lien** (le renderer ne les connaît pas).
  4. Retour : le texte, les ids réellement consultés, un indicateur
     « rien trouvé ».
- `LlmRequest::$maxTokens` borné (~500 côté réponse) et `truncated`
  traité comme un échec, pas comme une réponse.
- `AssistantException` pour les cas non-LLM ; les `LlmException`
  implémentent déjà `UserFacingException`, leur message est affichable
  tel quel.
- **Deux tables dans `schema/core.sql`** :
  `help_assistant_rate_limits` (`user_account_id`, `created_at`, index de
  lecture) et `help_assistant_cache` (empreinte de
  `question normalisée + rôle + version applicative`, réponse, ids,
  `created_at`). La version applicative dans la clé rend l'invalidation
  gratuite : le corpus ne bouge qu'à une release.
- `Task\PurgeHelpAssistantHandler`, auto-replanifié quotidiennement —
  calqué sur `PurgeHumanCheckRateLimitsHandler` — enregistré dans
  `CoreTaskHandlers` et atteignable depuis les deux points d'entrée.
- Journal : `help_assistant_query` (`info`) avec ids sélectionnés,
  tokens, cache hit/miss, **jamais le texte de la question**.

**Fait quand** : tests unitaires avec un faux `LlmConnectorInterface`
couvrant le connecteur absent (`null`), le tier indisponible, un id
inventé par le modèle, le cas « rien trouvé », le quota dépassé, et un
hit de cache. Aucune route, aucune UI à ce stade.

---

### IT-09 — Les surfaces

**Livrer.**

- `Core\Help\Assistant\AssistantSession` — accès `$_SESSION` encapsulé,
  précédent `Core\ScoutYear\ScoutYearSession`. Aucun Service ne touche
  `$_SESSION` directement. Purge à la déconnexion, expiration 60 min, 6
  échanges.
- `POST /api/aide/assistant`, `role_min: chief`, CSRF, réponse JSON.
  Quota dépassé → 429 avec un message en français. Connecteur absent →
  la route répond proprement, elle n'est pas 404.
- `GET /aide/assistant`, `role_min: chief`, **enregistrée avant**
  `/aide/{topic}`, breadcrumb avec `/aide` en ancêtre.
- `partials/help_assistant.html.twig`, partagé par le panneau et la page.
  `public/assets/js/help-assistant.js`.
- Séquence d'affichage : « Je cherche dans l'aide… », puis les titres
  retenus dès le retour de la sélection, puis la réponse. Pas de spinner
  muet.
- La réponse passe par `MarkdownRenderer` avec `RENDER_OPTIONS`. Les
  sujets consultés s'affichent en liens vers `/aide/{id}`.
- Le bouton « Demander à l'assistant » n'apparaît **que sous les
  résultats** de la recherche locale, et seulement si connecteur actif et
  rôle suffisant. La question déjà tapée est reprise telle quelle.
- États dégradés rédigés : connecteur inactif, rôle insuffisant, hors
  ligne, quota atteint.
- Tests d'intégration : frontière RBAC (`chief` passe, `intendant` non),
  CSRF, quota, connecteur absent. Vitest sur le JS.

**Fait quand** : l'assistant fonctionne depuis les deux surfaces avec la
même session, et couper le connecteur ne casse ni `/aide` ni la recherche.

---

### IT-10 — Documentation et couverture

**Livrer.**

- `ARCHITECTURE.md` : nouvelle section §8.70 « Recherche dans l'aide et
  assistant », dans le style des sections voisines — le pourquoi et les
  décisions, pas le détail d'implémentation.
- `design.md` §7.11 : le champ `question` et ses règles, le lien vers la
  page documentée, la place de la recherche et de l'assistant, **et les
  règles de révision du §6 ci-dessous**, qui deviennent permanentes.
- `specifications.md` §4.6 : les deux nouvelles pages.
- `AGENTS.md` : ajouter au tableau des interdits « ne jamais injecter de
  données de l'unité dans un prompt d'assistant d'aide ».
- `SECURITY.md` : une ligne sur l'endpoint de l'assistant (quota, CSRF,
  pas de donnée personnelle sortante, sortie non fiable rendue échappée).
- **Vérifier la page RGPD** : le connecteur IA est déjà déclaré comme
  sous-traitant ; confirme que le texte couvre ce nouvel usage et
  complète-le sinon (`AGENTS.md`, section « RGPD page maintenance »).
- Deux nouveaux sujets d'aide : `recherche-dans-l-aide` (`public`) et
  `assistant` (`chief`), avec leurs questions.
- `HelpMenuCoverageTest` doit couvrir `/aide/assistant`.

**Fait quand** : la CI est verte, `loadErrors()` vide, et un lecteur de
`ARCHITECTURE.md` comprend pourquoi la recherche est locale et pourquoi
l'assistant ne lit pas la base.

---

## 6. Comment réviser et enrichir un sujet (IT-03 à IT-06)

`design.md` §7.11 dit comment **écrire**. Ceci dit comment **réviser**.
Ces règles rejoindront §7.11 en IT-10.

**Avant de toucher au texte**

- Ouvre les vues Twig et le contrôleur de la page couverte. Le sujet se
  révise en regardant l'écran réel, jamais de mémoire.
- Vérifie chaque libellé cité dans le corps. Un libellé absent des
  templates est une dérive à corriger — pas une formulation à conserver.
- Vérifie que le `role_min` du sujet correspond au plancher réel de la
  route qu'il couvre.

**Écrire les questions d'abord**

- 2 à 4 questions, formulées **comme un animateur les taperait**, pas
  comme un sommaire. « Comment prévenir tous les parents d'une section ? »,
  pas « Envoi d'e-mails groupés ».
- Elles doivent contenir le vocabulaire réel des gens, y compris quand il
  diffère du titre : c'est tout l'intérêt du champ. Un sujet
  « Publipostage » gagne « Comment envoyer un mail personnalisé depuis un
  fichier Excel ? ».
- **Si tu n'arrives pas à en formuler deux vraies, le sujet décrit un
  écran au lieu de documenter une tâche : réécris-le, ne l'enrichis pas.**
  C'est le diagnostic, pas une formalité.
- Jamais deux fois la même question dans le corpus. Deux sujets qui
  revendiquent la même question sont une ambiguïté réelle, qui perdrait
  autant la recherche locale que le modèle.

**Réviser le corps**

- Un sujet documente une **tâche**, pas un écran. On n'énumère pas les
  champs d'un formulaire ; on explique ce qu'on cherche à obtenir et ce
  qui peut mal tourner.
- Première phrase : à quoi sert cette page, en une ligne, pour quelqu'un
  qui vient d'arriver dessus. Puis le déroulé. L'avertissement en dernier.
- Ne documente pas l'évident. Un bouton « Enregistrer » ne mérite pas de
  phrase. Écris ce qu'on ne devine pas : préconditions, effets de bord,
  ordre des opérations.
- Au-delà de ~400 mots, ce sont deux sujets : scinde et relie par
  `related`. Ne comprime jamais.
- **Corrige ce qui est faux, complète ce qui manque, ne réécris pas ce
  qui est correct.** Une PR d'enrichissement qui reformule tout devient
  irrelisable.

---

## 7. Hors périmètre

- Toute lecture de données de l'unité par l'assistant (D1).
- Un historique de conversation persistant, une table, une page
  d'administration des conversations (D5).
- Le streaming / SSE : aucun précédent dans ce dépôt, et la séquence
  d'affichage d'IT-09 couvre le besoin de latence perçue.
- Le *prompt caching* fournisseur : cache de préfixe à 5 minutes, à
  respecter octet pour octet, propre à un seul fournisseur, et il
  faudrait faire entrer un concept fournisseur dans `LlmRequest` que
  `LlmTier` interdit délibérément. Évalué, écarté.
- Une bibliothèque de recherche côté client (MiniSearch, Lunr, Fuse) :
  évaluée, écartée — voir §3.
- Les captures d'écran dans les sujets d'aide (`design.md` §7.11).
- Une traduction du corpus.
