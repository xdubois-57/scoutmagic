---
id: courrier-envoyes
title: Les e-mails envoyés depuis une boîte
summary: Le dossier des envoyés d'une boîte, lu pour les Locations seulement, et ce qui en est gardé.
category: Configuration
role_min: superadmin
question: Le site lit-il les e-mails envoyés depuis la boîte ?
question: Que faire si le dossier des envoyés n'est pas reconnu ?
paths: /config/courrier-entrant/boites/nouvelle, /config/courrier-entrant/boites/*/modification
related: courrier-entrant, courrier-portee, locations-courrier
---

Les Locations lisent aussi le dossier des envoyés d'une boîte ouverte à
leur module, pour montrer sur une réservation ce que l'unité a écrit au
locataire depuis cette boîte. Les autres modules ne voient jamais ces
e-mails.

## Le dossier des envoyés

Le site trouve ce dossier tout seul quand le serveur le désigne : le
test de connexion le nomme alors (« Dossier des envoyés reconnu »). Il
ne devine jamais d'après un nom. Si le test n'en reconnaît aucun,
indiquez son nom dans « Dossier des envoyés », tel qu'il apparaît dans
la liste des dossiers. Laissez le champ vide sinon.

Un dossier déjà coché dans « Dossiers surveillés » reste lu comme du
courrier reçu, et n'est pas lu une seconde fois.

## Ce qui est gardé

Comme le reste, la boîte n'est jamais modifiée. Mais à l'inverse du
courrier reçu, un e-mail envoyé n'est conservé que s'il est rattaché à
une réservation : le reste du courrier envoyé de l'unité n'entre pas sur
le site.
