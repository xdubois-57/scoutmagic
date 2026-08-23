# UX convergence — handoff / reprise

Document de passation du chantier « Convergence ScoutMagic » (branche
`claude/refactor-ux-analysis-dc4itj`, ~22 commits, RIEN n'est mergé sur main).
Écrit pour la session Claude qui reprendra le travail. À SUPPRIMER de la
branche avant merge — c'est un document de chantier, pas de la documentation
produit.

Références :

- Rapport d'analyse (révision 4, chiffres et constats) :
  https://claude.ai/code/artifact/72d1ffad-0d11-4aab-a2e0-a0ff083cec0f
- Rapport précédent (révision 3, fiches détaillées des 17 bloquants) :
  artifact « Revue UX ScoutMagic » du même compte.
- Conventions du chantier : `design.md` §7 (a été écrit par ce chantier) ;
  verrou mécanique : `tests/Core/View/UxConventionsTest.php`.

## Décisions actées par Xavier (ne pas re-demander)

1. Lexique : **animé** (enfant d'une section) ; **animateur** = mot canonique à
   l'écran pour l'encadrant (« chef » ne s'emploie plus seul, seulement dans
   « chef d'unité ») ; **staff de section** = les animateurs d'une section ;
   **Staff d'Unité** = les chefs d'unité, supérieurs hiérarchiques des
   animateurs. Menus : « Espace membres » (ex-animés), « Espace animateurs »
   (ex-chefs), « Espace chefs d'U » inchangé.
2. Pages Configuration renommées par la tâche : « Édition du site »
   (/config/general), « Installation & serveur » (/setup), « Réglages »
   (/config/settings). URLs inchangées (identifiants techniques).
3. Badges : mapping sévérité→couleur UNIFIÉ (pending→warning partout, etc.),
   même si des couleurs changent — voir `partials/status_badge.html.twig`.
4. Harmonisations visuelles acceptées : taille de h1 unique, nav-pills →
   chips, boutons de création tous en btn-primary (plus de vert).
5. `data-confirm` uniquement sur `<form>` (jamais le bouton), règle testée.
6. Cookies : « Tout accepter » et « Tout refuser » = deux btn-primary
   identiques ; « Personnaliser » en lien ; bannière absente de /cookies.
7. Mode sombre : préparé ET livré (bascule ◐ + prefers-color-scheme,
   persistance localStorage sous consentement fonctionnel).
8. Ordre de travail : bloquants d'abord, puis composants. Fait.
9. Fil d'Ariane = seule affordance de retour (décision de la révision 3,
   reconduite) ; exceptions listées dans UxConventionsTest.

## FAIT (tout est commité et poussé, dernière validation complète verte :
8 926 tests PHP, 679 tests JS/36 fichiers, PHPStan 0, typecheck 0)

- **Phase 0** — lexique propagé partout ; design.md §7 ; UxConventionsTest
  (à cliquet, TOUTES ses allowlists sont vides — toute nouvelle violation
  échoue) ; breadcrumbs déclarés sur toutes les routes de page (+
  breadcrumb_trail dans les contrôleurs de pages détail) ; boutons « Retour »
  supprimés/reformulés ; bandeau home « Du nouveau dans vos groupes » à 3
  compteurs (HomeGroupActivityProvider a changé de contrat).
- **Phase 1** — les 13 bloquants de la revue corrigés (voir messages de
  commit 0ad5162..5edfbf8) : nav mobile, confirmations CSP (retro, rental,
  inbound_mail, support_dashboard), quota+saisie du formulaire news, lien
  magique (écran d'expiration + renvoi), cartes finance mobiles cliquables
  au doigt et au clavier, cookies, passkey (data.success), groupes
  (suggestion IA affichée, garde dernier-modérateur sur les 3 chemins,
  include only sur la page Membres).
- **Phase 2** — `public/assets/js/api.js` (ScoutMagicApi : csrfToken,
  postJson→{ok,status,data}, getJson, withDisabled, escapeHtml, debounce,
  poll) + `toast.js` (ScoutMagicToast), chargés par base.html.twig ; 13
  scripts migrés dessus ; garde CSRF unifiée
  (`AbstractController::guardCsrf/guardCsrfJson`, constante
  SESSION_EXPIRED_MESSAGE, 159 blocs migrés) ; flash 'danger' éradiqué ;
  filtres Twig `|money|money_cents|date_fr|datetime_fr` créés (TwigFactory)
  et propagés (95 sites) ; CSS : bloc pointer:coarse couvre .btn/.form-control
  standard, restauration sur pointer:fine, `.tap-target`,
  `.page-narrow/medium/wide`, `.border-dashed`.
- **Phase 3** — 8 partials créés et documentés (bloc Usage en tête de
  chaque fichier) : empty_state, page_header, pagination, stat_tiles,
  status_badge, modal (embed, titre id = `{id}-title`), page_picker,
  form_field. TOUT le site migré dessus sauf form_field (voir À FAIRE) :
  ~95 page_header (h1 = <title> de la page), ~90 empty_state, 8 paginations,
  ~110 badges au mapping + syntaxe text-bg-*, 15 modales, 6 stat_tiles,
  sous-navs en page_picker, conteneurs imbriqués et rustines
  min-height:44px purgés. Tests de rendu :
  `tests/Core/View/SharedPartialsRenderingTest.php`.
- **Phase 4 (partiel, voir À FAIRE)** — mode sombre livré (`theme.js` +
  script inline anti-flash dans base.html.twig, bascule dans nav, entrée
  `theme_preference` dans CookieRegistry, spec `tests/js/theme.test.js`,
  vérifié visuellement en sombre) ; passe accessibilité (icônes aria-hidden,
  boutons icône-seule nommés, role=alert/status, onglets de connexion
  sémantiques, libellés des interrupteurs cookies, lien d'évitement,
  inputmode) ; extraction du JS inline de 3 gabarits sur 5 →
  `mass-mail-list.js`, `finance-categories.js` (bilan IA relisible dans la
  page), `rgpd-config.js` ; page Réglages pliée en sections ; nav desktop
  hiérarchisée (menus = onglets soulignés `.desktop-menu-btn`, pages =
  pilules).

## À FAIRE (dans l'ordre suggéré)

1. **Extraction JS inline restante (2 gabarits)** :
   `modules/finance/views/movements/list.html.twig` (~200 l. inline →
   `finance-movements.js` ; la délégation `.movement-row` récente part telle
   quelle) et `modules/llm_connector/views/config/index.html.twig` (~200 l. →
   `llm-config.js`) **avec le correctif fonctionnel** : dérouler la liste des
   fournisseurs déclenche aujourd'hui test+enregistrement (changement de
   sous-traitant silencieux) — dissocier « consulter » d'« activer » par un
   bouton explicite. Suivre le patron des 3 extractions faites (IIFE,
   toolbox, spec Vitest, data via `window.xxxData` nonce-tagué, JSDoc).
2. **Migration form_field** : le partial existe ; seuls
   `modules/registration/views/public.html.twig` et
   `modules/rental/views/config/_asset_fields.html.twig` sont migrés.
   Restent (champs simples uniquement, vérifier les ids ciblés par du JS
   avant chaque champ) : gallery/album_form + location_form, retro/config,
   rental/management/settings + public/request, groups/group_edit_form,
   banner, inbound_mail, calendar/config, sos_staff/config…
   `core/View/templates/setup/index.html.twig` est EXCLU (invalid-feedback
   propre).
3. **Politique getMessage()** : rien n'a été livré (l'agent a été arrêté
   avant d'écrire). Plan prévu : `core/Http/UserFacingMessage::from(\Throwable,
   $fallback)` + interface marqueur `Core\Exception\UserFacingException`
   posée sur les exceptions métier à messages français (RentalException,
   MassMailException… — inspecter leurs messages un à un) ; migrer les ~38
   FlashMessage + ~72 json qui exposent `$e->getMessage()` d'un catch
   générique ; journaliser l'erreur réelle.
4. **Bout en bout** : lancer `npm run e2e` (jamais exécuté pendant le
   chantier — PHPUnit/Vitest sont verts mais l'e2e est le seul à booter la
   vraie app ; `tests/e2e/specs/news-form-payment.spec.js` a été adapté au
   nouveau rendu « Complet », le reste devrait passer mais à vérifier).
5. **Rapport artifact** : mettre à jour
   https://claude.ai/code/artifact/72d1ffad-0d11-4aab-a2e0-a0ff083cec0f
   (section Avancement + captures avant/après). Les captures du chantier
   étaient dans le scratchpad de session (perdues) — recapturer : provision
   d'instance via `php scripts/e2e-support.php provision <dir> <port>` avec
   E2E_DB_*/E2E_ADMIN_* exportés, `php -S`, puis Playwright
   (`/opt/pw-browsers/chromium`, viewports 390 et 1280). ATTENTION : sur la
   page de connexion, attendre `networkidle` avant de cliquer (auth.js est
   deferred — un clic trop tôt ne soumet rien).
6. **Hors périmètre du chantier mais toujours ouverts** (issus des rapports,
   jamais planifiés ici) : les parcours de la section E de la révision 3
   (page de confirmation d'inscription autonome, correction des extractions
   IA des reçus, décompte avant envoi des mails groupés, prérequis FTP de la
   maintenance, emails rental décision/proposition/modification — toujours
   absents de RentalManagementController::changeStatus/propose/decideChange —,
   effectif visible d'un groupe, « vu par » reformulé) ; « Requis par : … »
   sur les cartes de modules ; luminance sur couleurs configurables ;
   conventions d'URL (dette assumée).

## Pièges appris (à respecter absolument)

- **Jamais `git stash`/`checkout`/`reset` dans un agent parallèle** : un
  stash a balayé le travail de 4 agents en plein vol (récupéré de justesse).
  Les prompts d'agents doivent l'interdire explicitement, et interdire les
  réécritures complètes de fichiers partagés (Edit ciblés seulement).
- **UxConventionsTest est à double sens** : une violation corrigée mais
  encore listée fait échouer le test (« shrink the allowlist ») — retirer
  l'entrée dans le même commit que le correctif.
- **aria-checked des role="switch" est SYNCHRONISÉ par nav.js**
  (`ScoutMagicNav.syncSwitchAriaChecked`) : ne pas les supprimer —
  `tests/Core/View/SwitchAriaCheckedTest.php` les épingle. (Une passe a11y
  les a retirés par erreur, c'est réverté.)
- **Titres de modales** : l'embed `partials/modal.html.twig` impose l'id
  `{id}-title` — tout JS qui remplit un titre doit viser cet id.
- **empty_state/page_header** : quand page_header porte déjà l'action de
  création, l'empty_state n'en reçoit pas (un seul btn-primary par écran).
- **Twig `|default(true)`** rend true pour `false` (default s'applique aux
  valeurs « empty ») — utiliser `x is defined ? x : true` pour un booléen
  paramétrable (bug réel attrapé dans modal.html.twig).
- Les montants dans des `value=` d'inputs parsés (JS ou serveur) ne passent
  PAS par `|money` — exceptions listées dans le message du commit filtres.
