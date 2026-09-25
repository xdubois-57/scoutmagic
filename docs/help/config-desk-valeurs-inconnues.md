---
id: config-desk-valeurs-inconnues
title: Les valeurs Desk que le site ne connaît pas
summary: Ce que l'encadré en tête de Correspondances Desk signale, ce que le site a fait à la place, et comment le corriger.
category: Configuration
role_min: superadmin
discovery: 1
question: Que veut dire « valeurs que le site ne connaît pas » ?
question: Pourquoi une branche importée n'a-t-elle pas de logo ?
question: Pourquoi des animateurs ne voient-ils rien après un import ?
paths: /config/functions
related: config-desk, import-desk
---

Un import Desk ne s'arrête jamais sur un mot qu'il ne reconnaît pas : il
crée l'entrée avec le réglage le plus prudent et continue. C'est
volontaire — un import qui refuserait tout un listing pour une fonction
inédite serait pire — mais encore faut-il que quelqu'un l'apprenne.
C'est ce que fait l'encadré en tête de « Correspondances Desk ».

## Ce que chaque ligne veut dire

**Une fonction** a été créée au rôle le plus bas. Les personnes qui la
portent ne voient rien de plus qu'un membre ordinaire tant que vous ne
leur avez pas donné un rôle. C'est la première cause d'un animateur qui
ne voit plus rien après un import.

**Une branche** n'est pas reconnue : elle n'a pas de logo sur la page
des animés, et elle se range en dernier dans toutes les listes.

Chaque ligne indique combien de fiches sont concernées et mène là où on
la corrige : donner un rôle plus bas dans la page, ou choisir un logo
pour la branche.

## L'encadré disparaît tout seul

Il n'y a rien à marquer comme lu : la liste est recalculée à chaque
affichage. Dès qu'une fonction est qualifiée ou qu'une branche est
reconnue, la ligne s'en va.

## Ce qui est transmis, et ce qui ne l'est pas

Si l'envoi quotidien des statistiques est activé (Configuration >
Diagnostic), ces libellés sont aussi signalés au mainteneur du site,
pour qu'il ajoute les correspondances manquantes dans une version
suivante — c'est la seule façon qu'il a de l'apprendre.

> **Seul le libellé est transmis** : jamais un nom de membre, et jamais
> un nom de section. Un nom de section est choisi par votre unité et
> l'identifie, alors qu'une fonction ou une branche est un mot de la
> fédération.
