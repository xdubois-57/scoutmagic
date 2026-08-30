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
- `MigrationRunner` reçoit un `?JournalService` nullable, câblé partout où
  un journal existe (`public/index.php`, `public/cron.php`,
  `InstallUpdateHandler`, `RestoreBackupHandler`,
  `StatisticsServiceFactory`). Il reste nul dans `SetupController` : sur
  une installation neuve, `event_log` est créée par la migration même qui
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
