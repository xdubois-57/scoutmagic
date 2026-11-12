---
id: historique-des-courriels
title: Reprendre les courriels de l'ancien site
summary: Importer l'historique des courriels envoyés, en lecture seule, sans rien renvoyer.
category: Espace chefs d'U
role_min: admin
discovery: off
question: Comment reprendre les anciens courriels de notre ancien site ?
question: Un courriel importé peut-il être renvoyé ou modifié ?
question: À quelle section un ancien courriel est-il rattaché ?
paths: /admin/migration-donnees/courriels
related: migration-de-donnees, envoi-de-mails
---

L'import « Historique des courriels » reprend les courriels que l'ancien site a envoyés et les range dans « Envoi de mails », en lecture seule. Il suit le même déroulé que les autres imports de la migration de données.

## Ce qui est repris

- Un courriel a besoin d'un sujet, d'un contenu, d'une date et d'un expéditeur. Sans l'un d'eux, il est ignoré et le bilan dit pourquoi.
- Il est rattaché à la section dont l'adresse l'a envoyé, sinon au Staff d'U. L'adresse et le nom d'expéditeur d'origine restent affichés tels quels.
- Les destinataires sont facultatifs. Chacun garde son statut d'origine : « Accepté par le serveur d'envoi » ne devient jamais « Remis ». Un courriel lié à un animé apparaît sur sa fiche.
- Un publipostage est repris une seule fois, avec son texte modèle.
- Une pièce jointe invalide est laissée de côté, et le courriel est importé sans elle.

## Ce que l'import ne fait pas

Aucun courriel n'est envoyé ni renvoyé. Un courriel importé porte le badge « Importé » et le bandeau « Élément historique en lecture seule » : il ne peut être ni modifié, ni supprimé, ni renvoyé, même si la migration de données est désactivée ensuite.
