# Chantier — Pages de texte

Roadmap d'exécution en **2 itérations séquentielles**, issue #368. Traite-les dans l'ordre.
N'entame pas la seconde avant que la première soit fusionnée sur `main`.

---

## Conventions de travail

**Avant d'écrire la moindre ligne**, lis intégralement `README.md`, `ARCHITECTURE.md`,
`SECURITY.md`, `AGENTS.md`, `CONTRIBUTING.md`, `specifications.md`, `design.md` et
`docs/module-development.md`. Ils priment sur ce fichier sur toute règle générale ; si tu
découvres qu'ils décrivent une réalité que le code contredit, **mets-les à jour dans la même PR**.

- Une itération = une branche, une PR. Rebase sur `main` avant de merger.
- **Merge et push sur `main` dès que la CI complète est verte** (auto-merge armé :
  `gh pr merge <n> --squash --auto`). Un test rouge arrête le chantier : tu corriges, tu ne
  contournes pas, tu ne désactives rien, tu n'ajoutes rien à une baseline.
- Tests obligatoires : PHPUnit, PHPStan, et `npm run typecheck` + Vitest dès que tu touches
  `public/assets/js/`. Couverture RBAC explicite sur chaque frontière de rôle touchée.
- Code, commentaires, identifiants et noms de colonnes en anglais ; interface en français.
- L'écran de configuration d'IT-02 est une page qu'un utilisateur voit : **il ship son sujet
  d'aide dans la même PR** (`docs/help/`, charte `design.md` §7.11). `tests/Core/Help/` échoue
  sinon.
- Tu avances seul. Tu ne poses une question que sur une **ambiguïté fonctionnelle réelle** ou un
  **changement de conception** non tranché ici.
- Un problème réel que tu décides de ne pas corriger devient une issue GitHub, jamais un
  commentaire de PR (`AGENTS.md`). Jamais une issue publique pour une faille de sécurité.
- **L'issue #368 reste unique.** La PR d'IT-01 la référence sans la fermer (« Refs #368 ») ;
  **la PR d'IT-02 porte « Closes #368 »**, et c'est sa fusion qui clôt le chantier. Vérifie avant
  de la fusionner que tout ce que tu aurais reporté a sa propre issue ouverte.

---

## Le besoin, et ce qu'il n'est pas

Un superadmin doit pouvoir **ajouter des pages de texte libre** — la description de l'ASBL, la
Bulle Safe — dans la section du menu de son choix, leur donner un nom et un titre, les activer ou
les masquer, et **rédiger leur contenu avec le mécanisme d'édition existant du site**, celui qui
s'active en mode configuration.

Ce n'est **pas** : un système de gabarits, un éditeur de pages par glisser-déposer, des pages avec
mise en page, des pages à contenu variable selon le lecteur, ni un CMS. Une page = un titre et un
texte riche. Si tu te surprends à concevoir plus, tu as quitté le périmètre.

---

## Ce qui existe déjà et qu'il ne faut pas réécrire

Quatre mécanismes couvrent l'essentiel du besoin. Les réutiliser tels quels est une contrainte,
pas une suggestion.

- **`Core\View\EditableContentService` + `editable()` + `Core\Security\HtmlSanitizer`** — le
  stockage d'un texte riche assaini, l'édition en place en mode configuration, la revérification
  du rôle à chaque enregistrement. Rien à ajouter.
- **`partials/list_editor.html.twig` + `public/assets/js/list-editor.js`** — la liste ordonnable
  avec sa rangée d'icônes (crayon, interrupteur `bi-toggle-on`/`bi-toggle-off`, corbeille), le
  glisser-déposer persisté en arrière-plan, l'ajout et la suppression. **Ne le forke pas.**
- **`Core\Module\MenuEntryProvider` + `Core\View\DynamicMenuRegistrar`** — les entrées de menu
  dynamiques, avec l'ordonnancement et le rafraîchissement du surlignage de page active déjà
  résolus.
- **`Core\View\MenuBuilder`** — les cinq menus, leur plancher de rôle, et leurs colonnes nommées
  (`MENU_GROUPS`).

---

## Les décisions verrouillées

### Au cœur, pas dans un module — **Décidé**

La fonctionnalité touche le routeur, les menus et `editable_contents` : les trois sont au cœur.
Un module devrait tendre la main aux trois. La table va dans `schema/core.sql`, qui auto-migre à
chaque requête — donc aucune version de module à bumper, et aucun piège de ce côté.

### Une route par page, chacune avec son propre `role_min` — **Décidé, et c'est le point dur**

`SECURITY.md` §3 promet qu'une route sans `role_min` est rejetée au chargement et que le garde
RBAC s'exécute **avant** le contrôleur ; `ARCHITECTURE.md` §2 interdit qu'un contrôle dans le
contrôleur soit la protection principale.

Déclarer une route unique `/pages/{slug}` en `public` et vérifier ensuite le rôle dans le
contrôleur **viole les deux**. Donc : au démarrage, les pages actives sont lues et **chacune
enregistre sa propre route** via `Router::addRoute()`, avec le `role_min` de sa section. Le garde
reste primaire, et le contrôleur ne vérifie rien.

Deux pièges à traiter explicitement :

- **La lecture au démarrage doit survivre à l'absence de base.** Pendant l'installation, avant que
  `secrets.enc` existe, il n'y a pas de connexion : la lecture échoue proprement et la boucle
  n'enregistre aucune route, plutôt que de faire tomber le front controller.
- **Une seule requête**, pas une par menu ni une par page.

### Le niveau d'accès dérive de la section — **Décidé**

Aucun champ de rôle. Les cinq menus portent déjà leur plancher (`MenuBuilder::MENUS`) : Notre unité
`public`, Espace membres `identified`, Espace animateurs `intendant`, Espace chefs d'U `admin`,
Configuration `superadmin`. Choisir la section, c'est choisir qui lit. Un champ séparé serait une
deuxième vérité, et celle qui se désynchronise.

### Une page masquée n'a pas de route — **Décidé**

Elle renvoie **404, pas 403**. Une page désactivée n'existe pas, elle n'est pas interdite : rien
ne doit confirmer à un curieux qu'il y a quelque chose derrière. C'est aussi ce qui tombe tout
seul du point précédent — on n'enregistre que les pages actives.

### Deux champs de nom — **Décidé**

**Nom dans le menu** (court) et **titre de la page** (explicite), comme ailleurs sur le site
(« Réglages » au menu, « Réglages du bien » en tête de page). Les deux sont obligatoires.

### La colonne du méga-menu — **Décidé**

Quatre menus sur cinq sont découpés en colonnes nommées (`MenuBuilder::MENU_GROUPS`) ; seul
« Notre unité » n'en a pas. Le formulaire propose donc une seconde liste déroulante **qui
n'apparaît que si la section choisie a des colonnes**, préremplie sur la plus évidente — « Pages »
pour Espace membres, « Contenu du site » pour Espace chefs d'U.

Piège : `MenuBuilder::addPage()` **lève une exception** si le groupe n'est pas déclaré pour ce
menu. La combinaison section + colonne se valide donc côté serveur à l'enregistrement, pas
seulement dans le navigateur — sinon une valeur bricolée fait tomber le menu de tout le site.

### L'adresse — **Décidé**

`/pages/{slug}`, le slug dérivé du titre à la création puis **figé**. Le préfixe supprime tout
risque de collision avec les routes existantes et avec celles qu'une version future ajoutera ; le
gel protège les liens déjà partagés le jour où on corrige une faute de frappe dans le titre.
Unicité garantie en base ; en cas de doublon, un suffixe numérique.

### Le contenu — **Décidé**

Il ne se rédige **pas** dans l'écran de configuration. La page rendue appelle `editable()`, et le
superadmin écrit dessus en mode configuration, exactement comme sur l'accueil ou la page de
contact.

**La clé de contenu est l'identifiant de la page, jamais le slug** (`page_content_{id}`) : un
renommage ne doit jamais orpheliner le texte.

Une page créée et encore vide affiche le **texte par défaut** d'`editable()` — « Cette page n'a pas
encore de contenu » — plutôt qu'un écran blanc.

### « Créer et ouvrir » — **Décidé**

Le bouton de création enregistre la page, **bascule la session en mode configuration** et
redirige vers la page, prête à écrire. On vient de créer une page vide : la seule chose sensée
ensuite est d'aller la remplir.

Le mode reste actif pour la session et se coupe à la main, comme partout ailleurs — pas
d'exception pour cette page. Et il n'accorde aucun droit : le rôle est revérifié côté serveur à
chaque enregistrement (`ARCHITECTURE.md` §8.2).

### Suppression — **Décidé**

La suppression retire la page **et sa ligne de contenu**, après confirmation. Pas de « archivé
mais conservé » : une page supprimée dont le texte reste en base est une donnée que plus personne
ne sait retrouver ni effacer.

---

## IT-01 — Le modèle, la route, la page rendue

### À faire

- La table dans `schema/core.sql` : identifiant, slug (unique), nom de menu, titre, section de
  menu, colonne de menu (nullable), ordre, actif, horodatages.
- `Core\Page\…` — le service et le repository, seule couche à toucher PDO.
- La génération du slug à la création, figée ensuite, unique.
- L'enregistrement des routes au démarrage depuis les pages actives, chacune avec le `role_min` de
  sa section, avec la garde « pas de base, pas de route » décrite plus haut.
- Le contrôleur et le gabarit de la page rendue : fil d'Ariane, titre, `editable()`. **Aucun
  contrôle de rôle dans le contrôleur.**
- Les entrées de menu via `MenuEntryProvider`, dans leur section et leur colonne, à leur ordre.
- Les tests : le slug figé, l'unicité, la dérivation du `role_min` depuis la section, une page
  masquée qui renvoie 404, et la frontière RBAC de chaque section (autorisé au plancher, refusé un
  cran en dessous).

### Hors périmètre, explicitement

Les pages publiques ne rejoignent **pas** `Core\Offline\OfflineWhitelist` : cette liste est une
déclaration statique côté serveur, et l'y raccorder dynamiquement est un sujet à part entière. Une
page de texte n'est pas consultable hors ligne, et personne ne l'a demandé.

---

## IT-02 — L'écran de configuration, l'aide, la clôture

### À faire

- L'écran dans le menu **Configuration, colonne « Site »**, donc `role_min: superadmin` par le
  garde du routeur — **aucun contrôle de rôle ajouté dans le contrôleur**.
- La liste via `list_editor` : glisser-déposer pour l'ordre, et par ligne le nom de menu, la
  section, la colonne le cas échéant, l'adresse, puis la rangée d'icônes crayon / interrupteur /
  corbeille. **Aucune case à cocher supplémentaire** : l'activation, c'est l'interrupteur.
- Le formulaire d'ajout et de modification : nom de menu, titre, section, colonne conditionnelle.
  Pas de champ d'activation — elle vit sur la ligne de la liste. Le bouton de création est
  « Créer et ouvrir ».
- La journalisation via `JournalService` : création, suppression, activation, désactivation.
  Identifiants seulement.
- Le sujet d'aide de l'écran, et le lien d'aide des pages créées vers ce même sujet — une page
  écrite par l'unité ne peut pas avoir d'aide livrée qui lui soit propre.
- Les tests : la validation serveur de la combinaison section + colonne, la frontière `superadmin`,
  la suppression qui emporte le contenu, et le basculement en mode configuration à la création.

### La documentation à reprendre

`specifications.md` (une section pour la fonctionnalité, dans les pages hors menus ou en
Configuration), `ARCHITECTURE.md` (§3 pour les menus, §8 pour le service, et la note sur les routes
enregistrées au démarrage — c'est le seul endroit du code où une route naît d'une ligne de base de
données, ça se documente), et `docs/help/`.

---

## Écarté, explicitement

Pour que ça ne revienne pas par accident :

- **Les gabarits de page** — évoqués dans l'issue, écartés par le demandeur : « oublie les
  gabarits, gardons les choses simples ».
- **Un champ de niveau d'accès distinct de la section du menu.**
- **Un slug modifiable après création.**
- **Une page à la racine du site** (`/asbl` plutôt que `/pages/asbl`) : risque de collision avec
  une route qu'une version future ajouterait, et c'est la version future qui perdrait.
- **La mise en cache hors ligne des pages publiques.**
- **Un avertissement expliquant qu'une page masquée renvoie 404, et un encart expliquant la
  structure de l'adresse** : deux explications de mécanique interne, sans intérêt pour la personne
  qui remplit le formulaire.
