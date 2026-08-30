# Journal de bord — ordonnanceur et migration de schéma

Journal du chantier décrit dans la roadmap « Ordonnanceur et migration de
schéma ». Une section par itération : ce qui a été fait, ce qui a surpris,
ce qui a été écarté, et les mesures avant/après quand l'itération en
annonce une.

Les mesures sont prises sur le conteneur de développement (MySQL local,
disque rapide, pas de contention), **pas** sur l'hébergement mutualisé de
référence. Elles sont donc des minorants : le même travail y coûte
davantage, sur un disque plus lent et un CPU partagé. Les chiffres de
production cités dans la roadmap (mise à jour médiane 24 s, p90 119 s,
maximum 831 s, six échecs à l'étape *migrating*) restent la référence pour
juger de l'ampleur réelle.

## IT-01 — Suppression du backup pré-migration

### Fait

- `MigrationRunner::attemptBackup()` et son appel supprimés, avec
  `getPrivateProperty()` qui n'existait que pour lui (il lisait par
  réflexion les identifiants de connexion privés).
- `MigrationProgress::$backupDone` / `$backupCreated` et
  `MigrationResult::$backupCreated` supprimés.
- `progressFraction()` : trois phases au lieu de quatre (diff, exécution,
  drops), dénominateur 4 → 3.
- `applyExplicitDrops()` journalise désormais `schema_drop_executed` au
  niveau `security`, avec la table et la colonne/contrainte, **uniquement
  quand un drop a réellement supprimé quelque chose** — une ligne de
  `drops.sql` dont la colonne a déjà disparu est ignorée avant ce point,
  donc un site installé ne rejournalise pas tout le fichier à chaque
  migration.
- `MigrationRunner` reçoit un `?JournalService` nullable, passé en
  argument nommé (`journal:`) plutôt qu'en sixième position — répéter le
  budget de temps par défaut à chaque site d'appel pour atteindre le
  paramètre suivant est exactement le genre de duplication qui diverge
  silencieusement le jour où la valeur par défaut change.
- **Câblé au seul chemin qui migre réellement aujourd'hui**,
  `public/index.php` (le contrôle de migration en attente et l'endpoint
  `/api/system/migration-step`). Volontairement pas dans
  `InstallUpdateHandler` ni `RestoreBackupHandler` : aucun test n'atteint
  leur étape de migration, et IT-07 réécrit précisément ces sites d'appel
  pour leur passer l'ensemble des fichiers de schéma. Y câbler le journal
  là-bas, avec le test que IT-07 doit de toute façon écrire, vaut mieux
  que le câbler ici sans couverture. Pas dans `public/cron.php` non plus :
  le `MigrationRunner` qu'il construit ne sert qu'à satisfaire le
  constructeur de `ModuleManager` et n'est jamais appelé — c'est IT-07 qui
  lui donnera un rôle. Il reste nul dans `SetupController` : sur une
  installation neuve, `event_log` est créée par la migration même qui
  tourne.
- Nettoyage induit : `scripts/e2e.sh` et `scripts/dast.sh` transportaient
  chacun un mécanisme d'instantané-et-diff de `storage/temp/backup_*.sql`
  qui n'existait que pour ramasser les dumps que la migration semait dans
  le dépôt. Plus de dumps, plus de ramassage.

### Mesures

Coût du dump que la migration prenait avant de savoir s'il y avait du DDL
à appliquer, sur la base de test (44 tables), en faisant varier le volume
de données :

| Lignes en base | Taille du dump | Dump (supprimé) | Re-diff complet |
|---:|---:|---:|---:|
| 0 | 0,06 Mo | 0,031 s | 0,728 s |
| 20 000 | 4,95 Mo | 0,132 s | 0,698 s |
| 50 000 | 12,3 Mo | 0,261 s | 0,640 s |
| 100 000 | 24,6 Mo | 0,491 s | 0,735 s |

Le coût du dump suit linéairement le volume de données ; celui du diff
n'en dépend pas. **Et il était payé une fois par appel à `migrate()`, pas
une fois par mise à jour** : une release touchant 6 modules produisait 7
dumps séquentiels (le core, puis un par module dont la version avait été
incrémentée), dont 6 étaient supprimés par le suivant. À 25 Mo de données,
cela fait ~3,4 s retirés du chemin critique sur ce conteneur — et bien
davantage sur un mutualisé, où le même dump est la seule partie du travail
qui écrit un fichier de plusieurs dizaines de Mo.

Une migration sans changement de schéma reste court-circuitée par le cache
de hash (0,001 s) : IT-01 ne change rien à ce chemin, qui est celui de
toutes les pages en régime stable.

### Ce qui a surpris

Le retrait de `MigrationResult::$backupCreated` a cassé quatre tests, et
**PHPStan ne l'a pas vu**. Le paramètre supprimé était le troisième
positionnel ; quatre mocks construisaient `new MigrationResult([], [],
false)` en positionnel, où ce `false` désignait `$backupCreated`. Après
suppression, le même `false` désigne `$complete` — un booléen passé à un
paramètre booléen, donc rien à signaler pour l'analyse statique, mais
`ModuleManager` recevait « migration incomplète » et refusait d'activer
tout module. C'est très exactement la mise en garde d'`AGENTS.md`
§ Static analysis : un `grep` des sites d'appel ne suffit pas — ici il a
même trompé, `grep 'new MigrationResult('` ne trouvant pas les
`new \Core\Database\MigrationResult(` pleinement qualifiés des tests. La
suite de tests, elle, l'a vu immédiatement.

### Ce qu'a coûté la couverture

Le Quality Gate SonarQube exige 80 % de couverture sur le code nouveau ;
la première version de cette itération est tombée à 43,9 %. Le déficit ne
venait pas de `MigrationRunner` (92,5 % de lignes couvertes) mais des
lignes de câblage ajoutées dans cinq racines de composition, qu'aucun
test n'atteint — `InstallUpdateHandlerTest` et `RestoreBackupHandlerTest`
existent mais ne vont jamais jusqu'à l'étape de migration. La réponse
retenue n'a pas été d'écrire des tests pour ces chemins (ils appartiennent
à IT-07) ni d'assouplir la porte, mais de **ne pas modifier ces lignes
dans cette itération**. Une itération plus petite est aussi une itération
plus couverte.

### Écarté

- Conserver le backup derrière un réglage. Un dispositif de sécurité qu'on
  peut désactiver et qui duplique celui du dessus n'est pas un dispositif
  de sécurité, c'est un coût conditionnel. Les deux seuls chemins par
  lesquels un fichier de schéma change sur le disque
  (`InstallUpdateHandler`, `RestoreBackupHandler`) prennent déjà leur
  propre backup de sécurité, et ce sont eux qui savent restaurer.
- Ne dumper que si le diff produit du DDL. C'est le bon ordre, mais il
  faut alors dumper *entre* le diff et l'exécution, donc en tenant le
  verrou, donc en rallongeant la tranche que IT-02 cherche justement à
  garder courte — pour reproduire un fichier que personne ne relit.

## IT-02 — Continuation générique de l'ordonnanceur

### Fait

C'est une itération sur `Core\Scheduler`, pas sur la migration : elle ne
contient aucune ligne spécifique au schéma, et bénéficie identiquement à
l'installation de mise à jour, aux backups, aux notifications et aux envois
de masse.

- `Core\Scheduler\SchedulerContinuation` : le moteur. Une tranche sous
  verrou d'exclusion (`GET_LOCK('scoutmagic_scheduler_slice', 0)`, timeout
  zéro), puis un saut HTTP vers le site lui-même si la tranche l'a mérité.
- `POST /api/scheduler/continue` : l'endpoint, public et sans session pour
  la même raison que le webhook GitHub — l'appelant est le processus PHP de
  cette installation. Le secret partagé est toute l'autorisation ; il vit
  dans `secrets.enc`, jamais dans `settings`. `ignore_user_abort(true)`
  avant tout travail, sans quoi le tout premier saut serait avorté.
- `SchedulerRunner::processOverdue(?float $deadline)` : budget de temps,
  contrôlé **entre** les tâches et jamais au milieu de l'une d'elles.
- `SchedulerRepository::release()` / `countOverdue()`.
- Le faux cron reste inchangé et reste l'allumage ; son bridage à une
  exécution par minute ne s'applique pas aux sauts, qui arrivent sur leur
  propre route.

### Ce qui a surpris

**Le piège du `claimOverdue()`.** Ajouter naïvement un budget de temps à
`processOverdue()` aurait perdu des tâches en silence. `claimOverdue()`
bascule *toutes* les tâches en retard en `processing` d'un bloc, et rien ne
re-réclame jamais une ligne `processing` — le claim filtre sur `pending`.
S'arrêter au budget sans rendre les lignes non démarrées les aurait
laissées bloquées à jamais, invisibles de toute passe ultérieure. D'où
`release()`, et un test qui vérifie que les tâches relâchées repassent bien
en `pending` et non en `processing`, sans être comptées comme ayant échoué.

**Une garde que j'avais écrite fausse.** La première version conditionnait
le budget à `$processed > 0` — donc une file de tâches qui échouent toutes
aurait ignoré le budget entièrement, `$processed` ne bougeant jamais. Le
compteur porte maintenant sur les tâches *démarrées*.

**La matrice d'autorisation exige un littéral.**
`tests/Security/AuthorizationMatrixInventoryTest` analyse `public/index.php`
et refuse d'auditer une liste qu'elle n'a pas pu parser entièrement. Écrire
la route avec la constante `SchedulerContinuationRoute::PATH` la rendait
invisible à cet audit, et le test a échoué immédiatement. C'est la bonne
exigence : une route invisible à la matrice de sécurité est pire qu'une
chaîne dupliquée. Le littéral est revenu dans `addRoute()`, et
`SchedulerContinuationRouteTest` épingle les deux copies l'une à l'autre —
si elles divergeaient, le saut atteindrait un 404 et, comme personne ne lit
la réponse d'un saut, la file cesserait de se vider sans la moindre erreur
nulle part.

### Écarté

- **Le spawn CLI détaché.** Mesuré non fonctionnel sur l'hébergement cible
  (`system`, `passthru`, `proc_open`, `popen` tous dans
  `disable_functions`), et de toute façon exposé à `kill_orphaned_php`.
  `ShellExecutor` et `ExecutableLocator` existent dans le codebase et
  donnent l'impression que c'est la solution ; ce n'en est pas une ici.
- **Un timeout non nul sur le verrou.** La tentation de « lisser » avec
  quelques secondes d'attente coûterait un worker FPM par visiteur en
  attente, sur un plafond d'environ vingt Entry Processes.
- **Un worker résident.** C'est exactement la silhouette que
  `kill_orphaned_php` cible.

## IT-03 — Introspection en bloc mémoïsée

### Fait

- `SchemaIntrospector::getTableDefinitions(array $tables)` : trois requêtes
  (`COLUMNS`, `STATISTICS`, `KEY_COLUMN_USAGE` joint à
  `REFERENTIAL_CONSTRAINTS`), filtrées sur `TABLE_SCHEMA = DATABASE()` et
  `TABLE_NAME IN (...)`, regroupées en PHP. Sans `CARDINALITY`.
- `MigrationRunner` mémoïse le résultat sur l'instance, au-delà d'un seul
  appel à `migrate()`, invalidé dès qu'un DDL s'exécute.
- `getTableDefinition()` par table est conservée : `SchemaComparator`,
  `BackupService` et les tests s'en servent. `getAllTableDefinitions()`
  passe par la version en bloc.

### Mesures

Environnement local : **MariaDB 10.11.14** — même version majeure que la
production (10.11.18), donc la mesure est directement représentative, ce qui
n'allait pas de soi.

| | Requêtes | Temps |
|---|---:|---:|
| Lecture en bloc (44 tables) | **3** | 0,014 s |
| Lecture table par table (44 tables) | **132** | 0,187 s |

Soit exactement le « 3 au lieu de 132 » visé, et un facteur 13 sur le temps.
La crainte exprimée dans la roadmap — que MariaDB, sans dictionnaire de
données derrière `INFORMATION_SCHEMA`, rende le gain illusoire — ne se
vérifie pas : trois requêtes larges y battent largement 132 étroites.

Un re-diff complet de `core.sql` coûte encore 196 requêtes au total, dont
seulement 3 d'introspection. Le reste, ce sont les écritures de checkpoint —
c'est le sujet d'IT-05.

### Ce qui a surpris

**Un test qui ne testait rien.** Le premier test d'invalidation du cache
passait aussi bien avec l'invalidation qu'avec l'invalidation désactivée. La
raison : contre une base vide, le memo n'est jamais peuplé — la boucle de
diff ne le consulte que pour les tables qui existent déjà, et s'il n'y en a
aucune elle court-circuite. La péremption ne mord que si la première passe
*lit* le schéma puis le *modifie*, d'où une table préexistante dans la
version corrigée. Vérifié dans les deux sens : le test passe avec
l'invalidation et échoue sans.

**139 `MODIFY COLUMN` cosmétiques sur MariaDB.** Une base déjà exactement
conforme à `schema/core.sql` produit malgré tout 139 `ALTER TABLE ... MODIFY
COLUMN` à chaque re-diff complet. Deux causes, indépendantes :

1. MariaDB rapporte `int(10) unsigned` là où le schéma déclare
   `int unsigned` — elle conserve les largeurs d'affichage que MySQL 8 a
   supprimées.
2. `COLUMN_DEFAULT` revient comme la **chaîne** `'NULL'` pour une colonne
   nullable dont le défaut déclaré est le `null` de PHP. Celle-ci touche
   *toutes* les colonnes nullables sans défaut explicite, c'est-à-dire la
   plupart — et c'est elle qui explique le gros des 139.

Mesuré **identique avant et après** ce changement : ce n'est pas une
régression, et c'est au passage une bonne validation que la lecture en bloc
se comporte exactement comme l'ancienne sur le schéma réel. Signalé et non
corrigé, comme la roadmap le demande — mais à ne pas laisser dormir : ces
139 ALTER s'exécutent réellement à chaque migration, et un `ALTER TABLE` sur
une table peuplée d'un mutualisé n'est pas gratuit. C'est un candidat
sérieux pour expliquer une partie des p90 à 119 s et du maximum à 831 s
observés en production.

### Écarté

- Faire passer `applyExplicitDrops()` par le cache. Les drops doivent voir
  l'état d'après-DDL, et `drops.sql` est petit et rare (c'est écrit dans son
  propre docblock) : la correction prime sur trois requêtes.
