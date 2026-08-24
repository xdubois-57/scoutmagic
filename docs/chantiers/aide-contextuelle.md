# Chantier — Aide contextuelle

Journal d'implémentation du document de chantier « Aide contextuelle »
(itérations IT-01 à IT-07). Une section par itération : ce qui a été fait,
les décisions prises en autonomie, les divergences constatées entre le
document de chantier et le dépôt réel, et ce qui a été reporté. Même
format que `docs/chantiers/support-statistics.md`.

---

## Récapitulatif final

**Ce qui a été livré.** Les sept itérations, dans l'ordre, chacune
committée sur la branche de chantier et mergée en `--no-ff` dans `main`
une fois la suite verte :

| # | Livré |
|---|---|
| IT-01 | Le moteur complet : `Core\Help`, `HelpController`, bouton + panneau offcanvas, `/aide`, options de `MarkdownRenderer`, câblage (routes, `route_help`, manifeste, `OfflineWhitelist`, `release.sh`), tests, docs |
| IT-02 | 11 sujets « Premiers pas » et « Espace membres » (dont les 2 gabarits d'IT-01) |
| IT-03 | 4 sujets « Espace animateurs », dont les 2 premiers sujets livrés par des modules (`calendar`, `member_stats`) |
| IT-04 | 6 sujets « Espace chefs d'U » (import, année scoute, membres, journal, édition du site, Config Desk) |
| IT-05 | 10 sujets « Configuration » — maintenance découpée en 3 sujets (sauvegardes, mises à jour, réinitialisation) comme demandé |
| IT-06 | 8 sujets de modules de vie de section (calendar, gallery ×2, groups ×2, trombinoscope, news ×2) |
| IT-07 | 23 sujets de modules de gestion + 4 sujets comblant les trous révélés par le test de couverture, et la vérification finale |

**Corpus final : 68 sujets** (25 core, 43 modules), ~330 Ko sur disque —
poids négligeable dans l'artefact de release.

**Vérification finale.** `tests/Core/Help/HelpMenuCoverageTest` écrit le
contrôle demandé comme un test : toute page de menu (pages core
enregistrées via `MenuBuilder::addPage()` dans `public/index.php` +
routes de modules à `label` non vide) doit être couverte par au moins un
sujet visible au plancher de rôle de la page. Son premier passage a
trouvé quatre pages orphelines (`/config/badges`, `/config/calendar`,
`/config/gallery`, `/config/retro`) — couvertes dans la foulée, preuve
que le test travaille. Les modules `visible_when` (support_dashboard,
test_tools) sont exclus du contrôle : ils n'existent sur aucune
installation d'unité, le public de l'aide.

**Les décisions autonomes les plus structurantes** (détail par
itération ci-dessous) : un seul emplacement pour le bouton (le fil
d'Ariane, visible partout depuis le mega-menu — le double include du
document aurait affiché deux boutons côte à côte) ; l'option
`blockquotes` du renderer exigée par la charte ; les sujets de pages de
modules livrés dans le module lui-même (ils disparaissent avec lui) ;
les catégories du sommaire ordonnées par une préférence de présentation
sans jamais l'exiger d'un auteur.

---

## IT-01 — Mécanisme générique

**Livré.** Tout le moteur : `Core\Help` (`HelpTopic`, `HelpFrontMatterParser`,
`HelpRegistry`, `HelpService`, `HelpException`), `HelpController`
(`/aide`, `/aide/{id}`, `role_min: public`, breadcrumb sans parents),
le bouton (`partials/help_button.html.twig`) et le panneau offcanvas
(`partials/help_panel.html.twig` + `public/assets/js/help-panel.js`,
`offcanvas-bottom` mobile / tiroir droit à ≥992px via `app.css`), la page
`/aide` (index groupé par catégorie, recherche `?q=` en GET,
`page_header`/`empty_state`, badge « Module »), l'extension de
`MarkdownRenderer` (options à défauts strictement rétro-compatibles),
le global Twig `route_help` (posé par `FrontController` après le RBAC,
comme `route_breadcrumb`), la section `help` optionnelle de `module.json`
(`ModuleManifest`/`ModuleManager`), les entrées `OfflineWhitelist`
`/aide` (exact) et `/aide/` (child), l'assertion `docs/help/` dans
`scripts/release.sh`, deux sujets d'exemple (`aide`,
`membres-par-section`), les tests (parseur, registre, service,
contrôleur, `route_help`, options du renderer, section `help` du
manifeste, invariants) et la documentation (ARCHITECTURE.md §8.64,
design.md §7.11, specifications.md §4.6, docs/module-development.md,
AGENTS.md, README.md).

**Décisions autonomes.**

1. **Un seul emplacement pour le bouton, pas deux** — voir « Divergences »
   ci-dessous, c'est la plus structurante.
2. **Une troisième option de rendu, `blockquotes`** (désactivée par
   défaut), en plus des deux que le document liste (niveau de titre,
   images `/assets/`) : la charte §10 impose l'encadré `> `, que le
   renderer ne connaissait pas. La sortie sans options reste identique
   au octet près (une ligne `> x` reste un paragraphe échappé), épinglé
   par `MarkdownRendererOptionsTest`.
3. **`heading_base_level: 1` et sections à `##`** : un auteur Markdown
   écrit naturellement `##` pour une section ; avec la base 1, `##` rend
   le `<h2>` qu'il annonce. Le `#` seul (qui produirait un second `<h1>`,
   contraire à design.md §7.6) est interdit par le test d'invariant.
4. **La section `help` du manifeste est facultative même pour un module
   qui a de l'aide** : `ModuleManager` scanne `modules/<id>/help/` dès
   que le répertoire existe ; la section ne sert qu'à renommer le
   répertoire. C'est la lecture la plus fidèle de la décision verrouillée
   3 (« ajouter de l'aide ne doit jamais exiger de toucher au code »).
5. **Le test d'invariant chemins→routes parse `public/index.php` par
   regex** (précédent : `tests/Security/FileAccessAuditTest`) plutôt que
   d'ajouter un accesseur public au `Router`, qui n'expose pas sa table —
   pas de surface de production ajoutée pour un besoin de test.
6. **Le parseur rejette une clé de front-matter inconnue** (non demandé
   explicitement) : le symptôme d'une faute de frappe silencieuse
   (`role-min:` ignoré → sujet public) serait une fuite, pas un bug
   cosmétique. Même logique : `Role::tryFrom`, jamais `Role::fromString`
   (qui rétrograde en silence vers `public`).
7. **`/aide/{id}` porte un `breadcrumb_trail` vers `/aide`** — le
   document demande `parents` vide (respecté) ; le trail est le mécanisme
   design.md §7.3 pour une vraie page ancêtre, sans quoi la page d'un
   sujet n'offre aucun chemin de retour.
8. **La charte est partiellement mécanisée** dans
   `HelpInvariantsTest` : ≤ 500 mots (marge sur les ~400 de la charte),
   ≤ 1 encadré, liens externes limités à lesscouts.be, pas de `#` seul.
   Le reste (ton, lexique) reste à la relecture.
9. **`OfflineManifestService` n'émet plus littéralement les entrées
   `child` autres que `/members/`** : il aurait pré-téléchargé `/aide/`
   (404). Les pages `/aide/{id}` sont mises en cache à la visite par le
   service worker, ce qui suffit (le contenu du panneau voyage de toute
   façon dans chaque page).
10. **`help-panel.js` sans test Vitest** : pur câblage DOM (bascule de
    classes), le cas que AGENTS.md § Tests exempte explicitement. Il est
    couvert par `npm run typecheck` et ajouté au app shell de `sw.js`
    (exigé par `AppShellCoverageTest`).

**Divergences avec le document de chantier.**

- **Le bouton n'est inclus qu'une fois, dans le fil d'Ariane** — le
  document imposait deux includes (fil d'Ariane pour mobile/PWA, nav
  desktop pour le reste) parce qu'au moment de sa rédaction le fil
  d'Ariane était masqué sur navigateur desktop. Le chantier « mega-menu »
  (mergé avant IT-01) rend le fil d'Ariane visible à toutes les largeurs :
  le second include aurait affiché deux boutons identiques côte à côte
  sur desktop. L'intention (« le bouton est toujours visible ») est
  respectée partout avec un seul emplacement.
- **La numérotation** : §8.64 dans ARCHITECTURE.md (le document disait
  « premier numéro libre » — c'était 8.64), §7.11 dans design.md.
- **`MarkdownRenderer` a un troisième consommateur** non cité : le filtre
  Twig `markdown` (`TwigFactory`). Couvert par les mêmes défauts
  rétro-compatibles ; `tests/Core/View/MarkdownRendererTest.php` n'a pas
  bougé d'une ligne (les nouveaux tests vivent dans
  `MarkdownRendererOptionsTest`).
- **La « ligne dans la checklist » d'AGENTS.md** est allée dans la
  checklist de création de module (point 13), avec la précision qu'elle
  vaut aussi pour une page core — AGENTS.md n'a pas d'autre checklist de
  périmètre adapté.

**Reporté.**

- L'index sérialisé sous `storage/core/help/` (déclencheur : ~100 sujets),
  conformément au document. À 68 sujets, le corpus s'en approche : c'est
  le premier chantier de suite si la latence devient mesurable.
- Un éventuel scénario E2E : la suite Playwright est réservée aux
  coutures navigateur↔serveur à haut risque ; le panneau server-rendered
  n'en introduit aucune nouvelle.

---

## IT-02 — Premiers pas et espace membres

**Livré.** 9 nouveaux sujets core (les gabarits `aide` et
`membres-par-section` datant d'IT-01) : `se-connecter`,
`decouvrir-le-site` (couvre `/`, `/contact`, `/sections` en un sujet),
`installer-application`, `un-email-plusieurs-animes` (purement
documentaire, sans `paths` — le thème imposé par le chantier),
`mon-compte`, `page-membre`, `adresses-email`, `notifications`,
`notifications-preferences`, `cookies`, `donnees-personnelles`.

**Décisions.** Deux sujets couvrent `/` (`decouvrir-le-site` +
`installer-application`) — première vraie utilisation de la vue liste du
panneau. La photo de compte et la photo de membre étant la confusion la
plus prévisible, chacune des deux fiches renvoie explicitement à l'autre.
La rédaction s'est faite sur fiches factuelles tirées des contrôleurs et
gabarits réels (libellés exacts vérifiés), pas de la spécification.

## IT-03 — Espace animateurs

**Livré.** `staffs` et `documents-de-section` (deux sujets sur
`/chefs/staffs` : la consultation à plancher intendant, les actions à
plancher chief), plus les deux premiers sujets **livrés par un module** :
`modules/calendar/help/calendrier-animateurs.md` et
`modules/member_stats/help/statistiques-membres.md`.

**Décision structurante.** Les pages de modules citées par les itérations
de contenu (IT-03 : `/chefs/calendar`, `/chiefs/stats`) reçoivent leur
sujet **dans le module**, jamais dans `docs/help/` : un sujet core
pointant une route de module désactivé apparaîtrait dans `/aide` en
menant à un 404. Appliqué systématiquement ensuite (IT-06/IT-07).

**Divergence d'actualité.** Le chantier parallèle « section documents »
(PR #41) a restreint l'écriture des documents aux sections réellement
staffées pendant la rédaction — la fiche a été réécrite sur la règle
mergée, pas sur l'état lu initialement.

## IT-04 — Espace chefs d'U

**Livré.** `import-desk`, `annee-scoute`, `membres-admin`, `journal`,
`edition-du-site`, `config-desk`.

**Décisions.** `role_min` suit la route réelle, pas le regroupement
éditorial du chantier : `config-desk` est `superadmin` (la page l'est)
bien que l'itération le range sous « Espace chefs d'U » ; l'exemple de
front-matter du document (`import-desk` en `chief`) est corrigé en
`admin`, le rôle réel de `/admin/import`. Les invariants « l'import
n'élève jamais un rôle » et « la famille ne voit une décision qu'à
l'envoi de l'e-mail » sont répétés dans chaque sujet concerné — ce sont
les deux incompréhensions les plus coûteuses.

## IT-05 — Configuration et maintenance

**Livré.** `reglages`, `modules`, `sauvegardes`, `mises-a-jour`,
`reinitialisation` (les trois sujets distincts exigés pour
`/config/maintenance`), `config-rgpd`, `actions-planifiees`,
`config-notifications`, `support`, `installation-serveur`.

**Décisions.** Les trois sujets Maintenance sont déclarés `role_min:
admin` (le plancher de la page) : un chef d'unité voit les blocs, les
sujets précisent quand une action exige l'administrateur du site. Le
piège « l'avertissement cron ne s'affiche pas sur /config/scheduled mais
sur /config/notifications » est traité en documentant le vrai
comportement dans `actions-planifiees`. Ton légèrement plus technique
(DNS, cron, IBAN) assumé pour ce public, sans jamais nommer une classe ou
une route.

## IT-06 — Modules de la vie de section

**Livré.** 8 sujets dans les `help/` des modules : `calendrier` (public),
`galerie` + `gerer-la-galerie`, `groupes` + `animer-un-groupe`,
`trombinoscope`, `actualites` + `publier-une-actualite`.

**Décisions.** Deux encadrés « vie privée » ajoutés de mon initiative
(photos d'enfants dans la galerie, lien ICS personnel secret) — la charte
les réserve à l'irréversible ou au contre-intuitif, ces deux cas sont les
deux. `groupes`/`animer-un-groupe` partagent `/groups/*` avec deux
planchers (identified/chief) : le panneau montre à chacun ce que son rôle
permet.

## IT-07 — Modules de gestion et vérification finale

**Livré.** 23 sujets : finance ×4 (`finances`, `importer-extraits`,
`recus`, `config-finance`), mass_mail ×3 (`envoi-de-mails`,
`publipostage`, `config-envoi-mails`), registration ×6
(`inscrire-un-enfant`, `suivre-une-demande`, `gerer-les-demandes`,
`departs`, `passage`, `previsions`), rental ×3 (`locations`,
`gerer-les-locations`, `config-locations`), banner, sos_staff ×2, retro
×2 (`retrospectives`, `retro-participer` — le tableau public `/r/*` est
la page la plus exposée à des non-membres, elle méritait son sujet),
inbound_mail, llm_connector. Puis, révélés par le test de couverture :
`badges` (core), `config-calendrier`, `config-galerie`, `config-retro`.

**Décisions.** `member_stats` (listé en IT-07) était déjà couvert depuis
IT-03. `/mes-locations` est déclaré `identified` comme sa route — le
garde réel étant « gestionnaire du bien », pas un rôle ; le sujet
l'explique. Les pages profondes non couvertes par la sémantique
exact/enfant (suivi d'un envoi, séjour d'une réservation) sont
documentées dans le sujet de leur page mère plutôt que d'élargir la
grammaire des `paths` — divergence de confort assumée, aucune de ces
pages n'est une page de menu.

**Vérification finale.** `HelpMenuCoverageTest` (voir récapitulatif) ;
poids du corpus contrôlé (~330 Ko) ; ce journal.
