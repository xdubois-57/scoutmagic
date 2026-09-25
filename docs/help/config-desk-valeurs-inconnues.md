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

Sur une fonction ou une branche qu'il ne reconnaît pas, un import Desk ne
s'arrête pas : il crée l'entrée avec le réglage le plus prudent et
continue. C'est volontaire — refuser tout un listing pour une fonction
inédite serait pire — mais encore faut-il que quelqu'un l'apprenne.
C'est ce que fait l'encadré en tête de « Correspondances Desk ».

Une **colonne** manquante ou renommée, elle, arrête l'import et le dit
tout de suite : il n'y a rien à découvrir plus tard.

## Ce que chaque ligne veut dire

**Une fonction** a été créée au rôle le plus bas. Les personnes qui la
portent ne voient rien de plus qu'un membre ordinaire tant que vous ne
leur avez pas donné un rôle. C'est la première cause d'un animateur qui
ne voit plus rien après un import.

**Une branche** n'est pas reconnue : elle n'a pas de logo sur la page
des animés, et elle se range en dernier dans toutes les listes.

La ligne dit combien de fiches sont concernées quand il y en a — des
membres pour une fonction, des sections pour une branche — et le dit
aussi quand il n'y en a aucune.

## Ce qu'il y a à faire, et ce qu'il n'y a pas à faire

**Pour une fonction, à vous :** la ligne mène là où on la corrige,
donnez-lui un rôle, plus bas dans la page.

**Pour une branche, rien.** Un logo rendra la page des animés correcte,
mais le rang qui la range en dernier vient d'une table du logiciel.

Rien à marquer comme lu : la liste est recalculée à chaque affichage. La
ligne d'une fonction s'en va dès qu'elle reçoit un rôle ; celle d'une
branche, à la version qui l'ajoute.

## Ce qui est transmis, et ce qui ne l'est pas

Si l'envoi quotidien des statistiques est activé (Configuration >
Diagnostic), ces libellés sont signalés au mainteneur — sa seule façon de
l'apprendre. Il peut ajouter une branche dans une version ; une fonction,
non, faute de liste de fonctions connues.

> **Seul le libellé est transmis** : jamais un nom de membre, et jamais
> un nom de section. Un nom de section est choisi par votre unité et
> l'identifie, alors qu'une fonction ou une branche est un mot de la
> fédération.
