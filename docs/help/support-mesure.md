---
id: support-mesure
title: Mesurer une lenteur du site
summary: Cinq minutes pendant lesquelles chaque page servie est chronométrée, pour le support.
category: Configuration
role_min: superadmin
question: Le site est lent, comment le montrer au support ?
question: Que mesure le bouton « Mesurer une lenteur » ?
paths: /config/support
related: support, journal
---

Quand le site paraît lent, le bloc « Mesurer une lenteur » de la page
Support enregistre, pendant cinq minutes, le détail de chaque page
servie : le temps passé dans chacune de ses étapes et le nombre de
requêtes à la base de données. C'est ce qui permet à celui qui vous
dépanne de dire **quelle** page est lente et **où** elle perd son temps,
au lieu de le deviner.

## Ce qui est enregistré, et pour qui

La mesure vaut **pour tous les comptes**, pas seulement le vôtre : la
page lente est souvent celle d'un animateur ou d'un parent, pas celle du
super-administrateur qui a appuyé sur le bouton. Le site le dit avant de
commencer et demande une confirmation.

Seul le **chemin** des pages est enregistré, jamais leur contenu ni
leurs paramètres. Les enregistrements vont dans le journal des
évènements et s'arrêtent d'eux-mêmes au bout de cinq minutes, ou après
cinq cents pages ; le bouton « Arrêter la mesure » les arrête avant.

## Comment s'en servir

1. Appuyez sur « Mesurer une lenteur » et confirmez.
2. Pendant les cinq minutes, parcourez les pages qui vous paraissent
   lentes — vous, ou la personne qui s'en plaint.
3. Revenez sur la page Support : elle dit combien de pages ont été
   enregistrées.
4. Générez un **nouveau** paquet de support, puis joignez-le à un
   ticket. C'est l'archive qui emporte les mesures, sous la rubrique
   « chronologies de requêtes » ; celle générée la veille ne les a pas.

## Ce que la mesure ne voit pas

Elle ne mesure que le serveur, entre l'arrivée de la demande et l'envoi
de la page. Une lenteur du réseau, de l'appareil ou de l'application
installée sur le téléphone n'y apparaît pas : si c'est ce que vous
observez, dites-le dans le ticket, la mesure ne le dira pas à votre
place.
