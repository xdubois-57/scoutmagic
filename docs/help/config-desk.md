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
Animé, Intendant, Chef ou Chef d'Unité. Une fonction nouvelle arrive
toujours en « Animé », non confirmée : la page les regroupe en tête
pour que vous confirmiez chacune — choisir un rôle, même « Animé »,
vaut confirmation.

> C'est ici que se décident les accès au site. Un rôle « Chef
> d'Unité » ouvre l'espace d'administration : n'attribuez ce rôle
> qu'aux fonctions qui le justifient. Le changement prend effet à la
> prochaine connexion des personnes concernées.

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

### L'adresse d'une section et les publipostages

Un publipostage part au nom de sa section, sous l'adresse renseignée
ici. Pour que les serveurs des destinataires l'acceptent, cette adresse
doit être **sur le domaine d'envoi du site** (ou un de ses
sous-domaines) : c'est le seul domaine pour lequel le site peut signer.
Une adresse chez un fournisseur grand public — Telenet, Skynet, Gmail,
Yahoo… — n'en fait pas partie, et un message envoyé sous une telle
adresse est refusé ou classé en indésirable chez une bonne partie des
destinataires.

Ce n'est pas un refus : la page vous avertit sous le champ, et le
publipostage part quand même — depuis l'adresse d'envoi du site, sous le
nom de la section suivi de celui de l'unité, avec les réponses qui
arrivent toujours à l'adresse de la section. Pour que l'adresse de la
section redevienne l'expéditeur, il faut lui en donner une sur le
domaine du site. La sous-page « Authentification » du courrier sortant
récapitule toutes les sections concernées.

## Les branches

Pour chaque branche d'âge, vous pouvez remplacer le logo fédéral et le
lien « En savoir plus » affichés sur la carte de branche de la page de
chaque animé. Sans réglage, le logo et le lien par défaut de la
fédération sont utilisés.

Chaque changement de rôle ou de section est consigné au journal, au
niveau sécurité.
