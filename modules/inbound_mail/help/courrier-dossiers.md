---
id: courrier-dossiers
title: Choisir les dossiers lus
summary: Quels dossiers d'une adresse le site lit, comment en cocher d'autres, et ce qui arrive quand un dossier disparaît du serveur.
category: Configuration
role_min: superadmin
question: Quels dossiers de la boîte e-mail le site lit-il ?
question: Pourquoi un dossier n'est-il pas proposé dans « Dossiers lus » ?
paths: /config/courrier-entrant/boites/nouvelle, /config/courrier-entrant/boites/*/modification
related: courrier-entrant, courrier-releve, courrier-envoyes
---

Le site lit toujours la boîte de réception, sans que vous ayez à
connaître son nom sur le serveur, et, pour les Locations, les messages
envoyés.

« Dossiers lus » apparaît dès que la connexion est vérifiée : à la fin de
la saisie, ou avec le bouton « Vérifier la connexion et choisir les
dossiers ». La boîte de réception y est cochée d'office ; les autres
dossiers du serveur sont proposés à cocher, aucun ne l'est au départ. La
corbeille, les brouillons et les vues comme « Tous les messages » ne sont
jamais proposés, ni les indésirables, sauf à une boîte témoin, qui doit
les cocher. Un serveur qui ne signale pas ses dossiers spéciaux (LWS par
exemple) les liste comme les autres : laissez-les décochés.

Changer de serveur, d'adresse, de port, de chiffrement ou de mot de passe
masque la liste jusqu'à ce que les nouveaux réglages répondent. Sans
JavaScript, la liste ne s'affiche pas : une nouvelle adresse lit sa seule
boîte de réception, une adresse modifiée garde ses dossiers.

Un dossier choisi que le serveur n'a plus est signalé dans la liste, et
n'est plus lu une fois l'adresse enregistrée. Tant qu'elle ne l'est pas,
la relève échoue en nommant ce dossier.
