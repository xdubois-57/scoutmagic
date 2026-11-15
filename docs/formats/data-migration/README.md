# Formats des archives de migration de données

Ce dossier décrit, pour qui **produit** les fichiers depuis un autre site,
ce que le module « Migration de données » (`data_migration`) de ScoutMagic
sait reprendre. Chaque document se suffit à lui-même : un exporteur n'a pas
besoin de lire le code de ScoutMagic.

Commencez par **[`conventions.md`](conventions.md)** : il fixe ce qui est
commun à toutes les archives (le ZIP, le manifeste `manifest.json`, les
versions, les empreintes SHA-256, les identifiants stables et le
dédoublonnage, les limites, les années scoutes passées). Le document de
chaque format en précise le contenu.

## Les sept imports

Un import n'est proposé que si son module est actif sur le site qui
importe, en plus du module « Migration de données » lui-même. ScoutMagic
lit toute version `1.x.y` d'un format et refuse toute autre version
majeure.

| Import (titre à l'écran) | `format` du manifeste | `format_version` décrite | Module requis | Document |
|---|---|---|---|---|
| Calendrier (fichier ICS) | — (fichier iCalendar, RFC 5545, lu à une adresse web) | — | Calendrier (`calendar`) | aucun : la source est un formulaire, pas un ZIP. Voir `specifications.md` §49.8 et l'aide « Copier le calendrier de l'ancien site » |
| Historique des courriels | `scoutmagic.email-history` | `1.0.0` | Envoi de mails (`mass_mail`) | [`email-history.md`](email-history.md) |
| Actualités et formulaires | `scoutmagic-news-history` | `1.0.0` | Actualités (`news`) | [`news-history.md`](news-history.md) |
| Photos des membres | `scoutmagic-member-photos` | `1.0.0` | aucun (fonction du cœur) | [`member-photos.md`](member-photos.md) |
| Galeries photo | `scoutmagic-gallery-links` | `1.0.0` | Photos et vidéos (`gallery`) | [`gallery-links.md`](gallery-links.md) |
| Endroits de camp | `scoutmagic-camp-places` | `1.0.0` | Camps (`camps`) | [`camp-places.md`](camp-places.md) |
| Inscriptions de l'année suivante | `scoutmagic-next-year-registrations` | `1.0.0` | Inscriptions (`registration`) | [`next-year-registrations.md`](next-year-registrations.md) |

Le nom du format historique des courriels s'écrit avec un point
(`scoutmagic.email-history`), les autres avec un tiret : c'est la valeur
exacte que `manifest.json` doit porter.

## Exemples

Chaque format a une archive exemple valide, et des cas invalides, sous
[`tests/fixtures/data-migration/`](../../../tests/fixtures/data-migration/),
dans un dossier qui ne porte pas toujours le nom du `format` ; les autres
fichiers du dossier sont les cas invalides :

| Import | Dossier | Exemple valide |
|---|---|---|
| Calendrier (fichier ICS) | `ics/` | aucun ZIP : des fichiers `.ics`, chacun pour un cas |
| Historique des courriels | `email-history/` | `exemple.zip` |
| Actualités et formulaires | `scoutmagic-news-history/` | `exemple.zip` |
| Photos des membres | `scoutmagic-member-photos/` | `exemple.zip` |
| Galeries photo | `gallery-links/` | `galeries-exemple.zip` |
| Endroits de camp | `scoutmagic-camp-places/` | `endroits-de-camp.zip` |
| Inscriptions de l'année suivante | `scoutmagic-next-year-registrations/` | `exemple-valide.zip` |

Leurs données sont inventées et ne correspondent à aucune unité réelle.

## Ce que ces formats ne couvrent pas

L'export depuis ScoutMagic, la synchronisation, les réponses aux
formulaires, les finances, les séjours de camp, les albums locaux et les
réinscriptions ne font partie d'aucun format. Aucun format n'est propre à
un site d'origine particulier : un exporteur pour un ancien site vit dans
son propre dépôt et produit ces archives.
