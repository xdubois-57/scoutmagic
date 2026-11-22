---
id: courrier-entrant
title: Raccorder le courrier entrant
summary: Ajouter une adresse e-mail de l'unité, en lecture seule, et choisir son usage.
category: Configuration
role_min: superadmin
question: Comment brancher une boîte e-mail de l'unité sur le site ?
question: Le site peut-il envoyer ou effacer des mails de la boîte ?
question: Comment réserver une boîte e-mail à un seul usage ?
question: Qui a le droit de lire le courrier entrant ?
paths: /config/courrier-entrant, /config/courrier-entrant/boites/nouvelle, /config/courrier-entrant/boites/*/modification
related: courrier-releve, courrier-reponse, courrier-envoyes, courrier-unite, gerer-les-locations
---

Le site lit une ou plusieurs adresses e-mail de l'unité pour ranger les
réponses des correspondants — un locataire, un parent, un fournisseur —
dans le bon dossier.

## Ce que le site fait de la boîte

- **Rien n'y est jamais modifié** : aucun message marqué lu, déplacé ou
  supprimé.
- **Le courrier relevé est conservé**, même ce qu'aucun module ne
  reconnaît, puis supprimé au terme du délai de conservation s'il n'est
  rattaché à rien (voir « Le courrier de l'unité »).
- **Les identifiants sont chiffrés** et le mot de passe n'est jamais
  réaffiché.

## Ajouter une adresse

Dans « Ajouter une adresse », tapez l'adresse e-mail. Pour Gmail, Google
Workspace, Outlook, Microsoft 365, OVH et Infomaniak, le site reconnaît
le fournisseur et remplit le serveur, le port et le chiffrement. Sinon,
complétez-les d'après l'aide de votre hébergeur, rubrique IMAP.

Gmail demande un **mot de passe d'application**, pas celui du compte :
le formulaire indique où le créer, et la validation en deux étapes doit
être active. Outlook et Microsoft 365 refusent en général toute
connexion IMAP par mot de passe ; le formulaire le signale.

« Vérifier et enregistrer » se connecte avant d'enregistrer. Une adresse
qui ne répond pas **n'est pas enregistrée** : une phrase dit quoi
vérifier, et les champs restent remplis, sauf le mot de passe. Un
certificat invalide bloque toujours.

## Deux usages

**Courrier général de l'unité** — chaque module actif y reconnaît ses
messages. Le Staff d'U voit tout
dans « Courrier reçu ». Les responsables d'un module ne voient que les
messages rattachés à un dossier qu'ils gèrent : quand un module hésite,
sa proposition reste dans « Courrier reçu », où le Staff d'U la
confirme ou l'écarte.

**Réservée à un module** — une adresse créée pour un seul usage. Seul
ce module la lit, et ses responsables voient tout ce qui y arrive,
comme le Staff d'U. Un module n'a qu'une adresse réservée, et un module
qui a déjà la sienne n'est pas proposé — sauf « Courrier sortant —
boîtes témoins », qui peut en avoir plusieurs.

Le courrier général est proposé tant qu'aucune adresse générale
n'existe, puis une adresse réservée si un module est disponible.

## Dossiers lus

Le site lit la boîte de réception et, pour les Locations, les messages
envoyés. « Dossiers lus (facultatif) » permet d'ajouter d'autres
dossiers ; la corbeille, les brouillons et les vues comme « Tous les
messages » ne sont jamais proposés, ni les indésirables, sauf à une
boîte témoin, qui doit les cocher.

## Ensuite

La liste montre l'état de chaque adresse et, en cas d'échec, quoi
faire. « Désactiver » suspend une adresse sans la supprimer ;
« Supprimer » conserve les messages déjà rattachés aux dossiers.

## Comprendre une relève en échec

Le Journal d'événements, catégorie « Courrier entrant », indique les
échecs manuels et changements automatiques. La réponse IMAP est masquée,
le débogage désactivé. Un jeton du serveur inconnu peut échapper au
masquage, y compris dans les archives de support. Voir « Lire une erreur
de relève ».
