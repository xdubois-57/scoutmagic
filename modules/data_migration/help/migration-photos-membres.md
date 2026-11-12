---
id: migration-photos-membres
title: Reprendre les photos des membres de l'ancien site
summary: Une photo par membre et par année scoute, rattachée par l'identifiant Desk, sans jamais remplacer une photo existante.
category: Configuration
role_min: superadmin
discovery: off
question: Comment importer les photos des animés de notre ancien site ?
question: L'import des photos remplace-t-il les photos déjà présentes ?
question: Pourquoi une photo importée est-elle ignorée ?
paths: /config/migration-donnees/photos
---

« Photos des membres » reprend, depuis un fichier ZIP préparé sur l'ancien site, les photos des membres pour les années passées. Elles apparaissent aussitôt au trombinoscope et sur les fiches, comme une photo ajoutée à la main.

## Avant de commencer

Importez d'abord l'export Desk de l'unité : chaque photo est rattachée à un membre par son identifiant Desk, et une photo dont le membre est inconnu du site est ignorée. Aucun membre n'est créé par l'import. Une photo envoyée sous l'ancien identifiant d'une fiche fusionnée rejoint la fiche actuelle.

## Ce qui n'est jamais remplacé

- Un membre qui a déjà une photo pour une année la garde : la photo du fichier est comptée dans « Déjà présents ».
- Si le fichier contient plusieurs photos pour le même membre et la même année, seule la première lisible est prise.
- Les photos de compte, les photos de section et les albums ne sont pas touchés.

## Les années passées

Si des photos visent une année passée que le site n'a pas encore, l'aperçu la propose : cochez celles à créer. Les photos d'une année non cochée sont ignorées.

## Les photos ignorées

Le bilan donne, pour chaque photo ignorée, l'identifiant Desk, l'année et le motif, jamais un nom : membre introuvable, image illisible ou trop lourde, type d'image non accepté. Une image se corrige sur l'ancien site : préparez alors une nouvelle archive et déposez-la, les photos déjà importées ne le seront pas une deuxième fois. « Relancer le même fichier » relit l'archive déjà déposée : il sert quand c'est ScoutMagic qui a changé, par exemple un membre ajouté depuis.
