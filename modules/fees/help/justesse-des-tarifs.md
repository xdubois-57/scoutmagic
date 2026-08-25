---
id: justesse-des-tarifs
title: Corriger les tarifs de cotisation dans Desk
summary: Les foyers dont la catégorie encodée ne correspond pas au nombre de personnes qui y vivent.
category: Espace chefs d'U
role_min: admin
paths: /admin/fees/tarifs
related: cotisations, import-desk, membres-admin
---

La fédération facture ce que Desk contient. Cette page compare, pour
chaque adresse, la catégorie tarifaire encodée dans Desk au nombre de
personnes que Desk y place. Vous corrigez ensuite dans Desk : le site n'y
écrit jamais.

Chaque foyer porte une phrase qui explique le verdict — « 3 membres dans
Desk — tarif attendu Famille » — puis la comparaison, personne par
personne : ce qui est encodé, barré, et ce qui est attendu.

## Deux onglets, deux moments

**À corriger dans Desk** rassemble les foyers qui ne correspondent pas à
ce que Desk contient aujourd'hui. C'est ce qui pèse sur la prochaine
facture, et c'est chiffré en euros dès que vous avez renseigné le barème.

**À prévoir** rassemble les foyers corrects aujourd'hui, qui changeront
quand un départ ou une arrivée déjà annoncés seront encodés dans Desk.
N'y touchez pas maintenant : Desk contient encore l'ancienne composition,
et c'est celle-là que la fédération facture. Chaque carte nomme ce qui la
fera basculer.

Un foyer peut apparaître dans les deux. La page vous le dit et vous laisse
choisir : corriger maintenant puis à la bascule fait deux modifications ;
attendre n'en fait qu'une, au prix d'une facture intermédiaire inexacte.

## Ignorés, et sans adresse

**Ignorer ce foyer** met une adresse de côté avec la raison que vous
écrivez : une garde alternée, une colocation, deux familles au même
numéro. Le foyer réapparaît si sa composition change — la décision
portait sur ces personnes-là.

**Sans adresse** compte les membres dont l'adresse est inexploitable. Ils
ne sont ni conformes ni en écart : le site ne peut rien dire de leur
foyer, et le résumé ne les compte pas comme vérifiés.

## Le barème

Replié en haut de la page. Trois montants, un par catégorie, saisis à la
main, qui ne servent qu'à traduire un écart en euros. Sans eux, un écart
s'affiche sans montant plutôt qu'avec un montant faux.

Le site devine tout seul lequel de vos tarifs Desk signifie « couple » ou
« famille » ; le sélecteur n'est là que s'il s'est trompé. Un membre sur
un autre tarif — animateur, réduit, iAM — n'est jamais comparé, ni signalé
comme une erreur.

> Un écart négatif veut dire que l'unité déclare plus qu'elle ne doit. Un
> écart positif reviendra dans la facture de régularisation : c'est le
> plus urgent des deux.
