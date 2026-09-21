# Réorganisation des menus — justification

Ce document porte le **pourquoi** de chaque choix. La roadmap dit quoi faire ; ce document empêche
qu'on « corrige » dans six mois une incohérence apparente qui n'en est pas une.

Relevé sur le code à jour du 21 septembre 2026 (`da64c8b`). Aucun changement de permission :
chaque page garde son `role_min`.

## Les personas

Des parents qui découvrent le site ; des animés et des animateurs de 20 à 25 ans ; des chefs
d'unité qui savent utiliser un ordinateur sans être informaticiens ; un super-admin un peu plus à
l'aise, mais pas développeur. Aucun ne lit le code, aucun ne connaît son vocabulaire.

## Ce que le code impose, et qu'il faut savoir avant de lire la suite

**Les pages du cœur passent toujours avant celles des modules.** `MenuBuilder::buildPages()` trie
d'abord par rang de groupe de tri (dynamique, puis cœur, puis module), et seulement ensuite par
`menu_order`. Un module ne peut donc **jamais** se placer avant une page du cœur, quel que soit son
ordre. C'est la vraie raison pour laquelle « Inscriptions » est la dernière entrée du menu public,
derrière la page RGPD : le décalage de 1000 par position de module ne fait qu'aggraver l'écart.

**Il n'existe pas d'entrée hors groupe.** Sur grand écran, chaque groupe est une colonne titrée du
méga-menu ; une entrée sans groupe tombe dans la **dernière** colonne déclarée. D'où la colonne
« Argent » pour Finances, plutôt qu'une Finances « seule en bas » qui n'existe pas.

**Les pages de texte stockent l'identifiant de leur colonne.** `text_pages.menu_group` est validé à
l'écriture, mais un identifiant retiré plus tard fait **disparaître silencieusement** la page de
son menu (`TextPageMenuProvider::placementIsStillValid()`) — le site ne plante pas, mais personne
n'est prévenu. Renommer ou retirer une colonne impose donc de reclasser ces lignes.

## Quatre défauts relevés

**L'ordre du menu public est presque l'inverse de l'utile.** Un parent vient pour inscrire son
enfant ; « Inscriptions » est la dernière entrée, après une page légale.

**Quatre groupes sont des bacs de débordement** : « Pages », « Services », « Suivi »,
« Exploitation ». Leur nom ne permet pas de deviner leur contenu. « Exploitation » compte en plus
dix entrées, ce qui annule le groupement.

**Le cycle d'inscription est éclaté** sur cinq pages, trois menus et trois groupes, alors que c'est
une seule séquence annuelle.

**Des labels sont dupliqués entre menus** : Actualités, Calendrier, Galerie, Camps, Inscriptions,
SOS Staff d'U. Un animateur voit trois « Galerie » dans les menus qu'il fréquente.

## Menu par menu

### Notre unité

Accueil, Sections, Inscriptions, Actualités, Calendrier, Contact.

« Protection des données » quitte le menu : elle est **déjà** dans le pied de page
(`base.html.twig`). L'obligation est qu'elle soit accessible, pas qu'elle occupe un septième de la
navigation.

### Espace membres

**Mes membres** — les entrées dynamiques, puis Notifications. **L'unité** — Les animateurs,
Photos, Discussions.

« Pages » ne veut rien dire. Notifications est personnel, elle rejoint ce qui me concerne.
« Trombinoscope » est un mot d'école : la page montre les animateurs par section, autant le dire.
« Groupes » évoque les sections de l'unité ; ce sont des **discussions**.

### Espace animateurs

**Ma section** — Présences, Animés de la section, Staffs et badges.
**Activités** — Calendrier, Camps, Gérer les photos, Rétrospectives.
**Communication** — Rédiger les actualités, Envoi de mails.
**Effectifs** — Statistiques, Prévisions d'effectifs, Départs de l'unité.
**Argent** — Finances.

« Staffs » et « Membres par section » cohabitaient sans qu'on devine ce qui les sépare : l'une gère
les animateurs, leurs badges et leurs documents ; l'autre liste les animés. « Staff » est du
vocabulaire scout indigène, on le garde.

Une page de gestion porte son verbe quand une page du même nom existe dans un menu que le même
utilisateur fréquente : « Gérer les photos », « Rédiger les actualités ». **Exception :
« Calendrier »** — c'est le même calendrier avec des droits d'édition, pas une autre page.

**Statistiques rejoint Effectifs** : `member_stats` ne fait que de la répartition d'animés par
branche et par année. Statistiques dit qui compose l'unité, Prévisions qui la composera, Départs
qui la quitte.

**Argent ne contient que Finances, et c'est assumé.** Il n'existe pas d'entrée hors groupe, et
« Argent » nomme un vrai sujet — ce qui n'était pas le cas de « Gestion ». Pour un intendant, le
menu tombe à trois colonnes : Ma section, Activités, Argent.

### Espace chefs d'U

**Suivi** — Points d'attention, Journal.
**Membres et année** — Import Desk, Membres, Année scoute, Encadrement, Attestations.
**Communication** — Édition du site, Listes de diffusion, Courrier reçu.
**Effectifs** — Réinscriptions, Passages de branche, Formulaire d'inscription, Cotisations.
**Services de l'unité** — Gérer les locations, Gérer le téléphone d'urgence.

**« Suivi » garde son nom et change de contenu.** Le mot n'était pas mauvais : c'est ce qu'il
contenait qui l'était — un journal technique, de l'argent, une boîte aux lettres et le suivi des
animateurs. Réduit à deux entrées, il est juste : Points d'attention dit ce qui ne va pas
maintenant, le Journal ce qui s'est passé. Il ouvre le menu, parce que Points d'attention est la
page qu'un chef d'unité devrait ouvrir en premier.

**« Courrier » → « Courrier reçu »**, puisque « Courrier sortant » existe en Configuration.

**Communication réunit ce que l'unité publie et échange**, dans l'ordre : ce qu'on publie, à qui on
écrit, ce qui revient. « Édition du site » est le basculement du mode configuration, donc
l'édition des pages publiques.

**Deux verbes pour deux services** : « Locations » ne disait pas qu'on y gère ; « SOS Staff d'U »
est du vocabulaire interne pour un calendrier de garde et un numéro de déviation.

### Le cycle d'inscription : deux menus, un même nom

Les pages du cycle ne peuvent pas cohabiter : `public` pour le formulaire, `chief` pour
Prévisions et Départs, `admin` pour Passage, Réinscription et les réglages du formulaire. Une page
à cinq onglets montrerait à un animateur des onglets qu'il ne peut pas ouvrir.

**La solution est le nom de colonne commun, « Effectifs ».** Un animateur y voit trois entrées, un
chef d'unité quatre, et le mot partagé dit que c'est le même cycle. Dans l'ordre où il se vit :
prévisions, réinscriptions, départs, passages de branche, nouvelles inscriptions.

| Aujourd'hui | Proposé | Pourquoi |
|---|---|---|
| Prévisions | Prévisions d'effectifs | prévisions de quoi ? |
| Réinscription | Réinscriptions | les membres existants confirment qu'ils continuent |
| Départs | Départs de l'unité | pas des départs en camp |
| Passage | Passages de branche | faire monter de branche |
| Inscriptions *(réglages)* | Formulaire d'inscription | c'est ce qu'on y règle, et ça ne heurte plus la page publique du même nom |

« Cotisations » rejoint Effectifs : une cotisation suit une réinscription, c'est le même moment de
l'année et souvent la même conversation avec la famille.

### Configuration

**L'unité** — Correspondances Desk, Badges, RGPD, Synchronisation des contacts.
**Le site** — Installation & serveur, Modules, Pages de texte, Paramètres, Comptes superadmin.
**Communication** — Courrier entrant, Courrier sortant, Notifications, Modèles d'e-mails.
**Données et sauvegardes** — Stockage, Maintenance, Actions planifiées.
**État du site** — Fréquentation, Diagnostic, Supervision.
**Réglages des modules** — Calendrier, Camps, Finances, Photos, Intelligence artificielle,
Téléphone d'urgence, Outils de test.

**« Exploitation » est un mot d'ingénieur** et comptait dix entrées. Il se découpe en trois sujets
qui, eux, se nomment.

**« E-mails » → « Modèles d'e-mails ».** La page est `/config/emails/{template}` — sujet, corps,
valeur par défaut, bouton de test. Posée à côté de « Courrier sortant », l'ancien nom ressemblait à
un doublon.

**« Réglages » → « Paramètres »**, dans un menu qui s'appelle déjà « Configuration ».

**« Support » → « Diagnostic ».** La page produit le paquet de diagnostic ; « Support » laisse
croire à un formulaire de contact.

**« Synchronisation des contacts » rejoint L'unité** : elle expose les coordonnées des animateurs
aux téléphones des chefs d'unité. C'est une donnée de l'unité, pas une affaire de serveur.

**« Surveillance » a été écarté** pour « État du site » : le mot est péjoratif, et ces pages disent
seulement comment le site se porte.

**Supervision et Outils de test sont déjà invisibles là où ils n'ont rien à faire** : leur
`visible_when` réserve l'une à l'installation qui reçoit les statistiques, l'autre à
l'installation de référence et aux installations locales. Aucune action n'est nécessaire.

## « Galerie » devient « Photos »

Dans les trois menus. Le module accepte aussi des vidéos, donc **les pages elles-mêmes continuent
de parler de médias** ; et son nom dans le registre, « Galerie photos et vidéos », devient
« Photos et vidéos » pour que le super-admin lise le même mot sur la page Modules.

## La page Modules

Plus de glisser-déposer : l'ordre des modules ne décide plus de rien. L'activation reste un
**interrupteur**, comme aujourd'hui — jamais une case à cocher. À la place, **une catégorie
déclarée par chaque module** dans son `module.json`, sur le vocabulaire des menus — Communication,
Activités, Membres et effectifs, Argent, Services de l'unité, Le site, Technique — et un tri
alphabétique à l'intérieur.

La page affiche déjà la description de chaque module. Le défaut est leur **qualité** : plusieurs
sont écrites pour un développeur, et celle du trombinoscope est fausse — elle annonce un
« annuaire photo des animés » alors que la page montre les animateurs.

## Règles à écrire pour que ça ne se redéfasse pas

**Un nom de groupe doit permettre de deviner son contenu.** Si un groupe ne se nomme qu'avec un mot
vague, c'est que le regroupement n'existe pas.

**Toute entrée de menu déclare son ordre et sa colonne.** Un ordre non choisi est un ordre subi.

**Le même mot désigne la même chose à tous les niveaux.** « Effectifs » dans deux menus,
« Communication » dans trois : quelqu'un qui monte en responsabilité retrouve ses repères.

**Aucun texte d'interface ne parle d'un état antérieur du site.** Le site est en phase de test ;
il n'y a personne à qui ce passé serait familier.
