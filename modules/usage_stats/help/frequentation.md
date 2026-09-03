---
id: frequentation
title: Lire la fréquentation du site
summary: Savoir si le site sert, et quels modules personne n'ouvre.
category: Configuration
role_min: superadmin
question: Combien de familles se connectent réellement au site ?
question: Comment savoir quel module personne n'utilise ?
question: Le site enregistre-t-il qui a consulté quelle page ?
paths: /config/usage, /config/usage/modules, /config/usage/pages
related: cookies
---

La page **Fréquentation**, sous Configuration, répond à deux questions et à
rien d'autre : le site sert-il, et qu'est-ce qui sert. Trois écrans, dans
le rail en haut de page.

## Vue d'ensemble

Quatre chiffres ouvrent l'écran. « Comptes actifs ce mois » compte les
comptes qui se sont connectés depuis le premier du mois ; « Comptes
existants » compte ceux qui pourraient le faire ; « Part des comptes
actifs » est le rapport des deux. C'est la réponse à « le site sert-il ».

Ces trois chiffres ne valent que pour le mois en cours. Le site ne
conserve que la dernière connexion de chaque compte, jamais l'historique :
pour un mois passé, la question n'a pas de réponse, et l'écran le dit
plutôt que d'afficher un nombre qui voudrait dire autre chose.

« Pages vues par mois », en dessous, couvre bien les douze derniers mois.
Un creux en juillet et en août est celui des camps.

## Modules

Le classement des modules par nombre de pages ouvertes, et surtout, en
bas, l'encadré des modules activés que personne n'a ouverts depuis douze
mois. C'est le seul constat de l'écran sur lequel vous pouvez agir : un
module inutilisé encombre les menus de tout le monde, et le désactiver
depuis Configuration ne perd aucune donnée — il se réactive à tout moment.

Un module réservé au staff porte l'étiquette « Staff ». Sans elle, trois
personnes qui ouvrent les cotisations se liraient comme un échec, alors
que trois est le nombre attendu.

## Pages

Le détail, page par page, filtrable par public avec « Anonymes »,
« Identifiés » et « Staff ». Sous chaque titre s'affiche le motif de
route — `/members/{id}` et non `/members/42`.

## Ce qui n'est pas mesuré

> Le site compte des pages, pas des personnes. Il ne conserve ni adresse
> IP, ni navigateur, ni parcours nominatif, et il ne pose aucun cookie
> pour ces mesures : c'est pourquoi elles n'apparaissent dans aucune
> catégorie des préférences de cookies.

Aucun écran ne peut donc dire qui a ouvert quelle page, ni combien de fois
une personne est revenue. Le partage de ces chiffres avec l'équipe qui
développe le logiciel se règle ailleurs, sur la page Support.

Les compteurs sont conservés trois années scoutes, puis supprimés.
