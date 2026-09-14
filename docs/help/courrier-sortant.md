---
id: courrier-sortant
title: Par où partent les e-mails du site
summary: Les fournisseurs d'envoi, les trois voies et leur chaîne de repli.
category: Configuration
role_min: superadmin
question: Pourquoi les e-mails du site ne partent-ils plus ?
question: Comment ajouter un deuxième fournisseur d'envoi ?
question: Pourquoi un publipostage empêche-t-il les liens de connexion de partir ?
question: À quoi sert la cadence d'un fournisseur ?
question: Que deviennent les messages quand plus aucun fournisseur ne répond ?
question: Pourquoi un publipostage s'arrête-t-il avant d'avoir atteint le quota ?
question: Comment relancer des e-mails qui n'ont pas pu partir ?
paths: /config/courrier-sortant, /config/courrier-sortant/acheminement, /config/courrier-sortant/fournisseurs/nouveau, /config/courrier-sortant/fournisseurs/*
related: installation-serveur, config-emails, adresses-email
---

Le site envoie du courrier pour trois raisons très différentes : vous
connecter, vous prévenir, et écrire aux familles. La page « Courrier
sortant » dit par où chacun de ces trois courriers passe, et ce qui se
produit quand ce chemin ne répond plus.

## Un fournisseur suffit pour commencer

Un **fournisseur** est un relais qui accepte vos messages et les remet.
Sur sa fiche : son nom, son serveur, son port et ses identifiants. Ceux-ci
sont conservés chiffrés en dehors de la base de données. Le **mot de passe**
ne vous est jamais réaffiché — laissez le champ vide pour conserver celui
qui est enregistré. L'**identifiant**, lui, reste visible : sans cela vous
ne sauriez pas quel compte sert, et l'effaceriez en enregistrant.

L'**envoi local** est toujours présent, en bas de liste : le serveur
expédie lui-même, sans relais. Il ne se supprime pas, seulement se
désactive ou se déplace. Gardez-le en dernier recours : beaucoup
d'hébergeurs bloquent la sortie, et la réputation de leur adresse IP ne
vous appartient pas.

## Trois voies, trois chaînes

Dans « Acheminement », chaque voie porte une liste ordonnée. On essaie le
premier fournisseur actif ; s'il échoue ou s'il a atteint son quota du
jour, on passe au suivant.

- **Authentification** — les liens de connexion et les confirmations
  d'adresse.
- **Transactionnel** — les notifications, les alertes, les accusés de
  réception.
- **Masse** — les publipostages, et rien d'autre.

Glissez pour réordonner sur un grand écran, utilisez les flèches sur un
téléphone. Une voie garde toujours **au moins un fournisseur actif** : en
vider une, c'est l'arrêter — et vider la voie « Authentification »
laisserait tout le monde à la porte du site, vous compris.

C'est ici que se règle le problème le plus courant : un publipostage de
quatre cents messages épuise en une fois le quota quotidien d'un relais
gratuit. Désactivez ce relais dans la voie « Masse » et le publipostage ne
peut plus consommer le quota dont dépendent vos liens de connexion.

## La cadence, et ce qu'elle ne fait pas

Derrière « Avancé », chaque fournisseur porte une cadence — tant de
messages toutes les tant de minutes — et un quota journalier. La cadence
ne s'applique qu'à la voie « Masse ». Une notification ou un lien de
connexion part immédiatement : un lien qui attendrait le prochain créneau
d'un lot ne serait plus un lien valide, il n'a qu'un quart d'heure à
vivre.

Quand une voie bascule sur le fournisseur suivant, elle adopte **sa**
cadence et **son** quota, jamais ceux du précédent.


## La réserve pour les liens de connexion

Sur un fournisseur qui porte à la fois la voie « Masse » et une autre, le
site met une part de son quota de côté. Le publipostage s'arrête avant
cette part ; les liens de connexion, eux, la trouvent encore là.

Le nombre n'est pas à régler : il vient de votre propre historique — votre
pointe quotidienne hors publipostage des trente derniers jours, plus une
marge. « Acheminement » l'affiche sous la voie « Authentification », avec
la phrase qui dit d'où il sort, pour que vous puissiez la vérifier sur vos
propres compteurs.

C'est pour cela qu'un envoi en masse peut s'arrêter à 930 messages sur un
quota de 1000. Ce n'est pas une erreur : ce sont vos connexions de demain
matin.

## Un fournisseur qui tombe est mis à l'écart

Quand un relais refuse trois messages d'affilée pour une raison qui lui
appartient — il ne répond plus, le mot de passe est refusé, le chiffrement
ne s'établit pas — le site cesse de l'essayer pendant quelques minutes, et
plus longtemps à chaque rechute. Sa fiche le dit, et l'heure à laquelle il
sera réessayé aussi.

Une adresse refusée ne compte pas : c'est le destinataire qui n'existe
pas, pas le relais qui va mal. Sans quoi une adresse mal orthographiée
dans un publipostage suffirait à écarter un fournisseur en parfait état.

Une voie n'est **jamais** vidée par ce mécanisme : même si tous ses
fournisseurs sont à l'écart, le dernier est tout de même essayé. Mieux
vaut une tentative inutile qu'un site où plus personne ne peut se
connecter.

## Les messages différés

Quand aucun fournisseur d'une voie ne peut prendre un message, il est mis
de côté et réessayé — d'abord dans cinq minutes, puis de plus en plus
tard, pendant la durée que vous avez fixée dans « Réglages ». La page en
donne le compte, voie par voie.

Un report n'est pas un silence : la personne qui a cliqué sur « Envoyer »,
elle, a vu un envoi réussi. Tant que la file ne se vide pas, ces messages
ne sont pas partis — et le site vous prévient quand elle cesse de se
vider.

**La voie « Authentification » ne diffère jamais.** Un lien de connexion
livré demain n'est plus un lien de connexion : il ne vit qu'un quart
d'heure, et la personne est devant son écran. Elle a besoin de la vérité
tout de suite.

## Relancer les échecs

Passé le délai, un message est abandonné. Il reste visible un temps, avec
les autres, répartis par âge : vous voyez d'un coup d'œil s'il s'agit de
la panne de ce matin ou d'une accumulation de la semaine.

Le bouton « Relancer » les remet en file — pour la fenêtre et les voies
que vous choisissez. La fenêtre proposée par défaut est la plus courte,
volontairement : les échecs qu'on veut relancer sont ceux de ce matin, et
un bouton qui renverrait quinze jours de messages d'un clic ne servirait
qu'une fois.

Après le délai de conservation, les messages abandonnés disparaissent pour
de bon, leur contenu avec eux.

> Un message classé en indésirables n'apparaît nulle part sur cette page :
> il a été accepté. Les compteurs disent ce qui est parti, pas ce qui a
> été lu.
