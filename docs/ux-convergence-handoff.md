# UX convergence — handoff / reprise

Document de passation du chantier « Convergence ScoutMagic ». Écrit pour
la session Claude qui reprendra le travail. À SUPPRIMER quand le chantier
sera clos — c'est un document de chantier, pas de la documentation
produit. Les trois premières sessions sont mergées sur `main` (PR #38) ;
la quatrième est sur `claude/refactor-ux-analysis-dc4itj`.

Références :

- Rapport d'analyse et d'avancement (révision 6) :
  https://claude.ai/code/artifact/72d1ffad-0d11-4aab-a2e0-a0ff083cec0f
- Rapport précédent (révision 3, fiches détaillées des 17 bloquants) :
  artifact « Revue UX ScoutMagic » du même compte.
- Conventions du chantier : `design.md` §7.1 à §7.11 (écrit par ce chantier) ;
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

10. **Emails de décision (location)** : automatiques, avec un mot libre
    facultatif du gestionnaire. Ni silencieux, ni derrière une case à
    cocher.
11. **Jeton de suivi (location)** : stocké **chiffré**, pas haché. Pas de
    migration des données — l'instance de test n'a que des biens qui
    seront supprimés.
12. **Aide au test** : un plan de test manuel
    (`docs/plan-de-test-manuel.md`), pas de passe de captures d'écran.

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

### Troisième session — les trous fonctionnels

Priorité choisie par Xavier : **les trous fonctionnels**, pas plus de
refactoring. Les quatre sont faits, les trois arbitrages ouverts sont
tranchés, et un plan de test manuel existe
(`docs/plan-de-test-manuel.md`).

- **Le dialogue partagé sort du gabarit et apprend à porter un mot.** Le
  gestionnaire `data-confirm` délégué était un `<script>` inline dans
  `base.html.twig` — dernière copie d'un bloc qui avait déjà existé à
  l'identique dans trois gabarits et **manqué** dans un quatrième. Il est
  dans `confirm.js`, donc testable (42 tests au lieu de 27), avec une
  garde contre un double chargement. Nouveau : `data-confirm-note`
  (+ `data-confirm-note-name`, défaut `message`) affiche un `<textarea>`
  sous la question et poste ce qui y est écrit ; `prompt()` a gagné
  `multiline` (Entrée passe à la ligne au lieu de valider) et `label`
  (un vrai `<label for>` au lieu d'un `aria-labelledby` sur le corps).
- **Location — les décisions atteignent le locataire.** `Booking\RenterDecision`
  énumère ce que quelqu'un a *décidé* (jamais `BookingStatus` : une option
  qui expire à 4 h du matin ne doit écrire à personne), et porte sa
  question, sa phrase d'annonce, son libellé de champ et le fait de porter
  ou non le lien de suivi. `RentalBookingMailService::sendDecision()` +
  `views/email/decision.{html,text}.twig`. Un envoi raté est signalé dans
  le bandeau et **n'annule jamais** la décision déjà enregistrée.
- **Le jeton de suivi est chiffré, plus haché.** Décision de Xavier
  (option 1, sans migration). Un hash ne répond qu'à « est-ce le jeton ? »,
  et chaque email de décision doit répondre à « quel est le lien ? ». Le
  coût est écrit dans `schema.sql` : la colonne résiste à une copie de base
  prise **sans** la clé applicative, plus à une copie prise avec — là où
  toutes les autres colonnes d'identité de cette table étaient déjà.
  `drops.sql` retire `tracking_token_hash`. Les réservations antérieures
  gardent un lien mort ; le correctif est « Régénérer le lien de suivi ».
- **Inscription en POST-redirect-GET** (`/inscriptions/envoyee`, reçu en
  session via `Service\RegistrationSubmissionReceipt`, valable 30 min, **non
  consommé à la lecture** pour qu'un F5 reconfirme).
- **Décompte avant envoi groupé** : `MassMailService::estimateRecipientCount()`
  + `GET /mass-mail/{id}/recipient-count`, demandé **au moment de la
  question** (la liste est vivante). Compte des personnes, pas des adresses.
- **Effectif d'un groupe sur les cartes** : union « invités ∪ dérivés des
  sections », comptée une fois, deux requêtes pour toute la page
  (`SectionMembershipRepository::findMemberIdsBySection()`,
  `GroupMemberRepository::findMemberIdsByGroups()`). Un groupe archivé garde
  l'effectif de **son** année.
- **Les trois arbitrages** (points 4, 5 et 6 de l'ancienne liste) sont
  fermés : bouton explicite sur `#default-number-member` ;
  `writeFailureMessage()` relatif à la racine et sans `error_get_last()`,
  donc `UpdateException` est maintenant marquée `UserFacingException` ;
  l'erreur technique du SDK S3 passe par la session
  (`Service\S3TestFailure`) au lieu de transiter par le navigateur — ce qui
  retire au passage une chaîne fournie par le navigateur d'un prompt de
  modèle.
- **Bug d'échappement trouvé en écrivant les gabarits d'email** : tous les
  `*.text.twig` du dépôt étaient rendus avec l'auto-échappement HTML. Rien
  ne re-transforme ces entités dans une partie `text/plain`, donc un
  locataire nommé O'Brien lisait « Bonjour O&#039;Brien », et
  « c&#039;est votre seul accès » était dans l'accusé de réception de
  **chaque** demande de location. `TwigFactory` choisit désormais la
  stratégie par nom de fichier ; épinglé par
  `tests/Core/View/PlainTextEmailEscapingTest.php`.
- **Un test de bout en bout passait pour la mauvaise raison** : la spec
  location cliquait « Confirmée » puis vérifiait que `/Confirmée/` était
  visible — ce qui décrit le bouton qu'elle venait de presser. Elle a
  continué à passer quand le clic s'est mis à ouvrir un dialogue au lieu
  de soumettre. Elle répond maintenant au dialogue, y écrit le mot, et
  vérifie le bandeau.
- **Validation complète** : 9 030 tests PHP / 24 994 assertions,
  1 173 tests JS / 62 fichiers, 38 scénarios Playwright, PHPStan `[OK]`,
  typecheck 0 nouveau.

### Quatrième session — la fin du chantier de convergence

Les trois points de l'ancienne liste « À FAIRE » sont faits.

- **Plus un seul `<script>` de comportement dans un gabarit.** Les 26
  gabarits qui en portaient encore sont vidés (16 nouveaux fichiers
  testés : account-passkeys, banner-config, calendar-public,
  finance-dashboard, password-reset, registration-passage,
  registration-departures, registration-public-form, mass-mail-tracking,
  config-modules, admin-scout-year, finance-import-form, mail-sandbox,
  plus les trois socles ci-dessous). Deux confirmations sont devenues
  `data-confirm` sur le formulaire, donc leurs pages n'ont plus de JS du
  tout.
- **`NATIVE_DIALOG_ALLOWLIST` est VIDE.** 91 boîtes natives au départ, zéro
  aujourd'hui, nulle part.
- **Nouveau verrou `testBehaviourLivesInFilesNotInTemplates`** : un gabarit
  ne peut plus porter de `<script>` de comportement. Deux exceptions
  documentées (l'amorce de thème anti-FOUC et l'enregistrement du service
  worker, toutes deux dans base.html.twig).
- **Les données serveur sont des îlots `<script type="application/json">`**,
  lus par `ScoutMagicApi.pageData(id)`. Sept pages posaient un
  `window.xxxData = {…}` inline ; `window-globals.d.ts` perd sept
  déclarations.
- **Quatre socles partagés de plus** : `pdf-thumbnail.js` (le repli d'une
  vignette PDF absente, qui existait en trois exemplaires),
  `reveal-details.js` (« cliquer une ligne pour voir son détail », trois
  exemplaires, désormais piloté par `data-reveal`), `drop-zone.js` +
  `partials/drop_zone.html.twig` (trois zones de dépôt), `sortable.js`
  (trois réordonnancements par glisser-déposer), et `scroll-into-focus.js`.
- **`form_field` : 20 → 47 gabarits, 155 inclusions.** Le partial a gagné
  ce qui bloquait la migration : `field_name` facultatif, `data: {…}` sur
  le contrôle ET sur une `<option>`, `wrapper_class`,
  `label_visually_hidden`, `control_class_extra`, et le type `password`.
- **Un défaut trouvé au passage** : la grille du réordonnancement
  enregistrait sur le `drop` de l'élément dans un cas sur trois — or `drop`
  ne se déclenche que si le pointeur est relâché SUR un voisin, donc
  relâcher juste à côté laissait la liste réordonnée à l'écran et le
  serveur dans l'ignorance. Tout passe par `dragend`.
- **Quatre scénarios de bout en bout mis à jour**, qui ont trouvé ce
  qu'aucun test unitaire ne pouvait voir : un dialogue du site est
  invisible à Playwright en tant que dialogue, et un état vide masqué
  n'est pas un état vide absent.

## À FAIRE

1. **La queue de la migration `form_field`** — 228 contrôles bruts dans
   72 gabarits, dont plus aucun n'en a plus de dix. Les plus gros restants
   sont les fiches de location (`rental/views/management/*`), le
   constructeur de formulaires d'actualité
   (`news/views/partials/_field.html.twig`, qui construit ses champs en
   JavaScript) et `config/maintenance`. Hors de portée, avec la raison :
   `setup/index.html.twig` (l'installateur rend avant que le thème
   existe), un libellé qui porte du balisage (« Tapez **EFFACER** pour
   confirmer »), et un `<select>` à `<optgroup>`.
2. **Toujours ouverts, hors périmètre** : extractions IA des reçus ;
   prérequis FTP de la maintenance ; « Requis par : … » sur les cartes de
   modules ; luminance sur couleurs configurables ; conventions d'URL
   (dette assumée).

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
- **Un `{% for %}` Twig tolère `null`, un `|map` non.** Remplacer une
  boucle par `options: xs|map(...)` casse tout rendu qui ne passe pas la
  variable (un test de gabarit qui rend avec un contexte partiel, par
  exemple) : écrire `xs|default([])|map(...)`.
- **Une expression Twig dans un attribut est échappée.**
  `<div{{ cond ? ' class="x"' : '' }}>` rend `class=&quot;x&quot;` —
  écrire le balisage avec `{% if %}`.
- **`aria-checked` des `role="switch"` est SYNCHRONISÉ par nav.js**
  (`ScoutMagicNav.syncSwitchAriaChecked`) : ne pas les supprimer —
  `tests/Core/View/SwitchAriaCheckedTest.php` les épingle.
- **Titres de modales** : l'embed `partials/modal.html.twig` impose l'id
  `{id}-title` — tout JS qui remplit un titre doit viser cet id.
- **empty_state/page_header** : quand page_header porte déjà l'action de
  création, l'empty_state n'en reçoit pas (un seul btn-primary par écran).
- **Twig `|default(true)`** rend true pour `false` — utiliser
  `x is defined ? x : true` pour un booléen paramétrable.
- **Le partial `form_field` émet maintenant des `data-*`** (sur le
  contrôle et sur une `<option>`), et `field_name` y est facultatif. Ce
  qui reste hors de portée : un libellé qui porte du balisage, et un
  `<select>` à `<optgroup>`.
- **`getByLabel(..., { exact: true })` casse quand un champ devient
  `required`** : le partial ajoute un « * » visible dans le libellé. Les
  specs concernées utilisent maintenant `getByLabel(/^Adresse email \*$/)`.
- Les montants dans des `value=` d'inputs parsés (JS ou serveur) ne passent
  PAS par `|money`.
- **Une modale vole le focus, et un `contenteditable` qui perd le focus perd
  sa sélection.** Tout dialogue ouvert depuis un éditeur de texte riche doit
  capturer la `Range` avant et la restaurer après (`rich-text-link.js` le
  fait ; c'est la partie subtile de ce fichier).
- **Un test de bout en bout peut passer pour la mauvaise raison.**
  `expect(page.getByText(/Confirmée/))` après un clic sur un bouton
  intitulé « Confirmée » ne prouve rien : il décrit le bouton. Quand un
  clic change de nature (soumission → dialogue), ce genre d'assertion
  continue à passer sur une action qui n'a plus lieu. Vérifier le
  **bandeau** ou l'**état en base**, jamais un texte que la page portait
  déjà avant le geste.
- **Les `*.text.twig` ne sont plus auto-échappés** (`TwigFactory` choisit
  la stratégie par nom de fichier). Un nouveau gabarit d'email en texte
  brut n'a donc PAS besoin de `|raw` — et ne doit pas en avoir.
- **Playwright dans ce conteneur** : `E2E_CHROMIUM_EXECUTABLE=/opt/pw-browsers/chromium npm run e2e`.
  Sans ça, les 40 scénarios échouent tous en 2 ms sur
  « Executable doesn't exist » — ce n'est jamais une régression du code.
- **Remplacer une boîte native par le dialogue du site casse les
  scénarios de bout en bout qui y répondaient**, et l'échec accuse
  l'assertion, jamais le dialogue : Playwright ne VOIT pas la modale du
  site comme un dialogue, donc `page.on('dialog')` ne se déclenche
  jamais et le clic ne résout rien. Passer par
  `tests/e2e/support/confirm-dialog.js` (`autoConfirm`,
  `answerConfirmation`, `collectToasts`).
- **Un `<script>` d'un gabarit ne peut pas être testé**, et c'est la
  vraie raison de l'extraction : Vitest importe des fichiers, pas du
  rendu Twig. Le verrou est
  `UxConventionsTest::testBehaviourLivesInFilesNotInTemplates`.
