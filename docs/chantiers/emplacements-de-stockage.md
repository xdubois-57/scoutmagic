# Chantier — Emplacements de stockage

Journal d'implémentation du document de chantier « Emplacements de
stockage » (itérations IT-01 à IT-07). Une section par itération : ce qui a
été fait, les décisions prises en autonomie, les divergences constatées
entre le document de chantier et le dépôt réel, et ce qui a été reporté.
Même format que `docs/chantiers/aide-contextuelle.md`.

Les deux maquettes qui accompagnent ce document sont déposées sous
`docs/chantiers/maquettes/` (`maquette-stockage.jsx`,
`maquette-galerie-config.jsx`) et inscrites au tableau de son `README.md`.

---

## Les décisions verrouillées, recopiées

Elles viennent du document de chantier et ne se rouvrent pas. Recopiées ici
parce qu'un refus non documenté se rouvre, et que ce journal est ce qui
survit à la session.

| # | Décision |
|---|---|
| D1 | Les emplacements vont dans le cœur — les sauvegardes sont du cœur et ne peuvent pas dépendre d'un module. |
| D2 | `Core\Storage` existe déjà (mesure du volume local) et ne se renomme pas : les emplacements vont dans `Core\Storage\Location\`. |
| D3 | Un socle commun, le reste en capacités déclarées — et les capacités ne s'affichent jamais telles quelles. |
| D4 | L'affectation appartient au consommateur : on déclare au centre, on choisit chez soi. Pas de table de liaison. |
| D5 | La migration d'album reste dans la galerie. |
| D6 | `/files/{id}` reste local, hors périmètre. |
| D7 | Les identifiants d'un emplacement vivent dans une colonne `BLOB` chiffrée de sa ligne, pas dans `secrets.enc`. |
| D8 | `storage/` n'est pas déplaçable — refus délibéré. |
| D9 | Les galeries ne sont pas interdites sous `storage/` — refus délibéré. |
| D10 | Le chemin ne décide plus de rien : une archive reprend `storage/` moins tout répertoire déclaré comme emplacement. |
| D11 | La protection est générique : la copie de secours d'un emplacement est un autre emplacement. |
| D12 | Rien en base ne fait autorité : l'inventaire vit dans un fichier, dans la destination. |
| D13 | Le délai de grâce est un attribut du fichier, pas de l'opération. |
| D14 | Pas de compteur de tentatives. |
| D15 | Un inventaire de source incomplet ne supprime jamais rien. |
| D16 | Pas de reprise des configurations existantes : les lignes `gallery_storage_locations` et les clés `remote_backup_*` sont reconstruites, pas migrées. Le projet est en phase de test. |

---

## IT-01 — Le modèle dans le cœur

**Livré en une seule itération**, sans la coupe que le document autorisait
à la frontière de la table : le déplacement est resté d'un seul tenant
parce que la galerie est le seul consommateur d'aujourd'hui et que la
couper en deux aurait fait exister, le temps d'une PR, deux modèles
d'emplacement au lieu d'un.

### Ce qui existe maintenant

`Core\Storage\Location\` :

- `StorageCapability` — l'énumération des capacités (lecture par plage, URL
  signée, envoi repris, quota, empreinte annoncée, copie côté serveur), et
  sa règle : **elles ne s'affichent jamais telles quelles** (D3).
- `StorageCapabilities::require()` — le seul endroit où une capacité
  absente devient un refus. Sans lui, le mode de panne est
  « Call to undefined method » : une page blanche au lieu d'une phrase.
- `StorageLocationType` — `local`, `s3`. Une énumération PHP contre une
  colonne `VARCHAR`, pour qu'un nouveau type coûte un `case` et rien du
  tout en base ; `capabilities()` **interroge la classe du backend** et ne
  redit rien, de sorte que la comparaison d'IT-07 ne pourra pas mentir.
- `StorageLocation` — l'entité, qui ne porte **jamais** le secret, et
  `StorageLocationRepository`, seul lecteur de celui-ci.
- `Config\LocationConfig` + `LocalLocationConfig` +
  `ObjectStorageLocationConfig` — la configuration par type, sérialisée
  dans une colonne unique.
- `StorageLocationService` — création, renommage, test de santé mis en
  cache, suppression refusée quand un usage en dépend, et
  `ensureDefaultExists()`.
- `StorageLocationConsumer` / `StorageLocationConsumerRegistry` — la
  réponse à la seule question que D4 laissait ouverte (voir plus bas).
- `Backend\` — l'interface socle, `RangeReadableBackend`,
  `ServerSideCopyBackend`, les deux backends déplacés depuis
  `Modules\Gallery\Service\Storage\`, et la fabrique.

La table `storage_locations` est dans `schema/core.sql`, avec les
commentaires de `gallery_storage_locations` repris : ils expliquent
pourquoi l'unicité du défaut est tenue dans le repository et pourquoi la
santé est mise en cache, et ils valent mieux que ce qui aurait été
réécrit.

### Décisions prises en autonomie

**Il fallait quand même répondre à « qui utilise cet emplacement ? ».** D4
supprime la table de liaison, ce qui est la bonne forme — mais laisse
exactement une question sans réponse, et c'est celle dont dépend le refus
de supprimer un emplacement encore servi. La réponse est
`StorageLocationConsumer` : un consommateur dit comment il s'appelle, en
français, et sur quels emplacements il se tient. Trois conséquences
voulues : la page peut écrire « Sert : Galeries photo » au lieu d'un
compteur, le refus de suppression cite le nom de l'usage au lieu d'une
violation de clé étrangère, et un module désactivé ne déclare rien — ce
qui est la bonne réponse, puisque ses fichiers sont toujours là mais que
plus rien ne les lit. Le registre est mutable et rempli par les blocs de
modules (ARCHITECTURE.md §7.6) : `public/index.php` est un script
linéaire, et le service qui pose la question est construit avant les
modules qui y répondent.

**`url()` devient `directUrl(): ?string`.** L'interface d'origine
promettait une URL à tout le monde, et le local rendait une route de la
galerie — du code de module dans ce qui allait devenir du cœur. La
généralisation est la nullité : un backend qui **ne sait pas** servir le
visiteur répond `null`, et le consommateur sert les octets par sa propre
route contrôlée. C'est ce que la galerie faisait déjà pour le local avec
un `if ($location->isS3())` ; c'est ce que WebDAV fera en IT-06 sans
inventer un second chemin (le document l'exige explicitement à cette
itération : « généralise ce chemin, n'en invente pas un second »).

**Le chemin d'un emplacement local accepte l'absolu.** Le champ `subdir`
devient `path` : relatif, il pend sous `storage/` — le cas ordinaire, sans
configuration ; absolu, il est pris tel quel, ce dont IT-02 a besoin pour
un montage réseau. La règle est résolue **une fois**, dans
`Backend\StorageBackendFactory`, pour que rien d'autre n'ait à la
réimplémenter de travers.

**`servesPubliclyWithoutExpiry()` est sur l'interface de configuration**,
donc obligatoire pour chaque type. La galerie avait un
`if ($location->s3PublicUrl !== null)` à trois endroits, pour une raison
sérieuse : un album délégué stocké derrière une URL publique permanente
est un album privé publié à qui a le lien. Généralisé en question posée à
chaque type plutôt qu'en test sur un champ S3, un nouveau backend qui
oublie d'y répondre ne compile pas — et un qui y répond de travers publie
les photos de quelqu'un.

**Les colonnes de `gallery_albums` sont renommées, pas repointées.**
`storage_location_id` → `location_id`, `migration_target_location_id` →
`migration_target_id`, avec de nouvelles clés étrangères vers
`storage_locations`. Repointer les anciennes était impossible : leurs
valeurs sont des identifiants de la table retirée, et aucune contrainte
vers la table du cœur ne les aurait acceptées. Conformément à D16 la
configuration est **redéclarée**, pas convertie ; un album qui se retrouve
sans emplacement est replacé sur le défaut par
`GalleryLocationService::resolveLocationForAlbum()`. **Conséquence assumée
et à dire** : sur une installation de test qui avait des albums sur S3,
ces albums repartent sur le disque local et leurs médias ne sont plus
trouvés tant que l'emplacement n'est pas redéclaré et l'album migré. C'est
exactement ce que D16 accepte.

**`gallery_s3_secret` disparaît vraiment.** Vérifié plutôt que supposé :
son unique lecteur restant était la reprise de configuration, qui part
avec ce changement. La table et `gallery_storage_locations` sont
supprimées par un `modules/gallery/drops.sql` neuf —
`MigrationRunner::applyExplicitDrops()` reconnaît bien `DROP TABLE`,
contrairement à ce que disait le commentaire (périmé) de l'ancien schéma.

**`GalleryStorageWiring`** rassemble l'assemblage du graphe de stockage
hors composition root. Trois endroits le montaient à la main — les deux
handlers de traitement de média et `Api\DelegatedAlbumManagerFactory` — et
les trois copies avaient déjà divergé. Les tests s'en servent aussi, ce
qui supprime la quatrième copie.

### Tests

Les tests de stockage existants passent, déplacés sous
`tests/Core/Storage/Location/`, sans changement de comportement. S'y
ajoutent : une capacité absente refusée par une phrase française et non
par une méthode manquante ; l'unicité du défaut vérifiée en comptant les
lignes et pas seulement en relisant deux emplacements ; le secret jamais
atteignable hors du repository ; l'ETag multipart d'S3 **non** rendu comme
empreinte comparable (le piège qui ferait conclure que toutes les copies
sont corrompues) ; la pagination d'un listage, des deux côtés ; une
suppression de clé absente qui réussit ; une ligne dont le type est
inconnu de cette version refusée plutôt que lue de travers.

### Ce que la relecture a trouvé, et ce qui manquait sous chaque trouvaille

Quatre défauts, et les quatre avaient le même parent : **une valeur nulle
qui veut dire « pas encore résolu » et qu'on a lue comme « rien »**.

**Le `drops.sql` visait les nouvelles colonnes.** Le renommage de
`storage_location_id` a été appliqué à tout le dépôt et a réécrit le
fichier de suppressions avec le code. Les deux `DROP FOREIGN KEY`
au-dessus nommaient toujours les anciennes contraintes — c'est
exactement ce qui rendait le fichier cohérent à la lecture.
`Tests\Architecture\ExplicitDropsTargetRetiredColumnsTest` pose la règle
pour tous les `drops.sql` : une suppression explicite ne peut nommer
qu'une colonne, ou une table, que le schéma d'à côté a cessé de déclarer.
`applyExplicitDrops()` est le seul mécanisme du chemin de migration qui
détruit des données, et rien ne le relisait.

**Le garde-fou des albums délégués était inerte.** Le filtre
« Migrer vers » lisait une propriété partie avec l'ancienne entité ;
`strict_variables` étant désactivé, Twig résout l'attribut manquant en
`null`, et « not null » est vrai pour tous les emplacements. Rien n'était
exposé — le serveur refusait la migration de toute façon — mais la moitié
qui évite à un administrateur de l'apprendre en essayant ne servait plus
à rien.

**Une migration pouvait geler un album pour toujours.** Un album dont
`location_id` est nul ne vit pas nulle part : il vit sur le défaut et on
ne le lui a pas encore écrit. Comparer la cible au nul brut faisait
passer « migrer vers le défaut » pour un déplacement vers un autre
emplacement ; la passe suivante ne trouvait pas de source et **repartait
sans rien dire**, laissant `migration_status` à `in_progress` pour
toujours — album indisponible, et chaque reprise refusée par le garde-fou
qui lit cette même colonne. Corrigé des deux côtés : le service résout
l'emplacement avant de comparer, et le handler marque la migration en
échec au lieu de se taire. Une migration qui ne peut pas démarrer doit le
dire, sans quoi elle est indiscernable d'une migration en cours.

**La même valeur nulle, une troisième fois, sur le chemin qui dessine
l'écran.** Corrigée dans `AlbumService`, elle survivait dans le gabarit :
le tableau de migration comparait la colonne brute, disait donc
« Non défini » d'un album qui se tient visiblement sur le défaut, et lui
proposait comme cible l'emplacement où il est déjà. Le contrôleur résout
maintenant l'emplacement effectif — par un
`GalleryLocationService::effectiveLocationId()` **qui n'écrit rien** :
épingler est juste au moment où quelque chose va toucher les fichiers,
pas quand un écran se contente de lister.

**Et deux orthographes du même dossier par défaut** — puis une troisième,
que la correction a créée. Le formulaire et le repli de
`normalizeSubdir()` disaient `gallery` là où j'avais écrit
`modules/gallery` dans la constante : accepter le formulaire tel quel
produisait un emplacement pointant vers un autre répertoire que celui que
le site avait déjà fait, tous deux présentés comme le stockage local par
défaut.

Unifier était juste ; avoir unifié **vers `modules/gallery`** ne l'était
pas. Deux endroits du cœur nomment encore ce dossier à la main —
`BackupService::excludedArchivePrefixes()`, qui est ce qui tient les
photos hors d'une archive à qui on a demandé de ne pas les porter, et
`DiskBudget::measureNow()`, qui est ce qui les compte comme la part de la
galerie plutôt que comme « divers » — et les deux se sont retrouvés à
désigner un répertoire vide. Une sauvegarde emportait donc en silence les
gigaoctets qu'on lui avait dit de laisser, et la répartition annonçait
une galerie à zéro. Rien n'échoue dans les deux cas : l'archive est
produite, la page s'affiche, les chiffres sont simplement faux.

**D16 parle des lignes de configuration, pas des photographies.** Il
autorise à redéclarer les emplacements en base ; il ne dit rien d'un
déplacement de fichiers sur le disque, et une constante ne doit pas le
faire discrètement. La constante vaut donc `gallery` — le répertoire qui
contient déjà quelque chose — et `Tests\Core\Storage\
DefaultStorageFolderTest` tient les trois lectures ensemble, par deux
assertions de comportement : les octets écrits sous ce dossier sont
comptés comme la galerie, et une archive priée de l'exclure est plus
petite que celle qui l'emporte.

**Et le défaut pouvait être supprimé sous les pieds des albums.**
`distinctLocationIds()` ne comptait que les identifiants écrits, donc un
emplacement par défaut sur lequel se tenaient des albums non encore
épinglés était rapporté comme inutilisé — et un emplacement inutilisé est
un emplacement que la page propose de supprimer. Suppression propre,
aucune violation de contrainte, aucun avertissement, et les albums
résolvaient ensuite vers le défaut suivant, où leurs fichiers ne sont
pas. Le consommateur rapporte maintenant le défaut dès qu'un album local
n'épingle rien.

**Et une leniency écrite pour l'affichage servait de garde à la
suppression.** Le registre avalait l'exception d'un consommateur — juste
pour une page, qui perd une ligne plutôt que de ne plus s'ouvrir du tout,
et c'est la même transaction que fait le calendrier avec ses événements
contribués. Mais la même réponse autorisait aussi la suppression, et là
« je n'ai pas pu demander » et « personne ne s'en sert » sont des
conclusions opposées : avalée, une erreur passagère de base de données
devenait une suppression propre d'un emplacement sur lequel quelque chose
se tenait encore. Les albums ayant un `location_id` écrit restaient
protégés par la clé étrangère ; ceux qui résolvent vers le défaut
n'avaient rien du tout.

Le registre dit maintenant la vérité, et la leniency est descendue chez
les appelants qui peuvent se la permettre : le service l'attrape pour les
écrans, `delete()` ne l'attrape pas et refuse. Les deux restent cohérents
quand ils divergent — une page dont la ligne « Sert : … » est sortie vide
propose le bouton, et le bouton tombe sur le refus.

**Et le garde de service lisait encore les champs d'un backend.** Le
re-contrôle au moment de servir les octets — celui que `SECURITY.md`
exige, parce que l'invariant n'est autrement tenu qu'à la création de
l'album — était écrit « est-ce une configuration S3 portant un préfixe
public ». Or cette question répond « non » pour toute forme de stockage
qui n'existait pas quand elle a été écrite, et répondre « non » ici
livre les octets. C'est précisément ce que
`servesPubliclyWithoutExpiry()` avait été mis sur l'interface pour
empêcher, et le seul des trois appels à ne pas l'utiliser.

**Et une ligne illisible pouvait éteindre le site entier.** Le calcul
des origines `img-src` a été sorti de son `isset()` et posé **après**
`ErrorHandler::guard()`, là où la réponse est déjà construite — alors que
lire ces lignes peut lever, puisqu'une ligne dont le `type` est inconnu
de cette version est refusée plutôt que mal lue. Une seule ligne
abîmée, et c'est une réponse terminée qu'on jette, sur toutes les routes
du site à la fois, y compris la page de configuration où il faudrait
aller la corriger. Le bloc est désormais gardé, et
`Tests\Architecture\ResponseTailCannotThrowTest` pose la règle pour la
queue de réponse entière : ce qui vient après la frontière d'erreur ne
lève pas, parce qu'une panne y coûte un en-tête et jamais le site.

**Le nul « pas encore résolu » avait une troisième page.** Corrigé dans
`AlbumService`, puis sur l'écran de configuration, il restait sur la fiche
d'édition d'album — la seule autre page qui imprime un emplacement.
Mêmes remèdes : le contrôleur résout, sans écrire.

**La requête du bandeau CSP tournait à chaque requête.** Sortie de son
`isset()`, elle lisait `storage_locations` sur toutes les routes, y
compris sur une installation sans galerie. Le registre sait maintenant
répondre « personne ne consomme d'emplacement ici » **de mémoire** —
`isEmpty()` et non `all()`, qui interrogerait chaque consommateur, donc
la base, exactement le coût qu'on voulait éviter.

**Et une méthode morte est partie plutôt que d'être testée.**
`locationForNewAlbums()` n'avait aucun appelant, et son docblock décrivait
un réglage « emplacement des nouveaux albums » qui n'existe pas encore :
elle se lisait comme du comportement livré. C'est IT-02 qui apporte ce
réglage, et qui écrira la méthode contre lui.

### Reporté à l'itération suivante, explicitement

`GalleryLocationService::diskSpaceFor()` est gardé tel quel pour une
itération, avec un commentaire qui dit que sa forme est fausse : la place
libre est une propriété d'un **volume**, pas d'un dossier, et trois
emplacements sur un même disque y afficheraient chacun la même place. La
mesure par volume et l'écran qui l'énonce sont IT-02 ; cette méthode part
avec eux.

### La seconde relecture, et ce qu'elle a durci

La première relecture avait trouvé des nuls mal lus. La seconde a trouvé
autre chose : **des retours qu'on ne regardait pas**.

**Le disque pouvait refuser d'écrire sans que personne le sache.**
`LocalStorageBackend` appelait `mkdir()`, `file_put_contents()`,
`unlink()` et `rmdir()` sans lire ce qu'ils répondaient. Un disque plein,
un dossier en lecture seule, un quota atteint : la photo n'était pas
écrite, et l'appelant enchaînait comme si elle l'était — vignette
générée depuis un fichier absent, ligne en base pointant vers rien. Les
quatre lèvent désormais, et une écriture partielle (moins d'octets que
demandé) est traitée comme un échec, parce que c'en est un.

**Le contrôle de chemin lisait le texte, le système suivait les liens.**
La vérification lexicale d'échappement empêchait un `..` dans une clé,
mais pas un lien symbolique déposé dans le dossier de l'emplacement et
pointant ailleurs : le texte du chemin reste sous la racine, le système
de fichiers, lui, sort. `assertNoSymbolicEscape()` remonte jusqu'au
premier ancêtre qui existe, le résout réellement, et compare à la racine
résolue.

**Deux créations simultanées pouvaient poser deux défauts.**
`create()` comptait les lignes puis insérait, hors transaction ; l'index
UNIQUE sur le libellé ne rattrape rien quand les deux libellés diffèrent.
Le couple compte-puis-insère est maintenant dans une transaction. Dans
le même esprit, `setDefault()` refuse une promotion qui n'a touché aucune
ligne : MySQL rapporte zéro ligne affectée aussi bien pour « cette ligne
n'existe pas » que pour « elle était déjà le défaut », donc `rowCount()`
seul ne pouvait pas distinguer les deux, et l'un des deux doit échouer.

**Et `ensureDefaultExists()` avalait trop large.** Le `catch
(\PDOException)` était écrit pour une seule situation — la course perdue
contre une requête concurrente — mais il attrapait aussi bien une table
absente qu'une connexion morte, et les transformait en « cette
installation n'a pas d'emplacement par défaut ». Une phrase qui envoie
l'administrateur chercher dans la configuration du stockage une panne qui
est dans la base. La course est maintenant reconnue à son SQLSTATE, et
le reste voyage.

**L'ETag d'un listage ne dit rien du chiffrement.** `announcedChecksum()`
écartait déjà l'ETag multipart, mais `list()` en annonçait un à partir
d'une entrée de listage — or `ListObjectsV2` ne dit pas si l'objet a été
écrit en SSE-C ou SSE-KMS, et dans ces deux cas l'ETag n'est pas une
empreinte du contenu du tout, sans que sa forme le trahisse. Un listage
n'annonce donc plus d'empreinte, et `announcedChecksum()`, qui interroge
`HeadObject` et reçoit les en-têtes de chiffrement, écarte aussi ces
deux modes.

**Et deux `rtrim()` perdaient la racine.** `rtrim('/', '/')` rend la
chaîne vide, ce qui a deux conséquences distinctes. Côté galerie, un
emplacement absolu configuré à `/` ne recevait plus de mesure de place
libre : `is_dir('')` est faux, donc ou bien « inconnu », ou bien — pire —
le chiffre de la racine de stockage affiché sous le nom d'un autre
volume. Côté disque, `fullPath()` comparait la clé à `$base . '/'`,
c'est-à-dire `//` pour un emplacement à la racine : aucune clé ne
commence par ça, donc **toutes** étaient refusées comme des évasions.
Les deux sont corrigés au même endroit qu'ils sont écrits, et
`GalleryLocationSpaceTest` mesure avec une racine de stockage absente,
pour que le repli ne puisse pas sauver la réponse à la place du correctif.

**Enfin, `ExplicitDropsTargetRetiredColumnsTest` lisait un schéma à la
fois.** Un `drops.sql` de module visant une colonne d'une table déclarée
par le **cœur** passait pour « une table que ce schéma n'a jamais
possédée ». C'est précisément la forme de ce chantier — une table qui
quitte un module pour le cœur —, donc exactement le cas qui laisse une
ligne périmée pointer vers le schéma du voisin. Le test construit
maintenant la carte des tables déclarées sur l'ensemble de
`SchemaFiles::all()`, et son message nomme le fichier qui déclare encore.

### Ce que durcir avait cassé à côté

Faire lever `delete()` était juste, et a rendu **inatteignable** la phrase
que `testConnection()` réservait à ce cas précis. Cette méthode existe pour
transformer une panne en une phrase française sur laquelle un
administrateur peut agir ; la suppression du fichier témoin n'étant pas
gardée, l'exception partait à sa place — hors d'une méthode que l'interface
décrit comme rendant `?string` et ne levant jamais. Le
`StorageLocationService::checkNow()` qui l'attrape plus haut ne rendait pas
la chose acceptable : il la **cachait**, en affichant « L'emplacement n'a
pas pu être ouvert » à la place de « Le fichier témoin n'a pas pu être
supprimé ». Le test `if ($this->exists($key))` qui devait la déclencher
était devenu du code mort, puisque `delete()` ou bien avait supprimé le
fichier, ou bien avait levé.

Les deux suppressions sont maintenant gardées, avec une asymétrie
délibérée : quand c'est la **relecture** qui a échoué, l'échec du nettoyage
est avalé, parce que le diagnostic dont l'administrateur a besoin est
« le fichier n'a pas pu être relu » et non l'incident survenu en sortant.
`ObjectStorageBackend` gardait déjà chacune de ses étapes ; c'est
`LocalStorageBackend` qui faisait exception.

### Et supprimer le défaut laissait la table sans aucun

`setDefault()` se donne beaucoup de mal — transaction, garde sur le nombre
de lignes touchées — pour qu'aucune lecture n'observe deux défauts. La
suppression, elle, faisait un `DELETE` nu : supprimer l'emplacement marqué
par défaut laissait **zéro** ligne marquée, soit l'autre moitié exacte de
l'état que la classe existe pour rendre inobservable.

C'est la moitié qu'on ne voit pas, parce que rien ne casse :
`findDefault()` se rabat sur la plus petite ligne, les albums continuent
d'atterrir quelque part de sensé, et `ensureDefaultExists()` reçoit une
réponse non nulle donc ne répare rien. Ce que l'administrateur voyait,
c'est une page de configuration où **aucun** emplacement n'est marqué
« Défaut » pendant que les nouveaux albums en choisissaient un tout seuls.

Le successeur promu est la plus petite ligne — **exactement celle vers
laquelle `findDefault()` se rabattait déjà**. La promotion ne décide donc
rien à la place de l'administrateur qui ne fût déjà décidé : elle met le
drapeau enregistré d'accord avec l'endroit où les octets vont réellement.
C'est aussi pourquoi la suppression n'est pas simplement refusée —
interdire une opération sans danger pour protéger un invariant qui coûte
un `UPDATE` serait payer le mauvais prix. Supprimer le dernier emplacement
laisse la table vide, et c'est légitime : `ensureDefaultExists()` recrée le
défaut à la requête suivante, comme sur une installation neuve.

### Le troisième moment où un album délégué pouvait se briser

Un album délégué refuse un emplacement qui sert publiquement sans
expiration — à la création (`DelegatedAlbumService::ensureAlbum()`) et de
nouveau au moment de servir les octets (`GalleryController::
serveDelegatedMedia()`, SECURITY.md § Gallery). Il manquait le troisième
moment : l'album est créé sur un emplacement privé, **l'emplacement est
ensuite modifié** pour porter une URL publique, et le garde-fou de service
fait alors exactement son travail — chaque média de chaque album délégué
posé là devient un 404, définitivement, sans que rien nulle part ne dise
pourquoi.

Rien n'était exposé : le garde-fou a tenu, c'est tout l'intérêt de
l'avoir. Mais « a cessé de fonctionner en silence » n'est pas une issue
acceptable pour un formulaire dont le texte d'aide promet que seuls le nom
et les détails de connexion sont modifiables. L'édition est donc refusée
tant qu'un album délégué s'y trouve, avec une phrase qui dit quoi faire
d'abord. Un album ordinaire, lui, n'est pas concerné : c'est le modèle
d'accès délégué — une autorisation à courte durée — qu'un lien public
permanent annule.

**La même brisure avait une seconde porte, sur la même page.** Promouvoir
un emplacement public **par défaut** fait exactement le même dégât que
modifier un emplacement déjà par défaut : un album délégué dont
`location_id` est nul n'est pas « nulle part », il est sur le défaut, et
`resolveLocationForAlbum()` l'y épingle au prochain contact. Comme
`location_id` est une colonne toute neuve, c'est l'état de **tous** les
albums délégués d'une installation mise à jour, pas un cas limite.
`setDefault()` est donc gardé du même prédicat, avec `true` en second
argument puisque la question porte sur l'emplacement qu'on s'apprête à
faire devenir le défaut.

**Et la lecture qui décide de la promotion ne verrouillait pas.** Le
docblock de `create()` explique pourquoi une transaction ne suffit pas —
sous REPEATABLE READ un `SELECT` nu lit un instantané et ne pose aucun
verrou — et `delete()` faisait exactement cela pour savoir si la ligne
supprimée portait le drapeau. Un `setDefault()` concurrent pouvait
déplacer le drapeau entre cette lecture et la promotion qui en dépend, et
deux lignes se retrouvaient marquées. `isDefaultWithin()` verrouille donc
sa lecture, et la clause est factorisée pour que les deux endroits qui en
ont besoin la prennent au même endroit. `setDefault()`, lui, n'en a pas
besoin : sa lecture suit son propre `UPDATE … SET is_default = 0`, qui a
déjà verrouillé toutes les lignes de la table dans la même transaction.

L'entrelacement lui-même n'est pas atteignable depuis un test — il
faudrait glisser une seconde transaction entre deux instructions de
`delete()` — et le test ajouté le dit : il épingle le mécanisme (la
lecture verrouillée fait bien attendre un écrivain concurrent sur le vrai
moteur), pas le scénario.

**Et le refus ajouté plus haut sortait en HTML d'un point JSON.** Faire
lever `setDefault()` quand la ligne a disparu était juste ; l'action du
contrôleur, elle, n'attrapait rien, contrairement à sa voisine `delete()`.
La ligne peut disparaître entre le `findById()` de l'action et la
promotion — supprimée depuis une autre session, ou la page rouverte après
coup — et le `fetch()` recevait alors une page d'erreur HTML là où il
attend un objet, ce qui ne dit rien du tout à l'administrateur. Le refus
est désormais rendu en JSON avec son 422, comme partout ailleurs sur cet
écran.

**Et le verrou ne faisait pas ce que j'avais écrit qu'il faisait.** Le
commentaire de `create()` disait que le verrou d'intervalle « fait
attendre le second appelant » : c'est faux, et vérifié faux contre le
vrai moteur — deux transactions prennent le **même** verrou d'intervalle
sans se gêner, parce qu'un gap lock est purement inhibiteur. Ce qui entre
en conflit, c'est l'INSERT : son *insert-intention lock* contre le gap
lock de l'autre. InnoDB tranche alors par un interblocage (1213) ou par
une expiration d'attente (1205).

Le verrou empêche donc bien deux défauts — mais le perdant **meurt** au
lieu de perdre gracieusement, et `ensureDefaultExists()` n'avalait que le
1062 de l'index UNIQUE. Sur une installation neuve la table est vide et
un navigateur qui demande plusieurs tailles d'une même photo appelle
cette méthode en parallèle : c'est précisément la requête que la méthode
existe pour faire aboutir qui partait en 500. Les trois codes sont
désormais reconnus comme « couru et perdu ».

**Le test de ce cas ne testait rien.** Il créait la ligne gagnante
*avant* d'appeler le service — donc `ensureDefaultExists()` trouvait un
défaut du premier coup, repartait aussitôt, et n'atteignait jamais le
`catch` en question. Il passait quoi qu'autorise ce `catch`. Le doublon
rend maintenant `null` à la première lecture seulement, ce qui est la
lecture faite avant que le gagnant ne valide ; vérifié en échec sur les
deux nouveaux codes.

**Et un refus de capacité ne nommait pas l'emplacement.** Le seul appel
réel en production, dans la fusion de deux albums délégués, disait « cet
album » — or ce qu'un administrateur peut aller re-pointer, c'est un
emplacement, pas un album. La phrase est maintenant construite à partir de
`StorageCapability::frenchDescription()`, comme celle que produit
`StorageCapabilities::require()` partout ailleurs, donc les deux formulations
ne peuvent plus diverger.

### La fenêtre de migration, et la seule vraie divulgation du chantier

Le garde-fou qui refuse de rendre public un emplacement portant des albums
délégués ne lisait que `location_id`. Or pendant une migration,
`location_id` nomme encore la **source** : la destination vit dans
`migration_target_id` jusqu'à ce que `completeMigration()` bascule. La
question « quelqu'un se dirige-t-il vers cet emplacement ? » recevait donc
« non » au sujet d'un album dont les fichiers y sont écrits à l'instant
même.

La suite est la divulgation que tout ce mécanisme existe pour empêcher :
l'administrateur ajoute l'URL publique, la migration se termine, l'album
atterrit là, et le garde-fou de service refuse alors de servir des octets
**déjà récupérables directement chez le fournisseur**. Contrairement aux
autres trouvailles de cette série, celle-ci n'est pas une question de
disponibilité.

`distinctLocationIds()`, juste au-dessus dans le même fichier, unit les
deux colonnes et son docblock explique exactement pourquoi — ce
raisonnement n'avait pas été reporté. Il l'est maintenant, dans la requête
comme dans le commentaire.

### Divergences constatées avec les maquettes, et ce qui fait foi

Les deux maquettes déposées dans `docs/chantiers/maquettes/` sont la
spécification écrite par le mainteneur. Elles sont déposées telles
quelles — les corriger pour satisfaire une relecture reviendrait à
réécrire en silence la spécification qu'on a reçue. Trois écarts ont
néanmoins été relevés en les lisant, et ils sont consignés ici plutôt que
dans les fichiers :

1. **`storage/modules/gallery` dans la maquette, `storage/gallery` dans
   le code.** Le dossier par défaut ne peut pas bouger : deux endroits du
   cœur le résolvent par son nom — `BackupService::excludedArchivePrefixes()`,
   qui tient les photos hors d'une archive qui a demandé à ne pas les
   porter, et `DiskBudget::measureNow()`, qui les compte comme la part de
   la galerie. Le renommer aurait fait une sauvegarde qui embarque
   silencieusement ce qu'on lui avait dit d'exclure. D16 porte sur les
   **lignes** d'emplacement redéclarées, pas sur le déplacement de
   photographies sur le disque. `Tests\Core\Storage\DefaultStorageFolderTest`
   tient les trois lectures ensemble.
2. **« lire par plage d'octets » apparaît tel quel dans l'onglet Vidéos
   de la maquette.** C'est le vocabulaire du code, et D3 interdit de
   montrer une capacité comme elle est écrite. L'écran énonce la
   conséquence — les vidéos ne pourront pas être lues en avançant dans la
   barre de lecture —, pas le mécanisme.
3. **`USED_BY` place les albums autrement que l'autre maquette.** Les
   deux fichiers ne sont pas d'accord entre eux sur ce point ; l'affectation
   d'un album à un emplacement suit D4 (elle appartient au consommateur) et
   c'est ce que le code fait.

Là où une maquette et une décision verrouillée se contredisent, c'est la
décision qui l'emporte, et l'écart est noté ici.

---

# IT-02 — La page Stockage, la mesure par volume, et la galerie réorganisée

## Le défaut latent était pire que « la mesure est imprécise »

`DiskBudget` est construit avec **un seul** `$storagePath`. Toutes ses
approbations décrivaient donc le disque système, et la roadmap appelait
ça un défaut latent — le mot est juste, mais il sous-estime : ce n'est
pas une mesure approximative, c'est une mesure **du mauvais disque**.

Dès qu'un emplacement local pointe ailleurs, l'écriture qu'`ensureRoom()`
autorise atterrit sur un volume que personne n'a regardé. Une sauvegarde
sur un NAS approuvée parce que le disque système est vide ; ou refusée
parce qu'il est plein alors que le montage a des téraoctets. La première
tronque une archive, et **rien ne révèle une archive tronquée avant le
jour où on la restaure** — c'est exactement la panne que `DiskBudget`
existe pour empêcher, retournée contre elle-même.

## Le regroupement se fait par périphérique, et c'est ce qui compte

`stat()` rend un numéro de périphérique ; deux chemins de même `dev` sont
le même système de fichiers. Le préfixe du chemin, lui, ment précisément
là où ça coûte : un lien symbolique, un montage lié, ou un
`/mnt/nas/photos` qui est en réalité un dossier du disque système se
lisent comme ailleurs par le nom et sont le même disque en dessous.

Sans ce regroupement, trois dossiers d'un même disque annoncent chacun la
même place libre. Le lecteur additionne. Ce n'est pas une erreur
d'arrondi, c'est une erreur qui change une décision : « je peux déplacer
les galeries ici » contre « je ne peux pas ».

**Deux lectures qui ressemblent à des détails et n'en sont pas.**

Un chemin que le système refuse de situer rend `null`, et **deux `null`
ne sont jamais fusionnés**. « Impossible de prouver que c'est le même
disque » est la lecture honnête ; traiter deux inconnus comme un seul
volume additionnerait leurs occupations sur une seule place libre, donc
sous-estimerait la place restante — le seul sens dans lequel se tromper
tronque une écriture.

Un dossier imbriqué dans un autre déjà compté n'ajoute rien à
l'occupation du volume. L'emplacement local par défaut est
`storage/gallery`, à l'intérieur de `storage/`, les deux sont déclarés,
et les additionner compterait la galerie deux fois sur son propre volume.
C'est le même double comptage, un étage plus bas.

## Le quota déclaré ne suit pas l'écriture

Il vient d'un contrat d'hébergement, qui ne dit rien d'un NAS monté
dessus : l'appliquer là rapporterait 900 Go de stockage réseau comme 4 Go.
Il reste donc attaché au seul volume principal, et chaque volume dit
**laquelle des deux mesures** il utilise — parce que « 62 % » d'un quota
déclaré et « 62 % » du volume d'un hébergeur mutualisé ne sont pas le
même fait, et que le second ne concerne pas celui qui le lit.

## `DeviceResolver` est une interface, et ce n'est pas de la cérémonie

La règle à tester est « deux dossiers sur un disque sont un volume ». La
tester demande deux disques ; un exécuteur d'intégration continue ne
laisse pas un test en monter un. L'alternative était d'écrire le test
contre le second système de fichiers que la machine a par hasard —
`/dev/shm`, en général — et de le regarder ne plus rien prouver sur la
machine qui n'en a qu'un. Donner la réponse à l'inventaire rend la règle
déterministe partout, et laisse au noyau une part beaucoup plus petite,
vérifiée séparément contre de vrais chemins.

## Ce que le budget de temps n'achète pas, écrit plutôt que sous-entendu

« Le dossier existe » a cessé d'être une vérification le jour où un
emplacement peut nommer un montage réseau, parce qu'un montage a un
troisième état entre marcher et échouer : il peut devenir **lent**.
Secondes par `stat()`, minutes pour un listage. Sans budget, cet état-là
rend une page de configuration qui ne finit jamais — et le seul écran qui
permettrait de réparer le montage est le seul qu'on ne peut pas ouvrir.

Mais un NFS `hard` dont le serveur a disparu bloque l'appel système
lui-même, dans le noyau, de façon ininterruptible. Aucun délai PHP ne
peut y mettre fin. Le remède est sur le montage (`soft`, `timeo=`), et
c'est écrit dans le code plutôt que laissé à découvrir : promettre un
délai maximal qu'on ne peut pas tenir est pire que de ne rien promettre.

**La vérification finale a été retirée après coup.** Elle avait été
écrite, avec le commentaire expliquant qu'un aller-retour lent mais
complet est un emplacement qui marche — et le code faisait exactement
l'inverse, puisqu'à ce point toutes les opérations avaient réussi. Le
commentaire disait la bonne chose et le code disait l'autre ; c'est le
code qui a changé. Le budget refuse d'attendre l'opération suivante, il
ne retire pas un succès déjà obtenu.

## Le veto d'un consommateur, ou D4 vu depuis l'autre bout

D4 met l'affectation chez le consommateur. La conséquence qu'IT-01
n'avait pas tirée : le consommateur est aussi la **seule** chose qui sait
ce qu'une destination donnée ferait à ce qu'il porte. L'écran Stockage
n'a aucune idée de ce qu'est un album délégué, et il n'en aura jamais.

La brisure est réelle et a été trouvée en IT-01 : un album délégué ne
peut pas vivre derrière une URL publique permanente, la galerie refuse un
tel emplacement à la création et refuse encore au moment de servir les
octets — mais rien ne couvrait le troisième moment, celui où c'est
l'**emplacement** qui est modifié sous l'album. Rien n'est exposé, le
garde-fou de service tient ; simplement chaque média de chaque album
délégué devient un 404 permanent que rien n'explique nulle part.

`objectionTo()` est sur l'interface principale et non dans une interface
optionnelle, pour la raison qui met `servesPubliclyWithoutExpiry()` sur
la sienne : un consommateur qui pourrait être brisé et oublie de le dire
est une casse silencieuse, et poser la question à chaque futur
consommateur coûte une ligne à chacun. Un consommateur qu'on ne peut pas
interroger est un refus, exactement comme pour une suppression.

## Les chemins absolus, et le seul refus qui est une question de sécurité

Le formulaire refusait un chemin absolu ; le modèle, lui, les résolvait
depuis IT-01. Autrement dit les seules destinations déclarables étaient
des dossiers de `storage/` — ce qui est précisément le cas dont la mesure
par volume n'a pas besoin. IT-02 ouvre le formulaire.

Ce qui remplace le refus général est plus étroit et plus utile : un
dossier **dans la racine web** est refusé, parce que tout ce qui y serait
déposé deviendrait téléchargeable sans le moindre contrôle d'accès —
`ARCHITECTURE.md` §8.3 et `SECURITY.md` disent tous les deux la même
chose. La comparaison résout les deux côtés, sinon un lien symbolique
contourne un test fait sur le texte du chemin.

Un NUL est refusé sur les deux branches. Il tronque un chemin dans toutes
les bibliothèques C sous PHP, donc `/mnt/nas\0/../../public` est une
chaîne pour les vérifications de cette application et une autre, plus
courte, pour le système de fichiers. C'est toute la forme du bug, et
elle coûte un `preg_match`.

## Les quatre onglets, et pourquoi chacun écrit ses propres clés

Une case décochée **n'envoie rien**. Une page qui écrirait tous les
booléens qu'elle connaît à chaque enregistrement éteindrait donc
« autoriser les vidéos » chaque fois que quelqu'un modifie les limites
photo sur un autre onglet — silencieusement, et sans qu'on puisse
distinguer ça d'un changement voulu. Découper les clés par onglet rend
cette panne impossible plutôt que soigneusement évitée.

« Emplacement des nouveaux albums » : le libellé est la totalité du
réglage. Un administrateur qui le lit comme « où vit la galerie »
attendra que les albums d'hier suivent, et rien ne bougera. C'est dit
dans le libellé, puis redit sous le champ.

## Ce que cette itération a cassé à côté, et qu'il valait mieux casser

`DelegatedAlbumServiceTest` construisait `new LocalStorageBackend($path,
'gallery')`. Le second argument était **silencieusement ignoré** — PHP
accepte les arguments surnuméraires — et le backend avait donc toujours
été enraciné sur `$path`. Il a cessé d'être ignoré le jour où le
constructeur a reçu un budget de santé, et une chaîne est arrivée là où
un `float` était attendu. Le test a été corrigé dans le sens de ce qu'il
faisait réellement, pas dans le sens de ce qu'il avait l'air de dire.

`GalleryLocationSpaceTest` disparaît avec `diskSpaceFor()`, que la
roadmap avait explicitement gardé une itération de plus en disant que sa
forme était fausse. Son sujet — la place libre — est maintenant celui de
`Core\Storage\Volume`, mesuré au bon endroit.

## Ce que le paquet de support ne porte pas

Aucun identifiant, aucune clé, aucun jeton : `StorageLocation` n'a pas de
propriété pour en porter un, donc le collecteur ne peut pas en imprimer
par accident. Ce qu'il dit, c'est **si** un secret est configuré — ce qui
distingue « jamais renseigné » de « renseigné et refusé ».

Les chemins passent par `redact()`. Un chemin de stockage est un dossier
de serveur et `/mnt/nas/photos` ne dit rien de personne — mais
`/home/marie.dupont/...` si, et un site a parfaitement le droit d'en
avoir un. Et l'endpoint d'un bucket est absent : le **nom** du
fournisseur répond à toutes les questions de diagnostic auxquelles
l'endpoint répondrait, en un mot, sans être la moitié d'une cible.

## Un fichier de test déplacé qui ne s'exécutait plus

`Checks / database-mariadb` est parti rouge en annonçant **zéro échec et
zéro erreur** : « OK, but there were issues! », 17 525 tests, et un code
de sortie 1. La cause tenait en une ligne du rapport :

> Class ObjectStorageErrorExplainerTest cannot be found in
> tests/Core/Storage/Location/Diagnostics/ObjectStorageErrorExplainerTest.php

Le fichier avait été déplacé par `git mv` avec la classe qu'il exerce,
et ni son `namespace` ni le nom de sa classe n'avaient suivi. PHPUnit ne
peut alors pas le charger : ses six tests **ne s'exécutaient plus du
tout**. C'est le pire des deux résultats possibles — pas un test rouge,
mais une couverture disparue en silence, exactement la panne que
l'AGENTS.md décrit pour un répertoire de tests non déclaré dans
`phpunit.xml`.

**Et je ne l'avais pas vu parce que je lisais le mauvais code de
sortie.** Chaque exécution locale passait `vendor/bin/phpunit` dans un
`grep` ou un `tail` : le shell rend alors le statut de `grep`, jamais
celui de PHPUnit. Le résumé affichait « OK, but there were issues! » et
je l'ai lu comme un succès. `.claude/skills/steward/SKILL.md` écrit
précisément ce piège au sujet de `npm run e2e:full` — « pipe it into
`tail` and the shell reports `tail`'s status » — et il vaut pour toutes
les commandes, pas seulement celle-là.

Vérifié dans les deux sens : la commande exacte du job reproduite
localement rend 1 avant le correctif et 0 après, avec le total des tests
qui passe de 17 525 à 17 531 — les six qui étaient muets.

Un balayage de tous les fichiers de `tests/` cherchant un écart entre le
chemin, le `namespace` et le nom de classe n'a ramené que celui-là (les
autres correspondances sont le `namespace` délibéré de
`tests/fixtures/reference-dataset/` et des classes d'appoint déclarées
avant la classe de test dans leur fichier).

## La relecture a trouvé cinq choses, et elles étaient toutes vraies

Quatre corrigées, une requalifiée. Ce qui suit est ce qu'elles avaient en
commun : chacune portait sur du code que j'avais écrit en croyant l'avoir
vérifié.

**Le dédoublonnage dépendait de l'ordre de déclaration.** `occupiedBytes()`
construisait un tableau de chemins, le triait du plus court au plus long,
puis ne le lisait jamais : la boucle parcourait `$directories` non trié. Le
saut ne joue que pour un dossier imbriqué dans un dossier **déjà compté**, et
un parent n'est jamais `isUnder()` son propre enfant — donc la règle tenait
uniquement parce que `declaredDirectories()` place `storage/` en premier.
Deux emplacements sur un même disque, `/mnt/nas/photos` déclaré avant
`/mnt/nas`, comptaient leurs octets deux fois. L'occupation gonflée alimente
`availableBytes()`, donc un refus : le défaut de ce sous-système, retourné —
refuser de la place qui existe. Le test le reproduit (8192 au lieu de 4096).

**Le garde-fou de la racine web était contournable.** `realpath()` rend
`false` pour un dossier qui n'existe pas encore — c'est-à-dire le cas
ORDINAIRE de ce formulaire, puisque déclarer un emplacement est la façon dont
le dossier vient à exister — et le code retombait alors sur une comparaison
de la chaîne brute avec la racine web canonique. Deux alphabets différents :
`/var/www/./public/photos` ne commence pas par `/var/www/public/` comme
texte et désigne le même dossier pour le noyau. Une double barre oblique
faisait la même chose, et un dossier absent sous un ancêtre lié
symboliquement aussi — précisément le cas que le commentaire disait
fermer. `canonicalise()` remonte jusqu'au premier ancêtre qui existe, le
résout, et rattache le reste. Le résultat sert à COMPARER seulement : le
stocker transformerait le `/var/www/current/photos` d'un déploiement en
`/var/www/releases/41/photos` et le casserait à la livraison suivante.

**L'exclusion de `FileAccessAuditTest` portait sur la ligne entière.** Une
ligne mêlant un lien externe et un vrai `href="storage/…"` était donc
exemptée en bloc — plus large que ce que son propre commentaire affirmait.
L'exclusion vise maintenant les URL elles-mêmes.

**La frontière RBAC n'était pas testée.** `AGENTS.md` demande, pour chaque
nouvelle route, l'accès autorisé au plancher et refusé un cran en dessous ;
ce fichier ne s'authentifiait qu'en superadmin, et ses seuls 403 étaient des
refus CSRF. Deux jeux de tests couvrent les onze routes — et un troisième
lit le rôle **déclaré dans `public/index.php`**, parce que les deux premiers
déclarent eux-mêmes le `role_min` qu'ils vérifient et continueraient de
passer si la composition publiait ces routes à un admin.

**Et `ensureRoomOn()` n'a aucun appelant de production.** C'est exact, et la
description de la PR comme `ARCHITECTURE.md` l'énonçaient au présent. La
raison pour laquelle rien n'a cassé est la même que celle pour laquelle
câbler la méthode ne changerait rien aujourd'hui : les quatre appelants
existants de `DiskBudget` — `UploadHandler`, `ChunkedUploadStore`,
`ImportController`, `BackupService` — écrivent tous sous `storage/`, donc
tous sur le volume principal, où `ensureRoomOn()` retombe exactement sur la
vérification qu'ils font déjà. Les écritures qui atteignent réellement un
emplacement déclaré passent par `StorageBackendInterface::put()` et n'ont
jamais consulté le budget disque — un trou antérieur à ce chantier. Le
mécanisme est livré et testé ; le premier appelant pour qui la distinction
existe arrive avec IT-05. Les deux affirmations au présent sont corrigées
plutôt que laissées à découvrir.

### La seconde relecture automatique, et six affirmations à corriger

**Le paquet de support énonçait trois promesses, il en tenait une.**
`StorageLocationsCollector` affirmait dans son propre en-tête qu'aucun
chemin pouvant nommer une personne et qu'aucun hôte de point de
terminaison n'y figurent. Or `SupportCollectorContext::redact()` remplace
les secrets connus du run et normalise les espaces — le nom d'une personne
dans un dossier n'est ni l'un ni l'autre —, et la ligne « Cible » imprimait
`describe()`, qui pour un bucket est précisément l'hôte du point de
terminaison. Ce fichier quitte l'installation et part chez un tiers. Les
chemins passent désormais par `maskPath()` — relatifs à la racine du projet
quand ils y sont, sinon le dossier lui-même derrière une empreinte de
l'arbre au-dessus, pour que deux emplacements d'un même montage restent
lisibles comme tels — et la cible d'un bucket est le nom du fournisseur.
La limite qui reste (le dernier segment survit) est énoncée plutôt que
maquillée. Rien ne testait ce collecteur ; c'est fait.

**« Sert à : rien » était une réponse fausse, pas une réponse manquante.**
Le registre est vide dans la tâche planifiée qui produit le paquet : la
question n'a jamais été posée. Écrire « rien » en face d'un emplacement sur
lequel une galerie se tient est exactement la conclusion sur laquelle on
supprime. `isEmpty()` existait déjà pour distinguer les deux.

**Un volume dont la place libre est inconnue se disait plein à 100 %.**
`VolumeInventory` garde `disk_free_space()` et `disk_total_space()`
séparément ; soustraire une place libre absente d'une taille connue donne
« tout est occupé », et c'est la lecture qui fait supprimer des
photographies. La règle « inconnu vaut null, jamais 0 » que ce fichier
énonçait pour le pourcentage s'applique un chiffre plus tôt.

**« Stockage » se rendait deux entrées trop haut.** Le menu trie sur le
numéro ; 47 était déjà celui d'« E-mails », et l'ordre d'enregistrement ne
départage que les ex æquo. Le test structurel lisait cet ordre
d'enregistrement, pas l'ordre rendu — il restait vert. Deux tests
s'ajoutent : l'ordre rendu, et l'unicité des numéros qui le rend lisible.

**La suppression d'un emplacement n'était qu'un bouton JavaScript.** La
seule action destructrice de la page, et la seule sans seconde porte :
sur une page dont le script n'a pas chargé, il n'y avait plus aucun moyen
de supprimer un emplacement. C'est un formulaire POST avec `csrf_field()`
et le `data-confirm` partagé, comme Maintenance et Courrier sortant.

**Et l'aide après sinistre faisait perdre les fichiers qu'elle venait
sauver.** L'étape 2 restaure la base, qui contient déjà les emplacements ;
l'étape 3 demandait de « déclarer » la destination, donc d'en créer une
avec un nouveau numéro, pendant que les albums restaurés continuaient de
désigner l'ancien. Elle demande maintenant de rouvrir l'emplacement
restauré et de ne corriger que ce qui a changé.

Le choix de l'emplacement des nouveaux albums quitte par ailleurs
`GalleryConfigController` pour `GalleryLocationService` (frontière
Controller → Service), et §8.108 rejoint le chapitre 8, où un lecteur le
cherche.

---

# IT-03 — Les archives cessent de reprendre les emplacements

## La règle était une orthographe, pas une dérivation

`BackupService::excludedArchivePrefixes()` nommait un chemin à la main —
`storage/gallery` — et `createFileBackup()` prenait un booléen de son
appelant. Les deux se trompaient de la même façon.

Un emplacement que quelqu'un avait déplacé était repris **quoi qu'on
demande**, parce que l'exclusion connaissait un nom et pas une déclaration.
Et le booléen posait à l'appelant une question à laquelle il ne pouvait pas
bien répondre : une archive reprend le contenu d'un emplacement ou non, et
la réponse ne peut pas dépendre du bouton sur lequel on a cliqué quand cet
emplacement a un cycle de vie — une rétention, une destination, un délai de
grâce — dont l'archive ne sait rien.

`DeclaredStorageDirectories` est cette dérivation, et la seule. Elle lit les
DTO de `storage_locations` et résout chaque emplacement local par
`StorageBackendFactory::localDirectoryFor()` — la même résolution que
`VolumeInventory`, donc un emplacement est un répertoire pour les deux. Elle
ne déchiffre rien : le chemin d'un emplacement local est dans la colonne de
configuration, jamais dans le secret.

**Un chemin que l'application refuse d'ouvrir n'entre pas dans la liste**,
et c'est le bon sens de l'erreur. L'application n'y a jamais écrit, donc
l'exclure ne protège rien ; alors qu'inventer un préfixe à partir d'un
chemin qu'on n'a pas su résoudre ferait sauter de l'archive un répertoire
que personne n'a désigné. Un stockage objet ne rend aucun répertoire : il
n'y a rien sur un volume à tenir hors d'un zip.

**Une table absente répond « aucun ».** Ce n'est pas de la programmation
défensive : la sauvegarde que prennent ces appelants est prise *avant*
l'opération qu'elle protège, donc le schéma est normalement intact. Le seul
cas réel est celui-ci : une installation antérieure au chantier prend sa
copie d'avant-mise-à-jour pendant que `storage_locations` n'existe pas
encore, parce que la migration tourne après le remplacement des fichiers.
Un site sans cette table n'a déclaré aucun emplacement ; l'archive produite
est exactement celle que cette version-là écrivait déjà.

## `full_with_gallery` ne pouvait plus tenir sa promesse

Sur une installation ordinaire, `storage/gallery` **est** un emplacement
déclaré — `ensureDefaultExists()` le crée au premier démarrage. Une archive
« avec galerie » ne différerait donc d'une archive sans que sur un site
n'ayant rien déclaré : l'inverse de ce que son nom annonce, exactement
quand quelqu'un en aurait besoin.

La portée disparaît de la page Maintenance et de `createFullBackup()`. Le
plafond transversal « une seule archive contenant la galerie » et
`Backup::GALLERY_TYPES` disparaissent avec ce qu'ils pesaient : plus aucune
archive n'est de la taille d'une galerie, donc chaque famille atteint enfin
le nombre que son réglage annonce — une série de réinitialisations
s'arrêtait à une, elle en garde trois comme les autres. Le réglage « Envoyer
aussi la galerie hors site » disparaît aussi : la question a été retirée,
pas répondue.

### La valeur reste dans l'ENUM, et c'est la bonne décision

Sondé sur le moteur réel plutôt que supposé. Sous `STRICT_TRANS_TABLES` —
le `sql_mode` effectif —, un `ALTER TABLE … MODIFY COLUMN t ENUM("a")` avec
une ligne portant la valeur retirée est **refusé** :

```
SQLSTATE[01000]: Warning: 1265 Data truncated for column 't' at row 1
```

`MigrationRunner` est un pur différentiel de schéma, sans fichiers de
migration et sans couture pour une migration de données. Il réessaierait,
abandonnerait après trois passes identiques, et se déclarerait cassé sur la
page Maintenance d'un site dont le seul tort est d'avoir pris une
sauvegarde.

Et réétiqueter ces lignes serait **pire** que les garder : une archive
`full_with_gallery` contient réellement les photographies, et l'appeler
`full_no_gallery` mentirait à qui la restaure. `BackupFamily` continue donc
de la classer Manuelle — un type qu'il ne sait pas classer est gardé et
jamais supprimé, ce qui est le bon réflexe et le mauvais résultat pour une
valeur que le schéma livre encore.

## La remise à zéro était devenue silencieusement plus destructrice

`FullResetHandler` vide `storage/` en entier sauf `keys/master.key`.
C'était réversible tant que la copie de sécurité prise à l'étape 1
contenait la galerie. Elle ne la contient plus — et la même opération
détruisait donc, sans retour, ce qu'aucune archive ne couvrait, sans que
personne ne l'ait demandé.

Les répertoires déclarés survivent. Des deux lectures irréversibles, c'est
la récupérable : des fichiers laissés sur un disque se suppriment encore à
la main. Et c'est la seule cohérente avec D10 — un emplacement a son propre
cycle de vie, dont la remise à zéro du site n'est pas un événement. L'écran
le dit à l'endroit où on confirme, et le journal nomme combien de dossiers
ont été gardés.

**Ils sont lus avant que quoi que ce soit ne soit détruit**, et c'est toute
la subtilité : l'étape 2 vide `storage_locations`, donc la même question
posée après répondrait « aucun » et l'étape 4 les supprimerait tous.

## Le cliquet a changé de place plutôt que de disparaître

`GalleryTypeCoverageTest` lisait les sites d'appel de
`createFileBackup(true)`, parce que le nom d'un type ne dit rien de son
contenu. Le drapeau n'existe plus ; la défaillance silencieuse, elle, a
simplement déménagé de l'**appel** vers la **construction**. Un
`new BackupService(...)` qui oublie son cinquième argument produit une
archive qui reprend de nouveau tout : rien n'échoue, rien n'est journalisé,
et le seul symptôme est une facture chez qui héberge le résultat.

`Tests\Architecture\BackupServiceWiringTest` lit donc les neuf sites de
construction et refuse celui que personne n'a classé. Une seule exception
est prévue et elle est nommée — `SetupController`, qui restaure une archive
dans un site en cours d'installation — et elle s'écrit
`DeclaredStorageDirectories::none()` plutôt que de s'omettre, pour que
« rien n'est déclaré » et « personne n'a câblé ça » ne se ressemblent pas.
Vérifié en cassant un site d'appel.

## Ce que les tests disent, et ce qu'ils ne diraient pas

Une archive perd un emplacement déclaré **et garde tout autre répertoire de
`storage/`** : sans la seconde moitié, la règle se lirait « une archive ne
reprend plus `storage/` ». Le même dossier non déclaré y reste, donc ce
qu'on observe est la déclaration et pas le nom `gallery` — précisément ce
que l'ancien booléen ne savait pas distinguer. Un emplacement déclaré
**hors** de `storage/` en sort aussi : le cas que l'ancienne règle ne savait
pas exprimer du tout.

Et l'envoi hors site est rejoué deux fois à travers la vraie base, sur le
même site et la même photographie, à une déclaration près.
