# Archives de migration de données — conventions communes

Ce document s'adresse à qui **produit** une archive pour le module
« Migration de données » de ScoutMagic, depuis un autre site : il se suffit
à lui-même et ne suppose aucune lecture du code de ScoutMagic. Chaque
format (photos des membres, galeries, endroits de camp…) a son propre
document dans ce dossier, qui précise **le contenu** de l'archive ; celui-ci
fixe ce qui est **commun à toutes** : la structure, le manifeste, les
versions, les empreintes, les identifiants et les limites.

Les mots « doit », « ne doit pas » et « peut » ont leur sens courant et
strict : une archive qui ne respecte pas un « doit » est refusée.

## 1. L'archive

- Un fichier **ZIP** standard (méthodes *stored* ou *deflate*), **sans mot
  de passe ni chiffrement**.
- À la racine, un fichier **`manifest.json`** (§ 2). Les autres fichiers
  sont ceux que décrit le document du format, et **aucun autre**.
- Les chemins sont relatifs, séparés par `/`, en UTF-8. Sont **refusés** :
  un chemin absolu (`/…`), une lettre de lecteur (`C:…`), un segment `..`
  ou `.`, un segment vide (`a//b`), une barre oblique inverse `\`, un
  caractère de contrôle, deux fichiers portant le même chemin.
- **Aucun lien symbolique.** Une archive qui en contient est refusée.
- Chaque format fixe la **liste des extensions** admises (par exemple
  `json`, `jsonl`, `jpg`). Un fichier d'une autre extension fait refuser
  l'archive entière.
- Les dossiers peuvent figurer dans l'archive ou non ; ils sont ignorés.

ScoutMagic **n'extrait jamais** l'archive sur le disque : il lit chaque
fichier depuis le ZIP, en mesurant ce qu'il décompresse (§ 6).

## 2. Le manifeste (`manifest.json`)

Un objet JSON, encodé en UTF-8, d'au plus 1 Mio.

```json
{
  "format": "scoutmagic-member-photos",
  "format_version": "1.0.0",
  "source": {
    "installation_id": "ancien-site-de-l-unite",
    "name": "Ancien site (texte libre, facultatif)"
  },
  "export_id": "2026-11-08-1430-photos",
  "exported_at": "2026-11-08T14:30:00+01:00",
  "files": [
    { "path": "photos.jsonl", "sha256": "9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08", "size": 4 }
  ]
}
```

| Champ | Type | Obligatoire | Règle |
|---|---|---|---|
| `format` | chaîne | oui | L'identifiant du format, **exactement** celui que nomme le document du format. Une archive d'un autre format déposée dans un import est refusée. |
| `format_version` | chaîne | oui | Version **sémantique complète** `MAJEUR.MINEUR.CORRECTIF` (`1.0.0`, jamais `1.0` ni le nombre `1`). Voir § 3. |
| `source.installation_id` | chaîne | oui | Identifiant **stable** de l'installation d'origine : le même pour toutes les archives produites par ce site, aujourd'hui et plus tard. 1 à 190 octets. Voir § 5. |
| `export_id` | chaîne | oui | Identifiant unique de cette opération d'export. 1 à 190 octets. Purement informatif. |
| `exported_at` | chaîne | oui | Date et heure de production, ISO 8601 **avec fuseau** : `2026-11-08T14:30:00+01:00` ou `2026-11-08T13:30:00Z`. |
| `files` | liste | oui | L'inventaire : **une entrée par fichier de l'archive**, le manifeste excepté. Voir § 4. |

**Champs inconnus.** ScoutMagic ignore tout champ qu'il ne connaît pas, à
n'importe quel niveau, dans le manifeste comme dans les données. Un
exporteur peut donc ajouter des informations sans risque ; elles ne seront
simplement pas reprises.

**Manifeste contradictoire.** Est refusée, en entier, une archive dont le
manifeste :

- a un champ obligatoire absent, vide ou du mauvais type ;
- cite deux fois le même chemin dans `files`, ou s'y cite lui-même ;
- cite un chemin absent de l'archive ;
- **ne cite pas** un fichier présent dans l'archive.

## 3. Versions

`format_version` suit le versionnement sémantique.

- **MAJEUR** change quand une archive de l'ancienne version ne peut plus
  être lue correctement par un lecteur de la nouvelle, ou l'inverse (champ
  obligatoire ajouté, sens d'un champ modifié, structure changée).
- **MINEUR** change pour un ajout **compatible** (champ facultatif nouveau).
- **CORRECTIF** change pour une correction qui ne touche pas la structure.

ScoutMagic lit une **seule version majeure** par format, celle que nomme
le document du format. Une archive d'une autre version majeure est
**refusée explicitement**, jamais interprétée au hasard. Toute version
mineure ou corrective de la version majeure connue est acceptée ; ce
qu'elle ajoute est ignoré (§ 2, champs inconnus).

## 4. Inventaire et empreintes

Chaque entrée de `files` est un objet :

| Champ | Type | Obligatoire | Règle |
|---|---|---|---|
| `path` | chaîne | oui | Le chemin du fichier dans l'archive, tel quel. |
| `sha256` | chaîne | oui | L'empreinte **SHA-256** des octets **décompressés** du fichier, en **64 caractères hexadécimaux minuscules**. |
| `size` | entier | non | La taille décompressée, en octets. Si elle est donnée, elle doit être exacte. |

Avant toute analyse, ScoutMagic recalcule l'empreinte (et la taille, si
elle est donnée) de **chaque** fichier inventorié. **Une seule empreinte
fausse fait refuser toute l'archive, avant la moindre écriture** : un
fichier tronqué pendant le transfert ou modifié après l'export n'est
jamais importé à moitié.

Pour calculer une empreinte en ligne de commande : `sha256sum fichier`
(Linux), `shasum -a 256 fichier` (macOS), `Get-FileHash -Algorithm SHA256`
(PowerShell, en minuscules ensuite).

## 5. Identifiants

- **Tout identifiant est une chaîne de caractères**, même quand il
  ressemble à un nombre : `"007"`, jamais `7`. Un identifiant transmis
  comme nombre JSON est **refusé** — l'élément est ignoré avec ce motif —
  parce que les zéros initiaux y sont déjà perdus.
- Les identifiants sont **comparés octet par octet** : `"A-1"` et `"a-1"`,
  `"007"` et `"7"` sont deux objets différents.
- Longueur : 1 à 190 octets en UTF-8.
- Un identifiant d'objet doit être **stable** : le même objet de l'ancien
  site porte le même identifiant à chaque export.
- Un membre est désigné par son **identifiant Desk** (le numéro de Tiers),
  dans un champ explicite `desk_id`, jamais par un identifiant interne de
  l'ancien site.
- Une section est désignée par son **code Desk** (`section_desk_code`
  dans les formats qui en ont besoin), jamais par un identifiant interne.
  Sans correspondance dans ScoutMagic, le lien est ignoré ; si le lien
  décide de qui peut voir l'objet, l'objet est ignoré plutôt que montré à
  toute l'unité.
- Une année scoute est désignée par son libellé `AAAA-AAAA` (`2019-2020`).

### Dédoublonnage

ScoutMagic retient, pour chaque objet importé, le quadruplet
**(`source.installation_id`, `format`, type d'objet, identifiant)** — le type
d'objet est fixé par le document du format (par exemple `article` et
`form` dans les actualités, qui peuvent porter le même identifiant). Un
objet déjà importé sous ce quadruplet est compté « déjà présent » et
**n'est jamais réécrit ni modifié**. Réimporter la même archive, ou une
archive plus récente du même site, ne crée donc aucun doublon ; c'est
pourquoi `installation_id` doit rester le même d'un export à l'autre.

Chaque format peut ajouter ses propres règles de reconnaissance d'un
objet déjà présent dans ScoutMagic (une adresse d'album déjà connue, un
lieu de camp semblable) : elles sont décrites dans son document.

## 6. Limites

Chaque format fixe ses limites dans son document : nombre de fichiers,
taille décompressée d'un fichier, taille décompressée totale, taille de
l'archive déposée (au plus **2 Gio** quel que soit le format). Les tailles
sont **mesurées pendant la décompression**, jamais lues dans les en-têtes
du ZIP : une archive qui annonce de petites tailles et se décompresse en
beaucoup plus (une « bombe ») est refusée dès que le plafond est franchi.

## 7. Fichiers JSON et JSON Lines

- Encodage **UTF-8**, sans BOM de préférence.
- Un fichier `.json` contient un seul objet JSON.
- Un fichier `.jsonl` (JSON Lines) contient **un objet JSON par ligne**,
  lignes séparées par `\n` (un `\r\n` est accepté). Les lignes vides sont
  ignorées. Une ligne d'au plus 1 Mio.
- Une ligne illisible (JSON invalide, ou qui n'est pas un objet) n'arrête
  pas l'import : l'élément est **ignoré avec le motif « Ligne illisible »**
  et les autres sont importés.
- Les dates seules s'écrivent `AAAA-MM-JJ` ; les moments, en ISO 8601 avec
  fuseau.

## 8. Ce qui fait refuser l'archive entière, et ce qui n'ignore qu'un élément

| Situation | Effet |
|---|---|
| Chemin dangereux, lien symbolique, extension non admise, plafond dépassé | Archive refusée, rien n'est écrit |
| Manifeste absent, invalide ou contradictoire ; version majeure inconnue | Archive refusée, rien n'est écrit |
| Empreinte ou taille fausse | Archive refusée, rien n'est écrit |
| Ligne illisible, identifiant absent ou numérique, champ obligatoire d'un élément manquant | L'élément est ignoré avec son motif, les autres sont importés |
| Objet déjà importé (§ 5) | « Déjà présent », jamais modifié |
| Référence vers un membre, une section ou une année introuvable | Le lien est ignoré ; s'il est indispensable, l'élément est ignoré |
| Erreur technique en écrivant un élément | L'élément est compté en erreur et sera retenté à la prochaine relance ; les autres sont importés |

Le bilan d'import ne montre **jamais le nom d'une personne** : seulement la
référence de l'ancien site et le motif. Un exporteur qui veut qu'un bilan
soit lisible choisit donc des identifiants parlants mais impersonnels.

## 9. Années scoutes passées

Quand des éléments visent une année scoute **passée** qui n'existe pas
encore dans ScoutMagic, l'aperçu la propose à la création et
l'administrateur coche celles à créer. Les éléments d'une année non
cochée sont ignorés. L'année en cours et les années à venir ne sont
**jamais** créées par un import.
