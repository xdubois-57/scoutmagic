---
id: finances
title: Suivre les finances de l'unité
summary: Le tableau de bord, les mouvements, et qui voit quels comptes.
category: Espace animateurs
role_min: intendant
question: Où voir combien il reste sur les comptes de l'unité ?
question: Qui a le droit de voir quel compte bancaire ?
paths: /finance, /finance/movements
related: controle-des-creances, rapprochement, importer-extraits, recus, config-finance, badges, outils-finance
---

Le module Finances suit les comptes de l'unité à partir des extraits
bancaires importés. Le tableau de bord tient en deux lignes de quatre
vignettes, suivies des graphiques et du bilan par catégorie de
l'exercice choisi.

La première ligne dit où en est le compte : solde actuel, solde le
plus bas des 18 derniers mois, dernier extrait importé, et paiements
encore attendus — ce qui reste dû sur les créances de ce compte, pas
le montant demandé au départ.

La seconde dit ce qui attend un geste, et chaque vignette mène à
l'écran où il se fait : les mouvements non catégorisés de l'exercice,
les reçus qui ne sont associés à aucun mouvement, les paiements à
traiter (voir « Paiements à traiter ») et les campagnes encore ouvertes
sur ce compte cette année scoute. Les listes ouvertes ainsi ne montrent
que ce compte. Quand tout est fait, ces compteurs affichent 0.

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
Désactiver le badge dans Espace chefs d'U > Badges suffit à revenir à ce
fonctionnement.

Si la page annonce « Aucun compte visible pour votre rôle », c'est l'un
de ces deux réglages qui joue : voyez avec votre équipe d'unité s'il
faut ajuster la visibilité du compte, ou vous attribuer le badge.

## Catégoriser les mouvements

La page Mouvements liste les opérations bancaires, chacune décrite en
clair : la créance qu'elle règle, le reçu, la contrepartie, la
catégorie. Une ligne surlignée
est « À catégoriser » : touchez-la, choisissez la catégorie, ajoutez
au besoin un commentaire, puis « Enregistrer ». Le badge « Auto »
signale une catégorie attribuée automatiquement par une règle — la
changer à la main la rend définitive. Les détails d'un paiement
listent les créances qu'il règle, avec un lien vers chacune. Le
trombone associe un justificatif, et « Exporter en XLSX » télécharge
la liste filtrée.

Le gros du travail peut se faire tout seul : des règles de
catégorisation (mots-clés, contrepartie, montant) s'appliquent à
chaque import — elles se règlent dans la configuration du module.

## Le rythme de travail

Tout part des extraits : importez régulièrement (voyez « Importer un
extrait bancaire »), catégorisez ce qui reste en attente, associez les
justificatifs — le tableau de bord signale en permanence ce qui
demande une action.
