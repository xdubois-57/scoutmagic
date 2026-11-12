---
id: migration-galeries-photo
title: Reprendre les galeries photo de l'ancien site
summary: Les albums montrés par un lien deviennent des albums externes de la Galerie, sans notification.
category: Configuration
role_min: superadmin
discovery: off
question: Comment reprendre les albums photo de notre ancien site ?
question: Pourquoi un album de l'ancien site a-t-il été ignoré ?
paths: /config/migration-donnees/galeries
---

L'import « Galeries photo » reprend les albums que l'ancien site montrait par un lien (Google Photos, Flickr…). Chacun devient un album externe de la Galerie ; aucune photo n'est copiée. Il n'est proposé que si le module Photos est activé.

## Pendant l'import

Le titre et la vignette sont relus sur la page de chaque album, ce qui prend environ deux secondes par album : gardez la page ouverte. Si la page de l'album ne répond pas, l'album est importé avec ce que contenait le fichier. Aucune notification « Nouvel album » n'est envoyée.

## Les albums ignorés

- Un album réservé à une section que le site ne reconnaît pas est ignoré plutôt que montré à toute l'unité (« Section sans correspondance »).
- Un album sans titre, ni dans le fichier ni sur sa page, est ignoré.
- Un album d'une année scoute passée que vous n'avez pas cochée dans l'aperçu est ignoré : il n'est jamais rangé dans l'année en cours.
- Une adresse que la Galerie connaît déjà compte comme « déjà présente » ; l'album existant n'est pas modifié.

## Ensuite

Les albums importés se gèrent dans « Gérer les photos », comme les autres : ils se modifient et se suppriment normalement.
