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

### Itération 3 — Les données personnelles sortent-elles du Repository ? — 2026-09-07

**Périmètre parcouru** : les 466 appels à `JournalService::log()` de `core/`,
`modules/` et `public/`, chacun lu pour sa description et son contexte ; les
onze appels à `NotificationService::dispatch()` et ce qu'ils mettent dans le
titre, le corps et l'URL ; chaque `->getMessage()` qui atteint une page, un
flash, une réponse JSON ou une colonne stockée sans passer par
`UserFacingMessage::from()` ; les appels à `EncryptionService::decrypt()` hors
d'un `Repository` et les boucles « déchiffrer toute la table pour comparer » ;
les dix-huit exports (XLSX, PDF, ICS, sauvegardes, paquet de support, extrait
de triage) — qui les demande, ce qu'ils portent, où ils atterrissent ;
l'anonymiseur de l'extrait de triage (`TriageExtractScrubber`). Puis, sur
l'instance de l'itération 1 : un album de section et une activité du
calendrier des animateurs créés par l'interface, un changement de paramètre,
neuf exports téléchargés et ouverts, un envoi de test et un envoi groupé
lancés contre un transport de courrier cassé (`sendmail_path=/bin/false`), une
passe de cron.

**Issues ouvertes** :
- #223 — un album de section et une activité du calendrier `chief` sont annoncés par notification aux 159 comptes de l'unité, y compris à ceux à qui la page répond 403 (galerie et calendrier : « every identified member », écrit dans les deux services) ;
- #225 — `setting_changed` recopie l'ancienne et la nouvelle valeur de tout paramètre, adresses comprises ; `section_info_updated` fait pareil ; quinze appels dupliquent l'adresse IP dans le contexte alors que `event_log.ip_address` la porte déjà ;
- #226 — le texte brut de PHPMailer atteint la page du superadmin (test de gabarit) et les contextes `mail_error`/`error` de six appelants, alors que `MailService` expurge sa propre entrée puis relance l'exception non expurgée (variante « adresse du destinataire » non reproduite : STARTTLS forcé, relais factice impossible) ;
- #227 — le PDF du trombinoscope reste sur le disque sans conservation, hors `files`, en `0755`, sans journal — l'inverse de la liste de section.

**Vérifié et tenu** :
- Le journal de l'instance après trois constructions, deux passes de cron et une centaine d'actions : uniquement des identifiants dans les contextes (`member_id`, `request_id`, `booking_id`, `email_id`…), aucune adresse ni nom ; `MailService::journalFailure()` expurge (`mail_send_failed` porte « Could not instantiate mail function. » et rien d'autre) ; `AuthService`, `MemberEmailService`, `RedirectService` remplacent l'adresse par `[adresse]` ; `NotificationService` journalise `type_id` seul ; `UploadController` ne reçoit que des compteurs. Les titres d'articles et d'évènements dans les descriptions (`article_created`, `event_created`) sont du texte libre d'animateur, pas une donnée personnelle par construction — noté, pas ouvert.
- Les notifications sont chiffrées au repos (`notifications.title`/`body`) ; le mode discrétion remplace titre et corps dans le push et le mail, comme §8.24 le dit — l'URL, elle, part telle quelle (`/members/{member_year_id}/emails/{id}`), un identifiant. Les charges lues : excerpt d'un message de groupe (aux membres du groupe), montant dû par enfant nommé (à la famille concernée), sujet d'un envoi groupé, titre d'album, titre d'activité, référence de réservation ou de ticket. Rien qui sorte du cercle des destinataires légitimes, hormis #223.
- Les exceptions : `SchedulerRunner` (`last_error`), `CreateBackupHandler` et `MaintenanceController` (`backups.error_message`), `MassMailController::testSend()`, `Connection::describeConnectionFailure()`, `RentalBookingService` (clé dupliquée → phrase française fixe), `ImportService::verifyIban()` (quatre derniers chiffres seulement) passent par le garde ou composent leur phrase ; `ConstraintViolation` traduit les codes MySQL en 400/409 sans la valeur. `inbound_mailboxes.last_error` est écrit mais jamais rendu. Le blanchiment `throw new X($e->getMessage(), 0, $e)` subsiste dans `FakeMailboxClient` (doublon d'essai livré sous `src/`), les quatre processeurs d'image, `UploadHandler`, deux endroits de `RentalManagementController`, `DocumentService` et `CampAlbumService` — avec une source déjà française dans chaque cas ; noté, pas ouvert.
- Les exports : toutes les cellules XLSX sont écrites en `TYPE_STRING` explicite (`TabularSpreadsheet`, `MemberExportService`, `CampaignExportService`, `MovementController`, `FeeAccuracyController`, `InvoiceReportController`, `FormController`), `setPreCalculateFormulas(false)` ; les deux seuls `setCellValue()` non typés sont la formule « montant dû » composée de lettres de colonnes et le montant reçu — l'injection de formule (`SECURITY.md` §23) tient. Les XLSX sont des fichiers temporaires supprimés après envoi ; le paquet de support est chiffré, `superadmin`, purgé à sept jours ; les attestations sont chiffrées et rattachées à leur membre ; la liste de section vit sept jours en `0700`. Les flux ICS et les suivis par jeton ne portent aucun participant. Les exports sont journalisés en compteurs, sauf le trombinoscope (#227), la campagne, ses étiquettes et le tableau de bord de support.
- Qui exporte quoi : l'intendant (`/chefs/membres/export`, `role_min: intendant`) obtient les 178 membres de toute l'unité — prénom, nom, totem, naissance, e-mails, téléphones, adresse, assurance complémentaire — sans le `handicap` (réservé à `admin` par `MemberExportColumns::forRole()`) ni le motif de départ (`chief`). C'est le périmètre que la page `/chefs/membres` lui donne déjà ; la question de savoir si un intendant de section doit voir l'unité entière relève de l'itération 2 et n'a pas été ouverte ici, la matrice d'autorisation déclarant ce `role_min` en connaissance de cause.
- L'extrait de triage : e-mails, IPv4/IPv6, hexadécimaux longs, identifiants de personne dans les chemins et les clés `*_id`, valeurs de requête → jetons ou `…` ; `phpinfo.html` et `configuration-parameters.xlsx` exclus ; les champs libres du ticket jamais transmis. Ce qui traverse : le texte libre des descriptions et des contextes (titre d'évènement, `answer` de `camps_place_name_read`), les noms de fichiers de `logs/` et `webserver/` (un chemin d'hébergement mutualisé porte le nom du compte), les références de réservation. Sur cette instance aucun nom de personne n'y figure ; zone §0.2 bis (site de support), lu, non exercé — noté.
- Le déchiffrement hors Repository : `MemberService`, `SectionService`, `SectionRosterService`, `MemberExportRowBuilder`, `ForecastService`, `SlotService`, `PassageService`, `ReenrollmentRecipientService` et `ExternalMailingListService` déchiffrent dans un Service, les cinq derniers une année entière, les deux derniers avec du SQL dans le Service ; `ReconciliationService` et `MemberSearchService` sont documentés et bornés (`ARCHITECTURE.md`), `ReenrollmentRecipientService::pendingFamilies()` regroupe par e-mail déchiffré alors que `email_blind_index` existe. Règle d'`AGENTS.md` non tenue, sans conséquence visible sur une unité de 178 membres : à l'itération 7 de mesurer, pas à celle-ci d'ouvrir.

**Non vérifiable, et pourquoi** :
- La variante « adresse du destinataire dans `mail_error` » de #226 : elle dépend du texte que PHPMailer compose en SMTP ; `MailService` impose STARTTLS et l'authentification, ce qu'aucun relais factice ne sert ici. Zone §0.2 bis.
- L'URL d'une notification poussée sans discrétion vers un vrai endpoint Web Push : aucun abonnement sur l'instance. Zone §0.2 bis.
- `llm_request_failed` (`error_detail` = corps d'erreur du fournisseur, pour des prompts qui portent libellés et contreparties bancaires) : aucun fournisseur configuré. Zone §0.2 bis, lu.
- Les groupes de discussion (excerpts dans les notifications) : aucun groupe dans le jeu de données.
- `FormController::createMailDraft()` attrape `\Throwable` et affiche `getMessage()` : aucun échec provoqué ; le chemin est lu, pas exercé.
- `SetupController::installDatabase()` renvoie l'instruction SQL complète dans ses avertissements : réservé à l'assistant d'installation, non rejoué.

### Itération 4 — Les entrées non fiables — 2026-09-07

**Périmètre parcouru** : ce que le code fait de ce qu'il n'a pas écrit —
fichier téléversé, HTML riche, CSV importé, mail entrant, en-tête de mail
sortant, URL distante. Deux relevés de lecture d'abord : les 306 `|raw`
des gabarits (`core/View/templates` + `modules/*/views`), triés jusqu'à
n'en garder que ceux qui portent une valeur non fixe ; et les 21 chemins
de téléversement du dépôt, avec pour chacun qui décide du type MIME. Puis,
sur l'instance de l'itération 1, la reproduction des candidats : les
téléversements de la galerie (`POST /gallery/{id}/media`) et des actualités
(`POST /news/images/upload`), le scraper Open Graph des albums externes,
chaque usage de `HTTP_HOST`, le contenu éditable, le champ de texte riche
en cas d'échec de validation, un CSV Desk hostile, le parseur MIME du
courrier entrant (en direct puis derrière `FakeMailboxClient`), et le
consommateur des locations contre le dépôt réel de l'instance.

**Issues ouvertes** :
- #228 — courrier entrant : un mot encodé au charset inconnu lève une `ValueError` (qui étend `\Error`) depuis `MimeMessageParser::toUtf8()`, non rattrapée par la clause `MailboxConnectionException | \RuntimeException` de la synchronisation ; un seul message bloque la boîte pour toujours, le curseur n'avançant jamais (§0.2 bis, section « Non reproduit ») ;
- #229 — contenu éditable : `POST /api/rich-text-content` avec `type=image` stocke du HTML sans passage par `HtmlSanitizer` (seul `rich_text` est nettoyé), et la lecture le ressort en `|raw` sur des pages publiques ;
- #230 — le partiel de texte riche ré-affiche la valeur POST brute en `|raw` quand la validation échoue, dans les camps (champ `note`) et les envois groupés (champ `body_html`) — reflet gardé par un jeton CSRF ;
- #231 — locations : une référence `[LOC-AAAA-NNNN]` dans le sujet lie un message d'un expéditeur quelconque à la réservation, sans vérifier l'expéditeur (§0.2 bis, section « Non reproduit »).

**Vérifié et tenu** :
- Le type MIME du chemin principal (`Core\File\UploadHandler::handle()`) est lu par le contenu (`finfo`), jamais dans `$_FILES['type']` ; l'extension vient d'un `match()` fermé dont le défaut est `bin`. Reproduit : `POST /gallery/2/media` et `POST /news/images/upload` refusent un PHP déguisé en `.jpg`, un SVG et un GIF polyglotte (garde de mégapixels), et stockent un vrai JPEG renommé `.php` sous un nom aléatoire `.jpg`. Le nom du client n'atteint jamais le disque.
- Les trois gardiens secondaires que `tests/Security/UploadMimeTrustAuditTest.php` surveille (documents de section, reçus et mouvements financiers) reniflent avec `finfo` et résolvent un échec de détection vers une sentinelle hors liste blanche (`'application/octet-stream'`), jamais vers le type annoncé par le client.
- `image/svg+xml` n'est accepté par aucune liste blanche du dépôt ; la classe XSS-par-SVG est fermée par construction.
- Le scraper Open Graph (`OgScraperService`, derrière `SsrfUrlValidator` §17) tient : cinq albums externes créés vers `http://127.0.0.1`, `http://localtest.me`, `http://169.254.169.254`, `http://[::1]` et `http://0x7f000001` sont tous enregistrés **sans** vignette OG, et le journal du serveur ne montre aucune requête sortante vers ces cibles.
- `HTTP_HOST` ne sert qu'à `SetupController::resolveDefaultBaseUrl()` (l'URL par défaut proposée à l'installation), jamais à fabriquer une URL que le serveur s'appelle lui-même.
- Injection de formule dans les exports : `Core\Export\TabularSpreadsheet` écrit chaque cellule en `TYPE_STRING` ; un `=`, `+`, `-` ou `@` initial ne s'exécute pas. Les deux blocs `<script type="application/json">` sans `JSON_HEX_TAG` (`modules/fees/views/accuracy.html.twig:143`, `modules/camps/views/camp_form.html.twig:148`) ne cassent pas non plus : un nom hostile ressort `Vincent Dewulf<\/script><b id=pwn>` — `json_encode` échappe `/` en `\/` par défaut, donc l'analyseur HTML ne voit pas `</script>` et le `<b>` reste du texte JSON, non un élément. Constat non reproduit, donc pas d'issue.
- Le HTML entrant est nettoyé une fois à l'arrivée (`MailboxSyncService::store()` → `MessageContentSanitizer` puis `HtmlSanitizer`) et c'est la forme nettoyée qui est stockée ; il est ensuite rendu `|raw` en ligne (`message_text.html.twig:6`), conséquence assumée de SECURITY.md §33.
- En-têtes de mail sortant : tout envoi passe par `Core\Mail\MailService::send()` ; il n'existe aucun appel `mail()` ni bloc d'en-têtes construit à la main. La neutralisation CR/LF vit dans PHPMailer 7.1.1 vendé — `secureHeader()` sur le `Subject`, `validateAddress()` sur les adresses (avec `new PHPMailer(true)`), `addCustomHeader()` qui refuse une nouvelle ligne. Un nom de locataire saisi sur le formulaire public voyage donc intact jusqu'à `$mail->Subject` et n'est neutralisé qu'au moment de l'émission des en-têtes, par le vendu — non par `MailService`.
- `Content-Disposition` : `FileController::serve()` échappe les guillemets (`addslashes`) ; un CR/LF venu d'un `filename*` de pièce jointe entrante est bloqué par `header()` de PHP lui-même (l'en-tête est alors simplement omis pour ce fichier — sans danger, `X-Content-Type-Options: nosniff` étant posé partout).
- La seule extraction de ZIP (`BackupService::restoreFiles()`, superadmin) est précédée de `assertArchiveEntriesAreSafe()` : chemins absolus, `..`, liens symboliques et total décompressé au-delà de 4 Gio rejetés.
- Un CSV Desk hostile (nom de famille portant `</script><b id=pwn>`) importé par `POST /admin/import` : l'import réussit (178 membres), et la valeur ressort échappée partout où elle est rendue — le nom n'atteint aucun contexte `|raw`. Roster restauré ensuite depuis le CSV de référence.

**Non vérifiable, ou lu sans reproduire l'effet** :
- Deux téléversements financiers n'ont ni plafond de taille ni contrôle de type par le contenu : l'import de relevé bancaire (`modules/finance/src/Controller/ImportController.php::upload()` passe `$file['tmp_name']` directement au parseur) et le XLSX de campagne (`CampaignService::createFromFile()` **affirme** `XLSX_MIME` au lieu de le détecter, et lit les styles avec `setReadDataOnly(false)`). Montrer un `mime_type` stocké faux exigerait un fichier non-xlsx que `XlsxReader::load()` accepte, et un déni de service exigerait un fichier de 48 Mo à styles lourds : ni l'un ni l'autre produit ici, donc pas d'issue — noté par lecture seule.
- Le courrier entrant n'a pas de borne sur le nombre de pièces jointes par message ni sur la taille totale d'un message récupéré (`BATCH_SIZE = 50` messages entiers en mémoire ; les plafonds de corps s'appliquent après que la chaîne existe). L'effet demande un vrai message surdimensionné — non produit.
- Les reçus financiers conservent leur EXIF (dont un éventuel GPS) tant qu'aucune rotation n'est nécessaire (`ReceiptService::correctOrientation()`, lignes 513-515) ; les vidéos de la galerie ne sont jamais ré-encodées au téléversement. Compromis énoncés dans le code, chiffrés au repos — lus, pas rouverts.
- L'écriture disque des pièces jointes par `RentalDocumentService` dans `onLinked()` (#231) : seule la décision de liaison a été rejouée, le classement ne l'a pas été.

### Itération 5 — Le contrat des modules — 2026-09-07

**Périmètre parcouru** : les 22 `module.json` et leurs 22 `schema.sql`, le
câblage optionnel de la racine de composition (`public/index.php`, ~7 100
lignes) et son pendant partagé `public/scheduler-bootstrap.php`, les deux
points d'entrée du planificateur (`index.php` et `cron.php`), le registre
des cookies (`core/Cookie/CookieRegistry.php`) confronté aux `setcookie()`,
`cache.put()` et `localStorage.setItem()` réels, et l'historique git de
chaque `schema.sql` face au `version` de son manifeste. Trois axes
reproduits sur l'instance de l'itération 1 : l'ordonnancement des tâches
`rental` par le chemin du cron, la liste de stockage rendue par `GET
/cookies`, et le versionnage installé du registre des modules.

**Issues ouvertes** :
- #232 — la tâche `expire_rental_holds` n'est amorcée que par `public/index.php:6553`, absente de `cron.php` et de la racine partagée où ses deux sœurs de location sont amorcées : un site en vrai crontab ne l'ordonnance jamais ;
- #233 — la liste de stockage local que `GET /cookies` déclare exhaustive omet le cache `offline-config` (`sw.js:204`) et les brouillons `localStorage` de `groups` (`groups.js:349,1303`), là où le registre déclare pourtant leurs jumelles `app-shell`/`content` ;
- #234 — `groups` écrit ses brouillons en `localStorage` sans vérifier le consentement fonctionnel, contrairement à `camps-map.js` qui lit `cookie_consent` avant d'écrire (`AGENTS.md:197`).

**Vérifié et tenu** :
- **La règle du bump de version est retirée**, et c'est écrit (`AGENTS.md`, § Database) : le schéma déclaré entier (`schema/core.sql` plus chaque `modules/*/schema.sql`) est migré en un seul jeu au déploiement par `Core\Database\SchemaFiles` + `MigrationRunner`, sur empreinte de contenu et non sur `version`. L'activation d'un module ne fait plus de DDL (`ModuleManager::activate()` est purement comptable) ; le `version_compare` restant ne pilote que l'élagage des settings qu'un manifeste cesse de déclarer. Les deux `schema.sql` modifiés sans bump — `inbound_mail` (`stored_analysis_attempts`, table `inbound_message_dismissals`) et `support_dashboard` (colonnes `whois_*`) — auraient cassé sous l'ancienne règle (`Unknown column` sur une instance déjà activée) ; ils sont inoffensifs sous le chemin actuel. Pas d'issue : le défaut de cette liste que le chantier redoutait est fermé par construction.
- Le `installed_version` du registre (`module_registry`) correspond au `version` du manifeste pour les 22 modules sur une instance neuve — aucune dérive.
- Deux tranchants documentés et acceptés (`AGENTS.md`), dont la justification tient encore : les index sont appariés par nom seul (changer les colonnes d'un index existant dans `schema.sql` est un no-op silencieux — le redéclarer sous un nouveau nom) ; une table de module ne doit jamais porter de clé étrangère vers la table d'un autre module (`ModuleSchemaBoundariesTest` l'impose).
- Les tâches planifiées : 18 du cœur (`CoreTaskHandlers::all()`, dont `create_backup`), 48 de modules (résolues automatiquement depuis les `module.json`) et 3 par fabrique (`generate_rgpd_content`, `sync_mailboxes`, `analyze_stored_messages`) sont enregistrées **à l'identique dans les deux points d'entrée** via la racine partagée `public/scheduler-bootstrap.php` ; la parité est tenue par `tests/Core/Scheduler/SchedulerBootstrapTest.php`. Les 48 gestionnaires déclarés existent tous. La seule asymétrie est l'**amorce** de `expire_rental_holds` (#232), que le test de parité ne voit pas car il interdit les appels de style `->registerHandler(` mais pas un `Handler::bootstrap(...)` statique.
- Les descriptions de `settings` : chaque setting déclaré dans les 15 modules qui en portent, et chaque `settingService->register(...)` du cœur (~65 dans `index.php`, plus les enregistrements internes), a une `description` présente et non vide (le paramètre est un `string` non nullable) — rien à signaler.
- Les dépendances optionnelles inter-modules : la racine de composition n'instancie chaque implémentation fournisseur qu'à l'intérieur de sa garde `$isEnabled(...)`, et l'expose aux consommateurs par un relais initialisé à `null` (`…ForOthers`) ; aucun service optionnel n'est instancié ni passé sans garde. Chaque consommateur déréférence sa dépendance nullable derrière une garde (`isAvailable()` ou un retour anticipé `=== null`) — vérifié sur `retro`, `groups`, `finance`, `sos_staff`, `registration`, `news`. Le site dégrade donc vraiment quand un fournisseur est absent.

**Noté sans issue** :
- Le cookie `_csrf_token` est **déclaré** dans le registre (catégorie `necessary`) mais n'est jamais posé comme cookie : il vit dans la session serveur (`PHPSESSID`). Sur-déclaration inexacte, sans conséquence observable.
- Deux entrées `sessionStorage` (`scoutmagic-offline-prefetch` dans `offline-prefetch.js`, `scoutmagic:help-assistant:question` dans `help-search.js`) ne sont pas déclarées ; le registre ne déclare aucun `sessionStorage` et ces entrées sont éphémères, à la portée de l'onglet comme `PHPSESSID` — question de complétude laissée au triage plutôt qu'un défaut.
- Trois consommateurs inter-modules typent leur dépendance `Api\` en **non-nullable** (`RentalAttentionProvider`, `FinanceAttentionProvider`, `RentalMessageConsumer` → `InboundMailInterface` ; `SosVirtualEventProvider` → `CalendarDirectoryInterface`), contrairement à la lettre de §7.5 qui veut un type nullable. Aucun défaut à l'exécution — chacun n'est construit que dans une garde non-null — mais un refactor qui sortirait l'une de ces constructions de sa garde échouerait sèchement au lieu de dégrader. Préférence d'architecture sans conséquence observable aujourd'hui.

### Itération 6 — L'année scoute effective — 2026-09-07

**Périmètre parcouru** : toute requête portant — ou omettant — un
`scout_year_id`. Les tables réellement porteuses de la colonne (neuf du
cœur, une quinzaine des modules), leurs méthodes de Repository ; chaque
appelant de `ScoutYearResolver`, trié entre `getEffectiveYear()`,
`getCurrentPublicYear()` et le `getCurrentYear()` date-calculé ; les
écritures faites sous aperçu ; les gestionnaires de tâches de fond, qui
n'ont pas de session ; et les lectures de dates qui devinent une première
année d'animation. Reproduit sur l'instance à trois années de l'itération 1
(1 = 2024-2025, 2 = 2025-2026, 3 = 2026-2027 publique, 4/5 vides), l'aperçu
d'année étant piloté par `POST /admin/scout-year/preview`.

**Issues ouvertes** :
- #235 — galerie : la création d'un album fixe l'année via `getCurrentYear()`, ignorant l'aperçu du chef et la date de l'album ; reproduit — un album daté de novembre 2024, créé en aperçu 2024-2025, atterrit en 2026-2027 ;
- #236 — le rappel d'évènement multi-jours résout ses destinataires sur l'année date-calculée : après la bascule du 1er septembre, avant l'import du nouveau roster, `getSectionStaff()` rend une liste vide et le rappel est abandonné en silence ;
- #237 — documents de section : l'année cible de l'écriture vient du corps de la requête, validée pour la section mais jamais pour l'année ; reproduit — un chef écrit dans une année que le formulaire n'offre pas ;
- #238 — trois lectures (réconciliation financière, chef d'unité de la rétro, manifeste hors-ligne) résolvent `getCurrentYear()` au lieu de l'année effective — même erreur, trois modules, regroupée.

**Vérifié et tenu** :
- Le dépôt est inhabituellement discipliné ici : la plupart des méthodes « sans année » portent une docstring qui énonce le comportement inter-années voulu. Les tables sans `scout_year_id` le disent en clair (`camp_camps`/`camp_places`, `push_subscriptions`, `sos_oncall_assignments`, `mass_mail_emails` dont l'année vit dans la table de jonction) — leurs méthodes sans année ne sont pas des constats.
- Les deux seules méthodes de lecture sans année sur une table qui en porte une sont sans conséquence : `GroupRepository::findAll()` (les appelants filtrent par section+année, docstring à l'appui) et `CampaignRepository::findAll()` (aucun appelant de production ; la liste vivante passe par `findByScoutYear()`). Notées, pas d'issue.
- Les chemins de requête principaux résolvent bien `getEffectiveYear()` : `MemberSearchController`, `StaffsController`, `SectionRosterController`, `FunctionsController`, la vue famille de `finance`, `fees`, `member_stats`, `retro` (le tableau lui-même), `leadership`, `camps`, `groups`, `calendar` (écran chef), `sos_staff`, `trombinoscope`.
- Les usages de l'année publique qui sont voulus et documentés tiennent : la résolution de rôle à la connexion (`AuthController`), l'import Desk (`RosterReplacementGuard`), tout le module `registration` (passage et départs = année publique + `nextLabel`, exception écrite en toutes lettres), le site public (`PageController`, `CalendarPublicController`).
- **Le cas connu tient** : `member_functions.start_date` n'est pas utilisé pour détecter une première année d'animation. `LeadershipRepository::findMemberIdsWithAnimationFunction()` s'appuie sur `members.id` et les fonctions de l'année précédente, sa docstring rappelant explicitement la remise à zéro ; « l'an dernier chez les Pionniers » passe par `member_section_periods` (qui survit au changement de section et à la réécriture des fonctions). Le seul usage vivant de `start_date` est le compte à rebours de mandat d'un intendant (`StewardService::resolveStart()`), étiqueté « Début de fonction encodé dans Desk », avec repli sur `member_section_periods`, et n'est pas une inférence d'ancienneté.
- Les recherches inter-années « dernière connue » de `MemberYearRepository` (`findMostRecentForMember`, `findMostRecentNamesForMembers`, `inheritedScoutYearOffset` — cette dernière ordonnant par `scout_years.start_date`, chronologie immuable et non date de fonction remise à zéro) sont voulues et documentées.

**Noté sans conséquence reproduite** :
- L'aperçu d'année est réservé à `admin` par la route (`POST /admin/scout-year/preview`), bien que `ScoutYearResolver::getEffectiveYear()` accepte le rôle `chief` : un chef non-admin ne peut pas déclencher l'aperçu par l'interface. La fuite d'écriture #235 se reproduit donc via un superadmin (qui a l'accès `chief`), ce qui est le chemin réel.

### Itération 7 — Les requêtes non bornées — 2026-09-07

**Périmètre parcouru** : ce qui grandit avec le nombre de membres, d'années,
d'articles, de réponses de formulaire ou de fichiers. Le recensement des
`findAll()` et des lectures de liste sans `LIMIT`, des index déclarés face
aux colonnes réellement filtrées (`scout_year_id`, index aveugles, clés
étrangères), des boucles émettant une requête par tour (N+1), et des
déchiffrements en masse pour un compteur. Mesuré sur l'instance à trois
années avec le compteur d'instructions SQL de `scripts/perf/bench-pages.php`
(`SHOW GLOBAL STATUS 'Questions'`) et `EXPLAIN`. Frontière tenue avec
`CHANTIER-performance.md` : les pages à l'échelle des membres qu'il a déjà
mesurées et traitées (`/admin/members`, `/chiefs/stats`) ne sont pas
rouvertes.

**Issues ouvertes** (chacune avec sa mesure) :
- #239 — `news_form_responses.structured_communication` sans index : `EXPLAIN` donne `type=ALL, key=NULL`, un balayage complet de toute la table à chaque scan d'un QR de paiement EPC, quand la colonne sœur `ticket_reference` est indexée ;
- #240 — la page des réponses d'un formulaire charge tout sans pagination et déchiffre chaque réponse par une requête à part : 42 requêtes SQL pour 6 réponses contre 40 pour 4, pente +1 requête/réponse ;
- #241 — la capacité restante d'un champ est recalculée à chaque affichage en déchiffrant toute la colonne des réponses (`sumFieldValues`), alors que le total est déjà calculé sous verrou à l'écriture.

**Vérifié et tenu** :
- Le schéma est globalement bien indexé : chaque filtre `scout_year_id`/`member_id`/`section_id` sur les tables de membres qui grossissent est couvert (`member_years.idx_my_year_active`, `member_functions.idx_mf_*`, `member_section_periods.idx_msp_*`) ; `event_log` porte `idx_logged_at`/`category`/`level`/`user`/`ip` ; `notifications` porte `idx_notif_user_unread` ; presque chaque colonne `*_blind_index` a son propre index. InnoDB indexe d'office chaque colonne de clé étrangère.
- `JournalRepository::search` est paginé (`LIMIT`/`OFFSET` sur `event_log`) et son total passe par `COUNT(*)` ; `ArticleRepository` utilise `findByVisibilitiesPage` (avec `LIMIT`) sur les chemins de requête — son `findAll()` non borné n'a aucun appelant vivant ; les lectures de messages entrants portent toutes un `LIMIT` ou filtrent par id.
- Les pages à l'échelle des membres qui déchiffrent tout le roster (`/admin/members`, le graphe de `member_stats`) sont déjà mesurées et tranchées par `CHANTIER-performance.md` (§2.2, §2.4, précaché) — non remesurées.

**Non reproduit, et pourquoi** :
- Le candidat le plus net par lecture — la page des réponses appelant `ExpectedReceivableService::getReceivableStatus()` par réponse payée, qui re-déchiffre **tous** les mouvements du compte à chaque fois (`ReceivableAllocationService.php:143-155`), alors qu'une méthode par lot (`getReceivableStatuses`) existe précisément pour l'éviter — n'est pas reproductible sur le jeu de référence : aucun formulaire n'a de `finance_account_id`, et aucune réponse ne porte de créance, donc la branche `buildReceivableStatus` ne s'exécute jamais. Constat lu, mesure impossible à l'échelle actuelle : versé au journal, pas ouvert.
- Les colonnes aveugles `inbound_messages.from_email_blind_index`/`in_reply_to_blind_index` sont déclarées sans index couvrant, mais rien ne filtre dessus aujourd'hui — latent, pas un balayage réel.
- Plusieurs `count($repo->findAll(...))` qui pourraient être un `COUNT(*)` (audience d'envoi groupé, demandes non finales, groupes archivés, aperçu de fusion de camps) portent sur de petites tables ou des chemins d'administration : notés, sans mesure de croissance significative.

### Itération 8 — Les tâches de fond — 2026-09-07

**Périmètre parcouru** : les 68 gestionnaires `Task\…Handler` du cœur et des
modules, `Core\Scheduler\TaskContext`, `public/scheduler-bootstrap.php` et le
marquage « fait » du runner. Chaque gestionnaire lu sous six angles :
rejouabilité (le runner ne marque « fait » qu'après le retour de `handle()`,
`SchedulerRunner.php:170-171` — d'où une fenêtre de rejeu sur arrêt brutal),
reprise après budget dépassé, visibilité de l'échec, défauts reconstruits hors
requête HTTP, avertissement cron sur les écrans de configuration, et
concurrence sur une même ressource.

**Issues ouvertes** :
- #246 — trois gestionnaires d'e-mails sans marqueur « déjà envoyé » (réinscription, rappel multi-jours, billets en attente) : un rejeu après arrêt brutal renvoie les mêmes courriels ;
- #247 — le rappel multi-jours journalise « envoyé » avant d'envoyer et avale l'échec de chaque destinataire : un échec total laisse une ligne « envoyé » sans trace d'erreur ;
- #248 — plusieurs fonctions horaires (redirection d'urgence SOS en tête, rappels calendrier, camps, digest news, statistiques) n'avertissent pas sur leur écran de configuration qu'un vrai crontab est nécessaire, là où `rental`/`inbound_mail`/`notifications` le font ;
- #249 — `GenerateImageVariantsHandler` traite toute la bibliothèque d'images en une passe non bornée, sans lot ni réarmement.

**Vérifié et tenu** :
- `TaskContext` ne porte ni `base_url`, ni horloge, ni année ; mais **aucun** gestionnaire ne lit `$_SERVER`/`HTTP_HOST` (grep propre), et toutes les URL viennent de `settings->get('base_url')` — correct hors requête. Le seul défaut d'année reconstruite est `MultidayEventReminderHandler` via `getCurrentYear()`, déjà ouvert (#236) ; tous les autres passent par `ScoutYearResolver::getCurrentPublicYear()`.
- L'idempotence tient partout ailleurs : le modèle `email_sent_at` de `SendNotificationEmailsHandler` (`NotificationRepository.php:188`, envoi seulement `WHERE email_sent_at IS NULL`), `PurgeOldMovementsHandler` (suppression + point de solde en une transaction, fichiers supprimés après le commit), `ReconcileReceivablesHandler` (« n'écrit que si le calcul diffère du stocké »), les transitions de `ReenrollmentCampaignHandler` (gardées par un marqueur `alreadyDone`), `ProcessPhotoHandler` (garde `status==='done'`), `SendBatchHandler` (succès enregistré après chaque envoi), et tous les gestionnaires de purge (suppression par date, rejouables).
- La reprise tient là où le volume l'exige : `SendNotifications`/`SendNotificationEmails` (budget de temps explicite + report des ids restants), `SendCertificates` (tranche + réarmement portant `batch_id`), `SendBatch` (curseur FIFO), les gestionnaires `inbound_mail` (lot borné + curseur), et `InstallUpdateHandler`/`RestoreBackupHandler` (reprise d'étape en étape). Seul `GenerateImageVariants` ne borne rien (#249).
- L'échec est visible partout ailleurs : le runner journalise toute exception *remontée* (`SchedulerRunner:200-206`), et les `catch` qui avalent portent presque tous un docstring justifiant le « best-effort » (envoi de mail, notification push, déplacement d'album). Seuls le `catch` vide du rappel multi-jours sous une ligne trompeuse (#247) et le `catch` de la réinscription (justifié, mais qui ne journalise qu'un compteur) sortent du lot.

**Noté sans issue** :
- Les opérations de maintenance destructrices (`RestoreBackup`, `FullReset`, `ResetSettings`, `AutoBackup`) ne partagent aucun verrou entre elles ni avec `InstallUpdate` (seul ce dernier prend `InstallLock`) : une sauvegarde automatique peut tourner pendant une installation et capturer un arbre à moitié écrit. Réservé au superadmin en pratique ; noté, un verrou de maintenance partagé serait le remède.
- Incohérence d'horloge : seul `SendCertificatesHandler` utilise `AppClock` ; les autres gestionnaires horaires lisent `new \DateTimeImmutable()` brut. Le fuseau du serveur est le même sur et hors requête, donc ce n'est pas un défaut de valeur hors requête — un écart de testabilité, pas un bug.

### Itération 9 — Les écritures partielles — 2026-09-07

**Périmètre parcouru** : tout ce qui écrit en base **et** sur disque, ou dans
deux tables sans transaction. L'atomicité du couple « blob chiffré + ligne
`files` » (`EncryptedFileStorageService`, `UploadHandler`, `FileRepository`)
et chacun de leurs appelants ; chaque `beginTransaction()` du cœur et des
modules ; les `catch` qui n'enregistrent ni ne relancent et les `@` ; le
nettoyage des fichiers temporaires ; les suppressions en cascade et leurs
orphelins ; l'avertissement de portabilité de la restauration.

**Issues ouvertes** :
- #242 — `FileRepository::delete()` n'efface que la ligne, jamais le fichier : les chemins de suppression et de purge de rétention (`inbound_mail`, `rental`) laissent le fichier sur le disque ; reproduit ;
- #243 — écriture fichier + ligne sans compensation : un échec de l'insertion laisse un blob orphelin dans `ReceiptService` (store/replace), `camps DocumentService`, `SectionDocumentService`, là où `CampaignService`/`DeskImport`/`BatchDeposit` nettoient déjà ;
- #244 — `SuppressedAddressRepository::suppress()` avale toute `PDOException` : une désinscription perdue passe inaperçue ;
- #245 — les écrans de sauvegarde et de restauration ne disent pas que l'archive exclut les clés de chiffrement, donc qu'elle n'est pas restaurable sur un autre hôte.

**Vérifié et tenu** :
- Les transactions : chaque `beginTransaction()` du cœur et des modules a son `commit()` sur le chemin de succès et son `rollBack()` sur le chemin d'exception (`MemberMergeService`, `DeskImportService`, finance `ImportService`/`CampaignService`/`AttachmentRepository`, `BatchDepositService`, camps `MergeService::transactionally`). Aucun `beginTransaction()` orphelin. Le patron est `try/catch`+relance, correct pour PDO.
- Le nettoyage des temporaires : chaque fichier temporaire portant des données personnelles disparaît « succès ou échec », en `finally` — le CSV Desk (`ImportController.php:298`), les relevés bancaires (`ImportService.php:195`, supprimé sur IBAN erroné comme sur succès, SECURITY.md §5), les pièces jointes entrantes, le paquet de support, l'archive de restauration.
- L'opérateur `@` : partout où il subsiste hors du nettoyage best-effort, il est sur une fonction de sonde/information (`disk_free_space`, `dns_get_record`, `set_time_limit`, `iconv`, `filemtime`, sondes shell/socket) — aucun ne masque une écriture de données.
- Les cascades de suppression membre/section/année : elles ne se déclenchent jamais parce qu'aucun chemin de suppression dure n'existe — `MemberMergeService` le dit (« Nothing in this codebase deletes a member », `:22-24`) et la fusion réaffecte `files.owner_member_id` au lieu de supprimer. Les reçus financiers sont archivés, jamais supprimés physiquement (blob conservé à dessein). La fusion de camps déplace les documents avant de supprimer le camp perdant.
- Le patron correct d'atomicité fichier existe et est suivi par trois chemins (`CampaignService::createFromFile` rollback + `fileStorage->delete`, `DeskImportService` rollback supprime le blob, `BatchDepositService` catch `discardStoredFiles`) — ce sont les quatre autres qui l'omettent (#243).

**Noté** :
- Les clés étrangères vers `files(id)` en `RESTRICT` (sans `ON DELETE`) protègent d'une référence pendante mais n'entraînent jamais la suppression du `files` ni du blob : l'accumulation n'est jamais ramassée (versé à #242).

### Itération 10 — Le navigateur — 2026-09-07

**Périmètre parcouru** : `public/assets/js/` (110 fichiers), `public/sw.js`
et les gabarits qui les câblent. Chaque écrivain de cache du service worker
et de `offline-prefetch.js` ; le retrait du consentement fonctionnel ; la
portée du cache par compte à l'expiration de session ; l'état au réveil de
l'application installée ; le double envoi de chaque bouton d'écriture ; les
contrôles désactivés côté client confrontés à leur route POST ; la
couverture de la nonce CSP. Reproduit ce qui l'était côté HTTP sur
l'instance ; le reste (course de cache, état après réveil — que ni CodeQL,
ni Vitest, ni Playwright ne regardent, comme le §10 le note) prouvé par
lecture avec le contraste de l'écrivain correct du même dépôt.

**Issues ouvertes** :
- #250 — `offline-prefetch.js:252` met en cache une réponse redirigée (garde `response.ok` seul, sans `!response.redirected`) : une session qui expire pendant le pré-téléchargement enregistre la page de connexion sous une URL de contenu ; reproduit au niveau HTTP (une page de liste blanche répond `302 → /login` suivi d'un `200`) ;
- #251 — la réponse à un formulaire public n'a ni bouton désactivé, ni redirection après POST, ni déduplication serveur : un double envoi crée deux réponses (reproduit) et, sur un formulaire payant, deux créances ;
- #252 — à l'expiration silencieuse de la session, le cache de contenu de l'ancien compte n'est jamais vidé (purge sur `form[action="/logout"]` seule) et un décalage d'une navigation peut servir ou écrire les pages d'un compte sous un autre.

**Vérifié et tenu** :
- La nonce CSP est étanche : `script-src 'self' 'nonce-…'` sans `'unsafe-inline'` ni `'unsafe-eval'` (`core/Http/Response.php:173-184`) ; dans une même requête, la nonce de l'en-tête correspond exactement à celle de chaque `<script>` exécutable en ligne (vérifié), et il n'existe aucun `<script>` exécutable sans nonce. Les deux îlots de données `<script type="application/json">` (`#offline-config-data`, `#help-search-index`) ne portent volontairement pas de nonce — non exécutés, donc hors de `script-src`. Aucun script n'est injecté dynamiquement (`createElement('script')`/`document.write` absents de `public/assets/js/`).
- L'écrivain de contenu du service worker lui-même (`sw.js:789`) porte bien le garde `!response.redirected` — c'est l'écrivain parallèle `offline-prefetch.js` qui l'omet (#250).
- Le retrait du consentement fonctionnel est correct : les deux chemins (bouton « tout refuser » de la bannière, formulaire des préférences) vident les caches `content-*` **et** posent `consent:false` ; le worker cesse ensuite d'écrire, sa vérification de consentement en amont (`sw.js:630`) rendant l'écrivain inatteignable.
- **Aucun contrôle désactivé côté client seul n'ouvre une brèche serveur** : chaque bouton grisé, champ `readonly` ou liste filtrée en JS que j'ai suivi est revalidé côté serveur — la section d'expéditeur d'un envoi groupé (`assertSenderSectionAllowed`), le corps requis d'un e-mail d'acceptation/refus, le délai de renvoi de confirmation, la visibilité de compte financier (`isAccountVisibleTo`, 403), le rôle du brouillon de mail aux répondants, le choix d'ami d'inscription. Le dépôt tient sa propre règle (« a hidden button is not a boundary »).
- Le double envoi est bien gardé là où la conséquence l'exige : les 16 formulaires de gestion des locations (`rental-booking.js:127`, `withDisabled`), l'« envoi » d'un mail de masse (machine à états `TEST → SENDING`, un second clic voit `SENDING` et refuse), l'édition d'un mouvement financier (idempotente par nature).

**Noté sans issue** :
- Aucune revalidation de session/auth au réveil de l'application (`pageshow`/`visibilitychange`) : la navigation authentifiée et le compteur restent affichés jusqu'au prochain tick de sondage après une expiration silencieuse. Sans perte de données (le serveur garde les écritures), mais c'est l'endroit où un écran revenu agit sur un état périmé — et il alimente la fenêtre inter-comptes de #252. Le badge, `navigator.onLine` et l'UI hors ligne, eux, se rafraîchissent bien au réveil.
- Les demandes publiques d'inscription et de location n'ont pas de déduplication serveur, mais utilisent une redirection après POST (F5 sans danger) et ne créent pas d'argent à l'envoi : conséquence moindre que #251, noté.
