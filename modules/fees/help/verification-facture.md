---
id: verification-facture
title: Lire le rapport de vérification d'une facture
summary: Ce que la fédération a compté, ce que Desk contenait le jour de l'émission, et nominativement où les deux divergent.
category: Espace chefs d'U
role_min: admin
discovery: 3
question: La facture de la fédération est-elle juste ?
question: Pour qui la fédération nous facture-t-elle à tort ?
paths: /admin/fees/factures/*
related: factures-federation, justesse-des-tarifs, cotisations
---

Le rapport compare la facture à la **photographie des données Desk** prise
au plus près avant son émission, et non à la situation actuelle.

## Lignes reconstituées : combien ?

Pour chaque référence et section, le tableau indique le prix unitaire,
la quantité facturée, la quantité attendue et leur écart chiffré.

La quantité attendue vient de la photographie de Desk. Elle ne vient
jamais d'un calcul de tarif de ménage : cette page vérifie le compte,
tandis que « Justesse des tarifs » vérifie les catégories encodées.

Une ligne est affichée **sans verdict** si les données ne permettent pas
de la juger : référence inconnue, section absente, photographie absente,
ajustement global ou tarif réparti sur plusieurs lignes. Le compte de
toute une section ne peut pas être comparé séparément à chacune de ces
lignes.

Une cotisation sans noms reste vérifiable en quantité lorsque son tarif
et sa section sont connus. Elle ne permet ni de désigner des personnes
manquantes ni de chiffrer les écarts nominatifs. Le rapport précise ces
limites ; une absence d'écart ne prouve pas que tout a été comparé.

Les lignes conformes sont repliées et comptées. Vous pouvez les afficher
pour rapprocher le rapport du document.

## Écarts nominatifs : qui ?

**Facturé mais parti** : la personne est facturée et marquée partante
dans Desk. Elle y compte encore ; c'est son encodage qu'il faut vérifier.

**Membre absent de la facture** : Desk contient la personne, mais aucune
ligne ne la nomme dans une section entièrement couverte par des listes.
Une section contenant aussi des récapitulatifs sans noms n'autorise pas
cette conclusion.

**Section différente** : les sections facturée et encodée diffèrent. Cet
écart n'est pas chiffré, puisque le tarif ne dépend pas de la section.

**Catégorie différente** : le tarif facturé diffère du tarif encodé.
L'incidence utilise les prix comparables de cette facture.

**Réduction breveté non appliquée** : une réduction nominative existe
pour d'autres personnes de la section, mais omet un breveté. Une facture
sans réduction, ou avec des réductions récapitulatives, ne suffit pas à
conclure à un oubli.

Une incidence positive indique une surfacturation ; négative, une
régularisation possible ultérieurement.

## Limites affichées

L'écart de dates entre photographie et facture peut expliquer un écart.
Les personnes non reconnues comptent dans les quantités, mais ne peuvent
pas être nommées dans le rapport. Ouvrez le PDF conservé pour les
identifier : la vérification ne stocke aucun nom des factures.

L'export tableur reprend les deux vues du rapport.
