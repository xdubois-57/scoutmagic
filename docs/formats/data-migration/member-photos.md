# Format `scoutmagic-member-photos` — photos des membres

Ce document s'adresse à qui **produit**, sur un ancien site, l'archive que
l'import « Photos des membres » de ScoutMagic lit. Il se suffit à lui-même
avec [`conventions.md`](conventions.md), qui fixe ce qui est commun à toutes
les archives de migration : l'archive ZIP, le manifeste, les versions, les
empreintes et les identifiants. Il ne suppose aucune lecture du code de
ScoutMagic.

| | |
|---|---|
| Identifiant du format (`format`) | `scoutmagic-member-photos` |
| Version décrite (`format_version`) | `1.0.0` — ScoutMagic lit toute version `1.x.x` |
| Import dans ScoutMagic | Espace chefs d'U › Migration de données › **Photos des membres** |
| Module requis | Aucun : les photos des membres sont une fonction du cœur, l'import est proposé dès que « Migration de données » est activé |
| Type d'objet (`source_kind`) | `photo` |

## 1. Ce que l'import fait, et ce qu'il ne fait pas

ScoutMagic garde **une photo de référence par membre et par année scoute**.
Elle s'affiche au trombinoscope, sur la fiche du membre et partout où son
visage apparaît ; pour une année sans photo, le site montre la plus récente
des années précédentes.

L'archive apporte, pour des membres que ScoutMagic connaît déjà, leurs
photos des années passées (ou de l'année en cours) :

- chaque photo est rattachée à un membre par son **identifiant Desk**
  (`desk_id`) et à une année par son **libellé** (`2022-2023`) ;
- elle passe par le traitement normal des photos du site : contrôle du
  type, redressement selon l'orientation EXIF, **suppression de toutes les
  métadonnées** (EXIF, position GPS), réencodage, vignette ;
- elle n'est visible que des visiteurs connectés, comme toute photo de
  membre.

L'import **ne remplace jamais** une photo : un membre qui a déjà une photo
pour une année la garde, et la photo de l'archive est ignorée (§ 7). Il ne
crée **aucun membre** : une photo dont le membre est introuvable est
ignorée. Il ne touche ni aux photos des comptes de connexion, ni aux photos
de section, ni aux albums. Il n'envoie aucun courriel et aucune
notification, et il n'ajoute aucun écran : les photos importées
s'affichent comme des photos ajoutées à la main.

## 2. Structure de l'archive

```text
photos-membres.zip
├── manifest.json
├── photos.jsonl
└── images/
    ├── 3f1c…9a2e.jpg
    ├── 7b04…c611.png
    └── …
```

- `manifest.json` : le manifeste commun ([`conventions.md`](conventions.md)
  § 2), avec `"format": "scoutmagic-member-photos"`. Son inventaire `files`
  cite **chaque** fichier de l'archive, `photos.jsonl` et chaque image
  compris, avec son empreinte SHA-256.
- `photos.jsonl` : **obligatoire**, à la racine. Une ligne par photo (§ 3).
  Son absence fait refuser l'archive.
- `images/` : les images, chacune nommée **`<sha256>.<extension>`**, où
  `<sha256>` est l'empreinte SHA-256 de ses octets en 64 caractères
  hexadécimaux minuscules, et `<extension>` l'une de `jpg`, `jpeg`, `png`,
  `webp`, en minuscules (§ 4).

Aucun autre fichier n'est admis. Les extensions acceptées dans l'archive
sont `json`, `jsonl`, `jpg`, `jpeg`, `png` et `webp` : un fichier d'une autre
extension (un `.gif`, un `.heic`, un `.txt`…) fait **refuser l'archive
entière**.

Une même image peut servir à plusieurs lignes (la même photo pour deux
années, par exemple) : elle n'est alors présente qu'une fois dans `images/`.

## 3. `photos.jsonl`

Encodage UTF-8, **un objet JSON par ligne**, lignes séparées par `\n`
([`conventions.md`](conventions.md) § 7). Les lignes vides sont ignorées.
L'ordre des lignes compte (§ 7) : ScoutMagic les lit de haut en bas.

| Champ | Type | Obligatoire | Règle |
|---|---|---|---|
| `desk_id` | chaîne | oui | L'identifiant Desk du membre (le numéro de Tiers), **toujours en chaîne** : `"004518"`, jamais `4518`. 1 à 100 octets ; en pratique, un identifiant Desk tient en 50 caractères. Jamais un identifiant interne de l'ancien site. |
| `scout_year` | chaîne | oui | Le libellé de l'année scoute : `AAAA-AAAA`, deux années consécutives (`2022-2023`). |
| `path` | chaîne | oui | Le chemin de l'image dans l'archive : exactement `images/<sha256>.<extension>`, avec la même empreinte que `sha256`. |
| `sha256` | chaîne | oui | L'empreinte SHA-256 des octets de l'image, 64 caractères hexadécimaux **minuscules**. Elle doit être celle que le manifeste donne pour `path`. |
| `original_name` | chaîne | non | Le nom de fichier d'origine (`portrait-2022.jpg`). ScoutMagic le garde comme nom du fichier enregistré, réduit à son nom de base et à 255 octets. Absent ou vide : le nom du fichier dans l'archive. |
| `taken_at` | chaîne | non | La date de prise de vue, `AAAA-MM-JJ`. Admise mais **non reprise** : ScoutMagic n'a pas de champ pour elle. Une valeur invalide ne fait pas ignorer la photo. |

Tout autre champ est ignoré.

Exemple de ligne (sur une seule ligne dans le fichier) :

```json
{"desk_id": "004518", "scout_year": "2022-2023", "path": "images/c7d08c1398eda7eeb67d236365355826a31b0ac667c4bdf08d417719689b32a8.jpg", "sha256": "c7d08c1398eda7eeb67d236365355826a31b0ac667c4bdf08d417719689b32a8", "original_name": "portrait-2022.jpg", "taken_at": "2022-10-01"}
```

Il n'y a pas de date ni d'heure à fuseau dans ce fichier : seule
`exported_at`, dans le manifeste, en porte un.

## 4. Les images

| Contrôle | Règle | Sinon |
|---|---|---|
| Type réel | JPEG, PNG ou WebP, **lu dans les octets** et non dans le nom | Photo ignorée : « Type d'image non accepté (JPEG, PNG ou WebP attendu) » |
| Extension | Celle du type réel : `jpg` ou `jpeg` pour un JPEG, `png`, `webp` | Photo ignorée : « Type réel de l'image différent de son extension » |
| Poids | **10 Mo** (10 485 760 octets) au plus | Photo ignorée : « Image trop lourde (plus de 10 Mo) » |
| Dimensions | **16 mégapixels** au plus (largeur × hauteur ≤ 16 000 000), et **10 000 pixels** au plus sur le plus grand côté | Photo ignorée : « Image trop grande (plus de 16 mégapixels ou de 10 000 pixels de côté) » |
| Lisibilité | L'image se décode entièrement | Photo ignorée : « Image illisible » |

Le GIF, le HEIC, le TIFF et le BMP ne sont pas acceptés : convertissez-les
en JPEG avant l'export. Une image de 2 400 pixels de côté suffit largement à
une photo de trombinoscope ; une photo plus grande est importée telle quelle
puis réduite pour la vignette.

Les métadonnées (EXIF, GPS) peuvent rester dans les fichiers : ScoutMagic
les supprime toutes au réencodage, après avoir appliqué l'orientation.

Un fichier de plus de **16 Mio** dans l'archive (une image ou
`photos.jsonl`) fait refuser l'archive entière (§ 8).

## 5. Identifiants et dédoublonnage

Une photo n'a pas d'identifiant propre dans ce format. ScoutMagic retient
chaque photo importée sous le type d'objet **`photo`** et l'identifiant
**`<desk_id>/<scout_year>/<sha256>`**, qu'il compose lui-même à partir de la
ligne (`004518/2022-2023/c7d0…32a8`). Avec `source.installation_id` et le
format, c'est la clé de dédoublonnage de [`conventions.md`](conventions.md)
§ 5 : réimporter la même archive, ou une archive plus récente du même site
(même `installation_id`), ne réimporte aucune photo déjà importée.

Une collection trop grande pour une archive (§ 8) se découpe donc en
plusieurs archives **du même `installation_id`**, importées l'une après
l'autre.

## 6. Membres et années

**Membre.** `desk_id` est cherché parmi les membres de ScoutMagic, y
compris parmi les **anciens identifiants Desk des fiches fusionnées** : une
photo envoyée sous l'ancien identifiant d'une personne réinscrite sous un
nouveau numéro rejoint bien sa fiche actuelle. Un identifiant inconnu fait
ignorer la photo : « Membre introuvable dans ScoutMagic ». Aucun membre
n'est jamais créé. Pour que vos photos trouvent leurs membres, importez
d'abord dans ScoutMagic l'export Desk de l'unité.

**Année.** `scout_year` doit désigner une année que ScoutMagic connaît.
Une année **passée** absente du site est proposée dans l'aperçu : la
personne qui importe coche les années à créer. Les photos d'une année non
cochée sont ignorées (« Année scoute non créée »). L'année en cours et les
années à venir ne sont jamais créées par l'import : une photo qui vise
l'une d'elles alors qu'elle n'existe pas sur le site est ignorée.

## 7. Jamais d'écrasement

- **Photo déjà présente.** Si le membre a déjà, dans ScoutMagic, une photo
  pour cette année, elle est **conservée** et la photo de l'archive est
  comptée « déjà présente », même si elle est différente. L'aperçu dit
  combien d'animés sont dans ce cas.
- **Plusieurs lignes pour le même membre et la même année.** Seule la
  **première ligne valide** (de haut en bas) est importée, et seulement si
  l'emplacement est libre. Les suivantes sont ignorées : « Une photo
  précédente du fichier vise déjà ce membre et cette année ». Une première
  ligne dont l'image est invalide ne compte pas : c'est alors la suivante
  qui est prise. Placez donc la photo préférée en premier.
- La comparaison se fait sur le `desk_id` écrit dans la ligne. Si deux
  identifiants différents désignent la même personne (un ancien et un
  nouveau numéro), la première photo importée occupe l'emplacement et la
  seconde est comptée « déjà présente ».

## 8. Limites

| Limite | Valeur | Dépassement |
|---|---|---|
| Archive déposée | 2 Gio | Dépôt refusé |
| Nombre de fichiers dans l'archive | 4 003 : au plus **4 000 images**, plus `manifest.json`, `photos.jsonl` et une entrée de dossier `images/` | Archive refusée |
| Taille d'un fichier, décompressé | 16 Mio | Archive refusée |
| Taille totale, décompressée | 2 Gio | Archive refusée |
| Manifeste | 1 Mio (environ 180 octets par image inventoriée : écrivez-le compact) | Archive refusée |
| Ligne de `photos.jsonl` | 1 Mio | Archive refusée |
| Poids d'une image | 10 Mo | Photo ignorée (§ 4) |
| Dimensions d'une image | 16 mégapixels, 10 000 pixels de côté | Photo ignorée (§ 4) |

Au-delà de 4 000 images, découpez en plusieurs archives du même
`installation_id` (§ 5).

## 9. Cas invalides et motif au bilan

Le bilan ne montre **jamais de nom** : chaque élément ignoré y apparaît
sous la référence `desk <desk_id> · <année>` (`desk 004518 · 2022-2023`),
ou `ligne <n>` quand la ligne n'est pas lisible jusque-là.

**Refus de toute l'archive**, avant la moindre écriture
([`conventions.md`](conventions.md) § 8) : manifeste absent ou
contradictoire, `format` différent de `scoutmagic-member-photos`, version
majeure autre que 1, empreinte ou taille fausse dans le manifeste, fichier
d'une extension non admise, `photos.jsonl` absent, plafond du § 8 dépassé,
chemin dangereux ou lien symbolique.

**Photo ignorée**, les autres sont importées :

| Situation | Motif au bilan |
|---|---|
| La ligne n'est pas un objet JSON | Ligne illisible |
| `desk_id` absent, vide, numérique ou de plus de 190 octets | desk_id absent ou qui n'est pas une chaîne de caractères |
| `desk_id` de 101 à 190 octets : aucun membre de ScoutMagic n'en porte un si long | Membre introuvable dans ScoutMagic |
| `scout_year` absent ou qui n'est pas `AAAA-AAAA` consécutifs | Année scoute absente ou illisible |
| `sha256` absent ou qui n'est pas 64 caractères hexadécimaux minuscules | Empreinte SHA-256 absente ou invalide |
| `path` absent, hors de `images/`, d'une extension non admise, ou dont le nom n'est pas `sha256` | Chemin d'image invalide |
| `path` absent de l'archive | Image absente de l'archive |
| Le manifeste donne pour `path` une autre empreinte que `sha256` (l'image ne porte pas le nom de son empreinte) | Empreinte différente de celle du manifeste |
| Aucun membre, ni ancien identifiant, ne porte ce `desk_id` | Membre introuvable dans ScoutMagic |
| Image d'un type non accepté, d'une extension trompeuse, trop lourde, trop grande, illisible | Voir le § 4 |
| Une ligne précédente valide vise le même membre et la même année | Une photo précédente du fichier vise déjà ce membre et cette année |
| Année passée absente du site et non cochée | Année scoute non créée |
| Année en cours ou à venir absente du site | Année scoute en cours ou à venir, absente du site |

**Déjà présente** : la photo a déjà été importée depuis ce site (§ 5), ou
le membre a déjà une photo pour cette année (§ 7).

## 10. Exemple complet

Une archive de trois images pour deux membres. Le membre `E2E-MEMBER`
reçoit deux photos pour 2023-2024 : la première est importée, la seconde
ignorée.

`photos.jsonl` :

```text
{"desk_id":"E2E-ADMIN","scout_year":"2022-2023","path":"images/c7d08c1398eda7eeb67d236365355826a31b0ac667c4bdf08d417719689b32a8.jpg","sha256":"c7d08c1398eda7eeb67d236365355826a31b0ac667c4bdf08d417719689b32a8","original_name":"portrait-2022.jpg","taken_at":"2022-10-01"}
{"desk_id":"E2E-ADMIN","scout_year":"2023-2024","path":"images/6a570065710d421450653030d672f4b873b2cdce4faf0498679f746cb5a5eefe.png","sha256":"6a570065710d421450653030d672f4b873b2cdce4faf0498679f746cb5a5eefe"}
{"desk_id":"E2E-MEMBER","scout_year":"2023-2024","path":"images/a16504f6f0654fc8a1d643a284ecbfac01588456eda10d31e35ee4f9d8049694.webp","sha256":"a16504f6f0654fc8a1d643a284ecbfac01588456eda10d31e35ee4f9d8049694","original_name":"portrait-2023.webp"}
{"desk_id":"E2E-MEMBER","scout_year":"2023-2024","path":"images/ab677ed8d23f4ae859c47197a97ad58bcafaecd452c032351f30e03cc26a2bd8.jpg","sha256":"ab677ed8d23f4ae859c47197a97ad58bcafaecd452c032351f30e03cc26a2bd8"}
```

`manifest.json` (les empreintes et tailles sont celles des fichiers réels de
l'archive) :

```json
{
  "format": "scoutmagic-member-photos",
  "format_version": "1.0.0",
  "source": { "installation_id": "exemple-ancien-site", "name": "Ancien site d'exemple" },
  "export_id": "exemple-photos-2026-11-08",
  "exported_at": "2026-11-08T14:30:00+01:00",
  "files": [
    { "path": "photos.jsonl", "sha256": "3cb6e4065819a92e9b997d3ab1177a41f7516f8677c99e191dc7430db7e86366", "size": 940 },
    { "path": "images/c7d08c1398eda7eeb67d236365355826a31b0ac667c4bdf08d417719689b32a8.jpg", "sha256": "c7d08c1398eda7eeb67d236365355826a31b0ac667c4bdf08d417719689b32a8", "size": 2838 },
    { "path": "images/6a570065710d421450653030d672f4b873b2cdce4faf0498679f746cb5a5eefe.png", "sha256": "6a570065710d421450653030d672f4b873b2cdce4faf0498679f746cb5a5eefe", "size": 681 },
    { "path": "images/a16504f6f0654fc8a1d643a284ecbfac01588456eda10d31e35ee4f9d8049694.webp", "sha256": "a16504f6f0654fc8a1d643a284ecbfac01588456eda10d31e35ee4f9d8049694", "size": 1078 },
    { "path": "images/ab677ed8d23f4ae859c47197a97ad58bcafaecd452c032351f30e03cc26a2bd8.jpg", "sha256": "ab677ed8d23f4ae859c47197a97ad58bcafaecd452c032351f30e03cc26a2bd8", "size": 2748 }
  ]
}
```

L'archive complète, avec ses images, est dans le dépôt de ScoutMagic :
`tests/fixtures/data-migration/scoutmagic-member-photos/exemple.zip`, à côté
d'archives invalides (`elements-invalides.zip`, `invalide-*.zip`) et du
script qui les produit (`generate.php`). Les images n'y sont que des formes
dessinées, et les identifiants ne désignent personne.

## 11. Produire l'archive, pas à pas

1. Pour chaque photo à reprendre : lire ses octets, calculer leur SHA-256,
   les écrire dans `images/<sha256>.<extension>` (une seule fois par
   empreinte), et ajouter sa ligne à `photos.jsonl`, la photo préférée de
   chaque membre et année en premier.
2. Écrire `manifest.json`, avec l'empreinte de `photos.jsonl` et de chaque
   image.
3. Zipper le tout, sans mot de passe, `manifest.json` à la racine.
4. Vérifier : le `desk_id` est une chaîne, chaque `path` est
   `images/` + `sha256` + extension, l'extension correspond au vrai type,
   aucune image ne dépasse 10 Mo ni 16 mégapixels, au plus 4 000 images.
