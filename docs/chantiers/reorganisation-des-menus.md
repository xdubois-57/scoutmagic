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
qu'avant. C'est la preuve qu'IT-01 ne change rien de visible, et c'est ce
qui rendra IT-02 relisible.

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

### Reporté

Rien. IT-02 et IT-03 sont le périmètre annoncé, pas un report.
