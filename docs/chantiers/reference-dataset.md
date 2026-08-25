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
| — | ~~**`DeskImportService::import()` supprime le CSV** qu'on lui donne (`@unlink`). Non mentionné, et destructeur pour des fixtures commitées.~~ **Corrigé** : le service conserve une copie chiffrée et ne supprime plus l'original (`SECURITY.md` §13). Le chantier « historique et rapport d'import » a levé cette gêne au passage. |
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


---

## IT-04 — Générateur de relevés BNP

**Livré.**

- **`BankBlueprint`** — la table : deux comptes (`Compte d'unité`,
  `Compte camps`) avec leurs IBAN invalides par construction et leurs soldes
  d'ouverture, les bases à dix chiffres des communications structurées, les
  mouvements récurrents et la règle de catégorisation que chacun est censé
  déclencher, et le nombre de lignes de recouvrement entre deux fichiers.
- **`BankStatementBuilder`** — une méthode par cas exigé par le chantier, pour
  qu'aucun ne soit espéré : cotisations à communication structurée, ligne
  refusée, séparateur de milliers, décimale pointée, ligne sans communication,
  virement entre les deux comptes de l'unité, et le recouvrement entre
  exercices successifs.
- **`BnpCsvWriter`** — le format exact de l'export BNP : BOM UTF-8, treize
  colonnes, `Nº de séquence` identique sur toutes les lignes, IBAN du compte
  répété sur chaque ligne, et la colonne `Détails` en prose continue avec son
  espace final.
- **Six fichiers commités**, deux comptes × trois exercices, 137 lignes
  exploitables au total.
- **Onze tests de format ajoutés** à `ReferenceDatasetFormatTest` : chaque
  relevé passe `BnpParser::parse()` et `extractSourceIban()`, chaque date tombe
  dans l'un des trois exercices, aucune référence n'est répétée à l'intérieur
  d'un fichier, trois le sont entre deux fichiers successifs, les deux formats
  de montant délicats sont lus correctement, la ligne refusée n'atteint jamais
  l'import, le virement interne a bien ses deux côtés, et chaque communication
  structurée repasse le calcul mod-97.

**Décisions prises en autonomie.**

1. **Le montant est une chaîne, pas un flottant, dans `StatementDraft`.** Deux
   des cas exigés portent sur le *formatage* du nombre — `1.234,56` et
   `35.98` — et un flottant ne peut pas porter cette distinction.
2. **Les communications structurées passent par
   `StructuredCommunicationService::format()`**, la méthode statique publique
   du vrai générateur, plutôt que par des chiffres de contrôle écrits à la
   main : le mod-97 est juste par construction.
3. **Le recouvrement porte les lignes de l'exercice précédent avec leur date
   d'origine.** Elles atterrissent donc dans l'exercice antérieur, où
   `findForDate()` les place quel que soit le fichier d'où elles viennent —
   c'est ce qu'un vrai téléchargement « quinze derniers mois » produit.
4. **Un troisième générateur aléatoire**, décalé encore d'un cran. Les exports
   Desk, le manifeste photo et les relevés sont régénérés ensemble et doivent
   rester indépendants les uns des autres : ajouter une photo ne peut pas
   déplacer une référence bancaire.
5. **Aucun solde courant n'est asserté.** Le solde d'ouverture que le builder
   passera à `ImportService` est celui de `BankBlueprint` ; les points de
   contrôle en découlent, et les figer dans un test en ferait un test du calcul
   de solde plutôt que du jeu de données.


---

## IT-05bis — `PhotoIngestionService`, extrait d'`UploadController`

L'itération de code de production décidée avant IT-01 (décisions 3 et 4).
Elle ne fait partie du découpage d'origine du chantier que parce que la
consigne « téléverser les photos par le vrai chemin » était inapplicable en
ligne de commande.

**Livré.**

- **`Core\Photo\PhotoIngestionService`** — tout ce qui arrive aux octets :
  liste blanche MIME, plafond de taille, court-circuit `unit_logo`, recadrage
  selon le contexte, `UploadHandler`, génération du dérivé, et le rattachement
  de la cible (contenu éditable, photo de membre, photo de compte, photo de
  section, logo de branche).
- **`Core\Photo\PhotoIngestionResult`** — l'identifiant de fichier (nul pour
  `unit_logo`, qui ne devient jamais une ligne `files`), le fait qu'une cible
  ait été rattachée, et le contexte de journal déjà extrait de la clé.
- **`UploadController` passe de 466 à 242 lignes** et de onze collaborateurs à
  deux. Il garde ce qui appartient vraiment à une requête : jeton CSRF,
  frontière d'autorisation, entrée de journal, message flash, redirection.
- **`public/index.php` recâblé**, service construit avant le contrôleur.
- **`Tests\Core\Photo\PhotoIngestionServiceTest`** — 7 tests sur le chemin
  sans requête : rattachement d'une photo de membre **sans compte auteur**,
  recadrage 4:3 avant stockage, plancher de rôle par contexte, un dérivé et un
  seul par contexte, clé malformée qui stocke sans rattacher, photo de compte
  refusée à un auteur qui n'est pas le compte, logo d'unité qui ne devient
  jamais une ligne `files`.
- **Les 22 tests existants d'`UploadControllerTest` passent inchangés**, en
  construisant le vrai service derrière le contrôleur plutôt qu'un double :
  ce qu'ils vérifient reste le pipeline de production.

**Ce qui a été préservé au caractère près.** Les deux recadrages écrivent dans
un **nouveau** fichier temporaire et ne réécrivent pas celui du téléversement
(PHP possède `$_FILES['tmp_name']` et le supprime en fin de requête), et un
fichier illisible ou d'un MIME hors liste est **retourné tel quel** au lieu de
lever : `UploadHandler::handle()` reste le seul endroit qui refuse un fichier,
avec un seul message. Ma première rédaction s'écartait des deux, ce qui aurait
donné à un même téléversement deux erreurs différentes selon son contexte.

**La seule différence de comportement, assumée et documentée.** Le contrôleur
n'attachait une photo de membre ou de section que si
`AuthSession::getUserAccountId()` était non nul. Sur le web il l'est toujours
(la route est `role_min: identified`), donc rien ne change pour un visiteur ;
mais c'est ce qui rendait le pipeline inutilisable depuis une ligne de
commande, alors que `MemberPhotoService::setPhoto()` et
`SectionPhotoService::setPhoto()` acceptent tous deux un auteur nul depuis
toujours. `account_photo` garde en revanche son refus explicite d'un auteur nul
ou différent du compte : c'est de l'autorisation, pas de la plomberie.

**Vérification du démarrage.** `npm run e2e` ne peut pas tourner dans ce
conteneur : les binaires installés sont `chromium_headless_shell-1194` et
`@playwright/test` 1.56 en réclame `-1234`. Les quarante tests échouent
identiquement au lancement du navigateur, sans qu'aucun corps de test ne
s'exécute — c'est l'environnement, pas le diff. Comme le risque propre à ce
refactor est précisément celui que la barrière e2e couvre (une racine de
composition qui ne se câble plus), il a été vérifié directement : une instance
jetable a été provisionnée et servie par le vrai `public/index.php`, et `/`,
`/login`, `/api/version` répondent 200 tandis que `/upload` répond 302 vers la
connexion — le comportement attendu pour un visiteur anonyme sur une route
`role_min: identified`. Aucun `TypeError`, aucun fatal dans le journal.


---

## IT-05 — Le builder CLI : imports, rôles, comptes, finances

**Livré.**

- **`build.php`** — garde `PHP_SAPI !== 'cli'`, `--yes` obligatoire, `--root`
  optionnel, refus explicite si l'installation a déjà servi, rapport final en
  français.
- **`InstanceContext`** — ouvre l'installation cible comme `public/index.php` :
  `SecretManager`, credentials, `EncryptionService` construit sur **les clés de
  la cible**. C'est là que se joue tout l'intérêt de l'approche « recette » :
  les clés ne voyagent jamais.
- **`FinanceSeeder`** — catégories et comptes par défaut, les deux comptes
  d'unité, puis les six relevés par le vrai `ImportService`.
- **`DemoAccounts`** — la table de qui porte quel rôle de démonstration, et un
  mot de passe posé sur le compte que l'import Desk a déjà créé pour ce
  membre. Le rôle obtenu est dérivé par le site de ses fonctions confirmées,
  pas écrit à la main.
- **`Tests\Integration\ReferenceDatasetBuilderTest`** — 5 tests sur ce que le
  builder orchestre : import des relevés par le vrai pipeline, déduplication
  entre fichiers successifs, IBAN retrouvable par index aveugle, catégories
  semées avant tout compte, superadministrateur créé avant les imports,
  comptes de démonstration adossés à leurs membres.

**Le builder a été exécuté pour de vrai**, sur une instance provisionnée par le
harnais e2e puis ramenée à l'état d'une installation neuve. Résultat : 178 /
180 / 180 membres actifs, 5 puis 1 puis 1 fonctions inédites, 7 fonctions
confirmées, 13 rattachements Staff d'U, 125 mouvements importés, 12 doublons
reconnus, 13 catégories, 105 mouvements catégorisés sur 125, `Iama Horizon`
inactive et `Ribambelle Verte` créée.

**Trois défauts que seule l'exécution réelle pouvait révéler.**

1. **IBAN écrit par le dépôt au lieu du service.** `AccountRepository::create()`
   calcule l'index aveugle sur la chaîne qu'on lui donne ; j'y passais
   `BE.. 0000 0000 0001` avec ses espaces, alors que
   `BnpParser::extractSourceIban()` renvoie la forme compacte. Résultat : deux
   index différents pour le même compte, et un import qui échouait sur
   « l'IBAN du fichier ne correspond pas » en nommant deux IBAN finissant par
   les mêmes chiffres. Le builder passe désormais par
   `FinanceService::createAccount()`, qui normalise — et qui synchronise aussi
   la catégorie système « Virement <compte> ».
2. **Les IBAN du jeu de données ont dû devenir valides au mod-97.** Corollaire
   du point 1 : `createAccount()` valide l'IBAN. Le chantier demandait des IBAN
   « invalides par construction » ; les rendre invalides aurait obligé à
   contourner la couche service, ce que ce chantier existe pour ne pas faire.
   Les deux comptes de l'unité portent donc `BE27 0000 0000 0001` et
   `BE97 0000 0000 0002` : somme de contrôle juste, code banque `000` non
   attribué en Belgique. Les IBAN de contrepartie, eux, ne sont validés par
   rien et gardent le `BE00` impossible.
3. **Un `1` en dur comme identifiant d'importateur.**
   `import_journal.user_account_id` porte une clé étrangère vers
   `user_accounts`. Sur une installation neuve le premier compte est
   généralement l'id 1 et cela passait ; sur l'instance de test, après remise à
   zéro, le superadministrateur a reçu l'**id 608** et l'import a échoué sur la
   contrainte. Le builder crée désormais le superadministrateur **avant** les
   imports et les lui crédite — ce qui est aussi la vérité : c'est lui qui
   lance un import Desk.

**Un quatrième, d'ordonnancement.** `ensureDefaultCategories()` ne sème que
tant que la table des catégories est complètement vide — délibérément, pour ne
pas ressusciter des catégories qu'un administrateur a supprimées. Créer les
comptes d'abord y ajoute leur catégorie « Virement <compte> » et neutralise le
semis : la première exécution a produit 2 catégories au lieu de 13 et 6
mouvements catégorisés sur 125. L'ordre est maintenant explicite dans le code
et dans le README, et un test le tient.

**Décisions prises en autonomie.**

1. **Destructeur, pas idempotent.** Le chantier laissait le choix en imposant
   d'en faire un. Un builder qui recolle à moitié sur un existant est le piège
   que la consigne décrit ; exiger une installation vierge est simple à dire,
   simple à vérifier, et c'est ce que sert une instance de test.
2. **Le refus est absolu, sans option de forçage.** Trois signaux : des
   membres, des mouvements financiers, plus d'un compte utilisateur. Ajouter un
   `--force` aurait transformé un garde-fou en formalité. *(Repris après coup,
   voir « Suite — `--reset` » plus bas : la suite ne donne pas de `--force`,
   elle donne un vidage.)*
3. **Une table manquante compte pour zéro** dans ce garde plutôt que de lever :
   un module peut être désactivé sur la cible, et le builder n'a alors rien à
   vérifier de ce côté.
4. **Les collaborateurs IA du module Finances reçoivent un connecteur nul**, ce
   que fait la racine de composition elle-même quand `llm_connector` est
   désactivé : la catégorisation retombe sur les règles.


---

## IT-06 — Les extras déclaratifs

**Livré.**

- **`ExtrasBlueprint`** — la table : décalages d'année scoute, départs marqués
  avec commentaire, attributions de badges, évènements de calendrier, libellés
  et montant des créances attendues.
- **`ExtrasApplier`** — l'application, **toujours par les vrais services** :
  `MemberYearRepository::updateScoutYearOffset()`, `DepartureService`,
  `BadgeService` (+ semis des badges par défaut et des badges référents),
  `PhotoIngestionService` pour les 57 photos, `CalendarEventService`,
  `ExpectedReceivableService`.
- **`build.php`** enrichi d'une étape 5 et de son rapport.
- **Quatre tests ajoutés** à `ReferenceDatasetBuilderTest` : les extras
  s'appliquent, chaque photo passe réellement par le pipeline de téléversement,
  une créance existe par communication structurée, et les extras d'un module
  désactivé sont ignorés.

**Exécution réelle**, sur une seconde instance jetable : 2 décalages,
3 départs, 5 badges, 43 photos individuelles, 14 photos de groupe,
9 évènements, 17 créances. Vérifié sur disque : 43 `thumb.webp` et 14
`md.webp` réellement générés, et une photo de groupe stockée en 1448×1086,
soit un 4:3 exact — les trois choses (recadrage, retrait EXIF, dérivé) qu'une
écriture directe dans `member_photos` aurait sautées, et la raison d'être
d'IT-05bis.

**Le sous-ensemble est assumé et nommé.** Le chantier demandait une couverture
large plutôt qu'exhaustive et autorisait explicitement à proposer un
sous-ensemble. Sont couverts les sept extras dont le reste du jeu de données a
besoin pour avoir du sens. Ne le sont pas : documents de section, articles avec
formulaire, groupes de discussion, demandes d'inscription, locations — chacun
demandant de recomposer la chaîne complète de son module (notifications,
planificateur, stockage chiffré, connecteur IA optionnel), soit beaucoup de
surface de câblage à maintenir pour une fixture. La liste et la raison sont
dans ARCHITECTURE.md §8.3.

**Décisions prises en autonomie.**

1. **Un module désactivé sur la cible est ignoré, pas fatal.** `ExtrasApplier`
   vérifie l'existence des tables avant d'écrire. Un module désactivé n'a pas
   de tables — c'est une configuration, pas une panne — et planter à mi-course
   laisserait le jeu de données à moitié appliqué. C'est aussi ce qui permet
   aux tests de couvrir le chemin sans monter le schéma du calendrier.
2. **Le montant dû d'une créance n'est pas le montant payé.** Les paiements du
   relevé sont tirés entre 35 € et 95 €, la cotisation vaut 65 € : certains
   foyers sont à jour, d'autres en dessous, d'autres au-dessus. Une page
   « Paiements attendus » où tout est soldé ne montre rien.
3. **Un départ marqué qui ne se réalise pas.** Deux des trois marquages
   correspondent à des membres réellement absents l'année suivante ; le
   troisième porte sur quelqu'un qui reste. Un marquage est une prévision, pas
   un fait, et une grille « Départs » réaliste contient les deux.
4. **PHPStan a attrapé quatre constructeurs** — `DepartureRepository`,
   `CalendarRepository`, `CalendarUnitFeedTokenRepository`,
   `ExpectedReceivableRepository` — qui prennent tous un `EncryptionService`
   en second argument. C'est exactement la classe de défaut pour laquelle ce
   répertoire est dans les `paths` de `phpstan.neon`, et elle s'est
   matérialisée dès la première itération qui compose beaucoup de services.


---

## IT-07 — Rappels croisés, documentation, vérification transverse

**Livré.**

- **`AGENTS.md` § Reference dataset** — la liste des changements qui obligent à
  vérifier ce jeu de données dans le même changement (format Desk,
  `DeskCsvParser`, `BnpParser`, pipeline d'import, schéma d'une table liée aux
  membres), les trois tests qui tiennent la ligne, la règle de régénération, et
  l'interdiction de retirer ce répertoire des `paths` de `phpstan.neon`.
- **Un en-tête de classe sur `DeskCsvParser`** qui renvoie au README et redit
  que `Sizaine/Patrouillle` et l'ignorance de `SECTION` ne sont pas des
  coquilles.
- **Un rappel équivalent sur `BnpParser`**, avec la liste des cas que les six
  relevés contiennent délibérément.
- **`ARCHITECTURE.md` §12** — le répertoire placé dans la carte du projet.
- **README §12** — le tableau des rappels croisés et des trois garde-fous
  mécaniques.

**Vérification transverse.** Une troisième instance jetable a été provisionnée
avec les 17 modules activés, ramenée à l'état d'une installation neuve, puis
construite de bout en bout. Le site a ensuite été servi par le vrai
`public/index.php` : `/`, `/login`, `/sections` et `/contact` répondent 200,
zéro erreur PHP dans le journal.

**Un défaut trouvé par cette vérification, et c'en était un vrai.** Le garde
« module désactivé » d'IT-06 testait l'existence d'une table `calendars` ; elle
s'appelle `calendar_calendars`. Sur une instance où le calendrier était actif
depuis le début, les neuf évènements étaient donc **silencieusement** sautés, et
le compteur affichait zéro — indiscernable d'un module réellement désactivé.
Corrigé deux fois : le nom de table, et surtout le fait que le saut est
désormais **signalé** (`(ignoré : module « calendar » désactivé)`) plutôt que
réduit à un zéro muet. Le chantier le disait déjà en toutes lettres — « aucune
troncature silencieuse » — et c'est exactement la forme qu'elle avait prise.

---

## Suite — `--reset` : vider l'installation cible avant de construire

**Demandé après la livraison du chantier** : « je veux que le builder donne la
possibilité de supprimer / réinitialiser la DB si elle est déjà peuplée. »

Le refus de l'IT-05 disait quoi ne pas faire sans dire comment s'en sortir. Les
deux issues existantes — réinstaller, ou restaurer une sauvegarde prise juste
au bon moment — supposent toutes deux quelque chose qui a été prévu à l'avance.
`--reset` est la troisième, et elle ne contourne pas le refus : elle y répond,
en enlevant les données au lieu de fusionner dedans.

**Livré.**

- **`InstanceReset`** — sauvegarde de sécurité par
  `BackupService::createDatabaseDump()`, `TRUNCATE` de toutes les tables sauf
  deux, suppression des fichiers téléversés sous `storage/` sauf `keys/`,
  `config/` et `maintenance/`.
- **`InstanceContext::connection()`** — le `Connection` et non plus seulement
  le `PDO` derrière lui : `BackupService` prend des identifiants, pas une
  poignée, parce que `DatabaseDumper` ouvre sa propre connexion depuis un DSN.
- **`build.php`** — `--reset`, `--no-backup`, le message de refus qui nomme
  désormais la sortie, et un avertissement supplémentaire quand `--reset` est
  passé sans `--yes`.
- **Quatre tests** dans `ReferenceDatasetBuilderTest` : le vidage laisse la
  configuration debout, il épargne exactement les tables que `BackupService`
  appelle de la configuration, il supprime les fichiers sans jamais toucher aux
  clés, et un dump de sécurité en échec interrompt tout sans rien vider.
- **README §8.4**, plus §3, §8 et §11 mis à jour.

**Décisions prises en autonomie.**

1. **Ce n'est pas la « Réinitialisation complète » de l'application.**
   `FullResetHandler` supprime `secrets.enc` et vide `storage/` jusqu'à
   `keys/master.key` : le site repart sur l'assistant d'installation, état dans
   lequel le builder ne peut plus rien construire puisqu'il ouvre l'instance
   par `SecretManager`. D'où un vidage plus étroit, et volontairement nommé
   autrement : les données partent, l'installation reste.
2. **`TRUNCATE`, pas `DROP TABLE`.** L'assistant d'installation détruit les
   tables parce que la migration les recrée juste après. Ici, personne ne
   re-migre entre le vidage et la construction : le schéma doit rester debout.
   Effet de bord voulu — les compteurs `AUTO_INCREMENT` repartent à 1, donc
   deux constructions `--reset` successives produisent les mêmes identifiants.
3. **`settings` et `module_registry` sont épargnées**, et la liste n'est pas
   une invention : c'est `BackupService::CONFIG_ONLY_TABLES`, la réponse relue
   du projet à « quelles tables sont de la configuration et non des données ».
   Les vider emporterait quels modules sont activés, alors que le jeu de
   données a besoin de finance et de calendrier. Un test épingle les deux
   listes l'une à l'autre.
4. **La sauvegarde est le défaut, la sauter demande un second drapeau.**
   `--no-backup` existe parce qu'un `storage/` non inscriptible ne doit pas
   rendre le vidage impossible — mais c'est exactement le raisonnement de
   `force_without_backup` dans l'assistant d'installation, et il vaut ici pour
   la même raison. Sans ce drapeau, un dump en échec abandonne le vidage.
   `--no-backup` sans `--reset` est refusé plutôt qu'ignoré.
5. **`--reset` vide même quand rien ne motivait un refus.** Les motifs ne
   comptent que les membres, les mouvements et les comptes ; une instance peut
   porter des sections, des années scoutes ou un calendrier sans un seul
   membre, et construire par-dessus ces restes serait le demi-mélange que le
   builder refuse par principe.
6. **Écrire du SQL à la main, ici et nulle part ailleurs.** La règle « toujours
   par les vrais services » n'a pas de service à nommer pour un vidage :
   l'application procède elle-même aux deux mêmes instructions près, dans
   `FullResetHandler::truncateAllTables()` et dans
   `SetupController::backupAndEmptyDatabase()`. `InstanceReset` le dit dans son
   en-tête plutôt que de le laisser découvrir.

**Exécution réelle**, sur une instance jetable provisionnée par
`scripts/e2e-support.php` — qui crée elle-même 2 membres et 2 comptes, donc
tombait jusqu'ici sous le refus :

| Contrôle | Valeur |
|---|---|
| Tables vidées | 152 |
| Fichiers supprimés au 2ᵉ passage | 114 — les 117 de `storage/` moins `master.key`, `secrets.enc` et le dump du 1ᵉʳ passage |
| `settings` / `module_registry` après vidage | 90 lignes / 19 modules, tous encore activés |
| Effectifs reconstruits | 178 / 180 / 180, 125 mouvements, 12 doublons, 43 + 14 photos, 9 évènements, 17 créances — identiques au premier passage |
| Boot après reconstruction | 200 sur `/`, `/login`, `/sections`, `/contact` ; 302 sur `/upload`, aucune fatale |

Aucun défaut trouvé à l'exécution cette fois — le vidage a marché du premier
coup sur MySQL, y compris le second passage par-dessus une instance que le
builder venait lui-même de remplir, qui est le cas d'usage réel.

---

## Récapitulatif final

| # | Livré |
|---|---|
| IT-01 | Lot de photos assaini et ré-encodé (117,8 → 11,6 Mo), inventaire, README, vérification post-zip dans `release.sh`, PHPStan sur le répertoire |
| IT-02 | Générateur déterministe, trois tables déclaratives, 33 membres de scénario, les trois exports Desk, mode `--check`, test de format |
| IT-03 | `DeskImportReplay` partagé avec le builder, et 22 invariants sémantiques sur base |
| IT-04 | Générateur de relevés BNP, six fichiers, onze tests de format |
| IT-05bis | `Core\Photo\PhotoIngestionService` extrait d'`UploadController` (code de production) |
| IT-05 | Builder CLI : garde de production, imports, confirmation des rôles, finances, comptes de démonstration |
| IT-06 | Extras déclaratifs : décalages, départs, badges, 57 photos, évènements, créances |
| IT-07 | Rappels croisés, documentation, vérification transverse |
| Suite | `--reset` : `InstanceReset`, sauvegarde de sécurité, vidage des données, quatre tests, README §8.4 |

**Ce que le chantier a produit dans le code de production**, en plus du jeu de
données : l'extraction de `PhotoIngestionService`, et deux vérifications
ajoutées à `scripts/release.sh` et `phpstan.neon`.

**Les défauts trouvés en exécutant plutôt qu'en lisant** — cinq, tous dans mon
propre code, tous invisibles à la relecture : l'IBAN écrit par le dépôt au lieu
du service, le `1` en dur comme importateur, l'ordre catégories/comptes, le nom
de table du calendrier, et le zéro silencieux qui le masquait. Chacun est
consigné dans l'itération qui l'a trouvé.
