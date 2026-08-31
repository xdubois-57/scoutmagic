---
id: actions-planifiees
title: Suivre les actions planifiées
summary: Les tâches de fond du site : états, échecs et exécution.
category: Configuration
role_min: superadmin
question: Pourquoi une tâche de fond du site ne s'exécute-t-elle pas ?
question: Comment relancer une action planifiée en échec ?
paths: /config/scheduled
related: config-notifications, installation-serveur
---

Le site fait travailler des tâches en arrière-plan : envoi des
notifications, traitement des photos, purges périodiques, rappels.
La page « Actions planifiées » les liste toutes, pour comprendre ce
qui s'est passé — ou ce qui n'est pas parti.

## Lire la liste

Chaque ligne donne la fonction concernée, la tâche, l'heure
d'exécution prévue, son état et le nombre de tentatives. Les états :

- **En attente** : la tâche attend son heure.
- **En cours** : elle s'exécute.
- **Terminé** : tout s'est bien passé.
- **Échoué** : touchez la ligne pour lire le message d'erreur.
- **Annulé** : la tâche a été rendue inutile par un autre évènement.

La page se consulte uniquement : on n'y relance ni n'y annule rien.
Une tâche échouée est généralement reprogrammée par la fonction qui
l'avait créée, et les échecs notables sont aussi consignés au journal.

## Pourquoi une tâche part en retard

Le site exécute ses tâches de deux façons :

- avec une **tâche planifiée du serveur** (« cron ») configurée chez
  votre hébergeur, elles partent à l'heure, chaque minute ;
- sans cela, elles ne s'exécutent qu'à l'occasion d'une **visite sur
  le site** : sur un site peu fréquenté, un rappel prévu à 18 h peut
  partir bien plus tard, voire pas du tout.

L'heure de la colonne « Exécution prévue » est donc une intention,
pas une garantie, tant qu'aucun vrai cron n'est en place. La ligne à
installer chez l'hébergeur est donnée sur la page « Installation &
serveur », bloc « Tâche cron » ; la page Notifications de
la configuration vous avertit lorsqu'aucun passage récent n'a été
détecté.
