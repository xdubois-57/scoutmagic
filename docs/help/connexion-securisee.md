---
id: connexion-securisee
title: « Connexion non sécurisée »
summary: Ce que dit cette alerte, d'où vient l'observation, et comment elle s'éteint.
category: Configuration
role_min: admin
discovery: off
question: Pourquoi le site signale-t-il une connexion non sécurisée ?
question: Comment faire disparaître l'alerte « connexion non sécurisée » ?
question: Que régler quand l'hébergeur gère le certificat devant le site ?
paths: /config/maintenance
related: alertes-operationnelles, installation-serveur
---

ScoutMagic doit toujours être servi en HTTPS. Quand un navigateur d'un
utilisateur connecté charge le site **sans connexion sécurisée**, il le
signale, et l'alerte « ScoutMagic a récemment été consulté depuis une
connexion non sécurisée » apparaît dans les Points d'attention, dans les
notifications et sur la page Santé de l'hébergement.

Les mots de passe et les données des membres ont alors pu circuler en
clair, et n'importe quel réseau traversé a pu les lire.

## D'où vient l'observation

C'est le navigateur qui la fait, pas le serveur. Il sait comment il a
chargé la page : en HTTPS, ou non. Le site ne déduit plus rien de ce
qu'il voit lui-même.

C'est ce qui évite la fausse alerte d'autrefois sur les hébergements qui
gèrent le certificat **devant** le site (un proxy, un CDN, le panneau de
l'hébergeur) : le visiteur y est en HTTPS de bout en bout, même si le
serveur reçoit la requête en interne sans chiffrement. Dans ce cas, il
n'y a **rien à régler** : la protection des cookies et l'en-tête qui
impose HTTPS au navigateur sont envoyés de toute façon.

## Ce qu'il faut vérifier

- Le certificat HTTPS est-il actif chez votre hébergeur ? C'est gratuit
  chez la plupart d'entre eux.
- L'adresse sans HTTPS renvoie-t-elle vers la version sécurisée ? Un
  ancien lien, un favori ou une adresse tapée à la main peuvent encore
  pointer vers la version non sécurisée.
- Le lien d'un e-mail ou d'un document mène-t-il vers une adresse sans
  HTTPS ?

## Comment l'alerte s'éteint

Elle reste active **24 heures** après le dernier accès non sécurisé
observé, puis s'éteint d'elle-même. Un accès en HTTPS entre-temps ne
l'efface pas : il faut une journée entière sans nouvel accès non
sécurisé.

Pour suivre la situation, regardez **ce qu'elle affiche** :
« dernier accès non sécurisé », suivi de son âge — il y a moins d'une
heure, puis un décompte en heures. Si l'âge grandit, c'est réglé. S'il revient à
moins d'une heure, quelqu'un atteint encore le site sans chiffrement.

La mesure ne se rafraîchit qu'une fois par quart d'heure : revenez un peu
plus tard.
