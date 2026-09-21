# Chantier — Réorganisation des menus

Journal d'implémentation du document de chantier « Réorganisation des
menus » (itérations IT-01 à IT-03). Une section par itération : ce qui a
été fait, les décisions prises en autonomie, les divergences constatées
entre le document de chantier et le dépôt réel, et ce qui a été reporté.
Même format que `docs/chantiers/aide-contextuelle.md`.

La roadmap et sa justification sont déposées dans le dépôt :
`docs/chantiers/proposition-menus.md` porte le *pourquoi* de chaque choix
et doit survivre au chantier. Les deux maquettes sont sous
`docs/chantiers/maquettes/`.

---

## Vérification des faits de la roadmap

La roadmap a été écrite sur le commit `da64c8b` et demande de vérifier
chaque fait cité avant de s'appuyer dessus. **`main` était encore
exactement `da64c8b`** au moment d'ouvrir IT-01 : zéro commit d'écart. Les
faits ont quand même été relus un par un, parce qu'« aucune dérive » ne
dit rien de l'exactitude de la lecture initiale.

| Fait cité | Vérifié |
|---|---|
| `buildPages()` trie par `SORT_GROUP_RANK` avant l'ordre | ✅ dynamique 0, cœur 1, module 2 |
| Pas d'entrée hors groupe ; repli sur la dernière colonne | ✅ documenté dans `addPage()` |
| `text_pages.menu_group` disparaît en silence si la colonne est retirée | ✅ `TextPageMenuProvider::placementIsStillValid()` |
| `TextPageMenuProvider::ORDER_BASE` vaut 600 | ✅ |
| `MODULE_ORDER_BASE` / `MODULE_ORDER_STEP` valent 1000 | ✅ |
| La route `/config/modules/reorder` existe | ✅ |
| `module_registry.sort_order` existe | ✅ |
| `schema/drops.sql` accepte un `DROP COLUMN` réexécutable | ✅ |
| **37 entrées de menu déclarées par des modules, 30 sans `menu_order`** | ✅ au chiffre près |
| Le docblock d'`addPage()` nomme « Exploitation »/« Suivi » | ✅ |
| `ARCHITECTURE.md` §7.1 décrit le réordonnancement | ✅ |
| « Protection des données » est déjà dans le pied de page | ✅ `base.html.twig` ligne 160 |
| `support_dashboard` et `test_tools` portent un `visible_when` | ✅ |

**Aucun écart de fond.** Trois précisions méritent quand même le journal.

**Les maquettes sont arrivées en `.tsx`, le dépôt range des `.jsx`.**
Déposées sous l'extension que la roadmap leur donne elle-même et que le
`README.md` du dossier documente — ce n'est pas cosmétique : ce README
explique que ces fichiers ne sont analysés ni par `tsconfig.json` ni par
Vitest, et l'extension fait partie de ce raisonnement.

**Le module que la proposition appelle « Supervision » s'appelle
« Tableau de bord support » dans son manifeste.** C'est son entrée de menu
qui porte « Supervision ». Les deux textes disent vrai, à des niveaux
différents ; IT-03 touche le champ `name`, pas le libellé de la route.

**« Un ordre non choisi est un ordre subi » est plus vrai que la roadmap
ne le dit.** Elle demande de reproduire « exactement l'ordre rendu
aujourd'hui ». Il n'en existe pas un seul : l'ordre des modules dépend de
`module_registry.sort_order`, une colonne par installation, écrite en
faisant glisser des lignes. Le seul ordre qui soit une propriété du dépôt
est celui d'une installation qui n'a jamais rien réordonné — modules par
nom de répertoire — et c'est celui que l'instantané fige. **Conséquence
assumée : une unité qui avait réordonné ses modules verra son ordre
changer.** C'est inhérent à D4, qui supprime le mécanisme ; ce n'est pas un
effet de bord d'IT-01, c'en est le but.

---

## IT-01 — Un ordre déclaré, pas calculé

### Livré

- **Un `menu_order` explicite sur les 37 entrées déclarées par des
  modules** et sur les 29 pages du cœur, sur l'échelle de D5. Ordre
  maximum atteint : **240**, loin des 599 disponibles, avec des
  intervalles de 10.
- **Une colonne explicite** sur chaque entrée d'un menu groupé.
- **`SORT_GROUP_CORE` et `SORT_GROUP_MODULE` partagent le rang 1** ;
  `SORT_GROUP_DYNAMIC` reste à 0.
- **Le décalage par position de module disparaît** : `MODULE_ORDER_BASE`,
  `MODULE_ORDER_STEP`, le paramètre `$modulePosition` et le tri des
  modules par `sort_order`.
- **Le réordonnancement disparaît** : la route, l'action du contrôleur,
  `ModuleManager::reorder()`, `ModuleRegistryRepository::reorder()`, et la
  colonne `module_registry.sort_order` via `schema/drops.sql`.
- La version de **22 modules** montée dans le même changement.

### Décisions prises en autonomie

**La page Modules n'embarque plus `list_editor`.** La roadmap demande de
retirer le réordonnancement et les lignes d'introduction qui l'expliquent.
Retirer la seule URL que cette page passait au partial n'aurait pas suffi :
`partials/list_editor.html.twig` dessine la poignée de glissement et les
flèches mobiles **sans condition**. La page aurait gardé une poignée qui
déplace une ligne et n'enregistre rien — pire qu'aucune poignée. Les mêmes
lignes sont donc rendues en liste simple, l'interrupteur d'activation
inchangé, et `list-editor.js` et `sortable.js` ne sont plus chargés.
`config-modules.js` se lie à `.module-toggle` par classe et ne dépendait
d'aucune de ces deux.

**Les deux modules à `visible_when` reçoivent aussi un ordre explicite.**
D3 dit « toute entrée de menu », sans exception, et le test d'architecture
les voyait. Leurs entrées existent, elles ne sont simplement jamais
rendues sur une installation ordinaire : 250 et 260 les laissent en fin de
menu Configuration là où elles sont visibles, et ne changent rien
ailleurs.

**Aucune entrée ne dépendait du repli vers la dernière colonne.** La
roadmap le laissait craindre ; les 64 entrées déclaraient déjà la leur. Le
repli reste dans `addPage()` — le retirer transformerait un placement
discutable en exception fatale pendant la construction du menu de tout le
site — et un test échoue désormais si une entrée recommence à s'y fier.

**`discoverModules()` trie par nom de module.** Il triait par position
persistée ; cette position n'existe plus. Le tri alphabétique n'est pas un
défaut de repli mais le seul ordre stable qui reste, et la page qui liste
les modules en a besoin.

### Tests

**L'instantané.** `tests/Core/View/Menu/MenuInventory.php` rejoue le vrai
`MenuBuilder` à partir des sources : les appels `addPage()` de
`public/index.php` lus dans le texte du fichier — comme le font déjà
`MenuRegistrationOrderTest` et `HelpMenuCoverageTest`, parce qu'aucun test
unitaire ne peut exécuter ce script de démarrage — et les routes étiquetées
des manifestes. `MenuSnapshotTest` compare les six rôles à une empreinte
prise **avant** la première ligne modifiée, avec le décalage d'alors.

Les six rôles rendent exactement les mêmes menus après la modification
qu'avant, **pour les 69 entrées que le site livre**. C'est ce qui rendra
IT-02 relisible.

La formulation compte : les pages de texte qu'une unité écrit elle-même
**bougent**, et c'est voulu (voir plus bas). Elles ne sont pas dans
l'instantané parce qu'elles n'existent dans aucun dépôt — ce sont des
lignes de base de données, propres à chaque installation. « Rien ne
change » serait donc faux ; « les 69 entrées livrées gardent leur
ordre » est exact.

Les entrées dynamiques — les membres liés au compte — sont hors
instantané : leur libellé vient d'une ligne de base de données et leur
adresse d'une année scoute. Elles restent devant tout le reste quoi qu'il
arrive, ce que `MenuBuilderTest` tient séparément.

**Le test d'architecture.**
`tests/Architecture/MenuEntriesDeclareTheirPlaceTest.php` échoue si une
entrée n'a pas d'ordre explicite, n'a pas de colonne sur un menu groupé,
nomme une colonne non déclarée, ou prend un ordre de 600 ou plus. La borne
est lue sur `TextPageMenuProvider::ORDER_BASE` par réflexion plutôt
qu'écrite en dur : un garde qui affirmerait une valeur que l'application a
cessé d'utiliser serait pire qu'aucun garde.

### Ce que la suppression a cassé, et comment

| Test | Traitement |
|---|---|
| 6 × `testTheVersionIsBumpedWheneverTheSchemaChanges` | Littéral mis à jour — c'est le fil-piège qui fonctionne |
| `testDynamicEntriesAlwaysSortBeforeCorePages…` | **Réécrit** : la règle a changé, il l'énonce à l'envers, et un second test dit explicitement qu'une page de module peut désormais précéder une page du cœur |
| `testPagesInsideAGroupKeepTheExistingSortGroupThenOrderSort` | **Réécrit** : dans une colonne, seuls les nombres comptent |
| `testEntriesSortWithinTheirGroupNotAheadOfCorePages` | **Réécrit** idem, côté `DynamicMenuRegistrar` |
| `testLoadEnabledModulesLoadsADependentSortedBeforeItsDependency` | **Réécrit** : la prémisse tenait par un glisser-déposer, elle tient maintenant par l'ordre alphabétique — `dependent_module` se charge avant `valid_module` |
| 4 × réordonnancement (contrôleur, gestionnaire, dépôt) | Supprimés : le comportement n'existe plus |
| 2 × rendu de la liste déplaçable | **Remplacés** par trois tests : la page ne dessine aucune commande de réordonnancement, elle garde son interrupteur, et elle ne charge plus que le script qui le branche |
| `testConfigurationMenuRendersInTheOrderTheFileIsWrittenIn` | **Retiré, avec sa raison écrite dans la classe** : il affirmait que l'ordre du fichier prédit l'ordre rendu, ce qui cesse d'être vrai dès que les ordres sont groupés par colonne. Ses deux rôles sont mieux couverts — le doublon d'ordre par `testNoTwoConfigurationEntriesShareAnOrderNumber`, le rendu par l'instantané |

### Le défaut que la relecture a trouvé, et pourquoi l'instantané ne le voyait pas

Le premier instantané ne lisait que deux sources : les appels `addPage()`
de `public/index.php` et les routes étiquetées des manifestes. Il manquait
la troisième — **les entrées contribuées par un `MenuEntryProvider`**.

Elles sont cinq. Une route dont le contrôleur restreint plus que son
`role_min` ne peut pas exprimer ne déclare pas de `label` statique : elle
contribue son entrée par un provider (`ARCHITECTURE.md` §3). Pour la
personne qui lit le menu, ce sont des pages comme les autres.

Quatre d'entre elles portaient des ordres calibrés sur l'ancienne échelle
à deux rangs — 500 à 520, choisis pour se placer parmi des pages de
modules décalées à 1000 et plus. **Fusionner les rangs les envoyait toutes
en fin de colonne**, et l'instantané, aveugle à leur existence, annonçait
que rien n'avait bougé. Le menu public perdait « Locations » de sa
cinquième place ; « Scanner un billet » passait de première à dernière de
sa colonne.

Deux corrections, pas une :

- **Le harnais lit désormais les providers**, par la même lecture de
  source que pour `public/index.php` — un provider a besoin des services
  de son module et d'une requête pour répondre, ce qu'aucun test unitaire
  n'a. Seules les constructions dont tous les arguments sont littéraux
  sont prises ; les deux entrées bâties sur le nom d'un membre et une
  année scoute sont dynamiques et passent devant de toute façon.
- **Les quatre constantes d'ordre sont recalibrées** sur l'échelle
  partagée, aux places que le rendu complet de `origin/main` leur donnait :
  `RentalMenuHookService::INDEX_ORDER` 500 → 50,
  `BannerMenuHookService::CONFIG_ORDER` 510 → 80,
  `NewsMenuHookService::SCAN_ORDER` 520 → 90,
  `RetroMenuHookService::CONFIG_ORDER` 510 → 100.

L'empreinte a été reprise sur un `git worktree` d'`origin/main`, avec le
harnais complet et les anciens rangs, pour qu'elle décrive vraiment
l'état d'avant. Les six rôles rendent à nouveau exactement la même chose
— 69 entrées livrées cette fois, pas 64.

Un test vérifie maintenant que l'inventaire voit toujours ces cinq
entrées, nommées une par une : sans lui, une expression rationnelle qui
cesserait de correspondre ferait passer tous les autres contrôles de ce
fichier sur moins d'entrées, sans un échec.

**Un mouvement reste, et il est voulu.** `TextPageMenuProvider` déclare
ses pages à `SORT_GROUP_CORE`, ordre 600 et plus. Avant, elles
précédaient donc toutes les pages de modules, qui étaient au rang 2 ;
maintenant elles viennent après tout ce que le site livre. C'est
exactement la promesse de D5 — l'ancien comportement était l'accident.

### Six constats de la seconde relecture

Cinq retenus, un décliné.

**Quatre commentaires étaient devenus faux par ma faute.** Renuméroter
sans relire ce que les commentaires voisins affirment, c'est laisser des
phrases qui citent des valeurs qui n'existent plus : « order 5 »,
« Order 11 », « order 10 » dans `public/index.php`, et le même docblock
recopié dans les quatre services de hook — « ceci n'ordonne que les
entrées de modules entre elles », ce que la fusion des rangs rend
exactement faux. Corrigés, en décrivant la position plutôt qu'en citant un
nombre là où le nombre n'apportait rien.

**Un vrai trou dans le garde.** `addPage()` donne 100 par défaut, donc une
page du cœur qui cesserait de déclarer son ordre se lisait « déclare
100 » : le test d'architecture serait passé, et l'instantané aussi partout
où 100 tombe au même endroit. L'inventaire retient désormais *si* l'ordre a
été écrit, et un test l'exige — la règle s'appliquait déjà à moitié sans
que rien ne le dise.

**Une phrase trop large dans ce journal.** « Ne change rien de visible »
contredisait le mouvement des pages de texte documenté dix lignes plus
bas. La formulation exacte est « les 69 entrées livrées gardent leur
ordre » : les pages qu'une unité écrit elle-même ne sont dans aucun dépôt,
donc dans aucun instantané.

**La page Modules dit que ses changements s'enregistrent seuls.** Un écran
à interrupteurs sans bouton d'enregistrement doit le dire. Ce n'est pas un
texte sur un état antérieur du site — D8 vise les phrases du genre
« l'ordre ne se règle plus ici », pas une phrase sur ce que fait la page
maintenant.

**Décliné : réécrire le commentaire d'en-tête de `maquette-menus.jsx`.**
Il décrit pourquoi « Inscriptions » finit dernière aujourd'hui, ce qui
cesse d'être vrai après cette PR. Mais cette maquette est une **pièce
datée** : sa colonne « Aujourd'hui » est l'état d'avant, et c'est
précisément la comparaison qu'elle existe pour montrer. La roadmap demande
de la déposer telle quelle. La corriger pour qu'elle décrive l'après
détruirait ce qu'elle documente.

### Un spec de location rendu robuste, qui n'est pas de cette PR

`Dynamic scan (passive)` est tombé sur `rental-request.spec.js`, alors que
le job `End-to-end (browser)` passait sur **le même commit**. La
différence entre les deux : le second fait passer le navigateur par un
proxy, donc plus lentement.

Le spec enregistre un commentaire interne par un POST de formulaire
classique, puis affirme que le texte est visible. Or
`tests/e2e/support/collapsible-card.js` le dit lui-même : « *a reload
folds everything back, so a spec calls this again after each* ». Le
journal d'échec le confirmait — l'élément était trouvé 82 fois et
**masqué**, pas absent.

Le spec était donc **flaky par construction** : il dépendait de ce que la
page n'ait pas eu le temps de recharger. Reproduit localement dans les
deux sens — il passe avec le correctif, et il passe aussi sans, parce
qu'à la vitesse locale le rechargement n'a pas lieu. C'est précisément
pourquoi seul le job sous proxy le voyait.

Le correctif rouvre l'encart après l'enregistrement, comme le spec le
fait déjà trois lignes plus bas pour son voisin. Ce n'est pas un défaut de
cette PR ; je l'ai corrigé parce que relancer le job m'a été refusé (403)
et que la règle est alors de rendre le test robuste plutôt que de le
laisser retomber. Le périmètre est d'une ligne.

*Note de méthode* : exécuter l'E2E dans ce conteneur demande
`E2E_CHROMIUM_EXECUTABLE=/opt/pw-browsers/chromium-1194/chrome-linux/chrome`
— le binaire `chrome-headless-shell` que Playwright attend n'y est pas.
Utile pour IT-02, qui cassera des specs naviguant par le texte des liens.

### L'instantané « avant » ne l'était pas — troisième et dernière fois

La relecture a trouvé que le fixture, présenté comme pris sur `da64c8b`,
enregistrait en réalité l'ordre **d'après** pour « Espace membres ›
Pages ». Elle avait raison, et la cause est plus instructive que le
symptôme.

Le fixture était généré dans un `git worktree` de `da64c8b`, auquel
j'avais prêté le `vendor/` du dépôt principal par un lien symbolique.
Or l'autoloader de Composer calcule `$baseDir` à partir de `__DIR__`,
qui résout **le vrai chemin** à travers le lien : `Core\` se chargeait
donc depuis le dépôt modifié. Le worktree exécutait mon nouveau
`MenuBuilder` — rangs fusionnés compris — et le résultat était enregistré
comme « avant », puis comparé à lui-même. L'instantané passait et ne
prouvait rien, exactement là où la fusion des rangs change quelque chose.

Une seule colonne était concernée, et c'est celle où l'ancien tri à deux
rangs était porteur : « Notifications », page du cœur d'ordre 10, passait
devant Trombinoscope, Galerie et Groupes, ordres 5, 6 et 7. Impossible
avec un rang unique. Quatre ordres corrigés : Notifications 40 → 10,
Trombinoscope 10 → 20, Galerie 20 → 30, Groupes 30 → 40. Partout ailleurs
mes valeurs étaient justes — par chance, faute d'entrelacement cœur/module.

Le fixture est repris avec un autoloader qui mappe `Core\` sur le
worktree lui-même, jamais sur un `vendor/` partagé. Et le test porte
désormais la vérification de sa propre provenance : il exige que
« Espace membres » commence par « Notifications » pour les quatre rôles
concernés — une empreinte régénérée avec le nouveau code ne peut pas le
satisfaire.

**Ce que je retiens.** Trois fois de suite, le défaut n'était pas dans le
code livré mais dans la chose censée le prouver : d'abord un instantané
aveugle aux entrées contribuées, puis un garde qui ne couvrait qu'un côté
de la règle, puis une empreinte générée avec le code qu'elle devait
contrôler. Un test qui se compare à lui-même est plus dangereux qu'un
test absent, parce qu'il affiche du vert.

### Deux constats du quatrième tour

**Le sujet d'aide de la page Modules n'avait pas suivi.** Il décrivait le
glisser-déposer et affirmait que « les pages du cœur du site passent
toujours en premier » — deux phrases que cette itération rend fausses, sur
une page que `CONTRIBUTING.md` demande de documenter dans le même
changement. `tests/Core/Help/` ne l'a pas vu : il vérifie les libellés
cités entre guillemets, pas la prose descriptive. La section est retirée
sans être remplacée (D8), le résumé et l'une des questions avec elle.

**Les deux modules à `visible_when` n'avaient pas d'ordre antérieur à
préserver.** « Fréquentation » et « Supervision » n'en déclaraient aucun :
elles dépendaient de `module_registry.sort_order`, donc d'une donnée
propre à chaque installation. Il n'existe pas d'ordre « d'avant » à
reproduire pour elles, et l'instantané ne peut pas en juger puisqu'il
décrit l'installation d'une unité ordinaire, où `support_dashboard`
n'existe pas.

J'ai donc choisi, plutôt que préservé : 240 puis 250, soit l'ordre
qu'IT-02 leur donnera de toute façon — « État du site » y est
*Fréquentation, Diagnostic, Supervision*. C'est une décision, pas une
conservation, et elle est ici pour qu'on ne la prenne pas pour l'autre.

### Reporté

Rien. IT-02 et IT-03 sont le périmètre annoncé, pas un report.
