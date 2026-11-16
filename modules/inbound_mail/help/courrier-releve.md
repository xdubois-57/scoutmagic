---
id: courrier-releve
title: Relever une boîte et lire ses erreurs
summary: Ce que vérifie le test de connexion, ce que dit « Rafraîchir maintenant », et comment lire une erreur de relève.
category: Configuration
role_min: superadmin
question: Pourquoi le test de connexion réussit-il alors que la relève échoue ?
question: Que veut dire l'erreur affichée sur une boîte ?
paths: /config/courrier-entrant
related: courrier-entrant, courrier-portee
---

## Le test de connexion

Le test vérifie la connexion et la liste des dossiers, **pas la relève
elle-même** : il n'ouvre pas les dossiers surveillés et ne lit aucun
message. C'est pourquoi il ne touche ni à l'état de la boîte ni à sa
date de dernière relève — un test réussi n'efface pas l'erreur d'une
relève qui vient d'échouer. Pour savoir si la relève fonctionne,
utilisez « Rafraîchir maintenant ».

## Rafraîchir maintenant

Le bouton relève les boîtes tout de suite, sans attendre le prochain
passage automatique — pratique juste après avoir saisi un mot de passe.
Un seul rafraîchissement à la fois : un second clic pendant qu'un
premier tourne est refusé, sans quoi les deux se courraient après sur
la même boîte.

Le message qui suit dit ce que les boîtes ont fait : une relève réussie
(avec le nombre de messages lus et conservés), une relève partielle
quand une boîte sur plusieurs échoue, ou un échec. La raison de chaque
échec est indiquée sur la boîte concernée, dans la colonne « État ».

## Lire une erreur de relève

L'erreur dit à quelle étape la relève s'est arrêtée :

- **le serveur n'a pas répondu** : la connexion elle-même n'a pas pu
  s'établir — vérifiez l'hôte, le port et que l'hébergement autorise
  les connexions sortantes ;
- **authentification refusée** ou **certificat non vérifié** : à
  corriger dans les réglages de la boîte ;
- **dossier introuvable** : un des dossiers surveillés n'existe pas
  sous ce nom exact ;
- **une étape après une connexion réussie** (liste des dossiers,
  ouverture d'un dossier, lecture des messages) : les identifiants sont
  bons, c'est le serveur qui a refusé ou coupé. Le nom technique entre
  parenthèses est celui que le support vous demandera ; le journal
  d'erreurs du serveur précise la même étape.
