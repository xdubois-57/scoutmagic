---
id: justesse-des-tarifs
title: Corriger les tarifs de cotisation dans Desk
summary: Les foyers dont la catégorie encodée ne correspond pas au nombre de personnes qui y vivent.
category: Espace chefs d'U
role_min: admin
discovery: 1
question: Quelles familles paient un tarif qui ne correspond pas ?
question: Comment corriger une catégorie de cotisation mal encodée ?
question: Où trouver les montants de cotisation de la fédération ?
paths: /admin/fees/tarifs
related: cotisations, import-desk, membres-admin
---

La fédération facture ce que Desk contient. Cette page compare, pour
chaque adresse, la catégorie tarifaire encodée dans Desk au nombre de
personnes que Desk y place. Vous corrigez ensuite dans Desk : le site n'y
écrit jamais.

Chaque foyer porte une phrase qui explique le verdict — « 3 membres dans
Desk — tarif attendu Famille » — puis la comparaison, personne par
personne : le code Desk encodé, le code attendu, et l'état — **Conforme**,
**À corriger** ou **Non comparé**. Rien n'est barré. Le nom de chaque
membre ouvre sa fiche. Le code attendu est celui que le site utilise pour
calculer ; si aucun code Desk n'est associé au tarif attendu, la carte le
dit et ne montre aucun code.

## Deux onglets, deux moments

**À corriger dans Desk** rassemble les foyers qui ne correspondent pas à
ce que Desk contient aujourd'hui. C'est ce qui pèse sur la prochaine
facture, et c'est chiffré en euros dès que vous avez renseigné le barème.

**À prévoir** rassemble les foyers corrects aujourd'hui, qui changeront
quand un départ ou une arrivée déjà annoncés seront encodés dans Desk.
N'y touchez pas maintenant : Desk contient encore l'ancienne composition,
et c'est celle-là que la fédération facture. Chaque carte nomme ce qui la
fera basculer.

Un foyer peut apparaître dans les deux : corriger maintenant puis à la
bascule fait deux modifications ; attendre n'en fait qu'une, au prix d'une
facture intermédiaire inexacte.

## Ignorés, et sans adresse

**Ignorer ce foyer** met une adresse de côté avec la raison que vous
écrivez : une garde alternée, une colocation, deux familles au même
numéro. Le foyer réapparaît si sa composition change — la décision
portait sur ces personnes-là.

**Sans adresse** compte les membres dont l'adresse est inexploitable : ni
conformes ni en écart, le site ne peut rien dire de leur foyer.

## Le barème

Replié en haut de la page. Trois montants, un par catégorie, qui ne servent
qu'à traduire un écart en euros. Sans eux, un écart s'affiche sans montant
plutôt qu'avec un montant faux.

Tant que le barème n'a jamais été enregistré, le site pré-remplit les trois
champs avec les montants fédéraux livrés avec lui, s'ils portent sur
l'année de l'écran, avec la date du relevé et un lien vers la fédération.
Vérifiez, puis « Enregistrer le barème » : rien n'est enregistré avant.

Si un connecteur IA est configuré, **Chercher les montants** lit la page
des cotisations de la fédération et remplit les champs de même, sans rien
enregistrer ; une autre année que celle de l'écran ne remplit rien.
L'adresse de la page se corrige dans Configuration > Réglages.

Le site devine lequel de vos tarifs Desk signifie « couple » ou
« famille » ; le sélecteur n'est là que s'il se trompe. Un membre sur un
autre tarif — animateur, réduit, iAM — n'est jamais comparé.

> Un écart positif (« à déclarer en plus ») reviendra dans la facture de
> régularisation : c'est le plus urgent. Un écart négatif (« déclaré en
> trop ») veut dire que l'unité déclare plus qu'elle ne doit.
