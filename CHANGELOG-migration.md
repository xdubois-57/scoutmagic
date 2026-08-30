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
