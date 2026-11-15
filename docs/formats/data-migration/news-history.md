# Archive « Actualités et formulaires » — format `scoutmagic-news-history` 1.0.0

Ce document s'adresse à qui **produit**, depuis l'ancien site d'une unité,
l'archive que l'import « Actualités et formulaires » du module « Migration
de données » de ScoutMagic lit. Il se suffit à lui-même : aucune lecture du
code de ScoutMagic n'est nécessaire.

Il complète [`conventions.md`](conventions.md), qui fixe ce qui est commun
à toutes les archives de migration : le ZIP, le **manifeste**
(`manifest.json`), les versions, les empreintes SHA-256, les identifiants
et les limites. **Lisez-le d'abord** : tout ce qu'il impose s'applique ici,
et n'est pas répété.

Les mots « doit », « ne doit pas » et « peut » ont leur sens strict.

## 1. Ce que l'import fait de l'archive

- Chaque **actualité** devient une actualité de ScoutMagic, **publiée à sa
  date d'origine**, avec son titre, son résumé s'il existe, son contenu, sa
  visibilité et ses images.
- Chaque **formulaire** devient le formulaire de son actualité : ses
  questions, dans l'ordre, avec leur type, leur libellé et leurs options.
  Il est **toujours fermé**, quel que soit son état sur l'ancien site.
- **Aucune réponse n'est importée.** L'archive n'a pas à en contenir ; si
  elle en contient, elles sont ignorées sans être lues (§ 7).
- Une actualité importée et son formulaire sont **en lecture seule, pour
  toujours** : personne ne peut les modifier, les publier, les repasser en
  brouillon, les supprimer, ni répondre au formulaire. Ils portent la
  marque « Importé ».
- L'import n'annonce rien : aucune notification de publication, aucun
  courriel, aucun récapitulatif quotidien, aucun billet, aucune créance.

## 2. Identification

| Champ du manifeste | Valeur |
|---|---|
| `format` | `scoutmagic-news-history` (exactement) |
| `format_version` | `1.0.0` — ScoutMagic lit toute version `1.x.y` ; une autre version majeure est refusée |

## 3. Structure de l'archive

```text
actualites-et-formulaires.zip
├── manifest.json
├── articles/
│   ├── <id-actualité>.json      une actualité par fichier
│   └── …
├── forms/
│   ├── <id-formulaire>.json     un formulaire par fichier
│   └── …
└── media/
    ├── <sha256>                 une image par fichier, nommée par son empreinte
    └── …
```

- **Aucun répertoire de réponses.** Ce format n'en prévoit pas.
- Extensions admises : `.json`, `.jsonl`, `.csv`, les images de `media/`
  (sans extension, ou `.jpg`, `.jpeg`, `.png`, `.webp`, `.gif`). Un fichier
  d'une autre extension fait **refuser toute l'archive** (conventions § 1).
- Tout fichier figure dans l'inventaire `files` du manifeste, `media/`
  compris (conventions § 4).
- Encodage : **UTF-8** partout. Un fichier `.json` contient **un seul
  objet JSON**.

### 3.1 Le nom des fichiers

- `articles/<id>.json` : `<id>` est **exactement** l'identifiant `id` de
  l'actualité qu'il contient. Un fichier dont le nom diffère de son `id` est
  ignoré (« Nom de fichier différent de l'identifiant »). C'est par ce nom
  qu'un formulaire retrouve son actualité.

  Un identifiant d'actualité doit donc pouvoir être un nom de fichier : ni
  `/`, ni `\`, ni caractère de contrôle, ni `.` ou `..` seul.
- `forms/<id>.json` : `<id>` est conseillé égal à l'`id` du formulaire, sans
  obligation.
- `media/<sha256>` : `<sha256>` est l'empreinte SHA-256 **du fichier
  lui-même**, en 64 caractères hexadécimaux minuscules, la même que celle
  de l'inventaire ; une extension d'image peut suivre (`media/<sha256>.jpg`).
  Une image dont le nom ne correspond pas à son empreinte n'est pas
  importée. Une même image utilisée par plusieurs actualités n'est mise
  qu'une fois dans l'archive.

## 4. Une actualité : `articles/<id>.json`

```json
{
  "id": "a-1187",
  "title": "Souper aux spaghettis 2023",
  "summary": "Merci aux 240 convives ! Les bénéfices financeront le camp.",
  "body_html": "<p>Merci à toutes et à tous.</p><p><img src=\"media/3f…e1\" alt=\"La salle\"></p>",
  "published_at": "2023-03-02T18:00:00+01:00",
  "visibility": "identified",
  "image": "media/9c…4a"
}
```

| Champ | Type | Obligatoire | Règle |
|---|---|---|---|
| `id` | chaîne | oui | Identifiant **stable** de l'actualité sur l'ancien site (conventions § 5). Égal au nom du fichier, sans `.json`. Il sert au dédoublonnage et au rattachement des formulaires. |
| `title` | chaîne | oui | Le titre, 1 à 255 caractères (espaces de début et de fin retirés). |
| `published_at` | chaîne | oui | Le moment de la publication sur l'ancien site, ISO 8601 **avec fuseau** : `2023-03-02T18:00:00+01:00`, `2023-03-02T17:00:00Z` (les secondes et une fraction sont facultatives). Il est conservé tel quel et devient la date affichée et triée ; ce n'est jamais la date de l'import. Un moment à venir est refusé. |
| `summary` | chaîne | non | Le sous-titre ou résumé, **au plus 300 caractères**. Absent, `null` ou vide : l'actualité est importée **sans résumé**. ScoutMagic n'en invente jamais. |
| `body_html` | chaîne | non | Le contenu, en HTML. Il est **nettoyé** à l'import selon les règles du site : seules les balises de mise en forme courantes (paragraphes, titres, listes, liens, gras, italique, images…) sont gardées ; scripts, styles, `iframe`, attributs d'événement et liens `javascript:` sont retirés. |
| `body_text` | chaîne | non | Le contenu en texte brut, si l'ancien site n'a pas de HTML. Une ligne vide sépare deux paragraphes ; un saut de ligne simple est gardé. Ignoré si `body_html` est présent et non vide. |
| `visibility` | chaîne | non | Qui peut lire l'actualité (§ 4.1). |
| `image` | chaîne | non | L'image principale : le chemin d'une image de l'archive, `media/<sha256>` (§ 4.2). Absent ou `null` : pas d'image principale ; les listes montrent alors une vignette neutre « Pas d'image ». |
| `status` | chaîne | non | `published` (valeur par défaut). Toute autre valeur (`draft`, `scheduled`, `archived`…) fait **ignorer** l'actualité (« Pas publiée sur l'ancien site ») : seules les actualités qui ont été publiées sont reprises. |

Une actualité sans `body_html` ni `body_text` est importée avec son seul
titre. Tout autre champ est ignoré : auteur, mots-clés, réglages de
référencement, options avancées ne sont pas repris.

Un champ connu d'un **autre type** que celui annoncé (un `summary` qui est
un nombre, par exemple) fait ignorer l'actualité (« Champ de type
inattendu »).

### 4.1 Visibilité

| Valeur | Qui peut lire l'actualité dans ScoutMagic |
|---|---|
| `public` | Tout le monde, sans connexion |
| `identified` | Les membres connectés (animés, parents, animateurs) |
| `chief` | Les animateurs |
| `admin` | Les chefs d'unité |
| `direct_link` | Quiconque a le lien ; elle n'apparaît dans aucune liste |

Toute **autre valeur**, une valeur absente ou `null` est une visibilité
**inconnue** : l'actualité est importée, visible des seuls **membres
connectés** (`identified`), **jamais publique par défaut**. L'aperçu
compte ces actualités avant l'import. Faites correspondre vous-même les
niveaux de l'ancien site à ces cinq valeurs, par exemple « réservée aux
animateurs » → `chief`.

### 4.2 Images

- L'image principale (`image`) et les images du contenu sont des fichiers
  de `media/`.
- Dans `body_html`, une image intégrée s'écrit
  `<img src="media/<sha256>">` (guillemets simples ou doubles), le chemin
  **exactement** tel qu'il est dans l'archive. Les autres attributs (`alt`,
  `width`, `height`) sont gardés.
- Une `<img>` dont la source n'est pas une image de l'archive — une adresse
  de l'ancien site, par exemple — est **retirée** : l'ancien site ne restera
  pas en ligne. Exportez les images, ne les liez pas.
- Types admis, d'après le **contenu** du fichier et non son nom : JPEG,
  PNG, WebP, GIF. Taille : **5 Mo au plus** par image, et **50 mégapixels
  au plus** (largeur × hauteur).
- Une image illisible, absente de l'archive, d'un autre type, trop lourde,
  trop grande ou mal nommée n'est **pas importée**, mais son actualité l'est, sans elle.
  L'aperçu compte ces images.
- Les images sont réencodées à l'import (les métadonnées EXIF, dont la
  position GPS, sont supprimées) et des vignettes sont produites.

## 5. Un formulaire : `forms/<id>.json`

```json
{
  "id": "f-0412",
  "article_id": "a-1187",
  "fields": [
    { "type": "text", "content": "<p>Inscrivez-vous avant le 25 février.</p>" },
    { "type": "number", "label": "Nombre d'adultes", "required": true },
    { "type": "number", "label": "Nombre d'enfants" },
    { "type": "radio", "label": "Menu", "required": true, "options": ["Bolognaise", "Végétarien"] },
    { "type": "long_text", "label": "Allergies" }
  ]
}
```

| Champ | Type | Obligatoire | Règle |
|---|---|---|---|
| `id` | chaîne | oui | Identifiant stable du formulaire (conventions § 5). Il peut être égal à celui d'une actualité : les deux sont dédoublonnés séparément. |
| `article_id` | chaîne | oui | L'`id` de **son actualité**. |
| `fields` | liste | oui | Les questions, **dans l'ordre**, au moins une qui demande une réponse (§ 5.1). |

Tout autre champ est ignoré, en particulier un état d'origine (« ouvert »,
« complet »…) et un éventuel champ `responses` : **aucune réponse n'est
jamais lue ni importée**.

### 5.1 Une question

| Champ | Type | Obligatoire | Règle |
|---|---|---|---|
| `type` | chaîne | oui | Une des valeurs du tableau ci-dessous. |
| `label` | chaîne | pour les types qui demandent une réponse | Le libellé, 1 à 255 caractères, en **texte brut** : les caractères `<` et `>` sont refusés. |
| `required` | booléen | non | `true` si la réponse était obligatoire ; `false` par défaut. |
| `options` | liste de chaînes | pour `dropdown`, `radio`, `checkbox` | Les choix, dans l'ordre : au moins un, chacun non vide et **sur une seule ligne**. |
| `content` | chaîne | non | Pour `text` : un bloc de texte en HTML, nettoyé comme le contenu (les images en sont retirées). Pour `confirmation` : la phrase à confirmer, en texte brut. |

| `type` | Ce que c'est |
|---|---|
| `short_text` | Réponse courte, une ligne |
| `long_text` | Réponse longue, plusieurs lignes |
| `number` | Nombre |
| `date` | Date |
| `phone` | Numéro de téléphone |
| `email` | Adresse électronique |
| `dropdown` | Liste déroulante, un choix (`options`) |
| `radio` | Boutons, un choix (`options`) |
| `checkbox` | Cases, plusieurs choix (`options`) |
| `switch` | Oui ou non |
| `confirmation` | Phrase à confirmer avant l'envoi (`content`) |
| `text` | Bloc de texte sans réponse (`content`) |

Faites correspondre les types de l'ancien site à ceux-ci. Un formulaire qui
contient une question d'un **autre type**, une question sans libellé ou au
libellé invalide, ou une question à choix sans options valides est
**ignoré en entier**, avec son motif : il ne peut être montré tel qu'il
était. Les prix, places limitées, paiements et billets ne sont pas repris.

### 5.2 Rattachement à l'actualité

Un formulaire est importé seulement si son actualité est **reconnue** :

- elle figure dans la même archive et y est importable ; ou
- elle a déjà été importée, par une archive précédente du **même ancien
  site** (même `source.installation_id`).

Sinon il est ignoré (« Formulaire sans actualité reconnue ») et les autres
sont importés. Une actualité n'a **qu'un formulaire** : si plusieurs le
visent, le premier dans l'ordre des chemins (`forms/…`, octet par octet)
est importé et les suivants sont ignorés (« L'actualité a déjà un
formulaire »).

## 6. Dates et fuseaux

- `published_at` est un moment **avec son fuseau** (voir § 4). ScoutMagic
  l'affiche à l'heure de Bruxelles.
- Aucun autre champ de date n'est lu.

## 7. Fichiers de réponses et autres fichiers

L'archive n'a pas à contenir de réponses, et **ne devrait pas** en
contenir : ce sont des données personnelles qui n'ont rien à faire dans un
fichier qui ne les importera pas.

Si un fichier `.json`, `.jsonl` ou `.csv` se trouve **hors de `articles/`
et `forms/`** (par exemple `responses/f-0412.json`), il n'est **jamais
ouvert**. Il apparaît dans l'aperçu et le bilan comme ignoré, avec le motif
« Réponses de formulaire : jamais importées » si son chemin contient
`responses`, `reponses` ou `réponses`, « Fichier non prévu par ce format »
sinon. Il doit tout de même figurer dans l'inventaire du manifeste, comme
tout fichier.

## 8. Identifiants, dédoublonnage et relance

- Types d'objet (`source_kind`) : **`article`** pour une actualité,
  **`form`** pour un formulaire, **`file`** pour un fichier ignoré du § 7
  (jamais enregistré).
- Clé de dédoublonnage : (`source.installation_id`, `scoutmagic-news-history`,
  type d'objet, `id`), conventions § 5. Une actualité ou un formulaire déjà
  importé est compté « déjà présent » et **n'est jamais modifié** : une
  correction faite sur l'ancien site après un premier import n'est pas
  reprise.
- Relancer la même archive, ou une archive plus récente du même site,
  n'importe que ce qui ne l'a pas encore été. Si l'enregistrement d'une
  actualité échoue (erreur technique), son formulaire échoue avec elle ; les
  deux sont retentés à la relance.
- Il n'y a pas d'autre rapprochement : une actualité écrite à la main dans
  ScoutMagic avec le même titre n'est pas reconnue comme déjà présente.

## 9. Limites

| Limite | Valeur |
|---|---|
| Nombre de fichiers dans l'archive | 50 000 |
| Taille décompressée d'un fichier | 16 Mio |
| Taille d'un fichier `.json` lu (actualité, formulaire) | 8 Mio |
| Taille d'une image importée | 5 Mo (au-delà, l'image est laissée de côté) |
| Dimensions d'une image importée | 50 mégapixels (au-delà, l'image est laissée de côté) |
| Taille décompressée totale | 3 Gio |
| Taille de l'archive déposée | 2 Gio |
| Titre | 255 caractères |
| Résumé | 300 caractères |
| Libellé de question | 255 caractères |

## 10. Cas invalides et leur motif au bilan

L'archive entière est refusée, avant toute écriture, dans les cas de
`conventions.md` § 8 (chemin dangereux, extension non admise, manifeste
invalide, empreinte fausse, version majeure inconnue…).

Sinon, seul l'élément concerné est écarté, avec ce motif ; les autres sont
importés :

| Situation | Motif au bilan |
|---|---|
| Fichier qui n'est pas un objet JSON | Fichier illisible |
| `id` absent, vide ou numérique | Identifiant d'origine absent ou qui n'est pas une chaîne de caractères |
| `articles/<nom>.json` dont `<nom>` n'est pas l'`id` | Nom de fichier différent de l'identifiant |
| `title` absent ou vide | Sans titre |
| `title` de plus de 255 caractères | Titre de plus de 255 caractères |
| `published_at` absent, sans fuseau ou impossible | Date de publication absente ou illisible |
| `published_at` à venir | Date de publication à venir |
| `status` autre que `published` | Pas publiée sur l'ancien site |
| `summary` de plus de 300 caractères | Résumé de plus de 300 caractères |
| Champ connu d'un type inattendu | Champ de type inattendu |
| Formulaire sans question qui demande une réponse | Formulaire sans question |
| Question d'un type absent du § 5.1 | Question d'un type non reconnu |
| Question sans libellé, libellé trop long ou contenant `<` ou `>` | Question sans libellé ou au libellé invalide |
| Question à choix sans options, ou option vide ou sur plusieurs lignes | Question à choix sans options valides |
| Formulaire dont l'actualité n'est pas reconnue (§ 5.2) | Formulaire sans actualité reconnue |
| Second formulaire pour une même actualité | L'actualité a déjà un formulaire |
| Fichier de réponses (§ 7) | Réponses de formulaire : jamais importées |
| Autre fichier `.json`, `.jsonl`, `.csv` hors de `articles/` et `forms/` | Fichier non prévu par ce format |

Ce qui est importé avec un avertissement dans l'aperçu, sans être écarté :
une visibilité inconnue (→ membres connectés), une actualité sans image ou
sans résumé, une image laissée de côté (§ 4.2).

Le bilan ne montre que l'identifiant `id` d'origine (ou le chemin du
fichier quand il n'a pas d'`id`) et le motif, jamais un nom de personne :
choisissez des identifiants impersonnels.

## 11. Exemple complet

Une archive minimale valide, avec une actualité, son formulaire et son
image (l'empreinte est raccourcie ici pour la lecture ; dans une vraie
archive elle compte 64 caractères) :

`manifest.json`

```json
{
  "format": "scoutmagic-news-history",
  "format_version": "1.0.0",
  "source": { "installation_id": "ancien-site-unite-exemple" },
  "export_id": "2026-11-08-actualites",
  "exported_at": "2026-11-08T14:30:00+01:00",
  "files": [
    { "path": "articles/a-1187.json", "sha256": "…", "size": 412 },
    { "path": "forms/f-0412.json", "sha256": "…", "size": 377 },
    { "path": "media/9c4a…", "sha256": "9c4a…", "size": 48213 }
  ]
}
```

`articles/a-1187.json`

```json
{
  "id": "a-1187",
  "title": "Souper aux spaghettis 2023",
  "summary": "Merci aux 240 convives ! Les bénéfices financeront le camp des Pionniers.",
  "body_html": "<p>Merci à toutes et à tous d'être venus si nombreux.</p>",
  "published_at": "2023-03-02T18:00:00+01:00",
  "visibility": "identified",
  "image": "media/9c4a…"
}
```

`forms/f-0412.json`

```json
{
  "id": "f-0412",
  "article_id": "a-1187",
  "fields": [
    { "type": "number", "label": "Nombre d'adultes", "required": true },
    { "type": "number", "label": "Nombre d'enfants" },
    { "type": "long_text", "label": "Allergies" }
  ]
}
```

Le dépôt de ScoutMagic contient une archive d'exemple complète et
réellement valide, avec ses empreintes, sous
`tests/fixtures/data-migration/scoutmagic-news-history/exemple/` (le contenu
décompressé) et `exemple.zip` (l'archive elle-même), ainsi que des cas
invalides sous `invalides/`.

## 12. Compatibilité

- Une version **1.x.y** ultérieure ne fera qu'ajouter des champs
  facultatifs ; ScoutMagic ignore ceux qu'il ne connaît pas.
- Un changement incompatible (un champ obligatoire ajouté, un sens
  modifié) fera passer le format en **2.0.0**, qu'un ScoutMagic qui ne lit
  que la version 1 refusera explicitement.
