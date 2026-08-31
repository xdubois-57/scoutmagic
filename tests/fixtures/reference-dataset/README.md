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
  FinanceSeeder.php      comptes bancaires + import des six relevés
  DemoAccounts.php       LA TABLE : quel membre porte quel rôle de démo
  ExtrasBlueprint.php    LA TABLE : décalages, départs, badges, évènements
  ExtrasApplier.php      applique les extras par les vrais services
  desk/                  les trois exports Desk générés, commités
  bank/                  les six relevés BNP générés, commités
  photos/                le lot de photos (§4) + assignments.csv, généré
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
2. **Les trois années scoutes et les trois imports Desk**, dans l'ordre
   chronologique, par le vrai `DeskImportService` — le même
   `DeskImportReplay` que le test d'import de bout en bout.
3. **La confirmation des rôles**, par le chemin de Config Desk
   (`FunctionRepository::updateRole(..., true)` puis
   `UnitStaffSectionService::syncMembership()` sur les trois années). C'est le
   seul endroit d'où Staff d'U peut naître.
4. **Les finances** : catégories par défaut, puis comptes de section, puis les
   deux comptes d'unité, puis les six relevés. **L'ordre est porteur** —
   `ensureDefaultCategories()` ne sème que tant que la table est vide, et créer
   un compte y ajoute déjà sa catégorie « Virement <compte> ».
5. **Les comptes de démonstration** adossés à des membres, après les imports
   qui les créent.
6. **Un rapport en français** : effectifs par année, sections actives et
   inactives, compteurs financiers.

### 8.2 Résultat constaté sur une instance jetable

| Contrôle | Valeur |
|---|---|
| Membres actifs | 178 / 180 / 180 |
| Fonctions inédites | 5 en A1, 1 en A2, 1 en A3 |
| Fonctions confirmées | 7 |
| Staff d'U (rôle admin confirmé) | 13 rattachements sur les trois années |
| Mouvements financiers importés | 125 |
| Doublons reconnus | 12 — les 2 comptes × 2 années × 3 lignes de recouvrement |
| Catégories | 13, dont les 2 de virement interne |
| Mouvements catégorisés | 105 sur 125 |
| Sections | 8 + Staff d'U, `Iama Horizon` inactive |

Les 20 mouvements non catégorisés sont les cotisations à communication
structurée : elles se réconcilient contre des créances attendues (§8.3), pas
contre une règle de libellé.

### 8.3 Les extras, et le sous-ensemble couvert

Les extras sont tout ce que Desk ne connaît pas. Ils sont déclarés dans
`ExtrasBlueprint` et appliqués par `ExtrasApplier`, **toujours par les vrais
services** — jamais une écriture directe dans `member_photos`, `member_badges`
ou `finance_expected_receivables`.

**Couverts :**

| Extra | Ce qu'il apporte |
|---|---|
| Décalages d'année | 2 membres, dont `T0009` en A1 — l'héritage par-dessus l'année manquante (scénario 5). |
| Départs marqués | 3, avec commentaire : deux qui se réalisent, un qui ne se réalise pas. C'est à quoi ressemble une grille « Départs » en mars. |
| Badges | 5 attributions sur `Infirmier` et `Trésorier`, après le semis des badges par défaut et des badges référents de section. |
| Photos individuelles | 43, par `PhotoIngestionService` — le pipeline de `/upload`. |
| Photos de groupe | 14, recadrées en 4:3 avant stockage. |
| Évènements de calendrier | 9, sur les calendriers de section et le calendrier d'unité. |
| Créances attendues | 17, une par communication structurée. Le montant dû (65 €) n'est **pas** le montant payé : certains foyers sont à jour, d'autres non — une page de réconciliation où tout est soldé ne montre rien. |

**Non couverts, délibérément.** Le chantier demandait « une couverture large
des modules, pas l'exhaustivité », et autorisait explicitement à proposer un
sous-ensemble plutôt que de livrer une couverture partielle non documentée.
Manquent donc : **documents de section**, **articles d'actualité avec
formulaire**, **groupes de discussion et messages**, **demandes d'inscription**,
**bien en location avec réservation**.

La raison est la même pour les cinq : chacun demande de recomposer la chaîne
complète de son module — notifications, planificateur, stockage chiffré,
parfois un connecteur IA optionnel — soit beaucoup de surface de câblage pour
une fixture, et autant de constructeurs à suivre à chaque évolution. Les sept
extras couverts sont ceux dont le reste du jeu de données a besoin pour avoir
du sens : sans les photos le trombinoscope est vide, sans les créances les
vingt cotisations ne se réconcilient contre rien, sans le décalage d'année le
scénario 5 n'est pas observable.

**Un module désactivé sur l'instance cible est ignoré, pas fatal — et le saut
est signalé.** `ExtrasApplier` vérifie que les tables du module existent avant
d'écrire : un module désactivé n'a pas de tables, et c'est une configuration,
pas une panne. Le builder affiche alors explicitement
`(ignoré : module « calendar » désactivé)` à côté du compteur.

Ce n'est pas un détail cosmétique : un compteur à zéro se lit exactement pareil
que le module soit désactivé ou que le nom de table dans le code soit faux — et
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
| Un comportement nommé à exercer | `ScenarioCatalog.php`, puis `ScenarioPeople.php` |
| Quelle photo de groupe va à quelle section | `PhotoLot::GROUP_PHOTOS` |
| Le genre d'un portrait ajouté au lot | `PhotoLot::INDIVIDUAL_GENDERS` |

puis relancer le générateur et committer ce qu'il a écrit.

Le tirage est déterministe : un xorshift32 écrit sur place plutôt que `mt_rand()`,
parce que le déterminisme de `mt_rand()` est une propriété d'implémentation du
moteur et non une promesse — il a déjà changé une fois — et que `--check`
échouerait alors sur une simple montée de version de PHP.

### 9.1 Les trois exports Desk

| Année | Membres | Lignes |
|---|---|---|
| 2024-2025 | 178 | 266 |
| 2025-2026 | 180 | 274 |
| 2026-2027 | 180 | 278 |

Une ligne par (fonction × adresse) : les adresses sont dédupliquées par
`Type d'adresse` dans le parseur, les fonctions ne le sont pas — le parseur
rend bien deux `ParsedFunction` identiques. C'est `DeskImportService` qui les
réduit à une seule ligne `member_functions`, juste avant l'écriture : deux
lignes identiques en tous points décrivent une fonction dite deux fois. Deux
lignes qui diffèrent sur n'importe quel champ (« Animateur / Louveteaux » et
« Animateur / Baladins ») restent deux fonctions.

### 9.2 Les scénarios

Les 24 scénarios sont déclarés dans `ScenarioCatalog::SCENARIOS`, avec pour
chacun son nom, les `Tiers` qui le portent et ce qu'on doit pouvoir observer.
Les `Tiers` sont **épinglés** et non dérivés : un identifiant calculé se
décalerait le jour où quelqu'un insère une personne au-dessus, et toutes les
assertions bougeraient avec lui. `T0001`-`T0099` sont réservés aux scénarios,
la population de fond commence à `T0101`.

### 9.3 Les relevés bancaires

Six fichiers : deux comptes × trois exercices. Un seul format existe,
`bnp` (`BankStatementParserFactory::getSupportedBankCodes()`).

| Compte | IBAN | Solde d'ouverture |
|---|---|---|
| Compte d'unité | `BE00 0000 0000 0001` | 4 250,00 € |
| Compte camps | `BE00 0000 0000 0002` | 1 875,00 € |

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
| chef d'unité | l'email de `T0015` | Animateur en A1, Chef d'unité ensuite (scénario 10) — le membre par qui Staff d'U se peuple. |
| intendant | l'email de `T0016` | Intendant d'unité les trois années (scénario 11) : voit les Finances sans être chef. |
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
