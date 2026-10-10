---
id: rapprochement
title: Paiements à traiter
summary: Les paiements que le site n'a pas su rattacher seul, rangés en quatre situations, et le geste qui convient à chacune.
category: Espace animateurs
role_min: intendant
discovery: 3
question: Un parent a payé pour trois enfants en un virement, que faire ?
question: Un virement porte une communication que le site ne reconnaît pas, que faire ?
question: Comment rembourser une famille qui a payé en trop ?
paths: /finance/reconciliation, /finance/receivables/*/qr
related: campagnes, finances, importer-extraits, controle-des-creances
---

Le site rattache tout seul un virement dont la communication structurée
désigne une créance qu'il connaît. « Paiements à traiter » réunit
**uniquement** ce qui attend ensuite une décision de votre part. Une
situation n'apparaît que dans **une seule** des quatre rubriques et
chacune a une issue : quand tout est traité, la page est vide et la
vignette du tableau de bord affiche 0.

Un mouvement **sans communication structurée** n'est pas un paiement à
traiter : il reste dans « Mouvements », comme tout mouvement ordinaire.

## À répartir

Un virement unique qui couvre plusieurs créances du même foyer. Le site
propose la répartition, vous corrigez si besoin, puis vous confirmez.
Tant qu'une répartition est proposée, le surplus n'apparaît pas aussi en
trop-perçu.

## Non imputés

Un crédit qui porte une **communication structurée valide** ne
correspondant à aucune créance, sur aucun compte. Deux issues :

- le **rattacher** à une créance, en tapant le nom du membre ;
- indiquer que **ce paiement ne correspond pas à une créance
  ScoutMagic**. Cette décision est conservée : le crédit ne reviendra
  pas au prochain import, et il reste visible dans « Mouvements ».

## Trop-perçus

Une créance qui a reçu plus que son montant, quand ce surplus n'est pas
déjà proposé à la répartition. Deux réponses : le déclarer à rembourser
— il passera à « remboursé » quand le débit correspondant apparaîtra —
ou l'imputer sur une autre créance du même foyer, souvent la bonne
réponse : un parent qui arrondit veut payer, pas se faire rembourser
6,75 €.

## Mauvais compte

Un paiement dont la communication désigne une créance d'un **autre
compte** — jamais aussi « Non imputé ». **Rien ne s'impute à
distance** : l'argent doit physiquement arriver sur le bon compte.

Faites un vrai virement entre les deux comptes, en **reprenant la
communication d'origine** — sans elle, le crédit qui arrive ne se
rattache à rien. Catégorisez le débit d'un côté et le crédit de l'autre
comme un **mouvement interne**, faute de quoi l'unité compte deux fois
la même recette.

> Les deux signalements restent affichés tant que le virement n'est pas
> fait **et** que les deux comptes n'ont pas été réimportés. Entre les
> deux, ce n'est pas un défaut.

## Montrer le QR à un parent

Sur une créance impayée ou partielle, « Afficher le QR » ouvre un code
plein écran, à faire scanner avec l'application bancaire du parent. Il
demande ce qui **reste** à payer, jamais le montant de départ. Le nom du
membre reste affiché : c'est ce qui évite de montrer la créance du
voisin.
