# Chantier — Module Documents

Journal d'implémentation du document de chantier « Module Documents —
partage de documents d'unité » (issue #508, itérations IT-01 à IT-03). Une
section par itération : ce qui a été fait, les décisions prises en
autonomie, les divergences constatées entre le document de chantier et le
dépôt réel, et ce qui a été reporté. Même format que
`docs/chantiers/aide-contextuelle.md`.

La maquette de référence est `docs/chantiers/maquettes/maquette-documents.jsx`.

---

## Récapitulatif final

À compléter à la clôture du chantier (IT-03).

---

## Vérification préalable — la roadmap face au code

La roadmap a été écrite sur le commit `fb9e661` ; elle a été relue contre
`main` à `ace885b` avant la première ligne de code. Les écarts :

- **SECURITY.md §6 et ses exceptions.** La roadmap parle d'« une seule
  exception » au principe « tout fichier passe par `/files/{id}` ». Le
  §6 en documente aujourd'hui deux (l'extrait de triage est la « deuxième
  exception délibérée ») et mentionne une troisième, retirée (les photos
  hors ligne). Sans conséquence pour le module : il n'ajoute aucune
  exception, il redirige vers `/files/{id}`.
- **La citation d'`ArticleService`.** La roadmap cite en français une règle
  qui, dans le code, est en anglais et paraphrasée :
  `ArticleService::enforceSeoRules()` et le commentaire de
  `modules/news/schema.sql` (« an indexed page is offered to every search,
  forever… »). Le fond est le même.
- **`SectionDocumentService` n'utilise pas `UploadHandler`.** Il passe par
  `EncryptedFileStorageService`, et la compression PDF y tourne dans une
  tâche de fond (`compress_section_document`) qui essaie `gs`, `qpdf`
  puis `pdftocairo`. Le module Documents stocke en clair (un document
  public n'a rien à chiffrer) via `UploadHandler`, et compresse de manière
  synchrone avec le même `PdfCompressor`.
- **Les quatre tables de pièces jointes n'ont pas la même forme.**
  `member_documents` n'a pas de `sort_order` ; `rental_documents` n'a ni
  titre, ni `sort_order`, ni clé étrangère vers `files`. La roadmap les
  présente comme uniformes ; c'est une raison de plus de ne pas en
  factoriser une cinquième.
- **44 px.** La roadmap en fait un minimum par bouton. Dans le dépôt, c'est
  un objectif de confort traité centralement par la règle CSS
  `pointer: coarse` (AGENTS.md, `design.md` §7.2), pas une contrainte que
  chaque vue doit imposer. Les vues du module n'ajoutent rien.
- **Montée de version d'un module.** AGENTS.md dit qu'un changement de
  schéma ne demande plus de monter la version ; `docs/module-development.md`
  (deux passages) dit encore le contraire — contradiction connue (#410).
  La version est montée quand même, puisque la roadmap le demande.
- **`design.md`** est à la racine du dépôt, pas sous `docs/`.

---

## IT-01 — Le module, les deux pages, les cinq visibilités

**Livré.**
- `modules/documents/` : manifeste (désactivé par défaut, catégorie
  communication), `schema.sql` (table `documents`, adresse figée en
  `slug` unique, visibilité en `ENUM`), deux sujets d'aide.
- `Service\DocumentVisibility` : les cinq visibilités, leurs libellés
  (ceux de l'éditeur d'actualités, mot pour mot), le `role_min` du fichier
  qui en découle (Lien direct → `public`), qui voit quoi, ce qui est
  indexable (Public seulement).
- `DocumentService` : ajout, modification (titre, description, visibilité
  et, facultativement, un nouveau fichier dans le même geste),
  suppression, ordre. Chaque changement de visibilité suit sur le
  `files.role_min` ; chaque geste est journalisé (identifiants seulement).
- « Notre unité › Documents » (`/documents`, public, filtré par lecteur)
  et « Espace chefs d'U › Documents » (`/admin/documents`, admin), plus
  l'adresse stable `/documents/{slug}` qui redirige (302, `no-store`) vers
  `/files/{id}`.
- Tests : la matrice complète cinq visibilités × cinq rôles, chaque route
  au rôle plancher et un rang en dessous, les pages, l'adresse stable,
  `noindex` pour tout ce qui n'est pas public, le service (adresse figée,
  segment aléatoire de 12 caractères hexadécimaux en Lien direct, suffixe
  numérique, remplacement et suppression du fichier), le script du
  formulaire.

**Décisions autonomes.**
- **Compression PDF synchrone.** Le document est petit et partagé une
  fois ; une tâche de fond ajouterait un état « en cours » à afficher pour
  rien.
- **Une page de modification séparée** plutôt que l'édition en ligne de la
  maquette : `partials/list_editor` ne gère que des champs courts, et
  l'envoi d'un fichier demande un vrai formulaire multipart. Pas de fork
  du partial (D10).
- **Le nombre de documents masqués ne compte pas les Liens directs.** La
  maquette les comptait ; ce serait apprendre à chaque membre qu'un
  document non listé existe.
- **Un visiteur anonyme** voit une invitation à se connecter plutôt qu'un
  nombre.
- **La gestion est réservée au rôle admin (Staff d'U)** sur toutes les
  routes. Le module Bannière restreint en plus au chef d'unité ; la
  roadmap ne le demande pas ici.
- **Les deux sujets d'aide sont livrés dès l'IT-01**, parce que
  `HelpMenuCoverageTest` exige un sujet pour toute route dotée d'un fil
  d'Ariane ; l'IT-03 les affinera.
- **`storage` laissé vide dans le manifeste** : les fichiers passent par
  `files`, que le cœur sait déjà sauvegarder et purger.
- **Le téléchargement pointe directement sur `/files/{id}`** via
  `partials/file_link` (qui gère le cas PWA) ; l'adresse stable sert au
  partage, pas à la liste.
- **Un document listé passé en Lien direct garde son adresse lisible.**
  L'adresse est figée par principe ; le formulaire prévient que le titre
  la rend devinable et propose de créer un nouveau document à la place.
- **Le bouton de suppression de `list_editor`** garde son libellé
  générique (D10 : pas de fork).
- **Namespace `Modules\Documents\` déclaré dans `composer.json`**, comme
  chaque module.
- **Une première section de `specifications.md` (§46) et les deux lignes
  de §4 dès l'IT-01**, plutôt qu'à l'IT-03 comme le prévoit la roadmap :
  `ModuleSpecificationCoverageTest` refuse un module sans section ni une
  entrée de menu sans sa ligne. L'IT-03 la complète.
- **Les maquettes des menus et de la page des modules ne sont pas
  redessinées** : ce sont des documents de chantier figés. « Documents »
  est nommé dans les listes des modules arrivés après elles
  (`MenuMockupTest::NOT_DRAWN`, `ModulesPageMockupTest::ARRIVED_AFTER_THE_MOCKUP`).
- **Les inventaires** qu'un nouveau module doit renseigner le sont :
  `DocumentException` parmi les exceptions montrées telles quelles (tous
  ses messages sont des phrases françaises sans détail technique),
  `/documents/{slug}` parmi les routes qui ne rendent pas de page (une
  redirection), et une valeur pour `{id}` et `{slug}` dans
  `tests/dast/authz-fixtures.json` pour la matrice d'autorisation.

**Divergences avec le document de chantier.**
- La roadmap demande un `<meta name="robots" content="noindex">` sur la
  page d'un document non public. Il n'existe pas de page HTML par
  document : l'adresse stable est une redirection. Le `noindex` est donc
  porté par l'en-tête `X-Robots-Tag` de cette redirection. Limite connue :
  la cible `/files/{id}` ne porte aucun en-tête robots. Pour un document
  réservé, c'est sans effet (un robot n'a pas de session) ; pour un Lien
  direct, dont le fichier est `public`, un robot qui suivrait la
  redirection pourrait indexer le fichier. À traiter dans le cœur
  (`FileController`), hors de ce chantier.

**Reporté.** Les versions (IT-02) ; la documentation d'architecture et les
spécifications (IT-03).

**Vérification finale.** `vendor/bin/phpstan analyse` sans erreur ;
la suite PHPUnit complète (SQLite) passée une fois, ses quinze échecs
d'inventaire corrigés puis leurs suites relancées vertes ; le groupe `database` des
suites `tests/Core/Database` et `tests/Integration` vert sur MariaDB ;
`tests/Modules/Documents` : 46 tests ; `tests/js/documents-form.test.js`
vert ; `npm run typecheck` sans erreur.
