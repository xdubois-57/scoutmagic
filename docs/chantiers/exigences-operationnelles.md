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
chantier le demande. `ARCHITECTURE.md` §8.96, le sujet d'aide
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

**Reporté.** La saisie du quota se fait sur la page générique
Configuration > Réglages, pas sur la page Maintenance : le document de
chantier borne l'interface de cette itération au seul encart de lecture.
Un champ de saisie à côté de l'encart serait plus direct ; il n'est pas
dans le périmètre et n'a rien de bloquant, l'encart nommant le réglage à
remplir.
