# Historique des courriels — format `scoutmagic.email-history` 1.0.0

Ce document décrit l'archive que lit l'import « Historique des courriels »
du module « Migration de données » de ScoutMagic. Il s'adresse à qui
**produit** cette archive sur un autre site et se suffit à lui-même, avec
[`conventions.md`](conventions.md), qui fixe ce qui est commun à tous les
formats : le ZIP, le manifeste, les versions, les empreintes, les
identifiants et les limites générales. Ce qui suit ne répète pas ces règles :
il les complète.

L'import reprend les **courriels sortants** de l'ancien site dans
l'historique d'envoi de ScoutMagic (module « Envoi de mails »). Ils y sont
**en lecture seule** pour toujours : ils ne peuvent être ni modifiés, ni
supprimés, ni renvoyés, et **aucun courriel n'est envoyé** par l'import.

## 1. Identité du format

| Champ du manifeste | Valeur |
|---|---|
| `format` | `scoutmagic.email-history` |
| `format_version` | `1.0.0`. ScoutMagic lit toute version `1.x.y` et refuse toute autre version majeure. |
| Module requis | « Envoi de mails » (`mass_mail`) actif sur le site qui importe. |

## 2. Structure de l'archive

```text
historique-emails.zip
├── manifest.json                      le manifeste commun (conventions.md § 2)
├── communications/
│   └── <identifiant>.json             un fichier par courriel
├── content/
│   └── <sha256>.html | <sha256>.txt   les corps des courriels
└── attachments/
    └── <sha256>                       les pièces jointes, sans extension
```

- **Extensions admises** : `json`, `html`, `txt`, et aucune extension pour
  les pièces jointes. Un fichier d'une autre extension fait refuser toute
  l'archive.
- Les fichiers de `content/` et `attachments/` sont **nommés par l'empreinte
  SHA-256 de leurs octets**, en hexadécimal minuscule. Un même corps ou une
  même pièce jointe partagés par plusieurs courriels n'est donc écrit
  qu'une fois.
- Le nom d'un fichier de `communications/` est libre (le plus simple :
  l'identifiant du courriel suivi de `.json`) ; c'est le champ `id` de son
  contenu qui identifie le courriel. ScoutMagic lit ces fichiers dans
  l'ordre de leurs noms, octet par octet.
- Tout fichier hors de ces trois dossiers est ignoré (mais doit figurer au
  manifeste, comme tout fichier de l'archive).
- **Tous** les fichiers, `manifest.json` excepté, figurent dans l'inventaire
  `files` du manifeste avec leur SHA-256 (conventions.md § 4).

## 3. Encodage

- Tout est en **UTF-8**. Un corps `html` ou `txt` qui n'est pas de l'UTF-8
  valide fait ignorer le courriel.
- Un fichier de `communications/` est **un objet JSON**.
- Les **moments** s'écrivent en ISO 8601 **avec fuseau** :
  `2024-05-21T18:30:00+02:00` ou `2024-05-21T16:30:00Z`, secondes et
  fractions de seconde facultatives. Une date sans fuseau est illisible.
  ScoutMagic les convertit à l'heure locale du site.

## 4. Un courriel : `communications/<identifiant>.json`

```json
{
  "id": "c-2024-0521-001",
  "sent_at": "2024-05-21T18:30:00+02:00",
  "subject": "Infos camp d'été 2024",
  "sender": { "address": "eclaireurs@exemple.test", "name": "Éclaireurs 99e Exempleville" },
  "body": {
    "html": "content/0ef8…587f.html",
    "text": "content/3a5b…ab04.txt"
  },
  "audience_name": "Parents des Éclaireurs",
  "mail_merge": false,
  "recipients": [
    { "address": "famille.alpha@exemple.test", "status": "accepted",
      "sent_at": "2024-05-21T18:31:04+02:00", "desk_ids": ["900001"] },
    { "address": "famille.beta@exemple.test", "status": "unknown", "desk_ids": ["900002"] },
    { "address": "ancien.chef@exemple.test", "status": "failed" }
  ],
  "desk_ids": ["900003"],
  "attachments": [
    { "path": "attachments/0c57…fdf6", "sha256": "0c57…fdf6",
      "filename": "programme-camp.pdf", "media_type": "application/pdf" }
  ]
}
```

### 4.1. Champs obligatoires

Ce sont les quatre choses qui font un message. Un courriel auquel il en
manque une est **ignoré**, avec le motif indiqué, et les autres sont
importés.

| Champ | Type | Règle | Motif au bilan si absent ou invalide |
|---|---|---|---|
| `id` | chaîne | Identifiant **stable** du courriel sur l'ancien site, 1 à 190 octets (conventions.md § 5). Jamais un nombre JSON. | « Identifiant d'origine absent ou qui n'est pas une chaîne de caractères » |
| `subject` | chaîne | Le sujet, non vide, au plus 255 caractères. | « Sans sujet », « Sujet de plus de 255 caractères » |
| `sent_at` | chaîne | Le moment de l'envoi (§ 3), pas dans le futur. | « Sans date d'envoi », « Date d'envoi illisible », « Date d'envoi dans le futur » |
| `sender.address` | chaîne | L'adresse d'expédition, une adresse électronique valide. | « Sans expéditeur », « Adresse d'expéditeur invalide » |
| `body` | objet | Au moins un de `html` et `text` (§ 4.3), qui dise quelque chose une fois nettoyé. | « Sans contenu », « Contenu introuvable dans l'archive », « Contenu de plus de 2 Mio », « Contenu qui n'est pas en UTF-8 » |

Un fichier de `communications/` qui n'est pas un objet JSON est ignoré avec
le motif « Fichier illisible ».

### 4.2. Champs facultatifs

Un champ facultatif absent reste absent : ScoutMagic **n'invente rien**. Un
champ facultatif invalide est ignoré seul ; il ne coûte jamais le courriel.

| Champ | Type | Règle |
|---|---|---|
| `sender.name` | chaîne | Le nom d'expéditeur affiché par l'ancien site. Tronqué à 255 caractères. |
| `audience_name` | chaîne | Le **nom** de la liste de diffusion d'origine (« Parents des Éclaireurs »). Gardé et affiché comme simple texte ; les destinataires ne sont **jamais** recalculés à partir de l'annuaire actuel. Tronqué à 255 caractères. |
| `mail_merge` | booléen | `true` pour un publipostage. Il est importé comme **un seul courriel, avec son texte modèle** (variables comprises) ; les versions personnalisées ne sont pas reprises. |
| `recipients` | liste | Les adresses auxquelles le courriel est parti, si l'ancien site les connaît (§ 4.4). Absente ou vide : le courriel est importé **sans destinataire**. |
| `desk_ids` | liste de chaînes | Les membres que le courriel concernait sans qu'on connaisse leur adresse (§ 4.5). |
| `attachments` | liste | Les pièces jointes (§ 4.6). |

### 4.3. Le corps : `body`

| Champ | Type | Règle |
|---|---|---|
| `body.html` | chaîne | Chemin d'un fichier `content/<sha256>.html` de l'archive : la version HTML **effectivement envoyée**. |
| `body.text` | chaîne | Chemin d'un fichier `content/<sha256>.txt` : la version texte. |

- ScoutMagic garde la version HTML si, une fois **nettoyée** selon ses règles
  (balises et attributs non admis retirés, scripts et styles supprimés, liens
  limités à `http`, `https`, `mailto` et `tel`), elle contient encore du texte
  ou une image. Sinon il prend la version texte, mise en paragraphes.
- Un chemin cité mais absent de l'archive fait ignorer le courriel.
- Un corps fait **au plus 2 Mio**.
- Une image référencée par une adresse `http(s)` reste telle quelle. Une
  **image intégrée** (`<img src="cid:…">`) ne peut pas être affichée dans
  le corps : sa référence est retirée au nettoyage. Pour la conserver,
  fournissez-la comme pièce jointe (§ 4.6) ; `content_id` peut alors en
  garder la trace.

### 4.4. Les destinataires : `recipients`

Chaque entrée est un objet :

| Champ | Type | Obligatoire | Règle |
|---|---|---|---|
| `address` | chaîne | oui | Une adresse électronique valide. Sinon l'entrée est ignorée (et comptée dans l'aperçu), le reste du courriel est importé. |
| `status` | chaîne | non | `accepted` : le serveur d'envoi de l'ancien site a accepté le message — affiché « Accepté par le serveur d'envoi ». `failed` : l'envoi a échoué — affiché « Erreur », avec le texte fixe « Échec signalé par l'ancien site. ». `unknown` : on ne sait pas — affiché « Inconnu ». **Toute autre valeur, et son absence, valent `unknown`.** Aucune valeur ne devient « Remis » : rien ne prouve qu'un message a été distribué. |
| `sent_at` | chaîne | non | Le moment où ce message est parti (§ 3). À défaut, celui du courriel. N'est affiché que pour `accepted`. |
| `desk_ids` | liste de chaînes | non | Les membres à qui appartient cette adresse (§ 4.5). |

Ne listez une adresse que si l'ancien site l'a vraiment utilisée pour ce
courriel. Le message d'erreur d'origine n'est pas repris.

### 4.5. Les membres : `desk_ids`

- Un membre est désigné par son **identifiant Desk** (numéro de Tiers), en
  chaîne (conventions.md § 5), dans `desk_ids` d'un destinataire ou du
  courriel.
- ScoutMagic retrouve le membre, y compris sous un ancien identifiant après
  une fusion de fiches. Le courriel apparaît alors dans les « Communications
  récentes » de sa fiche.
- Un identifiant qui ne correspond à aucun membre est **ignoré** : le lien
  n'est pas fait et **aucun membre n'est créé**. Le courriel est importé.

### 4.6. Les pièces jointes : `attachments`

Chaque entrée est un objet :

| Champ | Type | Obligatoire | Règle |
|---|---|---|---|
| `path` | chaîne | oui | Chemin du fichier dans l'archive, sous `attachments/`. |
| `sha256` | chaîne | oui | L'empreinte SHA-256 des octets du fichier, 64 caractères hexadécimaux minuscules. |
| `filename` | chaîne | non | Le nom affiché. À défaut : « piece-jointe ». |
| `media_type` | chaîne | non | Informatif : seul compte le type **réel**, lu dans les octets. |
| `content_id` | chaîne | non | Informatif : l'identifiant d'une image intégrée (§ 4.3). |

Une pièce jointe est **ignorée**, et le courriel importé sans elle, si :

- son chemin est absent de l'archive ou hors de `attachments/` ;
- son empreinte ne correspond pas à `sha256` ;
- elle dépasse **10 Mo** ;
- son type réel n'est pas PDF, JPEG, PNG, GIF ou WebP.

Ce sont les règles d'une pièce jointe ajoutée à la main. Les images sont
réenregistrées sans leurs métadonnées.

Un même `path` cité plusieurs fois par un courriel n'y est joint **qu'une
fois**, sous le nom de sa première entrée : le fichier est nommé par son
contenu, c'est donc la même pièce jointe.

## 5. Ce que devient un courriel

- **Section** : si `sender.address` est l'adresse d'une section ScoutMagic
  (comparée sans tenir compte de la casse ni des espaces autour), le
  courriel est rattaché à cette section. Sinon, ou si plusieurs sections ont
  cette adresse, il est rattaché au **Staff d'U**. Il est alors visible de
  qui peut voir les envois de cette section. Si le site n'a pas encore de
  section Staff d'U, le courriel qu'elle aurait reçu est ignoré avec le motif
  « Section « Staff d'U » absente du site ».
- L'**adresse et le nom d'expéditeur d'origine** sont conservés (chiffrés)
  et affichés tels quels.
- La **date** d'envoi est celle de `sent_at` : le courriel se range parmi
  ceux de son époque.
- Le courriel porte la mention « Importé » et le bandeau « Élément
  historique en lecture seule ».

## 6. Dédoublonnage et types d'objets

- Type d'objet (`source_kind`) : **`email`**, pour chaque courriel.
- La clé est (`source.installation_id`, `scoutmagic.email-history`, `email`,
  `id`), comparée octet par octet (conventions.md § 5). Un courriel déjà
  importé est « déjà présent » et n'est jamais réécrit.
- ScoutMagic retient aussi l'installation et l'identifiant d'origine sur le
  courriel lui-même : un courriel déjà importé est reconnu même si la
  correspondance de l'import a disparu.
- Deux fichiers portant le même `id` ne donnent qu'un courriel : le premier
  dans l'ordre des noms.

## 7. Limites

| Limite | Valeur | Dépassement |
|---|---|---|
| Archive déposée | 2 Gio | Dépôt refusé |
| Nombre de fichiers | 200 000 | Archive refusée |
| Un fichier, décompressé | 64 Mio | Archive refusée |
| Un fichier de `communications/` | 4 Mio | Archive refusée |
| Un corps (`content/`) | 2 Mio | Courriel ignoré |
| Une pièce jointe | 10 Mo | Pièce jointe ignorée |
| Sujet | 255 caractères | Courriel ignoré |

## 8. Cas invalides

| Situation | Effet |
|---|---|
| `format` différent, version majeure autre que 1, manifeste incohérent, empreinte fausse, extension non admise | Archive refusée, rien n'est importé (conventions.md § 8) |
| Fichier de `communications/` qui n'est pas un objet JSON | Courriel ignoré : « Fichier illisible » |
| `id` absent ou numérique | Courriel ignoré |
| Sujet, date, expéditeur ou contenu manquant ou invalide | Courriel ignoré avec son motif (§ 4.1) |
| Destinataire sans adresse valide | Destinataire ignoré, courriel importé |
| `desk_id` inconnu | Lien ignoré, courriel importé, aucun membre créé |
| Pièce jointe invalide | Pièce jointe ignorée, courriel importé |
| Statut de destinataire inconnu (`delivered`, `opened`…) | Lu comme `unknown` |

Le bilan montre la référence de l'ancien site (le champ `id`, ou le chemin du
fichier) et le motif, **jamais le nom d'une personne** : choisissez des
identifiants impersonnels.

## 9. Exemple complet

Le dépôt de ScoutMagic contient, sous
`tests/fixtures/data-migration/email-history/`, des archives inventées :

- `exemple.zip` et le dossier `exemple/` qu'elle contient : trois courriels
  valides — un courriel HTML avec texte, trois destinataires dans les trois
  statuts, deux membres, deux pièces jointes et un champ inconnu ignoré ; un
  courriel texte sans destinataire ; un publipostage ;
- `cas-ignores.zip` et `cas-ignores/` : un courriel par motif d'exclusion,
  et trois courriels importés sans leur partie invalide ;
- `version-majeure-2.zip` : refusée entière ;
- `generate.php`, qui les écrit.

Le manifeste de `exemple.zip` :

```json
{
    "format": "scoutmagic.email-history",
    "format_version": "1.0.0",
    "source": {
        "installation_id": "site-exempleville",
        "name": "Ancien site du 99e Exempleville"
    },
    "export_id": "export-2026-11-08-courriels",
    "exported_at": "2026-11-08T14:30:00+01:00",
    "files": [
        { "path": "attachments/0c57c5334ee551035603cc220f86b25a4ee98666e3753def892c357c73c5fdf6", "sha256": "0c57c5334ee551035603cc220f86b25a4ee98666e3753def892c357c73c5fdf6", "size": 218 },
        { "path": "communications/c-2024-0521-001.json", "sha256": "b0b6be135895122f9de0b1bb9b578779f7db37a03a4dc82d22c783ab66447189", "size": 1772 }
    ]
}
```

(Extrait : le manifeste réel cite les neuf fichiers de l'archive.)

Un courriel minimal, sans destinataire :

```json
{
  "id": "c-2023-0904-002",
  "sent_at": "2023-09-04T07:15:00Z",
  "subject": "Réunion de rentrée",
  "sender": { "address": "staff@exemple.test" },
  "body": { "text": "content/c13c…7bb6.txt" },
  "audience_name": "Toute l'unité"
}
```
