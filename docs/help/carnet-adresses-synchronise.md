---
id: carnet-adresses-synchronise
title: Carnet d'adresses synchronisé
summary: Connecter un téléphone au carnet des animateurs, et comprendre pourquoi cela échoue parfois.
category: Mon compte
role_min: admin
question: Quelle adresse donner à mon carnet d'adresses ?
question: Pourquoi mon téléphone n'arrive-t-il pas à se connecter ?
question: Puis-je corriger un numéro depuis mon téléphone ?
paths: /account/devices
related: appareils-synchronises, mon-compte
---

Une fois l'appareil enregistré (« Appareils synchronisés »), il reste à
le dire à l'application qui tient votre carnet d'adresses. Le site parle
**CardDAV**, la norme que comprennent iOS, Android, Thunderbird et la
plupart des gestionnaires de contacts.

## L'adresse à donner

Ajoutez un compte de type **CardDAV** et renseignez :

- **Serveur** : l'adresse du site, telle que la page « Appareils
  synchronisés » l'affiche. Le domaine suffit — l'application trouve le
  reste toute seule.
- **Nom d'utilisateur** : votre adresse e-mail.
- **Mot de passe** : celui qui ne vous a été montré qu'une seule fois, à
  l'enregistrement de l'appareil.

Si votre application réclame une adresse complète plutôt qu'un domaine,
la même page en affiche une, à copier telle quelle.

## Les fiches descendent, rien ne remonte

Le carnet est en **lecture seule**. Une correction faite dans votre
téléphone ne parvient jamais au site, et sera écrasée à la
synchronisation suivante : la source de vérité reste Desk. Pour corriger
une coordonnée, passez par Desk — la correction redescendra ensuite
toute seule.

## Si la connexion échoue

Avant de soupçonner le mot de passe, utilisez le bouton **« Vérifier que
cet hébergement laisse passer la synchronisation »**, sur la page
« Appareils synchronisés ».

Certains hébergements mutualisés refusent les requêtes particulières
qu'utilise un carnet d'adresses, avant même qu'elles n'atteignent le
site. Vu du téléphone, cela ressemble exactement à un mot de passe
erroné, et rien ne permet de les distinguer.

Le bouton tranche : s'il annonce que l'hébergement bloque, le site est
correctement configuré, et c'est à votre hébergeur qu'il faut demander
d'autoriser les méthodes **`PROPFIND`** et **`REPORT`** — en ces termes,
qui sont ceux qu'il comprendra.

> Si la synchronisation a été coupée pour tout le site par un
> superadministrateur, la page « Appareils synchronisés » vous le dit en
> haut, et aucun appareil ne se synchronisera tant qu'elle ne sera pas
> réactivée.
