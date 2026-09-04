# Chantier — Performances à grande échelle (membres × années)

Document de chantier. Il complète `ARCHITECTURE.md` (§7 modules, §8.x
composition root), `SECURITY.md`, `AGENTS.md` et
`docs/chantiers/reference-dataset.md`. Il part d'une campagne de mesures
menée le 3 septembre 2026 sur la branche
`claude/performance-multi-year-members-k77t1y`, dont l'outillage est commité
sous `scripts/perf/` pour que chaque lot puisse être re-mesuré. Le §5 est
le journal : ce qui a été fait, re-mesuré, et ce qui a été écarté.

Le symptôme rapporté : le site paraît lent quand l'unité a beaucoup de
membres sur plusieurs années, plus encore en mode application installée
(PWA), et un premier appui sur une entrée de menu semble parfois ne rien
faire.

---

## 1. Méthode

Deux instances jetables provisionnées par `scripts/e2e-support.php
provision`, tous les modules activés, servies par `php -S` avec OPcache
(vérifié : sans OPcache la même page coûte 7× plus), MariaDB 10.11 locale.
Peuplées par `scripts/perf/seed-members.php`, qui écrit les mêmes lignes
qu'un import Desk (member_years chiffrés, adresses, fonctions, périodes de
section) :

| Instance | Années | Membres distincts | Lignes membre-année | Actifs / an |
|---|---|---|---|---|
| Échelle 1 | 8 | 633 | 2 991 | 330 – 430 |
| Échelle 2,5 | 12 | 1 955 | 11 771 | 860 – 1 170 |

Mesures : `scripts/perf/bench-pages.php` (toutes les pages GET, médiane de
3 requêtes, nombre d'instructions SQL), `scripts/perf/request-timeline.php`
(chronologie `?debug=1` de `Core\Debug\RequestTimeline`, affinée par des
marques ajoutées dans la copie d'`index.php` de l'instance), journal général
MariaDB pour lire les requêtes, et `scripts/perf/pwa-request-storm.cjs`
(Playwright) pour comparer onglet et application installée.

Le jeu de données de référence (`tests/fixtures/reference-dataset/`) reste
la fixture réaliste ; le seeder de ce chantier n'apporte que du volume.

## 2. Constats

### 2.1 Un plancher payé par toutes les pages

Avant qu'un contrôleur ne s'exécute, chaque requête coûte **~47 ms et ~60
requêtes SQL** à l'échelle 1, **~75 à 95 ms** à l'échelle 2,5. Le manifeste
PWA (400 octets de JSON) coûte le même prix que la page d'accueil. Sur une
page ordinaire, ce plancher représente 70 à 80 % du temps serveur.

Répartition du plancher (manifeste, médiane de 5 requêtes, ms) :

| Segment d'`index.php` | Échelle 1 | Échelle 2,5 | Cause |
|---|---|---|---|
| Bloc `registration` (L5075 → `rental`) | 8,1 | **32,7** | `ReenrollmentMenuHookService` → `ReenrollmentFormService::cardsFor()` → `PassageService::getAnimeMemberYears()` : **tous les animés de l'année déchiffrés à chaque requête** pour un badge de menu, dès que `currentCampaignKey()` est non nul, c.-à-d. de mars à décembre avec les réglages par défaut |
| Services cœur (L955 → HelpRegistry) | 8,2 | 9,0 | résolution du rôle, comptes, réglages, notifications |
| ModuleManager → globals Twig | 6,7 | 7,2 | manifestes, menu, hooks |
| Index de recherche d'aide | 2,6 | 3,3 | `HelpSearchIndex::forRole()` recalculé et sérialisé dans chaque page (52 Ko, le commentaire dit « ~15 Ko ») |
| Vérification de migration | 2,6 | 3,2 | hachage des 23 fichiers `schema.sql` à chaque requête |
| 25 × `collapsePending()` | — | — | `SchedulerService::seed()` appelle `collapse()` dès qu'une chaîne est vivante : 25 SELECT par requête malgré `cachePendingRearms` |
| Chargements anticipés de modules | — | — | `rental_assets` (2×), `gallery_storage_locations`, `llm_providers`, `COUNT camp_places`, `news_forms`, `update_history` lus à chaque requête, quelle que soit la route |

### 2.2 Pages dont le coût croît avec les membres

Médiane en ms (échelle 1 → 2,5), instructions SQL, taille HTML :

| Page | Éch. 1 | Éch. 2,5 | SQL | Remarque |
|---|---|---|---|---|
| `/chefs/membres/export` | 1 121 | **2 792** | 71 | PhpSpreadsheet cellule par cellule ; le déchiffrement n'explique rien (2,5 µs/champ mesuré) |
| `/trombinoscope/pdf` | 1 229 | **2 194** | 172 → 259 | dompdf + 1 requête photo par membre |
| `/mass-mail/new` | 143 | 340 | 103 | |
| `/passage` | 133 | 314 | 93 | HTML 581 Ko → **1,07 Mo** |
| `/previsions` | 118 | 282 | 85 | précaché par la PWA |
| `/chefs/membres` | 118 | 270 | 70 | HTML 860 Ko → **1,84 Mo**, 1,6 Ko d'indentation par ligne |
| `/trombinoscope` | 118 | 218 | 173 → 260 | `getSectionStaff()` par section + `member_photo()` par membre (90 requêtes `member_photos`) ; précaché |
| `/chefs/staffs` | 184 | 205 | 95 | `syncSectionReferentBadges()` et `ensureSection()` sur chaque GET ; précaché |
| `/admin/points-attention` | 114 | 205 | 83 | `FeesAttentionProvider` rejoue tout le rapport de justesse des tarifs |
| `/admin/scout-year` | 92 | 204 | 81 | `ScoutYearPreparationService` → tous les animés |
| `/admin/fees/tarifs` | 104 | 193 | 75 | |
| `/sections` (public) | 110 | 172 | 151 | `getResponsable()` par section hydrate tout le staff |
| `/admin/members?q=` | 85 | 171 | 65 | filtre en mémoire après déchiffrement de toute l'année |
| `/api/offline/manifest` | 86 | 122 | 129 → 216 | appelé par la PWA à chaque page |
| `/` , `/contact` | 61 – 68 | 89 – 92 | 69 – 73 | le plancher |

### 2.3 Poids du HTML

Une page quelconque pèse **~207 Ko** (25 Ko gzip, ~1 000 balises) dont : mégamenu
de bureau 59 Ko (rendu aussi sur mobile, `d-none d-lg-block`), menu
offcanvas 76 Ko, index de recherche d'aide 52 Ko — soit 187 Ko sans rapport
avec le contenu. Le contenu d'accueil tient dans 400 octets de `<main>`.

### 2.4 Mode application installée

Mesuré avec Playwright, même serveur (6 workers), même compte, consentement
fonctionnel accordé :

| Mode | Requêtes par navigation | dont HTML / API | Pages rendues côté serveur |
|---|---|---|---|
| Onglet navigateur | 60 – 63 | 4 | la page, `unread-count` |
| Application installée | 76 – 93 | **20 – 34** | + `/api/offline/manifest` + les 14 pages de la liste hors ligne |

Causes, toutes dans le code :

1. `public/assets/js/offline-prefetch.js` n'a **aucune garde « une fois par
   lancement »** : son en-tête promet un préchauffage « à chaque lancement de
   l'application installée », le code le fait à **chaque page**. Il lance en
   parallèle et sans limite le manifeste, toutes les pages blanchies et
   toutes les images. Les pages blanchies sont précisément les plus lourdes
   (`/chefs/staffs`, `/previsions`, `/trombinoscope`, `/chiefs/stats`).
2. Un GET conditionnel ne coûte rien de moins qu'un GET : `FrontController::
   applyEtagIfEligible()` calcule l'ETag sur `md5($response->getBody())`,
   donc la page est entièrement rendue avant le 304.
3. `public/sw.js` : `handleNavigate()` attend la lecture de la configuration
   dans Cache Storage **avant** de lancer `fetch()` ; `navigationPreload`
   n'est pas activé. Chaque navigation paie le réveil du service worker plus
   un aller-retour disque, plus souvent dans une app que l'OS gèle.
4. En mode `standalone`, aucune barre d'adresse ni indicateur de chargement,
   et les liens de l'offcanvas ne portent pas `data-bs-dismiss="offcanvas"`
   (`partials/nav.html.twig` : seul le bouton de fermeture le porte). L'écran
   ne change donc **en rien** pendant la navigation : d'où le second appui.
5. `offline-nav.js` intercepte le clic en phase de capture et fait
   `preventDefault()` dès que `navigator.onLine` est faux — valeur souvent
   périmée au réveil d'une PWA — puis `showDialog()` abandonne en silence si
   Bootstrap n'est pas prêt : un clic annulé sans aucun retour.
6. Aucun nettoyage `pageshow` : un retour arrière (bfcache) restaure la page
   avec l'offcanvas et son backdrop ouverts ; le premier appui ferme le
   backdrop, le second atteint le lien.

`usage_stats` n'est pas en cause (enregistrement serveur après `send()`), ni
un flux de mise à jour du service worker (il n'en existe pas).

## 3. Plan

Cinq lots, séquentiels par priorité : chaque lot re-mesuré avec l'outillage
de `scripts/perf/` sur les deux échelles avant d'ouvrir le suivant. Aucun ne
change un comportement fonctionnel ; les lots 1 et 2 profitent à toutes les
pages et à tous les utilisateurs, les suivants aux pages listées.

### Lot 0 — Rendre le coût visible (½ jour)

- Décorer le PDO de `Core\Database\Connection` pour compter les instructions
  et cumuler leur durée, et l'inscrire dans `RequestTimeline` (`services_ready`
  porte alors `queries` et `sql_ms`). Sans cela, aucun des N+1 ci-dessus n'est
  lisible autrement qu'en lisant le code.
- Marques `RequestTimeline::mark()` permanentes à l'entrée de chaque bloc
  `if ($isEnabled('…'))` d'`index.php`.
- Budget écrit dans ce document : une page vide (manifeste) doit rester sous
  **15 requêtes et 15 ms hors contrôleur** ; une page de liste sous 30
  requêtes quelle que soit la taille de l'unité.

### Lot 1 — Le plancher (2 à 3 jours) — gain attendu : −60 % sur toutes les pages

1. **Hook de menu réinscription** (`ReenrollmentMenuHookService`,
   `ReenrollmentFormService::cardsFor()`) : remplacer « tous les animés puis
   filtre en mémoire » par une requête restreinte aux membres liés au compte
   (`WHERE my.member_id IN (…)`), et ne rien calculer hors campagne ouverte
   ou clôturée depuis moins de N jours. Résultat mémorisé en session pour la
   durée de la campagne. C'est le seul poste du plancher qui croît avec les
   membres : 8 → 33 ms.
2. **Scheduler** : `SchedulerService::seed()` n'appelle `collapse()` que si
   le snapshot `findLiveKeys()` compte au moins deux lignes `pending` pour la
   triple (il faut faire porter un compteur au snapshot). 25 SELECT → 0.
3. **Chargements anticipés** des modules `rental`, `gallery`, `llm_connector`,
   `camps`, `news`, maintenance : passer en fabrique paresseuse (closure
   évaluée par le consommateur) ou conditionner à la route du module ;
   `countPendingGeocoding()` ne doit tourner que dans la tâche planifiée.
4. **Vérification de migration** : mettre en cache (`SerializedFileCache`)
   le hachage des `schema.sql` indexé par leurs `mtime`, au lieu de relire
   et hacher 23 fichiers par requête.
5. **Index de recherche d'aide** : sortir le JSON de la page vers
   `GET /api/aide/index` (filtré par rôle, `ETag` + `Cache-Control:
   private, max-age`), chargé par `help-search.js` au premier focus du champ.
   −52 Ko et −3 ms par page.
6. **Menu** : une seule source de vérité HTML — rendre les entrées une fois
   dans un `<template>` et laisser `nav.js` / l'offcanvas les cloner, ou à
   défaut appliquer le contrôle d'espaces Twig (`{%- -%}`) aux deux partiels.
   −60 à −100 Ko par page, moins de DOM à construire sur mobile.

### Lot 2 — Application installée (2 jours) — c'est le lot du « double clic »

1. `offline-prefetch.js` : une seule exécution par lancement (drapeau
   `sessionStorage`, et uniquement quand `performance.getEntriesByType(
   'navigation')[0].type === 'navigate'` depuis `start_url`), lancée après
   `load` via `requestIdleCallback`, **2 requêtes simultanées maximum**, et
   jamais quand `navigator.connection.saveData` est vrai.
2. Liste hors ligne : retirer par défaut `/previsions`, `/chiefs/stats`,
   `/trombinoscope` et `/chefs/staffs` du préchargement automatique (ils
   restent cachés à la consultation) ; les modules déclarent `prefetch: false`
   dans leur `offline_pages`.
3. `sw.js` : activer `navigationPreload` à l'`activate`, utiliser
   `event.preloadResponse`, et lire la configuration **en parallèle** du
   `fetch()` plutôt qu'avant.
4. ETag bon marché : mémoriser côté serveur, par (chemin, rôle, année,
   version), l'ETag du dernier rendu, invalidé par l'import Desk et par la
   sauvegarde d'un contenu éditable ; répondre 304 **avant** le contrôleur
   quand `If-None-Match` correspond.
5. Retour visuel immédiat : `data-bs-dismiss="offcanvas"` sur les liens du
   menu, une fine barre de progression (`app.css`, déclenchée sur `click`
   d'un lien interne et `pagehide`, retirée sur `pageshow`), et un
   gestionnaire `pageshow` qui ferme offcanvas, modales et backdrops
   restaurés par le bfcache.
6. `offline-nav.js` : ne jamais `preventDefault()` si `showDialog()` ne peut
   pas afficher ; traiter `navigator.onLine === false` comme un indice et le
   confirmer par un `fetch` `HEAD` très court avant de bloquer.
7. `file-viewer.js` : le `MutationObserver` ne rescanne que
   `mutation.addedNodes`, pas tout le document, à chaque ouverture du menu.

### Lot 3 — Pages proportionnelles aux membres (3 à 4 jours)

1. **Photos** : `TwigFactory` `member_photo()` / `person_avatar()` →
   résolution groupée `MemberPhotoService::primeFileIds(int[] $memberIds,
   $year)` appelée par les contrôleurs de liste ; 90 requêtes → 1.
2. **Staff par section** : `SectionService::getSectionStaffForSections(
   int[] …)` en une passe (le batch `hydrateMemberProfiles()` existe déjà),
   utilisé par `/trombinoscope`, `/sections`, le PDF, `/chefs/camps/nouveau`
   et le module `groups` (`inviteCandidates()`, `candidatePool()`).
   `syncSectionReferentBadges()` et `ensureSection()` sortent du GET
   `/chefs/staffs` (hook d'import + tâche).
3. **Listes** `/chefs/membres` et `/passage` : « Toutes les sections » n'est
   plus le défaut ; contrôle d'espaces Twig sur les lignes (−60 % de HTML) ;
   tri et filtre côté serveur ; pagination à 100 lignes quand « Toutes »
   est choisi explicitement.
4. **Export XLSX** : `MemberExportService` écrit par `fromArray()` ligne à
   ligne, `setPreCalculateFormulas(false)`, pas de style par cellule ;
   objectif < 500 ms pour 1 000 lignes (mesuré 2,8 s).
5. **PDF trombinoscope** : générer en tâche planifiée à l'import et sur
   changement de photo, servir le fichier (`FileAccessGuard`) ; le contenu
   ne change qu'à ces deux moments.
6. **Points d'attention et statistiques** : `FeesAttentionProvider`,
   `LeadershipAttentionProvider` et `member_stats` lisent un résultat
   calculé à l'import (table ou `SerializedFileCache` invalidé par
   `DeskImportListener`), jamais le rapport complet à la demande.
7. **Recherche membres** : `MemberSearchRepository` cherche d'abord par
   blind index (nom normalisé, comme l'e-mail et l'adresse le font déjà) et
   ne déchiffre que les candidats ; `searchAllYears()` plafonné et paginé.
8. `/mass-mail/new` : identifier les 103 requêtes (`buildListOptions`,
   résumé d'audience) et grouper.
9. **Index** dans `schema/core.sql` (additifs, `SchemaComparator` ne
   supprime rien) : `member_functions (section_id, is_main_function, id)` et
   `(member_year_id, is_main_function, id)` — la table la plus jointe du
   site n'a aucun index déclaré et chaque lecture trie sur
   `is_main_function DESC, id` ; `member_years (scout_year_id, is_active,
   leaving)`.

### Lot 4 — Garde-fous (1 jour)

- Un test PHPUnit `tests/Integration/QueryBudgetTest.php` qui, sur le jeu de
  données de référence, compte les requêtes des routes de liste via le
  compteur du lot 0 et échoue au-delà du budget ; un second qui vérifie que
  le plancher ne dépend pas du nombre de membres (mêmes requêtes avec 10 et
  1 000 membres).
- `npm run e2e` : un scénario qui, en `standalone` simulé, vérifie qu'une
  navigation ne déclenche pas plus de N requêtes de document.
- Ce document devient le journal du chantier : une section par lot, avec
  les deux tableaux re-mesurés.

## 4. Hors périmètre, à garder en tête

- Hébergement mutualisé : chaque requête SQL y coûte souvent 0,5 à 1 ms de
  latence contre 0,1 ici ; les 60 requêtes du plancher pèsent donc 30 à 60 ms
  de plus en production qu'ici, ce qui renforce la priorité du lot 1.
- `public/index.php` construit ~626 objets par requête. Une injection
  paresseuse générale n'est pas dans ce plan : la mesure montre que le coût
  est dans les requêtes et les calculs, pas dans les `new`.
- `/config/maintenance` (218 ms) ne dépend pas des membres (état du cron,
  historique, sauvegardes) et n'est pas traité ici.

---

## 5. Journal du chantier

Les cinq lots ont été implémentés les 3 et 4 septembre 2026 sur la même
branche, chacun re-mesuré avec `scripts/perf/` sur les deux instances
d'échelle avant le suivant, puis commité séparément. Les tableaux ci-dessous
reprennent ceux du §2 avec les chiffres finaux (médiane de 3 requêtes,
même serveur, même compte). Les écarts par rapport au plan du §3 sont
notés lot par lot, avec la raison.

### Lot 0 — Rendre le coût visible

Fait tel que prévu. `Core\Database\Connection` construit un
`Core\Database\InstrumentedPdo` (avec `InstrumentedStatement`), qui
alimente `Core\Database\QueryCounter` : nombre d'instructions et durée
cumulée par requête HTTP. Chaque entrée de `Core\Debug\RequestTimeline`
porte désormais `sql` et `sql_ms`, et `public/index.php` pose une marque
`module_<id>` à l'entrée de chaque bloc de module, de sorte que
`scripts/perf/request-timeline.php` attribue les requêtes SQL à un segment
sans copie modifiée d'`index.php`.

Le budget écrit au §3 (« une page vide sous 15 requêtes et 15 ms hors
contrôleur ») n'est **pas** atteint : le plancher final est de ~35
instructions et ~28 ms, voir lot 1. Les garde-fous du lot 4 vérifient donc
des **formes** (un coût qui ne dépend pas de la taille de l'unité) et non
ce chiffre.

### Lot 1 — Le plancher

| Mesure (manifeste PWA, hors contrôleur) | Avant | Après |
|---|---|---|
| Échelle 1 | 51 ms, 60 SQL | **29 ms, 35 SQL** |
| Échelle 2,5 | 75 – 95 ms, 60 SQL | **28 ms, 35 SQL** |

Le plancher ne dépend plus du nombre de membres. Détail :

1. **Hook de menu réinscription** : `PassageService::animeMemberIdsAmong()`
   ne lit que les membres liés au compte (`WHERE my.member_id IN (…)`) ;
   `ReenrollmentFormService::cardsFor()` et `mayAnswerFor()` l'utilisent.
   C'était le seul poste qui croissait avec les membres (8 → 33 ms) ; il
   est maintenant constant. La mémorisation en session prévue n'a pas été
   nécessaire : la requête restreinte coûte moins de 1 ms.
2. **Scheduler** : le snapshot de `SchedulerService` compte les lignes
   `pending` par triple ; `collapseIfDuplicated()` ne fait plus de SELECT
   quand il y en a moins de deux. 25 SELECT par requête → 0.
3. **Chargements anticipés des modules** : **non fait**. Mesurés après les
   deux points précédents, ils pèsent ensemble moins de 2 ms et 7
   requêtes ; le gain ne justifiait pas de rendre paresseuses des fabriques
   que plusieurs modules consomment.
4. **Vérification de migration** : `MigrationRunner` met en cache le
   hachage des fichiers `schema.sql` indexé par leurs `mtime` et taille
   (`storage/cache/schema_hash.cache`).
5. **Index de recherche d'aide** : mémorisé par rôle dans un fichier de
   cache (`HelpSearchIndex`, `HelpRegistry::cacheDirectory()`) au lieu
   d'être recalculé à chaque page. Il reste inclus dans la page (52 Ko),
   pas déplacé vers une route `/api/aide/index` : c'est un changement du
   panneau d'aide (`help-search.js`) qui sort du périmètre « aucun
   comportement fonctionnel ne change », et son coût CPU est celui qui
   était mesuré, pas ses octets.
6. **Menu** : un filtre Twig `compact_html` (`Core\View\CompactHtmlExtension`)
   retire l'indentation du partiel de navigation. Une page ordinaire
   passe de **207 Ko à 139 – 145 Ko**, sans toucher au HTML rendu par
   `nav.js`. Le filtre laisse `<pre>` et `<textarea>` intacts.

### Lot 2 — Application installée

Mesuré avec `scripts/perf/pwa-request-storm.cjs`, même serveur (6
workers), même compte, consentement fonctionnel accordé :

| Mode | Requêtes par navigation | dont HTML / API | Avant |
|---|---|---|---|
| Onglet navigateur | 62 – 65 | 4 | 60 – 63 / 4 |
| Application installée | **62 – 65** | **4** | 76 – 93 / 20 – 34 |

L'application installée coûte désormais au serveur exactement ce que
coûte un onglet. Détail :

1. `offline-prefetch.js` : une exécution par lancement (drapeau
   `sessionStorage`, rejoué après 24 h), après `load` et en temps
   d'inactivité, **2 requêtes simultanées**, jamais sous Data Saver.
2. Liste hors ligne : `prefetch: false` sur `/chefs/staffs` (cœur),
   `/trombinoscope`, `/chiefs/stats`, `/previsions` (modules) ; ces pages
   restent servies hors ligne après consultation. `ModuleManifest` valide
   le drapeau ; `docs/module-development.md` le documente.
3. `sw.js` : `navigationPreload` activé à l'`activate`, `event.preloadResponse`
   utilisé, lecture de la configuration en parallèle du `fetch()`.
4. **ETag bon marché : non fait.** Répondre 304 avant le contrôleur
   suppose de connaître toutes les écritures qui invalident une page ; une
   page de décision (passage, prévisions) servie périmée coûte plus cher
   qu'un rendu. Le point 1 a fait disparaître le besoin : il n'y a plus de
   GET conditionnels en rafale.
5. Retour visuel immédiat : `navigation-feedback.js` ferme l'offcanvas
   au clic sur un lien interne, affiche une barre de progression
   (`#navigation-progress`, `app.css`) et nettoie offcanvas, modales et
   backdrops restaurés par le bfcache sur `pageshow`.
6. `offline-nav.js` : ne bloque plus un clic quand le dialogue ne peut pas
   s'afficher, et confirme `navigator.onLine === false` par un `HEAD
   /api/version` de 1,5 s avant de bloquer.
7. `file-viewer.js` : l'observateur ne rescanne que les nœuds ajoutés et
   les attributs `href` modifiés.

### Lot 3 — Pages proportionnelles aux membres

| Page | Éch. 1 avant → après | Éch. 2,5 avant → après | SQL avant → après |
|---|---|---|---|
| `/chefs/membres/export` | 1 121 → 1 005 | 2 792 → 2 444 | 71 → 48 |
| `/trombinoscope/pdf` | 1 229 → **40** (cache) | 2 194 → **44** (cache) | 172 – 259 → 43 |
| `/mass-mail/new` | 143 → 94 | 340 → 156 | 103 → 79 |
| `/passage` | 133 → 90 | 314 → 165 | 93 → 69 |
| `/previsions` | 118 → 71 | 282 → 105 | 85 → 61 |
| `/chefs/membres` | 118 → 83 | 270 → 136 | 70 → 46 |
| `/trombinoscope` | 118 → **53** | 218 → **55** | 173 – 260 → 44 |
| `/chefs/staffs` | 184 → 162 | 205 → 161 | 95 → 58 |
| `/admin/points-attention` | 114 → 89 | 205 → 146 | 83 → 59 |
| `/admin/scout-year` | 92 → 62 | 204 → 79 | 81 → 57 |
| `/admin/fees/tarifs` | 104 → 79 | 193 → 125 | 75 → 51 |
| `/sections` (public) | 110 → **56** | 172 → **59** | 151 → 71 |
| `/admin/members?q=` | 85 → 55 | 171 → 89 | 65 → 40 |
| `/api/offline/manifest` | 86 → 41 | 122 → 42 | 129 – 216 → 58 |
| `/` , `/contact` | 61 – 68 → 48 – 51 | 89 – 92 → 48 – 50 | 69 – 73 → 49 – 51 |

Taille HTML : `/chefs/membres` 860 Ko → 394 Ko (1,84 Mo → 746 Ko),
`/passage` 581 Ko → 345 Ko (1,07 Mo → 575 Ko).

Détail et écarts :

1. **Photos** : `MemberPhotoService::primeFileIds()` résout en une requête
   les identifiants de fichier d'une page ; `PageController`,
   `TrombinoscopeController` et l'emboîteur PDF l'appellent. 90 requêtes → 1.
2. **Staff par section** : `TrombinoscopeService::getSectionStaffForSections()`,
   `SectionService::getStaffForSections()` / `getAnimesForSections()` et
   `SectionResponsableProvider::getResponsables()` lisent toutes les sections
   en une passe ; utilisés par `/trombinoscope`, `/sections`, le PDF,
   `/chefs/camps/nouveau` et le module `groups`. Les badges de référent
   sont lus par `BadgeRepository::findAllByReferentSection()` en une
   requête. **Non fait** : sortir `syncSectionReferentBadges()` et
   `ensureSection()` du GET `/chefs/staffs`. La page reste à ~160 ms, dont
   124 ms dans le contrôleur pour 23 instructions (la requête des sections
   passe trois fois, les réglages deux fois, la liste des documents de
   section rafraîchit son backend détecté) ; c'est le prochain poste à
   ouvrir, hors de ce chantier.
3. **Listes** `/chefs/membres` et `/passage` : `compact_html` sur les
   lignes (−55 % de HTML). **Non fait** : changer le défaut « Toutes les
   sections », trier et filtrer côté serveur, paginer — ce sont des
   changements d'interface que le plan excluait, et le coût serveur restant
   (83 ms / 136 ms) est celui du déchiffrement des lignes affichées.
4. **Export XLSX** : `MemberExportService` écrit par `fromArray()`,
   `SpreadsheetResponse` désactive le pré-calcul des formules. Le plancher
   de PhpSpreadsheet est mesuré à 1,6 s de cellules + 0,9 s d'écriture pour
   860 × 33 cellules ; l'objectif « < 500 ms pour 1 000 lignes » n'est pas
   atteignable sans changer de bibliothèque, ce qui n'est pas dans ce lot.
5. **PDF trombinoscope** : cache disque signé par le contenu
   (`TrombinoscopePdfService::cacheFile()`, signature des membres, photos et
   réglages), plutôt qu'une tâche planifiée : même effet pour le visiteur,
   aucun état à invalider, et le premier appel après un changement rend
   comme avant.
6. **Points d'attention et statistiques : non fait.** Un résultat calculé à
   l'import serait périmé dès qu'un tarif, une fonction ou une formation est
   modifié dans l'application, et ces pages servent à décider. Les gains du
   lot 1 et des index les ramènent à 89 / 146 ms.
7. **Recherche membres** : les noms étant chiffrés et la recherche étant
   une sous-chaîne, un blind index n'est pas applicable. À la place,
   `MemberSearchService::matchForYear()` cherche d'abord dans l'année
   chargée sans ses adresses, complète les résultats trouvés par
   `MemberSearchRepository::findAddressTexts()`, et ne recharge l'année
   avec toutes les adresses que si rien n'a été trouvé. 85 / 171 → 55 / 89 ms.
8. `/mass-mail/new` : `MassMailController` ne demande la projection de
   l'année suivante (tous les animés, déchiffrés) que si aucun import
   n'existe déjà pour cette année ; 103 → 79 requêtes.
9. **Index** ajoutés dans `schema/core.sql` : `member_functions
   (section_id, is_main_function, id)`, `(member_year_id, is_main_function,
   id)`, `member_years (scout_year_id, is_active, leaving)`.

### Lot 4 — Garde-fous

- `tests/Integration/QueryBudgetTest.php` (SQLite, `InstrumentedPdo`)
  vérifie trois formes : le mur du trombinoscope coûte le même nombre
  d'instructions avec 2 ou 14 sections (≤ 5) ; staff et animés de toutes les
  sections coûtent le même nombre d'instructions quelle que soit la taille
  de l'unité (≤ 10) ; amorcer les photos d'une page est une instruction et
  leur rendu zéro.
- `tests/e2e/specs/pwa-prefetch-once.spec.js` : en `standalone` simulé
  (`matchMedia` répondu par un script d'initialisation), le manifeste hors
  ligne est demandé une fois au premier chargement et **jamais** sur les
  trois pages suivantes du même lancement.
- Ce document est le journal.

Observation faite en lançant `npm run e2e` complet : les scénarios de la
galerie échouent ici parce que le premier passage du cron de l'instance
jetable exécute `auto_backup`, qui archive ~340 Mo à travers les liens
symboliques de l'instance en ~104 s et épuise les 120 s d'attente de
`tests/e2e/support/scheduler.js`. Rejoués seuls sur une instance dont la
sauvegarde a déjà tourné, ils passent en 22 s. Ce n'est pas lié au chantier.

### Ce qui reste

- `/chefs/staffs` : 124 ms de contrôleur pour 23 instructions, voir lot 3.2.
- Export XLSX : changer de bibliothèque (ou écrire le XLSX directement) si
  1 à 2,5 s par export devient gênant.
- `/config/maintenance` (218 ms), hors périmètre depuis le §4.
- Sur hébergement mutualisé, le plancher de 35 instructions pèse encore
  15 à 35 ms de latence réseau ; les chargements anticipés des modules
  (lot 1.3) deviendraient alors le poste suivant.

---

## 6. Suite envisagée : une fenêtre de mesure depuis la page Support

Idée retenue le 4 septembre 2026, pas encore implémentée. Le superadmin
ouvre, depuis Configuration > Support et après confirmation, une fenêtre
de mesure de quelques minutes ; pendant ce temps chaque requête journalise
sa chronologie comme le fait `?debug=1` ; il envoie ensuite un ticket dont
l'archive contient les mesures. Ce qui existe déjà couvre l'essentiel :
`RequestTimeline` écrit dans `event_log`, et `EventJournalCollector`
embarque les 48 dernières heures du journal dans l'archive.

À faire, dans cet ordre :

1. **Activation par une date de fin**, jamais par un drapeau : un réglage
   ou un fichier témoin `storage/cache/measure_until` dont la date
   d'expiration est comparée à chaque requête. L'arrêt est automatique,
   sans tâche planifiée, et un mode oublié ne peut pas rester actif.
2. **Toutes les requêtes de tous les comptes** pendant la fenêtre, avec un
   plafond (quelques centaines de requêtes) pour protéger `event_log`. La
   page lente est souvent celle d'un chef, pas celle du superadmin.
3. **Cinq minutes par défaut, quinze au maximum.** Une minute suffit à
   peine, sur un téléphone, à ouvrir le menu et toucher trois pages.
4. **Un collecteur dédié** dans le paquet de support :
   `request-timelines.csv`, une ligne par segment (chemin, rôle, delta
   de temps, delta de SQL, temps SQL) et un résumé par chemin (nombre,
   médiane, maximum, segment le plus lourd). Le chemin seul, jamais la
   chaîne de requête, comme l'entrée de journal actuelle.
5. La page Support affiche l'état de la fenêtre et le nombre de requêtes
   enregistrées, puis le formulaire de ticket tel quel.

### Coût quand la fenêtre est fermée

Mesuré le 4 septembre 2026 (PHP 8.4, OPcache) : un `mark()` complet, avec
`microtime()`, `memory_get_usage()` et les deux compteurs SQL, coûte
**0,47 µs** ; les 37 jalons d'une requête coûtent **18 µs**, soit 0,06 %
du plancher de 28 ms. Un `filemtime()` sur un fichier témoin absent coûte
**2 µs**. Les deux approches sont donc sans effet mesurable ; celle du
fichier témoin garde en plus la propriété actuelle, « aucun travail quand
le mode est fermé », et se lit avant que les réglages ne soient
disponibles. C'est celle à retenir.

### Limite : on ne mesure que le serveur

`RequestTimeline` voit le temps entre l'entrée dans `index.php` et la fin
de la réponse. Il ne voit **ni** le réseau, **ni** le service worker,
**ni** le rendu, **ni** le pré-téléchargement hors ligne. Or les deux
symptômes rapportés à l'origine de ce chantier (§2.4 : lenteur en
application installée, « second appui » sur le menu) étaient entièrement
côté client ; une fenêtre de mesure serveur les aurait montrés comme des
pages à 50 ms.

Second temps, à faire quand le besoin se présentera : pendant la fenêtre,
la page envoie un petit beacon (`navigator.sendBeacon`) vers une route
dédiée avec les métriques de navigation du navigateur
(`PerformanceNavigationTiming` : temps jusqu'au premier octet, réponse
complète, `DOMContentLoaded`, `load`), le mode (`standalone` ou onglet),
si la réponse venait du service worker (`workerStart`), et le type de
connexion quand il est disponible. Versé dans le même CSV, il permet de
séparer un serveur lent d'un réseau lent ou d'un service worker qui
attend. Sans lui, un ticket « le site est lent » reçu d'une unité peut
conclure à tort que tout va bien.
