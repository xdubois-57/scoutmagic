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

Le site exécute ses tâches d'une seule façon : la **tâche planifiée
du serveur** (« cron ») configurée chez votre hébergeur, qui réveille
le site chaque minute. C'est le seul moteur ; les visites sur le site
ne déclenchent plus rien.

Tant que ce cron tourne, l'heure de la colonne « Exécution prévue »
est tenue à la minute près. S'il s'arrête — ligne mal configurée,
panne de l'hébergeur, chemin PHP devenu invalide — **plus aucune
tâche ne part** : ni sauvegarde, ni mise à jour, ni rappel, ni
notification. Rien ne rattrape le retard tout seul, et rien ne se
déclenche à l'occasion d'une visite du site.

La ligne à installer chez l'hébergeur est donnée sur la page
« Installation & serveur », bloc « Tâche cron » ; lors d'une première
installation, le site refuse d'ailleurs de se laisser installer tant
qu'elle n'a pas réellement tourné. La page Maintenance et la page
Notifications de la configuration vous avertissent ensuite si aucun
passage récent n'est détecté.

Une précision qui évite une fausse alerte : quand une tâche longue
(une sauvegarde complète, une mise à jour) dépasse la minute, les
passages suivants attendent leur tour sans rien faire plutôt que de
travailler en même temps. Une tâche peut donc partir avec quelques
minutes de retard derrière une grosse opération — c'est normal, et
c'est ce qui protège l'hébergement.
