---
id: config-emails
title: Réécrire les emails automatiques
summary: La liste de tous les emails que le site envoie tout seul, et comment en changer le texte.
category: Configuration
role_min: superadmin
discovery: 1
question: Comment changer le texte d'un e-mail automatique du site ?
question: Quels e-mails le site envoie-t-il tout seul ?
paths: /config/emails, /config/emails/*
related: reglages, config-notifications, adresses-email
---

Le site envoie des emails tout seul : un accusé de réception, un
rappel avant un séjour, un avis au Staff d'U. Cette page les liste
tous et permet d'en réécrire le texte.

## La liste

Les emails sont regroupés par origine : ceux du site, puis ceux de
chaque module activé. Chaque ligne dit ce que l'email est, quand il
part, et son état :

- **Par défaut** : il utilise le texte livré avec le site. Il suivra
  automatiquement les améliorations des prochaines versions.
- **Personnalisé** : votre unité en a réécrit le sujet ou le corps.
- **Non modifiable** : c'est un email d'authentification.

## Les emails qu'on ne peut pas réécrire

Les emails qui servent à se connecter ou à confirmer une adresse sont
affichés sans bouton pour les modifier : une erreur dans le lien de
connexion mettrait tout le monde dehors.

## Réécrire un email

Touchez son nom pour ouvrir sa page. Elle a trois parties.

**Le sujet** est une simple ligne de texte. Le nom de votre unité y
est ajouté automatiquement à l'envoi : inutile de le réécrire.

**Le corps du message** s'ouvre avec le crayon, dans le même éditeur
que partout ailleurs sur le site. Il part du texte livré : vous
corrigez plutôt que d'écrire depuis une page blanche. Inutile de
signer — la formule finale (« Bien à vous ») et le nom de votre unité
sont ajoutés automatiquement, à cet email comme à tous les autres.

**L'aperçu** montre le résultat avec des valeurs d'exemple. Le bouton
« M'envoyer un test », en dessous, vous envoie l'email pour de vrai —
par le même chemin que les autres, un test toutes les 30 secondes au
plus.

## Les variables

Un email parle de choses que le site ne connaît qu'au moment de
l'envoi : le nom du demandeur, la date d'une activité, un lien de
suivi. Ces trous s'écrivent `{{ nom_de_la_variable }}`.

Le texte livré les contient déjà. **Gardez-les** : remplacées par un
vrai nom, elles enverraient ce nom-là à tout le monde.

Sous le sujet et dans l'éditeur du corps, les boutons insèrent la
variable à la place du curseur ; sous le corps, un clic la copie.

Une variable mal orthographiée part telle quelle, accolades comprises,
dans l'email : utilisez les boutons plutôt que de la taper.

## Les variables obligatoires

Le bouton d'une variable sans laquelle l'email n'a pas de sens — un
lien de suivi, par exemple — porte la mention **« obligatoire »** :

- un texte qui ne la contient pas n'est pas enregistré, et le message
  dit laquelle manque ;
- si un texte déjà enregistré l'a perdue, l'email n'est pas envoyé : sa
  page le signale en rouge et le journal en garde la trace.

## Revenir en arrière

Un email personnalisé ne suit plus les améliorations du texte livré.
« Revenir au gabarit par défaut » supprime votre texte, définitivement,
et le remet sur celui du site.
