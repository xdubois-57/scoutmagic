# Galeries photo — format `scoutmagic-gallery-links` 1.0.0

Ce document s'adresse à qui **produit**, sur un autre site, l'archive que
l'import « Galeries photo » du module « Migration de données » de
ScoutMagic lit. Il se suffit à lui-même avec
[`conventions.md`](conventions.md), qui fixe ce qui est commun à toutes les
archives : le ZIP, le manifeste, les versions, les empreintes SHA-256, les
identifiants et les limites. Il n'est pas nécessaire de lire le code de
ScoutMagic.

Les mots « doit », « ne doit pas » et « peut » ont leur sens strict.

## 1. Ce que l'import fait

L'archive décrit les **albums photo hébergés ailleurs** (Google Photos,
Flickr, un partage de fichiers…) que l'ancien site présentait. Chacun
devient un **album externe** de la Galerie de ScoutMagic : une carte qui
ouvre le site d'origine. **Aucune photo n'est copiée** ; les albums locaux,
leurs photos et leurs vidéos ne font pas partie de ce format.

Pendant l'import, ScoutMagic **relit la page de chaque album** pour y
prendre son titre, sa description et son image d'aperçu (balises Open
Graph `og:title`, `og:description`, `og:image`), avec les mêmes protections
qu'un album externe ajouté à la main. C'est lent : comptez environ **deux
secondes par album**. Aucune donnée Open Graph n'est attendue dans
l'archive.

L'import n'envoie aucune notification « Nouvel album ». Les albums importés
sont ensuite des albums ordinaires : ils apparaissent dans les listes et
les filtres par année et par section, et un animateur peut les modifier ou
les supprimer comme les autres.

Ce format ne nécessite que le module **Photos** (`gallery`) de ScoutMagic,
et les albums externes doivent y être autorisés (Configuration de la
Galerie). Sinon, chaque album est ignoré avec le motif « Albums externes
désactivés dans la configuration de la Galerie ».

## 2. Identification

| Élément | Valeur |
|---|---|
| `format` du manifeste | `scoutmagic-gallery-links` |
| `format_version` | `1.0.0` (ScoutMagic lit toute version `1.x.y`, voir `conventions.md` § 3) |
| Type d'objet (`source_kind`) | `album`, pour chaque ligne de `albums.jsonl` |

## 3. Structure de l'archive

```text
galeries.zip
├── manifest.json
└── albums.jsonl
```

- `manifest.json` : le manifeste commun (`conventions.md` § 2). Son
  inventaire `files` cite **`albums.jsonl`** avec son empreinte SHA-256.
- `albums.jsonl` : **obligatoire**, à la racine, JSON Lines encodé en
  **UTF-8** : un objet JSON par ligne, un album par objet
  (`conventions.md` § 7).
- **Aucun autre fichier.** Extensions admises : `json` et `jsonl`. Un
  fichier d'une autre extension, ou présent dans l'archive sans figurer
  dans l'inventaire, fait refuser l'archive entière.

L'ordre des lignes n'a pas d'importance pour le résultat, mais il doit
rester **le même** si l'archive est déposée deux fois : l'import avance
ligne par ligne et peut reprendre après une interruption.

## 4. Un album (`albums.jsonl`)

| Champ | Type | Obligatoire | Règle |
|---|---|---|---|
| `id` | chaîne | oui | Identifiant **stable** de l'album sur l'ancien site, 1 à 190 octets. Une chaîne, jamais un nombre JSON (`"0091"`, pas `91`). Il est montré dans l'aperçu et le bilan : choisissez-le **impersonnel** (`album-0091`), jamais le nom d'un enfant. |
| `url` | chaîne | **oui** | Adresse complète de l'album, `http://` ou `https://`, au plus **500 caractères**, sans espace. C'est ce lien que la carte ouvrira. |
| `album_date` | chaîne `AAAA-MM-JJ` | **oui** | Date de l'activité photographiée. La Galerie classe et trie les albums par cette date. Une date seule, sans heure ni fuseau. |
| `scout_year` | chaîne `AAAA-AAAA` | non | L'année scoute de l'album (`2019-2020`), si l'ancien site la connaît. **Absente ou vide**, ScoutMagic prend l'année dans laquelle tombe `album_date` : du 1er septembre au 31 août (`2019-11-09` → `2019-2020`, `2020-08-20` → `2019-2020`). Si elle est donnée, c'est elle qui compte, même si la date tombe dans une autre année. |
| `title` | chaîne | non | Titre de l'album, au plus **255 caractères**. **Absent ou vide**, ScoutMagic prend le titre de la page de l'album (`og:title`) ; si la page n'en donne pas non plus, l'album est ignoré. |
| `subtitle` | chaîne | non | Sous-titre, au plus **255 caractères**. Jamais pris de la page. |
| `section_desk_code` | chaîne | non | Le **code Desk** de la section (`sections.desk_code`), seulement si l'album était **réservé à une section**. **Absent, `null` ou vide** : l'album est visible de toute l'unité. Un code que ScoutMagic ne connaît pas fait **ignorer** l'album, qui n'est jamais montré à toute l'unité à la place. |

Tout autre champ est ignoré (`conventions.md` § 2). Les textes sont repris
tels quels, sans HTML : une balise s'affichera comme du texte.

### Ce que la page de l'album complète

Pour chaque album à importer, pendant l'import (jamais pendant l'analyse),
ScoutMagic lit la page `url` comme pour un album externe ajouté à la main :

- **le titre** : celui du fichier est gardé s'il existe. Sinon, celui de la
  page le remplace, coupé à 255 caractères. La Galerie affiche de toute
  façon le titre de la page quand elle en a un, comme pour tout album
  externe ;
- **la description** de la page est conservée comme description d'aperçu ;
- **l'image** de la page est téléchargée une fois, débarrassée de ses
  métadonnées et conservée par ScoutMagic, qui ne fait jamais charger
  l'image du site tiers par ses visiteurs. Une image trop lourde (plus de
  5 Mo) ou d'un autre type que JPEG, PNG, WebP ou GIF est simplement
  omise.

Si la page ne répond pas, refuse l'accès ou n'a pas de balise Open Graph,
l'album est importé avec les seules données du fichier. Une adresse
interne ou privée (réseau local, `localhost`…) n'est jamais lue.

## 5. Dédoublonnage

Deux règles, appliquées dans cet ordre :

1. **Par l'identifiant** (`conventions.md` § 5) : un album déjà importé
   depuis la même installation (`source.installation_id`), sous le même
   `id`, est « déjà présent » et n'est jamais modifié.
2. **Par l'adresse** : un album dont l'adresse est déjà celle d'un album
   externe de ScoutMagic — importé ou ajouté à la main — est « déjà
   présent », et l'album existant n'est pas modifié. Les adresses sont
   comparées après une normalisation **sûre** :
   - le schéma et le nom d'hôte sont mis en minuscules
     (`HTTPS://Photos.Example.org/a` = `https://photos.example.org/a`) ;
   - le fragment `#…` est ignoré ;
   - les espaces autour de l'adresse sont ignorés.

   **Rien d'autre** : le chemin, les paramètres `?…`, la barre oblique
   finale, le port et `http` contre `https` sont comparés tels quels. Deux
   adresses qui ne diffèrent que par l'un d'eux sont deux albums.

Deux lignes d'une même archive qui portent la même adresse : la première
est importée, la seconde est « déjà présente » à l'import (l'aperçu les
compte encore toutes deux « à importer »).

## 6. Années scoutes

L'année d'un album (`scout_year`, ou celle de `album_date`) doit exister
dans ScoutMagic, ou être une **année passée** que l'administrateur coche
dans l'aperçu pour la créer (`conventions.md` § 9). L'album d'une année non
cochée est ignoré (« Année scoute non créée ») : il n'est **jamais** rangé
dans l'année en cours. Une année en cours ou à venir absente du site fait
ignorer l'album (« Année scoute en cours ou à venir, absente du site »).

## 7. Limites

| Limite | Valeur |
|---|---|
| Archive déposée | 25 Mo |
| Fichiers dans l'archive | 10 au plus, dossiers compris (deux suffisent) |
| `albums.jsonl` décompressé | 20 Mio |
| Total décompressé | 21 Mio |
| Une ligne de `albums.jsonl` | 1 Mio |
| `url` | 500 caractères |
| `title`, `subtitle` | 255 caractères chacun |

## 8. Cas invalides et motifs au bilan

Ce qui fait refuser **toute l'archive**, avant la moindre écriture, est
décrit dans `conventions.md` § 8 (manifeste, version majeure, empreinte,
fichier non inventorié, extension…). Un album seul est **ignoré**, avec ce
motif au bilan, et les autres sont importés :

| Situation | Motif au bilan |
|---|---|
| Ligne qui n'est pas un objet JSON | Ligne illisible |
| `id` absent, vide ou numérique | Identifiant d'origine absent ou qui n'est pas une chaîne de caractères |
| `url` absente, vide, ou pas une adresse `http(s)://` complète | Adresse de l'album absente ou invalide |
| `url` de plus de 500 caractères | Adresse de l'album trop longue (plus de 500 caractères) |
| Albums externes désactivés dans ScoutMagic | Albums externes désactivés dans la configuration de la Galerie |
| `album_date` absente ou pas au format `AAAA-MM-JJ` | Date de l'album absente ou illisible |
| `scout_year` pas au format `AAAA-AAAA` d'années consécutives | Année scoute illisible |
| `section_desk_code` inconnu de ScoutMagic (ou qui n'est pas une chaîne) | Section sans correspondance |
| `title` de plus de 255 caractères | Titre trop long (plus de 255 caractères) |
| `subtitle` de plus de 255 caractères | Sous-titre trop long (plus de 255 caractères) |
| Ni titre dans le fichier, ni titre sur la page | Sans titre, ni dans le fichier ni sur la page de l'album |
| Année passée non cochée dans l'aperçu | Année scoute non créée |
| Année en cours ou à venir absente du site | Année scoute en cours ou à venir, absente du site |

Le bilan montre la référence `id` (ou `ligne N` quand l'`id` manque),
**jamais** le titre de l'album, qui peut nommer une personne.

## 9. Exemple complet

`albums.jsonl` (une ligne par album ; les lignes sont coupées ici pour la
lecture, elles ne le sont pas dans le fichier) :

```jsonl
{"id":"album-0001","url":"https://photos.example.org/albums/journee-de-rentree-2018","album_date":"2018-09-22","scout_year":"2018-2019","title":"Journée de rentrée","subtitle":"Toute l'unité au parc"}
{"id":"album-0002","url":"https://photos.example.org/albums/hike-louveteaux-2019","album_date":"2019-03-16","scout_year":"2018-2019","title":"Hike des louveteaux","section_desk_code":"EX-LOUV"}
{"id":"album-0003","url":"https://photos.example.org/albums/camp-eclaireurs-2019","album_date":"2019-07-20","section_desk_code":"EX-ECL"}
{"id":"album-0004","url":"https://photos.example.org/albums/souper-d-unite-2019","album_date":"2019-11-09","title":"Souper d'unité"}
{"id":"album-0005","url":"https://photos.example.org/albums/raid-pionniers-2020","album_date":"2020-02-15","scout_year":"2019-2020","title":"Raid des pionniers","section_desk_code":"EX-PIO"}
```

`manifest.json` :

```json
{
  "format": "scoutmagic-gallery-links",
  "format_version": "1.0.0",
  "source": {
    "installation_id": "ancien-site-exemple",
    "name": "Ancien site de l'unité d'exemple"
  },
  "export_id": "export-2026-11-08-galeries",
  "exported_at": "2026-11-08T14:30:00+01:00",
  "files": [
    {
      "path": "albums.jsonl",
      "sha256": "b6302a7d6d7b92a2211cff6738e9cd1e88cd470c886b4244666f60ef192c8401",
      "size": 861
    }
  ]
}
```

Sur un site qui connaît les sections `EX-LOUV` et `EX-ECL` et l'année
`2019-2020`, mais pas `2018-2019` :

- l'aperçu annonce 4 albums à importer et propose de créer l'année passée
  `2018-2019` (3 albums) ;
- `album-0003` n'a pas de titre : il prendra celui de sa page ;
- `album-0004` n'a pas d'année : sa date le range en `2019-2020` ;
- `album-0005` vise une section inconnue (`EX-PIO`) : il est ignoré
  (« Section sans correspondance ») plutôt que montré à toute l'unité ;
- si `2018-2019` n'est pas cochée, `album-0001` à `album-0003` sont ignorés
  (« Année scoute non créée ») et seul `album-0004` est importé.

Cette archive, et des exemples d'archives invalides, sont fournis avec le
code source de ScoutMagic dans
`tests/fixtures/data-migration/gallery-links/`.

## 10. Compatibilité

- Un champ nouveau et facultatif fera passer le format en `1.1.0` ; une
  archive `1.0.0` reste lisible.
- Un champ rendu obligatoire, ou dont le sens change, fera passer le format
  en `2.0.0`, que cette version de ScoutMagic refusera explicitement.
