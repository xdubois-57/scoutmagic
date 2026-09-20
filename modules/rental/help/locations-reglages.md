---
id: locations-reglages
title: Les réglages d'un bien
summary: Ce qu'un visiteur peut demander, ce que ça coûte, et ce qui est attendu à l'avance.
category: Espace membres
role_min: identified
discovery: 3
question: Comment fixer le prix de location d'un local ?
question: Comment imposer une durée minimum de réservation ?
question: Comment demander une caution sur une location ?
question: Comment changer le délai d'un rappel sur un bien ?
paths: /mes-locations/*/reglages
related: gerer-les-locations, config-locations, locations-conformite
---

Quatre sections, quatre questions différentes. Chacune se lit d'un coup
d'œil et s'ouvre pour être modifiée : le bouton « Modifier » de la carte
ouvre le formulaire correspondant.

## Règles de réservation

Ce qu'un visiteur a le droit de **demander** : durée minimum et maximum,
préavis, horizon au-delà duquel c'est trop tôt, capacité, battement après
chaque location, jours d'arrivée autorisés.

Une règle laissée à zéro ne s'applique pas. Et une règle n'est pas une
occupation : un jour à l'intérieur du préavis reste libre, il s'affiche
simplement comme une date passée.

## Tarification

Le mode de facturation décide tout le reste — à la nuit, par personne et
par nuit, au forfait, à l'exemplaire. Ensuite viennent le tarif par
défaut, la grille par période et par catégorie de locataire, le minimum
facturable et les frais.

Le simulateur en bas de page calcule avec **le même moteur** que la page
publique et que le contrat. C'est le garde-fou : si le simulateur donne
un chiffre qui vous surprend, un visiteur verra le même.

## Acompte, solde et caution

Cette section n'existe que si le module Finances est actif. L'acompte
n'est pas une créance séparée : c'est un seuil sur la créance de la
location, avec la même communication structurée que le solde. La caution,
elle, est une créance à part entière et n'entre jamais dans le revenu de
location.

Le compte bancaire sur lequel les virements sont attendus ne se choisit
pas ici : il est fixé par le Staff d'Unité, parce que la liste des
comptes porte les IBAN de l'unité.

## Rappels

Une ligne par rappel : un nombre de jours et une case « Actif ».

Un champ vide **reprend la valeur de l'unité**, affichée juste en
dessous — c'est le nombre qui s'appliquera. **0** veut dire « le jour
même ». Vide ne veut donc jamais dire « jamais » : pour supprimer un
rappel sur ce bien, décochez la case. C'est ce qu'on fait sur une
remorque, qui n'a ni état des lieux ni caution.

> Modifier ces réglages ne change **aucune réservation existante** : le
> prix annoncé est figé au moment de la demande, et le prix convenu au
> moment de l'accord.
