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

## IT-04 — Re-diff à la reprise et politique de convergence

### Fait

- **`pendingStatements` n'est plus persisté.** Chaque passe re-diffe le
  schéma vivant et génère exactement ce qui manque encore. C'est le
  correctif du défaut qui pouvait bloquer un site indéfiniment.
- Les codes « déjà appliqué » (1050, 1060, 1061, 1826) sont des no-op
  bénins, pas des échecs. Une erreur de syntaxe reste un échec.
- `migrate()` qui n'obtient pas le verrou renvoie la progression réellement
  enregistrée, plus 0.0.
- Convergence bornée à 3 passes identiquement infructueuses : au-delà,
  l'empreinte est mise en cache malgré tout, une entrée `security` est
  journalisée, et un bandeau apparaît sur la page Maintenance.
- `MigrationResult::$converged` porte la distinction jusqu'aux appelants ;
  `InstallUpdateHandler` traite une migration non convergée comme une mise
  à jour échouée et repart sur son backup.

### Ce qui a surpris

**Le test de la roadmap est devenu trivial, et c'est le bon signe.** Elle
demandait un test simulant une interruption entre un `ADD COLUMN` et son
checkpoint, vérifiant que la reprise se termine proprement. Avec le re-diff,
ce scénario ne peut plus produire d'échec : la colonne existe, donc le
statement n'est plus généré. Le test est écrit et il passe — mais il ne
teste plus un rattrapage, il atteste que le chemin de panne a disparu. J'ai
donc ajouté séparément un test du classement bénin (introspecteur mocké qui
ment sur l'état de la base, pour que le `ADD COLUMN` soit réellement émis
contre une colonne existante), parce que ce chemin-là, lui, reste
atteignable par une course entre deux processus.

**Deux tests rouges pour une raison qui n'était pas dans mon code.**
`MigrationRunnerTest::setUp()` supprime *toutes* les tables — `settings`
comprise, c'est-à-dire là où vivent l'empreinte de schéma et le compteur de
convergence. Mes deux tests dépendaient de cette persistance et échouaient
donc pour une raison de harnais. Corrigé par un helper qui recrée `settings`
depuis `schema/core.sql` via le parseur et le comparateur de l'application,
plutôt que par un `CREATE TABLE` littéral qui aurait dérivé.

**Un effet de bord qui appartenait à IT-05.** La suppression du warning
« table exists in database but not in declared schema » était prévue pour
l'itération suivante. Elle est arrivée ici, non par élargissement mais par
disparition : le bloc qui l'émettait était la construction de la file de
tables, que le re-diff supprime. IT-05 n'aura donc plus que l'espacement des
checkpoints à traiter.

### Écarté

- Faire échouer la mise à jour dès le premier échec de migration. Le
  plafond existe pour le visiteur qui arrive sur un site que personne ne met
  à jour ; l'appelant qui dispose d'un backup, lui, doit échouer et
  restaurer. D'où `converged` plutôt qu'une politique unique.
- Réutiliser l'ancien `failedCount` comme compteur de convergence. Les deux
  ne mesurent pas la même chose — l'un compte des statements, l'autre des
  passes — et hériter de l'un pour l'autre raccourcirait le budget de
  reprises de la toute première tentative.

### Ajouté après coup : les cales de compatibilité

L'incident de production du 30 août (`dev-8e3b6c1` → `dev-63afd86`, six
restaurations identiques) a montré ce qui manquait à cette itération. Une
mise à jour remplace les fichiers puis migre **dans le même processus PHP** :
`MigrationRunner` est déjà chargé, donc c'est l'**ancien** runner qui
exécute la migration, tandis que `MigrationProgress` et `MigrationResult` —
que rien ne construit sur une requête ordinaire — sont chargés depuis les
**nouveaux** fichiers. Retirer un paramètre de l'un de ces deux objets casse
donc la mise à jour elle-même, et chaque nouvelle tentative rejoue le même
ancien code : le site ne peut plus jamais atteindre la version qui
corrigerait le problème.

IT-04 aurait reproduit exactement cela. Il supprime six propriétés de
`MigrationProgress`, dont `pendingStatements`, sur laquelle l'ancien runner
fait `array_push()` — `TypeError` fatale, mise à jour restaurée, en boucle.

Ces six propriétés sont donc **re-déclarées et re-sérialisées**, inertes,
avec la raison écrite dans le constructeur. `MigrationResult::$converged` a
été ajouté **en fin de signature**, après la cale `$backupCreated`, pour la
même raison. `SelfUpdateCompatibilityTest` rejoue la boucle de l'ancien
runner et échoue avec la `TypeError` exacte si les cales disparaissent.

La correction de fond reste IT-07 : migrer depuis un autre processus que
celui qu'on met à jour supprime toute cette classe de bug, et rendra ces
cales supprimables.

## IT-05 — Espacer les écritures de checkpoint

`checkpoint()` est appelé après chaque statement, et écrivait à chaque fois.
Sur l'installation de référence cela fait **139 UPSERT dans `settings` par
passe** — MariaDB rapporte les mêmes `MODIFY COLUMN` cosmétiques comme
nécessaires à chaque exécution — pour persister une valeur que personne ne
lit avant la fin de la passe.

L'écriture est désormais limitée à une par `CHECKPOINT_MIN_INTERVAL_SECONDS`
(0,5 s). Ce n'est défendable que grâce à IT-04 : depuis le re-diff, le
checkpoint ne porte plus de file. Ce qu'une écriture sautée perd, c'est la
liste des statements exécutés et le dénominateur de la barre de
progression — du rapport, pas de la correction. Une passe interrompue
reprend depuis la base, pas depuis cette ligne.

**L'écriture qui n'est jamais sautée est celle du départ.** Une passe qui
s'arrête parce que son budget est épuisé persiste avant de partir, quelle
que soit la date de sa dernière écriture. Sans cela, l'état lu par la passe
suivante serait périmé d'un intervalle entier de travail. Les deux tests
tiennent les deux bords : celui du bas échoue avec 500 écritures là où 3
sont permises si l'espacement disparaît, celui du haut échoue si le départ
cesse d'écrire.

### Sans objet

Le warning « table exists in database but not in declared schema » que cette
itération devait supprimer avait déjà disparu en IT-04, non par élargissement
mais par disparition : le bloc qui l'émettait était la construction de la
file de tables, que le re-diff a supprimée.

## IT-07 — Migrer au déploiement, pas dans le processus qui déploie

`InstallUpdateHandler` et `RestoreBackupHandler` remplacent tous deux
l'arborescence de fichiers pendant qu'ils tournent, et migraient tous deux
juste après — dans ce même processus, dont les classes chargées sont celles
d'il y a un instant tandis que tout ce qui n'est pas encore chargé vient des
nouveaux fichiers.

C'est ce mélange qui a produit les six restaurations consécutives de
production. Il ne suffisait pas d'ajouter des cales sur les deux objets
concernés : tant que la migration tourne là, n'importe quel changement futur
de leur signature rouvre exactement le même piège.

Les deux passent désormais le statut à `migrating`, planifient la tâche de
reprise qu'ils possédaient déjà pour le cas du budget dépassé, et rendent la
main. La séparation est **garantie et non espérée** :
`SchedulerRunner::processOverdue()` fige sa liste de tâches au début d'une
passe, donc une tâche créée pendant une passe n'est jamais exécutée par
elle — c'est ce qu'affirme le test ajouté ici.

`SchedulerKick::now()` démarre cette passe immédiatement, par la même
requête à soi-même sans lecture de réponse que l'ordonnanceur utilise pour
prolonger une chaîne. Sans elle, la migration attendrait le prochain tic de
cron ou le prochain visiteur — c'est-à-dire « migrer sur la requête de
quelqu'un », qui est précisément ce qu'on supprime. La relance est
opportuniste comme un saut : pas de `base_url`, pas de secret, pas de
loopback, une socket refusée — chacun de ces cas laisse la file s'écouler
comme avant. Quand elle s'exécute, l'opération a déjà réussi et la migration
est déjà en file : une relance ratée est une migration plus lente, jamais
une mise à jour ratée.

### Ce que cela rend possible

Les cales de compatibilité d'IT-04 sur `MigrationProgress` et
`MigrationResult` deviennent supprimables une fois qu'aucune installation
n'est antérieure à ce changement : plus aucune mise à jour ne peut exécuter
le `MigrationRunner` d'une version contre le `MigrationResult` d'une autre.
Elles restent en place jusque-là — leur retrait est une décision sur le parc
installé, pas sur le code.

## Annexe A — Additions au paquet de support

### A1 — Cron réel et cadence

`cron_last_run` et `scheduler_last_run` existaient depuis longtemps et
n'étaient remontés nulle part. Ensemble ils répondent à la question qui
oriente toute une conversation de support : est-ce que quelque chose
d'autre que les visiteurs entraîne la file ?

Aucun des deux ne répond à *à quelle fréquence*, parce que ce sont des
horodatages uniques écrasés à chaque passage — un crontab configuré à
l'heure sur un hôte qui le laisse tomber en silence ressemble exactement,
à travers un seul horodatage, à un crontab qui part toutes les minutes.
D'où `CronRunHistory`, tampon circulaire de vingt entrées écrit par
`public/cron.php` seul, seule source possible d'un intervalle.

**La latence d'ordonnancement est la raison d'être du fichier.** Six échecs
de mise à jour en production disaient tous « bloquée à *migrating* depuis
plus de 15 minutes », et la cause n'était pas la migration : c'étaient des
tâches triviales exécutées six minutes après leur heure prévue, face à un
chien de garde réglé à quinze. Cet écart n'était visible qu'en soustrayant
deux colonnes de `scheduled-tasks.xlsx` à la main. C'est désormais une
colonne, avec médiane et maximum.

### A2 — Prouver plutôt qu'affirmer

`commands.txt` écrivait « exécution de commandes disponible : oui » sur la
seule foi de `disable_functions`. Ce n'est pas une mesure. `ShellExecutor::
probe()` lance `echo` et compare la sortie à un marqueur ; les deux
réponses — déclarée et vérifiée — sont imprimées et jamais fusionnées.
C'est ce qui rend interprétables les cinq « non » qui suivent.

`extensions.txt` liste `get_loaded_extensions()` en entier avec les
versions, et la correspondance extension → fonctionnalité l'accompagne sans
la remplacer. La liste des binaires n'est plus curée : à côté des cinq que
le code appelle, un inventaire de l'hôte que rien ici n'appelle.

### A3 — Réglages de fond

Une tâche qui « n'a jamais tourné » et une tâche dont la planification est
désactivée se ressemblent sur `scheduled-tasks.xlsx`. `scheduler-settings.
txt` tranche : sauvegarde automatique, `auto_update_*`, `dev_update_*`,
rétentions. Une clé non définie est imprimée comme telle plutôt qu'omise —
une ligne absente est indistinguable d'une clé que personne n'a pensé à
inclure.

### A4 — Ce qui est possible sur cet hôte

`background-execution.txt` est la sonde ponctuelle transformée en
collecteur permanent : boucle HTTP testée **par cible** et les deux
résultats conservés, `open_basedir`, `disable_functions` cité en entier,
`fastcgi_finish_request`, `max_execution_time`, compteur de sauts.

La cible est prise dans `base_url` et jamais dans `HTTP_HOST` : un
collecteur qui se connecterait là où pointe un en-tête serait une SSRF
déclenchable par quiconque peut faire générer un paquet de support à un
superadmin. Et la sonde vise `/api/version`, publique et sans effet de
bord — viser l'endpoint de continuation ferait tourner du travail
d'ordonnanceur, ou écrirait une entrée de journal `security` à chaque
génération de paquet.

### Écarté

- Le spawn CLI détaché, y compris en repli. Mesuré non fonctionnel sur
  l'hébergement de référence, et de toute façon exposé au ramassage des
  processus orphelins.
- Un verdict unique fusionnant « déclaré » et « vérifié ». C'est
  précisément la fusion qui rendait le fichier trompeur.

## Après la roadmap — convergence réelle et retrait des cales

### Les 534 MODIFY COLUMN fantômes

Signalés en IT-03 comme un chantier à part, traités ici. Chaque passe de
migration régénérait les mêmes 534 `ALTER`, ils réussissaient tous,
l'introspection rapportait la même « différence » ensuite, et le schéma ne
convergeait jamais. **Rien n'échouait** : c'est pourquoi cela a duré. Un
coût permanent payé à chaque changement de schéma, sans une seule erreur
pour le désigner.

Quatre causes, toutes des divergences de report entre MariaDB et MySQL, et
une seule correspondait à l'hypothèse initiale :

| n | cause |
|---|---|
| 472 | `COLUMN_DEFAULT` contient une **expression** depuis MariaDB 10.2 : une colonne nullable sans défaut y porte le texte nu `NULL` |
| 60 | les défauts chaîne reviennent cités et échappés (`'public'`, `'it''s'`) |
| 9 | `JSON` est un alias de `LONGTEXT` sur MariaDB |
| 2 | `decimal(12, 2)` déclaré contre `decimal(12,2)` rapporté |

Les deux premières sont corrigées dans l'**introspecteur** et non dans le
comparateur. La distinction n'est pas cosmétique : `current_timestamp()`
face à `CURRENT_TIMESTAMP` sont deux orthographes d'un même défaut réel, à
réconcilier au moment de comparer ; ici l'introspecteur rapporte quelque
chose que la colonne **n'a pas**. Tout lecteur mérite la vérité, pas
seulement celui qui diffe.

Ce qui rend le premier cas décidable plutôt qu'ambigu : MariaDB **cite** les
littéraux chaîne, donc une colonne qui a réellement `NULL` pour défaut le
rapporte avec ses apostrophes, et la forme nue ne peut signifier que
« aucun défaut ». C'est pourquoi le test du `NULL` passe en premier.

Mesuré sur le schéma complet (165 tables) contre MariaDB 10.11 : **534 → 0**.

### MySQL et MariaDB : ce que l'échec de CI a révélé

Le correctif ci-dessus, écrit et mesuré contre MariaDB, a fait tomber en CI
le test auquel je tenais le plus — celui qui vérifie qu'une colonne ayant
réellement `NULL` pour défaut le conserve. La CI tourne sur MySQL 8.

La règle « `NULL` non cité signifie pas de défaut » est **vraie sur MariaDB
et fausse sur MySQL**, qui ne cite pas les littéraux : `DEFAULT 'NULL'` y
revient aussi en `NULL` nu, et c'est « pas de défaut » qui s'y exprime par
un vrai NULL SQL. Chaque moteur est cohérent avec lui-même ; les deux se
contredisent. Appliquer la lecture MariaDB à MySQL **efface un défaut
réel** ; appliquer celle de MySQL à MariaDB en **invente 472**.

Vérifié contre les deux moteurs réels plutôt que contre la documentation :
un MySQL 8.0.46 — la version exacte de la CI — a été démarré localement à
côté du MariaDB 10.11.

### Le trou de couverture, plus important que le correctif

Les quatre jobs de CI tournaient sur `mysql:8.0`. **La production tourne
sur le moteur que la CI ne testait jamais.**

L'asymétrie était du mauvais côté. Ce qui vient de se passer était le cas
favorable : du code juste sur MariaDB, attrapé par une CI MySQL. Le cas
inverse — juste sur MySQL, faux sur MariaDB — passe la CI et casse la
production, et il y a un précédent : `SchemaComparator` n'atteignant jamais
son état stable sur un hébergement MariaDB, avec des requêtes qui
paraissaient bloquées.

Un job `database-mariadb` fait désormais tourner **toute** la suite contre
MariaDB 10.11, sans couverture — le rapport Clover que SonarQube consomme
reste produit une seule fois.

Toute la suite plutôt que `--group=database`, délibérément. Les neuf
fichiers qui lisent `TEST_DB_*` portent tous ce groupe aujourd'hui, donc la
forme étroite couvrirait le même terrain — mais seulement jusqu'à ce que
quelqu'un en ajoute un qui ne le porte pas, et le mode d'échec de cet oubli
est le silencieux : il passe en CI et casse la production. Deux minutes de
plus achètent la disparition complète de la question « quels tests sont
sensibles au moteur ».

**Convergence mesurée sur les deux : 534 → 0 sur MariaDB 10.11, 0 sur
MySQL 8.0.46.**

### Et en local : `npm run test:engines`

Le trou de couverture avait une seconde moitié, moins visible : **en local
on teste sur MariaDB, la CI teste sur MySQL, donc personne ne voit jamais
les deux avant de pousser.** C'est exactement le piège dans lequel ce
chantier est tombé — le correctif des défauts a été écrit et mesuré contre
MariaDB, validé en local, poussé, et c'est la CI qui a révélé qu'il effaçait
un défaut réel sur MySQL.

`scripts/test-engines.sh` fait tourner la suite contre les deux : le
MariaDB que le hook de session a déjà démarré, et un MySQL 8 jetable —
conteneur Docker normalement, `mysqld` natif là où il en existe un.

**Découvert en le construisant** : `mysql-server` et `mariadb-server` sont
en **conflit** comme paquets Debian/Ubuntu — apt désinstalle l'un pour
poser l'autre. Un conteneur est donc ce qui rend « les deux, en local »
possible tout court, et c'est déjà le mécanisme que `scripts/e2e.sh`
utilise pour la même raison.

Un moteur que le script n'a pas pu démarrer est rapporté comme tel et fait
sortir en échec. « Vert sur les deux moteurs » et « vert sur le seul moteur
que j'ai trouvé » ne sont pas la même phrase — et c'est précisément la
seconde qui se lisait comme la première.

### Retrait des cales

Supprimées : `MigrationResult::$backupCreated` et les six champs de file de
`MigrationProgress`. Ce n'est pas le temps qui les rend supprimables, c'est
IT-07 : plus aucune mise à jour ne peut exécuter le runner d'une version
contre les objets d'une autre.

`SelfUpdateCompatibilityTest` disparaît, mais son sujet n'a pas disparu — il
a bougé. `Tests\Architecture\SelfUpdateMigrationBoundaryTest` tient
désormais la condition qui a rendu le retrait sûr : la méthode qui appelle
`installFiles()`/`restoreFiles()` ne migre jamais, et passe la main à
`resumeMigration()`. Les deux moitiés comptent — n'affirmer que la première
passerait tout aussi bien sur un handler qui aurait cessé de migrer, ce qui
est un schéma silencieusement non migré et non un problème corrigé.

Vérifié en échec : réintroduire un `migrate()` en ligne fait tomber le test.

### IT-07, les deux points que j'avais omis

Audit de la roadmap contre `main`, à la demande de Xavier : IT-07 en
demandait quatre choses, j'en avais fait deux. J'avais construit
l'itération autour de ma propre lecture — « ne plus migrer dans le
processus qui remplace les fichiers » — juste et importante, mais qui
n'épuisait pas la demande. On ne clôt pas une itération sans repasser sur
ses points.

**`public/cron.php` ne lançait pas la migration.** Il construisait un
`MigrationRunner` et ne l'appelait jamais ; depuis IT-06 ce runner ne
servait même plus à satisfaire le constructeur de `ModuleManager`, donc
c'était du code mort que rien ne signalait. Il migre désormais le schéma
déclaré entier avec un budget de 900 s : en CLI `max_execution_time` vaut
0, donc une passe unique suffit quelle que soit la taille du changement,
sans checkpoint à reprendre et sans personne qui attend. Quinze minutes
plutôt qu'aucun budget — la plus lente des 133 mises à jour de production
a pris 831 s au total, et un budget absurde échangerait un schéma à moitié
migré contre une passe de cron tuée en plein DDL.

La passe est dans `Database\DeploymentMigration` et non en ligne dans le
script, parce que c'est précisément le point : aucun test et aucun
navigateur n'exécute `cron.php`, donc un bloc en ligne y est un bloc que
rien ne peut vérifier — c'est comme ça qu'un `MigrationRunner` jamais
appelé y a survécu à une itération entière. `DeploymentMigrationTest`
exécute la vraie passe contre la vraie base, budget compris.

**Le sondage de la page Maintenance ne faisait que lire.** Il exécute
maintenant une tranche courte à chaque passage, pendant l'étape
`migrating` seulement. L'administrateur qui regarde refetche toutes les
trois secondes de toute façon ; ces requêtes lisaient une ligne de statut
et ne faisaient rien. Trois limites délibérées : uniquement pendant
`migrating`, un budget de 5 s puisque quelqu'un attend la réponse, et
jamais fatal — le métier de cet endpoint est de rapporter un statut, et la
tâche de reprise possède le chemin d'échec, rollback compris.

Aucun des trois moteurs n'est porteur à lui seul : une unité sans crontab
et sans personne devant l'écran reste servie par le chemin de déploiement
et, en dernier recours, par la page de progression.

La réponse du sondage porte au passage la fraction de progression, et le
formulaire d'installation l'affiche à côté de son spinner. C'est
l'`installing`/`migrating` qui peut durer des minutes : sans ce chiffre,
une migration qui avance et une mise à jour bloquée se ressemblent
exactement. Le texte est posé en `textContent`, jamais en `innerHTML`.

### Deux mises à jour « En cours » à partir de la même version

Xavier a vu, sur la page Maintenance, deux lignes partant toutes deux de
`dev-f2ca7af` : l'une à `pending`, l'autre à `migrating`. La question
était juste : est-ce normal, est-ce dangereux ?

**Ce n'était pas deux migrations en parallèle.** Une seule a tourné. Deux
pushes à deux minutes d'écart : le second a annulé la tâche planifiée du
premier — c'est le comportement voulu — mais rien n'a fermé sa ligne
d'historique. `findInProgress()` et `markOtherInProgressAsFailed()`
excluent `pending` délibérément (une install en file n'a touché à rien,
elle ne doit pas mettre les visiteurs derrière la page de maintenance), et
le filet des quinze minutes ne regarde que les lignes déjà en cours. La
ligne restait donc « En cours (pending) » pour toujours. Pas dangereux,
mais un historique qui ment en permanence — exactement le genre de
mensonge qui a rendu l'incident de production difficile à voir.

**Le danger réel était juste à côté.** Rien ne sérialisait deux
installations. La protection tenait à deux choses qui ne se recouvrent
pas : un garde qui ne voit pas une install en file, et une déduplication
qui n'opère qu'à l'intérieur d'une même référence. Deux lignes de
références différentes échappaient aux deux, et le commentaire du code
notait déjà que ça avait corrompu une installation en pratique.

Trois correctifs :

- `Maintenance\InstallLock` — un `GET_LOCK` nommé, **timeout 0** comme
  tous les verrous d'exclusion ici, pris avant
  `markOtherInProgressAsFailed()` et tenu pendant sauvegarde, download et
  `installFiles()`. Le perdant marque sa propre ligne `failed` et se
  retire, au lieu de faire la queue derrière une copie de fichiers pour
  démarrer la sienne à la seconde où elle finit.
- `GitHubWebhookService::supersedeQueuedInstall()` — ferme la ligne
  supplantée au lieu de l'abandonner à `pending`.
- `Maintenance\AbandonedInstallSweeper` — le filet en dessous, lancé
  depuis `public/cron.php`, qui ferme une ligne `pending` qu'aucune tâche
  planifiée n'attend plus. Le prédicat est cette absence, jamais l'âge :
  une install de release qui attend lundi 03:00 est légitimement en file
  pendant des jours.

Vérifiés en échec, les trois : neutraliser le verrou, la fermeture de la
ligne supplantée ou le prédicat du balayeur fait tomber son test.

### La migration n'avançait que si quelqu'un regardait

Régression d'IT-07, de mon fait, constatée en production le 30 août :
`dev-3fdd425 → dev-00804d3`, « Échouée ».

Le journal est sans ambiguïté. À 18:57:49 la tâche `install_update` se
termine en 7,3 s : sauvegarde, download, remplacement des fichiers,
statut `migrating`, tâche de reprise planifiée à 18:57:48 — exactement ce
qu'IT-07 prescrit. Puis plus rien pendant trente minutes. À 19:28:04 la
tâche de reprise s'exécute enfin, avec **30 min 16 s** de retard et en
1 ms — elle ne fait plus rien, le chien de garde des quinze minutes a
déjà tué la ligne — et une cinquantaine de tâches en retard se vident
dans la même seconde.

**Pourquoi la file a gelé.** Le bloc « migration en attente » de
`public/index.php` s'exécute avant le routage : toute requête qui n'est
pas `POST /api/system/migration-step` reçoit la page de progression et
`exit`. C'est voulu — un visiteur ne doit pas atteindre un site à moitié
migré. Ce qui ne l'était pas, c'est que ce court-circuit avale aussi
l'ordonnanceur : `SchedulerKick::now()`, ajouté en IT-07 précisément pour
que la migration n'attende pas un visiteur, est une requête HTTP vers ce
même site. Elle reçoit la page de progression comme les autres. Et sur
cet hébergement `cron_last_run` vaut `0 (jamais)` : `public/cron.php` n'y
a jamais tourné. Le seul moteur restant était un navigateur humain posé
sur la page de progression.

J'avais mis le kick en face du bon problème et il ne pouvait
structurellement pas le résoudre. Ma dernière addition — le sondage de
`update-status` qui fait avancer la migration — est derrière le même
bloc : elle est morte dans la fenêtre pour laquelle je l'ai écrite.

**État de la production après coup**, vérifié sur l'archive de support :
fichiers en `00804d3`, schéma **migré** (`registration_slot_capacities.
capacity` bien passé en `DEFAULT NULL`), mais `VERSION` resté à
`dev-3fdd425` parce que `finishInstall()` n'a jamais tourné. Aucun
rollback, rien de cassé — le site tourne sur `00804d3` en se déclarant
d'une version en retard, ce que la prochaine mise à jour réussie corrige.

**Le correctif.** Ni spawn CLI détaché, ni worker résident, ni dépendance
à un vrai crontab : les trois sont exclus, et ce qui reste est la boucle
HTTP vers soi-même que le projet utilise déjà. `Database\MigrationChain`
fait démarrer, par la requête même qui allait afficher une page de
progression à personne, une chaîne de sauts sans attente vers le seul
point d'entrée atteignable pendant la fenêtre — et chaque tranche qui
laisse du travail émet le suivant. Plafond propre (zéro le désactive),
chaîne relançable après 120 s sans saut pour qu'une chaîne tuée en vol ne
gèle pas le mécanisme, et repli exact sur le comportement précédent sans
`base_url` ou sur socket refusé.

Au passage, `Http\SelfRequest` : le transport commun aux deux chaînes,
pour que la règle « la destination vient de `base_url`, jamais de
`HTTP_HOST` » n'existe qu'à un seul endroit.

Vérifiés en échec : neutraliser le plafond, le garde « une chaîne tourne
déjà » ou l'allumage dans `index.php` fait tomber les tests
correspondants.

Le Quality Gate a d'abord refusé la première version à 74,2 % : les
32 lignes neuves étaient dans `public/index.php`, dans une branche que
l'end-to-end n'entre jamais — il provisionne une installation dont le
schéma est déjà à jour. Même leçon que `DeploymentMigration` pour
`cron.php` : ce qui vit en ligne dans cette branche est du code que rien
ne peut vérifier. La construction de la chaîne, l'enregistrement de ses
réglages et l'ordre « on écrit la réponse, puis on émet le saut » sont
donc dans la classe ; `index.php` ne garde que trois appels.

Le même défaut a reparu à 20:03, à l'identique, sur une install manuelle
cette fois : `dev-3000afc → dev-8f47824`, tâche d'installation terminée
en 8 s à 20:07:36, puis rien jusqu'à 20:24:11 où la reprise s'exécute en
1 ms sur une ligne déjà tuée. Le correctif n'était pas encore déployé.

### L'onglet ouvert qui tournait à vide

Suite du même incident. L'installation de 20:03 était manuelle : Xavier
avait la page Maintenance ouverte, dont le sondage interroge
`/api/maintenance/update-status/…`. Cette adresse est derrière le bloc
« migration en attente » et renvoie donc, pendant toute la fenêtre, le
HTML de la page de progression avec un code 200. Le `res.json()` échoue,
`getJson()` répond `{ok: false, status: 200, data: null}`, et le sondeur
traitait ça comme un accroc réseau : il continuait à interroger, toutes
les trois secondes, la seule adresse structurellement incapable de lui
répondre. Un onglet ouvert, actif, et parfaitement inutile.

Un 200 qui n'est pas du JSON, à cette URL, ne veut dire qu'une chose. Le
formulaire recharge donc, ce qui fait atterrir l'onglet sur la page de
progression — celle dont le script fait réellement avancer la migration,
et qui recharge en retour vers la page Maintenance une fois terminé.

La distinction est nette et non heuristique : `getJson()` répond
`status: 0` sur une vraie panne réseau, jamais 200. Recharger là-dessus
jetterait une page valide à chaque clignotement du wifi — c'est le second
test, et il tombe si l'on élargit la condition.


## IT-08 — La grille qui bloquait le saut censé la lever

### Fait

- `Maintenance\MaintenanceGate` laisse passer une seule adresse pendant une
  mise à jour : `POST /api/scheduler/continue`. Le chemin est désormais
  passé par `Http\FrontController` et testé **avant** `findInProgress()`.
- Le point d'entrée de tranche de migration rend la main à l'ordonnanceur
  sur la tranche qui termine la migration (`Scheduler\SchedulerKick::
  fromPdo()`, la même relance que `now()` pour un appelant qui n'a qu'un
  PDO).
- Le chien de garde des mises à jour abandonnées mesure le silence et non
  la durée : nouvelle colonne `update_history.progress_at`, écrite à la
  création, à chaque changement de statut, à l'entrée et à chaque sortie
  hors budget de `resumeMigration()`, à chaque tranche de migration
  (`touchInProgress()`) et à chaque sondage de la page Maintenance qui a
  réellement fait avancer une tranche.

### Ce qui a surpris

Le mécanisme d'IT-07 était en place, déployé, et n'a rien changé : six
mises à jour de plus abandonnées à l'étape `migrating` en quarante-huit
heures, dont les deux dernières **sans aucun changement de schéma**. Le
paquet de support du 31/08 le dit ligne à ligne : `dev-afd995a →
dev-a29f7ae` met la tâche de reprise en file à 15:03:00, la tâche
s'exécute à 15:27:35, et l'historique porte « bloquée à l'étape
*migrating* depuis plus de 15 minutes ». Idem à 14:03:33 → 14:29:22. Et
l'écart n'est jamais 15 minutes tout rond par hasard : `started_at` +
15 min tombe exactement sur l'horodatage de la tâche suivante remise en
file (15:17:50, 14:18:33, 13:37:31).

La cause n'était pas la migration — il n'y en avait pas. La relance de
`Task\InstallUpdateHandler` vise `/api/scheduler/continue`, et
`MaintenanceGate` s'exécute avant le routage, sur **toutes** les adresses
sans exception. Elle répondait donc au saut par la page « mise à jour en
cours », en se fondant sur la ligne même que ce saut venait faire avancer.
Le saut retombait ensuite sur le pseudo-cron en fin d'`index.php` — qui
est bridé à une passe par minute et venait d'être tamponné par la passe
qui avait lancé l'installation. Résultat : rien. Sur une installation sans
crontab, la tâche de reprise attendait la première requête sans rapport
qui passerait par là ; et ce qui débloquait le site, au bout du compte,
c'était `findInProgress()` déclarant lui-même la mise à jour abandonnée —
après quoi la reprise trouvait une ligne `failed` et renonçait.

Deuxième surprise, plus discrète : terminer le schéma n'est pas terminer
la mise à jour. `MigrationChain` mène la migration à son terme sans
personne, puis s'arrête — alors que `VERSION`, la clôture de la ligne
`update_history`, la purge des sauvegardes et la notification vivent dans
la tâche de reprise, que la chaîne n'a aucune raison propre de lancer.

### Ce qui a été écarté

Assouplir le bridage du pseudo-cron : il protège contre le trafic qui
déclenche l'ordonnanceur, et l'élargir aurait traité un symptôme sur une
seule des deux voies bloquées.

Faire dépendre le chien de garde de « une tâche planifiée pointe encore
sur cette ligne » plutôt que d'un battement de cœur : une tâche restée en
`processing` parce que le processus est mort ne redevient jamais rien, et
la grille aurait alors retenu les visiteurs indéfiniment — exactement ce
que ce seuil existe pour éviter.

## IT-09 — Le canal de développement installait l'arbre git, pas une application

Le mode développement (`auto_update_level = dev`) installait, à chaque
push sur la branche suivie, `https://api.github.com/repos/{repo}/zipball/{sha}`
— l'archive git du commit. C'est le *dépôt*, pas un site déployable, et
deux conséquences en découlaient, toutes deux vérifiées sur les paquets
de support de scoutmagic.be.

**La dérive silencieuse.** L'archive ne contient pas `vendor/`, qui est
gitignoré. Or `InstallUpdateHandler::installFiles()` copie par ajout :
rien n'a jamais remplacé ni supprimé le `vendor/` posé à la première
installation. `composer.lock`, lui, était bien remplacé à chaque mise à
jour. Mesure : sur une quarantaine de mises à jour du canal dev, la date
de modification de `vendor/` n'a jamais quitté celle de l'installation
d'origine pendant que `composer.lock` avançait de version en version. Un
site qui exécute du code de cette semaine contre des dépendances d'il y a
des mois, sans le moindre symptôme jusqu'à la première ligne qui appelle
quelque chose que l'ancienne dépendance n'a pas.

**Et ce qu'elle contenait en trop.** La même archive déposait `tests/`
(jeu de données de référence compris — ses mots de passe de démonstration
documentés et son constructeur CLI qui écrit massivement en base),
`.github/`, `bootstrap/` et `scripts/` sur une racine web de production.

### Fait

- `scripts/build-artifact.sh` — l'unique implémentation de « ce qu'est un
  artefact ScoutMagic installable » : `composer install --no-dev
  --optimize-autoloader`, la liste d'exclusions, la forme plate
  (`zip -r <artefact> .`, sans répertoire enveloppant), et les deux
  vérifications qui refusent une archive sans `vendor/autoload.php` ou
  avec un `.htaccess` à la racine. `scripts/release.sh` l'appelle
  désormais au lieu d'en porter sa propre copie ; ses trois autres
  contrôles (`docs/help/` présent, pas de `node_modules/`, pas de jeu de
  données de référence) restent chez lui, ils portent sur ce qu'une
  *release publiée* ne doit pas contenir, pas sur l'artefact lui-même.
  La restauration des dépendances de développement vit maintenant
  entièrement dans le script partagé, donc le `trap` de `release.sh` ne
  garde que ses deux fichiers temporaires — pas deux restaurations qui se
  chevauchent.
- `.github/workflows/dev-build.yml` — à chaque push sur `main`, construit
  cet artefact et le publie sous le nom `scoutmagic-dev-{sha7}.zip` sur
  une **préversion** roulante étiquetée `dev-build`, en gardant les trois
  plus récents. Permission `contents: write`, rien d'autre.
- `GitHubWebhookService::processPushEvent()` — installe cette archive
  (`source_type: 'release'`, puisqu'elle est plate comme une release), et
  calcule enfin `dependencies_changed` au lieu de l'écrire `false` en
  dur.
- `InstallUpdateHandler` — attend que l'artefact existe (voir plus bas).
- `BackupService` — la sauvegarde de sécurité archive `vendor/` et
  `schema/`, et `RESTORABLE_TOP_LEVEL` les accepte.

### Surpris

**La préversion n'est pas un détail de présentation, c'est la séparation
des deux canaux.** `GitHubReleaseClient` lit
`GET /releases/latest`, que GitHub définit comme excluant brouillons et
préversions. C'est le seul mécanisme qui empêche une build de `main`
d'être proposée — et installée sans surveillance — à tous les sites en
`patch`/`minor`/`major`. Le workflow ré-affirme donc
`--prerelease --latest=false` à chaque exécution plutôt que de faire
confiance à ce qu'il a créé une fois.

**Le webhook arrive avant l'artefact.** L'événement `push` part à la
seconde où le commit atterrit ; l'intégration continue met une à trois
minutes à résoudre les dépendances, zipper et téléverser. La première
tentative d'installation trouve donc un 404 — et un 404 sur ce chemin,
c'est une mise à jour en échec avec restauration automatique, à chaque
push. La charge planifiée porte donc `wait_for_artifact_until`, et le
handler sonde l'URL en HEAD **avant** la sauvegarde de sécurité et avant
tout changement de statut : absent et délai restant, il se replanifie
lui-même à +90 s en laissant la ligne à `pending` ; absent au-delà du
délai, il échoue en nommant l'archive et son étiquette. Laisser la ligne
à `pending` est sûr, et pour une raison précise :
`AbandonedInstallSweeper` ne ferme une ligne `pending` que si *aucune*
tâche en file ne la vise — et celle qui vient d'être créée la vise.

**Le repli qu'il ne faut surtout pas écrire.** « Si l'artefact n'est pas
là, prends le zipball » est la ligne évidente, et c'est exactement la
dérive de `vendor/` réintroduite — au moment où personne ne regarde. Il
n'y a pas de repli : une build qui n'a jamais publié son archive est une
build cassée, et une build cassée doit se voir.

**La référence de la tâche ne lui est jamais transmise.** `TaskContext`
ne la porte pas. Une replanification sans référence serait invisible à
`supersedeQueuedInstall()`, donc un push plus récent laisserait deux
installations exigibles en même temps — précisément ce que la section
« Deux mises à jour "En cours" » a corrigé. Elle voyage donc dans la
charge utile.

**La sauvegarde était accidentellement cohérente.** Tant que le canal dev
installait une archive sans `vendor/`, ne pas sauvegarder `vendor/`
n'avait aucune conséquence : rien ne le remplaçait. Maintenant que chaque
installation le remplace en bloc, une restauration qui ne rendrait que
`core`/`modules`/`public`/`storage` remettrait l'ancien code par-dessus
les nouvelles dépendances et le nouveau schéma déclaré — le même état
mixte qu'IT-07, atteint par l'autre bout. `RESTORABLE_TOP_LEVEL` reste un
contrôle de sur-ensemble, donc une archive antérieure se restaure
exactement comme avant.

### Écarté

- Rien, finalement, sur le second point d'entrée du canal dev : la
  relecture a aligné « Installer maintenant »
  (`MaintenanceController::installDevBranchUpdate()`) sur le webhook —
  même URL d'artefact (helper partagé `devArtifactUrl()`), même
  `source_type`, même délai d'attente de l'artefact. Un commit tout juste
  poussé sans artefact publié suit le même chemin : la tâche réessaie en
  arrière-plan puis échoue proprement, au lieu de faire attendre
  l'administrateur devant sa page.
- L'archive pesait 692 Mo mesurée localement : 612 Mo de `.git` interne à
  `aws-sdk-php` (installation depuis les sources) et le reste en
  définitions de services AWS inutilisés. `--prefer-dist`, l'exclusion
  des `.git` imbriqués et l'élagage Composer du SDK (`extra:
  aws/aws-sdk-php: ["S3"]` — seul S3 est utilisé, par la galerie) la
  ramènent à 31 Mo pour 12 000 entrées, mesuré sur une construction
  réelle. Le canal stable en profite à l'identique.

### Post-scriptum — le tag brûlé

L'immutabilité des releases GitHub a coûté trois passages du workflow :
le premier a échoué en téléversant un asset sur une release publiée
(« Cannot upload assets to an immutable release »), le second et le
troisième en republiant sous le même tag après suppression — « tag_name
was used by an immutable release », y compris une fois l'option
désactivée sur le dépôt. Un nom de tag ayant porté une release immuable
est réservé pour toujours. Le canal publie donc sous `dev-latest`, en
brouillon avec l'asset attaché puis publication ; `dev-build` reste
brûlé, et le site déployé qui pointait dessus a dû être ponté à la main
(un fichier, `GitHubWebhookService.php`, par FTP).
