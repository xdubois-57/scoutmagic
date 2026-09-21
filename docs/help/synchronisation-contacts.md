---
id: synchronisation-contacts
title: Synchronisation des contacts
summary: Le coupe-circuit du site et la liste de tous les appareils autorisés.
category: Configuration
role_min: superadmin
question: Comment arrêter la synchronisation des contacts pour tout le site ?
question: Qui synchronise le carnet d'adresses de l'unité ?
paths: /config/synchronisation-contacts
related: appareils-synchronises, donnees-personnelles
---

Cette page répond à deux questions qu'un superadministrateur doit
pouvoir trancher seul : **qui recopie les coordonnées du staff**, et
**comment arrêter tout le monde d'un coup**.

## Le coupe-circuit

« Couper la synchronisation » arrête immédiatement toutes les
synchronisations du site, sans avoir à révoquer les appareils un par
un. Les appareils restent enregistrés ; ils ne reçoivent plus rien.

Couper n'efface rien : les copies déjà descendues restent sur les
appareils. C'est une fonctionnalité qui réplique des données
personnelles, et son coupe-circuit sert à arrêter la réplication, pas à
la rattraper.

« Réactiver la synchronisation » remet tout en marche.

## Les appareils autorisés

Tous comptes confondus, avec leur propriétaire et leur dernière
synchronisation. Un appareil enregistré et jamais synchronisé est
signalé comme tel : c'est un identifiant valide qui traîne, pas une
ligne morte.

« Révoquer » arrête cet appareil-là, quel que soit le compte auquel il
appartient. C'est le seul endroit du site où l'on peut révoquer
l'appareil de quelqu'un d'autre.

## Ce que cela ne remplace pas

Un identifiant d'appareil ne s'autorise jamais tout seul : le rôle du
compte est recalculé à chaque requête, et un chef qui quitte le staff
perd la synchronisation au passage suivant, sans révocation manuelle.
Cette page sert aux cas que ce mécanisme ne couvre pas — un téléphone
perdu, un doute, une demande d'un parent.

> Chaque création, chaque révocation et chaque échec d'authentification
> est consigné au journal du site. Les synchronisations réussies ne le
> sont pas : elles arrivent toutes les quelques minutes et noieraient
> tout le reste.
