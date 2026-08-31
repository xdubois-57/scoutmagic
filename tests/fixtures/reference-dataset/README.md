# Jeu de données de référence

Un jeu de données reproductible pour peupler une **instance de test** de
ScoutMagic : trois années scoutes d'une unité belge fictive, ses membres, ses
sections, ses staffs, ses photos, ses comptes bancaires et de quoi exercer les
modules.

> **Une instance construite avec ce jeu de données n'est pas une instance de
> production.** Elle contient des mots de passe documentés publiquement dans ce
> fichier, des données volontairement fictives et un script qui écrit
> massivement en base. Ne l'exécutez jamais sur une installation réelle.

---

## 1. Le principe : une recette, pas un dump

Ce qui est versionné ici, ce sont **les fichiers d'import** (exports Desk,
relevés bancaires) et **une description déclarative** de tout ce que Desk ne
connaît pas. Un builder en ligne de commande rejoue le tout **à travers les
vrais services de l'application**, sur l'instance cible.

Deux conséquences, qui sont la raison d'être de cette approche :

- **Il n'y a aucune clé à transporter.** Le chiffrement se fait avec les clés de
  l'instance de destination, par construction. Une archive de sauvegarde
  ScoutMagic, elle, n'est pas portable : `BackupService::createFileBackup()`
  exclut `storage/keys/` et `storage/config/`, où vivent `encryption_key` et
  `blind_index_key`. Restaurée ailleurs, elle donne des données personnelles
  indéchiffrables (l'AAD par colonne fait échouer le GCM) et des blind indexes
  qui ne correspondent plus à rien : personne ne se connecte, la recherche
  membre ne trouve rien.
- **Le jeu de données ne peut pas cesser d'être restaurable.** Une évolution de
  schéma est absorbée par les services au lieu de casser un artefact figé. Et
  parce que le builder est du PHP qui appelle des constructeurs, une dérive de
  signature est attrapée par `vendor/bin/phpstan analyse` avant le commit —
  `phpstan.neon` liste ce répertoire dans ses `paths` précisément pour ça.

## 2. Ce répertoire ne pollue rien

- **Aucun point d'entrée web** : ni route, ni module, pas même un module
  `visible_when`. Uniquement des scripts CLI, avec le garde
  `PHP_SAPI !== 'cli'` de `public/cron.php` (voir SECURITY.md §24).
- **Rien sous `core/`, `modules/` ou `public/`** n'est ajouté pour ce jeu de
  données. *(Une exception assumée est en cours : voir §7.)*
- `scripts/release.sh` exclut `tests/*` de l'artefact de release, et une
  vérification post-zip explicite refuse désormais tout artefact contenant
  `reference-dataset`. Rien d'ici n'atteint jamais une unité déployée.

## 3. Contenu du répertoire

```
tests/fixtures/reference-dataset/
  README.md              ce fichier
  generate.php           point d'entrée CLI du générateur (+ --check)
  DatasetGenerator.php   produit tous les fichiers générés, en mémoire
  UnitBlueprint.php      LA TABLE : sections, effectifs, fonctions, viviers
  ScenarioCatalog.php    LA TABLE : les 24 scénarios et les Tiers épinglés
  ScenarioPeople.php     les 33 membres de scénario, écrits à la main
  PopulationBuilder.php  fait vieillir et renouvelle la population de fond
  PersonFactory.php      fabrique une personne fictive
  Person / PersonYear / PostalAddress / FunctionAssignment / Rng
  DeskCsvWriter.php      écrit une année au format d'export Desk
  DeskImportReplay.php   rejoue les trois exports par le vrai pipeline
                         (partagé avec le builder)
  PhotoLot.php           LA TABLE : genre de chaque portrait, photos de groupe
  PhotoAssigner.php      attribue les portraits aux cadres
  BankBlueprint.php      LA TABLE : comptes, IBAN, mouvements récurrents
  BankStatementBuilder / BnpCsvWriter / StatementDraft
  build.php              point d'entrée CLI du builder
  InstanceContext.php    ouvre l'installation cible et refuse les mauvaises
  InstanceReset.php      vide l'installation cible pour --reset (§8.4)
  ModuleActivator.php    active tous les modules par le vrai ModuleManager
  FinanceSeeder.php      comptes bancaires + import des relevés
  DemoAccounts.php       LA TABLE : quel membre porte quel rôle de démo
  ExtrasBlueprint.php    LA TABLE : décalages, départs, créances, adresses
  ExtrasApplier.php      applique les extras, et orchestre les semeurs
  desk/                  les trois exports Desk générés, commités
  bank/                  les six relevés BNP générés, commités
  photos/                le lot de photos (§4) + assignments.csv, généré
```

**Un domaine, deux fichiers** — c'est la règle de ce répertoire, et elle est
la raison pour laquelle `ExtrasBlueprint` et `ExtrasApplier` ont été coupés en
morceaux quand le jeu de données a grossi : un `*Blueprint` **décrit**, un
`*Seeder` **applique**, et aucun des deux ne fait le travail de l'autre.

```
  CalendarBlueprint / CalendarSeeder      rythme hebdomadaire, camps, Temps d'U
  NewsBlueprint / NewsSeeder              articles, formulaires, réponses
  CampaignBlueprint / CampaignSeeder      vente de calendriers + réconciliation
  CampsBlueprint / CampsSeeder            lieux de camp et séjours
  RegistrationBlueprint / RegistrationSeeder  capacités et demandes
  BannerBlueprint / BannerSeeder          bannières et leur role_min
  GalleryBlueprint / GallerySeeder        l'album externe
  RentalBlueprint / RentalSeeder          le local, son tarif, ses locations
  StaffBlueprint / StaffSeeder            responsables de section et badges
```

Les classes sont autochargées par l'entrée `autoload-dev` de `composer.json`
(`Tests\Fixtures\ReferenceDataset\`) : elles n'existent donc pas du tout dans
une installation `composer install --no-dev`, en plus de ne jamais entrer dans
l'artefact de release.

## 4. Le lot de photos

54 fichiers dans `photos/`, nommés `<branche>_<individual|group>_<compteur>.jpg`,
11,6 Mo au total.

| Branche | individuelles | groupe |
|---|---|---|
| baladin | 6 | 4 |
| louveteau | 8 | 3 |
| eclaireur | 9 | 2 |
| pionnier | 8 | 2 |
| unite (Staff d'U) | 9 | 3 |
| **total** | **40** | **14** |

Photographies synthétiques : aucune personne réelle, aucune donnée d'une unité
existante.

### 4.1 Où vont les deux familles

- `*_individual_*` → **photo de membre** (`Core\Photo\MemberPhotoService`, table
  `member_photos`, clé membre × année scoute). C'est ce que rend le
  trombinoscope, qui n'affiche que les fonctions de rôle `chief`/`admin`
  (`SectionService::getSectionStaff()`) — **toute personne portant une photo
  individuelle est donc un cadre, jamais un animé.**
- `*_group_*` → **photo de groupe de section**
  (`Core\Photo\SectionPhotoService`, table `section_staff_photos`, une par
  section et par année). Ces photos apparaissent aussi sur les pages publiques
  Contact et Sections, dont le `role_min` est `public`.

### 4.2 Couverture visée

Un staff de section descend rarement sous 3 personnes et le Staff d'U en compte
typiquement 3 à 5 : 40 individuelles suffisent à pourvoir les huit sections et le
Staff d'U à ce niveau. **Les effectifs ne sont jamais réduits pour tenir dans le
lot.** Un cadre sans photo n'est pas un défaut à corriger : `member_photo()` rend
un avatar d'initiales, et c'est l'état par défaut du site — le jeu de données doit
en contenir. De même, une section sans photo de groupe n'affiche rien du tout
(`section_photo()` n'a délibérément pas de repli en initiales), ce qui est là
aussi un cas à représenter.

### 4.3 Trois règles à ne pas « corriger »

1. **Le préfixe de branche est une indication d'affectation initiale, pas une
   identité.** Une fois une photo attribuée à un `Tiers`, elle suit la personne :
   le Pionnier promu animateur Baladins et l'animateur qui change de section
   gardent leur fichier, dont le nom cesse alors de décrire leur branche
   courante.
2. **Une photo appartient à une personne, pas à une année.** La plupart des
   cadres ne sont photographiés qu'en A1 : `MemberPhotoService::resolveFileId()`
   replie sur l'année antérieure la plus récente, donc la continuité en A2 et A3
   est *produite par le repli* — c'est ce qui l'exerce réellement. Deux ou trois
   cadres sont rephotographiés chaque année, pour couvrir aussi le chemin exact
   et le remplacement. Même logique côté groupe : une section ne reçoit
   délibérément de photo qu'en A1, pour que le repli soit exercé plutôt que
   supposé.
3. **La correspondance photo → `Tiers` est déclarative et versionnée**, jamais un
   appariement implicite par ordre alphabétique ou par compteur qu'un fichier
   ajouté décalerait silencieusement. Elle vit dans `photos/assignments.csv`,
   régénérée et comparée par `generate.php --check` — voir §9.4.

### 4.4 Contraintes techniques vérifiées

- **Décodage** : la plus grande image fait 1,7 Mpx, très en dessous des 50 Mpx de
  `Core\Image\ImageDimensionGuard`.
- **Recadrage** : `SectionPhotoProcessor` recadre au centre en 4:3 et plafonne à
  1600 px. Aucune photo de groupe n'a besoin d'être réduite ; huit sont déjà en
  4:3 exact, six perdent 5,5 % de chaque côté et une perd 3 % en haut et en bas.
  Vérifié image par image : personne n'est coupé.
- **Format** : le lot d'origine était fourni en PNG, 117,8 Mo pour 55 fichiers.
  Ré-encodé en JPEG qualité 82 sans redimensionnement — 11,6 Mo, soit 9,8 % du
  volume initial — parce qu'un dépôt git n'aime pas les binaires volumineux et
  que le trombinoscope n'affiche de toute façon que des dérivés réduits
  (`ImageVariantService`, ARCHITECTURE.md §8.39). Les blobs PNG restent dans
  l'historique git : le `.git` ne rétrécit pas pour autant, seul le coût des
  clones et de l'espace de travail baisse.
- **Nettoyage à l'import du lot** : `pionnier_group_003.png` était un doublon
  exact de `pionnier_group_001.png` (même md5) et a été supprimé ; les séries,
  qui présentaient des trous de numérotation, ont été renumérotées en continu.

## 5. Les trois années scoutes

`2024-2025` (A1), `2025-2026` (A2), `2026-2027` (A3).

Ces libellés sont **figés**, jamais calculés depuis la date du jour : les fichiers
générés sont commités et comparés à l'octet près par `generate.php --check`, et
des années relatives feraient échouer cette comparaison à chaque rentrée sans que
personne n'ait rien modifié.

**Conséquence à connaître.** Le builder ne pose pas le réglage
`current_scout_year_id` : le site retombe donc sur son année date-calculée
(`ScoutYearService::labelForDate()`). A3 est l'année courante du 1er septembre 2026
au 31 août 2027 ; passé cette date, le jeu de données se consulte par le sélecteur
d'année, et le module Finances — dont la fenêtre d'exercices est elle aussi
date-calculée (2 années passées, la courante, 2 suivantes) — cessera de lister ces
exercices. L'import bancaire, lui, continue de fonctionner :
`FiscalYearRepository::findForDate()` interroge `scout_years` directement.

**Une quatrième année existe en base**, `2027-2028`, créée par
`ScoutYearService::ensureYear()` : c'est celle des demandes d'inscription
(§8.3), qui sont par nature des demandes pour l'année *suivante*. Elle n'a
aucun membre et n'apparaît nulle part ailleurs. Elle est épinglée plutôt que
dérivée de « l'année suivante » pour la même raison que les trois autres : une
cible date-calculée mettrait les demandes dans une année différente selon le
jour de la construction, et l'arithmétique des capacités cesserait de vouloir
dire quelque chose.

## 6. Pièges du pipeline, constatés dans le code

À lire avant de toucher au builder ou aux tests d'import.

- **`DeskImportService::import()` ne supprime plus le fichier CSV qu'on lui
  donne** — cet avertissement est obsolète. Le service en conserve désormais
  une copie chiffrée (`SECURITY.md` §13) et laisse l'original à celui qui l'a
  écrit : `ImportController` efface le fichier déposé dans un `finally`. Le
  builder et les tests peuvent donc pointer directement sur un fichier de
  `desk/` sans le détruire. La contrepartie à connaître : chaque import
  enregistre un `FileRecord` chiffré, ce qui suppose un chemin de stockage
  utilisable — `DeskImportReplay` en accepte un et retombe sinon sur un
  répertoire temporaire.
- **`ScoutYearService::ensureYear()` insère toujours `is_current = 1`** et rien ne
  remet jamais la colonne à 0. Créer trois années laisse trois lignes à 1. Sans
  conséquence — la colonne n'est lue nulle part, la vérité vivant dans le réglage
  `current_scout_year_id` (`Core\ScoutYear\ScoutYearResolver`) — mais ne vous y
  fiez pas.
- **`EXPECTED_HEADERS` de `DeskCsvParser` compte 35 colonnes**, et l'une d'elles
  s'écrit `Sizaine/Patrouillle`, avec trois L. C'est la valeur attendue par
  `validateHeaders()` : l'orthographe correcte ferait échouer l'import. Ne la
  « corrigez » pas.
- **La colonne `SECTION` (capitales) n'est jamais lue.** L'identité d'une section
  vient de `Section`, qui porte à la fois son code et son nom. Le jeu de données
  remplit `SECTION` de valeurs plausibles mais *différentes*, pour prouver
  qu'elle est bien ignorée.
- **`AgeBranchRepository::canonicalSortOrder()` résout la branche par
  sous-chaîne**, et `unité` y renvoie le créneau Staff d'U (50). Aucun libellé de
  branche ou de section destiné à autre chose ne doit contenir « unité ».
- **`Staff d'U` n'apparaît jamais dans un CSV** : `UnitStaffSectionService` le
  synthétise depuis les fonctions de rôle `admin`, rôle qui n'est connu qu'après
  confirmation dans Config Desk. Une nouvelle `FONCTION` s'importe toujours en
  `role = 'identified'`, non confirmée.

## 7. Exception assumée au « rien sous core/ »

Le prompt de chantier demandait de téléverser les photos « par le vrai chemin de
téléversement », c'est-à-dire `UploadController`. Ce chemin n'est pas appelable en
ligne de commande : `store()` exige un jeton CSRF valide, une session
authentifiée, `ConfigurationMode` et un `$_FILES`, et son étape de recadrage est
`private`.

**Décision du mainteneur, prise avant IT-01 :** extraire ce pipeline dans un
service core réutilisable, appelé par le contrôleur comme par le builder, plutôt
que de le dupliquer. C'est une modification de code de production, contraire à la
règle « le builder ne modifie pas le code de production » — elle est assumée pour
qu'il n'existe qu'un seul chemin de téléversement, donc aucune dérive possible
entre les deux appelants.

**Livrée (IT-05bis) :** `Core\Photo\PhotoIngestionService` porte désormais la
liste blanche MIME, le plafond de taille, le recadrage par contexte,
`UploadHandler`, la génération du dérivé et le rattachement de la cible.
`UploadController` garde le jeton CSRF, l'autorisation, le journal, le message
flash et la redirection — et passe de onze collaborateurs à deux. Le builder
appelle `ingest()` avec un auteur nul, ce que tous les collaborateurs
acceptaient déjà.

## 8. Construction sur une instance de test

```bash
php tests/fixtures/reference-dataset/build.php --yes
php tests/fixtures/reference-dataset/build.php --yes --root=/chemin/vers/installation
php tests/fixtures/reference-dataset/build.php --yes --reset
```

Sans `--root`, le builder cible l'installation dans laquelle il se trouve.

**Il est destructeur, pas idempotent** — et le chantier impose de choisir l'un
ou l'autre, jamais l'entre-deux. Il refuse de tourner sur une installation qui
contient déjà des membres, des mouvements financiers ou plus d'un compte
utilisateur, et il ne fusionne rien. Trois sorties : repartir d'une
installation vierge, restaurer la sauvegarde prise juste après la première
construction (§11), ou **`--reset`**, qui vide l'installation ciblée avant de
construire (§8.4).

### 8.1 Ce qu'il fait, dans cet ordre

1. **Le superadministrateur.** Créé en premier, parce que chaque import Desk
   écrit une ligne `import_journal` créditant un compte et que cette colonne
   porte une clé étrangère. Un identifiant `1` en dur marche par coïncidence
   sur une installation neuve et échoue dès que les comptes ont été
   renumérotés.
2. **Tous les modules, activés par le vrai `Core\Module\ModuleManager::
   activate()`** (`ModuleActivator`), dans un ordre topologique de leurs
   `requires` — `activate()` refuse un module dont une dépendance dure n'est
   pas encore active. **Ici, et pas à la fin.** L'état final demandé est le
   même (tous les modules actifs), mais l'ordre décide de ce que le build peut
   écrire : un module éteint n'a ni réglages par défaut ni routes, et les
   extras qui en dépendent étaient sautés en silence. C'est le service de
   modules qui active, jamais une écriture dans `module_registry` : l'activation
   crée les réglages par défaut, enregistre les routes et journalise — un
   `INSERT` dans la table ne fait aucun des trois, et `RegistrationSeeder`
   écrit précisément un réglage qui n'existe pas avant.
3. **Les trois années scoutes et les trois imports Desk**, dans l'ordre
   chronologique, par le vrai `DeskImportService` — le même
   `DeskImportReplay` que le test d'import de bout en bout.
4. **La confirmation des rôles**, par le chemin de Config Desk
   (`FunctionRepository::updateRole(..., true)` puis
   `UnitStaffSectionService::syncMembership()` sur les trois années). C'est le
   seul endroit d'où Staff d'U peut naître.
5. **Les finances** : catégories par défaut, puis comptes de section, puis
   **l'IBAN de chaque compte de section**, puis les deux comptes d'unité, puis
   les six relevés. **L'ordre est porteur** — `ensureDefaultCategories()` ne
   sème que tant que la table est vide, et créer *ou compléter* un compte y
   ajoute déjà sa catégorie « Virement <compte> ».
   `ensureDefaultAccountsForSections()` crée un compte par section **sans
   IBAN**, ce qui est correct — il ne peut pas en inventer un — mais laisse
   huit comptes qu'aucun relevé ne peut atteindre ; `completeSectionAccounts()`
   les complète par `FinanceService::updateAccount()`, qui normalise l'IBAN
   avant l'index aveugle et synchronise la catégorie de virement.
6. **Les extras et les semeurs par domaine** : adresses de section, décalages
   d'année, départs, badges et photos, puis calendrier, actualités, camps,
   inscriptions, bannières, galerie, locations et responsables de section.
7. **La campagne de paiement**, après les extras : elle a besoin des membres
   (`finance_campaign_rows.member_id` est obligatoire, et une ligne qui ne
   résout personne fait refuser tout le fichier) et du compte d'unité. Ses
   communications structurées sont **tirées au hasard à la création**, donc
   elles ne peuvent pas figurer dans les relevés commités : le semeur les relit,
   écrit un septième relevé BNP et le fait entrer par le même `ImportService`,
   qui catégorise, déduplique et réconcilie.
8. **Les comptes de démonstration** adossés à des membres, après les imports
   qui les créent.
9. **Un rapport en français** : effectifs par année, sections actives et
   inactives, compteurs financiers, et une ligne par extra — avec les
   remarques (une section sans responsable, un album externe non créé, une
   réservation refusée) plutôt qu'un compteur qu'il faudrait interpréter.

### 8.2 Résultat constaté sur une instance jetable

> **Ces chiffres datent d'avant IT-18 et sont en attente d'une construction.**
> Le lot qui a ajouté le calendrier, les actualités, les camps, les
> inscriptions, les bannières, la galerie, les locations et la campagne de
> paiement **n'a pas pu être construit** : `build.php` rejoue toute
> l'application contre une vraie base et prend `GET_LOCK('scoutmagic_schema_
> migration')`, un verrou **serveur** MySQL, ce que la machine de
> développement partagée sur laquelle ce lot a été écrit ne permettait pas.
> Les lignes marquées **`à constater`** ci-dessous seront remplies à la
> prochaine construction sur une instance jetable ; celles qui portent encore
> un nombre sont celles qu'IT-18 ne touche pas, et elles restent vraies —
> la population, les relevés et le lot de photos n'ont pas bougé. **Ne
> devinez pas les valeurs manquantes** : un chiffre inventé dans cette table
> est pire qu'une case vide, parce qu'il se lit comme une observation.

| Contrôle | Valeur |
|---|---|
| Membres actifs | 178 / 180 / 180 |
| Fonctions inédites | `à constater` — le vocabulaire réel en compte davantage qu'avant |
| Fonctions confirmées | 9 (`UnitBlueprint::FUNCTIONS`), plus une laissée non confirmée : `Délégué de branche` |
| Staff d'U (rôle admin confirmé) | un rattachement par membre de niveau unité et par année ; au moins trois par année, et les trois fonctions d'unité représentées |
| Modules activés | `à constater` — tous ceux présents sur le disque |
| Mouvements financiers importés | 125 pour les six relevés, `à constater` avec le septième |
| Doublons reconnus | 12 — les 2 comptes × 2 années × 3 lignes de recouvrement |
| Catégories | `à constater` — les défauts, plus une par compte à IBAN |
| Mouvements catégorisés | `à constater` |
| Comptes de section complétés | 8 attendus, `à constater` |
| Évènements de calendrier | `à constater` — de l'ordre de 600 (le rythme, §8.3) |
| Articles / réponses de formulaire | 5 / 10 déclarés, `à constater` |
| Lieux de camp / séjours | 5 / 12 déclarés, `à constater` |
| Demandes d'inscription | 23 déclarées, `à constater` |
| Lignes de campagne / créances | une par membre de A3, `à constater` |
| Réservations | 7 déclarées, `à constater` |
| Sections | 8 + Staff d'U, `Iama Horizon` inactive |

Les 20 mouvements non catégorisés des six relevés commités sont les
cotisations à communication structurée : elles se réconcilient contre des
créances attendues (§8.3), pas contre une règle de libellé.

### 8.3 Les extras, et le sous-ensemble couvert

Les extras sont tout ce que Desk ne connaît pas. Ils sont déclarés dans un
`*Blueprint` et appliqués par un `*Seeder`, **toujours par les vrais
services** — jamais une écriture directe dans `member_photos`,
`calendar_events`, `news_articles` ou `finance_expected_receivables`.

**Couverts :**

| Extra | Ce qu'il apporte |
|---|---|
| Adresses de section | Une par section, plus le Staff d'U. `sections.email` existait et restait vide, ce qui faisait passer les surfaces « écrire à la section » pour cassées plutôt que pour non configurées. |
| Décalages d'année | 2 membres, dont `T0009` en A1 — l'héritage par-dessus l'année manquante (scénario 5). |
| Départs marqués | 3, avec commentaire : deux qui se réalisent, un qui ne se réalise pas. C'est à quoi ressemble une grille « Départs » en mars. |
| Badges | `Trésorier` et `Infirmier` sur une personne de **chaque section, chaque année** — une règle, pas une liste — plus cinq attributions épinglées sur des personnes nommées. |
| Responsables de section | La fonction `Chef de section`, portée par **exactement un cadre par section et par année** (le générateur la distribue) et marquée `is_lead` dans `trombinoscope_function_flags` (le semeur la marque). Une seule des deux moitiés ne se voit pas : une fonction non marquée est un animateur ordinaire, une marque sur une fonction que personne ne porte laisse toutes les sections sans responsable. |
| Photos individuelles | 43, par `PhotoIngestionService` — le pipeline de `/upload`. |
| Photos de groupe | 14, recadrées en 4:3 avant stockage. |
| Calendriers de section | Le **rythme** d'une vraie unité, décrit comme une règle et non comme une liste : réunion le samedi de la mi-septembre à fin juin, moins les congés scolaires belges et quelques samedis de plus ; un grand jeu et un weekend avant Noël, autant après ; un camp fin juillet dont la durée dépend de la branche (trois jours chez les Baladins, quinze chez les Pionniers). La Route et le Staff d'U n'en ont pas : l'une s'organise seule, l'autre se réunit en Conseil d'unité. |
| Calendrier Animateurs | Un Temps d'unité (un weekend) par année et quelques Conseils d'unité le samedi matin, sur le calendrier que `CalendarService::ensureDefaultCalendar()` crée — visible des seuls cadres, ce qui est exactement ce qu'un Conseil d'unité doit être. |
| Actualités | 5 articles couvrant les quatre visibilités, dont un `direct_link` (« listé nulle part », qui n'est pas un barreau de l'échelle des rôles), deux formulaires **avec leurs réponses déjà déposées**, une image dans le corps du texte et une capacité presque — mais pas tout à fait — épuisée. |
| Créances attendues | 17, une par communication structurée des relevés. Le montant dû (65 €) n'est **pas** le montant payé : certains foyers sont à jour, d'autres non — une page de réconciliation où tout est soldé ne montre rien. |
| Campagne de paiement | Une vente de calendriers couvrant **tous** les membres de A3, une ligne par membre, importée depuis un vrai `.xlsx` par `CampaignService::createFromFile()`. La réconciliation est **partielle et fautive à dessein** : une majorité n'a rien payé, une large minorité a payé juste, et il y a un paiement court, un trop-perçu, deux virements faits deux fois et deux communications qui ne correspondent à aucune créance. |
| Camps | 5 lieux et 12 séjours, la plupart passés, trois à venir — dont un `À confirmer`, un `Annulé` et un dont on ne connaît plus que l'année. |
| Inscriptions | Le formulaire **ouvert**, les capacités semées à `SlotService::DEFAULT_CAPACITY`, trois réglées à la main pour montrer les trois valeurs qui comptent (un nombre, `NULL` = sans limite, `0` = fermé volontairement), et 23 demandes concentrées sur la première année du chemin : treize acceptées contre une capacité de quinze, donc un créneau visiblement proche de sa limite sans que rien ne soit complet. |
| Bannières | 5, dont une inactive, réparties sur `public` / `identified` / `chief` — le texte formaté va dans `editable_contents` sous `banner_content_{id}`, jamais dans la table `banners`. |
| Galerie | Un album **externe** pointant vers une page publique de démonstration sans données personnelles. Délibérément pas d'album local : un album est précisément la surface où un lecteur cesse de voir une fixture et croit voir des photos d'enfants. |
| Locations | Le local d'unité, configuré de bout en bout (tarif, contraintes, gestionnaire désigné parmi les membres), et 7 réservations à des stades différents : trois clôturées, deux confirmées dont une à cheval sur aujourd'hui, une demande à traiter, une refusée. |

**Deux points à savoir, tous les deux imposés par les services :**

- **Un article sans image de couverture n'est pas constructible.**
  `ArticleService::create()` refuse un `image_file_id` nul (« Une image est
  obligatoire pour l'article. ») ; la colonne n'est nullable que pour que les
  lignes antérieures à son existence restent migrables. Le jeu de données
  varie donc ce qu'il peut varier — la visibilité, la présence d'une image
  *dans le corps*, l'indexation — et laisse une couverture à chaque article.
- **Une réponse de formulaire et une demande d'inscription passent par le
  dépôt de leur module, pas par son service de soumission.** `ResponseService::
  submit()` et `RegistrationService::submit()` sont des *requêtes* : ils
  veulent une session connectée, un environnement Twig et une boîte aux
  lettres, parce que leur travail est d'envoyer une confirmation. Un build en
  ligne de commande n'a aucun des trois, et lui donner une boîte aux lettres
  voudrait dire qu'une construction de jeu de données envoie du courrier à des
  familles fictives. Le dépôt reste le code du module : il chiffre, il pose
  les index aveugles, il fait tout dans une transaction. Les **changements de
  statut**, eux, passent bien par le service (`RequestStatusService`), qui est
  ce qui refuse une transition impossible.

**Non couverts, délibérément.** Le chantier demandait « une couverture large
des modules, pas l'exhaustivité », et autorisait explicitement à proposer un
sous-ensemble plutôt que de livrer une couverture partielle non documentée.
Manquent donc, et **seulement** ceux-là :

- **les documents de section** (`Core\Member\SectionDocumentService`) ;
- **les groupes de discussion et leurs messages** (module `groups`).

La raison est la même pour les deux : chacun demande de recomposer la chaîne
complète de son module — notifications, planificateur, stockage chiffré,
parfois un connecteur IA optionnel — soit beaucoup de surface de câblage pour
une fixture, et autant de constructeurs à suivre à chaque évolution. Les
groupes en particulier exigent que le super-administrateur ait une identité
membre et un nom sur son compte (`GroupAccessService`), ce que le scénario
end-to-end provisionne et que le builder ne fait pas.

Les trois extras qui manquaient avec eux — **articles d'actualité avec
formulaire**, **demandes d'inscription**, **bien en location avec
réservation** — sont couverts depuis IT-18 et figurent dans le tableau
ci-dessus.

**Un module absent de l'instance cible est ignoré, pas fatal — et le saut est
signalé.** `ExtrasApplier` vérifie que la table témoin de chaque domaine
existe avant d'écrire. Depuis IT-18 le builder active tous les modules avant
d'en arriver là (§8.1), donc en pratique rien n'est sauté ; le garde reste,
parce que le builder peut viser une installation dont le schéma est antérieur
à un module, et qu'un build qui meurt à mi-chemin serait pire qu'un build qui
nomme la page qu'il n'a pas pu remplir. Le builder affiche alors explicitement
`(ignoré : module « calendar » absent)` à côté du compteur.

Ce n'est pas un détail cosmétique : un compteur à zéro se lit exactement pareil
que le module soit absent ou que le nom de table dans le code soit faux — et
il l'a été une fois (`calendars` au lieu de `calendar_calendars`), ce qui a
silencieusement perdu neuf évènements sur une instance où le calendrier était
actif depuis le début.

### 8.4 `--reset` : reconstruire sur une instance qui a déjà servi

```bash
php tests/fixtures/reference-dataset/build.php --yes --reset --root=/chemin/vers/installation
```

Le refus du §8 disait quoi ne pas faire sans dire comment s'en sortir : la
seule issue était une réinstallation, ou une sauvegarde prise au bon moment et
pas un instant plus tard. `--reset` est la troisième issue. Il ne contourne pas
le refus, il y répond : au lieu de fusionner dans des données existantes, il
les enlève d'abord.

**Ce n'est pas la « Réinitialisation complète » de l'application.**
`Core\Maintenance\Task\FullResetHandler` supprime `secrets.enc` et vide
`storage/` jusqu'à `keys/master.key` : le site repart sur l'assistant
d'installation, état dans lequel le builder ne peut plus rien construire —
il ouvre l'instance par `SecretManager`. Ici c'est plus étroit et volontairement
nommé autrement : **les données partent, l'installation reste.**

Dans cet ordre, par `InstanceReset` :

1. **Une sauvegarde de sécurité**, par `BackupService::createDatabaseDump()` —
   l'API de l'application, sans binaire `mysqldump`, et le même premier geste
   que `FullResetHandler` et que `SetupController::backupAndEmptyDatabase()`
   avant de détruire quoi que ce soit. Le fichier atterrit dans
   `storage/maintenance/` et son chemin est affiché. Si le dump échoue, **rien
   n'est vidé** : un `storage/` non inscriptible est précisément le moment où
   le filet sert. Passer outre demande un second drapeau explicite,
   `--no-backup`, exactement comme l'assistant d'installation exige un
   `force_without_backup` avant de vider sans filet.
2. **`TRUNCATE` sur toutes les tables sauf deux.** Le schéma reste debout — ce
   qui distingue ce vidage du `DROP TABLE` de l'assistant : aucune migration
   n'a besoin de re-tourner avant que le builder n'écrive, et la cible reste
   une installation configurée du début à la fin. Les compteurs
   `AUTO_INCREMENT` repartent à 1, et c'est voulu : deux constructions
   `--reset` successives produisent les mêmes identifiants.
3. **Les fichiers téléversés sous `storage/` sont supprimés**, sauf `keys/`,
   `config/` et `maintenance/`. Une table `files` vidée avec les photos encore
   sur le disque n'est pas une table rase, c'est un tas d'orphelins — et la
   sauvegarde qui vient d'être écrite vit dans `maintenance/`.

Les deux tables épargnées sont `settings` et `module_registry`, et la liste
n'est pas une invention de ce répertoire : c'est
`BackupService::CONFIG_ONLY_TABLES`, la réponse relue du projet à « quelles
tables sont de la configuration et non des données ». Les vider emporterait le
nom de l'unité, les réglages SMTP et — décisif — **quels modules sont
activés**, alors que le jeu de données a besoin de finance et de calendrier
pour construire quoi que ce soit. Un reset qui désactive la moitié du site
n'est pas un reset, c'est une réinstallation, et l'application en a déjà une.
Un test épingle les deux listes l'une à l'autre : si celle de `BackupService`
gagne une troisième table, il échoue tant que le reset ne l'a pas suivie.

`--reset` vide **même quand rien ne motivait un refus**. Les motifs de refus ne
comptent que les membres, les mouvements et les comptes, alors qu'une instance
peut très bien porter des sections, des années scoutes ou un calendrier sans un
seul membre ; construire par-dessus ces restes-là, c'est exactement le
demi-mélange que le builder refuse par principe.

**Écrire du SQL à la main est la seule chose que le builder ne fait jamais** —
mais un vidage n'a aucun service par où passer, et l'application procède
elle-même exactement ainsi, aux deux mêmes instructions près, dans
`FullResetHandler::truncateAllTables()` et dans
`SetupController::backupAndEmptyDatabase()`. C'est le seul endroit du
répertoire où la règle « toujours par les vrais services » n'a pas de service à
nommer, et `InstanceReset` le dit dans son en-tête plutôt que de le laisser
découvrir.

Constaté sur une instance jetable provisionnée par `scripts/e2e-support.php`,
puis construite, puis reconstruite par-dessus elle-même :

| Contrôle | Valeur |
|---|---|
| Tables vidées | 152 |
| Fichiers supprimés (2ᵉ passage) | 114 — les 117 de `storage/` moins `master.key`, `secrets.enc` et le dump du 1ᵉʳ passage |
| `settings` après vidage | 90 lignes, intactes |
| `module_registry` après vidage | 19 modules, tous encore activés |
| Effectifs reconstruits | 178 / 180 / 180, identiques au premier passage |
| Boot après reconstruction | 200 sur `/`, `/login`, `/sections`, `/contact` ; 302 sur `/upload` |

---

## 9. Régénération des fichiers

```bash
php tests/fixtures/reference-dataset/generate.php           # écrit les fichiers
php tests/fixtures/reference-dataset/generate.php --check   # vérifie qu'ils sont à jour
```

**Les fichiers générés sont commités.** Une instance en construction n'a rien à
générer, et un relecteur de PR voit changer les données, pas seulement le code
qui les produit. Le mode `--check` régénère tout en mémoire et compare octet par
octet à ce qui est sur disque : un générateur modifié sans avoir été relancé
devient un test qui échoue, jamais une divergence silencieuse. Même mécanisme,
et même raison, que `js-typecheck-baseline.json`.

`Tests\Integration\ReferenceDatasetFormatTest` invoque ce `--check`, fait passer
chaque export par le vrai `DeskCsvParser`, et vérifie qu'aucune photo du lot n'est
orpheline.

Où porter une modification :

| Ce qu'on veut changer | Où |
|---|---|
| Taille ou forme de l'unité, effectifs, viviers de noms | `UnitBlueprint.php` |
| L'adresse email d'une section | `UnitBlueprint::SECTIONS` (clé `email`) |
| Un comportement nommé à exercer | `ScenarioCatalog.php`, puis `ScenarioPeople.php` |
| Quelle photo de groupe va à quelle section | `PhotoLot::GROUP_PHOTOS` |
| Le genre d'un portrait ajouté au lot | `PhotoLot::INDIVIDUAL_GENDERS` |
| Le rythme d'une branche, la durée d'un camp | `CalendarBlueprint.php` |
| Un article, un formulaire, ses réponses | `NewsBlueprint.php` |
| Un lieu de camp, un séjour | `CampsBlueprint.php` |
| Une capacité d'inscription, une demande | `RegistrationBlueprint.php` |
| Le montant de la campagne, l'état de sa réconciliation | `CampaignBlueprint.php` |
| Une bannière et son `role_min` | `BannerBlueprint.php` |
| Le local en location, son tarif, ses réservations | `RentalBlueprint.php` |
| Quels badges chaque section porte | `StaffBlueprint.php` |

Seuls les quatre premiers touchent des fichiers **générés** — les autres sont
lus au moment de la construction, et n'ont rien à régénérer. Après une
modification de l'un des quatre, relancer le générateur et committer ce qu'il
a écrit.

**Un cas mérite un mot** : `UnitBlueprint::SECTION_LEAD_FUNCTION`
(« Chef de section ») est distribuée par le générateur — exactement un cadre
par section et par année, choisi en post-passe et **sans tirer une seule fois
dans le Rng**, parce qu'un tirage supplémentaire décalerait tous les suivants
et réécrirait l'ensemble du jeu de données. `ReferenceDatasetFormatTest`
vérifie l'unicité section par section et année par année ; `StaffSeeder` pose
la marque `is_lead` correspondante et signale toute section restée sans
responsable.

Le tirage est déterministe : un xorshift32 écrit sur place plutôt que `mt_rand()`,
parce que le déterminisme de `mt_rand()` est une propriété d'implémentation du
moteur et non une promesse — il a déjà changé une fois — et que `--check`
échouerait alors sur une simple montée de version de PHP.

### 9.1 Les trois exports Desk

| Année | Membres | Lignes |
|---|---|---|
| 2024-2025 | 176 | 266 |
| 2025-2026 | 178 | 276 |
| 2026-2027 | 178 | 279 |

Une ligne par (fonction × adresse) : les adresses sont dédupliquées par
`Type d'adresse` dans le parseur, les fonctions ne le sont pas — le parseur
rend bien deux `ParsedFunction` identiques. C'est `DeskImportService` qui les
réduit à une seule ligne `member_functions`, juste avant l'écriture : deux
lignes identiques en tous points décrivent une fonction dite deux fois. Deux
lignes qui diffèrent sur n'importe quel champ (« Animateur / Louveteaux » et
« Animateur / Baladins ») restent deux fonctions.

#### Le vocabulaire des FONCTION

Ce sont les libellés qu'un vrai export Desk d'unité belge porte, au caractère
près. La table `UnitBlueprint::FUNCTIONS` en donne le rôle que Config Desk sera
prié de confirmer — le CSV, lui, ne porte jamais de rôle.

| Niveau | Fonction | Rôle confirmé |
|---|---|---|
| Section | `Animé` | `identified` |
| Section | `Animateur` | `chief` |
| Section | `Candidat animateur` | `chief` |
| Section | `Animateur responsable` | `chief` |
| Section | `Intendant` | `intendant` |
| Section | `Candidat intendant` | `intendant` |
| Unité | `Animateur d'unité` | `admin` |
| Unité | `Équipier d'unité` | `admin` |
| Unité | `Collaborateur d'unité` | `admin` |

Le jeu inventait auparavant quatre libellés que Desk ne produit pas — `Chef
d'unité`, `Intendant d'unité`, `Trésorier d'unité`, `Accompagnateur d'unité`.
Deux conséquences étaient invisibles : rien ne portait `Animateur responsable`,
donc le drapeau *responsable* du trombinoscope et
`Core\Module\SectionResponsableProvider` n'avaient aucune donnée du tout ; et
`Trésorier d'unité` disait dans une FONCTION ce que l'application modélise par
un **badge** (`Core\Badge`, que `ExtrasBlueprint` attribue déjà à `T0017` les
trois années), soit le même fait sous deux formes qui pouvaient diverger.

L'orthographe compte : **`Candidat animateur`**, le mot « candidat » d'abord.
`CandidateDetector` cherche la sous-chaîne « candidat » sans tenir compte de la
casse ni des accents, donc l'ordre des mots lui est indifférent — il est fixé
ici par fidélité, pas par contrainte technique. `FunctionRepository::findAll()`
trie par libellé : les deux `Candidat …` se rangent sous C, loin d'`Animateur`,
et c'est le comportement voulu.

Trois règles que le générateur tient :

- **exactement un `Animateur responsable` par section et par année** — c'est le
  responsable désigné, celui que la page publique Sections nomme, que la fiche
  membre affiche avec son adresse postale et que le trombinoscope met en avant ;
- **les trois fonctions d'unité tournent** — `UNIT_STAFF_SIZE` vaut 4 ou 5 par
  an et la rotation garantit au moins un exemplaire de chacune, donc Staff d'U
  est un staff et non quatre copies du même intitulé ;
- **une FONCTION reste délibérément hors de la table** —
  `UnitBlueprint::BRAND_NEW_FUNCTION` (`Délégué de branche`), qui n'apparaît
  qu'en A3. `DeskImportReplay::confirmFunctionRoles()` la laisse non confirmée,
  ce qui est le cas « une fonction toute neuve qu'aucun chef n'a encore vue dans
  Config Desk ». Sans elle, ce cas disparaîtrait du jeu.

### 9.2 Les scénarios

Les 24 scénarios sont déclarés dans `ScenarioCatalog::SCENARIOS`, avec pour
chacun son nom, les `Tiers` qui le portent et ce qu'on doit pouvoir observer.
Les `Tiers` sont **épinglés** et non dérivés : un identifiant calculé se
décalerait le jour où quelqu'un insère une personne au-dessus, et toutes les
assertions bougeraient avec lui. `T0001`-`T0099` sont réservés aux scénarios,
la population de fond commence à `T0101`.

### 9.3 Les relevés bancaires

Six fichiers commités : deux comptes × trois exercices. Un seul format existe,
`bnp` (`BankStatementParserFactory::getSupportedBankCodes()`).

**Un septième relevé est écrit à la construction et n'est pas commité** : les
paiements de la campagne (§8.3). Ses lignes portent les communications
structurées que `CampaignService` a tirées au hasard en créant les créances,
qui n'existent donc pas avant la construction. Il est produit par le même
`BnpCsvWriter` et importé par le même `ImportService`, avec des références
bancaires préfixées `9` pour qu'aucune de ses lignes ne puisse être confondue
avec la répétition d'une ligne d'un fichier commité.

| Compte | IBAN | Solde d'ouverture |
|---|---|---|
| Compte d'unité | `BE27 0000 0000 0001` | 4 250,00 € |
| Compte camps | `BE97 0000 0000 0002` | 1 875,00 € |

Les comptes de section, eux, n'ont pas de relevé : ils reçoivent seulement un
IBAN, **calculé** par `BankBlueprint::sectionIban()` avec ses vraies clés de
contrôle ISO 13616 plutôt que listé — il y en a un par section, les sections
sont déclarées ailleurs, et une seconde liste à tenir à jour est une seconde
liste à se tromper. Le code banque `000` n'est attribué à aucune institution
belge : aucun de ces comptes n'appartient à personne.

**Un exercice comptable est une année scoute** : `FiscalYearRepository::findForDate()`
le résout directement dans `scout_years`, et l'import refuse toute ligne
qu'aucun exercice ne couvre. Chaque date tombe donc entre le 1er septembre
2024 et le 31 août 2027.

Cas placés délibérément, un par méthode de `BankStatementBuilder` :

- des **cotisations** portant une vraie communication structurée, calculée par
  `StructuredCommunicationService::format()` — la même liste sert à créer les
  créances attendues en IT-06, sinon la page « Paiements attendus » ne
  réconcilie rien ;
- une ligne **`Refusé`**, que `BnpParser` ignore : elle n'a jamais eu lieu sur
  le compte ;
- un montant à **séparateur de milliers** (`1.284,50`) ;
- un montant en **décimale pointée** (`-35.98`) — lu comme un séparateur de
  milliers, il s'importerait en −3 598,00 € sans la moindre erreur ;
- une ligne **sans communication**, dont le libellé retombe sur la colonne
  `Détails` ;
- des libellés qui **déclenchent les règles de catégorisation** par défaut ;
- un **virement entre les deux comptes de l'unité**, débit d'un côté et crédit
  du même montant le même jour de l'autre ;
- les **trois dernières lignes de l'exercice précédent répétées** en tête du
  fichier suivant, avec la même `REFERENCE BANQUE` — ce qu'un vrai
  téléchargement « quinze derniers mois » produit, et la seule façon d'exercer
  la déduplication.

La clé de déduplication est `REFERENCE BANQUE : <chiffres>` dans la colonne
`Détails`, jamais le `Nº de séquence` — BNP Fortis écrit la même chaîne sur
toutes les lignes d'un export.

### 9.4 La correspondance photo → Tiers

`photos/assignments.csv`, généré et commité, comparé par `--check` : une photo
ajoutée ou retirée sans régénération devient une erreur. Colonnes
`file;kind;target;year;note` — `target` est un `Tiers` pour une photo
individuelle, un identifiant de section pour une photo de groupe.

L'attribution des **photos de groupe** est écrite à la main dans
`PhotoLot::GROUP_PHOTOS` : chaque ligne est une décision sur ce que le site doit
être vu en train de faire. Celle des **portraits** est calculée puis commitée :
elle dépend de quels cadres existent, ce que seul le générateur sait.

Une note `hors-prefixe` signale un portrait attribué à un cadre d'une autre
branche que ne le suggère son préfixe — c'est permis, le préfixe n'est qu'une
indication. Une note `rephotographie` signale la deuxième photo d'un cadre déjà
photographié une année antérieure.

## 10. Comptes de démonstration

**Mot de passe, pour tous : `Reference-Dataset-2026!`**

Ce sont des identifiants de démonstration assumés, publiés ici en clair. Une
instance construite avec ce jeu de données **n'est pas une instance de
production** — voir l'avertissement en tête de ce fichier.

| Compte | Adresse | Ce qu'il montre |
|---|---|---|
| superadmin | `superadmin@example.com` | Le seul compte sans membre derrière : celui de l'installation. |
| chef d'unité | l'email de `T0015` | Animateur en A1, Animateur d'unité ensuite (scénario 10) — l'un des membres par qui Staff d'U se peuple. |
| intendant | l'email de `T0016` | Intendant des Pionniers les trois années (scénario 11) : voit les Finances sans être chef. |
| chef de section | l'email de `T0014` | Animateur qui change de section entre A1 et A2 (scénario 9). |
| animé | l'email de `T0012` | Éclaireur qui gagne son totem entre A1 et A2 (scénario 7). |
| parent | l'email de `T0020` | Aîné d'une fratrie de trois partageant l'email d'un parent (scénario 17). |

Les cinq derniers ne sont pas des identités parallèles : le builder retrouve le
compte que l'import Desk a créé pour ce membre et lui pose un mot de passe. Le
rôle obtenu à la connexion est donc **dérivé par le site** de leurs fonctions
confirmées (`Core\Security\RoleResolver`), pas écrit à la main — c'est
précisément ce que le jeu de données doit démontrer. Le builder affiche les
adresses exactes à la fin de son exécution.

## 11. Repartir d'un état propre entre deux essais

Une fois le jeu de données construit, une **sauvegarde Maintenance ordinaire** de
l'instance sert de point de restauration jetable : elle se restaure sur la même
installation, avec les mêmes clés, donc sans aucun des problèmes de portabilité
décrits au §1. C'est la façon de récupérer la rapidité d'un dump sans en avoir les
inconvénients.

L'autre chemin, quand aucune sauvegarde n'a été prise ou qu'elle date d'un état
qu'on ne veut plus, est `--reset` (§8.4) : il vide l'instance et reconstruit à
partir de la recette. C'est plus long qu'une restauration — une quinzaine de
secondes contre quelques-unes — mais cela ne suppose rien d'avoir été prévu à
l'avance, et le vidage prend sa propre sauvegarde au passage.

---

## 12. Rappels croisés

Ce jeu de données ne se maintient pas tout seul, et rien ne le protège d'un
changement fait ailleurs sans y penser. Quatre rappels le disent là où le
changement se fait :

| Où | Ce qui y est dit |
|---|---|
| `AGENTS.md` § Reference dataset | La liste des changements qui obligent à vérifier ce jeu de données **dans le même changement** : format d'export Desk, `DeskCsvParser`, `BnpParser`, pipeline d'import, schéma d'une table liée aux membres. |
| `Core\Import\DeskCsvParser` | Un en-tête de classe qui renvoie ici, et qui redit que `Sizaine/Patrouillle` et l'ignorance de `SECTION` ne sont pas des coquilles. |
| `Modules\Finance\Parser\BnpParser` | Idem, avec la liste des cas que les six relevés contiennent délibérément. |
| `ARCHITECTURE.md` §12 | La place de ce répertoire dans la carte du projet, et le fait qu'il est le seul sous-répertoire de `tests/` listé dans les `paths` de `phpstan.neon`. |

Et trois garde-fous mécaniques, qui échouent au lieu de dériver :

- `generate.php --check`, invoqué par `ReferenceDatasetFormatTest` : les
  fichiers commités correspondent au générateur, octet pour octet, et aucune
  photo du lot n'est orpheline ;
- `ReferenceDatasetImportTest` : les exports veulent toujours dire ce qu'ils
  disent, rejoués par le vrai pipeline ;
- `ReferenceDatasetBuilderTest` : ce que le builder écrit par-dessus.

Un quatrième, qui ne vit pas ici : `tests/e2e/specs/all-modules-enabled.spec.js`
vérifie, sur l'instance jetable du harnais de bout en bout, que **tous les
modules sont actifs et que le site démarre avec eux**. C'est la même invariante
que le §8.1 impose au builder, épinglée là où elle est observable dans un
navigateur ; le scénario n'a pas besoin d'être étendu pour la construction du
jeu de données, parce que le builder rend compte lui-même de ce qu'il a activé
et de ce qui a refusé — et qu'ajouter un scénario de navigateur sur le
*contenu* du jeu de données irait contre la règle de ce répertoire (le contenu
se vérifie par le rapport du builder, pas au navigateur).

### 12.1 Ce qui n'a pas pu être vérifié

`build.php` rejoue toute l'application contre une vraie base et prend
`GET_LOCK('scoutmagic_schema_migration')`, un verrou **serveur** MySQL.
Sur une machine partagée, le lancer casse la suite de tests de tout le monde.
Le lot IT-18 a donc été écrit sans jamais être construit, et c'est une limite
assumée de cette voie : le builder « se vérifie sur une instance jetable,
jamais sur celle qui sert à valider les autres voies ». Ce qui tient la ligne
en attendant, c'est `vendor/bin/phpstan analyse` — ce répertoire est dans ses
`paths` précisément parce qu'il compose des services à la main comme une
racine de composition, et casse de la même façon.

Deux choses restent à constater à la première construction, en plus des
compteurs du §8.2 :

- **l'album externe de la galerie.** `AlbumService::create()` va chercher les
  balises Open Graph de la cible ; le réseau sortant est restreint sur les
  machines où ce lot a été écrit, donc le lien n'a **pas** pu être vérifié.
  L'échec est sans conséquence par construction — le scrape est *best effort*,
  et un album dont la cible n'a pas répondu est créé quand même, avec le titre
  déclaré et sans vignette, ce qui est un des états que le site doit savoir
  afficher — mais si la vignette manque après une construction en ligne, c'est
  le lien qu'il faut regarder.
- **les refus des modules**, s'il y en a : le rapport du builder imprime chaque
  module qui n'a pas voulu s'activer avec la raison qu'il a donnée, et chaque
  réservation ou section que son module a refusée.
