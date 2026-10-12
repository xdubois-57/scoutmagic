---
id: capacites-et-listes-d-attente
title: Régler les capacités, les seuils et les priorités
summary: Combien de places par année, quand une année est disponible, limitée ou complète, et dans quel ordre les demandes passent.
category: Espace chefs d'U
role_min: admin
question: Comment est calculé le nombre de places occupées ?
question: Que règlent les seuils Oui et Peut-être ?
question: Pourquoi une année de naissance est-elle annoncée complète ?
question: Dans quel ordre les demandes en attente reçoivent-elles une place ?
paths: /config/inscriptions, /config/inscriptions/reglages
related: gerer-les-demandes, inscrire-un-enfant, previsions, reinscription
---

## Les capacités

Dans l'encart « Capacités par branche », la grille « Capacités » dit
combien de places offre chaque année du parcours. Une case vide signifie
**pas de limite** ; pour fermer une année, écrivez **0** — ce n'est pas
la même chose. Une branche sans capacité en reçoit une de 15 dès votre
première visite.

## Les places occupées

Une place est occupée par un animé de l'effectif projeté ou par une
demande acceptée, encodée dans Desk ou non — chaque enfant compte une
seule fois. L'effectif projeté est celui des prévisions. Un animé dont
la famille n'a pas répondu à la réinscription compte comme restant. La
« Vérification des capacités » détaille ces nombres.

## Les seuils

La case « Gérer les listes d'attente » s'enregistre dès que vous la
cochez. Dessous, « Seuils de la recommandation et du niveau public » :
une glissière à deux poignées et deux champs, « Oui jusqu'à … % de places
occupées » (70) et « Peut-être jusqu'à … % de places occupées » (100) ;
au-delà, c'est Non. La limite est comprise : 7 places occupées sur 10
restent à Oui. L'exemple chiffré traduit les deux pourcentages en places.
Les familles lisent le même niveau : « Disponible », « Limitée » ou
« Complet ». Case décochée, les seuils restent affichés, grisés, et vos
valeurs sont conservées.

## La recommandation et les priorités

Listes d'attente gérées, le site calcule pour chaque demande en attente
une recommandation Oui, Peut-être ou Non, réservée au Staff d'U, par
année du parcours, dans l'ordre de l'encart « Priorités » :

- « Toujours Oui » : ces demandes passent en premier, toujours Oui,
  même au-delà de la capacité (par défaut, la fratrie) ;
- « Classement » : les autres sont classées par ces critères, de haut en
  bas, puis par date de dépôt ;
- « Désactivés » : critères ignorés.

Chaque demande est jugée sur les places qui seraient occupées si toutes
celles qui la précèdent étaient acceptées. « Ordre d'arrivée de la
demande » ne peut que classer. « Ancienne demande restée sans place »
retient un enfant refusé une année précédente avec le motif « Pas de
place ».

> Doublon possible (même nom, même date de naissance, même année) : une
> seule demande de l'enfant sert de référence, la plus ancienne ou celle
> déjà acceptée ou encodée. En attente, elle seule reçoit une
> recommandation ; les autres demandes de l'enfant, non, ni un enfant
> hors des tranches d'âge. Rien n'est calculé sans listes d'attente
> gérées, ni pour une année passée ; les familles n'en voient rien.
