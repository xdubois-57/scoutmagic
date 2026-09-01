---
id: camps-carte
title: La carte des lieux de camp
summary: Où sont les terrains, comment leurs coordonnées arrivent, et ce qui part vers OpenStreetMap.
category: Espace animateurs
role_min: chief
question: Où voir nos lieux de camp sur une carte ?
question: Pourquoi un terrain n'apparaît-il pas sur la carte ?
paths: /chefs/camps/lieux/*
related: camps, config-camps
---

La carte montre **les lieux**, pas les séjours : un terrain où l'unité est
allée quatre fois est une seule épingle. Touchez-en une pour ouvrir une
petite fiche, puis le lieu.

Elle est **dépliée par défaut**, et se charge donc avec la page. Cela a un
coût qu'il faut connaître : afficher une carte télécharge son fond depuis
les serveurs d'OpenStreetMap, qui reçoivent alors votre adresse IP.

Le bouton « Carte » la replie, et **votre choix est retenu** d'une visite à
l'autre : repliée, elle ne contacte plus rien tant que vous ne la rouvrez
pas. Ce souvenir est rangé dans le stockage local de votre navigateur, et
n'y est écrit que si vous avez accepté les cookies fonctionnels — sinon
rien n'est retenu et la carte est dépliée à chaque visite.

## D'où viennent les coordonnées

**Automatiquement**, à partir de l'adresse du lieu. Le site interroge
Nominatim (OpenStreetMap) en tâche de fond, **un lieu à la fois** — le
service impose une requête par seconde maximum. Sur une installation sans
véritable cron, cela peut prendre du temps ; ce n'est pas grave, les
coordonnées sont un confort.

C'est l'adresse d'un terrain qui est envoyée, jamais celle d'une personne.
Le géocodage automatique peut être coupé dans la configuration du module.

**À la main**, sur la fiche d'un lieu. C'est souvent le seul moyen pour un
pré sans adresse utilisable — et c'est le plus fiable.

## La règle qui compte

**Dès que quelqu'un saisit ou corrige les coordonnées à la main, le
géocodage automatique ne touche plus jamais ce lieu.** Une personne qui a
déplacé l'épingle sur le bon pré sait quelque chose qu'un annuaire
d'adresses ignore, et la fonctionnalité n'aurait aucune valeur si le
prochain passage automatique remettait le point sur la place du village.

Un lieu sans coordonnées n'apparaît simplement pas sur la carte, et le
nombre de lieux dans ce cas est indiqué sous la carte.
