# Chantier — Listes de diffusion : une page par liste, groupes de membres et groupes de contacts (#729)

Document de chantier. Il complète `AGENTS.md`, `ARCHITECTURE.md` (§8.71bis à
§8.71decies), `SECURITY.md` (§5), `design.md` (§1.4, §7) et `specifications.md`
(tableau des modules, §8), qui restent la source de vérité des règles. Il ne les
recopie pas. Le journal du chantier précédent sur ces listes,
`docs/chantiers/listes-de-diffusion.md`, explique pourquoi la résolution,
la désinscription et l'aller-retour Excel sont ce qu'ils sont : le relire avant
de toucher à ces trois sujets.

Maquette qui fait foi : `docs/chantiers/maquettes/listes-de-diffusion.html`
(onze écrans à 390 px, ouvrir dans un navigateur). Chaque zone y est étiquetée
**nouveau / modifié / inchangé** ; ce qui n'est pas dessiné ne change pas.

L'issue #729 était une série d'idées (« dynamique / statique », page dédiée).
L'analyse ci-dessous, les décisions du §2 et la maquette ont été **validées par
le mainteneur** : le chantier est prêt à implémenter. Là où le texte de l'issue
diffère de ce document, **ce document l'emporte**.

---

## 0. Règles de conduite

- **Autonomie complète.** Enchaîner les lots sans demander de validation. Ne
  poser une question au mainteneur (commentaire sur #729) que pour un vrai
  changement de design ou une ambiguïté fonctionnelle que ce document et la
  maquette ne tranchent pas. Une décision du §2 ne se rediscute pas.
- **Un lot = une branche = une PR.** Rebaser sur `main` avant la fusion. Suite
  complète (PHPUnit sur MariaDB, Vitest, PHPStan, `npm run typecheck`,
  Playwright si une page change). **Fusionner et pousser sur `main` dès que tout
  est vert, sans demander de confirmation.** Jamais de fusion rouge, jamais de
  baseline élargie.
- **Lots d'une même vague : les lancer réellement en parallèle** (un agent et
  une branche par lot). Le second à fusionner rebase sur le premier.
- **Documentation dans le même lot** que le code qu'elle décrit
  (`ARCHITECTURE.md`, `specifications.md`, aide `modules/mass_mail/help/*`). Le
  lot E consolide, il ne rattrape pas.
- **Journal** : tenir à jour le §6 (une ligne par lot fusionné).
- **Le site est en test** : schéma modifié directement dans
  `modules/mass_mail/schema.sql`, tables retirées par `DROP TABLE` dans
  `modules/mass_mail/drops.sql` (le runner l'accepte : `MigrationRunner::
  applyExplicitDrops()`). **Aucune conversion** des listes personnalisées
  existantes : leurs critères et leurs adresses disparaissent avec les anciennes
  tables, la liste elle-même (nom, description, état, e-mails qui la citent)
  survit, vide. Aucun texte d'interface ne parle d'un état antérieur.
- Code en anglais, interface en français. Tout bouton qui agit immédiatement
  passe par une confirmation qui dit la conséquence **et si un e-mail part ou
  non** (`ScoutMagicConfirm.ask()` / `data-confirm`). Un bouton de suppression
  est une icône seule.

---

## 1. État des lieux (vérifié dans le code au commit `69eaeb4`)

**Modèle.** Une liste personnalisée (`mass_mail_lists`) porte **un seul** jeu de
critères sur trois jonctions clés par `list_id` (`mass_mail_list_functions`,
`_sections`, `_badges` — ET entre axes, OU dedans, axe vide non contraignant,
trois axes vides = personne) et des adresses propres
(`mass_mail_list_addresses`, chiffrées, index aveugle, unique par
`(list_id, email_blind_index)`). Résolution = union des deux, dédoublonnée sur
l'adresse (`MailingListService` L477 `case 'custom'` →
`MemberResolutionRepository::resolveCustomList()` L128, puis
`resolveListAddresses()` L561 et la fusion L582/L678).

**Écran.** `/admin/listes-de-diffusion` (`MailingListController::index()`,
`views/mailing_lists.html.twig`) liste les listes ; tout se modifie dans **un**
dialogue (`#cfg-list-modal`) qui mélange deux modes d'enregistrement : nom,
description et critères attendent « Enregistrer », les adresses s'écrivent
aussitôt (`mass-mail-list-addresses.js`, 581 lignes). Problèmes constatés :

1. « Annuler » n'annule pas une adresse ajoutée.
2. Un sélecteur vide affiche « Toutes les fonctions » alors que trois sélecteurs
   vides ne désignent personne.
3. Une liste faite uniquement d'adresses est impossible sans un critère qui ne
   désigne personne (limite notée au journal, IT-03).
4. Une nouvelle liste doit être enregistrée avant de recevoir des adresses.
5. Impossible d'écrire « les animateurs **ou** le trésorier ».

**Envoi.** La page d'un e-mail (`views/compose.html.twig`,
`mass-mail-compose.js`) fait choisir une ou plusieurs années
(`#mm-scout-year-zone`, `.mm-year-checkbox`). Les critères sont résolus pour ces
années ; les adresses propres sont prises telles quelles. Le gel
(`MassMailService::freezeListRecipients()` L920) passe en deux temps : membres,
puis adresses (`freezeListAddressRecipient()` L1044) dédoublonnées contre les
membres.

**Désinscription.** Globale à l'unité : `UnsubscribeController::unsubscribe()`
→ `ListAddressService::unsubscribeEverywhere()` →
`ListAddressRepository::unsubscribeEverywhere()` L182, plus
`mass_mail_suppressed_addresses`. Une adresse désinscrite survit à tout
remplacement Excel (`replaceForList()` L205, `filterSuppressed()`).

**Garde des badges.** `Core\Badge\BadgeService::delete()` interroge
`Core\Module\BadgeUsageProvider`, implémenté par
`Service\MailingListBadgeUsageService` sur `findReferencedBadgeIds()`.

**Routes** (`module.json` L258–366, toutes `admin`, `MailingListController`) :
`index`, `previewCount`, `createList`, `updateList`, `addresses`, `addAddress`,
`exportAddresses`, `importAddresses`, `confirmAddressImport`, `deleteAddress`,
`toggleList`, `deleteList`. Elles apparaissent aussi dans
`tests/dast/authz-fixtures.json` (L45, L48), `scripts/perf/urls-admin.txt`
(L91) et l'allowlist « pas une page » de `tests/Core/View/UxConventionsTest.php`
(L647, L650). `MailingListRbacTest` lit `module.json` : une route ajoutée arrive
avec sa couverture.

**Ailleurs.** `core/View/RgpdContentService.php` (règle 10bis, « Adresses
propres à une liste », vers L555–566) ; le jeu de données de référence ne crée
aucune liste ; aucune spec Playwright ne couvre `/admin/listes-de-diffusion`.

---

## 2. Décisions verrouillées

### 2.1 Une liste est une suite de blocs, de deux sortes

- Une liste personnalisée contient **0 à N blocs**, additionnés (union). Pas
  d'exclusion, pas d'ordre : les blocs s'affichent rangés par sorte, groupes de
  membres d'abord, puis par date de création. **Aucune limite de nombre par
  sorte.**
- **Groupe de membres** : un jeu de critères fonctions × sections × badges, avec
  **exactement la sémantique actuelle** (ET entre axes, OU dedans, axe vide non
  contraignant). **Au moins un choix** est exigé, côté serveur comme à l'écran :
  un groupe ne peut plus désigner « personne » ni « tout le monde » par
  accident. Il n'a pas de nom : sa phrase le décrit.
- **Groupe de contacts** : un **nom** obligatoire (150 caractères) et un
  ensemble d'adresses externes (nom + adresse), **modifiable uniquement par
  fichier Excel**. Pas d'ajout, de correction ni de retrait d'une adresse à
  l'unité.
- **Écarté** : bloc « membre » choisi par son nom, bloc « contact » unique.
  Raison : la règle d'année (§2.3) tient en une phrase, et une adresse se
  rajoute en ajoutant une ligne au fichier.

### 2.2 Modèle de données

- Nouvelle table `mass_mail_list_blocks` : `id`, `list_id` (FK
  `mass_mail_lists` `ON DELETE CASCADE`), `kind` ENUM(`member_group`,
  `contact_group`), `name` VARCHAR(150) NULL (obligatoire pour `contact_group`,
  NULL pour `member_group` — vérifié par le service), `file_replaced_at`
  DATETIME NULL (affiché « fichier remplacé le … »), `created_at`, `created_by`.
- Critères : `mass_mail_block_functions`, `mass_mail_block_sections`,
  `mass_mail_block_badges`, clés `(block_id, …)`, mêmes FK et mêmes commentaires
  que les anciennes jonctions (le paragraphe sur la cascade des badges est
  repris tel quel, adapté).
- Contacts : `mass_mail_block_contacts` — même forme que
  `mass_mail_list_addresses` mais clée par `block_id` : unique
  `(block_id, email_blind_index)`, index `email_blind_index`, chiffrement et
  déchiffrement **uniquement** dans le repository (SECURITY §5).
- `drops.sql` : `DROP TABLE` des quatre anciennes tables
  (`mass_mail_list_functions`, `_sections`, `_badges`, `_addresses`).
- Les classes suivent : `ListBlock` / `ListBlockRepository`,
  `ContactRepository` (ex-`ListAddressRepository`, ses méthodes passent de
  `list_id` à `block_id`), `ContactGroupImportService` (ex-
  `ListAddressImportService`, mêmes trois moitiés `export` / `analyse` /
  `apply`). Ne pas garder de classe ou de méthode sans appelant.
- Réglage `mass_mail_list_addresses_max` : **conservé tel quel (clé et
  défaut 2000)**, appliqué **par groupe de contacts** ; libellé « Contacts par
  groupe (maximum) », description réécrite en conséquence.

### 2.3 Résolution et envoi

- Résoudre une liste = union de la résolution de chaque groupe de membres
  (`resolveCustomList()` inchangé, appelé avec les critères **du bloc**) et des
  contacts actifs de chaque groupe de contacts, dédoublonnée sur l'adresse comme
  aujourd'hui (`deduplicateByMemberAndAddress()`).
- **Les années choisies sur la page de l'e-mail s'appliquent aux groupes de
  membres ; les groupes de contacts sont toujours inclus**, quelle que soit
  l'année. C'est déjà le comportement actuel : il devient explicite.
- Le gel garde ses deux passes : membres de tous les groupes de membres, puis
  contacts de tous les groupes de contacts, dédoublonnés contre ce qui est déjà
  écrit. Un contact présent dans deux groupes n'est écrit qu'une fois.
- Une liste sans aucun bloc est valide et ne désigne personne ; l'envoi la
  traite comme une liste qui résout zéro destinataire aujourd'hui.

### 2.4 Désinscription

- Inchangée sur le fond : globale à l'unité. `unsubscribeEverywhere()` marque
  toutes les lignes de `mass_mail_block_contacts` portant le même index aveugle,
  et la ligne de `mass_mail_suppressed_addresses` est toujours écrite.
- Un remplacement Excel ne retire **jamais** une adresse désinscrite et ne la
  réabonne jamais ; une adresse supprimée ailleurs arrive désinscrite
  (`filterSuppressed()`), comme aujourd'hui.
- Retirer un groupe de contacts supprime ses lignes : les désinscriptions
  restent vraies via `mass_mail_suppressed_addresses`. Le dialogue de
  confirmation le dit (maquette, écran 9).

### 2.5 Écrans (maquette)

- **`/admin/listes-de-diffusion`** (écran 1) : ne modifie plus rien. Une carte
  par liste personnalisée, qui mène à sa page : nom, description, résumé
  « 2 groupes de membres · 1 groupe de contacts », « ≈ N destinataires en
  {année effective} ». Activer/désactiver et supprimer restent sur la carte
  (icônes, confirmation, règle « une liste citée par un e-mail se désactive, ne
  se supprime pas » inchangée). « Nouvelle liste » ouvre un dialogue nom +
  description (écran 2) ; « Enregistrer » crée la liste et **ouvre sa page**.
- **`/admin/listes-de-diffusion/{id}`** (écrans 3 et 5), `role_min: admin`, même
  `menu` que la page des listes, fil d'Ariane complet par `breadcrumb_trail`
  (« Listes de diffusion / {nom} ») :
  - en-tête : nom, description, crayon qui rouvre le dialogue de l'écran 2 ;
  - encadré du total : « N destinataires en {année effective} », la ventilation
    membres / contacts, la phrase sur le dédoublonnage, le lien « Voir les
    destinataires » ;
  - rubriques « Groupes de membres » et « Groupes de contacts » (une rubrique
    vide n'est pas affichée), chaque bloc en une ligne cliquable qui rouvre son
    dialogue, avec sa corbeille ;
  - un bouton « Ajouter des destinataires » qui ouvre le choix de la sorte
    (écran 4). Liste vide : l'encadré dit « 0 destinataire » et le bouton est le
    seul contenu (écran 3).
- **Dialogue Groupe de membres** (écran 6) : les trois `select_bar` en
  `mode: 'multi'` d'aujourd'hui, `none_selected_text: 'Peu importe'`, la phrase
  et le compteur en direct (route `preview-count` conservée, séquencée sur un
  jeton comme aujourd'hui). « Enregistrer » désactivé tant qu'aucun choix n'est
  fait. Les éléments désactivés ou retirés qu'un groupe nomme encore restent
  proposés, suffixés (règle du journal IT-02).
- **Dialogue Groupe de contacts** (écrans 7 et 8) : nom ; à la création, un
  fichier obligatoire ; ensuite, la liste des contacts en lecture seule
  (recherche, « Désinscrites seulement », tranches de 50 — repris de
  `mass-mail-list-addresses.js`), « Exporter en Excel » et « Choisir un fichier
  corrigé ». Le fichier est **analysé dès qu'il est choisi** et l'aperçu chiffré
  s'affiche (ajoutés, retirés, inchangés, doublons, lignes refusées) ; **rien
  n'est écrit avant « Enregistrer »**, qui applique ensemble le nom et le
  fichier ; « Annuler » abandonne les deux. Lien « Télécharger un modèle vide »
  (l'export d'un groupe vide).
- **Tous les dialogues se terminent par « Annuler » et « Enregistrer ».** Seul
  le retrait d'un bloc se fait depuis la page : corbeille → confirmation
  « Retirer ce groupe de membres / de contacts ? » qui nomme le bloc, dit ce
  qu'il désignait, que les e-mails déjà envoyés ne changent pas et qu'**aucun
  e-mail n'est envoyé** (écran 9).
- **Voir les destinataires** (écran 10) : dialogue en lecture seule, la liste
  résolue et dédoublonnée comme l'envoi, pour l'année effective, avec un
  sélecteur limité aux années que la page d'un e-mail propose ; chaque ligne
  nomme le ou les blocs qui l'apportent ; recherche. Ni adresse désinscrite ni
  membre sans adresse.
- **Page d'un e-mail** (écran 11) : une phrase sous les cases d'années, selon la
  liste personnalisée choisie : groupes des deux sortes → « Les années choisies
  s'appliquent aux groupes de membres de cette liste. Ses groupes de contacts
  sont toujours inclus. » ; seulement des groupes de contacts → « Cette liste ne
  contient que des contacts : l'année ne change rien. » ; seulement des groupes
  de membres ou aucun bloc → rien. Le reste de la page est inchangé.

### 2.6 Écarté

- Bloc « membre » unique, bloc « contact » unique, ajout ou correction d'une
  adresse à l'unité.
- Exclusions (« sauf … »), réordonnancement des blocs, carnet d'adresses partagé
  entre listes.
- Année portée par une liste ou par un bloc : l'année reste choisie à l'envoi.
- Conversion des listes existantes.
- Afficher la description d'une liste sur la page d'un e-mail (elle n'y est pas
  aujourd'hui) : hors périmètre, à proposer dans une issue séparée si utile.

---

## 3. Lots et ordre d'exécution

Trois vagues, **une PR par lot**. Les lots d'une vague tournent **en
parallèle** — les lancer en même temps.

```
Vague 1 :              [A Modèle, résolution, envoi]
                          │          │          │
Vague 2 :  [B Pages et groupe de membres]  [C Groupe de contacts]  [D Page d'un e-mail]
                          └──────────┬──────────┘                      │
Vague 3 :                 [E Bout en bout, aide, docs, clôture] ◄──────┘
```

**Entre A et B, l'édition des listes est indisponible** (A retire l'ancien
dialogue et ses routes) ; l'envoi, lui, fonctionne. Accepté : le site est en
test et B suit immédiatement.

**Propriété des fichiers partagés en vague 2.**

- B possède `views/mailing_lists.html.twig`, la nouvelle vue de la page d'une
  liste (`views/mailing_list.html.twig`), `public/assets/js/mass-mail-lists.js`,
  le dialogue de choix de sorte, le dialogue Groupe de membres, la confirmation
  de retrait et « Voir les destinataires ».
- C possède le partiel `views/partials/_contact_group_dialog.html.twig`,
  `public/assets/js/mass-mail-contact-groups.js` (qui remplace
  `mass-mail-list-addresses.js`) et les routes des groupes de contacts.
- **Contrat entre B et C** : la page d'une liste rend, pour ouvrir le dialogue,
  un élément `[data-contact-group-open]` (création, sans `data-block-id`) et,
  par groupe de contacts, une ligne `[data-contact-group-open][data-block-id]`.
  Après un enregistrement, le script de C émet sur `document` l'événement
  `mass-mail:blocks-changed` ; le script de B l'écoute et recharge les blocs et
  le total. **Le second des deux à fusionner** ajoute l'`include` du partiel et
  la balise `<script>` dans la vue de la page d'une liste.
- D ne touche qu'à `views/compose.html.twig`, `mass-mail-compose.js` et ce que
  `MassMailController::buildListOptions()` expose.

### Lot A — Modèle, résolution, envoi (vague 1)

- Schéma du §2.2 dans `modules/mass_mail/schema.sql` ; `drops.sql` ; version de
  `module.json` montée (le module change visiblement).
- `ListBlock`, `ListBlockRepository` (créer, lire par liste, supprimer,
  remplacer les critères d'un groupe de membres), `ContactRepository` (repris de
  `ListAddressRepository`, clé `block_id`), service des blocs avec la
  validation du §2.1 (au moins un choix ; nom obligatoire d'un groupe de
  contacts ; `kind` immuable).
- Résolution du §2.3 dans `MailingListService` (`case 'custom'`,
  `resolveListAddresses()` et la fusion) et `previewCount` ; compteurs par bloc
  et total dédoublonné pour une année donnée ; méthode qui rend la liste résolue
  avec, pour chaque destinataire, les blocs qui l'apportent (utilisée par
  « Voir les destinataires » en B).
- Gel en deux passes sur les blocs (`freezeListRecipients()`,
  `freezeListAddressRecipient()`), `SendBatchHandler` (L642–648),
  `unsubscribeEverywhere()` sur `mass_mail_block_contacts`,
  `MailingListBadgeUsageService` sur `mass_mail_block_badges`.
- `ContactGroupImportService` : `export` / `analyse` / `apply` repris tels
  quels, par bloc ; `apply` met `file_replaced_at` à jour ; plafond par groupe.
- Retirer l'ancien dialogue (`#cfg-list-modal`), `mass-mail-list-addresses.js`,
  et les routes `addresses` (GET/POST), `addresses/export`,
  `addresses/import`, `addresses/import/confirm`, `DELETE addresses/{id}`,
  `PATCH lists/{id}` avec leurs actions de contrôleur, leurs tests et leurs
  entrées dans `authz-fixtures.json` et `UxConventionsTest`. La page des listes
  reste affichée, en lecture seule, jusqu'à B.
- Câblage `public/index.php` (L9128, L9136, L9161, L9230, L11973).
- Tests : résolution (deux groupes de membres en union ; groupe de membres +
  groupe de contacts ; contact présent dans deux groupes ; contact égal à
  l'adresse Desk ou à la *deuxième* adresse d'un membre → un seul envoi ;
  désinscription propagée à tous les groupes ; année de l'envoi appliquée aux
  seuls groupes de membres ; liste sans bloc → zéro), validation (groupe de
  membres sans choix refusé, groupe de contacts sans nom refusé), garde des
  badges, import par bloc (les tests de `ListAddressImportServiceTest` et
  `ListAddressImportRoutesTest` portés), `MassMailTestHelper` mis à jour.
- Documentation : `ARCHITECTURE.md` §8.71septies à §8.71nonies réécrits sur le
  modèle des blocs ; `core/View/RgpdContentService.php` (règle 10bis :
  « groupes de contacts » au lieu d'« adresses propres à une liste »).

### Lot B — Pages et groupe de membres (vague 2, après A)

- Page des listes selon l'écran 1 ; dialogue Nouvelle liste / modifier le nom
  et la description (écran 2) ; route `GET /admin/listes-de-diffusion/{id}`
  (ou `/lists/{id}` si cela simplifie le routeur, à décider en autonomie et à
  noter au journal) et la page d'une liste (écrans 3 et 5) ; choix de la sorte
  (écran 4) ; dialogue Groupe de membres (écran 6) ; retrait d'un bloc
  (écran 9) ; « Voir les destinataires » (écran 10).
- Routes JSON des blocs de membres (créer, modifier, supprimer un bloc —
  la suppression sert aussi aux groupes de contacts) et route des destinataires.
  `MailingListRbacTest` les couvre par `module.json` ; ajouter les routes
  paramétrées à `tests/dast/authz-fixtures.json` et la page à
  `scripts/perf/urls-admin.txt`.
- Vitest (`tests/js/mass-mail-lists.test.js` réécrit) : choix de sorte, phrase
  « Peu importe », « Enregistrer » désactivé sans choix, compteur séquencé,
  confirmation de retrait qui dit qu'aucun e-mail ne part, rechargement sur
  `mass-mail:blocks-changed`.
- Aide : `listes-de-diffusion.md` réécrit (une page par liste, deux sortes de
  blocs, l'addition, la règle d'année) ; `paths` vers la nouvelle page.

### Lot C — Groupe de contacts (vague 2, après A)

- Partiel et script du dialogue (écrans 7 et 8) selon le contrat du §3 ;
  routes : créer un groupe (nom + fichier, analyse puis enregistrement),
  modifier (nom et/ou fichier, même aperçu), lister les contacts d'un groupe,
  exporter, modèle vide. L'analyse **n'écrit rien** et le fichier téléversé est
  supprimé dans un `finally` (règle actuelle) ; l'enregistrement revalide,
  redédoublonne et redemande le plafond, et refuse un corps sans tableau
  `contacts` (règles du journal IT-04).
- Vitest (`tests/js/mass-mail-contact-groups.test.js`, remplace
  `mass-mail-list-addresses.test.js`) : aperçu affiché au choix du fichier sans
  écriture, « Annuler » n'envoie rien, « Enregistrer » envoie nom et contacts
  ensemble, lignes refusées rendues en texte, recherche insensible aux accents,
  tranches de 50, désinscrites grisées, émission de `mass-mail:blocks-changed`.
- Aide : `adresses-de-liste.md` et `adresses-de-liste-excel.md` fusionnés en un
  sujet `groupes-de-contacts.md` (ajouter une personne = ajouter une ligne au
  fichier ; désinscription globale) ; liens entrants corrigés.

### Lot D — Page d'un e-mail (vague 2, après A)

- `buildListOptions()` expose, pour une liste personnalisée, la présence de
  groupes de membres et de groupes de contacts (`data-` sur l'option) ;
  `mass-mail-compose.js` affiche la phrase du §2.5 sous `#mm-scout-year-zone`,
  à côté des notes existantes par type de liste.
- Vitest (`tests/js/mass-mail-compose.test.js`) : les trois cas, et le retour à
  rien quand on choisit une liste par défaut.
- Aide : `envoi-de-mails.md`, paragraphe sur les listes personnalisées et
  l'année.

### Lot E — Bout en bout, aide et clôture (vague 3)

- Playwright (nouvelle spec `tests/e2e/specs/mass-mail-lists.spec.js`) :
  créer une liste → page vide → ajouter un groupe de membres → ajouter un groupe
  de contacts par fichier (aperçu, Annuler sans écriture, puis Enregistrer) →
  total → voir les destinataires → retirer un bloc avec confirmation → écrire un
  e-mail avec cette liste et vérifier la phrase d'année.
- Jeu de données de référence : une liste « Staff élargi » (deux groupes de
  membres, un groupe de contacts depuis un fichier commité) et une liste
  « Partenaires locaux » (un groupe de contacts seul), créées par les vrais
  services.
- Documentation : `specifications.md` (ligne « Listes de diffusion » et §8),
  relecture de `ARCHITECTURE.md` §8.71bis à §8.71decies, des sujets d'aide du
  module (`anciens.md`, `envoi-de-mails.md`, `listes-de-diffusion.md`,
  `groupes-de-contacts.md`, `se-desinscrire.md`), et une section « Chantier
  #729 » ajoutée à la fin de `docs/chantiers/listes-de-diffusion.md` qui renvoie
  vers ce document.
- Ligne de la maquette dans `docs/chantiers/maquettes/README.md` passée à
  « livré ».
- **Commenter #729** avec ce qui a été livré (lots, PR, écarts), **puis la
  clôturer**.

---

## 4. Pièges

- Un axe vide **ne contraint pas** ; c'est le groupe sans **aucun** choix qui est
  refusé. Ne pas réintroduire « au moins une fonction ET une section ».
- Les sélecteurs sont le seul endroit où les critères d'un groupe font
  l'aller-retour : un identifiant désactivé ou retiré que le groupe nomme encore
  doit rester proposé, sinon n'importe quelle modification l'efface et le groupe
  **s'élargit** en silence (journal IT-02).
- Le compteur dédoublonne comme l'envoi ; deux frères et sœurs sur une adresse
  familiale font **un** destinataire.
- Pas d'`ORDER BY` sur du chiffré : trier les contacts en PHP, dans le
  repository.
- Une adresse désinscrite n'est jamais retirée ni réabonnée par un fichier, et
  toute désinscription écrit `mass_mail_suppressed_addresses`.
- `IN ()` est refusé par MySQL et MariaDB mais toléré par SQLite : garder chaque
  liste d'identifiants vide avant la requête et tester avec `InstrumentedPdo`
  quand le cas compte.
- Le dialogue de groupe de contacts reçoit des réponses lentes (déchiffrement) :
  séquencer sur une génération comme aujourd'hui, pour qu'une réponse ne
  s'affiche jamais dans le dialogue d'un autre groupe.
- `UxConventionsTest` : confirmations, icônes du jeu vendu, pas de script dans
  les gabarits, allowlist « pas une page » à jour.
- Jamais de lecture d'une table `mass_mail_*` hors du module.

---

## 5. Critères d'acceptation

1. Une liste peut contenir autant de groupes de membres et de groupes de
   contacts qu'on veut, et rien d'autre ; elle résout l'union dédoublonnée de
   ses blocs.
2. Un groupe de membres sans aucun choix est refusé par le serveur ; un axe vide
   se lit « Peu importe ».
3. Un groupe de contacts ne se modifie que par fichier ; rien n'est écrit avant
   « Enregistrer » ; « Annuler » n'écrit rien.
4. À l'envoi, les années choisies changent les membres et jamais les contacts ;
   la page d'un e-mail le dit selon les trois cas du §2.5.
5. Une désinscription reste globale à l'unité et survit à tout fichier et au
   retrait d'un groupe.
6. Page des listes, page d'une liste et dialogues conformes à la maquette ;
   aide et documentation à jour ; suites vertes ; #729 commentée puis clôturée.

---

## 6. Journal du chantier

| Lot | PR | Livré | Écarts / notes |
|---|---|---|---|
| A — Modèle, résolution, envoi | #982 | Schéma des blocs (`mass_mail_list_blocks`, `mass_mail_block_functions/_sections/_badges`, `mass_mail_block_contacts`) et `DROP TABLE` des quatre anciennes ; `ListBlock`/`ListBlockRepository`, `Contact`/`ContactRepository`, `ListBlockService` (validation §2.1), `ContactGroupImportService` (`export`/`analyse`/`apply` par groupe, `createGroup`/`updateGroup`, plafond par groupe) ; résolution par blocs, `summariseList()` et `listRecipients()` dans `MailingListService` ; gel, désinscription et garde des badges sur les blocs ; ancien dialogue, `mass-mail-list-addresses.js` et routes d'adresses et `PATCH lists/{id}` retirés ; `ARCHITECTURE.md` §8.71septies–nonies et règle RGPD 10bis réécrites. | La page des listes reste en lecture seule (activer, désactiver, supprimer ; résumé par sorte de bloc) et n'a plus de bouton « Nouvelle liste » jusqu'à B. `ContactGroupImportService` n'est pas encore câblé dans `public/index.php` (aucune route ne l'appelle avant C). Les tests de routes d'import (`ListAddressImportRoutesTest`) sont retirés avec leurs routes : C les reporte sur ses routes. Les sujets d'aide `adresses-de-liste` et `adresses-de-liste-excel` sont retirés avec les commandes qu'ils décrivaient : C écrit `groupes-de-contacts.md` ; `listes-de-diffusion.md` décrit les blocs en attendant sa réécriture par B. |
| B — Pages et groupe de membres | #983 | Page des listes en cartes (résumé par sorte, « ≈ N destinataires » pour l'année effective, « Nouvelle liste » qui ouvre la page créée) ; page d'une liste `GET /admin/listes-de-diffusion/{id}` (total, rubriques par sorte, choix de la sorte, dialogue Groupe de membres, retrait d'un bloc, « Voir les destinataires ») ; routes `PATCH lists/{id}`, `GET lists/{id}/blocks`, `POST lists/{id}/member-groups`, `PATCH member-groups/{id}`, `DELETE blocks/{id}`, `GET lists/{id}/recipients?year=` ; phrase d'un groupe de membres construite côté serveur (`criteriaSentence()`, segments en gras) et renvoyée par `preview-count` ; `SendableScoutYears` extrait de `MassMailController` ; aide `listes-de-diffusion.md` réécrite ; ARCHITECTURE §8.71quinquies–sexies. | Chemin `/admin/listes-de-diffusion/{id}` retenu (le routeur limite `{id}` aux chiffres). Modification d'un groupe de membres sur `PATCH member-groups/{id}` (symétrique de `contact-groups/{id}` de C) plutôt que `blocks/{id}`. La page dessine blocs et total en JavaScript depuis un îlot JSON puis depuis `GET …/blocks` : un seul rendu. Le dialogue Groupe de contacts reste à brancher (include et `<script>` par le second de B/C à fusionner). `mass-mail.spec.js` échoue sur le lot d'envoi dans ce conteneur, aussi sur la base du lot A. |
| D — Page d'un e-mail | #984 | `MassMailController::buildListOptions()` expose `member_groups` / `contact_groups` (d'après `ListBlockService::countKindsByList()`, injecté en dernier argument du contrôleur) ; chaque option du sélecteur porte `data-member-groups` / `data-contact-groups` (« 1 » / « 0 ») ; deux phrases sous les cases d'années (`#mm-custom-list-mixed-note`, `#mm-custom-list-contacts-note`), rendues par le serveur pour la liste enregistrée et basculées par `mass-mail-compose.js` au changement de liste ; aide `envoi-de-mails.md` (paragraphe sur l'année et les groupes de contacts). | Les phrases vivent dans le gabarit, à côté des notes existantes par type de liste, plutôt que dans le script : le texte d'interface reste au même endroit que les autres notes et le premier affichage est juste sans JavaScript. Les listes par défaut et le publipostage portent « 0 » pour les deux attributs. |
