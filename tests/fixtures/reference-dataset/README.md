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
  PhotoLot.php           LA TABLE : genre de chaque portrait, photos de groupe
  PhotoAssigner.php      attribue les portraits aux cadres
  desk/                  les trois exports Desk générés, commités
  photos/                le lot de photos (§4) + assignments.csv, généré
  bank/                  les relevés bancaires générés           — IT-04
  build.php              builder CLI                             — IT-05/IT-06
```

Les entrées marquées d'une itération n'existent pas encore.

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
   régénérée et comparée par `generate.php --check` — voir §9.3.

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

- **`DeskImportService::import()` supprime le fichier CSV qu'on lui donne**
  (`@unlink($filePath)`). Le builder et les tests copient toujours le fichier
  versionné vers un temporaire avant l'appel — c'est déjà ce que fait
  `tests/Core/Import/DeskImportServiceTest.php`. Pointer directement sur un
  fichier de `desk/` le détruirait.
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
entre les deux appelants. Livrée par son itération propre, avec ses tests, avant
l'itération des extras.

## 8. Construction sur une instance de test

*À compléter en IT-05.* Le builder n'existe pas encore.

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
`Type d'adresse` dans le parseur, les fonctions ne le sont pas.

### 9.2 Les scénarios

Les 24 scénarios sont déclarés dans `ScenarioCatalog::SCENARIOS`, avec pour
chacun son nom, les `Tiers` qui le portent et ce qu'on doit pouvoir observer.
Les `Tiers` sont **épinglés** et non dérivés : un identifiant calculé se
décalerait le jour où quelqu'un insère une personne au-dessus, et toutes les
assertions bougeraient avec lui. `T0001`-`T0099` sont réservés aux scénarios,
la population de fond commence à `T0101`.

### 9.3 La correspondance photo → Tiers

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

*À compléter en IT-05.* Les mots de passe seront documentés ici, en clair et
assumés comme tels — voir l'avertissement en tête de ce fichier.

## 11. Repartir d'un état propre entre deux essais

Une fois le jeu de données construit, une **sauvegarde Maintenance ordinaire** de
l'instance sert de point de restauration jetable : elle se restaure sur la même
installation, avec les mêmes clés, donc sans aucun des problèmes de portabilité
décrits au §1. C'est la façon de récupérer la rapidité d'un dump sans en avoir les
inconvénients.
