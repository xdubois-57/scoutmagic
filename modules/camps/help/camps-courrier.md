---
id: camps-courrier
title: Le courrier des camps
summary: Ce que le site reprend de vos e-mails, ce qu'il ne touchera jamais, et le courrier non classé.
category: Espace animateurs
role_min: chief
paths: /chefs/camps/courrier
related: camps, config-camps
---

Si votre unité relève ses e-mails depuis ScoutMagic, les messages qui
concernent un séjour peuvent s'y rattacher tout seuls. Ce qui se passe
dépend entièrement de la boîte dans laquelle ils arrivent.

## Une boîte partagée

L'adresse ordinaire de l'unité, que d'autres modules lisent aussi. Le
module des camps y est **volontairement très prudent** : tout ce qu'il
prend est un message que les autres modules ne verront jamais. Il ne
reprend que deux choses :

- une réponse dans une conversation déjà rattachée à un séjour ;
- un message venant d'un contact déjà enregistré, écrit dans une période
  proche du séjour concerné.

Rien d'autre. **Jamais** sur un mot dans l'objet, jamais sur un nom de
lieu. Et si deux séjours correspondent au même expéditeur, le message
n'est rattaché à aucun : le mettre sur le mauvais séjour serait pire, car
personne ne pourrait s'en apercevoir.

## Une boîte dédiée

Une adresse dont **tout** le contenu concerne les camps, par exemple
camps@votre-unite.be. Le module y prend tous les messages, et ceux qu'il
ne sait rattacher à aucun séjour vont dans le **courrier non classé**,
d'où vous les rattachez à la main.

Une boîte dédiée doit être **exclue des autres modules** qui lisent le
courrier — le module de location, en particulier, lit toutes les boîtes
par défaut. Le premier module qui réclame un message le garde.

Le courrier non classé est effacé après le délai réglé dans la
configuration (six mois par défaut).

## Ce que le site lit dans un message

Des dates et un prix, uniquement quand c'est écrit sans ambiguïté :
« du 12 au 19 juillet 2028 », « 2 450 € ». Une date isolée n'est pas
retenue — c'est bien plus souvent un rendez-vous qu'un départ en camp. Et
si un message contient **deux** montants, aucun n'est retenu : un devis
qui annonce un acompte et un solde est exactement le message où se
tromper coûte le plus cher.

## La règle qui compte

**Un champ vide est rempli. Un champ déjà rempli n'est jamais écrasé.**

Vous avez écrit 2 450 € parce que vous aviez le contrat sous les yeux ;
une lecture automatique d'e-mail n'a pas à vous contredire en silence. Le
site range alors sa lecture **à côté du champ concerné**, sur la page du
séjour, avec « Appliquer » et « Ignorer ».

Les deux réponses sont notées dans l'historique — y compris « Ignorer ».
Dans six mois, quelqu'un demandera pourquoi la page ne dit pas la même
chose que le mail : la réponse sera écrite.
