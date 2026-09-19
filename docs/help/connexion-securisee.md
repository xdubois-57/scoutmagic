---
id: connexion-securisee
title: « Le site est servi en HTTP »
summary: Ce que dit cette alerte, et le cas fréquent où elle se trompe sans avoir tort.
category: Configuration
role_min: admin
discovery: off
question: Pourquoi le site dit-il qu'il est servi en HTTP alors qu'il est en HTTPS ?
question: Comment faire disparaître l'alerte « connexion non sécurisée » ?
question: Que faire quand un proxy ou un CDN gère le certificat du site ?
paths: /config/maintenance
related: alertes-operationnelles, installation-serveur
---

Le site surveille comment les visiteurs l'atteignent. Quand une requête
lui parvient **sans chiffrement**, il prévient les administrateurs : les
mots de passe et les données des membres circulent alors en clair, et
n'importe quel réseau traversé peut les lire.

Cette alerte a deux causes possibles, et elles n'appellent pas du tout le
même geste.

## Premier cas : il n'y a pas de certificat

C'est le cas simple, et le plus grave. Activez le certificat HTTPS chez
votre hébergeur — c'est gratuit chez la plupart d'entre eux. L'alerte
s'éteint d'elle-même quand plus aucune requête n'arrive en clair.

## Second cas : quelque chose gère le HTTPS devant le site

Beaucoup d'hébergements placent un intermédiaire devant l'application :
un proxy, un répartiteur de charge, un CDN, ou simplement le panneau de
l'hébergeur. **C'est lui qui porte le certificat.** Il déchiffre la
requête du visiteur, puis la transmet à l'application en interne, en
clair.

Le visiteur est bel et bien en HTTPS de bout en bout. L'application, elle,
ne voit que la dernière étape — et cette étape n'est pas chiffrée. Elle
signale donc ce qu'elle observe, et ce qu'elle observe est exact.

Un signe qui ne trompe pas : si une adresse en HTTP bascule toute seule
vers la version sécurisée, cette redirection ne vient pas de ScoutMagic,
qui n'en fait aucune. Elle vient forcément de quelque chose placé devant
lui.

### Ce qu'il faut faire alors

Le site sait fonctionner ainsi, mais il ne le suppose jamais de lui-même :
il faut l'y autoriser. Le réglage qui l'autorise à croire l'intermédiaire
est dans le fichier de configuration du site, sur votre serveur — celui
que vous avez rempli à l'installation. Il y est décrit à l'endroit où il
se trouve. Activez-le, puis rechargez une page sécurisée. L'alerte ne
s'éteint pas dans la foulée : voyez plus bas.

> Il est désactivé par défaut, et ce n'est pas une précaution excessive.
> Sans un intermédiaire qui garantisse l'information à chaque requête,
> n'importe quel visiteur pourrait prétendre arriver en HTTPS — le site
> le croirait, et les protections qui en dépendent tomberaient. Ne
> l'activez que si un intermédiaire est réellement en place.

## Comment savoir dans quel cas je suis

N'activez pas le réglage pour voir : sans intermédiaire qui garantisse
l'information, l'activer ouvre la porte décrite ci-dessus — que vous
naviguiez vous-même en HTTPS n'y change rien. Le signe donné plus haut
tranche déjà. Sinon, posez la question à votre hébergeur : elle se répond
en une phrase.

## Vérifier que c'est réglé

Ne guettez pas la disparition de l'alerte : il lui faut environ une
journée entière sans la moindre requête en clair. Regardez **ce qu'elle
affiche** sur la page Points d'attention.

- « en clair à l'instant » : le site est encore atteint sans chiffrement.
- « en clair il y a moins d'une heure », puis un décompte en heures :
  l'âge grandit, c'est réglé — l'alerte s'éteindra seule.

La mesure ne se rafraîchit qu'une fois par quart d'heure : revenez un peu
plus tard.
