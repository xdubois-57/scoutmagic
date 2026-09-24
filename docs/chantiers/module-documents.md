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

**Ce qui a été livré.** Les trois itérations, dans l'ordre, une PR
chacune, fusionnées sur `main` une fois la CI verte :

| # | Livré |
|---|---|
| IT-01 (#512) | Le module `documents` : la page publique filtrée par lecteur, l'écran de gestion, les cinq visibilités des actualités, l'adresse stable `/documents/{slug}` qui redirige vers `/files/{id}`, deux sujets d'aide, la matrice complète des tests |
| IT-02 (#524) | Les versions : l'ancien fichier devient une version précédente réservée au Staff d'U, cinq gardées, historique replié dans l'écran de gestion, journalisation |
| IT-03 | `ARCHITECTURE.md` §8.121 (dont la dette du cinquième mécanisme de documents), `specifications.md` §46 complété, les deux sujets d'aide affinés |

**Corrections de revue.** Sur la PR de l'IT-01, la revue automatique a
relevé deux vrais défauts, corrigés avant la fusion : l'écran de gestion
ne chargeait pas `sortable.js` ni `list-editor.js` (glisser-déposer,
flèches et corbeille inertes — le partial ne les charge pas lui-même),
et le refus d'`UploadHandler` était réemballé dans une
`DocumentException` avec son message, construction qu'AGENTS.md
interdit ; il remonte désormais tel quel jusqu'au contrôleur. SonarCloud a
ensuite relevé l'avertissement de remplacement, un `div` à
`role="status"` : il est devenu un `<output>`, comme les autres messages
d'état du site. CodeRabbit, enfin, a relevé le défaut le plus sérieux :
le fichier d'un lien direct, en `role_min: public`, se trouvait en
comptant les identifiants de `/files/{id}`. L'adresse du document en est
devenue la seule clé (voir IT-01, « L'adresse d'un Lien direct est la
seule clé de son fichier ») ; la même revue a fait retirer le fichier
orphelin d'une modification ratée, exiger un 403 exact dans le test RBAC
et préparer les requêtes des tests.

Sur la PR de l'IT-02, la revue a relevé deux défauts dans l'archivage
des versions, corrigés avant la fusion : l'ancien fichier était fermé
après l'écriture de sa ligne de version (un échec le laissait ouvert), et
le premier correctif supprimait ce fichier alors qu'une modification
concurrente venait de l'archiver. L'archivage calcule désormais son
numéro dans l'`INSERT` et n'archive jamais deux fois le même fichier.

**Restent ouverts, hors chantier.**
- `/files/{id}` ne porte aucun en-tête robots : le `noindex` d'un
  document non public est porté par la redirection. Un robot n'atteint
  plus le fichier d'un lien direct sans passer par son adresse ; un
  en-tête servi par `FileController` resterait une défense de plus, à
  placer dans le cœur : #516.
- `ARCHITECTURE.md` §8.28 décrit encore les documents de section comme
  téléversés par `UploadHandler` en `role_min: identified` ; le code
  passe par `EncryptedFileStorageService`. Constaté pendant la
  vérification préalable, non corrigé ici : ce n'est pas le module de
  ce chantier. Suivi : #532.
- Cinq versions ou une durée : voir IT-02.

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
  la rend devinable et propose de créer un nouveau document à la place. Ce
  que le formulaire affiche se décide sur `documents.slug_is_random`,
  posé à la création, jamais sur la visibilité courante (relevé par la
  revue : un document passé en Lien direct se voyait promettre un segment
  aléatoire qu'il n'a pas).
- **Le bouton de suppression de `list_editor`** garde son libellé
  générique (D10 : pas de fork).
- **L'adresse d'un Lien direct est la seule clé de son fichier.** Relevé
  par la revue de la PR : un fichier en `role_min: public` se télécharge
  par `/files/{id}`, et ces identifiants se suivent — n'importe qui les
  comptant trouverait les documents non listés, ce qui rendait le segment
  aléatoire de D6 inutile. Le fichier garde `role_min: public` (D4 : pas
  de compte requis), mais tout fichier de document porte désormais
  `owner_type = 'document'`, et `File\DocumentFileOwnershipChecker` —
  le point d'extension de `FileAccessGuard` prévu pour ça — ne le sert,
  pour un Lien direct, qu'à une session passée par `/documents/{slug}`
  (`File\DirectLinkGrants`, en session, cookie `SameSite=Lax`, qui suit
  la redirection depuis un lien d'e-mail). Le Staff d'U lit tout ; un
  document listé n'ajoute rien à son `role_min` ; module désactivé,
  aucun vérificateur ne répond et le garde refuse. Coût assumé : un
  client sans cookies (un gestionnaire de téléchargement, un robot)
  n'obtient pas le fichier par l'adresse. Corollaire, dans le cœur : `FileController`
  ne marquait `Cache-Control: public` que d'après le `role_min`, si bien
  qu'un proxy aurait pu garder le fichier d'un lien direct et le servir à
  n'importe qui sans repasser par le garde. Un fichier n'est désormais en
  cache partagé que si le `role_min` est `public` **et** qu'il n'a aucun
  propriétaire (`isSharedCacheable()`), pour le téléchargement, la
  vignette et les variantes. Et à la création, le fichier est enregistré
  fermé (`admin`, sans propriétaire) puis ouvert à son rôle seulement une
  fois possédé par le document : sinon, le temps de la compression PDF,
  le fichier d'un lien direct était un fichier public ordinaire. La
  même règle vaut à la modification : un fichier de remplacement est
  enregistré fermé et n'est ouvert qu'une fois la nouvelle visibilité
  écrite sur le document ; un changement de visibilité sans nouveau
  fichier ferme le fichier courant avant et le rouvre au bon rôle après
  (et lui rend l'ancien si l'enregistrement échoue). Dernière forme,
  après une quatrième remarque de la revue : l'ancien fichier est fermé
  dès que la visibilité change, nouveau fichier ou non, et la visibilité
  comme le fichier courant s'écrivent en une seule requête
  (`DocumentRepository::applyEdit()`) — un échec à mi-chemin ne peut plus
  laisser la ligne et le `role_min` du fichier en désaccord.
- **Un envoi de nouveau fichier qui échoue en cours de modification**
  retire le fichier qu'il venait d'enregistrer, comme l'ajout le faisait
  déjà (relevé par la même revue).
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
  porté par l'en-tête `X-Robots-Tag` de cette redirection. La cible
  `/files/{id}` ne porte aucun en-tête robots, mais un robot ne l'atteint
  pas : pour un document réservé, il n'a pas de session au bon rôle ; pour
  un Lien direct, il lui faudrait l'adresse et garder le cookie de
  session entre la redirection et le fichier (voir la décision ci-dessus).
  Un en-tête `noindex` servi par `FileController` lui-même resterait
  une défense de plus, à placer dans le cœur, hors de ce chantier.

**Reporté.** Les versions (IT-02) ; la documentation d'architecture et les
spécifications (IT-03).

**Vérification finale.** `vendor/bin/phpstan analyse` sans erreur ;
la suite PHPUnit complète (SQLite) passée une fois, ses quinze échecs
d'inventaire corrigés puis leurs suites relancées vertes ; le groupe `database` des
suites `tests/Core/Database` et `tests/Integration` vert sur MariaDB ;
`tests/Modules/Documents` : 56 tests ; `tests/js/documents-form.test.js`
vert ; `npm run typecheck` sans erreur.

---

## IT-02 — Les versions

**Livré.**
- Table `document_versions` : le document, le numéro, le fichier, sa
  taille, quand et par qui il était devenu courant, quand il a été
  remplacé. Version du module montée à 1.1.0.
- À chaque remplacement de fichier, l'ancien devient la version
  précédente la plus récente et son `files.role_min` passe à `admin`, quelle
  que soit la visibilité du document (D7) ; au-delà de cinq versions
  précédentes, la plus ancienne est supprimée, ligne **et** fichier.
  Supprimer un document supprime ses versions et leurs fichiers.
- Dans l'écran de gestion, sous chaque document remplacé au moins une
  fois, un historique replié : chaque version conservée, sa taille, sa
  date et un bouton pour la télécharger, suivi de la phrase qui explique
  pourquoi une ancienne version devient inaccessible. L'avertissement du
  formulaire annonce la même chose au moment de choisir un fichier.
- Journal : `document_file_replaced` (avec le numéro de la nouvelle
  version) et `document_version_deleted`, niveau `info`, identifiants
  seulement.
- Sujet d'aide « Gérer les documents » : une section sur les versions
  précédentes et une question de plus.
- Tests : le sixième remplacement supprime la première version et son
  fichier ; une ancienne version n'est plus lisible en dessous d'`admin`
  (`FileAccessGuard`, rôle par rôle), même sur un document public ou en
  lien direct ; l'adresse stable suit la version courante ; supprimer un
  document supprime ses versions et leurs fichiers ; modifier sans fichier
  ne crée pas de version ; l'historique s'affiche dans l'écran de gestion.

**Décisions autonomes.**
- **Cinq versions *précédentes*, la courante en plus.** C'est la lecture
  qui rend vrai le test demandé par la roadmap (« le sixième remplacement
  supprime la première version ») : après cinq remplacements, les
  versions 1 à 5 sont gardées et la 6 est courante.
- **La version courante n'est pas une ligne de `document_versions`** :
  elle reste `documents.file_id`, ce qui laisse intacts la redirection et
  tout le code de l'IT-01. Son numéro se calcule (le plus haut numéro
  gardé, plus un).
- **La date et l'auteur d'une version** sont recopiés de sa ligne `files`
  au moment où elle est remplacée : c'est le seul endroit qui savait
  quand ce fichier était devenu courant, `documents.updated_at` bougeant
  aussi pour une simple correction de titre.
- **Pas de `ON DELETE CASCADE` de `documents` vers `document_versions`** :
  la cascade effacerait les lignes en laissant leurs fichiers sur le
  disque. Le service supprime les versions, fichiers compris, avant le
  document.
- **L'ancien fichier est fermé avant que sa version soit écrite**, et
  l'archivage résiste à deux modifications simultanées (relevé en deux
  temps par la revue de la PR). Le numéro de version est calculé par
  l'`INSERT` lui-même, pas repris d'une ligne lue plus tôt ; un fichier ne
  peut être archivé qu'une fois (index unique sur `file_id`), si bien que
  la requête qui arrive seconde ne fait rien au lieu d'échouer. Si
  l'écriture échoue encore après une seconde tentative, le fichier reste
  fermé et sur le disque — jamais supprimé sous une ligne qui pourrait le
  désigner — et le journal le signale (`document_version_lost`, niveau
  `warning`) sans annoncer de numéro que personne n'a reçu.
- **Pas de restauration d'une ancienne version en un clic.** La roadmap
  demande de consulter et de télécharger ; restaurer, c'est téléverser à
  nouveau la version téléchargée.

**À trancher pendant le chantier — cinq versions ou une durée.** Un
plafond en nombre ne dit pas la même chose selon le document : pour un
règlement mis à jour une fois l'an, cinq versions remontent à cinq ans ;
pour une liste de matériel modifiée trois fois par camp, elles couvrent
un été. Le plafond en nombre est gardé, conformément à D7 : il est simple
à expliquer (« les cinq dernières ») et borne le stockage sans tâche
planifiée. Une durée paraîtrait plus juste pour les documents très
souvent remplacés ; si l'usage le confirme, la constante
`DocumentService::KEPT_VERSIONS` est le seul endroit à changer, et le
passage à une durée demanderait une tâche de purge. Rien n'est changé
en silence.

**Divergences avec le document de chantier.** Aucune.

**Reporté.** Rien.

**Vérification finale.** `vendor/bin/phpstan analyse` sans erreur ; suite
PHPUnit complète verte (SQLite, 20 620 tests) ; groupe `database` de
`tests/Core/Database` et `tests/Integration` vert sur MariaDB ;
`tests/Modules/Documents` : 63 tests.

---

## IT-03 — Documentation et aide

**Livré.**
- `ARCHITECTURE.md` §8.121 : les deux pages, la visibilité qui n'est pas
  un rôle, l'adresse stable et sa redirection (302 et pourquoi pas 301,
  `noindex` et sa limite), la règle du `role_min` des anciennes versions,
  et **la dette assumée du cinquième mécanisme de documents**, avec les
  quatre autres nommés (`section_documents`, `camp_documents`,
  `rental_documents`, `member_documents`), ce qui les sépare et ce qui
  déclenchera l'extraction.
- `specifications.md` §46 complété : les versions (46.4) et l'écran de
  gestion (46.5), et la ligne de §4.4 qui mentionne l'historique.
- Sujet d'aide public : ce que voit un visiteur anonyme, et le message
  exact qu'affiche un lien réservé ou l'ancien lien d'une version
  remplacée. Le sujet de gestion avait reçu sa section sur les versions
  avec l'IT-02.

**Décisions autonomes.**
- **Le message cité dans l'aide est celui de `FileController`** (« Ce
  fichier n'est pas accessible avec votre compte. ») : un lien réservé
  ne redirige pas vers la connexion, il refuse. L'aide dit ce que la
  personne voit réellement.

**Divergences avec le document de chantier.** La roadmap place la
section de `specifications.md` et les deux sujets d'aide à l'IT-03 ; les
tests d'inventaire les exigeaient dès l'IT-01 (voir cette section).
L'IT-03 les complète au lieu de les créer.

**Reporté.** Rien.


**Vérification finale.** `tests/Architecture` (dont la résolution des
renvois), `tests/Core/Help`, `ModuleSpecificationCoverageTest` et
`tests/Modules/Documents` verts.
