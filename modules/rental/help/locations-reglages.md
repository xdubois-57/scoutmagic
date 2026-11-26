---
id: locations-reglages
title: Les réglages d'un bien
summary: Ce qu'un visiteur peut demander, ce que ça coûte et ce qui est attendu à l'avance.
category: Espace membres
role_min: identified
discovery: 3
question: Comment fixer le prix de location d'un local ?
question: Comment imposer une durée minimum de réservation ?
question: Comment changer le délai d'un rappel sur un bien ?
paths: /mes-locations/*/reglages
related: gerer-les-locations, config-locations, locations-conformite, locations-gabarits
---

Cinq sections, cinq questions différentes. Chacune se lit d'un coup d'œil,
et le bouton « Modifier » de la carte ouvre son formulaire.

## Règles de réservation

Ce qu'un visiteur a le droit de **demander** : durée minimum et maximum,
préavis, horizon au-delà duquel c'est trop tôt, capacité, battement après
chaque location, jours d'arrivée autorisés.

Une règle à zéro ne s'applique pas. Et une règle n'est pas une occupation :
un jour à l'intérieur du préavis reste libre, affiché comme une date
passée.

Par défaut, la durée maximum vaut 30 nuits et l'horizon 548 jours ; un
champ vide reprend ces valeurs. Zéro les rend illimités. Une demande ne
bloque jamais d'elle-même plus de 30 nuits : au-delà, elle arrive sans
option.

## Tarification

Le mode de facturation décide tout le reste. Viennent ensuite le tarif
par défaut, la grille par période et par catégorie, le minimum facturable
et les frais. Une case vide de la grille suit le tarif par défaut, en
gris ; remplie, même à 0, elle a son prix. Deux périodes ne partagent
jamais un jour.

Le simulateur calcule avec **le même moteur** que la page publique et le
contrat.

## Acompte, solde et caution

« Suivre les paiements via le site » s'enregistre dès qu'on coche : une
créance par réservation, avec une communication structurée. Il faut
d'abord un compte et une catégorie, fixés dans « Biens à louer ».

« Acompte » et « Caution » s'enregistrent chacun à part, même sans
Finances : le contrat les indique. L'acompte est attendu après le premier
envoi du contrat ; le solde et la caution, ensemble, avant l'arrivée.

Les conditions de location se trouvent désormais sur la page « Gabarits »,
avec le contrat et la facture.

## Bailleur

Le bailleur par défaut, ou un bailleur spécifique à ce bien — une ASBL
propriétaire des locaux, par exemple.

## Contrat

L'« Adresse du bien », sans laquelle aucun contrat ne se génère, et la
« Date limite de désistement sans indemnité », en jours avant l'arrivée :
chaque contrat imprime la date calculée pour son séjour, puis l'indemnité
due — la moitié du prix jusqu'à un mois avant le séjour, les trois quarts
ensuite. Une date déjà passée à la génération n'est pas offerte : le
contrat dit que la période sans indemnité ne s'applique pas.

## Rappels

Un résumé, puis « Modifier » : chaque rappel, son destinataire, un
délai en jours et une case « Actif ».

Un champ vide **reprend la valeur de l'unité**, affichée juste en dessous.
**0** veut dire « le jour même ». Vide ne veut donc jamais dire « jamais » :
pour supprimer un rappel sur ce bien, décochez la case.

> Modifier ces réglages ne change **aucune réservation existante** : le
> prix annoncé est figé au moment de la demande, et le prix convenu au
> moment de l'accord.
