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
4. **Toujours ouverts, hors périmètre** : extractions IA des reçus ;
   prérequis FTP de la maintenance ; « Requis par : … » sur les cartes de
   modules ; luminance sur couleurs configurables ; conventions d'URL
   (dette assumée). Les autres points de la section E de la révision 3
   sont faits (troisième session).

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
  Sans ça, les 38 scénarios échouent tous en 2 ms sur
  « Executable doesn't exist » — ce n'est jamais une régression du code.
