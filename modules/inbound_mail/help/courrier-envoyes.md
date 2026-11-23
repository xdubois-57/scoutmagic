---
id: courrier-envoyes
title: Les e-mails envoyés depuis une boîte
summary: Le dossier des envoyés d'une boîte, lu pour les Locations seulement, et ce qui en est gardé.
category: Configuration
role_min: superadmin
question: Le site lit-il les e-mails envoyés depuis la boîte ?
question: Que faire si le dossier des envoyés n'est pas reconnu ?
paths: /config/courrier-entrant/boites/nouvelle, /config/courrier-entrant/boites/*/modification
related: courrier-entrant, locations-courrier
---

Les Locations lisent aussi le dossier des envoyés d'une boîte ouverte à
leur module, pour montrer sur une réservation ce que l'unité a écrit au
locataire depuis cette boîte. Les autres modules ne voient jamais ces
e-mails.

## Le dossier des envoyés

Le site trouve ce dossier tout seul quand le serveur le désigne : une fois
la connexion vérifiée, la liste « Dossier des messages envoyés » affiche
« Trouvé automatiquement » et son nom. Il ne devine jamais d'après un nom.
Si le serveur n'en désigne aucun, la liste vous propose les dossiers du
serveur : choisissez celui que votre webmail utilise pour les messages
envoyés, ou laissez « Aucun ». Un nom ne se tape jamais.

Un dossier déjà coché parmi les dossiers lus comme du courrier reçu ne
peut pas être choisi en plus comme dossier des envoyés.

## Ce qui est gardé

Comme le reste, la boîte n'est jamais modifiée. Mais à l'inverse du
courrier reçu, un e-mail envoyé n'est conservé que s'il est rattaché à
une réservation : le reste du courrier envoyé de l'unité n'entre pas sur
le site.
