# Chantier — Jeu de données de référence

Journal d'implémentation du chantier « Jeu de données de référence
ScoutMagic » (itérations IT-01 à IT-07, plus IT-05bis). Une section par
itération : ce qui a été fait, les décisions prises en autonomie, les
divergences constatées entre le document de chantier et le dépôt réel, et ce
qui a été reporté.

Le jeu de données lui-même, et la façon de s'en servir, sont documentés dans
`tests/fixtures/reference-dataset/README.md`. Ce fichier-ci est le journal du
chantier, pas le mode d'emploi.

---

## Décisions prises avec le mainteneur avant IT-01

Cinq questions ont été posées avant d'écrire la moindre ligne, chacune parce
que le document de chantier laissait une contradiction ou une impossibilité.

1. **Ancrage des années scoutes → figées, sans réglage.** Trois libellés en
   dur (`2024-2025`, `2025-2026`, `2026-2027`), aucun `current_scout_year_id`
   posé par le builder. Le site retombe sur son année date-calculée. Écarté :
   des années relatives à la date de construction, qui feraient échouer
   `generate.php --check` à chaque rentrée puisque les fichiers générés sont
   commités et comparés à l'octet près.
2. **Lot de photos → ré-encodage JPEG.** 117,8 Mo de PNG ramenés à 11,6 Mo.
   Écarté : la purge d'historique (`git filter-repo`), qui seule ferait
   rétrécir le `.git` mais imposerait un force-push sur `main`.
3. **Téléversement des photos → le contrôleur devient appelable en CLI.**
   `UploadController::store()` exige un jeton CSRF, une session
   authentifiée, `ConfigurationMode` et un `$_FILES` : la consigne du
   document (« par son vrai chemin de téléversement ») était littéralement
   inapplicable. Le mainteneur a choisi de lever sa propre règle « rien sous
   `core/` » plutôt que de laisser le builder recomposer le pipeline de son
   côté.
4. **Forme de l'extraction → un nouveau service core.** Un
   `Core\Photo\PhotoIngestionService` appelé par le contrôleur comme par le
   builder, plutôt qu'une méthode publique sur le contrôleur (qui en ferait
   un service déguisé) ou une logique répartie par famille de photo (qui
   dupliquerait le pipeline). Ajoute **IT-05bis** au découpage.
5. **Photos et années → majorité en A1 seulement.** Le document affirmait que
   poser la photo en A1, A2 et A3 « exerce la résolution par année de
   `MemberPhotoService` ». C'est faux : `resolveFileId()` a le même repli sur
   l'année antérieure que les photos de groupe, donc trois lignes explicites
   font toujours mouche sur le chemin exact et n'exercent jamais le repli. La
   plupart des cadres ne sont donc photographiés qu'en A1 ; deux ou trois le
   sont chaque année, pour couvrir aussi le chemin exact et le remplacement.

## Divergences constatées entre le document de chantier et le code

Vérifiées une par une dans le dépôt, avant IT-01 puis reconfirmées après
rebase sur `main`.

| Le document dit | Le code dit |
|---|---|
| « les 34 en-têtes de `EXPECTED_HEADERS` » | Il y en a **35**. |
| « Séparateur `;` » | `DeskCsvParser::detectDelimiter()` autodétecte `;` ou `,` (cf. `desk_export_comma.csv`). La consigne reste bonne pour la génération, mais ce n'est pas une contrainte du parseur. |
| — | L'en-tête réel est `Sizaine/Patrouillle`, avec **trois L**. L'orthographe correcte fait échouer `validateHeaders()`. |
| — | **`DeskImportService::import()` supprime le CSV** qu'on lui donne (`@unlink`). Non mentionné, et destructeur pour des fixtures commitées. |
| — | `ScoutYearService::ensureYear()` insère toujours `is_current = 1`, et rien ne remet jamais la colonne à 0. |
| « photo posée en A1/A2/A3 exerce la résolution par année » | Faux — voir décision 5 ci-dessus. |
| « passer par `UploadController` » | Impossible en CLI — voir décision 3 ci-dessus. |

Confirmés exacts, en revanche : l'exclusion de `tests/*` du zip de release et
l'absence de `scripts/` de cette liste, le garde CLI de `public/cron.php`,
l'absence de `tests/` dans `phpstan.neon`, la résolution de branche par
sous-chaîne, l'absence de Route et Iama de `MemberYearService::BRANCHES`, les
tranches d'âge canoniques, le calcul de l'âge sur l'année de début, la double
lecture de `Section` et l'ignorance de `SECTION`, la déduplication des
adresses par type et la non-déduplication des fonctions, la synthèse de Staff
d'U depuis le rôle `admin`, la désactivation-puis-réactivation des sections à
chaque import, l'héritage de `scout_year_offset` ordonné par `start_date`,
`bnp` comme seul format bancaire, `REFERENCE BANQUE` comme clé de
déduplication, l'exercice comptable résolu dans `scout_years`, les exclusions
de `BackupService::createFileBackup()`, le recadrage 4:3/1600 px, le plafond
de 50 Mpx et le filtre `chief`/`admin` de `getSectionStaff()`.

---

## IT-01 — Fondations

**Livré.**

- **Lot de photos assaini.** `pionnier_group_003.png` supprimé (doublon md5
  exact de `pionnier_group_001.png`), les 54 fichiers restants ré-encodés en
  JPEG qualité 82 sans redimensionnement — aucune image n'atteignait le
  plafond de 1600 px — et les séries renumérotées en continu, 11 fichiers
  ayant changé de numéro. 117,8 Mo → 11,6 Mo, soit 9,8 % du volume initial.
- **Inventaire reporté** dans `tests/fixtures/reference-dataset/README.md`
  §4 : 40 individuelles et 14 de groupe, réparties par branche, avec la
  couverture de staffs que cela permet et la règle explicite qu'un cadre sans
  photo est un état normal du site, jamais un défaut à corriger.
- **Contraintes techniques vérifiées et documentées.** Toutes les images sont
  très en dessous des 50 Mpx d'`ImageDimensionGuard`. Le recadrage 4:3 de
  `SectionPhotoProcessor` a été exécuté sur les 14 photos de groupe et le
  résultat inspecté : huit sont déjà en 4:3 exact, six perdent 5,5 % de
  chaque côté, une perd 3 % en haut et en bas, et personne n'est coupé.
- **README initial**, qui porte aussi la liste des pièges du pipeline
  constatés dans le code (§6) et l'exception assumée au « rien sous `core/` »
  (§7).
- **Vérification post-zip dans `scripts/release.sh`** : un artefact contenant
  `reference-dataset` fait échouer la release, à côté des vérifications
  existantes sur `.htaccess`, `vendor/autoload.php` et `node_modules`. Le
  répertoire était déjà couvert par l'entrée `tests/*` de la liste `-x` ;
  cette vérification transforme une exclusion tacite en garde-fou explicite.
- **`tests/fixtures/reference-dataset` ajouté aux `paths` de
  `phpstan.neon`**, avec le commentaire qui dit pourquoi ce répertoire précis
  et pas `tests/` en entier.

**Décisions prises en autonomie.**

1. **Le script de conversion des photos n'est pas commité.** C'est une
   opération unique : après elle, les JPEG *sont* la matière première, et les
   PNG d'origine restent récupérables dans l'historique git. Un script de
   conversion versionné laisserait croire que le lot est régénérable, ce
   qu'il n'est pas.
2. **Le squelette du répertoire se limite à ce qui existe.** `desk/`,
   `bank/`, `generate.php` et `build.php` sont décrits dans le README §3 avec
   l'itération qui les livrera, plutôt que créés vides avec des `.gitkeep`
   qu'une itération ultérieure supprimerait.
3. **Ce journal de chantier a été créé** sur le modèle de
   `docs/chantiers/support-statistics.md`, qui est la convention du dépôt pour
   un chantier pluri-itérations. Le document de chantier ne le demandait pas.
4. **Le README est en français**, comme `README.md` à la racine,
   `docs/rental-guide.md` et `docs/inbound-mail-setup.md` — la convention du
   dépôt réserve l'anglais aux documents purement développeur
   (`AGENTS.md`, `ARCHITECTURE.md`, `docs/module-development.md`). Le code et
   les commentaires PHP à venir resteront en anglais.

**Reporté.**

- La correspondance photo → `Tiers` et le relevé du genre apparent de chaque
  photo individuelle appartiennent à IT-02 : ils se dérivent des `Tiers`, qui
  n'existent pas avant le générateur, et se référeront aux noms de fichiers
  définitifs issus de la renumérotation.


---

## IT-02 — Générateur Desk, table des scénarios, les trois exports

**Livré.**

- **`generate.php`** — point d'entrée CLI (garde `PHP_SAPI !== 'cli'` de
  `public/cron.php`) avec un mode `--check` qui régénère tout en mémoire et
  compare octet par octet aux fichiers commités.
- **Les tables déclaratives.** `UnitBlueprint` porte les sections, les
  effectifs par section et par année, les fonctions et leur rôle cible, les
  viviers de noms, de rues et de communes. `ScenarioCatalog` porte les 24
  scénarios : nom, `Tiers` épinglés, ce qu'on doit pouvoir observer.
  `PhotoLot` porte le genre de chacun des 40 portraits et l'attribution des 14
  photos de groupe. Maintenir le jeu de données veut dire éditer l'une de ces
  trois tables et relancer.
- **Les 33 membres de scénario**, écrits à la main dans `ScenarioPeople`, un
  bloc par scénario. Les années de naissance sont choisies pour que la branche
  tombe de l'arithmétique que fera `MemberYearService::getEffectiveAge()` —
  année de référence moins année de naissance — et non d'une règle déclarée :
  un passage de frontière est une année de naissance, pas une consigne.
- **La population de fond**, vieillie d'année en année par `PopulationBuilder`
  plutôt que tirée indépendamment à chaque année : tout le monde prend un an,
  la branche est recalculée depuis l'âge, puis seulement départs et arrivées
  ramènent chaque section à son effectif déclaré.
- **Les trois exports Desk**, générés et commités : 178, 180 et 180 membres
  pour 266, 274 et 278 lignes — dans la fourchette de 170-190 membres et
  250-300 lignes qu'un vrai export de cette taille produit.
- **`photos/assignments.csv`**, généré et commité : 43 attributions
  individuelles et 14 de groupe, aucune photo orpheline.
- **`Tests\Integration\ReferenceDatasetFormatTest`** — 11 tests : chaque export
  passe par le vrai `DeskCsvParser`, les fichiers commités correspondent au
  générateur, aucune photo n'est orpheline, chaque portrait a un genre déclaré,
  chaque attribution pointe sur quelque chose qui existe, le `Genre` du membre
  ne contredit jamais sa photo, et un portrait n'appartient jamais qu'à un
  cadre. Placé dans `tests/Integration/`, suite déjà déclarée : aucun nouveau
  `<testsuite>` n'était nécessaire.

**Décisions prises en autonomie.**

1. **Un xorshift32 écrit sur place plutôt que `mt_rand()`.** Le déterminisme de
   `mt_rand()` est une propriété d'implémentation du moteur, pas une promesse
   faite aux appelants — il a déjà changé une fois, au correctif de biais de
   PHP 7.1. Comme les fichiers générés sont comparés à l'octet près, `--check`
   échouerait alors sur une simple montée de version de PHP, avec rien dans le
   jeu de données qui ait bougé. Trente lignes d'arithmétique coûtent moins
   cher que ce diagnostic.
2. **Une entrée `autoload-dev` ajoutée à `composer.json`**
   (`Tests\Fixtures\ReferenceDataset\` → `tests/fixtures/reference-dataset/`).
   PSR-4 mappe les segments de namespace littéralement sur des noms de
   répertoire, et le chantier impose un répertoire en minuscules avec un tiret :
   sans cette entrée, aucune classe n'est autochargeable. Ce n'est pas une
   dépendance nouvelle, et `autoload-dev` disparaît d'un
   `composer install --no-dev`.
3. **La table des scénarios est un fichier dédié, pas l'en-tête de
   `generate.php`.** Le chantier demandait « la table des scénarios en tête de
   fichier » ; il y a trois tables (unité, scénarios, photos) et les mettre
   toutes en tête d'un même fichier l'aurait rendu illisible. L'intention —
   éditer une table plutôt que 900 lignes de CSV — est tenue, et le README §9
   dit où porter chaque type de modification.
4. **Les `Tiers` des scénarios sont épinglés, pas dérivés.** `T0001`-`T0099`
   sont réservés, la population de fond commence à `T0101`. Un identifiant
   calculé se décalerait le jour où quelqu'un insère une personne au-dessus, et
   toutes les assertions bougeraient avec lui — exactement la dérive
   silencieuse que le §4 du chantier existe pour empêcher. Le test refuse aussi
   un `T00xx` qui occuperait la plage réservée sans être déclaré au catalogue.
5. **L'attribution des portraits est calculée puis commitée ; celle des photos
   de groupe est écrite à la main.** Une photo de groupe va à une section : la
   décision est signifiante et tient en quatorze lignes. Un portrait va à un
   cadre, et quels cadres existent n'est connu que du générateur. Les deux
   passent par `--check`, donc ni l'une ni l'autre ne peut se décaler en
   silence.
6. **Le genre apparent de chaque portrait a été relevé une fois et versionné**
   dans `PhotoLot::INDIVIDUAL_GENDERS`. Rien dans un JPEG ne le dit, et une
   incohérence entre la fiche et la photo affichée à côté n'est visible que
   pour un humain qui regarde la page. Le générateur refuse un portrait dont le
   genre n'est pas déclaré, plutôt que de deviner.
7. **Deux staffs ont été étoffés** (Meute de Seeonee 4→5 cadres, Troupe du
   Faucon 5→6, sur les trois années). Le lot compte 24 portraits masculins et
   l'unité n'offrait que 23 cadres masculins distincts : une photo serait
   restée orpheline. Le chantier autorise explicitement d'agrandir un staff
   pour consommer le reliquat — jamais l'inverse.
8. **T0028 (handicap) quitte la section Iama en A3.** Il y était les trois
   années, ce qui annulait en silence le scénario 15 : Iama Horizon doit être
   vidée en A3 pour que la section passe inactive. Il a 15 ans en A3, le
   passage aux Éclaireurs est légal en âge.
9. **Deux générateurs aléatoires distincts**, l'un pour la population et
   l'autre pour les photos, graines décalées. Ajouter une photo au lot ne peut
   ainsi déplacer aucun octet des CSV.

**Divergence constatée.**

- Le chantier annonçait « environ 46 % » de filles ; le tirage réel donne 42,
  39 et 40 % selon l'année. L'attente du catalogue a été réécrite en
  l'invariant qui compte réellement — la part de F n'est jamais à moins de cinq
  points de 50 % et bouge d'une année à l'autre — plutôt qu'en un chiffre à
  poursuivre.

**Reporté.**

- Les invariants de sens (les passages de branche ont bien eu lieu, Staff d'U
  est peuplé après confirmation, la section vidée est inactive, les fratries
  partagent un blind index) demandent une base de données et le vrai pipeline
  d'import : c'est IT-03.


---

## IT-03 — Test d'import de bout en bout et ses invariants

**Livré.**

- **`Tests\Fixtures\ReferenceDataset\DeskImportReplay`** — le rejeu des trois
  exports à travers le vrai pipeline : composition des services, création des
  années scoutes par `ScoutYearService::ensureYear()`, import dans l'ordre
  chronologique, puis confirmation des rôles par le chemin exact de Config Desk
  (`FunctionRepository::updateRole(..., true)` suivi de
  `UnitStaffSectionService::syncMembership()`). **Partagé avec le builder
  d'IT-05** : un test qui aurait sa propre copie du câblage continuerait de
  passer le jour où celle du builder casse.
- **`Tests\Integration\ReferenceDatasetImportTest`** — 22 tests, 182
  assertions, sur base SQLite en mémoire (`@group database`). Les invariants
  demandés par le chantier : effectifs par année et par section, chaque passage
  de frontière effectivement franchi, le membre parti qui garde sa ligne
  `members` sans `member_years`, le membre revenu qui hérite de son
  `scout_year_offset` par-dessus l'année manquante, la section vidée passée
  `is_active = false` sans être supprimée, Staff d'U peuplé après confirmation
  des rôles, et la fratrie qui partage un blind index d'adresse.
- **Trois invariants ajoutés au-delà de la liste du chantier** : l'ordre
  canonique des sept branches (une résolution par sous-chaîne qui cesserait de
  matcher enverrait silencieusement une branche en 99), l'absence de toute
  section identifiée par la colonne `SECTION`, et le fait que la fonction
  inédite de A3 arrive bien en `role = 'identified'`.

**Deux erreurs d'assertion attrapées par le test lui-même.**

1. **L'héritage du `scout_year_offset` ne s'observe pas sur un rejeu.**
   `MemberYearRepository::inheritedScoutYearOffset()` ne s'applique qu'à
   l'INSERT — délibérément, pour qu'une correction de chef survive à un
   ré-import de la même année. Poser le décalage puis rejouer les trois années
   ne prend donc que la branche UPDATE, qui ne touche jamais la colonne. Le test
   rejoue désormais année par année (`DeskImportReplay::importYear()`) et pose
   le décalage **entre** A1 et A3, ce qui est aussi la chronologie réelle : un
   chef ouvre la fiche en A1, l'import suivant hérite.
2. **Un membre à deux adresses et une fonction produit deux `member_functions`,
   pas une.** J'avais asserté une seule ligne. Le comportement est celui que le
   chantier décrit et que `replaceFunctions()` applique : les adresses sont
   dédupliquées par type, les fonctions ne le sont pas. L'assertion a été
   retournée en ce qu'elle doit dire — deux lignes portant le même `Animé`, et
   un comptage par section qui reste en `DISTINCT member_year_id`, sinon ce
   membre serait compté deux fois.

**Décisions prises en autonomie.**

1. **Le rejeu est une classe du jeu de données, pas du test.** Le chantier
   demandait un test qui rejoue les CSV ; le mettre dans une classe partagée
   avec le builder est ce qui rend le test protecteur du builder plutôt que
   simplement voisin. Elle n'appelle que des API publiques existantes et
   n'écrit dans aucune table directement.
2. **La confirmation des rôles resynchronise les trois années.**
   `FunctionsController` ne resynchronise que l'année en vigueur pour la
   requête ; un jeu de données à trois années doit les faire toutes, sinon
   Staff d'U est peuplé dans l'une et vide dans les autres.
3. **Une fonction absente de `UnitBlueprint::FUNCTIONS` est laissée non
   confirmée** plutôt que de recevoir un rôle par défaut. C'est exactement
   l'état d'une fonction inédite pour un chef qui n'est pas encore passé par
   Config Desk, et le jeu de données doit en contenir une.
4. **Le test capture l'état de Staff d'U avant la confirmation** au lieu de le
   décrire en commentaire. C'est la moitié qui compte : si Staff d'U était déjà
   peuplé avant confirmation, cela voudrait dire que le rôle vient du CSV.
5. **L'équilibre filles/garçons est asserté comme une fourchette**
   (30 % < part de F < 45 %) et non comme une valeur : la valeur exacte est une
   conséquence du tirage, la non-trivialité est l'invariant.

**Reporté.**

- Les relevés bancaires et leur test de format : IT-04.
