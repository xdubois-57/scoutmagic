---
id: sante-hebergement
title: Santé de l'hébergement
summary: La page d'accueil de la maintenance : la tâche cron du serveur et le résultat des mises à jour automatiques.
category: Configuration
role_min: superadmin
question: Comment savoir si la tâche cron du site tourne ?
question: Où voir si la dernière mise à jour automatique a réussi ?
paths: /config/maintenance
related: mises-a-jour, actions-planifiees, installation-serveur
---

La maintenance du site est rangée en six pages, reliées par la rangée
d'onglets en haut de l'écran : Santé de l'hébergement, Mise à jour,
Sauvegarde manuelle, Sauvegarde automatique, Sauvegardes récentes et
Réinitialisation. Cette page-ci est la première, celle qui s'ouvre
depuis le menu Configuration.

## La tâche cron

Le site a besoin que l'hébergeur le réveille régulièrement : c'est la
tâche cron. Sans elle, rien ne se passe en dehors des visites — ni
sauvegarde automatique, ni mise à jour, ni rappel, ni notification — et
aucune erreur ne le signale.

La page dit donc si la tâche a été vue récemment, et à quel rythme. Si
elle n'a jamais été détectée, ou plus depuis un moment, elle affiche la
ligne exacte à ajouter chez votre hébergeur, dans la rubrique « Tâches
planifiées » ou « Cron ». Le mot `php` au début de la ligne est
obligatoire.

## Les mises à jour automatiques

La page rappelle aussi la date de la dernière mise à jour installée
automatiquement. Quand la dernière tentative a échoué, elle le dit en
rouge et renvoie vers la page Mise à jour, où l'historique détaille ce
qui s'est passé.

## Une migration restée incomplète

Si une mise à jour a dû abandonner une modification de la base de
données, un bandeau rouge le signale en haut de chacune des pages de
maintenance, avec les instructions concernées. Il disparaît de lui-même
dès qu'une migration suivante se termine sans erreur.
