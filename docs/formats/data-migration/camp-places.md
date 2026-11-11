# Format `scoutmagic-camp-places` — endroits de camp

Ce document s'adresse à qui **produit**, depuis un ancien site, l'archive
que l'import « Endroits de camp » de ScoutMagic lit. Il se suffit à lui-même
pour le contenu de l'archive ; le manifeste, les versions, les empreintes,
les identifiants et les limites communes sont décrits dans
[`conventions.md`](conventions.md), qu'il faut lire d'abord.

Les mots « doit », « ne doit pas » et « peut » ont le sens strict de
`conventions.md`.

## 1. En bref

| | |
|---|---|
| Identifiant du format (`format`) | `scoutmagic-camp-places` |
| Version décrite (`format_version`) | `1.0.0` (ScoutMagic lit toute version `1.x.y`) |
| Module ScoutMagic requis | Camps (`camps`) |
| Ce qui est repris | Les **lieux de camp** eux-mêmes : nom, adresse, coordonnées, site web, état archivé |
| Ce qui n'est pas repris | Les anciens séjours, les sections qui y ont campé, les contacts, les avis, les prix, les contrats, les documents et les photos |
| Type d'objet (`source_kind`) | `place` |

Aucun lien vers une année scoute, une section ou un membre n'est nécessaire
pour décrire un lieu.

## 2. Structure de l'archive

```text
endroits-de-camp.zip
├── manifest.json
└── places.jsonl
```

- Exactement **deux fichiers**, à la racine : `manifest.json` (voir
  `conventions.md` § 2) et `places.jsonl`. Le nom du ZIP est libre.
- Extensions admises : `json` et `jsonl`. Un fichier d'une autre extension
  fait refuser l'archive entière.
- `places.jsonl` doit figurer dans l'inventaire `files` du manifeste, avec
  son empreinte SHA-256. S'il est absent de l'archive, l'archive est
  refusée.

## 3. `places.jsonl`

Encodage **UTF-8**, **un objet JSON par ligne** (JSON Lines,
`conventions.md` § 7), un objet par lieu. L'ordre des lignes est libre mais
doit être **le même d'un export à l'autre** pour une même archive : c'est
la position d'une ligne qui permet de reprendre un import interrompu.

### 3.1 Champs

| Champ | Type | Obligatoire | Règle |
|---|---|---|---|
| `id` | chaîne | oui | Identifiant **stable** du lieu sur l'ancien site, 1 à 190 octets, toujours une chaîne JSON (`"0043"`, jamais `43`). Voir § 4. |
| `name` | chaîne | oui | Nom du lieu, 1 à 150 caractères après suppression des espaces de début et de fin. |
| `address` | chaîne ou `null` | non | Rue et numéro, ou toute indication d'accès. Au plus 255 caractères. |
| `postal_code` | chaîne ou `null` | non | Code postal, **en chaîne** (`"5360"`, `"01000"`). Au plus 20 caractères. |
| `city` | chaîne ou `null` | non | Localité. Au plus 120 caractères. |
| `country` | chaîne ou `null` | non | Pays, en texte libre (`"Belgique"`). Au plus 80 caractères. |
| `latitude` | nombre, chaîne ou `null` | non | Degrés décimaux WGS 84, de −90 à 90 (`50.341205`). Voir § 3.2. |
| `longitude` | nombre, chaîne ou `null` | non | Degrés décimaux WGS 84, de −180 à 180 (`5.150118`). Voir § 3.2. |
| `website_url` | chaîne ou `null` | non | Site web du lieu, en `http://` ou `https://`. Une adresse sans schéma (`ferme.example`) reçoit `https://`. Au plus 500 caractères. |
| `is_archived` | booléen ou `null` | non | `true` si le lieu était archivé (retiré des écrans courants) sur l'ancien site, `false` s'il était actif. Absent ou `null` : actif. |

Un champ absent et un champ à `null` ont le même sens : « inconnu ». Une
chaîne vide ou faite d'espaces aussi.

Les champs inconnus sont ignorés (`conventions.md` § 2) : un exporteur peut
ajouter ce qu'il veut, rien de plus ne sera repris.

Le format ne contient **aucune date** : un lieu n'a ni date de création ni
fuseau à transmettre. Il ne contient pas non plus de donnée personnelle :
le nom et l'adresse d'un lieu désignent un terrain, pas une personne.
N'y mettez **pas** le nom d'un propriétaire ou d'un contact.

### 3.2 Coordonnées

- `latitude` et `longitude` vont **ensemble** : un point a besoin des deux.
- Elles s'écrivent de préférence en **nombres JSON** (`50.341205`). Une
  chaîne numérique est aussi acceptée (`"50.341205"`, et la virgule
  décimale `"50,341205"`).
- ScoutMagic garde six décimales (une dizaine de centimètres).
- Les coordonnées reprises sont marquées **saisies à la main** : le
  géocodage automatique de ScoutMagic ne les remplacera **jamais**, même si
  l'adresse change ensuite.
- **Aucun géocodage** n'a lieu pendant l'import. Un lieu repris **sans**
  coordonnées est ensuite placé sur la carte par le géocodage automatique
  habituel, d'après son adresse, comme un lieu encodé à la main (sauf s'il
  est archivé).

Si une seule des deux est donnée, si l'une n'est pas un nombre, ou si
l'une sort de ses bornes, **les deux sont écartées et le lieu est quand
même créé**, sans point sur la carte.

### 3.3 Valeurs non reconnues

ScoutMagic crée le lieu « avec les informations reconnues ». Une valeur
facultative qui ne convient pas est **écartée**, et le lieu est créé sans
elle :

- un texte trop long pour son champ ;
- une valeur du mauvais type (`"postal_code": 5360`, `"is_archived": "oui"`) ;
- un `website_url` qui n'est pas une adresse `http://` ou `https://` valide ;
- des coordonnées inutilisables (§ 3.2).

L'aperçu compte ces lieux (« N lieux ont des informations non reconnues »)
avant que rien ne soit écrit.

## 4. Identifiants et dédoublonnage

Chaque lieu est retenu sous le quadruplet de `conventions.md` § 5 :
(`source.installation_id`, `scoutmagic-camp-places`, `place`, `id`).

L'import décide du sort de chaque lieu dans cet ordre :

1. **La table de correspondance d'abord.** Un lieu déjà importé sous ce
   quadruplet est « déjà présent » et n'est jamais réécrit. Relancer la même
   archive, ou un export plus récent du même site, ne crée donc aucun
   doublon, à condition que `installation_id` et les `id` restent stables.
2. **Puis la recherche de doublons de Camps**, sur les lieux déjà présents
   dans ScoutMagic, **lieux archivés compris**. Elle compare le nom, la
   localité (ville ou code postal) et l'adresse, en ignorant la casse, les
   accents, la ponctuation et les espaces. Elle est **purement textuelle** :
   aucun modèle de langage n'est consulté pendant l'import.
   - **Correspondance certaine** — même nom et même localité (ou localité
     inconnue d'un côté), ou même adresse, ou un nom qui commence l'autre
     dans la même localité (« Domaine de Mozet » et « Domaine de Mozet
     asbl ») : le lieu est compté **« déjà présent »**. Le lieu existant
     n'est **pas modifié**.
   - **Correspondance possible** — le même nom dans une autre localité, un
     nom à une faute de frappe près, un nom qui commence l'autre ailleurs :
     le lieu est **ambigu**. Il n'est **ni créé ni fusionné** ; l'aperçu et
     le bilan l'affichent avec le motif « Ressemble à « *nom du lieu
     existant* », *localité* ». À vérifier dans Camps, où deux lieux se
     fusionnent à la main.
   - **Aucune correspondance** : le lieu est **créé**.

Un lieu ambigu n'est pas retenu dans la table de correspondance : une
relance le compare de nouveau, et le crée si, entre-temps, le lieu auquel il
ressemblait a été renommé ou fusionné.

Deux lignes d'une même archive qui décrivent le même terrain sous deux `id`
différents : la première est créée, la seconde est ensuite reconnue comme
« déjà présente » (ou ambiguë) par la recherche de doublons.

## 5. Après l'import

Un lieu importé est un lieu ordinaire de Camps : il se modifie, s'archive,
se restaure et se fusionne comme les autres. Son historique dit qu'il a été
repris de l'ancien site, et nomme le chef d'unité qui a lancé l'import.
L'import n'envoie aucun courriel ni aucune notification, et ne modifie
jamais un lieu déjà présent.

## 6. Limites

| | Plafond |
|---|---|
| Archive déposée | 32 Mio |
| Fichiers dans l'archive | 10 (deux suffisent) |
| `places.jsonl`, décompressé | 16 Mio |
| Contenu total décompressé | 16 Mio |
| Une ligne de `places.jsonl` | 1 Mio |

Une ligne de lieu fait quelques centaines d'octets : ces plafonds laissent
place à des dizaines de milliers de lieux.

## 7. Cas invalides et motifs au bilan

Les cas qui font refuser **l'archive entière** sont ceux de
`conventions.md` § 8 (chemin dangereux, extension refusée, manifeste
contradictoire, version majeure autre que 1, empreinte fausse,
`places.jsonl` absent…). Rien n'est alors écrit.

Les cas qui n'écartent **qu'un lieu** :

| Situation | Effet | Motif affiché |
|---|---|---|
| Ligne qui n'est pas un objet JSON | Ignoré | « Ligne illisible » |
| `id` absent, vide, numérique ou trop long | Ignoré | « Identifiant d'origine absent ou qui n'est pas une chaîne de caractères » |
| `name` absent, vide ou qui n'est pas une chaîne | Ignoré | « Sans nom » |
| `name` de plus de 150 caractères | Ignoré | « Nom de plus de 150 caractères » |
| Lieu déjà importé, ou correspondance certaine | Déjà présent | — |
| Correspondance possible | Ambigu, ni créé ni fusionné | « Ressemble à « *nom* », *localité* » |
| Valeur facultative non reconnue (§ 3.3) | Créé sans elle | — (compté dans l'aperçu) |

Le bilan montre, pour chaque lieu écarté, sa référence : l'`id`, le nom du
lieu et sa localité (« p-0043 · Ferme du Bois, Hamois »). Ce sont des noms
de terrains, jamais de personnes.

## 8. Exemple complet

`places.jsonl` (quatre lignes) :

```json
{"id":"p-0001","name":"Ferme du Bois-Haut","address":"Chemin du Bois-Haut 12","postal_code":"5360","city":"Hamois","country":"Belgique","latitude":50.341205,"longitude":5.150118,"website_url":"https://ferme-du-bois-haut.example","is_archived":false}
{"id":"p-0002","name":"Prairie Lambert","postal_code":"5590","city":"Leignon","country":"Belgique","latitude":50.268,"longitude":5.0201}
{"id":"p-0003","name":"Domaine des Trois Sources","address":"Rue des Sources 3","postal_code":"6690","city":"Vielsalm","is_archived":true}
{"id":"p-0004","name":"Le pré du Moulin","country":"Belgique"}
```

`manifest.json` :

```json
{
    "format": "scoutmagic-camp-places",
    "format_version": "1.0.0",
    "source": {
        "installation_id": "ancien-site-exemple",
        "name": "Ancien site de l'unité exemple"
    },
    "export_id": "2026-11-08-1430-endroits-de-camp",
    "exported_at": "2026-11-08T14:30:00+01:00",
    "files": [
        {
            "path": "places.jsonl",
            "sha256": "e8ee97dc94b21423d4245bfa30faa888b9c79da18bb8ac9bc449e6822c4eeb82",
            "size": 590
        }
    ]
}
```

L'empreinte et la taille sont celles du `places.jsonl` ci-dessus, chaque
ligne terminée par `\n`. Sur un site vide, cette archive crée quatre lieux :
deux avec leur point sur la carte, verrouillé contre le géocodage, un
archivé, et un sans adresse ni point.

Le dépôt de ScoutMagic contient cette archive, prête à déposer, dans
`tests/fixtures/data-migration/scoutmagic-camp-places/endroits-de-camp.zip`,
avec des archives invalides à côté (version majeure inconnue, empreinte
fausse, autre format, extension refusée, fichier absent, lignes invalides).
