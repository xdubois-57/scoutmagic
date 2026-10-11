# Chantier — Sauvegardes : une liste qui dit vrai, une restauration qui n'efface rien

Répond à l'issue **#847**. Cœur : `core/Maintenance`, `core/Http/Controller/MaintenanceController.php`,
`core/View/templates/config/maintenance/`.

**Prérequis : l'issue #832 doit être fermée avant d'écrire la moindre ligne de code.** Ce chantier
s'appuie sur ce qu'elle livre : la mise de côté d'un état avant que la restauration remplace la base
(`StorageBindingSnapshot`, tenu dans `storage/temp`, dont le chemin suit la charge utile de reprise),
et la réconciliation qui tourne une fois le schéma restauré à jour (`StorageBindingReconciler`). La
partie 3 ajoute sa propre mise de côté **au même endroit et selon le même modèle** ; elle ne crée pas
un second mécanisme. Lis d'abord le code livré par #832 : si ses noms diffèrent de ceux d'ici, ce sont
les siens qui font foi.

**Un seul lot, une seule PR**, en quatre parties, un commit chacune. Elles touchent les mêmes fichiers ;
les séparer en plusieurs PR ferait attendre la CI plusieurs fois pour rien.

**Pour qui.** La personne qui restaure n'est pas une experte du site, et elle le fait en urgence, sous
stress. Chaque écran dit ce qui va se passer, en français courant ; le site fait lui-même tout ce qu'il
peut faire ; il refuse tôt plutôt que d'échouer tard ; et rien de ce qui existait avant la restauration
n'est perdu sans qu'on l'ait demandé.

---

## Conventions de travail

**Avant d'écrire la moindre ligne**, lis intégralement `README.md`, `ARCHITECTURE.md`,
`SECURITY.md`, `AGENTS.md`, `CONTRIBUTING.md`, `specifications.md`, `design.md` et
`docs/module-development.md`, puis le code livré par #832. Ils priment sur ce fichier sur toute règle
générale ; si tu découvres qu'ils décrivent une réalité que le code contredit, **mets-les à jour dans
la même PR**.

- **Le travail suit `AGENTS.md` § « Fix the backlog », pour #847 seulement** : branche
  `claude/issue-847` créée par l'API, une PR, un commit par partie.
- **Lance PHPUnit, PHPStan, et `npm run typecheck` + Vitest si tu touches `public/assets/js/`,
  localement avant chaque poussée.** La CI doit confirmer, pas découvrir. Les aller-retours de
  restauration (`RestoreBackupRoundTripTest`) tournent contre MariaDB : lance-les avec la base de test.
- **Arme l'auto-merge dès que la PR est prête**, comme le décrit `AGENTS.md` § « Merging a pull
  request », sans demander de confirmation. Un test rouge arrête le lot : tu corriges, tu ne contournes
  pas, tu ne désactives rien, tu n'ajoutes rien à une baseline.
- Code, commentaires et identifiants en anglais ; interface en français.
- Chaque écran modifié ship son sujet d'aide dans la même PR (`docs/help/sauvegardes.md`,
  `docs/help/restaurer.md`, `docs/help/sauvegarde-hors-site.md`, et ceux que tu découvres) ;
  `tests/Core/Help/` échoue sinon.
- Couverture RBAC explicite sur toute route nouvelle ou modifiée : superadmin, comme le reste de
  `/config/maintenance`.
- Aucune donnée personnelle dans le journal, dans un fichier mis de côté ni dans un message d'erreur.
- Tu avances seul. Tu ne poses une question que sur une **ambiguïté fonctionnelle réelle** ou un
  **changement de conception** non tranché ici.
- Le site est en test : **aucune reprise de données**, et aucun texte d'interface ne mentionne un état
  antérieur.
- **La PR porte « Corrige #847 »** dans sa description — pas un mot-clé de fermeture GitHub, voir
  `AGENTS.md`. Après la fusion, **vérifie que #847 est fermée** ; sinon, ferme-la avec un commentaire
  qui renvoie à la PR.

---

## La maquette, et ce qu'il ne faut pas toucher

La maquette jointe (`docs/chantiers/maquettes/sauvegardes-restauration.html`) est **partielle**. Chaque
zone porte une étiquette :

- **NOUVEAU** — à créer ;
- **MODIFIÉ** — seul ce que dit l'étiquette change ; le reste de la zone ne bouge pas ;
- **INCHANGÉ** — ne pas toucher, même quand la zone est abrégée ou seulement nommée dans un encadré en
  pointillé.

**Tout ce qui n'est pas dessiné reste exactement comme aujourd'hui** : les autres sous-pages de la
maintenance, les badges de famille et d'intégrité existants, le format de la date et de la taille, le
bouton « Voir plus », la confirmation de suppression, le mécanisme du mot de passe, le téléversement
d'un fichier dans « Restaurer une sauvegarde ». La maquette fait autorité sur la hiérarchie, les
libellés et les états, pas sur le style : ses styles sont en ligne pour la rendre autonome, le produit
suit Bootstrap 5 et `design.md`.

---

## Ce qui existe aujourd'hui

- **La liste** (`MaintenanceController::backupList()`, macro `backup_rows` de
  `config/maintenance/_macros.html.twig`) affiche « Télécharger » et le mot de passe dès que
  `status = completed` et que `file_id` est renseigné, sans regarder le disque. Le sélecteur de
  restauration (`sauvegardes_recentes.html.twig`) retient `completed` et non portable, sans plus. Les
  trois boutons d'une ligne sont des éléments frères d'un `d-flex flex-wrap` : sur un écran étroit, le
  troisième passe à la ligne.
- **Le contrôle d'intégrité** (`BackupIntegrity::verify()`, tâche quotidienne
  `VerifyBackupIntegrityHandler`) sait dire `missing` ; la liste l'affiche en badge (« Fichier
  absent ») sans en tirer de conséquence sur les actions. Il peut dater de la veille.
- **Le téléchargement** passe par `/files/{id}` (`FileController::serve()`), qui répond `Not Found`
  en texte brut quand le fichier manque. Le lien porte `download` (`partials/file_link.html.twig`) et,
  dans l'application installée, `file-viewer.js` intercepte les liens `/files/` : une page d'erreur
  serait enregistrée sous le nom de l'archive, ou affichée sans moyen d'en revenir.
- **La restauration** (`Task/RestoreBackupHandler`) prend d'abord la copie de sécurité complète
  (`createSafetyBackup()`, type `auto_reset`, libellée « Avant réinitialisation »), puis résout la
  source (`resolveSource()`). Un dump absent n'est découvert qu'après cette copie — des minutes de
  travail — et suivi d'un retour arrière. **Une archive dont la ligne `files` a disparu est
  silencieusement ignorée** : seule la base est restaurée.
- **« Base de données »** (`database`) ne contient que la base. La restaurer remet la base d'une date
  avec les fichiers d'aujourd'hui : photos, documents et pièces jointes ne correspondent plus.
- **« Configuration seule »** (`full_config`) contient la **structure de toutes les tables** et les
  données de `settings` et `module_registry` seulement (`BackupService::CONFIG_ONLY_TABLES`). La
  restaurer **vide le site** : toutes les autres tables sont recréées vides.
- **La restauration remplace toute la base**, y compris ce qui décrit le serveur plutôt que les
  données de l'unité :
  - `backups` et `files` : les sauvegardes supprimées depuis réapparaissent sans fichier (le cas de la
    sauvegarde n° 67), et **la copie de sécurité prise juste avant disparaît de la liste**, avec toutes
    celles prises après la date restaurée — fichiers sur le disque, aucune ligne, jamais purgés ;
  - `event_log` : le journal revient à la date restaurée, la trace de ce qui s'est passé depuis — y
    compris l'incident qui motive la restauration — disparaît ;
  - `scheduled_actions` : les tâches en attente à la date restaurée reviennent et s'exécutent ;
  - les réglages `remote_backup_*` : la destination hors site et la génération de la phrase de passe
    reviennent en arrière, alors que la phrase elle-même (dans `secrets.enc`) ne bouge pas.
- **La restauration portable** (`restorePortable()`, `Portable\PortableRestore`) remplace en plus
  `secrets.enc`, sauf `PortableRestore::TARGET_OWNED_SECRETS` : les mots de passe gardés des
  sauvegardes (`BackupPasswords`, clés `backup_password_{id}`) et la phrase hors site
  (`remote_backup_passphrase`) sont remplacés par ceux de l'archive.
- **Les copies hors site** (`Task/SendRemoteBackupHandler`) sont des archives portables envoyées en
  morceaux sur plusieurs passages ; la charge utile porte `archive_path` et `remote_name`, l'archive
  locale est effacée après succès. `Remote\RemoteRetention` les nomme (date et génération de la phrase,
  `nameFor()`) et les liste (`listArchives()`). **Aucun écran ne les montre**, et rien ne permet d'en
  restaurer une sans la télécharger à la main puis la téléverser.
- **Vérifier une phrase de passe ne coûte rien, quelle que soit la taille de l'archive** :
  `PortableArchive::open()` lit le commentaire du zip, dérive la clé une fois (Argon2id interactif, ou
  PBKDF2), et déchiffre le seul manifeste. Mais l'archive doit être sur le serveur.

---

## Partie 1 — La liste dit vrai, et la restauration refuse tôt

### La présence réelle, à chaque affichage

- Une classe dédiée (`BackupPresence` ou équivalent) dit, pour une sauvegarde, si **l'archive** et
  **le dump** sont présents : ligne `files` existante **et** fichier lisible sur le disque. Elle
  partage avec `BackupIntegrity` la façon de résoudre un fichier (pas de second calcul de chemin) et ne
  calcule **aucune empreinte** : une vérification d'existence, faite à chaque affichage.
- **La présence prime sur le dernier statut d'intégrité** : une sauvegarde « Vérifiée » hier dont
  l'archive a disparu aujourd'hui est traitée comme absente.
- `backupList()` transmet à Twig `archivePresent` et `dumpPresent`, et en déduit `canDownload`,
  `canShowPassword` et `canRestore`. Le gabarit ne refait aucun calcul.

### Les actions de chaque ligne

| Situation (sauvegarde terminée) | Badge | Télécharger | Mot de passe | Restaurable | Supprimer |
|---|---|---|---|---|---|
| Archive et dump présents | inchangé | oui | si gardé | oui, sauf `database` et portable | oui |
| Archive absente, dump présent | **« Archive absente »** | non | si gardé | non | oui |
| Dump absent, archive présente | **« Base de données absente »** | oui | si gardé | non | oui |
| Les deux absents | **« Fichiers absents »** | non | si gardé | non | oui |

- **Le mot de passe reste affiché tant qu'il est gardé**, archive présente ou non : c'est la seule
  clé d'une copie déjà téléchargée. `canShowPassword` dépend du mot de passe gardé, jamais de la
  présence de l'archive.
- Seuls comptent les composants que la sauvegarde déclare : une ligne sans `file_id` n'est jamais
  « Archive absente ».
- Le badge est rouge (`text-bg-danger`), avec en `title` une phrase qui dit ce qui manque et ce qu'on
  peut faire — celles de la maquette. Il **remplace** le badge d'intégrité « Fichier absent » sur sa
  ligne, il ne s'y ajoute pas.
- **« Supprimer » reste toujours disponible**, fichiers présents ou non. `BackupRetention::forget()`
  tolère déjà des fichiers absents ; un test le fixe.

### « Base de données » devient un export

- Une sauvegarde `database` reste **téléchargeable** (avec son mot de passe), mais **n'est plus
  restaurable sur ce serveur** : sans les fichiers du site de la même date, elle produirait un site
  incohérent.
- Sa ligne porte un badge neutre **« Export »** et la phrase « À télécharger seulement : sans les
  fichiers du site, elle ne se restaure pas ici. » Elle n'apparaît plus dans le sélecteur.
- **Le serveur refuse** : le contrôleur et `RestoreBackupHandler` refusent une demande qui désigne une
  ligne `database`, avant la copie de sécurité.
- Le libellé du bouton qui la crée, dans « Sauvegarde manuelle », ne change pas.

### Où est la sauvegarde

- La ligne de date se termine par **« Sur ce serveur »** (`white-space: nowrap`) : « 11/10/2026 03:00
  · 1,2 Go · Sur ce serveur ». C'est vrai de toutes les lignes de cette liste : les copies hors site
  sont une autre archive, jamais inscrite dans `backups`, et ont leur propre carte (partie 4).

### Les boutons d'une ligne, sur une seule rangée

- Les actions de la ligne (télécharger, mot de passe, supprimer — celles qui existent) sont dans **un
  seul conteneur** `d-inline-flex flex-nowrap gap-2`, à largeur intrinsèque, aligné à droite. Le texte
  et les badges prennent le reste et passent à la ligne s'il le faut ; **le groupe ne se coupe jamais**,
  sans débordement horizontal à 320 px.
- `aria-label`, `title`, cibles tactiles et confirmation de suppression inchangés. Le champ du mot de
  passe révélé (`<output>`) reste sous la ligne, sur toute la largeur.

### Le téléchargement, y compris dans l'application installée

Le bouton n'est proposé que si l'archive est présente à l'affichage. Reste le cas où elle disparaît
entre l'affichage et le clic :

- **Aucune redirection ni page d'erreur en réponse à un lien `download`.**
- Une route **`GET /config/maintenance/backup/{id}/presence`** (superadmin, JSON
  `{"archive": bool, "dump": bool}`) répond sans rien télécharger.
- Au clic sur « Télécharger », un script de la page interroge cette route **avant** de laisser le lien
  suivre son cours :
  - archive présente : le lien part par le chemin habituel (`download` dans un onglet,
    `file-viewer.js` dans l'application installée) ;
  - archive absente : **rien n'est suivi** ; sous la ligne s'affiche « L'archive de cette sauvegarde
    n'est plus sur ce serveur. Elle ne peut plus être téléchargée ni restaurée ; vous pouvez supprimer
    la ligne. » (`role="status"`), le badge passe à « Archive absente » et « Télécharger »
    disparaît — le mot de passe reste —, sans recharger la page ;
  - requête en échec (réseau) : le lien part comme aujourd'hui, pas de faux négatif.
- Le comportement est **identique dans un onglet et dans l'application installée** (mode
  `standalone`, iOS compris) : la vérification précède toute navigation. Vérifie l'ordre des écouteurs
  avec `file-viewer.js`, qui ne doit pas partir avant la réponse.
- Sans JavaScript, le lien reste un lien ; `FileController::serve()` répond un 404 **en français**
  (« Ce fichier n'est plus sur le serveur. ») au lieu de `Not Found`, pour tout fichier.

### La restauration refuse tôt

- Le sélecteur ne propose que les sauvegardes **restaurables** (`canRestore`). Si aucune ne l'est, la
  phrase actuelle « Aucune sauvegarde de ce serveur ne peut être restaurée pour l'instant. » reste.
- **Le contrôleur** vérifie la présence au moment de la demande et refuse aussitôt, avec un message en
  tête de page : « Restauration refusée. Cette sauvegarde ne peut plus être restaurée : son archive
  n'est plus sur ce serveur. Rien n'a été modifié. » — ou « sa base de données », « ses fichiers ».
- **`RestoreBackupHandler` revérifie avant la copie de sécurité**, au même endroit que le refus d'une
  sauvegarde portable, et refuse de même (journal `backup_restore_refused`, motif `files_missing` ou
  `export_only`) : le fichier peut disparaître entre la demande et l'exécution. La personne qui a
  demandé la restauration est prévenue comme pour tout échec (`RequesterNotice`).
- **Une archive déclarée mais introuvable n'est plus ignorée** : `resolveSource()` refuse au lieu de
  ne restaurer que la base.

---

## Partie 2 — « Configuration seule » ne restaure que la configuration

- Restaurer une sauvegarde `full_config` **ne touche à aucune autre table** que `settings` et
  `module_registry`. Membres, finances, actualités, fichiers et tout le reste ne bougent pas. Le
  sélecteur l'annonce sous la liste quand elle est choisie : « Seuls les réglages et l'état des modules
  reviendront au 8 octobre 2026. Les membres, les finances, les actualités et tout le reste ne
  bougeront pas. »
- **Le dump n'est pas rejoué tel quel** : il contient `DROP TABLE` et `CREATE TABLE` pour chaque table.
  Le handler en lit les lignes de `settings` et de `module_registry` et les applique à la **structure
  actuelle** de ces deux tables, en une transaction.
  - `settings` : contenu remplacé par celui de la sauvegarde, à l'exception des clés que la partie 3
    garde de l'état d'aujourd'hui (réglages hors site) ; une clé que la sauvegarde ne connaît pas garde
    sa valeur actuelle.
  - `module_registry` : seul l'**état d'activation** revient (`enabled`, `enabled_at`, `enabled_by` —
    ce dernier à `NULL` si le compte n'existe pas), pour les modules présents des deux côtés.
    **`installed_version` n'est jamais restaurée** : elle décrit le schéma réellement en base, et une
    version plus ancienne ferait rejouer des migrations sur des tables déjà migrées.
- Aucune archive de fichiers n'est extraite. La copie de sécurité est prise comme pour toute
  restauration ; la passe de reprise tourne comme aujourd'hui, la partie 3 s'y applique.
- **Le serveur refuse** un dump `full_config` qui contiendrait des données d'une autre table : il ne
  devine pas.

---

## Partie 3 — Une restauration garde l'état d'aujourd'hui

### Le principe

Une restauration ramène **les données de l'unité** à une date. Elle ne ramène pas ce qui décrit **ce
serveur et ce qui s'y est passé** : la liste de ses sauvegardes et leurs mots de passe, ses réglages
hors site, son journal, ses tâches. Ces éléments sont **mis de côté avant que la base soit remplacée,
puis remis tels quels** une fois le schéma restauré à jour — sur le modèle de `StorageBindingSnapshot`
(#832), pour la restauration ordinaire, la restauration portable et « Configuration seule ».

### La mise de côté

- Juste avant de remplacer la base — **après** la copie de sécurité, pour que sa ligne en fasse
  partie —, un `RestoreStateSnapshot` (ou un nom aligné sur ceux de #832) écrit dans `storage/temp`,
  mode 0600 :
  - les lignes `backups` et les lignes `files` qu'elles référencent ;
  - les lignes `event_log` ;
  - les réglages `remote_backup_*` (destination, génération de la phrase, génération notée, et tout
    autre réglage hors site que tu trouves) ;
  - pour la restauration portable seulement : les secrets `backup_password_{id}` et
    `remote_backup_passphrase`, dans le dépôt que `restorePortable()` tient déjà à l'écart
    (`portable_secrets_hold`), jamais en clair dans ce fichier.
- Son chemin suit la charge utile de reprise, à chaque passe. Il est **supprimé à chaque sortie** :
  succès, retour arrière, migration en échec.
- Le journal peut être gros : écris et relis le fichier en continu, sans tout charger en mémoire.

### La remise en place

Elle tourne **sur la passe de reprise, après la migration et après la réconciliation de #832, et avant
la purge de rétention** de `finishRestore()` — sauf l'annulation des tâches (point 5), qui ne peut pas
attendre cette passe.

1. **La liste des sauvegardes est celle d'aujourd'hui.** Les lignes `backups` restaurées sont
   remplacées par celles mises de côté, **avec les mêmes identifiants** — c'est ce qui garde les mots de
   passe attachés à la bonne sauvegarde. Les lignes `files` des sauvegardes restaurées sont retirées ;
   celles mises de côté sont réinsérées, avec leur identifiant s'il est libre dans la base restaurée,
   sinon avec un nouvel identifiant, et `backups.file_id` / `db_dump_file_id` suivent. **Une ligne
   `files` d'un fichier de l'unité n'est jamais touchée.** `requested_by` passe à `NULL` si le compte
   n'existe pas dans la base restaurée.
2. **Les mots de passe des sauvegardes sont ceux d'aujourd'hui.** Restauration ordinaire :
   `secrets.enc` n'est pas remplacé, rien à faire. Restauration portable : les `backup_password_{id}`
   d'aujourd'hui remplacent ceux de l'archive, et ceux de l'archive qui ne correspondent à aucune
   sauvegarde gardée sont retirés. **Jamais un mot de passe ne reste attaché à l'identifiant d'une autre
   sauvegarde** : un test le fixe.
3. **Les réglages hors site sont ceux d'aujourd'hui.** Restauration ordinaire et « Configuration
   seule » : les réglages mis de côté sont réécrits ; la phrase, dans `secrets.enc`, n'a pas bougé.
   Restauration portable : **si ce serveur avait déjà une destination hors site**, son jeu complet est
   gardé (destination, phrase, génération, génération notée) ; **sinon**, c'est celui de l'archive. La
   phrase et sa génération voyagent toujours ensemble. La destination désigne un emplacement de
   stockage : si, après la réconciliation de #832, il n'existe plus, la destination est vidée et
   l'alerte existante le signale.
4. **Le journal est celui d'aujourd'hui.** Les lignes `event_log` restaurées sont remplacées par celles
   mises de côté, suivies de celles écrites depuis le remplacement de la base (la restauration elle-même)
   : rien ne se perd, l'ordre reste chronologique. **Les identifiants se télescopent** : le dump remet
   l'`AUTO_INCREMENT` de la base restaurée, et les lignes écrites pendant la restauration prennent des
   identifiants déjà portés par des lignes mises de côté. D'où l'ordre : retenir, dans la passe du
   remplacement et aussitôt celui-ci fait, le plus grand identifiant de la base restaurée (il suit la
   charge utile de reprise) ; à la remise en place, lire les lignes au-delà — celles de la restauration
   —, vider la table, réinsérer les lignes mises de côté **avec leurs identifiants**, puis les lignes de
   la restauration **avec de nouveaux identifiants**, à la suite. `user_account_id` est gardé tel quel s'il existe dans
   la base restaurée, sinon `NULL` (contrainte de clé étrangère). Une ligne écrite il y a peu peut ainsi
   désigner un compte dont l'identifiant a changé de sens entre les deux dates : en pratique un compte
   suit une adresse e-mail et reste la même personne ; ce risque résiduel est accepté et noté dans le
   docblock.
5. **Les tâches repartent d'un état propre.** Toute ligne `scheduled_actions` en attente ou en cours
   ramenée par la sauvegarde est passée à `canceled`, et le journal reçoit un événement qui les compte
   par tâche (sans charge utile). **Cette annulation se fait dans la passe même du remplacement,
   aussitôt la base remplacée et avant `scheduleMigrationResume()`**, pas sur la passe de reprise : à la
   passe suivante, `SchedulerRunner::processOverdue()` prend toutes les lignes dues
   (`SchedulerRepository::claimOverdue()`), sans priorité pour la reprise — une tâche ramenée (un envoi
   hors site, une sauvegarde) s'exécuterait donc contre un schéma ni migré ni réconcilié avant d'être
   annulée. La ligne de reprise, créée juste après, n'est pas concernée ; un retour arrière vers la copie de
   sécurité, qui remet les tâches d'aujourd'hui, n'annule rien. Les chaînes
   récurrentes sont ré-amorcées par les `ensureScheduled()` existants (`SchedulerService::seed()`),
   d'après les réglages restaurés ; vérifie qu'elles le sont dès la passe suivante et pas seulement à la
   prochaine requête web, sinon amorce-les explicitement.

- **Toute donnée gardée chiffrée avec la clé du site** doit rester lisible après une restauration
  portable, qui installe la clé de l'archive : vérifie-le pour chaque élément gardé. Un test le fixe.
- Le journal reçoit `restore_state_kept`, avec des compteurs (sauvegardes, entrées de journal, tâches
  abandonnées), sans chemin ni nom.
- **Une remise en place qui échoue est journalisée** (`restore_state_keep_failed`) **et n'annule pas**
  une restauration réussie par ailleurs — même règle que pour #832. L'avertissement de fin de
  restauration le dit.
- **Pas concernés**, comme pour #832 : l'assistant d'installation et le retour arrière d'une mise à jour.

### Un envoi hors site en cours

- **Avant la copie de sécurité**, la restauration interrompt un envoi hors site en cours : la tâche
  `SendRemoteBackupHandler` vivante est annulée, son archive locale (`archive_path`) supprimée, l'envoi
  partiel abandonné sur la destination (`ResumableUploadBackend::discardPartial($remote_name)`), et le
  journal le dit. Une seule passe cron tourne à la fois (`CronPassLock`) : vérifie qu'aucun envoi ne peut
  tourner pendant la restauration.
- **Juste après une restauration réussie**, si une destination hors site est configurée, un envoi hors
  site est programmé tout de suite, pour qu'une copie de l'état restauré parte sans attendre la semaine.

### La copie de sécurité, et « Annuler cette restauration »

- La copie prise avant une restauration a son propre type, **`auto_restore`**, libellé **« Avant
  restauration »**. `auto_reset` (« Avant réinitialisation ») reste celui de la réinitialisation.
  Ajoute la valeur à l'`ENUM` de `backups.type` dans `schema/core.sql`, et partout où la famille d'un
  type est décidée (`BackupFamily`, `BackupSafetyNet`, la rétention, `purgeAfterCreating()`, les
  libellés) ; `BackupFamilyCoverageTest` le vérifie.
- Sa ligne porte la note **« Copie de sécurité prise avant la restauration du 11 octobre 2026 à
  10 h 12. »**, déduite de son type et de sa date — pas de colonne.
- **La plus récente** de ces copies porte un bouton **« Annuler cette restauration »**, qui ouvre la
  confirmation de la maquette (« Le site reviendra exactement à son état du … », « Ce qui a été fait sur
  le site depuis la restauration sera perdu. », mot-clé RESTAURER). C'est une restauration ordinaire de
  cette copie : elle prend elle-même une copie de sécurité, donc elle s'annule à son tour.
- Après une restauration terminée, la page affiche le message de la maquette (« Restauration terminée.
  Le site est revenu à la sauvegarde du … »), qui renvoie à cette copie.
- **La purge de rétention qui suit ne supprime jamais la copie de la restauration qui vient de se
  faire.** Un test le fixe.

---

## Partie 4 — Les copies hors site

### La carte « Copies hors site »

- Nouvelle carte de « Sauvegardes récentes », sous la liste. Elle **se charge d'elle-même** à
  l'ouverture de la page, sans la bloquer : la page s'affiche, puis un script appelle
  **`GET /config/maintenance/remote-backup/archives`** (superadmin) qui renvoie le fragment de la carte.
- Les copies sont **lues sur la destination** (`RemoteRetention::listArchives()`), jamais déduites de
  la base. Pour chacune : date, taille, et l'un de deux badges, déduits de la génération lue dans son nom
  (`nameFor()`), sans rien télécharger :
  - **« Lisible avec la phrase actuelle »** — génération égale à celle en vigueur ;
  - **« Ancienne phrase de passe »** — toute autre, ou un nom dont la génération ne se lit pas.
- Sous le titre, l'emplacement réel, avec le libellé que l'écran Stockage donne à cet emplacement
  (« Google Drive — Mon Drive / ScoutMagic »).
- Sous la liste : « Sur une installation neuve, téléchargez une copie depuis Google Drive et déposez-la
  dans « Restaurer une sauvegarde », avec la phrase de passe notée. » — avec le nom du service réel.
- États de la maquette : **lecture en cours**, **aucune destination choisie** (renvoi vers « Sauvegarde
  automatique »), **destination injoignable** (message et bouton « Réessayer » ; les copies déposées ne
  sont pas touchées).

### Restaurer une copie hors site

- Chaque copie a un bouton **« Restaurer »**, qui ouvre le dialogue de la maquette : les trois étapes
  (rapatrier, prendre une copie de sécurité, revenir à la date), mot-clé RESTAURER.
  - **Phrase actuelle** : rien d'autre à saisir — le site la connaît.
  - **Ancienne phrase** : un champ « Phrase de passe de cette copie », et la phrase « La phrase est
    vérifiée une fois la copie rapatriée, avant de toucher au site. Si elle est fausse, vous pourrez la
    ressaisir sans tout rapatrier à nouveau. »
- **`POST /config/maintenance/remote-backup/restore`** (superadmin, CSRF) reçoit le **nom** de la copie.
  Le serveur **n'accepte qu'un nom présent dans la liste lue sur la destination** et reconnu par
  `RemoteRetention::isArchive()` : jamais une clé arbitraire. Une phrase saisie voyage chiffrée dans la
  charge utile, comme `encrypted_password` aujourd'hui.
- **Le rapatriement** est une tâche planifiée qui lit la copie **par morceaux sur plusieurs passages**
  (`RangeReadableBackend::getRange()`), comme l'envoi l'écrit — jamais `get()` d'un bloc, qui chargerait
  toute l'archive en mémoire. **Google Drive n'implémente pas encore `RangeReadableBackend`** : ajoute-le
  (l'API accepte l'en-tête `Range` sur `alt=media`). La place disque est vérifiée avant de commencer
  (`DiskBudget`) ; s'il en manque, refus immédiat, avec la place nécessaire.
- Une fois la copie entière, sa taille contrôlée, **la phrase est vérifiée** (`PortableArchive::open()`)
  avant de toucher au site. Puis la restauration suit le **parcours portable d'aujourd'hui**, comme un
  fichier téléversé : copie de sécurité, remplacement, reprise, et la partie 3.
- **Phrase fausse** : rien n'est modifié, l'archive rapatriée est **gardée** et la carte propose de
  ressaisir la phrase sans rapatrier à nouveau. Elle est supprimée dès que la restauration aboutit, dès
  qu'un autre rapatriement commence, ou au plus tard 24 heures après ; `PortableBackupLingerCheck` en tient
  compte.
- **Pendant le rapatriement**, la carte remplace ses boutons par la progression de la maquette
  (« 540 Mo sur 1,4 Go »), mise à jour à intervalle régulier ; la page peut être fermée. À la fin, le
  résultat s'affiche sur la page, et la personne qui a demandé est prévenue comme pour toute restauration
  (`RequesterNotice`).
- Un seul rapatriement à la fois ; une restauration déjà en cours le refuse, et inversement.

---

## Tests

PHPUnit, avec des lignes `backups` et `files` réalistes et de vrais fichiers sous un `storage` de test :

- **présence** : archive et dump présents ; ligne `files` disparue ; fichier absent ; fichier illisible ;
  ligne sans `file_id` jamais « Archive absente » ; statut `intact` mais archive retirée depuis → absente ;
- **actions** de chaque ligne selon le tableau de la partie 1, « Supprimer » toujours là, et suppression
  d'une ligne aux fichiers absents qui aboutit ; « Sur ce serveur » sur chaque ligne ; ligne `database`
  marquée « Export », absente du sélecteur et refusée par le contrôleur et le handler ;
- **route `presence`** : réponses, droits (superadmin seulement, refus pour tout autre rôle et pour un
  visiteur), identifiant inconnu ; `FileController` : 404 en français ;
- **refus tôt** : au contrôleur et dans le handler, **avant** toute copie de sécurité (aucune ligne
  créée) ; archive déclarée mais introuvable refusée au lieu d'une restauration de la base seule ;
- **« Configuration seule »**, en aller-retour MariaDB : membres et autres tables intacts ; `settings`
  restauré sauf les réglages hors site ; état d'activation des modules restauré, `installed_version`
  jamais ; dump contenant d'autres données refusé ;
- **état gardé**, en aller-retour MariaDB (`RestoreBackupRoundTripTest`, toutes les passes de reprise),
  pour la restauration ordinaire et la portable : liste des sauvegardes identique, mêmes identifiants,
  copie de sécurité présente ; ligne `files` d'une sauvegarde dont l'identifiant est pris par un fichier
  de l'unité → nouvel identifiant, fichier de l'unité intact ; mots de passe gardés et jamais attachés à
  une autre sauvegarde ; réglages hors site gardés, et la règle portable avec et sans destination ;
  journal d'aujourd'hui suivi des lignes de la restauration, sans collision d'identifiant même quand
  la restauration a écrit sur des identifiants mis de côté, compte absent → `NULL` ; tâches ramenées
  annulées et comptées dans la passe du remplacement — aucune ne s'exécute à la passe de reprise —,
  chaînes récurrentes ré-amorcées ; données gardées lisibles après installation de
  la clé de l'archive ; échec de la remise en place journalisé sans faire échouer la restauration ; fichier
  mis de côté supprimé dans tous les cas ; purge de rétention qui épargne la copie de sécurité ;
- **envoi hors site** : envoi en cours interrompu, archive locale supprimée, envoi partiel abandonné ;
  nouvel envoi programmé après une restauration réussie, pas après un échec ;
- **« Avant restauration »** : type, libellé, note, bouton sur la plus récente seulement ; l'annulation
  restaure cette copie et prend sa propre copie de sécurité ;
- **copies hors site** : liste lue sur un faux backend, badges selon la génération, nom illisible →
  « Ancienne phrase de passe » ; aucune destination ; destination injoignable ; restauration refusée pour
  un nom absent de la liste ; rapatriement par morceaux sur plusieurs passages, place disque insuffisante
  refusée ; phrase fausse → rien modifié, archive gardée, nouvelle saisie sans nouveau rapatriement ;
  archive supprimée dans chacun des trois cas ; `getRange()` de Google Drive ; droits des routes ;
- **Vitest** : le clic sur « Télécharger » interroge la présence, suit le lien si l'archive est là, ne le
  suit pas et affiche le message sinon, suit le lien si la requête échoue, en mode standalone comme dans
  un onglet ; chargement de la carte hors site et ses états ;
- **Playwright mobile** (`tests/e2e/`), à 320 px : les boutons d'une ligne sur une seule rangée, sans
  débordement horizontal ; une ligne aux fichiers absents n'offre que le mot de passe, s'il est gardé, et « Supprimer ».

---

## Écarté, explicitement

- **Inscrire les copies hors site dans `backups`** : elles restent lues sur la destination.
- **Tester la phrase d'une copie hors site avant de la rapatrier**, par une lecture partielle à
  distance : une lecture différente par type de destination, pour quelques minutes gagnées dans un cas
  rare.
- **Garder les anciennes phrases de passe** sur le serveur : une copie d'une ancienne génération se
  restaure avec la phrase notée à l'époque.
- **Supprimer automatiquement** une ligne dont les fichiers ont disparu, ou des fichiers sans ligne ;
  **balayer `storage/maintenance`** pour réinscrire des fichiers que rien ne connaît.
- **Recalculer les empreintes** à l'affichage : la présence suffit, l'intégrité reste la tâche
  quotidienne.
- **Figer le nom de l'auteur** de chaque entrée du journal : l'identifiant du compte suffit.
- **Changer le téléversement** d'un fichier dans « Restaurer une sauvegarde », y compris d'une archive
  qui ne contient que la base : il suit le parcours d'aujourd'hui.
- **Se prononcer sur la cause de l'incident n° 67** : le rapport la dit non démontrée.
