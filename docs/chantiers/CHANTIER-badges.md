# Chantier — Badges : déménagement, porteurs par année

Roadmap d'exécution en **3 itérations séquentielles**. IT-01 déménage, IT-02 et IT-03 ajoutent les
deux pages neuves. Traite-les dans l'ordre.

Répond à l'issue #367 (« badges des années précédentes ») et à la réorganisation demandée avec elle.

---

## La règle qui gouverne le chantier

**Ce chantier déménage et ajoute. Il ne retire rien.**

La page de configuration des badges arrive **telle quelle** : mêmes champs, mêmes interrupteurs,
mêmes règles de suppression, mêmes textes. Seules l'adresse, le menu et la disposition sur petit
écran changent. Si tu te surprends à simplifier un bloc « au passage », arrête-toi.

---

## Conventions de travail

**Avant d'écrire la moindre ligne**, lis intégralement `README.md`, `ARCHITECTURE.md`,
`SECURITY.md`, `AGENTS.md`, `CONTRIBUTING.md`, `specifications.md`, `design.md` et
`docs/module-development.md`. Ils priment sur ce fichier sur toute règle générale ; si tu
découvres qu'ils décrivent une réalité que le code contredit, **mets-les à jour dans la même PR**.

- Une itération = une branche, une PR. Rebase sur `main` avant de merger.
- **Merge et push sur `main` dès que la CI complète est verte** (auto-merge armé :
  `gh pr merge <n> --squash --auto`). Un test rouge arrête le chantier : tu corriges, tu ne
  contournes pas, tu ne désactives rien.
- Tests obligatoires : PHPUnit, PHPStan, et `npm run typecheck` + Vitest dès que tu touches
  `public/assets/js/`. **Couverture RBAC explicite sur les trois sous-pages.**
- Code, commentaires, identifiants et noms de colonnes en anglais ; interface en français.
- Chaque sous-page ship son sujet d'aide dans la même PR ; `tests/Core/Help/` échoue sinon.
- Aucune donnée personnelle dans le journal ni dans un message d'erreur.
- Tu avances seul. Tu ne poses une question que sur une **ambiguïté fonctionnelle réelle** ou un
  **changement de conception** non tranché ici.

---

## Ce que le modèle permet déjà

- **Une attribution est liée à un `member_year_id`** (`member_badges`) : « cette année » et
  « l'année précédente » sont deux requêtes naturelles, sans rien ajouter au schéma.
- **Trois sortes de badges cohabitent** : les badges par défaut (Infirmier, Trésorier), jamais
  supprimables ; les badges **« Référent {section} »**, générés automatiquement, un par section
  visible hors Staff d'U, maintenus par `BadgeService::syncSectionReferentBadges()` et assignables
  aux seuls membres du Staff d'U ; et les badges libres.
- **Un badge désactivé conserve ses attributions** : il devient invisible et non assignable, mais
  `member_badges` n'est pas purgé.
- **L'attribution ne se fait pas sur la page Badges** mais sur `/chefs/staffs`
  (`StaffsController::toggleBadge`, rôle `chief`), par un interrupteur à côté de chaque membre.

---

## La structure cible — **Décidé**

Trois sous-pages dans l'**Espace chefs d'U**, avec le **rail de sous-pages partagé** :
`partials/page_picker.html.twig`, qui alimente `partials/nav_rail.html.twig`, exactement comme
`modules/finance/views/_nav.html.twig`. **Ne recopie pas ce balisage à la main** : le docblock du
composant raconte que six endroits le dupliquaient avant qu'il existe.

1. **Les porteurs de l'année en cours** — page d'accueil de la section.
2. **Les porteurs de l'année précédente.**
3. **La configuration des badges** — la page existante, déplacée telle quelle.

**Les onglets portent les années** (« 2026-2027 », « 2025-2026 »), pas « Année en cours » et
« Année précédente » : ça se lit sans réfléchir, et ça ne devient pas faux pendant la transition
d'année.

### Le rôle — **Décidé**

`/config/badges` et ses quatre routes d'écriture sont aujourd'hui à `superadmin`. **Tout passe à
`admin`** : le chef d'unité gère désormais ses badges. Les `POST` comptent autant que les `GET`.

Conséquences à traiter dans la même itération :

- **`ARCHITECTURE.md` §8.11** décrit les badges comme un sujet de Configuration à `superadmin` :
  à reprendre.
- **L'entrée du menu Configuration disparaît.**
- **Les anciennes adresses `/config/badges` redirigent** vers les nouvelles, sans quoi les favoris
  et les liens d'aide existants cassent.

---

## IT-01 — Le déménagement

- Les trois routes dans l'Espace chefs d'U à `role_min: admin`, le rail partagé sur chacune.
- **La page de configuration arrive telle quelle** : renommage en place, interrupteur `role="switch"`
  — **un interrupteur, pas une case à cocher** —, suppression refusée pour un badge par défaut,
  automatique ou déjà attribué (avec son `title` expliquant pourquoi), fond grisé du champ
  non renommable, et la ligne d'ajout.
- **Une seule amélioration de disposition**, et c'est tout : sous le point de rupture, le nom du
  badge prend toute la largeur, et l'interrupteur, son libellé « Actif » et la corbeille passent sur
  la ligne du dessous, la corbeille poussée à droite. C'est **réactif**, pas « mobile » : sur large
  écran la ligne reste comme aujourd'hui. Le gabarit utilise déjà `d-flex flex-wrap` avec
  `gap-2 gap-md-3` — le changement se joue dans ces classes, pas dans une réécriture.
- Les redirections, la reprise documentaire, le sujet d'aide.

---

## IT-02 — Les porteurs de l'année en cours

### Ce que la page montre — **Décidé**

Groupée **par badge**, et **seuls les badges ayant au moins un porteur** apparaissent. Chaque bloc
porte le nom du badge, son nombre de porteurs, et la liste des personnes.

Chaque personne affiche son nom, **sa section et sa fonction de cette année-là** — un nom seul ne
dit pas à qui on a affaire.

- **Les badges automatiques sont marqués** « Automatique » : ils se créent et se renomment seuls
  avec leur section, et ne s'attribuent qu'à des membres du Staff d'U.
- **Un badge désactivé qui a encore des porteurs reste visible**, marqué « Désactivé ». Sans ça, son
  porteur disparaîtrait de tous les écrans sans que personne sache qu'il reste à retirer.
- **L'ordre est stable** : badges par défaut, puis automatiques par section, puis libres par ordre
  alphabétique. Un tri par nombre de porteurs ferait danser la page d'une semaine à l'autre.

### Lecture seule — **Décidé**

La page **n'attribue rien**. L'attribution reste sur `/chefs/staffs`, où chaque membre porte ses
interrupteurs ; un pied de page y renvoie. Deux endroits pour basculer la même chose finiraient par
diverger.

### Le lien vers la personne — **Décidé**

Chaque nom mène à **`/admin/members/{id}`**, la fiche de l'Espace chefs d'U qu'on atteint par la
recherche de membres. Même plancher de rôle que cette page, donc aucun lien ne mène à un refus.

**Le lien porte `members.id`, l'identité persistante — jamais le `member_year_id`.** La liste
travaille sur des années ; la fiche est celle de la personne. Il faut remonter, sinon le lien ouvre
la mauvaise fiche ou aucune.

Le fil d'Ariane de la fiche continue de désigner « Membres » comme ancêtre, et c'est voulu : la
fiche appartient à Membres, et lui faire changer de parenté selon d'où l'on vient se paierait en
confusion.

### Le piège de performance

`BadgeService::getBadgesForMemberYears()` prend déjà **un tableau d'identifiants**. C'est elle qu'il
faut, pas une boucle appelant `getBadgesForMemberYear()` par personne.

---

## IT-03 — Les porteurs de l'année précédente

La même page, sur l'année scoute précédente, **sans aucune action**.

- **Le nom affiché est celui de l'année lue**, pas de l'année en cours : une personne peut avoir
  changé de section, de fonction, voire de totem entre les deux.
- **Quelqu'un qui a quitté l'unité depuis garde un lien cliquable** : son `members.id` existe
  toujours, la fiche s'ouvre et montre qu'il n'est plus affilié. C'est le comportement correct.
- **L'onglet reste toujours présent**, même sur une unité à sa première année : la page affiche
  alors un message expliquant qu'il n'y a rien. Ça vaut aussi pour une unité plus ancienne qui
  n'avait attribué aucun badge — cas bien plus fréquent que la toute première année.

---

## Écarté, explicitement

- **Attribuer ou retirer un badge** depuis l'une des deux pages de porteurs.
- **Masquer ou griser l'onglet de l'année précédente** quand elle est vide.
- **Masquer un badge désactivé** qui a encore des porteurs.
- **Trier les badges par nombre de porteurs.**
- **Simplifier ou redessiner la page de configuration** au passage de son déménagement.
- **Changer le fil d'Ariane de la fiche membre** selon la page d'origine.
