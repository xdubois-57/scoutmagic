---
id: securite-hebergement
title: Mises à jour et sécurité de l'hébergement
summary: Le contrôle hebdomadaire des versions de PHP, de la base de données, d'OpenSSL et de cURL chez votre hébergeur, et des failles de sécurité connues qui les concernent.
category: Configuration
role_min: superadmin
question: Comment savoir si la version de PHP de mon hébergeur a une faille de sécurité ?
question: Mon hébergeur est-il à jour ?
question: Pourquoi ai-je reçu une alerte de sécurité sur l'hébergement ?
paths: /config/maintenance
related: sante-hebergement, alertes-operationnelles, installation-serveur
---

La boîte « Mises à jour et sécurité de l'hébergement », en haut de la
page Santé de l'hébergement, dit si les logiciels que votre hébergeur
fait tourner pour le site sont à jour, et si une faille de sécurité
connue les concerne.

## Ce qui est contrôlé, et quand

Une fois par semaine, la tâche planifiée compare les versions exactes
de **PHP** — celui qui répond aux visiteurs et celui des tâches
planifiées, qui peuvent différer —, du **serveur de base de données**,
d'**OpenSSL** et de **cURL** avec trois sources publiques : php.net,
endoflife.date et la base des failles de sécurité NVD. Un outil absent
n'apparaît pas. La page affiche le dernier contrôle, daté ; l'ouvrir ne
relance rien.

## Les quatre états

- **À jour** — dernière version de sa branche, et aucune faille connue
  de gravité 7 ou plus. Pour cURL, dont aucune source ne publie les
  versions maintenues, seule l'absence de faille est vérifiée.
- **Pas à jour** — un correctif est publié pour la branche utilisée, ou
  cette branche ne reçoit plus de correctifs. Une version majeure plus
  récente ne suffit pas à rendre une branche maintenue obsolète.
- **Potentiellement vulnérable** — une faille connue de gravité 7 ou
  plus (score CVSS) concerne cette version.
- **Non vérifiable** — version inconnue, mesure trop ancienne ou source
  injoignable. Ce n'est ni un bon ni un mauvais signe.

Chaque composant pas à jour ou potentiellement vulnérable ajoute un
point au bandeau en haut de la page, une seule fois quel que soit le
nombre de failles.

## À confirmer avec l'hébergeur

Beaucoup d'hébergeurs corrigent les failles sans changer le numéro de
version. Quand c'est possible, la ligne le dit : transmettez-lui la
version, les identifiants des failles et l'action proposée, et
demandez-lui s'il a déjà appliqué ces correctifs.

## L'alerte

Les superadministrateurs reçoivent une alerte quand un composant a
besoin d'une mise à jour **et** qu'une faille de gravité 7 ou plus le
concerne. Elle ne se répète pas chaque semaine ; elle revient pour une
nouvelle faille, ou après une correction vérifiée.
