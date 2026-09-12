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
   `ObjectStorageBackend`). La vidéo est exclue du chiffre et signalée à
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

   **Fermé depuis, et fermé dans la couche notification.** Un type peut
   désormais déclarer `NotificationType::$deliversImmediately`, auquel cas
   `dispatch()` appelle `sendEmailsForNotifications()` et
   `sendPushForNotifications()` — les deux mêmes méthodes que les
   gestionnaires planifiés, avec la même réservation de ligne avant envoi
   — au lieu de planifier. `core.operational_alert` le déclare ; rien dans
   `Core\Alert` n'envoie quoi que ce soit, donc le raccourci écarté
   ci-dessus l'est toujours. Trois limites assumées : les heures calmes
   retiennent encore le push (un réglage dont le sujet est ce qui a le
   droit de réveiller quelqu'un ne se contourne pas pour cause
   d'urgence), une fabrique de mailer absente fait retomber sur la file
   plutôt que de perdre l'envoi, et un transport qui échoue est journalisé
   sans remonter — l'envoi a lieu dans la requête d'un visiteur, et un
   SMTP muet n'a pas à devenir une erreur 500.

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

**Un constat de revue, et il détruisait de vraies archives.** Les deux
règles ne filtraient que sur le type et la famille, jamais sur le
**statut**. Or une ligne `backups` est insérée en `pending` avant que sa
tâche de fond ne tourne, et une tâche qui échoue laisse la ligne en place,
en `failed`, sans aucun fichier. Cette ligne vide devenait donc la plus
récente de sa famille — et avec le plafond galerie à un, la création
suivante, de n'importe quelle sorte, gardait l'échec et supprimait la
dernière archive contenant réellement la galerie. Même aveuglement sur le
quota de famille : trois échecs suffisaient à en épuiser un et à faire
partir une sauvegarde bien réelle.

Reproduit d'abord, corrigé ensuite. **Seule une ligne `completed` occupe
une place** : un quota est une promesse sur le nombre de copies
utilisables, et une ligne qui n'en est pas une ne peut pas la dépenser. Ne
pas les compter ne pouvait pas vouloir dire les garder indéfiniment — la
table grossirait d'une ligne par échec — donc **un échec survit par
famille**, le plus récent, parce que « Échouée » sur la dernière tentative
est précisément la raison pour laquelle la ligne n'est pas simplement
effacée quand la tâche renonce. Les lignes `pending` et `in_progress` ne
sont jamais supprimées : un gestionnaire écrit dedans, et l'autre bout de
cette course est une archive à moitié écrite dont plus rien ne garde
trace. Quatre tests, dont celui qui reproduit le scénario exact du
constat.

**Deux constats de revue de plus, et le second a rouvert un arbitrage.**

1. **Un commentaire de code en français.** `AGENTS.md` § Language est
   clair : ce qui est écrit *à propos d'un changement* est en français,
   le code et ses commentaires en anglais — et un commentaire qui
   explique une décision de mise en forme est un commentaire de code.
   Corrigé.

2. **`GALLERY_TYPES` ne listait que `full_with_gallery`**, et le nom d'un
   type ne dit rien de son contenu. `InstallUpdateHandler`,
   `ResetSettingsHandler` et `RestoreBackupHandler` appellent tous
   `createFileBackup(true)` : l'opération dont ils protègent peut effacer
   `storage/gallery/`, donc leur copie de sécurité doit la contenir. Les
   archives `auto_update` et `auto_reset` échappaient donc au plafond, et
   une installation pouvait garder **quatre** archives de la taille de la
   galerie à la fois — une manuelle plus un quota de famille de trois.
   C'est exactement le disque que le plafond existe pour défendre.

   `GalleryTypeCoverageTest` lit désormais les **sites d'appel** plutôt
   que les noms : il énumère tout appel à `createFileBackup()` dont
   l'argument n'est pas littéralement `false`, en dépouillant les
   commentaires par le tokenizer (une mention en prose n'est pas un appel,
   et un cliquet incapable de faire la différence se fait taire au lieu
   d'être corrigé). Un site d'appel neuf que personne n'a classé casse la
   compilation. `FullResetHandler` y figure avec la valeur `null` : il
   n'enregistre volontairement aucune ligne `backups`, une réinitialisation
   complète vidant la table qui la porterait.

   **Le relecteur avait raison sur la suite aussi** : ajouter les deux
   types sans plus n'aurait pas suffi, parce que `purgeAfterCreating()` ne
   consultait pas `BackupSafetyNet`. Une sauvegarde manuelle avec galerie
   prise pendant qu'une mise à jour tourne aurait évincé la seule chose
   depuis laquelle son retour en arrière peut repartir — en silence, sans
   que personne ait demandé la moindre suppression. La purge automatique
   lit maintenant le filet, une fois par purge et non par candidat, et
   **saute** une ligne protégée plutôt que de la reporter : le plafond est
   dépassé d'une archive jusqu'à la fin de l'opération, ce qui dure des
   minutes et coûte une archive, contre une installation qui perdrait son
   chemin de retour.

**Un arbitrage rouvert, et une divergence assumée avec le document.** Le
document fixe le plafond sur la prémisse qu'« une `full_with_gallery` peut
peser plus que les huit autres réunies » — arithmétique qui suppose que
les huit autres n'ont pas la galerie, alors que trois d'entre elles l'ont.
Le plafond s'appliquant à toutes les familles, il mord donc **avant** le
quota « avant opération » : `backup_keep_operational` vaut 3 par défaut,
mais une seule sauvegarde avant opération est conservée en pratique.

Le contraire aurait autorisé trois archives de 2 Gio de filet de sécurité
sur une installation dont le §1 dimensionne la galerie entière à 2 Gio :
le plafond est ce qui doit gagner. Le réglage le dit dans sa propre
description plutôt que de promettre trois, §4bis l'explique, et la
question de fond — une mise à jour remplace du code, son filet a-t-il
besoin de la galerie ? — part en **issue #298** avec ce qu'il faut vérifier
avant d'y toucher : si le retour en arrière restaure `storage/` en bloc,
retirer la galerie de l'archive effacerait les photos au premier rollback.

**Réponse, depuis : non.** La vérification demandée a été faite et le
retour en arrière ne remplace pas `storage/` — `BackupService::
restoreFiles()` extrait par-dessus l'arbre vivant et ne supprime rien de
ce que l'archive ne contient pas, donc une galerie absente de l'archive
est une galerie laissée telle quelle sur le disque — un test de
`BackupServiceTest` restaure désormais une archive sans galerie
par-dessus l'arbre dont elle a été tirée et vérifie que les photos y sont
toujours. `InstallUpdateHandler` demande
`createFileBackup(false)`, `auto_update` a quitté `GALLERY_TYPES` avec sa
galerie, et l'arbitrage ci-dessus ne vaut plus que pour les copies avant
**réinitialisation** : une série de mises à jour atteint désormais le
quota de 3, une série de réinitialisations toujours le plafond de 1.

**Un troisième constat de revue : la copie de sécurité de la restauration
n'était pas protégée pendant la restauration.** `BackupSafetyNet` lit la
charge utile des tâches vivantes, or une charge utile est écrite au moment
où la tâche est *planifiée*, et `RestoreBackupHandler` prend sa copie
`auto_reset` bien après. `safety_backup_id` n'apparaissait que dans la
charge utile de la passe de *reprise*, planifiée beaucoup plus tard.

Entre le `markCompleted()` de cette copie et le remplacement de la base,
elle était donc une ligne `completed` comme une autre : listée avec un
bouton « Supprimer » qui marche, et invisible au filet. Sur une
installation avec galerie, construire cette archive prend des minutes — et
c'est la seule chose depuis laquelle le retour en arrière peut repartir.
La pire copie à perdre, dans la pire fenêtre.

La tâche la déclare maintenant dans **sa propre** ligne, dans le même
geste que le `markCompleted()`, via `SchedulerRepository::rememberInPayload()`.
Le lanceur injecte pour cela un second identifiant réservé,
`scheduled_action_id`, à côté du `requested_by_user_account_id` qui suivait
déjà exactement le même raisonnement : un gestionnaire qui crée quelque
chose que le reste du site ne doit pas détruire doit pouvoir le dire, ce
qui suppose de savoir quelle ligne il est. La fusion se fait en PHP et non
par une fonction JSON du moteur — les deux moteurs ne les écrivent pas
pareil, et `docs/quality-pipeline.md` décrit précisément cette panne-là.

**Ce constat n'a pas pu être épinglé par un test de comportement, et la
raison mérite d'être écrite.** La passe se rétablit depuis cette copie
quand elle échoue, ce qui restaure la base et emporte avec elle la ligne
`backups` et la ligne de file : au moment où un test peut regarder, ce
qu'il voulait observer a été consommé par le mécanisme qu'il observait. La
propriété est donc épinglée en deux moitiés — que `rememberInPayload()`
rende la copie visible au filet est prouvé par le comportement dans
`BackupSafetyNetTest` ; que *ce* gestionnaire l'appelle, sur sa propre
tâche, dans le même geste, est prouvé textuellement, à la manière de
`DiskBudgetWiringTest` et pour la même raison : un site d'appel qui
disparaît ne fait échouer aucun test et ne journalise rien.

**Reporté.** Le bloc « Sauvegarde portable » (IT-06) et son avertissement,
la destination distante (IT-08), la phrase de passe générée (IT-09), l'état
d'intégrité par ligne et le marqueur « sur Drive » (IT-05, IT-09). La
maquette les montre ; cette itération ne les anticipe pas.

---

## IT-05 — L'intégrité des sauvegardes stockées

**Livré.** `Core\Maintenance\BackupIntegrity` (l'enregistrement des
empreintes et la vérification), `BackupIntegrityStatus` (les cinq états),
`Task\VerifyBackupIntegrityHandler` (la passe), `Core\Alert\Check\
BackupIntegrityCheck` (l'alerte), quatre colonnes sur `backups`, et le
statut affiché sur chaque ligne de « Sauvegardes récentes ».
`ARCHITECTURE.md` §8.101, `docs/exigences-non-fonctionnelles.md` §4, le
sujet d'aide « Conserver et supprimer les sauvegardes ».

**Le défaut, énoncé simplement.** Rien ne demandait jamais si une
sauvegarde *déjà sur le disque* était encore celle qu'on avait écrite.
`RestoreBackupHandler` valide une archive téléversée parce qu'il va s'en
servir, mais une archive stockée n'est ouverte qu'un seul jour — celui où
on en a besoin, c'est-à-dire le pire jour pour découvrir qu'elle est
tronquée. Et la troncature n'est pas hypothétique : c'est ce que produit un
quota atteint, la panne qu'IT-02 refuse désormais d'avance mais que toute
archive écrite avant lui a pu subir.

**Décisions autonomes.**

1. **Quatre colonnes, pas deux.** Le document dit « la taille et
   l'empreinte SHA-256 de chaque fichier, dans deux colonnes ». La taille
   est déjà dans `files.size_bytes` : l'ajouter à `backups` en ferait une
   seconde source de vérité, exactement ce que D3 refuse pour la famille.
   Restent donc les deux empreintes — plus deux colonnes que les autres
   exigences du document rendent nécessaires : un `integrity_status`
   (« un statut visible dans la liste ») et un `integrity_checked_at`,
   sans lequel la passe reprendrait éternellement les mêmes sauvegardes.

2. **Cinq états, et chaque distinction a été payée.** `intact` et
   `corrupt` sont la paire évidente. `missing` est à part parce que rien
   dans l'application ne retire un fichier sans sa ligne — la suppression
   d'IT-04 emporte les deux —, donc un fichier disparu vient de
   l'extérieur : un ménage FTP, une migration d'hébergement. `unknown`,
   c'est « pas encore regardé ». Et **`unverifiable` n'est délibérément
   pas `corrupt`** : une installation qui se met à jour avec cinq
   sauvegardes antérieures allumerait sinon l'alerte dès la première
   passe, sur cinq sauvegardes très probablement saines — une alerte qui
   crie le jour où elle arrive est coupée avant d'avoir jamais rien dit de
   vrai. Enregistrer l'empreinte à la première rencontre était
   l'alternative, et elle est pire : elle certifierait l'état du fichier
   *aujourd'hui*, y compris déjà cassé.

3. **`BackupIntegrity` est le seul chemin de production vers
   `markCompleted()`**, et c'est un cliquet plutôt qu'une consigne. Les
   empreintes sont des paramètres optionnels en fin de signature : un site
   qui passerait outre ne casserait ni la compilation, ni un test, ni un
   journal — il écrirait une ligne d'apparence normale que plus rien ne
   pourra jamais vérifier, pour la vie de cette sauvegarde. C'est la forme
   de trou pour laquelle `DiskBudgetWiringTest` avait été écrit, avec six
   sites où se tromper. `BackupIntegrityWiringTest` refuse un appelant qui
   contourne, et vérifie aussi la réciproque : tout fichier qui *crée* une
   sauvegarde doit la terminer par le service.

4. **Une tâche à part, pas un sixième contrôle dans la passe
   quotidienne.** Celle-ci lit quatre faits bon marché et justifie sa
   cadence là-dessus ; la vérification, elle, hache des gigaoctets. Les
   fondre ferait de la passe la chose la plus lourde du cron et lierait
   deux cadences qui n'ont aucune raison de coïncider. L'alerte, elle,
   reste dans la passe quotidienne et **ne calcule rien** : elle compte ce
   que la vérification a écrit. C'est le même partage que D2 trace entre
   la notification et le point d'attention.

5. **Deux sauvegardes par passe, la moins récemment vérifiée d'abord.**
   Tout revérifier chaque nuit rehacherait les mêmes gigaoctets inchangés
   sans information nouvelle, sur un hébergement qui facture les
   entrées-sorties. Avec la rétention d'IT-04, une installation garde une
   poignée de sauvegardes : deux par nuit en fait le tour en moins d'une
   semaine, ce qui est la bonne échelle pour un fait qui change quand un
   disque se remplit. Le plafond est petit exprès — une passe qui traîne
   sur un hébergement mutualisé est une passe tuée à mi-chemin, qui ne
   rapporte rien du tout.

6. **Un seul est déjà de trop.** Tous les autres seuils du chantier
   surveillent un niveau qui dérive ; celui-ci n'en est pas un. Une
   sauvegarde illisible est une copie du travail de l'unité qui n'existe
   plus, et il n'existe aucun nombre assez petit pour être tolérable. La
   paire est donc 1 / 0, et c'est le réarmement qui porte le sens : rien
   ne répare une archive tronquée, donc l'alerte se tait quand la dernière
   illisible a été supprimée.

**Divergences constatées entre le document de chantier et le dépôt.**

1. **Le nombre de colonnes** (constat 1 ci-dessus) : la taille existait
   déjà ailleurs, et deux exigences du même paragraphe en imposaient deux
   autres.

2. **Un cliquet d'IT-03 était une consigne, pas un cliquet.**
   `ChecksTest::testEveryShippedCheckHasASurfaceLabel` comparait
   `AlertSurfaces` à une liste de classes **écrite à la main**. Un
   septième contrôle livré sans libellé se serait donc affiché sous sa clé
   brute sur la page des points d'attention, et le test dont c'est
   précisément le rôle serait passé au vert — parce que personne n'aurait
   pensé à ajouter la classe à la liste non plus. Il lit maintenant le
   dossier `core/Alert/Check/`. Vérifié en retirant le libellé : il mord.

3. **Le schéma des tests était à mettre à jour aussi.**
   `tests/DatabaseTestHelper.php` porte une copie SQLite écrite à la main
   des tables, indépendante de `schema/core.sql` — c'est le piège que le
   docbloc de `RestoreBackupRoundTripTest` décrit (« hand-written tables
   were the first attempt and they were wrong within minutes »). Les
   quatre colonnes y ont été ajoutées.

4. **Le cliquet d'IT-04 sur la copie de sécurité a bougé d'ancre.** Il
   repérait `markCompleted($safetyBackupId` ; la complétion passe
   désormais par `BackupIntegrity`. La propriété épinglée est inchangée —
   la copie est déclarée dans la charge utile de la tâche courante dès
   qu'elle existe —, seule l'ancre textuelle a suivi.

**Un test qui ne prouvait rien, et ce qu'il a fallu pour qu'il prouve
quelque chose.** La propriété « l'archive n'est jamais chargée en
mémoire » décide à elle seule si toute la vérification est abordable :
l'installation de référence garde des archives en gigaoctets, sur un
hébergement dont la limite mémoire est bien en dessous. La première
version mesurait `memory_get_peak_usage(true)` autour de la vérification
d'un fichier de 8 Mio — et **l'implémentation naïve
`hash('sha256', file_get_contents(...))` passait**, parce que le pic est
un maximum de tout le processus et que PHPUnit l'avait déjà poussé bien
au-delà de 8 Mio. Le test ne mesurait que l'appétit du harnais.
`memory_reset_peak_usage()` avant la mesure rend le chiffre exact ;
l'implémentation naïve échoue maintenant d'un facteur huit, ce qui a été
vérifié en la remettant.

**Deux constats de revue, et le second portait sur le cliquet lui-même.**

1. **Le test du gestionnaire ne reflétait pas l'arborescence.** `AGENTS.md`
   § Tests : « `tests/` mirrors the structure of `core/` ». Le fichier
   était sous `tests/Core/Maintenance/` alors que la classe vit dans
   `core/Maintenance/Task/`. Déplacé ; `phpunit.xml` enregistre
   `tests/Core` en bloc, donc rien d'autre à changer.

2. **Le cliquet était aveugle au site que son propre docbloc nomme en
   premier.** Le motif `\$\w*[bB]ackupRepository->markCompleted\(` exige
   que le nom du dépôt commence juste après le `$` : il voyait la variable
   locale d'un gestionnaire et **pas** la propriété promue d'un
   contrôleur, `$this->backupRepository->...`. Le contrôleur était donc
   parcouru et invisible, et une régression y serait passée en silence.
   Le même angle mort affectait le second test, sur `->create(`.

   Un cliquet dont l'angle mort est le site le plus risqué est pire que
   pas de cliquet, parce qu'on lui fait confiance. La détection ne
   s'appuie plus sur le **nom** du receveur — un nom n'est pas une preuve,
   c'est la leçon d'IT-04 sur `GALLERY_TYPES` — mais sur l'appel lui-même,
   en n'excluant que la classe qui partage le verbe
   (`UpdateHistoryRepository`, qui termine une mise à jour, pas une
   sauvegarde). Un test de fixtures épingle les quatre orthographes du
   receveur et les deux qu'il ne doit pas voir, et la régression exacte
   décrite par le relecteur a été rejouée dans le contrôleur pour vérifier
   qu'elle échoue désormais.

**Reporté.** Le marqueur « sur Drive » sur chaque ligne (IT-09) et la
vérification des archives distantes : ce qui part chez un tiers se vérifie
autrement, et le raccordement n'existe pas encore.

---

## IT-06 — La sauvegarde portable

**Livré.** Le type `portable`, `Core\Maintenance\Portable\SecretEnvelope`
(la seconde enveloppe), `PortableManifest` (`scoutmagic-backup.json`),
`PortablePassphrase` (le plancher de seize caractères),
`BackupService::createPortableBackup()`, la famille `Portable` à quota 1
non réglable, `Core\Alert\Check\PortableBackupLingerCheck`, la route
`POST /config/maintenance/backup/portable`, et le troisième sous-bloc de
la section « Sauvegardes ». `ARCHITECTURE.md` §8.102, `SECURITY.md` §5
et §12, `docs/exigences-non-fonctionnelles.md` §4 et §4bis, le sujet
d'aide « Emporter le site ailleurs ».

**Le défaut, énoncé simplement.** Toutes les archives du site excluent
`storage/keys/` et `storage/config/`. C'est ce qui rend une archive
perdue peu intéressante — les colonnes personnelles y sont le chiffré
d'une clé restée sur le serveur — et c'est exactement ce qui fait
qu'aucune d'elles ne se restaure ailleurs. Sur un hébergement neuf, la
restauration réussit et le site remonte incapable de lire un seul nom.
Une sauvegarde hors site qu'on ne peut pas restaurer hors site ne sert à
rien.

**Décisions autonomes.**

1. **Le zip ne voit jamais la phrase de passe.** Le chiffrement AES d'un
   zip dérive sa clé par PBKDF2-HMAC-SHA1 à **1000 itérations**, chiffre
   figé par le format en 2003, et y range une **valeur de vérification du
   mot de passe**. La première version livrait la phrase de l'opérateur à
   `ZipArchive` *et* s'en servait pour la seconde enveloppe : casser la
   couche faible rendait alors **la phrase elle-même**, après quoi la
   dérivation lente se calculait une fois, honnêtement, gratuitement. Elle
   n'ajoutait rien contre l'attaque qui la motivait — et le dump de la
   base étant sous cette même couche, la casser livrait déjà tout. Une
   passe lente produit donc un secret maître, `hash_hkdf()` en tire deux
   clés séparées, et ce que reçoit `ZipArchive` est 256 bits dérivés : ses
   1000 itérations gardent désormais un secret qu'aucun dictionnaire ne
   contient. C'est un relecteur qui l'a vu, et la question a été posée au
   mainteneur parce qu'elle touchait D4.

1bis. **Les deux clés sont séparées par domaine.** Le mot de passe
   d'archive est une chaîne remise à une bibliothèque tierce — elle peut
   finir dans un fichier temporaire ou un message d'erreur —, donc la clé
   qui scelle la clé maîtresse ne doit pas en être calculable.

1ter. **Ce que l'enveloppe achète vraiment**, une fois qu'elle ne porte
   plus tout le dessin : une archive extraite — dans un dossier temporaire
   pendant une restauration, sur un bureau, chez un hébergeur de fichiers —
   garde ses secrets scellés au lieu de les laisser en clair à côté du
   dump. Le zip protège l'archive ; ceci protège les deux fichiers quand
   l'archive cesse d'en être une.

2. **Argon2id si libsodium est là, PBKDF2-SHA256 sinon, et le lecteur
   suit l'en-tête.** Une archive scellée avec Argon2id peut être
   ouverte sur un hébergement qui n'a pas libsodium, et l'inverse.
   Redemander « que sait faire ce serveur ? » au lieu de lire `kdf`
   dériverait silencieusement la mauvaise clé et annoncerait une phrase
   de passe incorrecte, envoyant l'opérateur chercher une phrase qui
   était juste depuis le début. Les limites libsodium sont la paire
   INTERACTIVE et non MODERATE : celle-ci réclame 256 Mio dans une seule
   requête sur un hébergement mutualisé, et une dérivation qui meurt est
   une sauvegarde qui n'existe pas.

2bis. **Les paramètres de dérivation voyagent dans le commentaire
   d'archive du zip, en clair**, et non dans le manifeste : le mot de passe
   d'archive en est dérivé, donc rien de ce que ce mot de passe protège ne
   peut les porter sans circularité. Un sel n'est pas un secret. Le
   commentaire porte aussi une phrase en français : quelqu'un qui ouvre le
   fichier dans 7-Zip mérite qu'on lui dise ce que c'est.

3. **Une archive, une dérivation — et c'est structurel.** La première
   version faisait générer son sel par `seal()` à chaque appel ;
   l'appelant enregistrait celui du premier fichier et scellait le second
   sous un autre. L'archive sortait sans la moindre erreur, et son
   `secrets.enc` était définitivement inouvrable par quiconque lit le
   manifeste. `newDerivation()` est séparé de `seal()` pour que cette
   forme-là ne puisse plus être écrite. C'est aussi la forme la moins
   chère : la dérivation est lente exprès, une fois par archive plutôt
   qu'une fois par fichier.

4. **Les secrets ne passent pas par la marche d'archive** —
   contrairement à ce que le document laissait attendre en désignant
   `excludedArchivePrefixes()` comme la couture à modifier. Lui apprendre
   un « mode portable » qui cesserait d'exclure ces deux dossiers est la
   forme évidente et la mauvaise : elle ajouterait la clé maîtresse comme
   une **entrée ordinaire**, protégée par les 1000 itérations ci-dessus,
   avec tout le reste de la fonction marchant parfaitement. L'exclusion
   reste donc absolue dans tous les modes, et les deux fichiers voyagent
   par un chemin qui ne peut pas oublier de les sceller. Bénéfice
   secondaire : le piège que le docbloc de cette méthode signale — la
   marche et l'estimation qui divergent — ne s'ouvre jamais, puisque la
   liste ne change pas.

5. **Les membres scellés ne portent pas le nom de leur destination.**
   Une entrée nommée `storage/keys/master.key` serait extraite par-dessus
   la vraie clé par n'importe quelle restauration ordinaire — en y
   écrivant les octets *scellés*, c'est-à-dire en enfermant
   l'installation dehors de sa propre base par une restauration qui
   annonce avoir réussi. Elles vivent sous `secrets/`, et le manifeste
   porte leur destination.

6. **Le manifeste est chiffré comme le reste.** Rien n'a besoin de le
   lire sans la phrase de passe — qui restaure vient de la taper — et en
   clair il offrirait à qui détient le fichier sans le mot de passe le
   sel et les paramètres de coût, c'est-à-dire l'avance que la seconde
   serrure existe pour refuser.

7. **Quota 1 sans clé de réglage du tout**, plutôt qu'une clé lue puis
   ignorée. `quotaSettingKey()` répond `null` et la table `settings`
   n'est pas consultée : aucune ligne `backup_keep_portable` — écrite à
   la main, restaurée d'un site plus ancien, ou inventée par une version
   future — ne peut lever le nombre. Les autres familles sont des copies
   du site, et qui a le disque pour en garder plus y a droit ; celle-ci
   est une copie des clés.

8. **Une route à part plutôt qu'une quatrième portée.** Tout ce que
   `createFullBackup` produit est restaurable ici seulement ; celle-ci
   emporte les clés. Les deux points d'entrée ne valident pas la même
   chose et ne journalisent pas la même chose — `security` d'un côté,
   `info` de l'autre —, et un relecteur ne devrait pas avoir à savoir que
   la première peut aussi émettre la clé maîtresse. Le chemin d'écriture
   sous elles, lui, est unique : `writeArchive()`.

9. **`admin`, pas `superadmin`.** Le rôle qui atteint cette route peut
   déjà prendre une sauvegarde complète, la télécharger, lire le dossier
   de chaque membre et réinitialiser le site. Élever celle-ci seule
   n'achèterait rien et laisserait entendre que les autres sont
   anodines. Ce qui la garde est ce qu'elle produit.

**Divergences constatées entre le document de chantier et le dépôt.**

1. **Les empreintes ne couvrent pas chaque membre de l'archive.** Le
   document demande « les empreintes de chaque membre » ; le manifeste
   décrit les arborescences sans les énumérer. Des dizaines de milliers
   de lignes SHA-256 ajouteraient une seconde lecture complète du site à
   un travail qui le lit déjà entièrement une fois, et produiraient un
   manifeste plus gros que certains des fichiers qu'il décrit — pour
   presque rien : le format zip stocke déjà un CRC-32 par entrée et le
   vérifie à l'extraction, ce qui attrape la corruption qui arrive
   réellement à une archive transportée. Contre quelqu'un capable de
   réécrire une entrée, une liste d'empreintes scellée par la même phrase
   de passe que l'entrée n'est pas une barrière. Les membres que la
   restauration traite par leur nom, eux, sont bien listés avec leur
   empreinte.

2. **`SecretEnvelope::open()` est livré avec `seal()`**, alors que la
   restauration est IT-07. Un chiffrement dont rien ne sait déchiffrer la
   sortie n'est pas un chiffrement vérifié, c'est un espoir : l'aller-
   retour est le seul test de cette classe qui prouve quoi que ce soit.
   L'inclusion s'arrête là — rien de ce qui *lit* une archive portable
   n'est écrit ici.

3. **Le sujet d'aide est scindé**, comme en IT-04 et pour la même raison :
   « Sauvegarder le site » dépassait les 400 mots de la charte
   (`design.md` §7.11) une fois le bloc portable décrit. « Emporter le
   site ailleurs » est le nouveau sujet ; le premier le désigne.

4. **La galerie n'est pas dans l'archive portable**, et `portable` n'est
   donc pas dans `Backup::GALLERY_TYPES`. Le document ne tranche pas ; la
   maquette si, indirectement — sa ligne « Portable » pèse 412 Mo contre
   377 Mo pour « Complète (sans galerie) », et une archive avec galerie
   pèserait plusieurs fois cela. C'est aussi ce que réclame la suite :
   IT-09 l'envoie de façon récurrente, et les photos sont ce qui la
   rendrait trop lourde pour partir.

**Ce qu'un test a trouvé et qu'aucune relecture n'aurait vu.** Le test qui
construit une *vraie* archive puis la rouvre a échoué du premier coup, sur
le second fichier scellé. C'est le défaut décrit au point 3 ci-dessus :
l'archive se produisait parfaitement, et la moitié de ce qu'elle
transportait était perdue. Aucun test unitaire sur `SecretEnvelope` ne
pouvait le voir — la classe était juste ; c'est son contrat avec
l'appelant qui permettait la faute.

Vérifié de la même façon dans l'autre sens : en réintroduisant les deux
erreurs que ces tests existent pour interdire. Écrire le clair au lieu du
scellé fait échouer deux tests et seulement eux ; retirer l'exclusion de
`storage/keys` en fait échouer deux autres, et pas les mêmes. Deux fautes
différentes, deux gardes différentes.

**Et un troisième, entre le gabarit et l'écran.** L'assertion « la page
rend bien `minlength="16"` » a échoué : le partiel `form_field` ne
connaissait pas cet attribut, et l'ajout était tombé dans la branche
`textarea` au lieu de la branche `input`. Conséquence réelle et
silencieuse : le champ ne portait aucune contrainte de longueur côté
navigateur, et le contrôle JavaScript — qui lit précisément cet attribut
pour éviter un aller-retour inutile — comparait à zéro, donc laissait tout
passer. Le serveur refusait toujours, la règle n'était donc jamais
enfreinte ; mais les deux gardes censées épargner un aller-retour à
l'opérateur étaient mortes. L'assertion porte sur le HTML *rendu* et non
sur la source du gabarit : c'est ce qui fait la différence entre vérifier
qu'on a écrit quelque chose et vérifier que ça arrive à l'écran.

**Et un second défaut, dans un test cette fois.** La première version de
`PortableDispatchTest` lisait le fichier du gestionnaire en entier — or son
docbloc de classe mentionne `createPortableBackup()` en prose. Toutes ses
assertions passaient donc avec l'appel supprimé et le commentaire qui le
décrit laissé en place : un test qui vérifie la documentation de la chose
au lieu de la chose. C'est la faute d'IT-04 sur `GALLERY_TYPES` et celle
qu'une revue d'IT-05 a relevée sur un cliquet, une troisième fois : ce
qu'un code *dit de lui-même* n'est pas une preuve de ce qu'il fait. Le
tokenizer dépouille désormais les commentaires, et la vérification a été
refaite en retirant la branche tout en gardant le docbloc — deux tests
échouent, ce qui est le comportement voulu.

**Deux constats de revue, dont un qui a rouvert une décision verrouillée.**

1. **Un chemin de fichier dans un message destiné à l'écran.**
   `BackupException` porte le marqueur `UserFacingException`, qui est une
   affirmation sur *tout* message dont la classe est construite. Le chemin
   part désormais en `$previous` : le diagnostic garde le détail, l'écran
   ne l'affiche plus.

2. **La seconde enveloppe ne protégeait rien**, pour la raison écrite au
   point 1 des décisions ci-dessus. Le correctif touchant D4, la question
   a été posée au mainteneur avec trois options — renforcer le zip,
   documenter sans corriger, retirer l'enveloppe — et la réponse a été
   **renforcer le zip**. L'auto-fusion est restée désarmée entre-temps :
   une PR qui affirme dans `SECURITY.md` une protection qui n'existe pas
   ne doit pas être fusionnée.

**Et une archive portable proposée à la restauration.** Troisième constat,
et le plus coûteux à l'exécution : une archive portable satisfait tous les
tests du chemin de restauration — `completed`, avec un dump —, donc la
passe restaurait la base, échouait ensuite sur `secrets/` à l'extraction
des fichiers, et rejouait le retour en arrière depuis la copie de
sécurité. Le site s'en remettait, après avoir été remplacé puis
dé-remplacé pour une opération qui ne pouvait pas aboutir. Le refus est
maintenant en amont : le contrôleur, le sélecteur qui ne la propose plus,
et le gestionnaire lui-même — **avant sa sauvegarde de sécurité**.

La première correction plaçait ce dernier refus dans `resolveSource()`, et
une seconde passe de revue a montré que son commentaire décrivait le cycle
depuis l'intérieur de ce cycle : la copie de sécurité (dump complet plus
archive avec galerie, des minutes sur une vraie installation) est prise
*avant*, et tout ce qui est levé ensuite est rattrapé par un vrai retour en
arrière. Refuser tard, ce n'est pas refuser. La justification invoquée —
« une tâche reprise porte une charge utile antérieure » — était fausse de
surcroît : le chemin `resume_migration` rend la main bien avant. Le refus
est remonté en tête de `handle()`, et un test distingue les deux : refusé
tôt, le gestionnaire journalise `backup_restore_refused` et rien d'autre ;
refusé tard, il journalise `backup_restore_failed` comme tous les autres
cas de cette classe de tests. Vérifié en remettant la garde à l'endroit
signalé — deux tests tombent.

**Reporté.** Tout ce qui *lit* une archive portable : la restauration, la
lecture du manifeste, la conservation des identifiants de la cible (D5) et
le nouvel `installation_id` (D6) sont IT-07. Le champ `installation_id` du
manifeste est écrit mais rien ne le relit encore. La destination distante
(IT-08) et l'envoi récurrent (IT-09) non plus.

## IT-07 — La restauration portable

La moitié lecture d'IT-06, et celle où les erreurs sont destructrices
plutôt que simplement inutiles.

**Ce qui a été livré.** `PortableArchive` ouvre et authentifie une
archive : commentaire en clair, dérivation des deux clés, manifeste
chiffré, empreintes des membres déclarés — dans cet ordre, et entièrement
avant la première écriture. `PortableRestore` applique le résultat :
extraction, installation des clés, conservation des identifiants de la
machine (D5), nouvelle identité (D6), abonnements push vidés.
`RestoreBackupHandler` route vers ce chemin une archive téléversée qui
s'annonce dans son en-tête, et `SetupController` offre la même chose
depuis l'assistant, avec téléversement fragmenté. La charge de
statistiques porte `restored_from`. Un sujet d'aide décrit la marche à
suivre complète.

**Décision autonome, et la plus lourde : le code n'est jamais restauré.**
L'archive porte `core/`, `modules/` et `public/`, et la restauration les
ignore. Cela ressemble à jeter la majeure partie du fichier, donc voici
le raisonnement en entier. La règle de version n'autorise que deux cas.
Sur la même version, extraire `core/` réécrit des milliers de fichiers
pour rien. Sur une installation **plus récente**, c'est une
rétrogradation silencieuse — et elle contredit l'étape suivante, la
migration de schéma n'existant que pour amener un dump ancien vers du
code récent : il faut donc que le code récent soit encore là. Le
troisième cas, une installation plus ancienne, n'existe pas ici puisque
l'archive est refusée avant. Cela supprime en outre un danger au lieu de
le gérer : extraire `core/` remplacerait le code du processus en train de
tourner, le mélange qui a coûté six retours en arrière consécutifs en
production. C'est ce qui permet à l'assistant de migrer dans la même
requête. Les entrées restent **dans** l'archive : c'est un seul zip, et
qui veut l'arborescence d'origine peut l'ouvrir.

**Décision autonome : une ligne portable de la liste de ce site reste
refusée.** IT-06 la refusait « en attendant IT-07 » ; elle le reste, mais
pour une raison qui tient debout seule. Une archive portable est faite
pour être emportée et téléversée ailleurs ; la restaurer sur le site qui
l'a produite, c'est une sauvegarde complète en moins bien — le même site,
sans la galerie. Le chemin réel est donc le téléversement, depuis
Maintenance ou depuis l'assistant.

**Décision autonome : une version de développement n'est ordonnée contre
rien.** `version_compare()` classe `dev-a1b2c3d` sous toute release, ce
qui laisserait passer une archive de développement sur une release et
refuserait l'inverse — deux réponses obtenues par accident. Quand l'un
des deux côtés est un build de développement, ou qu'une version est
inconnue, la comparaison s'abstient. Le trou est délibéré et de la bonne
taille : un build de développement est une copie de travail, pas une
installation dont une unité dépend.

**Divergence entre le document et le dépôt.** Le document demande la
section correspondante dans `specifications.md` ; ce fichier décrit les
menus et n'a pas de section consacrée à l'assistant d'installation. La
ligne « Maintenance » du tableau a donc été complétée, et rien n'a été
inventé autour.

**Le manifeste n'énumère pas les arborescences**, décision d'IT-06 que
IT-07 hérite : « empreintes vérifiées avant extraction » porte donc sur
les membres déclarés, c'est-à-dire les deux secrets scellés — ceux dont
la corruption est silencieuse. Le CRC par entrée du format zip couvre le
reste, et c'est lui qui attrape la corruption qui arrive réellement à une
archive transportée sur une clé USB.

**Réparation en passant.** `ARCHITECTURE.md` plaçait §8.100 à §8.102 —
IT-04, IT-05 et IT-06 — après le titre « ## 9. Installation / bootstrap »,
donc dans la mauvaise section. §8.103 s'y ajoutait naturellement, ce qui
aurait fait quatre. Les quatre sont remontées dans la section 8, dans
l'ordre numérique ; le diff se limite au déplacement du titre de la
section 9 et au texte neuf.

**Ce que les gardes prouvent.** Chacune a été vérifiée en réinjectant
exactement le défaut qu'elle interdit : retirer la remise en place des
identifiants fait tomber le test D5 et lui seul ; neutraliser le routage
portable fait tomber le test qui distingue un refus tôt d'un refus tard ;
retirer la garde « site déjà configuré » fait tomber le test
correspondant de l'assistant.

**Un constat de revue, et c'était le bon.** « Rien n'est écrit avant que
tout ce qui peut refuser ait refusé » est l'invariant que cette
fonctionnalité affiche partout — et une moitié ne le tenait pas.
`restorableEntries()`, la marche qui refuse un chemin en `..`, un lien
symbolique ou une charge au-delà du plafond de quatre gigaoctets, n'était
atteinte que par `extractFiles()`, qui s'exécute **après** le
remplacement de la base. Le refus arrivait bien, à une installation dont
les données venaient d'être écrasées : l'archive hostile coûtait
exactement ce qu'elle aurait coûté si elle avait été acceptée. La marche
ne lit que le répertoire central du zip, donc la remonter en tête
d'`apply()` ne coûte rien. Le test ajouté n'affirme pas que l'archive est
refusée — cela n'a jamais fait de doute — mais que la base de la cible
est intacte quand elle l'est ; en rétablissant l'ordre d'avant, c'est
exactement lui qui tombe.

**Un plafond qui ne plafonnait que le disque.** Le refus « archive trop
volumineuse une fois décompressée » est fixé à quatre gigaoctets — or
aucun hébergement n'a quatre gigaoctets de `memory_limit`. Tant que le
code lisait les membres avec `getFromName()`, un dump *parfaitement
ordinaire* d'un gros site suffisait à faire mourir la restauration au
moment de la vérification des empreintes, bien avant que le plafond soit
consulté. Ce n'était donc pas un cas hostile mais le cas courant. Les
empreintes se calculent désormais par flux (`hash_update_stream()`) et le
dump est recopié sur le disque par `stream_copy_to_stream()` : la mémoire
maximale d'une restauration ne dépend plus de la taille du site restauré.
Le test le mesure sur le pic et non sur le niveau — une chaîne allouée
puis libérée laisse le niveau où il était, ce qui est exactement la faute
en question.

**Un tableau vide qui voulait dire le contraire de ce qu'il disait.**
`targetOwnedSecrets()` renvoyait `[]` quand les secrets du site ne
pouvaient pas être lus. Mais `[]` signifie « cette machine n'a aucun
identifiant à conserver », et `installSecrets()` ne remplace que les clés
qu'on lui donne : un `secrets.enc` illisible se terminait donc avec
l'hôte, le nom et le mot de passe de la base de **l'origine** en place —
précisément D5, atteint par une panne que personne ne verrait. Le refus
ne coûte rien, puisqu'il précède la sauvegarde de sécurité.

**Une photo des clés qui ne survivait pas au processus qui l'a prise.**
Sur le chemin Maintenance la migration est différée : une restauration
peut donc échouer sur une passe qui s'exécute des heures plus tard, dans
un autre processus, alors que la photo en mémoire a disparu depuis
longtemps. Le retour en arrière y restaure la base et l'arborescence
depuis une archive de sécurité qui, par construction, ne contient ni
`storage/keys/` ni `storage/config/` : le site revenait donc avec sa
propre base et la clé de l'archive, pendant que le journal annonçait une
récupération propre. Les clés sont désormais mises de côté sur le disque
sous `storage/temp` — le seul arbre qu'un retour en arrière ne touche
pas, qu'aucune archive ne contient et qu'une sauvegarde portable n'a plus
le droit d'écrire — et c'est le *chemin* qui voyage dans la charge du
planificateur, jamais la matière chiffrante, qui se retrouverait sinon
dans une ligne de base lisible par tout ce qui lit la file.

**Un plafond de quatre gigaoctets n'est pas un plafond pour une clé.**
Les deux secrets scellés sont les seuls membres lus d'un bloc plutôt
qu'en flux — ils sont descellés, pas recopiés — et ils recevaient le
plafond générique, qui admet une taille qu'aucun hébergement ne peut
tenir en mémoire. Or `unsealSecrets()` s'exécute en dernier, après le
remplacement de la base et de l'arborescence, et une fatale de
`memory_limit` n'est pas une `Throwable` : le retour en arrière ne se
serait donc jamais exécuté. Ils ont leur propre plafond, vérifié aussi
dans `verifyDeclaredMembers()`, c'est-à-dire avant la première écriture.

**Un manifeste peut aussi mentir par omission.** Vérifier les empreintes
de ce qu'un manifeste énumère ne dit rien de ce qu'il tait : une archive
qui ne déclarait tout simplement pas ses deux secrets scellés passait
tous les refus d'avant-écriture et n'était attrapée que par
`unsealSecrets()`, après le remplacement de la base et de
l'arborescence. Leur présence dans la liste déclarée est désormais exigée
là où les autres refus se font.

**Et une comparaison sensible à la casse là où le système de fichiers ne
l'est pas.** `storage/Temp/twig_cache/intrus.php` franchissait la liste
noire et atterrissait dans `storage/temp/` sur Windows ou un volume macOS
par défaut. Une garde dont le sujet est « où ce fichier va finir » doit
raisonner sur l'idée de « pareil » du système de fichiers, pas sur celle
de PHP. La liste blanche, elle, reste sensible à la casse : `Storage/...`
n'est pas admis du tout, donc rien n'est extrait.

**Une taille annoncée n'est pas une taille.** Le plafond se lit dans le
répertoire central du zip, c'est-à-dire chez celui qui a écrit l'archive.
Une entrée peut annoncer quelques kilo-octets et se décompresser en
gigaoctets — les taux DEFLATE au-delà de 1000:1 sont ordinaires — et
`PortableRestore` n'a pas de budget disque à lui. La taille annoncée
borne donc désormais la *recopie* elle-même, au lieu d'être comparée
après coup à un disque déjà plein. Et borner seul ne suffisait pas : une
charge plus longue que son propre en-tête serait alors silencieusement
tronquée, or un dump coupé sur une frontière d'instruction se restaure
sans protester. Un octet est donc lu au-delà du plafond, et sa présence
refuse l'archive. Le test forge l'archive à la main — `ZipArchive` ne
sait pas produire un fichier qui ment sur lui-même, et un fichier qui
ment sur lui-même est tout le sujet.

**Sous `storage/` n'est pas la même chose que « des données ».** La liste
blanche `storage/` laissait passer `storage/temp/twig_cache/`, où vivent
les gabarits compilés que le rendu suivant fait `include` : une archive
capable d'y déposer un fichier était une archive capable d'exécuter du
code sur le site qui la restaure — et le modèle de menace de cette
fonctionnalité, c'est précisément l'archive qu'un inconnu remet avec sa
phrase de passe. Les sous-arbres que l'écrivain ne produit jamais
(`keys`, `config`, `temp`, `maintenance`) sont désormais refusés à la
lecture, et la liste est la sienne
(`BackupService::NON_ARCHIVED_STORAGE_SUBDIRS`) plutôt qu'une seconde à
tenir à jour. La galerie n'y est pas : elle est exclue par périmètre, pas
par nature.

**Un garde-fou qui n'en était pas un.** `restorableEntries()` filtrait les
entrées par une liste blanche (`storage/`) *puis* par une liste noire
(`secrets/`). La seconde ne pouvait jamais s'exécuter : un nom qui
commence par `storage/` ne commence pas par `secrets/`. Elle a été
retirée plutôt que couverte — du code inatteignable qui se lit comme une
protection est pire qu'absent, puisqu'il invite à croire que la
protection existe à deux endroits. La liste blanche, elle, refuse les
secrets scellés, le manifeste et le dump de la seule façon qui vaille :
en n'ayant jamais dit oui.

**Reporté.** La destination distante (IT-08) et l'envoi récurrent avec
rétention distante (IT-09). Le téléversement fragmenté de l'assistant ne
consulte pas le budget disque, faute de base de données où lire un quota
à ce moment-là ; le magasin de fragments applique son propre plafond.
