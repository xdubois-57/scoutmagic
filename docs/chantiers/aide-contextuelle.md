# Chantier — Aide contextuelle

Journal d'implémentation du document de chantier « Aide contextuelle »
(itérations IT-01 à IT-07). Une section par itération : ce qui a été fait,
les décisions prises en autonomie, les divergences constatées entre le
document de chantier et le dépôt réel, et ce qui a été reporté. Même
format que `docs/chantiers/support-statistics.md`.

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
  conformément au document.
- Un éventuel scénario E2E : la suite Playwright est réservée aux
  coutures navigateur↔serveur à haut risque ; le panneau server-rendered
  n'en introduit aucune nouvelle.
