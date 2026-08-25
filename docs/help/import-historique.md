---
id: import-historique
title: L'historique des imports Desk
summary: Retrouver un import passé, son fichier conservé et sa durée de conservation.
category: Espace chefs d'U
role_min: admin
paths: /admin/import/historique, /admin/import/*/rapport
related: import-desk, donnees-personnelles, reglages
---

Chaque import Desk laisse une trace : sa date, la personne qui l'a
lancé, le nombre de lignes lues et de membres traités, et le fichier CSV
qui l'a produit. La page les liste par année scoute.

## Le rapport d'un import

Chaque ligne de l'historique ouvre le rapport de son import : ce qu'il a
changé, dans l'ordre des conséquences. D'abord les accès — qui vient
d'obtenir ou de perdre la Configuration, et quelles fonctions attendent
d'être qualifiées. Puis la structure — les sections devenues inactives,
les arrivées, les départs, les changements de section. Enfin la qualité
des données que Desk a livrées.

Ce rapport est **figé** : il décrit ce qui s'est passé ce jour-là et ne
changera plus. Ce qu'il reste à faire aujourd'hui vit ailleurs, sur la
page « Points d'attention ».

Le premier import d'une saison n'a rien à quoi se comparer : ses membres
sont le point de départ, pas des arrivées. Le rapport le dit.

## À quoi sert le fichier conservé

Un import douteux se réexamine en comparant son rapport au fichier exact
qui l'a produit. Sans le fichier, il reste une impression ; avec lui, on
peut vérifier ligne par ligne ce que Desk contenait ce jour-là.

Le site conserve aussi, pour chaque import, une photographie de la
composition du roster : c'est elle qui permet de vérifier une facture de
cotisation émise en novembre, même relue en février.

## Qui peut le télécharger

Ce fichier contient les données personnelles de toute l'unité : noms,
dates de naissance, adresses, téléphones, adresses email. C'est le
document le plus dense du site.

Il est chiffré sur le serveur. Seuls les chefs d'unité peuvent le
télécharger, et **chaque téléchargement est consigné au journal** — le
journal note qui et quand, jamais le contenu du fichier.

> Téléchargé, ce fichier n'est plus protégé par le site. Ce qui en est
> fait ensuite relève de vous : ne le laissez pas traîner sur un
> ordinateur partagé.

## Combien de temps

Deux années scoutes par défaut : l'année en cours et la précédente. La
durée se règle sur la page Réglages, en nombre d'années scoutes et non
en nombre d'imports — ainsi la photographie de novembre reste disponible
quel que soit le nombre d'imports faits depuis.

Passé ce délai, la saison entière disparaît d'un bloc : la ligne
d'import, le fichier et la photographie du roster partent ensemble. La
suppression est définitive et s'exécute toute seule, chaque jour, même
si vous n'importez plus.

Si un membre demande l'effacement de ses données, celles-ci figurent
aussi dans les fichiers conservés. Un CSV ne pouvant être retouché sans
perdre sa raison d'être, la réponse est la suppression du fichier
entier concerné.
