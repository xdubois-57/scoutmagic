---
id: importer-des-actualites
title: Reprendre les actualités de l'ancien site
summary: Importer les anciennes actualités et leurs formulaires, en lecture seule et sans les réponses.
category: Espace chefs d'U
role_min: admin
discovery: off
question: Comment reprendre les actualités de notre ancien site ?
question: Pourquoi les actualités importées ne peuvent-elles pas être modifiées ?
question: Les réponses aux anciens formulaires sont-elles reprises ?
paths: /admin/migration-donnees/actualites
related: migration-de-donnees, publier-une-actualite
---

L'import « Actualités et formulaires » reprend les anciennes actualités, à leur date de publication d'origine, avec leur contenu, leur visibilité et leurs images, ainsi que les questions de leurs formulaires. Il se prépare sur l'ancien site sous forme de fichier ZIP, dont le format est décrit dans « Formats des fichiers ZIP ».

## Ce que l'import fait

- Rien n'est annoncé : pas de notification de publication, pas de courriel, pas de récapitulatif quotidien.
- Une actualité sans résumé ou sans image est importée telle quelle : la liste montre « Pas d'image », rien n'est inventé.
- Une visibilité que le site ne reconnaît pas devient « Membres connectés », jamais publique. L'aperçu les compte avant l'import.

## Des éléments historiques

Une actualité importée porte la marque « Importé » et le bandeau « Élément historique en lecture seule ». Personne ne peut la modifier, la repasser en brouillon ni la supprimer, même si le module de migration est désactivé ensuite.

Son formulaire est toujours « Formulaire fermé » : il montre les questions de l'ancien site, n'accepte aucune réponse, et les réponses d'origine ne sont jamais importées — un fichier de réponses glissé dans le ZIP est ignoré.
