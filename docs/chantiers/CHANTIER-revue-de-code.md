# Chantier — Revue de code complète

Document de chantier. Il complète `ARCHITECTURE.md`, `SECURITY.md`,
`AGENTS.md`, `CONTRIBUTING.md` et `docs/quality-pipeline.md`, qui restent la
source de vérité pour les règles elles-mêmes. Il ne les recopie pas.

Il porte sur **les défauts** : bugs, failles, coûts. Pas sur le style, pas sur
la duplication, pas sur le nommage — Sonar et CodeRabbit s'en occupent déjà et
mieux.

Son point de départ n'est pas « relire le code », c'est une question posée à
`docs/quality-pipeline.md` : **PHPStan, six suites PHPUnit, deux moteurs de
base, Playwright, la matrice d'autorisation ZAP, CodeQL et Sonar tournent sur
chaque commit — que reste-t-il qu'aucun d'eux ne peut voir ?** Chaque itération
ci-dessous est une de ces réponses. C'est ce qui les rend finies : une
itération ne relit pas un dossier, elle pose une question à tout le code et
s'arrête quand elle y a répondu partout.

---

## 0. Règles communes à toutes les itérations

### 0.1 On ne corrige rien

**Un défaut trouvé devient une issue GitHub, jamais un correctif.** Sans
exception, y compris quand la correction tient en une ligne : une revue qui
corrige au fil de l'eau produit une branche que personne ne peut relire, et
mélange le constat avec sa réparation au moment précis où il faudrait pouvoir
discuter du second sans remettre le premier en cause.

La seule chose qu'une itération écrit dans le dépôt est son entrée de journal
(§0.6). Le reste part dans les issues.

### 0.2 Un constat non reproduit n'est pas un constat

**Avant d'ouvrir une issue, le défaut doit avoir été observé sur une instance
réelle**, peuplée par le jeu de données de référence :

```bash
composer install                      # dépendances dev incluses : le builder
                                      # vit sous autoload-dev
php tests/fixtures/reference-dataset/build.php --yes --root=/chemin/instance
```

`--reset` vide d'abord l'installation cible ; ne jamais viser autre chose
qu'une instance jetable. `scripts/e2e-support.php provision` en fabrique une.
Les comptes de démonstration et leur mot de passe sont dans
`tests/fixtures/reference-dataset/README.md`.

La raison est écrite dans `docs/quality-pipeline.md` § *Le mode de défaillance
que ce dépôt rencontre* : ce dépôt s'est déjà fait avoir par un lecteur de code
qui n'avait jamais fait tourner le site. Un `bug:confirmed` produit par lecture
seule est exactement cette erreur, une couche plus haut.

Quand le défaut résiste à la reproduction **alors que la zone est
reproductible**, une seule forme est honnête : ouvrir le constat en disant ce
qui a été tenté et ce qui s'est passé à la place. Ne jamais écrire « devrait »
au passé.

### 0.2 bis — Les zones qu'on ne peut pas faire tourner

Une partie du code ne s'exécute pas sur une instance de test, faute
d'identifiants ou d'infrastructure : le fournisseur téléphonique OVH
(`modules/sos_staff`), les fournisseurs d'IA (`modules/llm_connector`), le
stockage S3 de la galerie, le relais SMTP et la vérification DNS DKIM/SPF/DMARC,
le webhook GitHub et le client de release, l'envoi Web Push vers un vrai
endpoint, le site de support et son jeton, Ghostscript quand il est absent,
les deux dispositions d'installation de `bootstrap.php` sur un hébergement
mutualisé réel, le vrai crontab, et les contraintes LWS (`open_basedir`,
`disable_functions`, CageFS).

**Ces zones sont dans le périmètre et leurs défauts se rapportent.** Les en
exclure reviendrait à ne jamais relire précisément le code que personne ne
voit tourner — c'est-à-dire celui où un défaut survit le plus longtemps.

Trois règles s'y appliquent à la place de §0.2 :

1. **Reproduire derrière la couture, pas à travers le fournisseur.** Ce dépôt
   a mis une interface exactement là où passe la frontière :
   `PhoneProviderInterface`, `GitHubReleaseClientInterface`,
   `LlmConnectorInterface`, `BackupServiceInterface`, les backends de stockage
   de la galerie. Un doublon d'essai derrière l'interface exerce **tout le code
   de ce dépôt** ; ce qui reste hors d'atteinte est le protocole du tiers, pas
   la logique en cause. Un constat reproduit ainsi est un constat reproduit :
   dire lequel des deux côtés a été exercé suffit.
2. **Quand il n'y a pas de couture, c'est un constat en soi.** Un appel réseau
   émis depuis un Service sans interface interposée rend le comportement
   invérifiable pour toujours, y compris pour la correction future. L'ouvrir
   comme tel, séparément du défaut soupçonné derrière.
3. **Ce qui reste ne s'ouvre que s'il se démontre par lecture seule.** Un
   ordre d'arguments inversé, un code de retour ignoré, une exception non
   rattrapée sur un chemin sans repli, une signature qui ne correspond pas à
   la documentation du tiers : chacun se prouve en citant le code, sans
   hypothèse sur ce que le tiers répond. « Ça pourrait mal se comporter » n'est
   pas un constat et reste dans le journal.

Le corps de l'issue porte alors une section **« Non reproduit »** en clair,
disant ce qui manquait pour le faire et ce qui a été exercé à la place. Elle
est obligatoire : sans elle, le triage lit une observation là où il y a une
lecture, et un `bug:confirmed` s'appuie sur une preuve qui n'existe pas.

### 0.3 Une issue par constat, une issue par famille

Un constat = une issue. **Mais la même erreur répétée dans N modules = une
seule issue qui les liste** — dix issues disant la même chose coûtent dix
triages et se répondent en une phrase.

Chaque issue est en **français** (les issues font partie de l'interface, comme
les formulaires de `.github/ISSUE_TEMPLATE/`), porte le label
`triage:pending` à la création, et contient :

- **Ce qu'on observe**, à l'indicatif, sur l'instance de référence ;
- **La commande ou le clic exact** qui le reproduit, depuis un dépôt neuf ;
- **Ce qui était attendu**, et d'où vient cette attente (fichier, section de
  `ARCHITECTURE.md`, commentaire du code) ;
- **Le fichier et la fonction** en cause — jamais un numéro de ligne seul, il
  aura bougé ;
- **Aucune donnée personnelle**, même issue d'un jeu fictif : la règle ne
  s'assouplit pas selon la provenance ;
- une section **« Non reproduit »** quand §0.2 bis s'applique, et rien
  d'équivalent quand elle ne s'applique pas — c'est ce contraste qui rend
  l'absence de section lisible comme « observé ».

Ne jamais proposer le correctif dans l'issue. Décrire le défaut suffit ; le
correctif est une décision, et elle appartient au mainteneur.

**Une faille de sécurité s'ouvre en issue publique comme le reste**, pendant la
durée de ce chantier : aucune installation n'est en production. Le signalement
privé de vulnérabilité du dépôt reste le bon canal pour un tiers, et le
redeviendra pour ce chantier le jour où un site tourne pour de vrai — décision
à rouvrir ce jour-là, pas avant.

### 0.4 Les issues passent par le triage normal

C'est délibéré : le triage est un second lecteur, et un constat d'audit qu'il
renvoie en `bug:not-a-bug` mérite d'être relu avant d'être cru.

Conséquence à connaître : `issue-triage.yml` se déclenche sur `issues: opened`,
donc **chaque issue ouverte consomme un run de triage** et fait apparaître un
commentaire public. C'est la raison du regroupement en §0.3, pas une raison
d'ouvrir moins.

Ne jamais appliquer `triage:done`, `bug:*` ou `status:accepted` à la main :
`docs/quality-pipeline.md` § *Labels* explique pourquoi les labels sont l'état,
et une itération qui les écrit elle-même rend le triage aveugle.

### 0.5 Ce qu'on n'ouvre pas

- Une remarque de style, de nommage, de duplication → journal, pas issue.
- Une préférence d'architecture sans conséquence observable → journal.
- Un manque de test sur un invariant par ailleurs vérifié → **une seule issue
  par itération** les regroupant, jamais une par invariant. Même traitement
  pour les coutures manquantes de §0.2 bis, point 2.
- Ce que `docs/chantiers/CHANTIER-performance.md` a déjà mesuré et tranché.
- Les éléments explicitement acceptés dans `ARCHITECTURE.md` (les fichiers
  orphelins de `FullResetHandler`, l'exception `/api/offline/photo/{id}`, la
  dispense CSRF du webhook GitHub). Les relire pour vérifier que la
  justification tient encore ; ne pas les rouvrir parce qu'ils surprennent.

### 0.6 Ce qu'une itération livre

Ce fichier n'est pas encore dans le dépôt : il arrive en pièce jointe. **La PR
de l'itération 1 le dépose en `docs/chantiers/CHANTIER-revue-de-code.md`**, tel
quel, avant d'y écrire sa propre entrée de journal — comme tous les autres
chantiers, pour qu'une session ultérieure retrouve les décisions au lieu de les
réinventer.

Ensuite, une PR contenant **uniquement** l'entrée de journal de l'itération,
ajoutée au §11 de ce fichier :

- la question posée et le périmètre réellement parcouru ;
- les issues ouvertes, par numéro, une ligne chacune ;
- **ce qui a été vérifié et tenu** — c'est la moitié utile du journal : sans
  elle, personne ne saura jamais si un silence signifie « rien à signaler » ou
  « pas regardé » ;
- ce qui n'a pas pu être vérifié, et pourquoi.

Aucun test n'est ajouté : un test qui reproduit un bug est rouge, et une PR
rouge ne se fusionne pas. La reproduction vit dans l'issue.

La PR est rebasée sur `main`, l'auto-merge est armé une fois `All checks` vert
sur le head courant, et l'itération suivante démarre depuis `main` à jour.

### 0.7 Ne pas refaire ce qui tourne déjà

Avant de chercher, lire ce qui couvre déjà la question :

| Déjà couvert par | Ne pas re-parcourir |
|---|---|
| `tests/Security/SqlInjectionAuditTest` | la concaténation SQL |
| `tests/Security/AuthorizationMatrixInventoryTest` + `dast.sh --profile=standard` | le `role_min` déclaré de chaque route |
| `tests/Security/EncryptionAuditTest` | quelles colonnes sont chiffrées |
| `tests/Security/CsrfGuardCoverageTest` | la présence du jeton sur les POST |
| `tests/Architecture/ModuleBoundariesTest` | les références hors `Api\` |
| `tests/Architecture/ModuleSchemaBoundariesTest` | les FK inter-modules |
| CodeQL | les puits DOM en JavaScript |

Ces couches disent ce qui est **déclaré**. Aucune ne dit ce qui est **fait**.
C'est là que toutes les itérations travaillent.

---

## 1. Le jeu de données de référence emprunte-t-il le chemin réel ?

**Périmètre** : `tests/fixtures/reference-dataset/` en entier, confronté aux
contrôleurs et services que chaque seeder prétend rejouer.

**Pourquoi en premier** : le `README.md` du dossier promet que le builder
« rejoue le tout à travers les vrais services de l'application », et c'est cette
promesse qui rend la reproduction de §0.2 valable. Un seeder qui court-circuite
une étape ne produit pas seulement une donnée fausse : il rend **toutes les
itérations suivantes non concluantes**, puisqu'elles observeront un site que
personne n'utilise. Cette itération est la fondation des neuf autres.

**Deux constats déjà établis, à ouvrir en l'état** (reproduits par lecture
croisée, à confirmer sur instance) :

1. **Les images d'articles n'ont pas de dérivés.**
   `NewsSeeder::upload()` appelle `Core\File\UploadHandler::handle()` et
   s'arrête là. Le chemin réel,
   `Modules\News\Controller\NewsController::resolveUploadedImageFileId()`,
   enchaîne sur `ImageVariantService::generate()` pour chaque
   `ImageVariantService::VARIANTS`. Or `Core\View\TwigFactory` demande
   `/files/{id}/thumb` et `/files/{id}/md`, et
   `Core\Http\Controller\FileController::variant()` répond **404** quand le
   dérivé manque — il ne retombe jamais sur l'original, et son commentaire dit
   que c'est délibéré. Une instance fraîchement construite affiche donc des
   images cassées partout où un article apparaît, jusqu'à ce que
   `Modules\News\Task\GenerateImageVariantsHandler` passe — c'est-à-dire au
   bout d'assez de visites pour que le poor man's cron l'atteigne, la tâche
   n'étant amorcée qu'à `public/index.php` sous le drapeau
   `news_image_variants_backfilled`.
   *À vérifier en reproduisant* : combien de visites avant que la liste
   d'actualités s'affiche complète, et ce que voit un visiteur public entre
   les deux.

2. **La couverture d'un article réservé est publique.**
   `NewsSeeder::upload()` passe `'public'` en `role_min` pour toutes les
   images. Le contrôleur, lui, aligne le `role_min` du fichier sur la
   visibilité de l'article (`identified` / `chief` / `admin`), avec en
   commentaire la raison : *« ou l'image dit ce que la page tait »*. Dans le
   jeu de référence, la couverture d'un article chefs est donc lisible par
   n'importe qui via `/files/{id}`. Le défaut est dans la fixture, pas dans le
   produit — mais une assertion d'étanchéité écrite sur cette fixture
   mesurerait l'inverse de la réalité, ce qui est pire qu'aucune assertion.

**Ce qui doit être vérifié, seeder par seeder** — il y en a une vingtaine
(`News`, `Gallery`, `Finance`, `Camps`, `Rental`, `Registration`, `Calendar`,
`Banner`, `Campaign`, `Staff`, `ExtrasApplier`, `PhotoAssigner`…) :

- **Chaque seeder appelle-t-il le service que le contrôleur appelle, ou une
  couche en dessous ?** Descendre d'un cran (Repository au lieu de Service)
  saute silencieusement les effets de bord : dérivés d'image, notification,
  entrée de journal, tâche planifiée, invalidation de cache, mise à jour d'un
  compteur.
- **Chaque valeur passée en dur est-elle la même que celle que le contrôleur
  calcule ?** `role_min`, visibilité, année scoute, `module_id`, propriétaire
  (`owner_member_id`), état initial. C'est la classe du constat n°2, et elle se
  cherche argument par argument.
- **Ce qui est créé est-il visible ?** Pour chaque objet semé, ouvrir la page
  où il doit apparaître, avec le rôle qui doit le voir et avec celui qui ne le
  doit pas.
- **Le drapeau de fin est-il honnête ?** Un seeder qui pose un
  `…_seeded`/`…_backfilled` que la vraie exécution n'aurait pas posé neutralise
  une reprise dont l'instance a besoin.
- **Le builder est-il rejouable ?** Deux exécutions successives sur la même
  instance : doublons, contrainte violée, ou refus propre.
- **`--reset` vide-t-il vraiment ce qu'il prétend vider ?** Fichiers de
  `storage/` compris, pas seulement les tables.

---

## 2. La portée objet, au-delà du `role_min`

**Question** : un utilisateur qui a le bon rôle peut-il atteindre l'objet d'un
autre en changeant un identifiant dans l'URL ?

**Périmètre** : toutes les routes dont le chemin porte un `{id}` — soit
l'essentiel des 520 routes déclarées.

**Ce qui est déjà couvert, et pourquoi ça ne suffit pas** : la matrice ZAP
rejoue chaque route sous chaque rôle et compare au `role_min` déclaré. Elle
prouve donc qu'un `identified` n'entre pas sur une route `chief`. Elle ne dit
**rien** de deux `chief` de sections différentes, ni de deux membres d'une même
famille, ni d'un `intendant` face au compte financier d'un autre. C'est le plus
gros angle mort de sécurité du dépôt, et il grandit à chaque module.

**Ce qui doit être vérifié** :

- Pour chaque contrôleur prenant un `{id}` : **une vérification d'appartenance
  existe-t-elle après le garde de rôle ?** `ARCHITECTURE.md` §2 l'autorise
  explicitement comme second contrôle ; l'absence de ce second contrôle est le
  défaut recherché.
- Cette vérification est-elle **côté serveur et non conditionnée par l'écran
  d'où vient la requête** ? Un contrôle présent dans une page mais absent de la
  route POST équivalente est le cas classique.
- Les cas où le dépôt a déjà écrit la règle, à confronter au code :
  `MemberService::canAccess()`, `MemberEmailService::isOwnMember()`
  (§8.27, aucun contournement chef/admin), `files.owner_member_id` via
  `FileAccessGuard` (§8.3, aucun contournement non plus),
  `BadgeService::toggleAssignment()` (§8.11),
  `Modules\MassMail\Controller\MemberEmailController` (§8.22),
  `TreasurerScope` et `GroupAccessService`.
- **Les identifiants devinables** : réponses de formulaire, pièces jointes de
  finance, documents de section, réservations, messages entrants,
  billetterie. Pour chacun : deux comptes de démonstration, l'objet de l'un,
  l'URL ouverte par l'autre.
- **La réponse trahit-elle l'existence de l'objet ?** Un 403 sur un objet
  existant et un 404 sur un objet inexistant énumèrent le contenu.

**Reproduction attendue dans l'issue** : deux comptes du jeu de référence,
l'URL exacte, le code HTTP obtenu, ce qui s'affiche.

---

## 3. Les données personnelles sortent-elles du Repository ?

**Question** : `EncryptionAuditTest` prouve que les colonnes sont chiffrées.
Que deviennent les valeurs **après** le `decrypt()` ?

**Périmètre** : tout ce qui écrit ailleurs que dans une page — journal,
notifications, push, mails, exports, archives de support, messages d'erreur,
logs.

**Ce qui doit être vérifié** :

- **`JournalService::log()`** : le tableau `context` de chaque appel. La règle
  (`SECURITY.md` §11) est « jamais de donnée personnelle, seulement un
  `member_id` ». À confronter appel par appel, y compris dans les modules.
- **Les notifications** : le titre et le corps passés à
  `NotificationService::dispatch()`. Une notification stocke sa charge en base
  et la pousse chez un tiers ; le mode discrétion (§8.24) ne protège que le
  push, pas la ligne stockée.
- **Les messages d'erreur rendus à l'utilisateur**, en particulier ceux
  construits depuis une exception (`UserFacingMessage`) : une exception PDO
  peut porter la valeur qui a violé une contrainte.
- **Les exports** : XLSX de réponses de formulaire, PDF de trombinoscope et de
  liste de section, ICS de calendrier, sauvegardes. Qui peut les demander, ce
  qu'ils contiennent, où ils atterrissent, et avec quel `role_min` de fichier.
- **L'archive de support** (§8.49sexies / `SECURITY.md` §18ter) : l'anonymisation
  est la garantie sur laquelle repose l'accès de l'agent de triage à des
  journaux de site réel. Vérifier ce qui échappe au remplacement — un nom dans
  un champ libre, une adresse dans un `context` de journal, un totem.
- **Les index aveugles** : une recherche exacte sur un champ chiffré passe-t-elle
  par l'index, ou déchiffre-t-elle en mémoire ? La seconde forme est autorisée
  mais doit être documentée (`ARCHITECTURE.md`) et **bornée** — sinon elle
  appartient aussi à l'itération 7.
- **Le déchiffrement hors Repository** : chercher les appels à
  `EncryptionService::decrypt()` dans un Service ou un Contrôleur.

---

## 4. Les entrées non fiables

**Question** : que fait le code de ce qu'il n'a pas écrit — fichier téléversé,
HTML riche, CSV importé, mail entrant, URL distante ?

**Périmètre** : `Core\File\UploadHandler`, `Core\Security\HtmlSanitizer`, les
imports (Desk, relevés bancaires, factures fédérales), `modules/inbound_mail`,
`modules/gallery`, tout appel sortant.

**Ce qui doit être vérifié** :

- **Le type MIME est-il vérifié par le contenu et non par l'annonce du
  client ?** `UploadMimeTrustAuditTest` existe : lire ce qu'il couvre, chercher
  les chemins qui lui échappent — dont les seeders de l'itération 1, qui
  passent un `type` en dur.
- **L'injection de formule dans les exports** : une réponse de formulaire
  commençant par `=`, `+`, `-` ou `@` s'exécute à l'ouverture du XLSX chez le
  destinataire. Vérifier ce que fait `phpoffice/phpspreadsheet` ici et si une
  neutralisation existe.
- **Le SSRF du scraper Open Graph du module `gallery`** — déjà identifié comme
  à durcir avant que `groups` ne l'expose à tout membre identifié : absence de
  contrôle d'IP privée, suivi de redirection non borné. À rouvrir en issue avec
  sa reproduction, puisque `groups` a avancé depuis.
- **Toute construction d'URL interne** : `HTTP_HOST` ne doit jamais servir à
  fabriquer une URL que le serveur appelle lui-même (le saut de continuation du
  scheduler l'a déjà payé). Chercher chaque usage.
- **Le HTML riche** : ce que la liste blanche de `HtmlSanitizer` laisse passer,
  confronté aux 306 `|raw` des gabarits. Chaque `|raw` sur une valeur qui n'est
  pas passée par le sanitizer est un constat.
- **Les en-têtes de mail** : un sujet ou un nom d'expéditeur construit depuis
  une valeur utilisateur.
- **Le mail entrant** : pièces jointes, encodages, taille, expéditeur usurpé.

---

## 5. Le contrat des modules

**Question** : ce que `module.json` déclare correspond-il à ce que le module
fait, et le module survit-il à l'absence de ceux dont il dépend ?

**Périmètre** : les 22 `module.json`, les 22 `schema.sql`, et le câblage
optionnel de `public/index.php` (7 139 lignes).

**Ce qui doit être vérifié** :

- **La règle du bump de version** (`AGENTS.md` § Database) : pour chaque
  `schema.sql`, l'historique git montre-t-il une modification sans bump de
  `version` correspondant dans `module.json` ? C'est le seul défaut de cette
  liste qui a déjà cassé de la production (`Unknown column`), et il est
  invisible à toute installation neuve — il ne se voit que sur une instance
  déjà activée. Reproduction : construire l'instance, modifier, réactiver.
- **Chaque `settings` déclaré a-t-il une `description` non nulle**, et chaque
  `cookies` déclare-t-il ce que le module pose réellement ? Le second se
  vérifie en comparant les `setcookie()` du module à sa section `cookies` —
  la page de préférences prétend être exhaustive.
- **Les dépendances optionnelles dégradent-elles vraiment ?** Pour chaque
  consommateur d'une interface `Api\` nullable (§7.5), désactiver le module
  fournisseur et ouvrir chaque page du consommateur. La matrice de démarrage
  par module de `npm run e2e:full` prouve que le site **démarre** ; elle ne
  prouve pas qu'une page rend quelque chose de sensé.
- **Le câblage est-il conditionné au bon endroit ?** Un service optionnel
  instancié inconditionnellement dans le composition root ramène la dépendance
  dure que §7.5 interdit.
- **Les tâches planifiées déclarées existent-elles**, et sont-elles enregistrées
  **dans les deux points d'entrée** (`public/index.php` et `public/cron.php`) ?
  Ce dépôt a déjà eu un `create_backup` absent de `cron.php`, qui échouait
  silencieusement dès qu'un vrai cron tournait.

---

## 6. L'année scoute effective

**Question** : chaque lecture est-elle bornée à l'année qu'elle croit lire ?

**Périmètre** : toute requête portant — ou omettant — un `scout_year_id`.

**Pourquoi une itération à elle seule** : c'est le défaut le plus spécifique à
ce produit et le plus invisible à tout le reste. Il ne plante pas, ne déclenche
aucune alerte et ne se voit pas sur une instance d'une seule année : il affiche
simplement la donnée d'une autre année. Le jeu de référence en couvre trois,
c'est précisément ce qui le rend reproductible ici.

**Ce qui doit être vérifié** :

- **Chaque `findBy…` de Repository sur une table portant `scout_year_id`
  prend-il l'année en paramètre ?** Une méthode qui ne la prend pas est un
  constat, même si tous ses appelants filtrent ensuite.
- **L'année utilisée est-elle l'année *effective*** (`ScoutYearResolver`,
  §8.26) et non « l'année publique » ni « la dernière » ? Les trois divergent
  pendant la transition, exactement quand le site est le plus regardé.
- **Le mode aperçu** (session seule, admin) fuit-il ? Une écriture faite sous
  aperçu doit atterrir dans l'année aperçue, ou être refusée — jamais dans
  l'année publique.
- **L'année du staff** : un chef en année N+1 qui écrit sur une page partagée
  avec les membres en année N.
- **Les tâches de fond** n'ont pas de session : quelle année lisent-elles ?
  Une tâche planifiée en septembre et exécutée en octobre a changé d'année
  entre les deux.
- **Le cas connu** : `member_functions.start_date` ne doit pas servir à
  détecter une première année d'animation (il est remis à zéro au changement
  de section) — `member_section_periods` le fait. Chercher les autres lectures
  de dates qui font une hypothèse du même genre.

---

## 7. Les requêtes non bornées

**Question** : qu'est-ce qui grandit avec le nombre de membres, d'années,
d'articles ou de fichiers ?

**Périmètre** : les Repository et les Services qui les enchaînent.

**Frontière avec `CHANTIER-performance.md`** : ce chantier-là a mesuré le
plancher payé par chaque requête et l'a traité. Celui-ci cherche autre chose :
les requêtes dont le coût dépend d'une donnée que l'unité fait grossir, y
compris sur des pages que la campagne de mesure n'a pas ouvertes. Ne pas
re-mesurer ce qui l'a été ; lire le §5 de ce fichier avant de commencer.

**Ce qui doit être vérifié** :

- **Chaque `findAll()`** (58 fichiers) : borné, ou borné seulement par la
  taille actuelle du jeu de test ?
- **Chaque requête dans une boucle** : le N+1 classique. Le compteur
  d'instructions SQL de `Core\Database\InstrumentedPdo` et
  `scripts/perf/bench-pages.php` le rendent visible sans lecture.
- **Chaque déchiffrement en masse** : déchiffrer tous les animés d'une année
  pour afficher un compteur est le motif que la campagne de perf a déjà trouvé
  une fois. Chercher les autres.
- **Les index** : toute colonne servant de filtre — `scout_year_id`, chaque
  index aveugle, chaque FK — porte-t-elle un index dans le `schema.sql` ? Une
  colonne d'index aveugle sans index est une recherche séquentielle sur du
  BLOB.
- **Ce qui est chargé et jeté** : `SELECT *` (341 occurrences) sur une table
  portant des BLOB chiffrés, pour n'en lire qu'une colonne claire.
- **Les pages sans pagination** : journal, membres, réponses de formulaire,
  messages entrants, galerie.
- **Ce qui est recalculé à chaque requête** plutôt qu'à l'écriture.

**Ce qui doit être mesuré, pas supposé** : toute issue de cette itération porte
un chiffre — nombre d'instructions SQL, ou millisecondes médianes sur trois
requêtes, à l'échelle du jeu de référence. Un constat de performance sans
mesure n'est pas un constat.

---

## 8. Les tâches de fond

**Question** : que se passe-t-il quand une tâche tourne deux fois, s'arrête au
milieu, ou ne tourne jamais ?

**Périmètre** : `Core\Scheduler`, tous les `Task\…Handler` du cœur et des
modules, les deux points d'entrée.

**Ce qui est déjà couvert** : `ChainSeedingInvariantTest`,
`CronPassSingleFlightTest`, `RecurringTasksRearmTest`,
`ScheduledTasksAreTestedTest`, `BackgroundWorkIsNotSilentTest`. Le cœur du
scheduler est donc tenu. Les handlers des modules le sont beaucoup moins.

**Ce qui doit être vérifié, handler par handler** :

- **Idempotence** : le rejouer produit-il le même état, ou un doublon ? Le
  poor man's cron n'a pas de garantie d'exécution unique en cas de crash après
  effet et avant marquage.
- **Reprise** : un handler qui dépasse son budget de temps reprend-il là où il
  s'est arrêté, ou recommence-t-il ? Un traitement qui recommence sur une
  unité de 1 500 membres ne finit jamais.
- **Ce qui se passe quand personne ne visite le site** : `poor_mans_cron`
  n'avance que sur visite. Toute fonction dépendant d'une heure précise
  (redirection SOS, digest, rappel) doit le dire dans son écran de
  configuration. Vérifier que l'avertissement existe là où il est nécessaire.
- **L'échec est-il visible ?** Une tâche qui échoue en silence est la
  défaillance que `docs/quality-pipeline.md` documente à longueur de page.
  Journal, statut, notification : lequel des trois, et est-il lisible par
  quelqu'un ?
- **L'ordre** : deux tâches concurrentes sur la même ressource (une sauvegarde
  pendant une mise à jour, un import pendant une synchronisation).
- **Le contexte** : `TaskContext` porte-t-il tout ce dont le handler a besoin,
  ou le handler reconstruit-il un service avec des valeurs par défaut fausses
  hors requête HTTP (URL du site, année, fuseau) ?

---

## 9. Les écritures partielles

**Question** : quand une opération en plusieurs pas échoue au troisième, dans
quel état est le site ?

**Périmètre** : tout ce qui écrit en base **et** sur disque, ou dans deux
tables sans transaction.

**Ce qui doit être vérifié** :

- **Fichier écrit sans ligne, ligne sans fichier** : téléversement, pièce
  jointe, sauvegarde, export, dérivés d'image. `EncryptedFileStorageService`
  et `FileRepository` sont deux écritures ; qui les rend atomiques ?
- **Les transactions** : où commencent-elles, où se terminent-elles, et une
  exception au milieu déclenche-t-elle un rollback ? Chercher les `beginTransaction()`
  sans `finally`.
- **Les exceptions avalées** : chaque `catch` qui ne relance rien et
  n'enregistre rien, et chaque `@` devant un appel. Certains sont justifiés
  (`generate()` ne doit pas faire échouer un téléversement) — le constat est
  celui qui ne l'est pas.
- **Le nettoyage** : fichiers temporaires supprimés en `finally` et non en fin
  de chemin heureux. Le CSV Desk et les relevés bancaires doivent disparaître
  « succès ou échec » (`SECURITY.md` §5).
- **La suppression** : supprimer un membre, une section, une année, un article,
  un compte financier — que devient ce qui pointait dessus ? Ligne orpheline,
  fichier orphelin, ou refus propre.
- **La restauration** : une sauvegarde restaurée rend-elle un site cohérent ?
  Ce que `BackupService` exclut (`storage/keys/`, `storage/config/`) est ce qui
  rend une archive non portable — vérifier que le produit le dit là où
  quelqu'un le lira.

---

## 10. Le navigateur

**Question** : que fait le code côté client quand le réseau, la session ou le
cycle de vie de l'application ne coopèrent pas ?

**Périmètre** : `public/assets/js/` (110 fichiers, ~24 000 lignes),
`public/sw.js`, les gabarits qui les câblent.

**Ce qui est déjà couvert** : CodeQL pour les puits DOM, Vitest pour la logique
découplée, Playwright pour les parcours. Aucun des trois ne regarde l'état
après réveil ni la cohérence du cache.

**Ce qui doit être vérifié** :

- **Le cache hors ligne sert-il ce qu'il prétend ?** Une réponse redirigée
  n'est jamais mise en cache (§8.25) — vérifier que la règle tient sur tous les
  chemins, y compris ceux ajoutés depuis.
- **Le retrait du consentement fonctionnel** vide-t-il vraiment les caches
  `content-*`, et le worker cesse-t-il d'écrire ensuite ?
- **Le changement de compte sur le même appareil** : la portée de cache par
  `user_accounts.id` tient-elle aussi quand la session expire sans
  déconnexion ?
- **L'application installée au réveil** : une hypothèse de connectivité
  périmée, un badge non rafraîchi, un formulaire soumis deux fois parce que
  l'écran est revenu.
- **Le double envoi** : chaque bouton qui déclenche une écriture est-il
  désactivé pendant la requête ? La conséquence sur une inscription payante
  n'est pas la même que sur un filtre.
- **Ce qui est désactivé côté client seulement** : un bouton grisé, un champ
  `readonly`, une liste filtrée en JS — pour chacun, la route POST refuse-t-elle
  aussi ?
- **La nonce CSP** : `InlineStyleElementNonceTest` couvre les styles. Les
  scripts injectés dynamiquement, et le blob JSON `#offline-config-data`.

---

## 11. Journal

Une entrée par itération, ajoutée par la PR de fin d'itération (§0.6). Format :

```text
### Itération N — <titre> — <date>

**Périmètre parcouru** : …
**Issues ouvertes** : #… (une ligne par issue)
**Vérifié et tenu** : …
**Non vérifiable, et pourquoi** : …
```

### Itération 1 — Le jeu de données de référence emprunte-t-il le chemin réel ? — 2026-09-07

**Périmètre parcouru** : `tests/fixtures/reference-dataset/` en entier — les
seize classes qui écrivent (`DemoAccounts`, `ModuleActivator`,
`DeskImportReplay`, `FinanceSeeder`, `CampaignSeeder`, `ExtrasApplier`,
`StaffSeeder`, `CalendarSeeder`, `NewsSeeder`, `CampsSeeder`,
`RegistrationSeeder`, `BannerSeeder`, `GallerySeeder`, `RentalSeeder`,
`InstanceReset`, `build.php`), chacune confrontée au contrôleur ou au service
que le site appelle pour le même geste. Instance construite trois fois au
commit `1614aee` (provision par `scripts/e2e-support.php`, `build.php --yes
--reset`, deux fois sur la même instance, une fois sur une seconde instance
vierge), servie par `php -S`, parcourue avec les six comptes du README §10
plus un visiteur anonyme : les 138 routes `GET` sans paramètre sous chaque
rôle, les pages des objets semés (`/news` et chaque article, `/sections`,
`/trombinoscope`, `/gallery`, `/calendar`, `/locations`, `/inscriptions`,
`/finance/*`, `/admin/scout-year`, `/admin/journal`), puis une passe de
`public/cron.php` et l'état d'après.

**Issues ouvertes** :
- #210 — les images d'articles n'ont pas de dérivés : vignettes en 404 jusqu'à une passe de cron (le poor man's cron n'existe plus), et pour toujours après `--reset` (constat n° 1 du §1, aggravé) ;
- #211 — couverture et image de corps d'un article `chief`/`identified` en `role_min = public` (constat n° 2 du §1, étendu aux images de corps) ;
- #212 — `--reset` épargne `settings`, donc `current_scout_year_id` : l'instance de référence affiche 2024-2025 comme année publique (`/admin/scout-year`, `/sections`, 403 du chef d'unité sur `/config/banner`), et sept drapeaux de reprise/d'exécution survivent au vidage ;
- #213 — `ModuleActivator` résout le profil d'installation depuis un `base_url` que seule la première requête web écrit : 20 modules sur 22, les deux `visible_when` ni activés ni nommés ;
- #214 — `build.php` n'appelle pas `AppClock::apply()` : tout ce que le builder écrit est daté deux heures avant le site (journal, tâches, fichiers) ;
- #215 — le formulaire d'inscription « ouvert » est refermé par la première passe du planificateur (marqueur `applied_on` vide + fenêtre de rattrapage), et une ouverture manuelle sur une installation neuve subit la même chose ;
- #216 — une issue pour la famille « descendu d'un cran » : articles, réponses, demandes d'inscription, badges, décalages, évènements, bannières sans la ligne de journal du vrai chemin ; réponses sans compte sur un formulaire `identified` ; import Desk sans les listeners.

**Vérifié et tenu** :
- Le refus du builder sur une instance qui a servi, et `--reset` qui l'obtient : 183 tables vidées, 149 fichiers supprimés — tout `storage/` hors `keys/`, `config/`, `maintenance/`, y compris les dérivés d'image et les caches de `temp/` — ; les effectifs (176 / 178 / 178), les identifiants et les compteurs identiques d'une construction à l'autre ; la sauvegarde de sécurité écrite avant le vidage.
- Les photos : 43 portraits et 14 photos de groupe par `PhotoIngestionService`, le même pipeline que `/upload`, dérivés compris ; le repli d'année de `MemberPhotoService` et de `SectionPhotoService` (le trombinoscope montre 25 portraits et 7 avatars d'initiales ; les sept sections photographiées ont une photo sur `/sections`, seule l'année lue est fausse — #212).
- La visibilité des articles : `/news` liste 1 et 2 pour un anonyme, 1, 2 et 4 pour un membre ; `/news/3` (chief) refuse animé, intendant et parent ; `/news/4` (identified) refuse l'anonyme ; `/news/5` (`direct_link`) s'ouvre par son adresse pour tous. Les articles `chief` ne sont listés sur `/news` pour personne — `ArticleService::listableVisibilities()` s'arrête à `identified`, et `/news/manage` les porte : un choix, pas un défaut.
- Les finances : 2 comptes d'unité et 8 comptes de section avec IBAN, 190 mouvements dont 65 de la campagne, 12 doublons reconnus, 10 catégories, 195 créances ; les pages `/finance/*` répondent 200 à l'intendant et 403 à l'animé.
- Les 520 évènements par `CalendarEventService::createEvent()` et rendus sur `/calendar` ; les 5 lieux et 12 séjours par `PlaceService`/`CampService` avec leur historique d'audit (`AuditSource::System`) ; les 7 réservations par le vrai cycle `received → reviewing → confirmed → closed` (14 changements de statut journalisés) ; l'album externe par `AlbumService::create()`, avec ses balises Open Graph récupérées (le réseau sortant passe ici) ; les 5 bannières par `BannerService` et `EditableContentService`.
- La matrice des rôles sur les 138 routes `GET` sans paramètre : aucune 500 hors `/test-tools/uncaught-error` (voulue), aucun 200 sous le `role_min` déclaré, aucune page d'erreur PHP dans le journal du serveur. Les 403 de `/config/banner` et `/config/retro` au chef d'unité et au superadmin viennent de `requireUnitChief()` — et de l'année effective (#212) pour le premier ; `/mes-locations` refuse le superadmin, qui n'a pas de membre.
- Les comptes de démonstration : les six se connectent par mot de passe, et le rôle obtenu est bien dérivé des fonctions confirmées (`RoleResolver`), jamais écrit.
- La première passe de cron : 24 tâches exécutées sans échec ; la reprise des vignettes fait son travail (#210) ; `purge_desk_imports` supprime l'import de 2024-2025 au premier passage — `import_retention_scout_years = 2`, c'est la règle, mais le README ne dit pas que l'historique des imports d'une instance de référence commence à A2.

**Non vérifiable, et pourquoi** :
- Le décalage de **date** de #214 (un build entre 22 h et minuit UTC) : la construction a eu lieu à 19:55 UTC ; seul le décalage de deux heures a été observé, la conséquence sur les dates l'a été par le harnais E2E (`e2e_apply_application_clock()`), pas ici.
- La face « produit » de #215 par le bouton de Configuration › Inscriptions : reproduite par le builder, qui écrit le même réglage au même endroit, pas par le clic.
- La construction sur une installation faite par l'assistant d'installation plutôt que par `e2e-support.php provision` : #212 et #213 en dépendent (`current_scout_year_id` épinglé par le provisionnement, `module_registry` épargné) ; leur forme sur un vrai `SetupController` est déduite du code, pas observée.
- Les deux extras que le README §8.3 déclare non couverts (documents de section, groupes de discussion) : rien à confronter.
- La rejouabilité **sans** `--reset` est un refus propre par construction (README §8) ; une seconde construction ne se teste que par `--reset`, et c'est là que #212 apparaît.
- Trois écarts de documentation, notés ici plutôt qu'en issue : la réservation « à cheval sur aujourd'hui » du README §8.3 s'est terminée le 4 septembre (dates figées, calendrier qui avance) ; le docblock de `DeskImportReplay` dit encore que `DeskImportService::import()` supprime le fichier ; le §1 du présent chantier parle d'un poor man's cron qui n'existe plus.

### Itération 2 — La portée objet, au-delà du `role_min` — 2026-09-07

**Périmètre parcouru** : les 294 routes portant un paramètre (sur 650
déclarées dans `public/index.php` et les 22 `module.json`), lues contrôleur
par contrôleur jusqu'au service qui résout l'objet — 40 du cœur, 83 de
`finance`/`registration`/`rental`/`fees`/`attestations`, 171 des autres
modules — pour répondre à une question par route : après le garde de rôle,
quelle méthode confronte l'objet à l'appelant, et répond-elle pareil à
« interdit » et à « inexistant ». Puis, sur l'instance de l'itération 1
(le réglage `current_scout_year_id` retiré pour retrouver l'année
date-calculée que le README promet — voir #212), avec les six comptes du
README §10 plus deux animateurs d'autres sections dotés d'un mot de passe
comme `DemoAccounts` le fait : chaque candidat rejoué en `curl`, GET et
POST, avec le code HTTP obtenu.

**Issues ouvertes** :
- #218 — envois groupés : tout animateur lit, réécrit et fait changer d'état le brouillon d'une autre section (la règle de section ne porte que sur la liste choisie à la création) ;
- #219 — deux routes répondent 403 sur un objet existant et 404 sinon : les mouvements financiers d'un compte invisible (`MovementController`, et `ReceiptController` par lecture) et les réponses de formulaire, sans être connecté (`FormController::editResponse()`) ;
- #220 — le scanner de billets ignore `response_role_min` : un `chief` lit les inscrits d'un formulaire réservé au chef d'unité ;
- #221 — le décalage d'année scoute d'un animé se modifie depuis n'importe quelle section, alors que la grille « Départs » refuse le même compte sur le même membre ;
- #222 — un intendant ne peut jamais être trésorier : le badge s'attribue, mais `TreasurerScopeService` exige une fonction `chief`/`admin`, et le compte de section reste invisible.

**Vérifié et tenu** :
- `MemberService::canAccess()` sur `/members/{id}` : soi-même et les membres du même foyer (le parent ouvre ses trois enfants), 403 pour un autre membre **et** pour un id inexistant ; tout `chief`/`admin` voit tout membre, ce qui est la règle documentée (§8.14) — le manque n'est pas là mais sur l'écriture (#221).
- Les adresses secondaires (`/members/{id}/emails/*`, `requireOwnMemberId()` sans contournement), les documents de membre (`/admin/members/{id}/documents/{document_id}/renvoyer`, égalité du membre), les notes (`requireOwnNote()`), les documents de section (`staffsEverySection()`), les notifications (compte propriétaire) : chaque objet imbriqué est rattaché à son parent — par lecture pour ceux que le jeu de données ne contient pas (aucun `member_emails`, `member_documents`, `section_documents`, `notifications` semé).
- `FileAccessGuard::check()` : 403 uniforme pour « refusé » et « inexistant » ; l'accès à l'audit (`/api/audit/{type}/{id}`) répond 403 dans tous les cas, avec son commentaire qui énonce la règle que #219 viole ailleurs.
- La partition des comptes financiers : l'intendant sans badge ne voit que les comptes d'unité, l'animateur trésorier de Waingunga voit son compte et ceux de l'unité, `?account_id=` d'un autre compte retombe sur un compte visible sur les huit pages `/finance/*` ; `CampaignService::requireCampaign()`, `ReceivableAllocationService::requirePair()` (transaction et créance sur le même compte), `ReceiptController::changeAccount()` (404 sur le compte cible) tiennent.
- Les Départs : `POST /departs/{member_year_id}` refuse l'animé d'une autre section (« Cette section n'est pas la vôtre ») et accepte celui de la sienne ; 403 aussi pour un id inexistant.
- Les jetons : `/inscriptions/suivi/{id}/{token}` (bcrypt, `password_verify`), `/locations/suivi/{id}/{token}` (64 hex, `hash_equals`), `/finance/qr/{id}/{token}` et `/news/qr/{reference}/{token}` (HMAC, `hash_equals`) : 404 uniforme pour un mauvais jeton comme pour un id inconnu, l'identifiant seul n'ouvre rien. Le jeton QR des créances est dérivé, sans expiration — révocable par rotation de clé seulement, ce que son en-tête dit.
- Les locations : `/mes-locations/{slug}*` répond 404 (jamais 403) à qui ne gère pas le bien, animé et parent compris, y compris sur les réglages et une réservation ; `bookingOfAsset()` rattache la réservation au bien. Le suivi par jeton ne dépend pas de l'id.
- Les groupes de discussion : 404 pour un non-membre sur les 40 routes, 403 pour un membre non modérateur, chaque message et réponse rattaché à son groupe — par lecture seule, le jeu de données ne contenant aucun groupe (README §8.3).
- Les actualités : `/news/{id}` refuse selon la visibilité (l'animé, l'intendant et le parent reçoivent 403 sur l'article `chief`) ; `/news/{id}/gerer` refuse l'animateur qui n'est pas l'auteur ; `loadResponseContext()` rattache la réponse à l'article (pas de réponse d'un autre article) ; `/news/{id}/form/responses` applique `response_role_min` (403 pour l'intendant et l'animateur sur le formulaire réservé au chef d'unité).
- La galerie : un album de section créé par un animateur est lu par les animateurs des autres sections (200) et refusé à ses animés/parents d'une autre section (403) ; l'édition ne s'ouvre qu'à sa section (403 ailleurs). Lecture large, écriture étroite — cohérent avec la règle des membres.
- Les camps : tout animateur voit tout séjour et tout lieu — écrit trois fois dans le module comme voulu (`CampAlbumAccessChecker`, `CampsMessageConsumer`, `schema.sql`).
- Les demandes d'inscription, factures de cotisation, lots d'attestations, propositions de doublons, rapports d'import : objets d'unité derrière `admin`, sans second contrôle et sans en avoir besoin ; `/inscriptions/suivi/demande/{id}` refuse un compte non lié (403, identique pour un id inconnu).
- Deux remarques notées sans issue, faute de conséquence au-delà d'un rôle déjà unitaire : `GET /api/maintenance/reset-status/{id}` rend le statut et le `last_error` de n'importe quelle tâche planifiée à tout `admin` (`SupportController::packageStatus()` filtre, lui, sur sa `task_key`) ; `POST /passage/membre/{id}/note` accepte tout entier positif sans vérifier le membre.

**Non vérifiable, et pourquoi** :
- `CampaignController::waive()` accepte une créance d'une autre campagne du même compte visible (lecture) : une seule campagne dans le jeu de données.
- `ReceiptController::requireVisibleAttachment()` (le second endroit de #219) : aucun reçu sur un compte invisible ; le mécanisme est le même que celui des mouvements, exercé.
- `FileAccessGuard` et l'année effective : les identifiants de membre liés sont rebâtis pour l'année effective alors que `files.owner_member_id` est un `members.id` persistant (lecture de `public/index.php`) — un membre sans ligne dans l'année effective, ou une session en aperçu d'année, perdrait l'accès à ses propres documents ; aucun fichier à propriétaire dans le jeu de données (`owner_member_id` toujours nul). À reprendre en itération 6.
- `MassMailService::sendTestEmail()`, `resendToRecipient()`, `removeAttachment()` : même forme que #218, non exercés pour ne pas envoyer de courrier ni téléverser de pièce jointe.
- Les listeners d'import Desk et les groupes : hors jeu de données, lecture seule.
