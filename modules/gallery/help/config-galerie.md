---
id: config-galerie
title: Configurer le stockage de la galerie
summary: Où vivent les photos : emplacements locaux ou S3, et l'espace disponible.
category: Configuration
role_min: superadmin
paths: /config/gallery, /config/gallery/locations/new, /config/gallery/locations/*/edit
related: galerie, gerer-la-galerie
---

Les photos et vidéos de la galerie occupent vite de la place : cette
page décide où elles sont stockées. Deux familles d'emplacements :

- **Local** : sur le disque de l'hébergement, sans rien d'autre à
  configurer. Simple, mais limité par l'espace du serveur.
- **S3** : un stockage externe compatible S3 (un fournisseur de
  stockage en ligne), avec ses accès à renseigner. L'espace n'est plus
  une contrainte, contre un abonnement chez le fournisseur.

## L'emplacement par défaut

Les nouveaux albums se créent sur l'emplacement marqué par défaut —
les animateurs ne choisissent jamais eux-mêmes. Un album existant
peut être migré vers un autre emplacement depuis cette page : pendant
la migration, l'album est indisponible, puis tout reprend sans autre
intervention.

## Surveiller l'espace

Chaque emplacement local affiche l'espace encore libre sur le disque
qui l'héberge, avec un avertissement quand il devient inférieur au
plus gros envoi que la galerie accepte.

> Ce chiffre est celui du disque entier, partagé avec tout le reste du
> site — pas une réserve dédiée à la galerie. Un disque qui se remplit
> menace aussi les sauvegardes et les mises à jour : anticipez, en
> faisant du tri ou en passant à un stockage externe.

Un emplacement S3 n'affiche rien : sa capacité est l'affaire du
fournisseur.

## Les limites d'envoi

Les tailles maximales par photo et par vidéo, le nombre de médias par
album et l'autorisation des vidéos se règlent dans la page Réglages,
groupe de la galerie. Baisser ces limites est le second levier quand
l'espace se fait rare.
