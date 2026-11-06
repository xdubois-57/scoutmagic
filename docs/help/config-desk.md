---
id: config-desk
title: Correspondances Desk — fonctions, sections et branches
summary: Donner leurs rôles aux fonctions Desk, et régler nom, e-mail, couleur et visibilité des sections.
category: Configuration
role_min: superadmin
discovery: 1
question: Comment dire quel rôle donne une fonction de Desk ?
question: Comment renommer une section ou changer sa couleur ?
question: Comment masquer une section aux visiteurs ?
paths: /config/functions
related: import-desk, staffs, config-desk-valeurs-inconnues
---

La page « Correspondances Desk » relie ce que la fédération connaît —
fonctions, sections, branches — à ce que le site en fait. Tout s'y
enregistre immédiatement, sans bouton « Enregistrer ».

## Donner leurs rôles aux fonctions

Chaque fonction importée de Desk reçoit un rôle sur le site : Public,
Animé, Intendant, Chef ou Chef d'Unité. À leur première apparition,
même dans un import ultérieur, les fonctions standard sont confirmées :

- **Chef** : Animateur, Animateur responsable, Candidat animateur.
- **Chef d'Unité** : Animateur d'unité, Candidat cadre local,
  Collaborateur d'unité, Equipier d'unité.
- **Public** : Iama local.
- **Animé** : Scout.

Avec Trombinoscope actif, seuls **Animateur responsable** et
**Animateur d'unité** sont cochés « Responsable de section » par défaut.
Cette case est distincte de la fonction principale dans Desk.

Tout reste modifiable ici. Un nouvel import conserve les correspondances
existantes, même non confirmées, et vos cases cochées ou décochées.
Les fonctions inconnues arrivent en « Animé », non confirmées, regroupées
en tête : choisir un rôle, même « Animé », les confirme.

> C'est ici que se décident les accès au site. Un rôle « Chef
> d'Unité » ouvre l'espace d'administration : n'attribuez ce rôle
> qu'aux fonctions qui le justifient. Le changement s'applique dès leur
> prochaine action sur le site.

## Régler les sections

Pour chaque section, vous pouvez :

- lui donner un **nom** d'affichage (à défaut, son code Desk s'affiche) ;
- renseigner son **adresse e-mail** de section ;
- choisir sa **couleur** — par défaut celle de sa branche ; le bouton ↺
  revient à cette couleur ;
- la rendre **visible ou non** : une section masquée disparaît de tous
  les sélecteurs du site (Staffs, trombinoscope, calendrier, page
  publique Sections).

Une section sans aucun membre au dernier import devient inactive et
disparaît d'elle-même, y compris de cette page ; elle revient dès
qu'un import lui redonne des membres — rien n'est jamais supprimé.

Les sections peuvent partager un nom. Un badge de référent au nom déjà
pris reçoit le code Desk, puis un numéro au besoin. Les affectations
sont conservées.

### L'adresse d'une section et les publipostages

Pour être l'expéditeur d'un publipostage, l'adresse de section doit être
**sur le domaine d'envoi du site** ou un de ses sous-domaines.
Une adresse Gmail, Skynet ou autre fournisseur ne peut pas être signée
par le site : la page vous avertit sous le champ.

Le message part alors depuis l'adresse d'envoi du site, sous le nom
de la section suivi de celui de l'unité ; les réponses arrivent à la
section. La sous-page « Authentification » du courrier sortant
récapitule les adresses concernées.

## Les branches

Pour chaque branche d'âge, vous pouvez remplacer le logo fédéral et le
lien « En savoir plus » affichés sur la carte de branche de la page de
chaque animé. Sans réglage, le logo et le lien par défaut de la
fédération sont utilisés.

Chaque changement de rôle ou de section est consigné au journal, au
niveau sécurité.
