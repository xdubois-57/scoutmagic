---
id: camps-courrier
title: Le courrier des camps
summary: Ce que le site reprend de vos e-mails, ce qu'il ne touchera jamais, et l'écran où vous triez.
category: Espace animateurs
role_min: chief
question: Pourquoi les e-mails du propriétaire arrivent-ils sur le séjour ?
question: Comment dédier une adresse e-mail aux camps ?
paths: /chefs/camps/courrier
related: camps-courrier-ecran, camps, camps-propositions, config-camps
---

Si votre unité relève ses e-mails depuis ScoutMagic, les messages qui
concernent un séjour peuvent s'y rattacher tout seuls. Ce qui se passe
dépend entièrement de la boîte dans laquelle ils arrivent.

## Une boîte partagée

L'adresse ordinaire de l'unité, que d'autres modules lisent aussi. Le
module des camps y est **volontairement très prudent** : il ne reprend que
deux choses :

- une réponse dans une conversation déjà rattachée à un séjour ;
- un message venant d'un contact déjà enregistré, écrit dans une période
  proche du séjour concerné.

Rien d'autre. **Jamais** sur un mot dans l'objet, jamais sur un nom de
lieu. Et si deux séjours correspondent au même expéditeur, le message
n'est rattaché à aucun : le mettre sur le mauvais serait pire, car
personne ne pourrait s'en apercevoir. Chacun des deux devient une
proposition, que vous confirmez ou écartez sur l'écran du courrier.
Un séjour annulé ne compte jamais.

## Une boîte dédiée

Une adresse dont **tout** le contenu concerne les camps, par exemple
camps@votre-unite.be. Un superadministrateur la déclare dédiée aux camps
dans la configuration du courrier entrant ; à partir de là, aucun autre
module ne la lit, et **tout** son contenu apparaît sur cet écran, rattaché
ou non.

Sur une boîte dédiée, le site lit aussi **les dates que le message
annonce** — *du 12 au 19 juillet 2026* dans l'objet, le corps ou une
pièce jointe. Un seul séjour couvre exactement ces dates : le message y
est rattaché, avec la mention « Période annoncée dans le message ».
Plusieurs séjours les couvrent : une proposition par séjour, et aucun
choisi.

Ce que l'écran « Courrier des camps » montre, et ce que « Retirer »
veut dire, sont décrits dans « L'écran du courrier des camps ».

## Créer un camp depuis un message

Un message qui annonce des dates sans ambiguïté peut devenir un séjour.

**Automatiquement**, si la configuration l'autorise : le séjour est créé
« à confirmer » sous un terrain que le site reconnaît déjà, et le message
est classé dessous. **Le nom d'expéditeur ne baptise jamais un terrain** :
une ferme signe ses e-mails du nom de la personne qui les écrit, et ce
nom-là n'a rien à faire dans votre liste de lieux.

Un terrain inconnu n'est créé que si le module d'intelligence artificielle
est actif : son nom est alors **lu dans le message**, avec la consigne de
ne jamais renvoyer un nom de personne et de ne rien renvoyer en cas
d'hésitation. Sinon, aucun terrain n'est créé et le message vous attend
sur l'écran « Courrier des camps ».

**À la main** : « Créer un camp depuis ce message » ouvre le formulaire
habituel, dates, prix et lieu déjà remplis quand le site a su les lire.
Vous corrigez, vous enregistrez, le message va se ranger sous le séjour.
Le bouton reste là même en mode automatique.

Ce que le site sait lire dans un message, et ce qu'il en fait sur la page
du séjour : voir « Les informations lues dans un message ».
