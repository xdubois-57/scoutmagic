# UX convergence — handoff / reprise

Document de passation du chantier « Convergence ScoutMagic » (branche
`claude/refactor-ux-analysis-dc4itj`, RIEN n'est mergé sur main). Écrit pour
la session Claude qui reprendra le travail. À SUPPRIMER de la branche avant
merge — c'est un document de chantier, pas de la documentation produit.

Références :

- Rapport d'analyse et d'avancement (révision 6) :
  https://claude.ai/code/artifact/72d1ffad-0d11-4aab-a2e0-a0ff083cec0f
- Rapport précédent (révision 3, fiches détaillées des 17 bloquants) :
  artifact « Revue UX ScoutMagic » du même compte.
- Conventions du chantier : `design.md` §7.1 à §7.10 (écrit par ce chantier) ;
  politique des messages d'exception : `AGENTS.md` § Exception messages ;
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
3. Badges : mapping sévérité→couleur UNIFIÉ, même si des couleurs changent.
4. Harmonisations visuelles acceptées : taille de h1 unique, nav-pills →
   chips, boutons de création tous en btn-primary (plus de vert).
5. `data-confirm` uniquement sur `<form>` (jamais le bouton), règle testée.
6. Cookies : « Tout accepter » et « Tout refuser » = deux btn-primary
   identiques ; « Personnaliser » en lien ; bannière absente de /cookies.
7. Mode sombre : préparé ET livré (bascule ◐ + prefers-color-scheme,
   persistance localStorage sous consentement fonctionnel).
8. Ordre de travail : bloquants d'abord, puis composants. Fait.
9. Fil d'Ariane = seule affordance de retour ; exceptions listées dans
   UxConventionsTest.
10. **Ne pas merger sur main** — Xavier veut tester à la main d'abord.

## FAIT

### Première session (phases 0 à 4)

- **Phase 0** — lexique propagé partout ; design.md §7 ; UxConventionsTest
  (à cliquet) ; breadcrumbs déclarés sur toutes les routes de page ; boutons
  « Retour » supprimés ; bandeau home à 3 compteurs.
- **Phase 1** — les 13 bloquants de la revue corrigés.
- **Phase 2** — `api.js` (ScoutMagicApi) + `toast.js` (ScoutMagicToast) ;
  garde CSRF unifiée (`guardCsrf`/`guardCsrfJson`, 159 blocs) ; flash
  'danger' éradiqué ; filtres Twig `|money|money_cents|date_fr|datetime_fr` ;
  socle CSS tactile remis à l'endroit, largeurs de page.
- **Phase 3** — 8 partials créés et documentés : empty_state, page_header,
  pagination, stat_tiles, status_badge, modal (embed), page_picker,
  form_field. Tout le site migré dessus.
- **Phase 4 (partiel)** — mode sombre livré ; passe accessibilité ; page
  Réglages pliée en sections ; nav desktop hiérarchisée.

### Deuxième session

- **`confirm.js` — le dialogue partagé** (`ScoutMagicConfirm.ask()` →
  `Promise<boolean>`, `.prompt()` → `Promise<string|null>`). Remplace 75 des
  91 boîtes natives, y compris le gestionnaire `data-confirm` de
  `base.html.twig`, qui arrête la soumission, demande, puis la **rejoue avec
  son bouton d'origine** (`requestSubmit(submitter)` + garde
  `data-confirmed`). Focus sur « Annuler », jamais sur le bouton destructeur.
  Spec : `tests/js/confirm.test.js` (27 tests).
- **`rich-text-link.js` — l'insertion de lien partagée**. Cinq barres
  d'outils réimplémentaient le même `prompt('URL du lien :')`. La sélection
  est capturée avant l'ouverture du dialogue et restaurée après (sans quoi
  `execCommand('createLink')` n'a rien à envelopper), un hôte nu devient
  `https://…`, et un `javascript:` est refusé avec un motif.
- **Politique des messages d'exception** : `Core\Exception\UserFacingException`
  (interface marqueur) + `UserFacingMessage::from($e, $repli)`. 27 classes
  marquées, les trois classes du mauvais côté corrigées (`SettingException`
  parlait anglais, `ModuleException` affichait un chemin serveur,
  `MailException` recopiait PHPMailer), les sites de blanchiment
  (`new XException($e->getMessage())`) réécrits. Documenté dans AGENTS.md.
- **Extraction du JS inline** : 2 584 → 814 lignes. 14 nouveaux fichiers
  testés (finance-movements, llm-config, calendar-config, calendar-chief,
  config-functions, config-badges, mass-mail-config, finance-config,
  finance-accounts, finance-receipt-form, staffs, member-search, sos-config,
  sos-admin).
- **`form_field`** : 2 → 20 gabarits, ~100 champs. Le partial a gagné
  `size: 'sm'` (la variante compacte qui débloque les 34 fichiers que le
  relevé avait dû rejeter), `disabled`, `readonly`, `pattern`, `list`,
  `autofocus`, `describedby_extra`, et `disabled` par option.
- **Trou hors-ligne bouché** : `api.js`, `toast.js`, `confirm.js`,
  `rich-text-link.js` et `theme.js` n'étaient dans aucun cache du service
  worker. `AppShellCoverageTest` compare désormais les deux listes.
- **Correctifs fonctionnels** : « consulter ≠ activer » sur le connecteur IA
  (dérouler la liste changeait le sous-traitant actif) ; cinq trouvailles de
  sécurité dans le module SOS (XSS dans le message du fournisseur, données
  OVH reparsées, `href` non validé, page 500 injectée comme liste,
  enregistrements silencieux).
- **Couverture JS** : 15 % → **78 %** d'instructions, 86 % de branches,
  6 → 62 fichiers de spec, 97 → **1 149** tests.
- **Bout en bout** : `npm run e2e` n'avait jamais tourné du chantier. Il
  passe — **38 tests verts**. Onze specs répondaient aux confirmations par
  `page.on('dialog')`, qui ne se déclenche plus sur une modale du site ;
  elles passent par `tests/e2e/support/confirm-dialog.js`. La suite a
  trouvé ce qu'aucun test unitaire ne pouvait voir : le helper cliquait
  pendant la transition d'ouverture de Bootstrap, dont `Modal.hide()` ne
  fait rien tant qu'elle court — la modale et son fond restaient à l'écran
  et avalaient tous les clics suivants.
  **Note d'environnement** : le binaire Chromium de ce conteneur (build
  1194) ne correspond pas au `@playwright/test` installé (1234) ; il faut
  donc `E2E_CHROMIUM_EXECUTABLE=/opt/pw-browsers/chromium npm run e2e`
  (l'échappatoire documentée dans README § « Où ça tourne »). Sur une
  machine dont les navigateurs correspondent, `npm run e2e` suffit.

## À FAIRE (dans l'ordre suggéré)

1. **Extraction du JS inline restant** — 814 lignes dans 26 gabarits. Les
   plus gros : `account/index.html.twig` (127 l., 5 boîtes natives),
   `banner/views/config.html.twig` (88 l., 3),
   `calendar/views/public.html.twig` (78 l., 2),
   `finance/views/dashboard.html.twig` (64 l.),
   `registration/views/passage.html.twig` (54 l.),
   `auth/password_reset.html.twig` (52 l.). Le patron est établi : lire
   `public/assets/js/config-badges.js` et sa spec, puis faire pareil.
   Chaque extraction rend le fichier testable ET fait tomber une entrée de
   `UxConventionsTest::NATIVE_DIALOG_ALLOWLIST`, qui ne peut que rétrécir.
2. **`drop_zone`** — le seul composant de la section C jamais créé. Deux
   zones de dépôt recodées + deux réimplémentations du réordonnancement
   (~50 lignes de JS chacune).
3. **Migration `form_field` restante** — le partial sert maintenant les
   champs compacts, donc les fichiers que le relevé avait rejetés sont
   éligibles : gallery/config, retro/settings, finance (dashboard, receipts,
   import), mass_mail (list, tracking), groups (list, members, show),
   sos_staff/admin, calendar (chief, config), registration/config,
   admin/journal, admin/scout_year, config/maintenance,
   notifications/preferences. Exclus : `setup/index.html.twig`, et tout
   champ dont le contrôle porte des `data-*` lus par du JS (le partial ne
   les émet pas).
4. **`Core\Maintenance\UpdateException` reste non marquée, délibérément.**
   Deux de ses sites de lancement construisent leur message avec
   `InstallUpdateHandler::writeFailureMessage()`, qui interpole un chemin
   serveur ABSOLU et l'avertissement PHP brut (« copy(...): Failed to open
   stream: Permission denied »). Conséquence assumée : sur la page
   Maintenance, l'admin lit le repli (« …le détail est dans le journal »)
   au lieu du chemin. Si on veut lui rendre le chemin — c'est l'information
   qui lui sert à corriger ses droits — il faut d'abord rendre
   `writeFailureMessage()` relatif à la racine d'installation et lui retirer
   `error_get_last()`, puis marquer la classe. Pas fait : la version sûre
   est en place, l'amélioration est un choix produit.
5. **« Expliquer avec l'IA » d'un échec S3, légèrement dégradé.**
   `public/assets/js/gallery-storage-location.js` renvoie au serveur la
   chaîne d'erreur qu'il a reçue ; comme `S3StorageBackend::testConnection()`
   retourne désormais une phrase française au lieu du message du SDK AWS,
   `S3ErrorExplainerService` reçoit moins de matière. La perte est faible —
   la phrase française est dérivée du **code** d'erreur S3, donc elle porte
   la même information, en plus structurée — et le texte du SDK est au
   journal. Correctif propre si on veut le refermer : que
   `GalleryConfigController::testConnection()` mémorise
   `$backend->lastTechnicalError()` en session et que `explainS3Error()` le
   relise côté serveur, plutôt que de le faire transiter par le navigateur.
6. **Un « save on change » silencieux restant** — `#default-number-member`
   (sos_staff/admin) confirme maintenant par un toast, mais **enregistre
   toujours au `change`** : c'est la forme exacte du bug llm_connector, sur
   le réglage qui décide à qui sonne le numéro SOS de l'unité. Décision
   produit à prendre : bouton explicite, ou statu quo.
7. **Hors périmètre du chantier mais toujours ouverts** : parcours de la
   section E de la révision 3 (page de confirmation d'inscription autonome,
   extractions IA des reçus, décompte avant envoi groupé, prérequis FTP de
   la maintenance, **emails rental décision/proposition/modification —
   toujours absents de `RentalManagementController::changeStatus/propose/
   decideChange`**, effectif visible d'un groupe) ; « Requis par : … » sur
   les cartes de modules ; luminance sur couleurs configurables ;
   conventions d'URL (dette assumée).

## Pièges appris (à respecter absolument)

- **Jamais `git stash`/`checkout`/`reset` dans un agent parallèle** : un
  stash a balayé le travail de 4 agents en plein vol. Les prompts d'agents
  doivent l'interdire explicitement, et interdire les réécritures complètes
  de fichiers partagés (Edit ciblés seulement).
- **Les tests `@group database` partagent une seule base MySQL.** Deux
  suites complètes lancées en même temps se marchent dessus :
  `SetupControllerTest` crée et supprime de vraies tables, et on obtient des
  « Failed asserting that 5 is identical to 1 » qui ne sont pas des
  régressions. Avant de croire un échec de ce groupe, **relancer le fichier
  seul**. Corollaire : ne pas faire lancer `vendor/bin/phpunit` complet à
  plusieurs agents en parallèle.
- **UxConventionsTest est à double sens** : une violation corrigée mais
  encore listée fait échouer le test (« shrink the allowlist ») — retirer
  l'entrée dans le même commit que le correctif. Quand plusieurs agents
  extraient en parallèle, leur dire de NE PAS toucher au test et resserrer
  la liste soi-même à la fin.
- **`aria-checked` des `role="switch"` est SYNCHRONISÉ par nav.js**
  (`ScoutMagicNav.syncSwitchAriaChecked`) : ne pas les supprimer —
  `tests/Core/View/SwitchAriaCheckedTest.php` les épingle.
- **Titres de modales** : l'embed `partials/modal.html.twig` impose l'id
  `{id}-title` — tout JS qui remplit un titre doit viser cet id.
- **empty_state/page_header** : quand page_header porte déjà l'action de
  création, l'empty_state n'en reçoit pas (un seul btn-primary par écran).
- **Twig `|default(true)`** rend true pour `false` — utiliser
  `x is defined ? x : true` pour un booléen paramétrable.
- **Le partial `form_field` n'émet pas de `data-*`** : un champ dont le
  contrôle porte un `data-` lu par du JS n'est pas migrable tel quel.
- **`getByLabel(..., { exact: true })` casse quand un champ devient
  `required`** : le partial ajoute un « * » visible dans le libellé. Les
  specs concernées utilisent maintenant `getByLabel(/^Adresse email \*$/)`.
- Les montants dans des `value=` d'inputs parsés (JS ou serveur) ne passent
  PAS par `|money`.
- **Une modale vole le focus, et un `contenteditable` qui perd le focus perd
  sa sélection.** Tout dialogue ouvert depuis un éditeur de texte riche doit
  capturer la `Range` avant et la restaurer après (`rich-text-link.js` le
  fait ; c'est la partie subtile de ce fichier).
