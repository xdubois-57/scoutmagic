---
id: migration-de-donnees
title: Reprendre les données de l'ancien site
summary: Importer une fois, depuis un fichier préparé sur l'ancien site, sans doublon et sans rien envoyer.
category: Espace chefs d'U
role_min: admin
discovery: off
question: Comment reprendre les données de notre ancien site ?
question: Que se passe-t-il si l'import s'arrête en cours de route ?
question: Peut-on relancer un import sans créer de doublons ?
question: Combien de temps le fichier déposé reste-t-il sur le serveur ?
paths: /admin/migration-donnees, /admin/migration-donnees/*
---

Cette page sert à reprendre, une seule fois, ce que votre ancien site contenait : chaque import se prépare sur l'ancien site sous forme de fichier ZIP, puis se dépose ici. Seuls les imports dont le module est activé sont proposés.

## Le déroulé

1. **Source** : déposez le fichier ZIP. Un envoi coupé reprend là où il s'est arrêté, tant que l'onglet reste ouvert.
2. **Analyse** : le site vérifie le fichier — son format, sa version, l'empreinte de chaque fichier — puis regarde chaque élément. Rien n'est écrit pendant cette étape. Un fichier abîmé ou d'une version inconnue est refusé en entier, avec le motif.
3. **Aperçu** : vous voyez ce qui sera importé, ce qui est déjà présent et ce qui sera ignoré, avec la référence de l'ancien site, jamais le nom des personnes. Si des éléments visent des années scoutes passées qui n'existent pas encore, cochez celles à créer.
4. **Confirmation** : rien n'est écrit avant d'avoir cliqué sur « Importer ».
5. **Exécution** : l'import avance par petits paquets. Gardez la page ouverte. Vous pouvez la « Mettre en pause » ; si elle se ferme, l'import s'arrête et vous le reprenez plus tard avec « Reprendre l'import ».
6. **Bilan** : importés, déjà présents, ignorés et erreurs techniques.

## Relancer sans doublon

Le site retient, pour chaque élément importé, sa référence sur l'ancien site. « Relancer le même fichier » ne crée donc rien deux fois : seuls les éléments ignorés ou en erreur sont retentés. L'import n'envoie aucun courriel et aucune notification.

## Endroits de camp

L'import « Endroits de camp » reprend les lieux seuls : nom, adresse, coordonnées, site web et état archivé. Les anciens séjours, les contacts, les avis et les documents ne sont pas repris.

Chaque lieu est comparé à ceux que Camps connaît déjà, **lieux archivés compris**, sur le nom, la localité et l'adresse :

- un lieu qui est certainement le même est compté « déjà présent », et le lieu existant n'est pas modifié ;
- un lieu qui lui ressemble sans certitude est compté parmi les « Ambigus, non créés » : il n'est ni créé ni fusionné, et le bilan nomme le lieu auquel il ressemble. Vérifiez-le dans Camps : si c'est bien le même terrain, il n'y a rien à faire ; si c'est un autre terrain, créez-le à la main dans Camps ;
- les autres lieux sont créés.

Les coordonnées venues de l'ancien site sont gardées telles quelles : la recherche automatique de coordonnées ne les remplacera jamais. Un lieu importé se modifie, s'archive et se fusionne ensuite comme les autres.

## Galeries photo

Les albums que l'ancien site montrait par un lien (Google Photos, Flickr…) deviennent des albums externes de la Galerie ; aucune photo n'est copiée. Le titre et la vignette sont relus sur la page de chaque album pendant l'import, ce qui prend environ deux secondes par album. Un album réservé à une section que le site ne reconnaît pas est ignoré plutôt que montré à toute l'unité, et une adresse que la Galerie connaît déjà n'est pas importée une seconde fois. Les albums importés se gèrent ensuite dans « Gérer les photos », comme les autres.

## Abandonner

« Abandonner et effacer le fichier » garde ce qui est déjà importé et efface le fichier déposé.

> Le fichier déposé contient les données de vos membres. Il est effacé du serveur au plus tard 24 heures après la fin de l'import, son abandon ou son dernier mouvement.
