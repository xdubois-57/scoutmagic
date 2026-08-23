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
