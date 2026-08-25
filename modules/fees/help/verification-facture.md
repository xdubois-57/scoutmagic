---
id: verification-facture
title: Lire le rapport de vérification d'une facture
summary: Ce que la fédération a compté, ce que Desk contenait le jour de l'émission, et nominativement où les deux divergent.
category: Espace chefs d'U
role_min: admin
paths: /admin/fees/factures/{id}
related: factures-federation, justesse-des-tarifs, cotisations
---

Le rapport compare une facture à la **photographie du roster** prise à
l'import Desk le plus proche avant son émission — pas à la situation
d'aujourd'hui, mais à ce que Desk contenait le jour où la fédération a
facturé.

## « Lignes reconstituées » — combien

Pour chaque référence et chaque section : le prix unitaire, la quantité
facturée, la quantité que la photographie contenait, et l'écart chiffré.

**La quantité attendue vient de la photographie, jamais d'un calcul de
tarif.** C'est la différence entre cette page et « Justesse des tarifs » :
l'une vérifie le compte, l'autre les catégories. Les confondre produirait
un écran qui reproche à la fédération une erreur commise par l'unité.

Une ligne que le site ne sait pas juger est affichée **sans verdict** :
référence inconnue, ligne sans section, ajustement global comme la
déduction de l'acompte. Le silence n'est pas une accusation.

Les lignes conformes sont repliées et comptées, jamais supprimées : sans
elles, le rapport ne se rapproche plus du document papier.

## « Écarts nominatifs » — qui

Cinq types, chacun désignant une action différente.

**Facturé mais parti** — la fédération facture quelqu'un que Desk marque
comme partant. Elle n'a pas tort : Desk le contient encore. C'est Desk
qu'il faut corriger, et l'argent est réel jusque-là.

**Membre absent de la facture** — Desk le contient, dans une section que
cette facture couvre, sur un tarif de foyer, et aucune ligne ne le nomme.
L'unité est sous-facturée ; la régularisation le rattrapera.

**Section différente** — facturé sous une section, inscrit dans une autre.
**Cela ne coûte rien**, le tarif étant identique de part et d'autre : aucun
montant n'est donc affiché. C'est signalé parce qu'une section qui a dérivé
mérite d'être connue, pas parce que c'est de l'argent.

**Catégorie différente** — facturé sur un autre tarif de foyer que celui
encodé. L'incidence est la différence entre les deux, lue sur les prix de
cette facture-là.

**Réduction breveté non appliquée** — la photographie indique un brevet et
aucune ligne de réduction ne nomme la personne, sur un document qui
l'applique à d'autres. Une facture qui n'en applique aucune n'est pas une
facture qui a oublié.

Une incidence positive : l'unité a été facturée **plus** que le roster ne le
justifie. Négative : elle le sera plus tard.

## Deux avertissements qui comptent

**L'écart de dates.** Si la photographie précède ou suit l'émission de
plusieurs jours, tout ce qui a été encodé entre les deux apparaîtra comme un
écart sans en être un. Le nombre de jours est affiché pour cette raison.

**Les personnes non reconnues.** Un nom que le site n'a pas rapproché d'un
membre compte dans les quantités mais n'apparaît dans aucun écart nominatif
— le site ne conserve aucun nom des factures. Ouvrez le PDF conservé pour
les identifier.

L'export tableur reprend les deux onglets, dans l'ordre exact de leurs
colonnes.
