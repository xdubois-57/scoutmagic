# Format `scoutmagic-next-year-registrations` — inscriptions de l'année suivante

Ce document s'adresse à qui **produit**, depuis un autre site, l'archive
que l'import « Inscriptions de l'année suivante » de ScoutMagic lit. Il se
suffit à lui-même avec [`conventions.md`](conventions.md), qui fixe ce qui
est commun à toutes les archives : le ZIP, le manifeste, les versions, les
empreintes, les identifiants et les limites. Rien ici ne suppose une
lecture du code de ScoutMagic.

| | |
|---|---|
| Format (`format` du manifeste) | `scoutmagic-next-year-registrations` |
| Version décrite | **`1.0.0`** — ScoutMagic lit toute version `1.x.y` |
| Module requis sur le site d'arrivée | « Inscriptions » (`registration`) |
| Type d'objet (`source_kind`) | `request` |

## 1. Ce que l'archive transporte

Les **nouvelles demandes d'inscription** d'enfants qui ne sont pas encore
membres, encore **en cours de traitement** sur l'ancien site, pour l'année
scoute que chaque demande nomme. Elles deviennent dans ScoutMagic des
demandes ordinaires : mêmes listes, mêmes décisions, même acceptation
automatique, même rapprochement avec Desk, sans marque « Importé ».

Ne sont **pas** transportés : les réponses aux campagnes de réinscription
des membres déjà présents, les passages de branche, les jetons ou liens de
suivi de l'ancien site, un export de base de données ou des identifiants
SQL de l'ancien site.

L'import **n'envoie rien** : ni accusé de réception, ni alerte à l'unité,
ni e-mail de décision, ni confirmation d'adresse secondaire. Chaque demande
reçoit un lien de suivi propre à ScoutMagic, que l'import n'envoie à
personne. Une famille connectée avec son adresse retrouve sa demande par
les moyens habituels.

## 2. Structure du ZIP

```text
inscriptions-annee-suivante.zip
├── manifest.json
└── requests.jsonl
```

- Exactement ces **deux fichiers**, à la racine. Extensions admises :
  `json`, `jsonl`. Un autre fichier fait refuser l'archive entière.
- `manifest.json` : voir `conventions.md` § 2, avec
  `"format": "scoutmagic-next-year-registrations"`. Son inventaire `files`
  cite `requests.jsonl` et son empreinte SHA-256.
- `requests.jsonl` : JSON Lines en **UTF-8**, **une demande par ligne**
  (`conventions.md` § 7).

## 3. Une ligne de `requests.jsonl`

Un objet JSON. Tout champ inconnu est ignoré. Sauf mention contraire, une
valeur est une **chaîne** ; une valeur d'un autre type (un nombre pour un
code postal, par exemple) fait ignorer la demande avec le motif « Valeur
non reconnue : … ». Les espaces au début et à la fin d'une chaîne sont
retirés ; une chaîne vide vaut un champ absent.

### 3.1 Champs de l'import (obligatoires)

| Champ | Type | Règle |
|---|---|---|
| `id` | chaîne | Identifiant **stable** de la demande sur l'ancien site, 1 à 190 octets (`conventions.md` § 5). C'est la clé de dédoublonnage. |
| `scout_year` | chaîne `AAAA-AAAA` | L'année scoute **demandée**, par exemple `2027-2028`. Elle doit **déjà exister** dans ScoutMagic : elle n'est jamais déduite d'une date, et cet import ne crée jamais d'année. |
| `received_at` | moment ISO 8601 avec fuseau | La date de réception **historique** de la demande : `2026-10-12T19:04:00+02:00` ou `2026-10-12T17:04:00Z`. Elle fixe l'ordre « premier arrivé » et la durée de validité du lien de suivi, comme pour une demande déposée sur ScoutMagic. |
| `status` | chaîne | L'état de la demande, **exactement** l'une des cinq valeurs du § 3.4. |

### 3.2 La demande elle-même

Les champs obligatoires sont **exactement ceux qu'exige le formulaire
public** de ScoutMagic, avec les mêmes contrôles. La seule différence :
l'acceptation de la politique de confidentialité (celle de l'ancien site
n'est pas vérifiable ici) et la question sur une ancienne unité, que
l'ancien site n'a peut-être jamais posée, ne sont pas exigées.

| Champ | Obligatoire | Règle | Nom dans le bilan |
|---|---|---|---|
| `child_first_name` | oui | Prénom de l'enfant, ≤ 100 caractères | prénom de l'enfant |
| `child_last_name` | oui | Nom de l'enfant, ≤ 100 caractères | nom de l'enfant |
| `child_birth_date` | oui | Date seule `AAAA-MM-JJ`, une vraie date | date de naissance |
| `child_gender` | oui | `F`, `H` (garçon) ou `X` — les valeurs de Desk | genre |
| `parent_name` | oui | Nom du parent ou responsable, ≤ 150 caractères | nom du responsable |
| `street` | oui | Rue, ≤ 200 caractères | rue |
| `street_number` | oui | Numéro (et boîte), ≤ 20 caractères | numéro |
| `postal_code` | oui | Code postal **en chaîne** (`"5000"`), ≤ 20 caractères | code postal |
| `city` | oui | Localité, ≤ 100 caractères | localité |
| `email` | oui | Adresse électronique principale, valide, ≤ 254 caractères | adresse électronique |
| `phone` | oui | Téléphone principal, ≤ 30 caractères | téléphone |
| `other_phone` | non | Autre téléphone, ≤ 30 caractères | autre téléphone |
| `remarks` | non | Remarques de la famille, ≤ 2000 caractères | remarques |
| `previous_unit_answer` | non | `yes` ou `no` : l'enfant fait ou a fait partie d'une autre unité Les Scouts. Absent : la question n'a pas été posée. | ancienne unité |
| `previous_unit_name` | si `yes` | Le nom de cette unité, ≤ 150 caractères. Ignoré sans `yes`. | nom de l'ancienne unité |

### 3.3 Suivi, affectation et liens (facultatifs)

| Champ | Type | Règle |
|---|---|---|
| `decided_at` | moment ISO 8601 avec fuseau | La date de la décision sur l'ancien site (acceptation, refus, retrait, encodage). Affichée sur la fiche (« Décision du … ») et point de départ de la durée de conservation d'une demande close. Absente : la date de l'import. |
| `refusal_reason` | chaîne | Pour `refused` seulement : `no_room` (« Pas de place ») ou `other` (« Autre »). Absent : refus sans motif connu. Toute autre valeur fait ignorer la demande. |
| `desired_section_desk_code` | chaîne | La section **souhaitée** par la famille, par son code Desk (`sections.desk_code`). |
| `intended_section_desk_code` | chaîne | La section **prévue** par l'unité, par son code Desk. |
| `fee_category_desk_code` | chaîne | La catégorie de cotisation, par son code Desk. |
| `internal_notes` | chaîne | Notes internes de l'unité, ≤ 10 000 caractères. Jamais montrées aux familles. |
| `sibling_desk_ids` | liste de chaînes | Les frères et sœurs **déjà membres**, par leur identifiant Desk (numéro de Tiers), en chaînes (`"0001234"`). |
| `member_desk_id` | chaîne | Pour `encoded` seulement, et alors **obligatoire** : l'identifiant Desk du membre que la demande est devenue. |
| `secondary_emails` | liste d'objets | Adresses secondaires : `{"email": "…", "status": "valid"}` (confirmée sur l'ancien site) ou `"pending"` (pas encore confirmée). |

Une section, une catégorie de cotisation ou un frère/une sœur **introuvable**
dans ScoutMagic : **le lien est ignoré, la demande est reprise** (l'aperçu
compte ces liens perdus). Une adresse secondaire invalide, dans un autre
état, ou identique à l'adresse principale n'est pas reprise. Une adresse
`pending` reste à confirmer : aucune confirmation n'est envoyée par
l'import ; la famille peut la redemander depuis sa page de suivi.

### 3.4 Statuts admis

| `status` | Sens | Ce que voit la famille |
|---|---|---|
| `pending` | En attente | « en attente » |
| `accepted` | Acceptée | la décision, tout de suite |
| `refused` | Refusée (voir `refusal_reason`) | la décision, tout de suite |
| `withdrawn` | Retirée par la famille | « retirée » |
| `encoded` | Acceptée et déjà encodée dans Desk (voir `member_desk_id`) | l'acceptation, tout de suite |

Toute autre valeur est ignorée avec le motif « Statut non reconnu » : elle
n'est **jamais convertie** en un statut voisin. Si l'ancien site connaît
un état qui n'entre dans aucun des cinq, l'exporteur choisit l'un d'eux en
connaissance de cause ou omet la demande.

Une décision `accepted`, `refused` ou `encoded` a été annoncée à la famille
par l'ancien site : ScoutMagic l'inscrit dans l'historique de la demande
comme « **Communiquée par l'ancien site** ». La famille la voit tout de
suite sur sa page de suivi ; aucun e-mail ne part et aucun envoi n'est
inventé. Si l'unité change ensuite la décision, le nouvel e-mail suit le
chemin habituel.

Une demande `encoded` n'est reprise que si `member_desk_id` désigne un
membre **présent** dans ScoutMagic (alias de fusion Desk compris) et que
ce membre n'est pas déjà lié à une autre demande. Sinon elle est ignorée :
la représenter sans membre serait la déformer. `member_desk_id` sur un
autre statut est ignoré : le rapprochement avec Desk le refera.

## 4. Dates et fuseaux

- Dates seules (`child_birth_date`) : `AAAA-MM-JJ`.
- Moments (`received_at`, `decided_at`) : ISO 8601 **avec fuseau**
  (`+01:00`, `+02:00` ou `Z`), à la minute ou à la seconde. ScoutMagic les
  convertit dans le fuseau du site. Un moment sans fuseau est refusé.

## 5. Dédoublonnage

- **Même demande réimportée** : la clé est (`source.installation_id`,
  `scoutmagic-next-year-registrations`, `request`, `id`). Une demande déjà
  importée sous cette clé est comptée « déjà présente » et **jamais
  modifiée** — ni son statut, ni ses notes. Gardez donc le même
  `installation_id` et le même `id` d'un export à l'autre.
- **Ressemblance avec une demande déjà présente** (même enfant — nom,
  prénom, date de naissance — pour la même année, demande en attente,
  acceptée ou encodée) : la demande est **importée quand même** et la
  liste des demandes la signale « doublon possible », exactement comme une
  famille qui aurait déposé deux fois. Rien n'est fusionné ni supprimé.

## 6. Limites

| Limite | Valeur |
|---|---|
| Fichiers dans l'archive | 2 (`manifest.json`, `requests.jsonl`) |
| `requests.jsonl` décompressé | 64 Mio |
| Archive déposée | 65 Mio |
| Une ligne | 1 Mio |

## 7. Ce qui est ignoré, et le motif au bilan

Le bilan ne montre **jamais un nom** : la valeur de `id` et le motif.

| Situation | Motif au bilan |
|---|---|
| Ligne qui n'est pas un objet JSON | Ligne illisible |
| `id` absent, vide, numérique | Identifiant d'origine absent ou qui n'est pas une chaîne de caractères |
| `scout_year` absent | Champ obligatoire manquant : année scoute |
| `scout_year` qui n'est pas `AAAA-AAAA` | Année scoute illisible |
| Année absente de ScoutMagic | Année scoute absente de ce site |
| `received_at` absent / illisible | Champ obligatoire manquant : date de réception / Date de réception illisible |
| `status` absent / inconnu | Champ obligatoire manquant : statut / Statut non reconnu |
| `decided_at` illisible | Date de décision illisible |
| `refusal_reason` inconnu | Motif de refus non reconnu |
| Champ obligatoire du § 3.2 absent ou vide | Champ obligatoire manquant : *nom dans le bilan* |
| Valeur non admise (genre, date, adresse, ancienne unité) ou d'un autre type que chaîne | Valeur non reconnue : *nom dans le bilan* |
| Valeur trop longue | Champ trop long : *nom dans le bilan* |
| `yes` sans `previous_unit_name` | Champ obligatoire manquant : nom de l'ancienne unité |
| `encoded` sans membre Desk retrouvé | Demande encodée sans membre Desk reconnu |
| `encoded` vers un membre déjà lié | Membre Desk déjà lié à une autre demande |
| `internal_notes` trop longues | Champ trop long : notes internes |

Une archive malformée (manifeste, empreinte, version majeure, fichier en
trop) est refusée en entier avant toute écriture (`conventions.md` § 8).

## 8. Exemple complet

Des archives d'exemple, valide et invalides, se trouvent dans le dépôt de
ScoutMagic sous `tests/fixtures/data-migration/scoutmagic-next-year-registrations/`.
Les données sont inventées.

`manifest.json` :

```json
{
    "format": "scoutmagic-next-year-registrations",
    "format_version": "1.0.0",
    "source": {
        "installation_id": "ancien-site-exemple",
        "name": "Ancien site d'exemple"
    },
    "export_id": "export-2026-11-08",
    "exported_at": "2026-11-08T14:30:00+01:00",
    "files": [
        {
            "path": "requests.jsonl",
            "sha256": "b5e499e1b6a61764f8a837663dc10580122136fd98f4aa94e638895a83a5a1a5",
            "size": 2640
        }
    ]
}
```

`requests.jsonl` (une ligne par demande ; les lignes sont longues) :

```text
{"id":"r-2027-0001","scout_year":"2027-2028","received_at":"2026-10-12T19:04:00+02:00","status":"pending","child_first_name":"Camille","child_last_name":"Exemple","child_birth_date":"2019-05-06","child_gender":"F","parent_name":"Alex Exemple","street":"Rue de l'Essai","street_number":"12","postal_code":"5000","city":"Namur","email":"alex.exemple@example.org","phone":"+32 470 00 00 01","other_phone":"+32 81 00 00 01","remarks":"Souhaite être avec son cousin.","previous_unit_answer":"yes","previous_unit_name":"12e Unité d'essai","desired_section_desk_code":"LOU-A","sibling_desk_ids":["0001234"],"secondary_emails":[{"email":"parent2.exemple@example.org","status":"valid"},{"email":"grand-parent.exemple@example.org","status":"pending"}]}
{"id":"r-2027-0002","scout_year":"2027-2028","received_at":"2026-10-12T19:04:00+02:00","status":"accepted","child_first_name":"Noé","child_last_name":"Exemple","child_birth_date":"2018-02-14","child_gender":"H","parent_name":"Alex Exemple","street":"Rue de l'Essai","street_number":"12","postal_code":"5000","city":"Namur","email":"alex.exemple@example.org","phone":"+32 470 00 00 01","decided_at":"2026-11-03T10:00:00+01:00","previous_unit_answer":"no","intended_section_desk_code":"LOU-A","fee_category_desk_code":"COT-1","internal_notes":"Dossier complet."}
{"id":"r-2027-0003","scout_year":"2027-2028","received_at":"2026-10-12T19:04:00+02:00","status":"refused","child_first_name":"Lou","child_last_name":"Exemple","child_birth_date":"2016-09-30","child_gender":"X","parent_name":"Alex Exemple","street":"Rue de l'Essai","street_number":"12","postal_code":"5000","city":"Namur","email":"famille.lou@example.org","phone":"+32 470 00 00 01","refusal_reason":"no_room","decided_at":"2026-11-04T09:30:00+01:00"}
{"id":"r-2027-0004","scout_year":"2027-2028","received_at":"2026-10-12T19:04:00+02:00","status":"withdrawn","child_first_name":"Sam","child_last_name":"Exemple","child_birth_date":"2017-01-20","child_gender":"F","parent_name":"Alex Exemple","street":"Rue de l'Essai","street_number":"12","postal_code":"5000","city":"Namur","email":"famille.sam@example.org","phone":"+32 470 00 00 01","decided_at":"2026-10-20T18:00:00+02:00"}
{"id":"r-2027-0005","scout_year":"2027-2028","received_at":"2026-10-12T19:04:00+02:00","status":"encoded","child_first_name":"Eden","child_last_name":"Exemple","child_birth_date":"2018-07-01","child_gender":"F","parent_name":"Alex Exemple","street":"Rue de l'Essai","street_number":"12","postal_code":"5000","city":"Namur","email":"famille.eden@example.org","phone":"+32 470 00 00 01","member_desk_id":"0005678","decided_at":"2026-10-28T12:00:00+01:00"}
```

Sur un site qui a l'année `2027-2028`, la section `LOU-A`, la catégorie
`COT-1` et les membres Desk `0001234` et `0005678`, l'aperçu annonce :
« Année visée par le fichier : 2027-2028. 1 en attente, 1 acceptée,
1 refusée, 1 retirée, 1 encodée dans Desk. » et « Les 3 décisions déjà
prises sont marquées « Communiquée par l'ancien site ». »
