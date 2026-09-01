---
id: config-notifications
title: Administrer les notifications
summary: L'état du système de notifications, les clés d'envoi et la notification test.
category: Configuration
role_min: superadmin
question: Pourquoi les notifications push n'arrivent-elles pas ?
question: Comment tester l'envoi d'une notification ?
paths: /config/notifications
related: notifications-preferences, actions-planifiees, reglages
---

Cette page vérifie que le système de notifications fonctionne pour
toute l'unité — les préférences individuelles, elles, se règlent par
chacun depuis sa page Notifications.

## L'état

La première carte résume la santé du système : les clés d'envoi sont
présentes ou non, combien d'appareils sont inscrits aux notifications
push, et combien de types de notification existent sur votre site.

Un avertissement apparaît ici si aucune tâche planifiée du serveur
n'a tourné récemment : sans elle, les notifications ne partent qu'à
l'occasion d'une visite sur le site et peuvent prendre du retard. La
ligne à installer chez l'hébergeur se trouve sur la page
« Installation & serveur ».

## Les clés d'envoi

Les notifications push reposent sur une paire de clés propre à votre
site. Elles se génèrent une fois et ne demandent ensuite aucun
entretien.

> « Régénérer les clés » déconnecte immédiatement **tous** les
> appareils inscrits : chaque membre devra réactiver les notifications
> push depuis chacun de ses appareils. Ne régénérez qu'en cas de
> problème avéré, jamais par curiosité.

## La notification test

« Envoyer une notification test » envoie un message à vos propres
appareils inscrits — et à eux seuls. Si elle arrive, la chaîne
complète fonctionne. Si rien n'arrive, vérifiez d'abord que vous avez
activé les notifications push pour cet appareil depuis « Mon compte ».

## Les réglages généraux

La plage d'heures calmes par défaut (appliquée aux membres qui n'ont
pas défini la leur) et la durée de conservation des notifications se
règlent depuis la page Réglages — le bouton en bas de page y mène.
