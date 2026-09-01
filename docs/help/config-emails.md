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

Quelques emails servent à se connecter au site ou à confirmer une
adresse. Ils sont affichés pour que la liste soit complète, mais sans
bouton pour les modifier : une erreur dans le lien de connexion
mettrait tout le monde dehors, y compris la personne qui vient de la
faire.

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

Le texte livré les contient déjà, à leur place. **Gardez-les** : ce
sont eux qui écrivent le nom, la date ou le lien de chaque
destinataire. Un texte où vous les auriez remplacés par un vrai nom
enverrait ce nom-là à tout le monde.

Ces boutons sont à deux endroits où ils insèrent la variable là où se
trouve votre curseur : sous le champ du sujet, et dans l'éditeur du
corps du message. Sur la page elle-même, sous l'aperçu du corps, un
clic la copie — pratique si vous écrivez votre texte ailleurs.

Une variable mal orthographiée part telle quelle, accolades comprises,
dans l'email : utilisez les boutons plutôt que de la taper.

## Revenir en arrière

Tant qu'un email est personnalisé, il ne suit plus les améliorations
apportées au texte livré. Le bouton « Revenir au gabarit par défaut »
supprime votre texte et le remet sur celui du site. C'est définitif :
votre version n'est conservée nulle part.
