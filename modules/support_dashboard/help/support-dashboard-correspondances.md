---
id: support-dashboard-correspondances
title: Correspondances Desk à corriger
summary: Les valeurs que les installations remontent et que ce code ne reconnaît pas, et ce qu'il faut en faire.
category: Configuration
role_min: superadmin
discovery: off
question: D'où vient la liste des correspondances à corriger ?
question: Pourquoi une valeur disparaît-elle toute seule de la liste ?
question: À quoi sert la plus ancienne version affichée ?
paths: /support-dashboard/correspondances
---

Cette page n'existe que sur l'installation qui **reçoit** les rapports
des autres. Elle liste les valeurs venues de Desk qu'aucune installation
n'a su rattacher : une fonction, ou une branche.

## D'où vient la liste

De nulle part en particulier : elle est **recalculée à chaque
affichage** à partir des rapports déjà reçus. Rien n'est catalogué ici.
Cette installation fait tourner le même ScoutMagic que les autres, donc
elle reconnaît une valeur en interrogeant son propre code — et une
valeur apprise par une version suivante quitte la liste d'elle-même, dès
que ce site est mis à jour.

Trois choses seulement sont conservées, parce qu'aucun rapport ne peut
les porter : la date de première apparition, le fait qu'une valeur a déjà
été signalée, et le fait qu'elle a été écartée à la main.

## Ce que chaque colonne sert à décider

Le **nombre d'unités** dit s'il s'agit d'une faute de frappe isolée ou
d'un mot que la fédération a introduit partout.

La **plus ancienne version** qui remonte encore la valeur dit si le
travail est déjà fait : une valeur corrigée dans le code continue d'être
remontée par toutes les installations qui n'ont pas installé la version.
Sans cette colonne, on la reprend en croyant l'avoir oubliée.

La dernière ligne nomme **la table du code à compléter**. Une exception :
une **fonction** n'en a pas. Ce logiciel n'a aucune liste de fonctions
connues — chaque unité qualifie les siennes — donc aucune version ne peut
la reconnaître. La ligne dit alors qu'un mot circule, pas qu'un correctif
viendra.

## Écarter, et revenir en arrière

« Écarter » retire une valeur de la vue par défaut sans la supprimer :
elle reste consultable par le lien « Montrer les valeurs écartées », et
peut être réactivée. Ce n'est jamais une suppression — le prochain
rapport recréerait la ligne, et l'arbitrage serait à refaire chaque matin.

Une valeur jamais vue déclenche **une notification, une seule fois**, à
l'arrivée d'un rapport. Si personne n'était abonné à ce moment-là, elle
reste en attente et c'est un rapport suivant qui l'annonce. La
notification ne porte que le nombre de valeurs : les libellés se lisent
ici.
