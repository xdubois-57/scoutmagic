---
id: camps-courrier
title: Le courrier des camps
summary: Ce que le site reprend de vos e-mails, ce qu'il ne touchera jamais, et le courrier non classé.
category: Espace animateurs
role_min: chief
paths: /chefs/camps/courrier
related: camps, camps-propositions, config-camps
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
personne ne pourrait s'en apercevoir.

## Une boîte dédiée

Une adresse dont **tout** le contenu concerne les camps, par exemple
camps@votre-unite.be. Le module y prend tous les messages ; ceux qu'il ne
sait rattacher vont dans le **courrier non classé**.

Une boîte dédiée doit être **exclue des autres modules** qui lisent le
courrier — le module de location, en particulier, lit toutes les boîtes
par défaut. Le premier module qui réclame un message le garde.

Le courrier non classé est effacé après le délai réglé dans la
configuration (six mois par défaut). Chaque message s'y lit **en entier** :
on ne supprime pas définitivement un courrier dont on n'a vu que les
premières lignes.

## Créer un camp depuis un message

Un message qui annonce des dates sans ambiguïté peut devenir un séjour.

**Automatiquement**, si la configuration l'autorise : le séjour est créé
« à confirmer » et le message est classé dessous. Le lieu est celui que le
site reconnaît ; sinon, il en crée un au nom de l'expéditeur — à relire,
donc, et à fusionner s'il fait double emploi.

**À la main** : « Créer un camp depuis ce message » ouvre le formulaire
habituel, dates, prix et lieu déjà remplis. Vous corrigez, vous
enregistrez, le message va se ranger sous le séjour. Le bouton reste là
même en mode automatique : ce qui traîne ici est justement ce que le site
n'a pas su faire seul.

Ce que le site sait lire dans un message, et ce qu'il en fait sur la page
du séjour : voir « Les informations lues dans un message ».
