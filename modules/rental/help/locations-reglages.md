---
id: locations-reglages
title: Les réglages d'un bien
summary: Ce qu'un visiteur peut demander, ce que ça coûte, ce qui est attendu à l'avance et sous quelles conditions.
category: Espace membres
role_min: identified
discovery: 3
question: Comment fixer le prix de location d'un local ?
question: Comment imposer une durée minimum de réservation ?
question: Comment demander une caution sur une location ?
question: Où écrire les conditions de location d'un bien ?
paths: /mes-locations/*/reglages
related: gerer-les-locations, config-locations, locations-conformite
---

Quatre sections, quatre questions différentes. Chacune se lit d'un coup
d'œil et s'ouvre pour être modifiée : le bouton « Modifier » de la carte
ouvre le formulaire correspondant.

## Règles de réservation

Ce qu'un visiteur a le droit de **demander** : une durée minimum et
maximum, un préavis, un horizon au-delà duquel c'est trop tôt, une
capacité et un battement après chaque location. Vous pouvez aussi
n'autoriser l'arrivée que certains jours.

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

## Conditions de location

Le texte que le locataire lit — et accepte — avant d'envoyer sa demande.
Il s'affiche aussi sur la page publique du bien.

Tant que vous ne l'avez pas écrit, ce sont les **conditions standard**
fournies avec le site qui s'appliquent : un point de départ à relire et à
adapter, pas un avis juridique. Vous les modifiez en texte riche ici même,
et « Revenir aux conditions standard » remet le texte d'origine.

Le site conserve le texte **tel qu'il a été montré** à chaque demande.
Modifier vos conditions ne change donc rien à ce qu'un locataire a déjà
accepté, et personne ne peut se voir opposer une version qu'il n'avait
pas vue.

> Modifier ces réglages ne change **aucune réservation existante** : le
> prix annoncé est figé au moment de la demande, et le prix convenu au
> moment de l'accord.
