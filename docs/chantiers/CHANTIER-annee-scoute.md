# Chantier — Parcours « Année scoute » et années importables dans Import Desk (#930)

Document de chantier. Il complète `AGENTS.md`, `ARCHITECTURE.md` (§4, §7.4/§7.5,
§8.26, §8.38), `SECURITY.md`, `design.md` (§7) et `specifications.md` (§7, §16,
§19.2), qui restent la source de vérité des règles. Il ne les recopie pas.

Maquette qui fait foi : `docs/chantiers/maquettes/annee-scoute.html` (ouvrir dans
un navigateur ; la barre en pointillés du haut est propre à la maquette). Chaque
zone y est étiquetée **nouveau / modifié / inchangé** ; ce qui n'est pas dessiné
(en-tête du site, bannière de prévisualisation, menus, page d'historique des
imports, page barrière « Forcer l'import ») **ne change pas**.

L'issue #930 demandait une analyse, des maquettes et une validation du mainteneur
avant tout développement. Cette analyse, les décisions ci-dessous et la maquette
ont été **validées par le mainteneur** : le chantier est prêt à implémenter.

---

## 0. Règles de conduite

- **Autonomie complète.** Enchaîner les lots sans demander de validation. Ne
  poser une question au mainteneur (commentaire sur #930) que pour un vrai
  changement de design ou une ambiguïté fonctionnelle que ce document et la
  maquette ne tranchent pas. Une décision du §2 ne se rediscute pas.
- **Un lot = une branche = une PR.** Rebaser sur `main` avant la fusion. Lancer
  la suite complète (PHPUnit sur MariaDB, Vitest, PHPStan, `npm run typecheck`,
  Playwright si le lot touche une page). **Fusionner et pousser sur `main` dès
  que tout est vert, sans demander de confirmation** (`AGENTS.md` « Merging a
  pull request »). Jamais de fusion rouge, jamais de baseline élargie.
- **Lots parallèles** : les lancer réellement en parallèle (un agent / une
  branche par lot). Le second à fusionner rebase sur le premier.
- **Documentation dans le même lot** que le code qu'elle décrit :
  `specifications.md`, `ARCHITECTURE.md`, `design.md` si une convention bouge,
  `docs/help/*`. Le lot F consolide, il ne rattrape pas.
- **Journal** : tenir à jour le §6 de ce fichier (une ligne par lot fusionné :
  PR, ce qui a été livré, écarts).
- **Le site est en test** : pas de migration, schéma modifié directement dans
  `schema/core.sql` / `schema.sql` du module. Aucun texte d'interface ne parle
  d'un état antérieur (« l'import a changé », « désormais »…).
- Code et identifiants en anglais, interface en français. Lexique
  `design.md` §7.1 (« animateur », « Staff d'Unité », jamais « Desk » dans un
  écran de membre — ici les écrans sont pour le Staff d'U, « Desk » y est permis).

---

## 1. État des lieux (vérifié dans le code au commit `5aa2d20`)

**Page Année scoute** (`ScoutYearTransitionService::stepDefinitions()`,
`ScoutYearController`, `admin/scout_year.html.twig`, `admin-scout-year.js`)

- 14 étapes dans un ordre où l'import (8) vient **après** les cotisations (6) et
  la prévisualisation (7).
- « Fait » = signal observé OU coche. L'import se déclare fait sur
  `member_years > 0` (`MemberYearRepository::countMembers`), la prévisualisation
  sur la session.
- Tout est POST + redirection : chaque coche recharge la page.
- **Faille** : « une année staff doit exister avant la bascule publique » n'est
  vérifié **que dans l'interface** (`can_activate_public`) ; `activatePublic`
  côté serveur ne le contrôle pas. `activateStaff` ne vérifie que l'existence de
  l'année.
- Seul chemin d'écriture des années publique/staff : `ScoutYearAdminService`
  (appelé seulement par `ScoutYearController`). Le veto inscriptions y est déjà.
- Coches : `scout_year_transition_steps` (année cible, `step_key`, qui, quand).

**Import Desk** (`ImportController`, `DeskImportService`, `admin/import.html.twig`)

- L'année cible accepte **toute année existante** ; le seul contrôle est
  l'existence de l'année (`DeskImportService.php:310`).
- `import_journal` n'est écrit **que dans la transaction d'un import réussi** :
  « dernier import réussi de l'année X » = `ImportJournalRepository::findByYear(X)[0]`.
  Aucune colonne à ajouter.
- Le bloc « Dernier import » montre l'année publique en GET, et son état vide dit
  toujours « Aucun import pour l'année publique ».
- Chemins d'import : uniquement le formulaire web. Le rejeu du jeu de référence
  (`tests/fixtures/reference-dataset/DeskImportReplay.php`) instancie le service
  et importe volontairement dans des années historiques.
- Pas d'horloge injectable (`new \DateTimeImmutable()` dans le service,
  `AppClock` statique).
- `ConfigurationMode::isActive()` relit le rôle à chaque appel (session
  `_config_mode`).

**Ce que les modules exposent au cœur aujourd'hui**

| Besoin | Disponible | Manque |
|---|---|---|
| Passages sans destination | `Registration\Api\ScoutYearPreparationProvider` | détail par animé |
| Veto bascule | `Registration\Api\ScoutYearTransitionVetoProvider` | — |
| État de la campagne de réinscription | — (settings + `ReenrollmentCampaignService::timeline()`) | contrat `Api\` |
| Demandes en attente / acceptées sans section prévue | requêtes internes | contrat `Api\` + requête « sans `intended_section_id` » |
| Justesse des tarifs | `FeeAccuracyService::report($yearId)` | contrat `Api\` (le module n'en a aucun) |
| Responsable d'une section | crochet `Core\Module\SectionResponsableProvider` (Trombinoscope) | lecture pour une année donnée à vérifier |
| Événements par section | `Calendar\Api\SectionEventLookupInterface` | comptage par section sur une période |
| Badges par année | cœur (`member_badges` par `member_year_id`, `badges.referent_section_id`) | agrégat par section |
| Photo individuelle de l'année exacte | `MemberPhotoRepository::findFileIdForYearOrEarlier` ne dit pas l'année d'origine | méthode « année exacte / héritée / aucune » |
| Photo de groupe de l'année exacte | `SectionPhotoRepository::countSectionsWithPhotoForYear` | liste des sections manquantes |

---

## 2. Décisions verrouillées

### 2.1 Modèle des étapes

- Deux natures, affichées en **étiquettes** (pas en texte) :
  « Décision humaine », « Constaté par le site ». **Aucune étiquette de module.**
- États affichés : **À faire**, **Étape faite**, **À vérifier** (pastille
  `n à vérifier`, cumulable avec les deux premiers), **Bloquée**.
- Une étape humaine **peut être marquée faite malgré des points à vérifier** ;
  ils restent affichés à côté de « Étape faite ». Le responsable garde son
  jugement.
- **Seuls blocages durs** (tous vérifiés côté serveur, interface ET service) :
  1. Marquer l'étape 1 tant que la campagne de réinscription de l'année cible
     n'est pas terminée (§2.3).
  2. Activer l'année cible pour les staffs (étape 9) sans import réussi de
     l'année cible.
  3. Bascule publique (étape 14) si : l'année staff n'est pas l'année cible, OU
     aucun import réussi de l'année cible, OU le veto inscriptions (§19.2).
- Un contrôle **sans données** (avant l'import de l'année cible) n'est jamais
  une réussite : il affiche « Le contrôle sera disponible après un import
  réussi de {cible} » et ne produit aucun point à vérifier ni aucun « ✓ ».
- Coches par année cible, comme aujourd'hui (`scout_year_transition_steps`),
  **en gardant les `step_key` existants** (`departures`, `passage`,
  `confirm_registrations`, `desk_staff`, `desk_members`, `fees`, `preview`,
  `import`, `activate_staff`, `ephemerides`, `badges`, `trombinoscope`,
  `staff_photos`, `activate_public`). Pas de remise à zéro globale.

### 2.2 Les 14 étapes (ordre conseillé, numérotation recalculée)

| # | Clé | Nature | Présente si | Contrôle affiché |
|---|---|---|---|---|
| 1 | `departures` | humaine | module Inscriptions | état de la campagne (§2.3), nombre de partants annoncés ; blocage du marquage tant que la campagne n'est pas terminée |
| 2 | `passage` | humaine | Inscriptions | passages attendus, animés sans section d'arrivée (à vérifier) ; « Aucun passage à effectuer » si zéro |
| 3 | `confirm_registrations` | humaine | Inscriptions | demandes en attente (à vérifier), acceptées sans section prévue (à vérifier) ; rappel séparé du veto de bascule |
| 4 | `desk_staff` | humaine | toujours | après import : par section, nombre d'animateurs et responsable (§2.4) |
| 5 | `desk_members` | humaine | toujours | après import : écarts préparé ↔ importé (§2.5) |
| 6 | `import` | constatée | toujours | dernier import **réussi** de la cible (date, auteur, membres) depuis `import_journal` ; liens rapport / nouvel import |
| 7 | `fees` | humaine | module Cotisations | après import : foyers à corriger, membres non vérifiables (sans adresse), foyers mis de côté ; jamais factures ni paiements |
| 8 | `preview` | **humaine** (était constatée) | toujours | « Prévisualisation active dans votre session » à titre d'info, bouton prévisualiser/quitter ; la prévisualisation ne marque rien |
| 9 | `activate_staff` | constatée | toujours | blocage si pas d'import ; rappel non bloquant des demandes non clôturées |
| 10 | `ephemerides` | humaine | module Calendrier | **compteur par section** sur l'année cible ; seule une section à 0 est « à vérifier » |
| 11 | `badges` | humaine | toujours | §2.6 |
| 12 | `trombinoscope` | humaine | module Trombinoscope | §2.7 |
| 13 | `staff_photos` | humaine | toujours | sections sans photo de groupe **de l'année cible** (la reprise de l'an dernier ne compte pas) |
| 14 | `activate_public` | constatée | toujours | ouvre la revue avant bascule (§2.9) |

L'étape 13 n'est plus « faite » automatiquement : elle devient humaine, le
signal sert de point à vérifier (cohérent avec le §2.1). Les étapes 2 et 10
perdent de même leur « fait automatique ».

Sections contrôlées : sections **visibles** ayant des membres actifs dans
l'année cible. Staff d'U **exclu** du contrôle de responsable (étape 4),
**inclus** pour les photos individuelles et de groupe (12, 13) s'il apparaît sur
la page Staffs.

### 2.3 Campagne de réinscription « terminée » (étape 1)

Terminée = la campagne de l'année cible **a démarré** (date d'ouverture passée
ou ouverte à la main, `ReenrollmentCampaignService::hasStarted()`) **et n'est
plus ouverte** (fermée par la date ou à la main). Jamais déduit d'un compteur.

Programmée ou en cours → marquage refusé côté serveur, avec le lien vers la
campagne et un message propre à chaque cas :

- programmée : « La campagne de réinscription {cible} n'a pas encore commencé
  (ouverture le {date d'ouverture}). L'étape pourra être marquée une fois la
  campagne terminée. »
- en cours : « La campagne de réinscription {cible} est en cours (fermeture le
  {date de fermeture}). L'étape pourra être marquée une fois la campagne
  terminée. »

Une étape déjà marquée n'est pas démarquée si quelqu'un rouvre la campagne
ensuite (on ne réécrit pas une décision humaine) ; le point « campagne
rouverte » s'affiche alors à vérifier. Sans module Inscriptions, les étapes 1 à
3 disparaissent (la page Départs appartient au module).

### 2.4 Responsable de chaque staff (étape 4)

- Module Trombinoscope actif : chaque section contrôlée (hors Staff d'U) doit
  avoir au moins un animateur dont une fonction porte le drapeau
  « Responsable » **dans l'année cible**. Source : le crochet
  `SectionResponsableProvider` (étendu à une année explicite s'il ne la prend
  pas) — le cœur ne lit jamais `trombinoscope_function_flags`.
- Module inactif : seulement « au moins un animateur » par section, avec la
  mention « Responsable non vérifiable sans le module Trombinoscope ».
- Une section qui a des animés mais aucun animateur est à vérifier.

### 2.5 Écarts préparé ↔ importé (étape 5)

Après un import réussi de la cible, trois listes, données à titre d'aide :
1. animés annoncés partants (`member_years.leaving` de l'année publique) mais
   présents et actifs dans l'import cible ;
2. passages dont la section importée diffère de la destination choisie ;
3. **demandes d'inscription acceptées absentes de l'import cible**.

Pour le 3, réutiliser le rapprochement existant du module (statut `encoded`,
`MemberRegistrationOrigin`…) **sans en écrire un second** ; les bugs #890 et
#891 sont connus : si le rapprochement ne permet pas une réponse fiable pour un
cas, ne pas l'afficher plutôt que d'afficher un faux écart, et le noter au
journal du chantier.

### 2.6 Badges (étape 11)

Vue informative, pas de jugement « obligatoire » :
1. par section : badges attribués dans l'année cible, avec leurs titulaires ;
2. badges `is_default` attribués **dans aucune** section ;
3. badges transversaux (`referent_section_id IS NULL`) présents dans certaines
   sections et pas d'autres.

La section d'un badge transversal = la section du titulaire dans l'année cible.
Un badge référent (`referent_section_id` non nul) est rangé sous le **Staff
d'U** et ne crée jamais de « manque » dans la section qu'il désigne. **Pas de
comparaison avec l'année précédente** : lien vers les pages « Porteurs ».

### 2.7 Photos individuelles (étape 12)

Animateurs de l'année cible = rôle `chief`/`admin` avec une section dans
l'année cible (même règle que `TrombinoscopeRepository`). Par section :
total, photo de l'année cible, photo héritée d'une année antérieure, aucune
photo. « Aucune » et « héritée » sont **à vérifier**, jamais bloquants ; liste
nominative cliquable vers la page du membre dans le dialogue Détails.
Nouvelle méthode cœur qui dit, pour chaque membre, l'année de la photo
affichée (exacte / antérieure / aucune). Étape liée au module Trombinoscope.

### 2.8 Interaction de la page (maquette)

- **Le rond numéroté est le seul contrôle de marquage** des étapes humaines :
  un clic marque « Étape faite », un clic sur le rond vert remet à faire. Pas
  de case, pas de bouton « Marquer comme faite » ni « Remettre à faire ». Le
  rond porte `aria-pressed` et un `aria-label`/`title` qui disent l'effet. Pas
  de dialogue sur le rond (réversible d'un clic, `design.md` §7.5).
- Chaque étape montre sur la page **une seule action, la plus pertinente selon
  l'état** (ex. étape 9 sans import : « Importer {cible} » ; avec import :
  « Activer pour les staffs »), plus un lien **« Détails »** qui ouvre un
  **dialogue** (pas un bloc dépliable) contenant les contrôles, tableaux,
  personnes à corriger, qui a marqué et quand, **et toutes les actions** de
  l'étape.
- **Tout bouton d'étape qui agit immédiatement** (prévisualiser, quitter la
  prévisualisation, activer/désactiver pour les staffs) passe par
  `ScoutMagicConfirm.ask()` avec un message qui dit la conséquence **et
  qu'aucun e-mail n'est envoyé**. Les liens qui mènent vers une autre page n'en
  ont pas.
- **Aucun rechargement complet**. Chaque action est un appel JSON
  (`ScoutMagicApi.postJson`, `guardCsrfJson`) qui renvoie l'état recalculé :
  le serveur rend **le fragment Twig du bloc des étapes + résumé + cartes
  d'années** et le JS le remplace. Une seule source de rendu (Twig) — pas de
  copie de la logique d'affichage en JS. Pendant l'appel, le rond / bouton est
  désactivé et marqué occupé ; en erreur, toast `error` et état inchangé. Les
  commandes sont idempotentes (`done: true|false` explicite, jamais « basculer »),
  donc deux onglets ouverts ne se contredisent pas : le fragment renvoyé reflète
  l'état réel.
- Résumé en tête : « n étapes faites sur N », barre, « n points à vérifier »,
  « n blocages ». Ligne « Dernière bascule : {ancienne} → {nouvelle}, le … par … »
  lue dans le journal (`scout_year_public_activated`), sans table nouvelle.
- Repli sans JS : les formulaires POST existants restent la cible des mêmes
  actions (le JSON est une variante de réponse, pas une seconde route métier).

### 2.9 Revue avant bascule (étape 14)

Dialogue : rappel très visible « {publique} → {cible} », blocages réels avec
leur lien de résolution (bouton de confirmation désactivé s'il y en a), points
à vérifier (« n'empêchent pas la bascule »), étapes non faites, étapes faites
(repliées). Le serveur **revérifie tout** à la confirmation : le dialogue n'est
qu'un affichage. Bascule toujours manuelle, jamais programmée.

### 2.10 Politique des années importables

Service `Core\Import\DeskImportYearPolicy` (nom indicatif), horloge injectée,
seule source de vérité pour l'interface **et** le serveur.

Les deux dates MM-JJ sont placées dans l'année civile de **fin de l'année
publique réellement configurée** (`current_scout_year_id`) :

| Hors mode configuration | Années importables | Présélection |
|---|---|---|
| avant `scout_year_import_next_start_date` (défaut `07-01`) | publique | publique |
| de cette date à la veille de `scout_year_desk_encoding_date` (défaut `08-15`) | publique **et** suivante | suivante |
| à partir de `scout_year_desk_encoding_date`, jusqu'à la bascule | suivante | suivante |

- Après la bascule, la règle repart de la nouvelle année publique ; jamais
  d'avance d'une seconde année au motif de la date civile.
- Année publique choisie pendant la fenêtre transitoire → **dialogue de
  confirmation** (sans case à cocher, le fichier reste sélectionné) ; le POST
  porte un champ `confirmed_year` égal à l'identifiant de l'année, **exigé par
  le serveur** pour ce cas (un POST forgé ou rejoué sans lui est refusé). Ce
  champ est distinct du mot `REMPLACER` de la barrière roster, qui reste
  intacte.
- **Mode configuration** (`ConfigurationMode::isActive()` côté serveur, jamais
  une valeur du navigateur) : toutes les années existantes ; une année hors de
  la règle habituelle demande le **même dialogue** et le même `confirmed_year`.
  Toutes les validations CSV et la barrière roster s'appliquent.
- Année refusée : message clair, aucune écriture, journal
  `desk_import_year_refused`.
- La page ne propose que les années permises, présélectionne la bonne, et le
  bloc « Dernier import » **suit l'année sélectionnée** (données de chaque année
  permise dans un îlot JSON, mise à jour sans requête ; état vide « Aucun import
  pour {année}. »). Le lien « Historique » porte `?annee=`.
- Le lien depuis Année scoute ouvre `/admin/import?annee={cible}` ; l'année
  n'est présélectionnée que si la politique la permet.
- Nouveau réglage `scout_year_import_next_start_date` (défaut `07-01`) à côté de
  `scout_year_desk_encoding_date`, dont la description change (il ferme aussi
  l'import dans l'année publique). Validation à l'enregistrement : MM-JJ, les
  deux avant le `09-01`, ouverture strictement avant l'encodage — refus avec un
  message qui nomme les deux valeurs. Valeur stockée incohérente : les deux
  retombent sur leurs défauts (et `specifications.md` le dit).
- Le rejeu du jeu de référence et les tests appellent le service avec une
  politique **sans restriction** explicite ; le chemin web ne peut pas la
  demander.

### 2.11 Écarté

Liste de badges obligatoires ; comparaison des badges avec l'an passé ;
grille mensuelle du calendrier ; récapitulatif complet des bascules passées ;
vérification de factures, montants, paiements ; bascule programmée ; nouvelle
colonne de statut dans `import_journal` ; étiquettes de module ; contrôle
automatique qui marquerait une étape humaine.

---

## 3. Lots et ordre d'exécution

Livraison en **trois vagues**, **une PR par lot**. Les lots d'une même vague
tournent **en parallèle** — les lancer en même temps.

```
Vague 1 :  [A Import]   [B Moteur + gardes]   [C Contrats de lecture]
                              │                      │
Vague 2 :            [D Page dynamique]   [E Contrôles des 14 étapes]
                              └──────────┬───────────┘
Vague 3 :                          [F E2E, aide, clôture]
```

A est indépendant de tout. D dépend de B. E dépend de B et C. D et E sont
parallèles : B fixe le contrat et crée les fichiers qui les séparent (§3, lot B).
F attend tout.

### Lot A — Politique des années importables (vague 1)

- `DeskImportYearPolicy` + horloge injectable (motif `?\Closure $clock` déjà
  utilisé ailleurs) ; appliquée dans `DeskImportService::import()` **et** dans
  `ImportController` (liste, présélection, confirmation).
- Réglage `scout_year_import_next_start_date`, validation croisée des deux
  dates (le formulaire de `/config/settings` enregistre au changement : le
  refus doit revenir par sa réponse JSON existante).
- Page Import : années permises, présélection, dialogue de confirmation
  (`ScoutMagicConfirm.ask`, JS dans un fichier, pas dans le gabarit), îlot JSON
  des derniers imports, bloc qui suit l'année, `?annee=`.
- Rejeu du jeu de référence : politique sans restriction.
- Tests : chaque seuil à J-1/J/J+1, dates personnalisées, dates incohérentes,
  bascule anticipée (publique = 2027-2028 le 20/08/2027 → seule 2027-2028
  jusqu'au 30/06/2028), mode configuration on/off, POST forgé sans
  `confirmed_year`, POST d'une année non proposée, barrière roster inchangée.
- Docs : `specifications.md` §7, `docs/help/import-desk.md`, description du
  réglage, `ARCHITECTURE.md` (où vit la politique, pourquoi dans le service).

### Lot B — Moteur des étapes et gardes serveur (vague 1)

- Nouveau modèle d'étape (valeur immuable) : clé, numéro, phase, nature,
  état (`todo|done|blocked`), points à vérifier (liste de libellés), action
  principale, actions, `markRefusal` (raison de refus du marquage), et un
  **fournisseur de contrôle par étape** (`StepCheckProvider`, interface du
  cœur, une implémentation par clé) qui renvoie points à vérifier + données du
  dialogue. Ce lot livre les 14 fournisseurs **avec les contrôles déjà
  disponibles** (import par `import_journal`, année staff, publique,
  passages/veto via contrats existants) et des fournisseurs vides pour les
  autres — E les remplit sans toucher au moteur.
- **Gabarits de détail** : B crée les 14 fichiers
  `admin/scout_year/steps/{key}.html.twig` (contenu minimal : ce que B sait
  déjà afficher). La propriété est ensuite fixée pour que D et E ne se
  marchent pas dessus : **D possède la page et la coquille du dialogue** qui
  inclut ces fichiers, **E possède le contenu** de ces 14 fichiers. D ne les
  modifie pas ; E ne touche ni à la page ni au dialogue.
- Étape 1 : B garde le comportement actuel du marquage (aucun blocage
  aujourd'hui) ; le blocage pendant la campagne arrive avec E, qui a besoin du
  contrat de C. L'intervalle est assumé : le site n'est en production nulle
  part.
- Nouvel ordre (§2.2), `preview`, `passage`, `ephemerides`, `staff_photos`
  deviennent humaines ; refus serveur du marquage d'une étape constatée.
- Gardes dans `ScoutYearAdminService` (seul chemin d'écriture) :
  `activateStaffYear` exige un import réussi de l'année ; `activatePublicYear`
  exige année staff = cible + import réussi + veto. Exceptions dédiées,
  journal `…_blocked`. Les tests de contrôleur couvrent les POST directs.
- « Dernière bascule » lue dans le journal.
- Ne touche pas au gabarit au-delà de ce qu'exige le nouvel ordre (D refait la
  page) ; garder l'E2E existant vert en adaptant ses attentes d'ordre et de
  « fait automatique ».
- Docs : `specifications.md` §16.3/§16.4/§19.2, `ARCHITECTURE.md` §8.26.

### Lot C — Contrats de lecture (vague 1)

Rien d'affiché ; uniquement des lectures testées, pour une **année explicite**
quand c'est possible.

- Inscriptions (`Modules\Registration\Api\…`) : état de la campagne de
  l'année cible (`not_started|open|finished` + dates), demandes en attente /
  acceptées / acceptées sans section prévue, partants annoncés, détail des
  passages sans destination, écarts préparé ↔ importé (§2.5).
- Cotisations (`Modules\Fees\Api\…`, premier contrat du module) : résumé de
  `FeeAccuracyService::report($yearId)`.
- Calendrier : nombre d'événements par section entre deux dates.
- Trombinoscope : responsable(s) par section pour une année donnée (étendre le
  crochet si besoin, avec un test d'architecture inchangé).
- Cœur : photo individuelle par membre avec son origine (exacte / antérieure /
  aucune) ; sections sans photo de groupe de l'année ; agrégat des badges par
  section pour une année ; animateurs par section pour une année.
- Respect de `ModuleBoundariesTest` : le cœur ne dépend que d'interfaces
  `Api\` ou de crochets, jamais d'une classe concrète de module.
- Docs : `ARCHITECTURE.md` (§7.4/§7.5 et sections des modules concernés).

### Lot D — Page dynamique (vague 2, après B)

- Réécriture de `admin/scout_year.html.twig` selon la maquette : résumé, cartes
  d'années, phases, rond-contrôle, une action par étape, « Détails » en
  dialogue (`partials/modal.html.twig`), revue avant bascule.
- Variante JSON des actions existantes (marquer, prévisualiser, quitter,
  activer/désactiver staff, bascule) renvoyant le fragment ; repli POST
  conservé.
- Confirmations sur les boutons qui agissent (§2.8) ; aucune sur le rond.
- JS dans `public/assets/js/admin-scout-year.js` + tests Vitest (succès, erreur
  réseau, erreur serveur, double clic, état occupé).
- Le dialogue Détails inclut les gabarits par étape
  `admin/scout_year/steps/{key}.html.twig` créés par B ; D n'en modifie pas le
  contenu (propriété de E).
- `UxConventionsTest` reste vert (pas de script dans le gabarit, pas de
  `confirm()`, une action principale par écran, etc.).
- Docs : `design.md` si une convention nouvelle émerge (rond-bascule
  d'étape), sinon rien.

### Lot E — Contrôles des 14 étapes (vague 2, après B et C)

- Remplit les fournisseurs de contrôle et le contenu des gabarits
  `admin/scout_year/steps/{key}.html.twig` des étapes 1, 2, 3, 4, 5, 7, 10, 11,
  12, 13 avec les contrats de C (§2.3 à §2.7). Ne touche ni à la page ni à la
  coquille du dialogue (propriété de D).
- Blocage du marquage de l'étape 1 (§2.3), côté serveur.
- Aucun contrôle ne produit de « ✓ » ni d'absence de point à vérifier sur des
  données manquantes (avant import : message d'attente).
- Tests unitaires par fournisseur, y compris : campagne programmée / en cours /
  terminée / fermée à la main / rouverte ; zéro passage ; section sans
  animateur ; Trombinoscope inactif ; module Cotisations ou Calendrier inactif
  (étape absente) ; badge référent jamais compté comme manque de section.

### Lot F — Parcours de bout en bout et clôture (vague 3)

- Réécrire `tests/e2e/specs/scout-year-transition.spec.js` pour le nouveau
  parcours **sans rechargement** (attendre la réponse JSON, pas
  `domcontentloaded`), et ajouter un scénario Import Desk : fenêtre
  transitoire + dialogue, refus d'une année non permise, mode configuration.
- Relecture finale des docs : `specifications.md` §7/§16/§19.2,
  `ARCHITECTURE.md` §8.26, `docs/help/annee-scoute.md` (réécrite pour le
  nouveau parcours, l'ordre conseillé, les trois blocages),
  `docs/help/import-desk.md`, `docs/help/reglages.md` si concerné.
- Ligne de la maquette dans `docs/chantiers/maquettes/README.md` passée à
  « livré ».
- **Commenter #930** avec ce qui a été livré (lots, PR, écarts éventuels),
  **puis clôturer #930**.

---

## 4. Pièges à ne pas manquer

- `listYears()` crée l'année suivante comme effet de bord : ne pas s'en servir
  pour déterminer ce qui est **permis**.
- La date d'encodage Desk était « purement informative » : chercher toutes ses
  descriptions (réglage, spec §16.4, ARCHITECTURE §8.26, aide) et les corriger.
- `import_journal` n'a pas de statut : ne pas en ajouter un. Un import rejeté
  n'y est jamais.
- L'accès pendant la transition (§16.5, `StaffYearEligibility`) ne change pas.
- La prévisualisation ne décide jamais d'un accès ni d'une étape.
- `ScoutMagicConfirm` met le focus sur « Annuler » ; nommer l'action sur le
  bouton (« Activer pour les staffs », « Importer dans 2026-2027 »).
- Le fragment JSON doit être rendu pour le **même** utilisateur et la même
  année cible que la page (recalcul serveur, jamais d'état envoyé par le
  navigateur).
- `section_staff_photos` et `member_photos` : la reprise de l'année précédente
  est un **affichage**, jamais une photo de l'année cible.
- Ne jamais lire une table d'un module depuis le cœur.

---

## 5. Critères d'acceptation

1. Aucun POST (même forgé) ne permet : d'importer dans une année refusée par la
   politique ; d'importer dans l'année publique en fenêtre transitoire sans
   `confirmed_year` ; d'activer l'année staff sans import réussi ; de basculer
   sans année staff = cible, sans import réussi, ou malgré le veto ; de marquer
   l'étape 1 pendant la campagne ; de marquer une étape constatée.
2. Sur la page Année scoute, aucune action ne recharge la page ; compteurs,
   pastilles, actions principales et revue avant bascule suivent l'état serveur
   après chaque action, y compris en erreur.
3. Les étapes des modules désactivés sont absentes et la numérotation suit.
4. Rien ne s'affiche comme vérifié sur des données absentes.
5. Suites PHPUnit, Vitest, PHPStan, typecheck et Playwright vertes ; docs à
   jour ; #930 commentée puis clôturée.

---

## 6. Journal du chantier

| Lot | PR | Livré | Écarts / notes |
|---|---|---|---|
| A | #960 | `DeskImportYearPolicy` (+ `DeskImportYearDates`, `DeskImportYearWindow`, `DeskImportYearOption`, `DeskImportYearRefusedException`) appliquée dans `DeskImportService::import()` et `ImportController` ; réglage `scout_year_import_next_start_date` et validation croisée dans `SettingsController::update()` ; page Import (règle du jour, années permises, présélection, `?annee=`, dialogue `confirmed_year` dans `admin-import.js`, îlot JSON du dernier import par année) ; rejeu et tests en politique sans restriction ; journal `desk_import_year_refused` ; lien de l'étape import vers `/admin/import?annee={cible}` ; aide `import-desk-annee` | Carte « Année scoute » de la page Import remplacée par le bloc de la règle (son texte « n'importe quelle année » devenait faux). Libellé du réglage d'encodage aligné sur la maquette (« Date d'encodage Desk »). La barrière transporte `confirmed_year` au second dépôt. L'E2E de la transition importe en mode configuration (le jour d'exécution est quelconque). `ScoutYearTransitionService::deskEncodingDate()` ne lit pas encore le repli commun des deux dates (lot B). |
