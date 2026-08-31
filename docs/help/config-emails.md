---
id: config-emails
title: Réécrire les emails automatiques
summary: La liste de tous les emails que le site envoie tout seul, et comment en changer le texte.
category: Configuration
role_min: superadmin
question: Comment changer le texte d'un e-mail automatique du site ?
question: Quels e-mails le site envoie-t-il tout seul ?
paths: /config/emails, /config/emails/*
related: reglages, config-notifications, adresses-email
---

Le site envoie des emails tout seul : un accusé de réception à une
famille qui demande une location, un rappel avant un séjour, un avis
au Staff d'U quand une adresse se désinscrit. Cette page les liste
tous et permet d'en réécrire le texte, pour que ce que votre unité
écrit ressemble à votre unité.

## La liste

Les emails sont regroupés par origine : d'abord ceux du site, puis
ceux de chaque module activé. Chaque ligne dit ce que l'email est,
quand il part, et son état :

- **Par défaut** : il utilise le texte livré avec le site. Il suivra
  automatiquement les améliorations des prochaines versions.
- **Personnalisé** : votre unité en a réécrit le sujet ou le corps.
- **Non modifiable** : c'est un email d'authentification.

## Les emails qu'on ne peut pas réécrire

Trois emails servent à se connecter au site ou à confirmer une
adresse : le lien de connexion, la réinitialisation du mot de passe et
la confirmation d'une adresse secondaire. Ils sont affichés pour que
la liste soit complète, mais sans bouton pour les modifier.

Ce n'est pas un oubli : une erreur dans le lien de connexion mettrait
tout le monde dehors, y compris la personne qui vient de la faire.

## Réécrire un email

Touchez son nom pour ouvrir sa page. Elle a trois parties.

**Le sujet** est une simple ligne de texte. Le nom de votre unité est
ajouté automatiquement devant lui à l'envoi : inutile de le réécrire.

**Le corps du message** s'ouvre avec le crayon, dans le même éditeur
que partout ailleurs sur le site. Il part du texte livré : vous
corrigez plutôt que d'écrire depuis une page blanche.

**L'aperçu** montre le résultat avec des valeurs d'exemple. L'en-tête et
le pied de page du site sont ajoutés à l'envoi et ne se modifient pas
ici. Le bouton « M'envoyer un test », en dessous, vous envoie l'email
pour de vrai — par le même chemin que les autres, un test toutes les 30
secondes au plus.

## Les variables

Un email parle de choses que le site ne connaît qu'au moment de
l'envoi : le nom du demandeur, la date d'une activité, un lien de
suivi. Ces trous s'écrivent `{{ nom_de_la_variable }}`, et les boutons
sous le champ donnent ceux que cet email accepte.

Sur le sujet, un bouton insère la variable là où se trouve le curseur.
Pour le corps, l'éditeur s'ouvre par-dessus la page : le bouton copie
alors la variable, et vous la collez dans le message.

Une variable mal orthographiée part telle quelle, accolades comprises,
dans l'email que reçoivent vos familles : utilisez les boutons plutôt
que de la taper.

## Revenir en arrière

Tant qu'un email est personnalisé, il ne suit plus les améliorations
apportées au texte livré. Le bouton « Revenir au gabarit par défaut »,
en bas de sa page, supprime votre texte et le remet sur celui du site.
C'est définitif : votre version n'est conservée nulle part.
