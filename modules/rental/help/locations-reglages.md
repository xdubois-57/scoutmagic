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

## Tarification

Le mode de facturation décide tout le reste — à la nuit, par personne et
par nuit, au forfait, à l'exemplaire. Viennent ensuite le tarif par défaut,
la grille par période et par catégorie, le minimum facturable et les frais.

Le simulateur en bas de page calcule avec **le même moteur** que la page
publique et que le contrat : s'il vous donne un chiffre qui surprend, un
visiteur verra le même.

## Acompte, solde et caution

Cette section n'existe que si le module Finances est actif. Chaque
réservation a une seule créance — le prix de la location plus la caution —
avec une seule communication structurée. L'acompte est un seuil sur cette
créance, attendu le nombre de jours choisi après le premier envoi du
contrat. Le solde et la caution sont attendus ensemble, le nombre de jours
choisi avant l'arrivée : la caution n'a pas d'échéance à elle.

Le compte bancaire attendu ne se choisit pas ici : il est fixé par le Staff
d'Unité, la liste des comptes portant les IBAN de l'unité.

Les conditions de location se trouvent désormais sur la page « Gabarits »,
avec le contrat et la facture.

## Bailleur

Qui le contrat et la facture nomment comme bailleur, si ce bien en a un
autre que l'unité.

## Contrat

L'« Adresse du bien », sans laquelle aucun contrat ne se génère, et la
« Date limite de désistement sans indemnité », en jours avant l'arrivée :
chaque contrat imprime la date calculée pour son séjour, puis l'indemnité
due — la moitié du prix jusqu'à un mois avant le séjour, les trois quarts
ensuite. Une date déjà passée à la génération n'est pas offerte : le
contrat dit que la période sans indemnité ne s'applique pas.

## Rappels

Une ligne par rappel : un nombre de jours et une case « Actif ».

Un champ vide **reprend la valeur de l'unité**, affichée juste en dessous.
**0** veut dire « le jour même ». Vide ne veut donc jamais dire « jamais » :
pour supprimer un rappel sur ce bien, décochez la case — ce qu'on fait sur
une remorque, qui n'a ni état des lieux ni caution.

> Modifier ces réglages ne change **aucune réservation existante** : le
> prix annoncé est figé au moment de la demande, et le prix convenu au
> moment de l'accord.
