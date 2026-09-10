# Chantier — Exigences opérationnelles : disque, alertes, sauvegardes

Journal d'implémentation du document de chantier « Exigences
opérationnelles » (itérations IT-01 à IT-09). Une section par itération :
ce qui a été livré, les décisions prises en autonomie, les divergences
constatées entre le document de chantier et le dépôt réel, et ce qui a été
reporté. Même format que `docs/chantiers/aide-contextuelle.md`.

Le document de chantier lui-même est une pièce jointe et ne se trouve pas
dans le dépôt : ce journal est la seule trace qui y survit.

---

## IT-01 — Écrire les exigences non fonctionnelles

**Livré.** `docs/exigences-non-fonctionnelles.md` — cible de
dimensionnement, budget de rendu, objectifs de reprise, seuils d'alerte,
socle technique (PHP/MySQL, navigateurs, accessibilité). Référencé depuis
`CONTRIBUTING.md` (§ Before you start, point 5) et depuis l'en-tête
d'`ARCHITECTURE.md`, qui dit explicitement la répartition : l'un dit
comment le système est bâti, l'autre à quoi il doit tenir.

Documentation seule, aucun code, conformément au document de chantier.

**Décisions autonomes.**

1. **Le document est en anglais, son nom de fichier en français.** Le nom
   est celui que le document de chantier fixe ; le contenu suit ses deux
   voisins immédiats, `ARCHITECTURE.md` et `docs/quality-pipeline.md`,
   d'où il est référencé et avec lesquels il sera lu. `AGENTS.md`
   § Language ne tranche que le code et l'interface ; la documentation du
   dépôt est mixte (`README.md` et `docs/rental-guide.md` en français,
   destinés aux utilisateurs ; `ARCHITECTURE.md`, `AGENTS.md`,
   `docs/module-development.md`, `docs/quality-pipeline.md` en anglais,
   destinés aux contributeurs). Celui-ci est de la seconde famille.

2. **Deux seuils de plus que les quatre proposés**, pour les alertes que
   IT-09 devra publier : âge de la dernière sauvegarde distante réussie
   (10 jours / 3 jours) et occupation du quota distant (90 % / 80 %). Les
   écrire maintenant évite qu'IT-09 invente ses propres nombres hors de la
   page qui centralise les nombres. Aucun code ne les lit encore.

   Le seuil distant porte **les mêmes nombres que le seuil local**, et
   c'est le plus important des deux : dans le scénario dont parle
   réellement le RPO — le serveur n'existe plus — la vétusté de la copie
   hors site *est* la perte de données réalisée. Un premier jet écrivait
   21 jours, soit trois fois le RPO qu'il est censé garder ; corrigé en
   revue.

3. **Deux contraintes d'hôte chiffrées dans le budget de rendu** —
   `max_execution_time` à 30 s (jamais plus de 120 s) et 128 Mio de
   mémoire. Le document de chantier les invoque en prose dans IT-09 ; sans
   nombre opposable, « ça ne tient pas dans une requête » reste une
   opinion.

4. **Les valeurs de dimensionnement sont déduites, pas inventées** : 500
   membres par année et 5 années viennent du docblock de
   `Core\Member\Service\MemberSearchService` (« quelques centaines de
   membres », « cinq années scoutes, c'est cinq fois le travail AES ») ;
   178 membres est ce que contiennent réellement les exports **commités**
   de `tests/fixtures/reference-dataset/` : 176 / 178 / 178 `Tiers`
   distincts, comptés dans les trois fichiers, ce que confirme le
   `README.md` du jeu de données lui-même (« Membres actifs |
   176 / 178 / 178 »).

   Un premier jet écrivait 180, en lisant dans
   `docs/chantiers/reference-dataset.md` la ligne « Effectifs
   reconstruits » — qui décrit une instance jetable rebâtie par le
   builder, pas les fichiers commités. Ce journal-là n'a pas tort ; c'est
   la ligne qui avait été mal choisie. La citation renvoie désormais au
   `README.md` du jeu de données, qui en est le manuel et qui est tenu à
   jour.

   **Le volume de galerie a dû être refait.** Un premier jet écrivait
   3 Gio, en multipliant un nombre de médias par la taille d'une photo
   *envoyée*. La galerie conserve **trois rendus** par photo
   (`Modules\Gallery\Service\ImageProcessingService` : 3000 px q90,
   1200 px q85, 300 px q80) et `ProcessPhotoHandler` ne supprime que
   l'original — soit environ 2 Mio stockés par photo, et 8 Gio sur cinq
   ans.

   Le total `storage/` passe de 4 à 10 Gio, et **la correction ne portait
   pas que sur la galerie** : la part non-galerie n'avait jamais été
   chiffrée, elle valait 1 Gio implicitement, et elle en vaut 2. Elle est
   désormais une ligne à elle dans le tableau — une dizaine d'archives
   conservées à ~150 Mio pièce (une archive `full_no_gallery` embarque
   `vendor/`, qui la domine) plus cinq ans de pièces jointes — pour que le
   total soit une somme visible plutôt qu'un chiffre à croire. Le
   déséquilibre arithmétique du premier jet (+5 Gio de galerie, +6 Gio de
   total) a été relevé en revue.

   10 Gio dépasse ce que donne l'hébergement mutualisé le moins cher :
   c'est écrit noir sur blanc dans le document, avec ses trois issues
   (payer plus, garder moins d'années en ligne, ou passer la galerie sur
   `S3StorageBackend`). La vidéo est exclue du chiffre et signalée à
   part — `gallery_max_video_upload_mb` vaut 2 048 par défaut, donc une
   douzaine de vidéos de camp pèse plus que cinq ans de photos.

**Divergences constatées entre le document de chantier et le dépôt.**

1. **La fréquence par défaut des sauvegardes automatiques contredit les
   seuils d'alerte proposés.** `backup_auto_frequency` vaut `monthly` par
   défaut (`public/index.php`), et le seuil « âge de la dernière
   sauvegarde réussie » déclenche à 10 jours. Sur une installation
   laissée dans sa configuration par défaut, cette alerte serait donc
   **déclenchée en permanence** dès la mise en service d'IT-03 — c'est-à-
   dire inutile, et exactement le mécanisme d'extinction que la décision
   D1 cherche à éviter.

   L'objectif de reprise écrit en IT-01 (RPO 7 jours) tranche dans
   l'autre sens : une sauvegarde planifiée doit être **hebdomadaire**.
   Changer le défaut est du code, donc hors du périmètre d'IT-01. **C'est
   IT-03 qui portera ce changement**, dans la PR qui allume l'alerte —
   c'est la seule qui puisse le faire sans livrer une alerte fausse.
   Ouvert comme issue #286, ce qu'exige `AGENTS.md`.

   **L'écart est écrit sur la page d'exigences elle-même**, et pas
   seulement ici. Relevé en revue : la page se lit toute seule et se cite
   telle quelle, donc une phrase au présent disant que le RPO « fixe » le
   défaut à hebdomadaire y ferait conclure que le défaut livré correspond
   déjà. Les deux endroits qui l'affirmaient disent maintenant l'état réel
   et nomment l'issue.

2. **`ARCHITECTURE.md` ne comportait aucun renvoi vers un document
   d'exigences non fonctionnelles** parce qu'il n'en existait aucun : le
   renvoi ajouté est une création, pas une mise à jour.

**Reporté.** Rien pour cette itération. `axe-core` dans la suite
Playwright reste explicitement hors chantier (section « Reporté » du
document de chantier), désormais adossé à un niveau d'accessibilité
énoncé.

---

## IT-02 — Le budget disque

**Livré.** `Core\Storage` : `DiskBudget` (mesure, quota déclaré,
`ensureRoom()`), `StorageUsage` (l'objet de valeur que l'écran rend),
`DirectorySize` (la marche récursive, liens symboliques compris ou non
selon l'appelant), `ByteFormatter` (format et lecture d'un nombre
d'octets en français), `InsufficientDiskSpaceException`. Le réglage
`storage_quota_bytes`, vide par défaut. L'encart « Espace disque » en
tête de la section Sauvegardes de Configuration > Maintenance — la
seule modification d'interface de cette itération, comme le document de
chantier le demande. `ARCHITECTURE.md` §8.98, le sujet d'aide
`docs/help/sauvegardes.md`, et 55 tests.

**Les points d'appel de `ensureRoom()`** : `BackupService` (dump et
archive, chacun dimensionnant sa propre écriture),
`Task\InstallUpdateHandler` (l'espace de travail de l'artefact, avant
sa propre sauvegarde de sécurité), `Core\File\UploadHandler` (donc
l'envoi galerie, et toutes les autres surfaces d'envoi avec lui),
`Core\File\ChunkedUploadStore` (par fragment d'archive de
restauration), et le CSV déposé de l'import Desk.

**Décisions autonomes.**

1. **La mesure de `storage/` est mise en cache 15 minutes**, dans
   `storage/core/disk-usage.json`. Sans cela, chaque envoi de photo
   parcourait tout `storage/` — des milliers de fichiers dès qu'une
   galerie existe — pour un nombre qui bouge de quelques mégaoctets par
   heure. C'est le budget de rendu d'IT-01 (§2) qui tranche. Deux
   conséquences voulues : l'écran appelle `measureNow()` et affiche
   toujours un chiffre frais (rapporter ce nombre EST son travail), et
   `ensureRoom()` ne parcourt rien du tout quand aucun quota n'est
   déclaré, puisque `disk_free_space()` suffit alors à répondre.

   Dans un fichier plutôt que dans une ligne `settings` : une tâche de
   fond doit pouvoir le rafraîchir sans dépendre d'une ligne qu'un autre
   point d'entrée aurait enregistrée (`public/cron.php` n'enregistre que
   `cron_last_run`).

2. **Une marge de sécurité de 50 Mio** exigée en plus de toute
   estimation. Une estimation est une prédiction, un système de fichiers
   n'en est pas une : sans marge, l'écriture qui tombe exactement sur la
   place restante est celle qui tronque.

3. **Le réglage accepte « 10 Go » autant qu'un nombre d'octets.** Le nom
   `storage_quota_bytes` est celui que le document de chantier fixe, et
   l'octet reste l'unité canonique ; mais personne ne lit son contrat
   d'hébergement en octets, et taper 10737418240 est une faute de
   recopie qui attend son heure. `ByteFormatter::parse()` lit les deux.

4. **La bucket « pièces jointes et divers » est un reste, jamais une
   somme.** `total − galerie − sauvegardes − temp`. Un module qui
   ajoute un dossier de stockage demain apparaît donc dedans au lieu de
   disparaître du total.

5. **`ensureRoom()` ne bloque pas quand rien n'est connu.** Un hébergeur
   qui ne déclare pas de quota et ne rapporte pas son volume n'a pas dit
   que le disque était plein ; refuser toute sauvegarde là-dessus
   casserait précisément ce que ce garde-fou protège. C'est le repli que
   le document de chantier demande de documenter, et c'est pour cela que
   l'écran dit quelle mesure il a pu faire.

6. **`Modules\Gallery\Service\DiskSpace::format()` délègue désormais
   à `Core\Storage\ByteFormatter`.** Deux orthographes de « 1,5 Go »
   sur deux écrans de configuration du même site, c'est exactement la
   dérive que personne ne remarque et que tout le monde lit. Les tests
   existants de la galerie épinglent le comportement déplacé.

7. **`DirectorySize` prend un paramètre `$followLinks`, plutôt qu'un
   compromis.** Une mesure de quota ne doit pas suivre un lien ; une
   archive doit le faire, sous peine de ne pas contenir un fichier que
   l'hébergeur a lié ailleurs — et c'est ce que `BackupService` faisait
   déjà. Les deux appelants veulent des réponses réellement
   différentes.

8. **Les deux nombres écrits en dur sont mesurés, pas devinés.** Le
   plancher d'espace de travail d'une mise à jour vaut 256 Mio, adossé à
   l'artefact réel de la v1.0.41 : 19,8 Mio compressés, 56,6 Mio
   décompressés, 9 527 entrées — donc environ 77 Mio pour le
   téléchargement et son extraction ensemble, et un peu plus de trois fois
   cette valeur en marge. Un premier jet écrivait 512 Mio en justifiant
   par « une centaine de Mo compressés », chiffre jamais vérifié et faux
   d'un facteur cinq. Le nombre de points d'appel qui attrapent
   `UploadException` est de dix-huit, recompté sur le dépôt, pas seize.

9. **`ChunkedUploadStore` est un cinquième point d'appel**, non listé
   par le document de chantier. C'est la plus grosse écriture du site
   (une archive de restauration peut atteindre 500 Mo) et elle grandit
   fragment par fragment : remplir le quota à mi-chemin y laisse une
   archive partielle qu'une restauration lira ensuite comme corrompue.

**Divergences constatées entre le document de chantier et le dépôt.**

1. **« Import Excel » ne correspond à aucune écriture disque.**
   `Modules\MassMail\Service\AudienceImportService` — le seul import
   Excel de ce genre — lit le fichier temporaire que PHP a déjà écrit
   pour l'envoi, puis le supprime dans un `finally` ; il n'écrit rien
   sous `storage/`. Même chose pour les imports de campagne et de
   facture, qui font `file_get_contents($file['tmp_name'])`. Un
   `ensureRoom()` y contrôlerait une écriture qui n'a pas lieu — et
   l'écriture qui a lieu (celle de PHP dans `upload_tmp_dir`) est déjà
   terminée quand le code du site s'exécute. Rien fait, volontairement.

2. **`BackupService` n'avait aucun moyen de dimensionner ses écritures.**
   Les deux estimations sont donc nouvelles : `estimateDatabaseDumpBytes()`
   lit `information_schema` (avec un plancher quand le serveur ne répond
   pas — la base de test est en SQLite et n'en a pas), et
   `estimateFileBackupBytes()` somme l'arbre qu'elle s'apprête à lire.
   Cette seconde est délibérément une **sur**-estimation : l'archive est
   compressée et sortira plus petite, et pour une question « est-ce que
   ça tient ? », se tromper vers le haut est le bon sens de l'erreur.

3. **La liste des exclusions d'archive était écrite une fois, dans la
   boucle d'archivage.** Elle est devenue `excludedArchivePrefixes()`,
   partagée avec l'estimation. Deux copies auraient fini par diverger,
   et le sens qui fait mal est le silencieux : une estimation qui omet
   ce que l'archive écrit annonce « ça tient » à propos d'une écriture
   qui ne tient pas.

**Cinq défauts relevés en revue, tous réels, tous corrigés.** Ils
partagent une même racine — le cache de quinze minutes de `DiskBudget`
gèle la mesure, donc plusieurs contrôles successifs se comparent tous à
la même ligne de base — et c'est la revue qui l'a vue avant nous.

1. **`ChunkedUploadStore` ne contrôlait que la taille du fragment.** Le
   premier fragment peuple le cache ; tous les suivants comparaient donc
   les mêmes huit mégaoctets à la même marge, et une archive d'un
   demi-gigaoctet passait entièrement le quota — exactement le
   dépassement en cours d'écriture que ce garde-fou refuse. Il contrôle
   désormais `$offset + taille du fragment`, c'est-à-dire ce que pèsera le
   fichier assemblé, qui est le bon nombre face à une ligne de base prise
   avant que rien n'existe.

2. **L'archive avait hérité de la clémence de la mesure.** Avant cette
   itération, `addDirectoryToZip()` utilisait un itérateur nu : un
   sous-dossier illisible faisait échouer la sauvegarde bruyamment.
   Partagé avec `DirectorySize`, il s'est mis à le sauter en silence — une
   sauvegarde de sécurité pouvait donc se terminer avec succès en omettant
   tout un sous-arbre, découvert le jour d'un retour en arrière. Les deux
   booléens (`followLinks`, clémence) allaient toujours ensemble : ils
   sont devenus **une** énumération `DirectoryWalk` (`Measurement` /
   `Archive`), pour que l'erreur ne soit plus faisable par omission.

3. **L'estimation dimensionnait six arbres pour une archive qui en écrit
   quatre.** `createFullBackup()` exclut délibérément `vendor` et
   `schema` ; l'estimation les sommait quand même, gonflant le contrôle de
   plusieurs centaines de mégaoctets et pouvant refuser une sauvegarde qui
   tenait. `FULL_BACKUP_TOP_LEVEL` est maintenant une constante nommée que
   la marche ET l'estimation lisent — même leçon que
   `excludedArchivePrefixes()`, prise par l'autre bout.

4. **La seule route synchrone n'attrapait pas le refus.**
   `InsufficientDiskSpaceException` est un **frère** de `BackupException`,
   pas un sous-type : sur un quota plein, elle échappait au `catch`, la
   ligne `backups` restait bloquée à `in_progress` pour toujours et
   l'administrateur recevait un 500 au lieu de la phrase qui dit quoi
   faire.

5. **`InstallUpdateHandler` faisait trois contrôles indépendants.**
   L'espace de travail, le dump et l'archive, chacun contre la même
   mesure gelée : les trois passaient séparément alors que leur somme ne
   tenait pas. C'est précisément le piège que `createFullBackup()`
   documente pour ses deux moitiés — « les contrôler un par un laisserait
   le dump réussir et l'archive manquer de place à mi-chemin » — et une
   mise à jour en a trois. Un seul contrôle, sommé.

**Un sixième, relevé sur la correction du premier.** Le contrôle cumulé
tenait sur une prémisse fausse : que la ligne de base était gelée pour
toute la durée de l'envoi. Elle ne l'est pas. `availableBytes()` lit
`disk_free_space()` **à chaque appel**, et sans quota déclaré — le
défaut livré — c'est là toute sa réponse ; avec un quota, l'autre moitié
re-parcourt `storage/` dès que la mesure mise en cache expire, ce qu'un
envoi d'un demi-gigaoctet dépasse largement. Dans les deux cas la
lecture a déjà le fichier `.part` déduit d'elle-même, donc lui opposer
la taille cumulée facturait `$offset` **deux fois** : une fois parce
qu'il avait déjà réduit la place libre, une fois parce qu'on l'ajoutait
à la demande. Le refus tombait donc sur des envois qui tenaient, et
précisément sur les hébergements presque pleins pour lesquels ce
garde-fou existe.

Corrigé en épinglant **une** lecture avant le premier fragment et en
lui opposant le fichier assemblé (`DiskBudget::ensureRoomAgainst()`).
L'épingle vit dans un fichier voisin du `.part`, parce que chaque
fragment arrive dans une requête distincte et que rien d'autre ne
survit entre elles. Sans épingle — envoi repris après une purge, dossier
temporaire en lecture seule — on retombe sur le contrôle vivant du seul
fragment en main : plus faible, jamais faux, et jamais un double
comptage. Le test de non-régression a été vérifié dans les deux sens :
il échoue sur l'ancien comportement, il passe sur le nouveau.

**Trois autres, du même tour de revue, et le premier est le plus
gênant.**

7. **Le quota déclaré ne payait que `storage/`.** Le réglage demande
   l'allocation « telle qu'elle figure sur votre contrat » — c'est le
   compte d'hébergement entier, `vendor/` et le code compris, soit deux
   cents mégaoctets et quelques dans ce projet, plusieurs fois la marge de
   sécurité de 50 Mio. N'en soustraire que `storage/` sur-estimait donc la
   place restante de tout le poids de l'application, dans la seule
   direction qui laisse une écriture se tronquer, et sous-estimait
   l'occupation affichée d'autant. `StorageUsage::quotaChargedBytes()`
   lit désormais l'installation entière — le dossier parent de
   `storage/`, la convention que `BackupService` utilise déjà pour son
   `$basePath` — et la ventilation porte la part de l'application comme
   une ligne à elle plutôt que comme une différence invisible.

8. **`DirectoryWalk::Archive` promettait de suivre les liens et ne le
   faisait pas pour les dossiers.** `RecursiveDirectoryIterator` refuse
   de descendre dans un dossier lié sans
   `FilesystemIterator::FOLLOW_SYMLINKS`, quoi que renvoie le filtre :
   l'entrée remontait en feuille, échouait au test `isFile()` et
   disparaissait. La promesse tenait donc pour les fichiers liés — d'où
   un test qui passait — et échouait en silence pour le cas qui la
   justifie : un hébergement où `storage/gallery` pointe sur un autre
   volume, dont l'archive ne contenait rien et dont l'estimation de
   taille était d'accord avec le mauvais chiffre. Suivre les liens rend
   les cycles atteignables et l'itérateur de PHP n'en détecte aucun :
   chaque dossier est donc entré une seule fois, par chemin résolu.

9. **Quatre gestionnaires prenaient la paire dump + archive sans la
   réserver.** `createFullBackup()` documente le piège depuis toujours —
   « les contrôler un par un laisserait le dump réussir et l'archive
   manquer de place à mi-chemin » — et `AutoBackupHandler`,
   `FullResetHandler`, `ResetSettingsHandler` et `RestoreBackupHandler`
   y sont tombés pour la même raison : le contrôle interne de chaque
   écriture a l'air complet tout seul, et les deux lisent la même mesure
   mise en cache. `BackupService::ensureRoomForDumpAndArchive()` est
   cette réservation, en un seul endroit, déclarée sur l'interface pour
   qu'un remplaçant ne puisse pas l'omettre, et prenant l'écriture
   supplémentaire de l'appelant là où il y en a une. Le cas le plus dur
   est `FullResetHandler` : l'étape 4 efface `storage/` et l'étape 2 vide
   toutes les tables, donc une sauvegarde tronquée est la seule copie
   d'un site qui n'existe plus. `BackupPairReservationTest` vérifie sur
   le source qu'aucun fichier ne prend la paire sans la réserver — c'est
   le genre d'erreur qu'une revue attrape une fois et qu'un cinquième
   gestionnaire refait l'année suivante.

**Quatre de plus, dont deux d'écriture et deux de fond.**

10. **Sept commentaires de section en français dans les tests.** La règle
    d'`AGENTS.md` est sans exception — code et commentaires en anglais, le
    français pour l'interface — et c'est la première des trois que
    `CLAUDE.md` signale comme piégeuses. Traduits.

11. **`|capitalize` détruisait toutes les unités.** Le gabarit assemblait
    la ventilation avec `join(' · ')|capitalize`, et le filtre de Twig
    signifie « majuscule initiale **et minuscules ensuite** » : la ligne
    sortait « Galerie 1,9 go · sauvegardes 300 mo », trois lignes sous un
    `storageLabel` qui écrit « 1,9 Go » correctement, et dans la classe
    même dont le propos est que les deux écrans de configuration « ne
    dérivent pas vers deux orthographes de 1,5 Go ».
    `StorageUsage::breakdownSentence()` assemble désormais la phrase là où
    elle est testable, ne lève que la première lettre, et ne rend rien
    plutôt qu'un point isolé quand il n'y a rien à ventiler.

12. **Deux écritures distinctes dans la même fenêtre de cache étaient
    approuvées sur la même mesure.** Troisième visage du même défaut : rien
    ne rafraîchit `disk-usage.json` quand des octets atterrissent, donc
    deux envois galerie ou deux sauvegardes à quelques minutes lisent
    l'occupation d'avant la première. `notePendingWrite()` ne compte pas
    les octets — il faudrait que chaque point d'écriture les rapporte, et
    deux contrôles qui dimensionnent la même écriture les compteraient deux
    fois — mais compte juste assez pour décider **quand refaire la
    marche** : dès que les approbations atteignent la marge de sécurité, le
    cache est jeté. Compter deux fois coûte donc une marche anticipée et
    jamais un refus. Quota déclaré seulement : sans lui la lecture est
    vivante et se corrige d'elle-même.

13. **Le constructeur de l'itérateur échappait à sa propre garde.**
    `RecursiveDirectoryIterator` ouvre le dossier **dans son
    constructeur** et y lève `UnexpectedValueException`. Construit
    au-dessus du `try`, derrière un `is_dir()` qui avait déjà répondu, il
    échappait au traitement documenté pour lui : sous `Measurement`, un
    dossier illisible devenait un 500 non rattrapé sur la page
    Maintenance — et sur toutes les surfaces d'envoi dès qu'un quota est
    déclaré —, c'est-à-dire exactement ce qu'une mesure clémente existe
    pour éviter. Le `is_dir()` préalable n'aurait jamais pu fermer cela :
    entre le contrôle et l'ouverture il y a toujours une fenêtre. Il a donc
    été **supprimé** plutôt que rétréci, et la construction est passée dans
    la garde, avec une distinction que le code ne faisait pas :
    **absent n'est pas illisible**. Un arbre qui n'est pas là ne pèse rien
    pour les deux intentions — `storage/gallery` sur un site sans galerie
    est le cas ordinaire ; seul un chemin qui *est* un dossier et n'a
    quand même pas pu être ouvert est la panne de permissions qu'une
    archive refuse de dépasser en silence.

14. **Suivre les liens ouvrait un contournement des exclusions —
    régression introduite par la correction n° 8, et la plus grave du
    chantier.** Les préfixes exclus étaient comparés au chemin textuel.
    Tant que les dossiers liés n'étaient pas parcourus, cela suffisait ;
    dès qu'ils le sont, `storage/link -> storage/keys` produit des entrées
    nommées `storage/link/master.key`, qu'aucun préfixe commençant par
    `storage/keys` ne peut faire correspondre. Or ce sont précisément ces
    préfixes qui tiennent `master.key` et `secrets.enc` **hors** de toute
    archive (`SECURITY.md` §11 : les secrets ne quittent jamais le serveur
    dans une sauvegarde, chiffrée ou non). Reproduit avant correction : la
    clé maîtresse sortait bien du filtre. Le chemin **résolu** est
    désormais comparé lui aussi, à des préfixes résolus une fois par
    marche et non par entrée, et la règle de frontière tient sur les deux —
    exclure `temp` n'exclut toujours pas `temperatures`.

    Leçon à consigner : une correction qui élargit ce qu'un parcours
    atteint doit être relue contre tout ce qui filtrait ce parcours. Le
    suivi des liens et la liste d'exclusions étaient corrects
    séparément.

15. **Neuf messages d'assertion en français**, traduits — même règle que
    les commentaires, relevée au tour précédent et appliquée trop
    étroitement la première fois.

16. **Deux points de construction sans budget disque, dont une racine de
    composition entière.** Le docbloc d'`UploadHandler` affirmait que
    « tous les points de construction du dépôt en passent un » ; c'était
    faux. L'écran de rafraîchissement manuel des boîtes mail dans
    `public/index.php` n'en passait pas, et `public/scheduler-bootstrap.php`
    — la seconde racine de composition, que personne n'avait ouverte — non
    plus. Le paramètre étant nullable et en dernier, l'oubli ne casse
    rien : il écrit simplement par-dessus un quota plein, en silence.

    Les deux sont corrigés, et le motif est fermé plutôt que réparé :
    `DiskBudgetWiringTest` vérifie sur le source qu'aucune construction de
    production d'`UploadHandler`, `ChunkedUploadStore` ou `BackupService`
    n'omet le budget, avec **une** exception nommée et motivée —
    `SetupController::reinstall()` fait son dump avant que l'application
    existe, donc sans `SettingService` pour lire un quota, et ce dump est
    la copie de secours prise avant une réinstallation : la refuser sur une
    lecture impossible détruirait ce qu'elle sauvait. Le test vérifie aussi
    que l'exception désigne encore quelque chose de réel et que le balayage
    atteint bien les deux racines.

    Rendre le paramètre obligatoire aurait été l'autre réponse : elle
    imposait un `, null` à quarante constructions de test pour fermer un
    trou qui n'existe que dans le câblage de production.

**Reporté.** La saisie du quota se fait sur la page générique
Configuration > Réglages, pas sur la page Maintenance : le document de
chantier borne l'interface de cette itération au seul encart de lecture.
Un champ de saisie à côté de l'encart serait plus direct ; il n'est pas
dans le périmètre et n'a rien de bloquant, l'encart nommant le réglage à
remplir.

---

## IT-03 — Les alertes opérationnelles

**Livré.** `Core\Alert` : la table `operational_alerts` et son dépôt,
`OperationalAlertService` (la machine à états D1), `AlertReading`,
`OperationalCheck`, `AlertThresholds` (les nombres d'IT-01 en code),
`AlertSurfaces`, six contrôles (`DiskUsageCheck`, `BackupAgeCheck`,
`MailDeliveryCheck`, `DevelopmentModeCheck`, `CronSilenceCheck`,
`HttpsCheck`), `OperationalAttentionProvider`,
`Task\RunOperationalChecksHandler` et `RequestBoundChecks`. Deux types de
notification, le sujet d'aide `alertes-operationnelles`,
`ARCHITECTURE.md` §8.99, et 38 tests.

**Corrige #286** : `backup_auto_frequency` passe de `monthly` à `weekly`.
C'est la PR qui allume l'alerte, donc la seule qui pouvait le faire sans
livrer une alerte fausse.

**Quatre constats de revue, tous réels — et le quatrième porte sur la
correction du deuxième.**

1. **Une lecture inconclusive effaçait le chiffre affiché.**
   `AlertReading::inconclusive()` porte une valeur vide, et le
   `recordValue()` de fin de méthode l'enregistrait sans condition : la
   première fois que l'hébergeur cessait de répondre, le point d'attention
   d'une alerte **toujours déclenchée** tombait de « Espace disque : 92 % »
   à « Espace disque » tout court — l'inverse exact de ce pour quoi cette
   valeur est stockée, et en silence. Un contrôle qui ne peut pas savoir
   doit laisser en place ce que le dernier qui savait avait écrit.

2. **`HttpsCheck` n'avait aucun écart.** `overTrigger: !$secure` et
   `underRearm: $secure` sont deux lectures complémentaires du même booléen
   de requête, donc un écart de zéro — alors que l'écart *est* le dessin.
   Rien ici ne force HTTP vers HTTPS (aucune redirection dans
   `public/.htaccess`, HSTS émis seulement une fois déjà sécurisé), donc un
   site qui répond sur les deux schémas alterne au gré des visiteurs, et ce
   contrôle tourne tous les quarts d'heure : déclenché, réarmé, déclenché,
   avec un courriel à chaque super-administrateur dans les deux sens.

   La correction apportée alors — exiger que l'observation et la
   déclaration `base_url` s'accordent dans les deux directions — était un
   vrai écart, et **elle était fausse**. Le constat 4 ci-dessous la
   remplace ; elle reste consignée ici parce que c'est la deuxième version
   de ce contrôle sur trois, et que la troisième ne se comprend qu'avec
   les deux premières.

   À noter, et cela vaut pour les trois versions : `DevelopmentModeCheck`
   a la même forme complémentaire et n'a pas le défaut, parce qu'il lit un
   **réglage** — qui ne change que quand un administrateur le change, et ne
   peut donc pas osciller entre deux requêtes. C'est la nature de la
   source, pas la forme du code, qui décide.

3. **L'alerte « cron muet » ne peut pas atteindre un administrateur
   absent.** `dispatch()` planifie push et courriel au lieu de les
   envoyer, et cette file est vidée par le cron — celui dont l'alerte
   annonce la mort. Les canaux hors site attendent donc derrière la panne
   qu'ils décrivent. La notification dans l'application et le point
   d'attention fonctionnent, et quelqu'un *est* sur le site puisque c'est
   une requête qui a fait tourner le contrôle : l'alerte est dégradée, pas
   cassée. Fermer le trou demande un chemin d'envoi synchrone que
   `NotificationService` n'a pas, ce qui n'est pas le sujet de cette
   itération — **issue #296**, et la limite est écrite dans le docbloc de
   `CronSilenceCheck` pour que personne ne croie que le courriel part. Le
   contournement évident, appeler `MailService` depuis `Core\Alert`, est
   exactement le raccourci qui survit à sa raison d'être.

4. **La correction du constat 2 ne pouvait ni se déclencher ni
   s'éteindre**, et c'est le constat le plus utile des quatre. Exiger que
   `base_url` ne dise **pas** `https://` pour déclencher rendait le
   contrôle aveugle au seul cas pour lequel la classe avait été écrite —
   un certificat expiré cette nuit sur un site qui déclare toujours,
   correctement, `https://`. Le docbloc de la classe décrivait ce
   scénario ; le code ne pouvait pas le voir. Et comme toute instance
   déclenchée avait dès lors `base_url` en `http://` par construction,
   tandis que le réarmement exigeait `https://`, réparer le certificat ne
   pouvait jamais éteindre l'alerte : seule l'édition d'un réglage le
   pouvait — un réglage que le conseil de l'alerte (« Activez le
   certificat HTTPS chez votre hébergeur ») ne mentionne pas. L'alerte
   était, en pratique, définitive.

   **L'écart est désormais du temps, pas une seconde lecture du même
   booléen**, et le contrôle reprend la forme de tous les autres : un
   évènement, et depuis combien de temps il a eu lieu. Déclencher, c'est
   avoir servi cette requête en clair — la condition entière ; un site qui
   confie un mot de passe au réseau ne devient pas acceptable parce qu'un
   réglage dit autre chose. Réarmer, c'est avoir servi cette requête en
   HTTPS **et** n'avoir rien vu en clair depuis
   `AlertThresholds::HTTPS_REARM_QUIET_HOURS` (24 h). Un site qui répond
   sur les deux schémas retampone sans cesse et ne se tait jamais : c'est
   la bonne réponse et non une réponse tolérée, puisqu'il confie toujours
   des mots de passe au réseau. Et l'administrateur qui répare son
   certificat éteint l'alerte en le réparant, sans rien avoir à éditer.

   **`base_url` n'est plus lu du tout, et l'abandonner n'a rien coûté.**
   Ce que fait une adresse déclarée, c'est y envoyer des gens ; les gens
   envoyés vers une adresse `http://` arrivent ici en requêtes claires,
   c'est-à-dire exactement ce que le contrôle mesure. La conséquence est
   observable, la cause n'a pas besoin de l'être.

   **Décision autonome : le contrôle écrit.** Il est le seul, et le seul
   qui doive l'être. `cron_last_run` existe pour que `CronSilenceCheck` le
   lise parce que `public/cron.php` le tamponne en tournant : l'évènement
   se consigne lui-même. Une requête servie en clair ne consigne rien —
   ni ligne, ni fichier, rien que le `$_SERVER` d'une requête déjà en
   cours de réponse — donc la trace doit être faite au seul moment où le
   fait existe. Une ligne de `settings` (`insecure_request_last_seen`)
   plutôt qu'un fichier-marqueur sous `storage/temp/`, alors que
   `RequestBoundChecks` et `DiskBudget` utilisent un fichier pour leur
   propre comptabilité de chemin de requête : ce qui tranche, c'est le
   coût de la perte. Perdre le marqueur du limiteur achète une évaluation
   de plus ; perdre ce tampon laisserait un site bi-schéma se réarmer trop
   tôt puis se redéclencher à la requête claire suivante — précisément
   l'oscillation que tout ce dessin existe pour empêcher. L'écriture coûte
   au plus une fois par quart d'heure, uniquement sur un site réellement
   servi en clair, et après `send()` et `session_write_close()`.

   Le réglage est enregistré à l'endroit où le contrôle est câblé, et non
   avec les autres réglages du démarrage — comme `public/cron.php`
   enregistre `cron_last_run` juste avant de l'écrire.

**Deux pages que le code contredisait.** Elles sont corrigées dans la même
PR, comme le chantier l'exige. `docs/exigences-non-fonctionnelles.md` §4
annonçait encore que `backup_auto_frequency` valait `monthly` et que
l'autre moitié de l'objectif de reprise « n'est pas encore livrée » —
c'était cette itération qui la livrait. Son tableau des seuils ne portait
par ailleurs ni la ligne des échecs d'envoi (5 sur 24 h), ni celle du
HTTPS, alors que `AlertThresholds` se présente comme l'endroit unique où
ce code et cette page se rencontrent : un seuil présent d'un seul côté est
la manière dont une exigence cesse d'en être une.

**Un mot réservé que seul MySQL réserve.** La colonne s'appelait
`last_value` ; MySQL 8 a refusé la table entière — `LAST_VALUE` y est un
mot réservé depuis 8.0.2 (la fonction de fenêtrage) et ne l'est **pas**
dans MariaDB. L'instruction passait donc sur le moteur de production, et
sur ce conteneur qui tourne MariaDB, pour échouer uniquement dans le
travail `test` de l'intégration continue : dix-neuf erreurs dans une seule
classe, toutes « syntax error near 'last_value' », après une suite
complète verte en local.

Renommée en `last_reading` plutôt qu'échappée par des accents graves : un
mot réservé entre guillemets fonctionne jusqu'au jour où quelqu'un écrit
le nom de la colonne dans une requête sans les mettre, et il n'y avait
aucune raison de garder la mine pour une table que rien n'avait encore
livrée.

`docs/quality-pipeline.md` ne décrivait que l'asymétrie inverse — « juste
sur MySQL et faux sur MariaDB atteint la production ». Celle-ci ne coûte
qu'un cycle d'intégration, mais elle est **invisible depuis le conteneur**,
ce que le document dit maintenant, avec ce cas comme exemple : les listes
de mots réservés des deux moteurs ne sont pas les mêmes, et un identifiant
neuf est précisément l'endroit où elles divergent.

**Une interaction relevée au rebasage, à consigner parce qu'elle ne se
voyait dans aucune des deux itérations prises seule.** `DiskUsageCheck`
délègue à `StorageUsage::usedPercent()` plutôt que de calculer quoi que ce
soit, et IT-02 a changé — pendant la revue de cette itération — ce que ce
pourcentage rapporte : le quota déclaré est désormais facturé sur
l'installation entière, pas sur `storage/` seul. Le contrôle a hérité de la
correction sans une ligne de plus, ce qui est le dessin qui marche. En
revanche `ChecksTest` posait son `storage/` directement sous le dossier
temporaire du système, si bien que « 900 octets sur un quota de 1000 »
était mesuré contre tout ce que la machine garde dans `/tmp` : 100 % au
lieu de 90 %. Le test imbrique maintenant son `storage/` dans une racine
d'installation à lui, comme les tests d'IT-02 l'ont fait au même moment.
Ni l'itération ni l'autre n'était fautive ; c'est leur rencontre qui l'a
été, et seule la suite complète rejouée sur l'état fusionné pouvait le
dire.

**Décisions autonomes.**

1. **Deux types de notification, pas un.** Le document de chantier
   demande de traiter « explicitement » le corollaire : l'alerte « l'envoi
   d'e-mails échoue » ne peut pas partir par e-mail.
   `core.operational_alert_mail` déclare son canal e-mail à `'off'` — une
   valeur verrouillée qu'aucune préférence ne peut rallumer — là où
   `core.operational_alert` l'a en `default_on`. Un seul type avec un
   filtrage à l'appel aurait été invisible sur la page des préférences ;
   deux types y affichent une ligne sans case e-mail, ce qui est
   exactement la vérité.

   Le vrai coût de l'alternative n'est pas l'inutilité : l'envoi échoue,
   cet échec est journalisé en `mail_send_failed`, et l'alerte gonflerait
   donc le compteur même qu'elle lit.

2. **Un second contrôle hors tâche planifiée.** Le document n'en nomme
   qu'un — le cron. `HttpsCheck` est dans le même cas pour une raison de
   même nature : un schéma appartient à une requête, et une passe CLI n'en
   a pas. Le lire depuis le réglage `base_url` a été écarté : c'est ce
   qu'un administrateur a tapé un jour, pas ce qu'un visiteur reçoit, et
   un certificat expiré cette nuit ne modifie aucun réglage.

   `RequestBoundChecks` limite les deux à une évaluation par quart
   d'heure, décidée par le mtime d'un fichier témoin : une requête
   ordinaire coûte un `stat()` et aucune écriture en base. Placés après
   `send()` et `session_write_close()`, comme la Fréquentation (§8.93) et
   pour la raison qui avait fait retirer le poor man's cron de cet
   endroit.

3. **Le plancher « jamais sauvegardé » déclenche, le plancher « cron
   jamais vu » non.** Ce n'est pas une incohérence. N'avoir jamais
   sauvegardé est la pire lecture possible et la plus utile à dire ; un
   cron jamais vu est une installation en cours de configuration, où
   l'assistant refuse déjà de terminer sans crontab et le dit bien mieux
   qu'une notification — et où il n'existe encore aucun super-admin à
   prévenir.

4. **Une lecture indécise laisse l'alerte où elle est.**
   `AlertReading::inconclusive()` n'est ni au-dessus du seuil ni
   en dessous du réarmement, donc une alerte déclenchée le reste. Traiter
   « je ne sais pas » comme « tout va bien » effacerait une alerte
   précisément sur les installations les moins capables de s'en rendre
   compte.

5. **Un contrôle qui lève une exception est journalisé et sauté**, comme
   `AttentionService` le fait déjà de ses fournisseurs. Un site dont le
   contrôle disque est cassé doit quand même apprendre que son cron s'est
   arrêté.

6. **`alert_key` est une clé primaire naturelle**, la première du schéma —
   les 49 autres tables portent un identifiant de substitution. Il y a une
   ligne par contrôle pour la vie de l'installation et la clé ne dit rien
   de moins que ce qu'un entier dirait de plus. Vérifié sur MariaDB 10.11
   avant d'être écrit, parce que `SchemaComparator` ignore purement et
   simplement les changements de clé primaire : ce choix n'est pas
   révisable sur place.

7. **`AlertSurfaces` sépare les libellés des contrôles.** Le fournisseur
   de points d'attention rend des *lignes*, pas des contrôles : construire
   les six contrôles — donc un `DiskBudget` et un `CronHealth` — pour leur
   demander leur nom à chaque affichage d'une page qui ne veut
   qu'« Espace disque » serait absurde. Un test épingle que tout contrôle
   livré figure dans la carte.

**Divergences constatées entre le document de chantier et le dépôt.**

1. **`UserAccountRepository::findAllSuperAdmins()` n'a pas été ajouté.**
   `NotificationService::recipientsForType()` répond déjà exactement à
   cette question, en résolvant l'audience depuis le `role_min` du type et
   en revérifiant le rôle **courant** de chaque destinataire — c'est-à-dire
   la propriété même sur laquelle le document de chantier s'appuie. Une
   méthode de dépôt dont l'unique appelant pouvait utiliser l'existante
   aurait été une seconde manière de demander « qui sont les
   super-admins », à garder en phase avec le résolveur de rôles.

   (`findSuperAdmins()` existe par ailleurs déjà, pour la page Comptes
   superadmin.)

2. **Le réglage `dev_update_enabled` n'existe pas.** Le mode développement
   est `auto_update_enabled` activé **et** `auto_update_level` à `'dev'` —
   une quatrième valeur du groupe de boutons radio, sous le même
   interrupteur général, plutôt qu'un basculeur de zone dangereuse propre
   (ARCHITECTURE.md §8.17). `DevelopmentModeCheck` lit donc les deux, et
   un test épingle que le niveau seul, sans l'interrupteur, n'est pas le
   mode développement : il n'installe rien.

3. **Le journal n'avait aucun moyen de compter un type d'évènement.**
   `JournalRepository::search()` filtre la description par `LIKE`, ce qui
   est le mauvais instrument : une description est une phrase française
   écrite pour un lecteur, et elle change sans que personne y voie un
   changement de comportement. `countEventsSince()` compte sur
   `event_type`, l'identifiant stable. De même,
   `BackupRepository::lastSuccessfulCompletedAt()` est nouveau — rien ne
   savait répondre « quand la dernière sauvegarde a-t-elle abouti ».

4. **Une entrée d'allowlist de `HelpLabelDriftTest` a cessé d'être
   nécessaire.** « envoi d'e-mails » y figurait pour le sujet
   `assistant-d-aide` parce que la citation ne correspondait à aucun
   libellé de l'interface ; elle en a un maintenant. Le cliquet de ce test
   refuse une entrée qui n'excuse plus rien, donc elle a été retirée — ce
   qui est le mécanisme fonctionnant comme prévu.

**Reporté.** Aucune interface nouvelle sur la page Maintenance : les
alertes s'affichent dans la cloche et sur la page Points d'attention, qui
existent déjà. Le découpage de la section Sauvegardes reste à IT-04.

---

## IT-04 — Quotas par famille, et suppression manuelle

**Livré.** `Core\Maintenance\BackupFamily` (l'énumération), `BackupRetention`
(le seul chemin de purge et de suppression), `BackupSafetyNet` (le refus),
la route `POST /config/maintenance/backup/{id}/delete`, les trois réglages
`backup_keep_*`, et le découpage de la section « Sauvegardes » en trois
sections `<h2 class="h5">`. `ARCHITECTURE.md` §8.100,
`docs/exigences-non-fonctionnelles.md` §4bis, et le sujet d'aide scindé en
deux.

**Le défaut, énoncé simplement.** `findBeyond(5)` triait *tout* par date et
coupait après cinq, sans distinction. Les sauvegardes automatiques sont
plus nombreuses que les délibérées sur toute installation réellement
entretenue, donc une liste ordonnée unique garde toujours le bruit et jette
le signal : trois mises à jour consécutives évinçaient la sauvegarde
complète qu'un administrateur venait de prendre cinq minutes plus tôt.
Chaque famille évince désormais la sienne.

**Six copies de la même purge, et c'est ça le vrai sujet.** Le contrôleur
et cinq gestionnaires de tâches portaient chacun leur exemplaire des mêmes
quinze lignes. Elles étaient d'accord entre elles — c'est pour cela que
personne ne l'avait relevé —, et le risque était la septième. Une ligne
`backups` possède **deux** fichiers, `file_id` et `db_dump_file_id` : une
routine qui oublie le second laisse sur le disque un orphelin que plus rien
ne référence et que seul un accès FTP peut atteindre. La suppression
manuelle réutilise donc `BackupRetention::forget()` au lieu d'ajouter la
septième copie — c'est le premier des quatre pièges du document de chantier,
et le seul dont la correction se mesure en lignes retirées.

**Décisions autonomes.**

1. **La famille est déduite du type, jamais stockée** (D3, mais la mise en
   œuvre restait à choisir). Une colonne `backups.family` serait une seconde
   source de vérité pour ce que le type décide déjà, et les deux
   divergeraient au premier type livré sans elle. `tryFromType()` répond
   `null` pour un type inconnu, et la purge laisse alors la ligne
   tranquille : garder ce qu'on ne sait pas classer coûte du disque,
   le supprimer coûte à quelqu'un son unique copie. Le prix de cette
   prudence est qu'un type livré sans famille ne serait *jamais* purgé,
   sans que rien à l'exécution ne le dise — d'où
   `BackupFamilyCoverageTest`, qui lit l'énumération de `schema/core.sql`
   comme du texte et refuse un type sans famille, sans libellé français, ou
   absent de `Backup::TYPES`. IT-06 ajoute `portable` : c'est exactement le
   moment où le trou se serait ouvert.

2. **Le plafond galerie s'applique après *chaque* création**, pas seulement
   après une création avec galerie. C'est une lecture délibérée de « à la
   création » : une seconde archive avec galerie ne peut exister que parce
   que quelqu'un l'a faite, et une installation qui en avait déjà deux à la
   livraison ne devrait pas avoir à en faire une troisième pour que le
   plafond s'en aperçoive. Le déclenchement à la création reste entier — le
   piège que le document nomme est le démarrage et la migration, pas la
   création d'une autre famille.

3. **La suppression est `admin`, pas `superadmin`.** C'est le plancher de
   toutes les autres écritures de la section : qui peut créer une sauvegarde
   et télécharger l'archive peut aussi en retirer une. Le cas réellement
   dangereux n'est pas un rôle, c'est supprimer le filet d'une opération qui
   tourne en ce moment — et `BackupSafetyNet` le refuse quel que soit le
   rôle de l'appelant. Un rôle plus élevé aurait par ailleurs affiché à un
   `admin` un bouton qui répond 403, ce que la section Réinitialisation fait
   déjà et qu'il n'y avait pas de raison d'étendre.

4. **Le refus lit deux sources**, parce qu'une opération en cours s'inscrit à
   deux endroits : la charge utile de la tâche planifiée (`backup_id`,
   `safety_backup_id`) tant qu'elle est en file ou réclamée, et
   `update_history.backup_id` pour toute la durée d'une installation.
   Délibérément **pas** construit sur `findInProgress()`, qui marque une
   ligne bloquée comme échouée en effet de bord : savoir si un bouton peut
   être pressé ne doit pas changer l'état d'une mise à jour en répondant.
   Un test épingle précisément cela.

5. **Le refus parle, il ne grise pas.** Un contrôle désactivé sans
   explication se lit comme un bug, se signale comme un bug, et n'apprend à
   personne que la sauvegarde redeviendra supprimable dans deux minutes.

6. **Les libellés de type passent du Twig au PHP** (`Backup::typeLabel()`).
   La page dit déjà, à propos du bloc disque, qu'un choix pris dans un
   fichier Twig est un choix que personne ne peut tester ; et ici deux
   surfaces ont besoin de la même chaîne — la ligne de la liste et la
   confirmation qui doit nommer ce qu'elle va détruire. Deux orthographes de
   « Complète (sans galerie) », c'est une confirmation qui cesse de
   correspondre à la ligne cliquée.

7. **« Voir plus » reprend le mécanisme de la page**, le « collapse »
   Bootstrap de l'historique des mises à jour vingt lignes plus haut, avec
   `collapse-label.js` déjà chargé — et non un `<details>` natif, qui aurait
   fait deux façons de replier une liste sur un même écran. Les libellés
   (« Voir plus (N) », « Réduire ») viennent de la maquette, qui fait foi
   là-dessus ; le mécanisme non, elle est en Tailwind et en React.

8. **La pastille porte le libellé du *type*, coloré par la famille.** Le
   document dit « la famille est une pastille sur chaque ligne » et la
   maquette écrit le type dans cette pastille. Le type est strictement plus
   informatif, et la couleur porte le regroupement : c'est la maquette qui
   tranche, comme prévu, sur ce qui s'affiche.

**Divergences constatées entre le document de chantier et le dépôt.**

1. **La famille « Portable » n'est pas ajoutée.** Le type `portable`
   n'existe pas encore — il arrive en IT-06 —, et une branche
   d'énumération pour un type qu'aucune ligne ne peut porter est du code
   mort. Le cliquet ci-dessus force IT-06 à l'ajouter au même moment que le
   type, ce qui est la bonne mécanique.

2. **Les sections 2 et 3 de la maquette arrivent presque vides.** « Sauvegardes
   automatiques et distantes » ne porte que la fréquence : la destination
   Drive est IT-08, la phrase de passe IT-09. La hiérarchie est celle de la
   maquette dès maintenant, parce que la déplacer plus tard coûterait une
   seconde relecture de la même page — mais les blocs qu'elle attend sont
   nommés dans les commentaires plutôt que livrés en avance.

3. **Deux paragraphes d'`ARCHITECTURE.md` que le code contredisait**, tous
   deux hérités d'IT-03 : §8.15 annonçait encore `backup_auto_frequency`
   par défaut à `monthly` et « la même quota de 5 sauvegardes partagé par
   tous les types ». Corrigés dans cette PR, comme le chantier l'exige.
   Même chose pour le repli `?: 'monthly'` du contrôleur, qui aurait placé
   le sélecteur sur une valeur que l'installation ne porte pas.

4. **Le sujet d'aide a dû être scindé.** `HelpInvariantsTest` refuse un
   sujet au-delà de 500 mots, et « Sauvegarder le site » en faisait déjà 464
   avant cette itération. Rogner la prose existante pour faire tenir les
   nouvelles règles de conservation aurait été le mauvais arbitrage : le
   cliquet dit ce que la charte dit (« au-delà d'environ 400 mots, ce sont
   deux sujets »), et l'écran venait précisément de fournir la couture.
   « Conserver et supprimer les sauvegardes » est donc un sujet à part,
   qui suit la troisième section de la page.

5. **Un test de restauration reposait sur la purge globale.**
   `RestoreBackupRoundTripTest` faisait échouer la queue d'une passe reprise
   en supprimant la table `files` sous une purge qui allait la lire — avec
   sept sauvegardes `database`. La purge d'une reprise porte sur la famille
   « avant opération » : les sept lignes manuelles ne la concernaient plus,
   et le test n'observait plus rien. Le montage a été corrigé, pas
   l'assertion : ce qu'il épingle (le retour en arrière depuis les chemins
   portés par la charge utile) n'a pas changé.

6. **Deux cliquets ont parlé, tous deux utilement.**
   `AuthorizationMatrixInventoryTest` a refusé la route neuve tant qu'elle
   n'avait pas de fixture dans `tests/dast/authz-fixtures.json` — sans quoi
   la matrice l'aurait silencieusement laissée non vérifiée. Et le test E2E
   de la page cherchait ses lignes par `getByRole('cell')` : la liste n'est
   plus un tableau, et il fallait le dire au test plutôt qu'à personne.

**Reporté.** Le bloc « Sauvegarde portable » (IT-06) et son avertissement,
la destination distante (IT-08), la phrase de passe générée (IT-09), l'état
d'intégrité par ligne et le marqueur « sur Drive » (IT-05, IT-09). La
maquette les montre ; cette itération ne les anticipe pas.
