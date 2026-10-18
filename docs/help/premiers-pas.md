---
id: premiers-pas
title: Premiers pas après l'installation
summary: Les trois pages à visiter, dans l'ordre, pour qu'un site tout juste installé fonctionne correctement.
category: Configuration
role_min: superadmin
discovery: off
question: Que faire une fois le site installé ?
question: Comment faire disparaître le bandeau « Premiers pas » ?
related: modules, sante-hebergement, points-attention
---

L'assistant d'installation crée le site, sa base de données et le
premier compte superadmin. Il reste trois étapes, à faire **dans cet
ordre** : chacune prépare la suivante.

Tant qu'elles ne sont pas faites, un bandeau « Premiers pas » les
rappelle en haut de chaque page, aux superadmins seulement.

## 1. Activer les modules

La page **Configuration › Modules** choisit les fonctions du site. Six
modules sont activés d'office sur une installation neuve : Calendrier,
Actualités, Trombinoscope, Documents officiels, Statistiques et
Cotisations. Activez ceux que l'unité veut en plus, désactivez ceux dont
elle n'a pas l'usage.

Cette étape vient en premier parce que les deux suivantes en dépendent :
un module activé peut demander quelque chose à l'hébergeur, et il ajoute
ses propres points d'attention.

## 2. Vérifier la santé de l'hébergement

La page **Configuration › Maintenance** s'ouvre sur la santé de
l'hébergement : tout ce dont le site dépend chez l'hébergeur — la tâche
cron d'abord —, ce qui cesse de marcher sans chaque élément, et quoi
demander à l'hébergeur pour le corriger.

## 3. Consulter les points d'attention

La page **Points d'attention** dit ce qu'il reste à faire dans l'unité.
Sur un site tout neuf, elle peut en afficher beaucoup d'un coup, et
c'est normal. Chaque point disparaît de lui-même une fois corrigé.

## Masquer le bandeau

Le bouton **Masquer** du bandeau le retire pour tous les superadmins, et
pour de bon : les étapes concernent le site, pas un compte. Cette page
d'aide, elle, reste disponible.

Rien n'oblige à attendre d'avoir tout fini pour le masquer, et rien ne
l'empêche de rester affiché plusieurs jours : il ne se ferme qu'avec ce
bouton.
