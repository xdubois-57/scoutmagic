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

### Reporté à l'itération suivante, explicitement

`GalleryLocationService::diskSpaceFor()` est gardé tel quel pour une
itération, avec un commentaire qui dit que sa forme est fausse : la place
libre est une propriété d'un **volume**, pas d'un dossier, et trois
emplacements sur un même disque y afficheraient chacun la même place. La
mesure par volume et l'écran qui l'énonce sont IT-02 ; cette méthode part
avec eux.
