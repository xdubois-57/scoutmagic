---
id: finances
title: Suivre les finances de l'unité
summary: Le tableau de bord, les mouvements, les paiements attendus et qui voit quels comptes.
category: Espace animateurs
role_min: intendant
paths: /finance, /finance/movements, /finance/receivables
related: importer-extraits, recus, config-finance, badges
---

Le module Finances suit les comptes de l'unité à partir des extraits
bancaires importés. Le tableau de bord résume la situation : solde
actuel, solde le plus bas des derniers mois, dernier extrait importé,
graphiques d'évolution et bilan par catégorie pour l'exercice choisi.

## Qui voit quels comptes

Deux conditions, et il faut les deux.

D'abord, chaque compte porte son propre niveau de visibilité —
intendant, chef ou chef d'unité.

Ensuite, un compte rattaché à une section est réservé au **trésorier de
cette section** : la personne qui porte le badge « Trésorier » et qui
anime cette section-là, pour l'année scoute en cours. Un compte de
l'unité, lui, n'est rattaché à aucune section et ne dépend que du
premier réglage. Les chefs d'unité voient tous les comptes, quoi qu'il
arrive.

Cette règle ne s'applique **que si votre unité a attribué le badge
« Trésorier » à quelqu'un cette année**. Tant que personne ne le porte,
rien ne change et tout intendant voit tous les comptes comme avant.
Désactiver le badge dans Configuration > Badges suffit à revenir à ce
fonctionnement.

Si la page annonce « Aucun compte visible pour votre rôle », c'est l'un
de ces deux réglages qui joue : voyez avec votre équipe d'unité s'il
faut ajuster la visibilité du compte, ou vous attribuer le badge.

## Catégoriser les mouvements

La page Mouvements liste les opérations bancaires. Une ligne surlignée
est « À catégoriser » : touchez-la, choisissez la catégorie, ajoutez
au besoin un commentaire, puis « Enregistrer ». Le badge « Auto »
signale une catégorie attribuée automatiquement par une règle — la
changer à la main la rend définitive. Le trombone associe un
justificatif au mouvement, et « Exporter en XLSX » télécharge la liste
filtrée.

Le gros du travail peut se faire tout seul : des règles de
catégorisation (mots-clés, contrepartie, montant) s'appliquent à
chaque import — elles se règlent dans la configuration du module.

## Les paiements attendus

La page « Paiements attendus » suit ce que les familles doivent :
chaque ligne compare le montant attendu au montant reçu et affiche
Payé, Partiel ou Non payé. **Rien ne s'y coche à la main** : le statut
se calcule depuis les extraits importés, grâce à la communication
structurée du virement. Un paiement en plusieurs fois est additionné
automatiquement.

## Le rythme de travail

Tout part des extraits : importez régulièrement (voyez « Importer un
extrait bancaire »), catégorisez ce qui reste en attente, associez les
justificatifs — le tableau de bord signale en permanence ce qui
demande une action.
