---
id: etiquettes-paiement
title: Distribuer des étiquettes de paiement
summary: Imprimer une feuille d'étiquettes à découper, une par créance, avec son code QR de paiement.
category: Espace animateurs
role_min: intendant
paths: /finance/campaigns/*
related: campagnes, rappels, rapprochement, mes-paiements
---

Le bouton « Étiquettes », sur l'écran d'une campagne, produit une feuille
A4 de **27 étiquettes par page**, à découper le long des traits : trois
colonnes sur neuf rangées. Chaque étiquette porte le nom du membre, le
montant, l'IBAN, la communication structurée et un **code QR** que
l'application bancaire d'un parent scanne directement.

C'est fait pour la réunion d'unité. La feuille se distribue à la porte en
deux minutes, l'étiquette part dans une poche et le virement se fait le
soir même depuis un téléphone. Le rappel par courriel touche le parent
qui lit ses courriels ; l'étiquette touche l'autre.

## Ce qui est imprimé, et ce qui ne l'est pas

**Le montant imprimé est ce qui reste dû**, jamais le montant de départ.
Une créance de 45 € dont 20 € sont déjà rentrés donne une étiquette de
25 €. Une créance soldée ou abandonnée **ne produit aucune étiquette** :
la feuille ne contient que les familles qui doivent encore quelque chose,
et le compteur du bouton est celui des impayées.

Le filtre affiché à l'écran n'a donc aucun effet sur la feuille,
contrairement à l'export.

Le bénéficiaire ne figure pas sur l'étiquette : il est le même sur les
vingt-sept, et l'IBAN l'identifie déjà. Tout le reste est répété en texte
à côté du code, parce qu'un QR qui refuse de se scanner ne doit jamais
laisser quelqu'un sans moyen de payer.

## Imprimer

> Imprimez **à 100 %**, sans « ajuster à la page ». C'est ce qui garde la
> grille à ses 66 mm de large par étiquette et, surtout, le code QR à ses
> 18 mm. Un code que l'imprimante a réduit finit par ne plus se scanner.

Du papier ordinaire suffit : les traits du tableau sont les lignes de
découpe. Une feuille de plus est produite automatiquement au-delà de 27
créances.

Si le compte de la campagne n'a pas d'IBAN, le site refuse d'imprimer et
vous le dit sur la page de la campagne : une étiquette sans IBAN ne
donnerait aucun moyen de payer.
