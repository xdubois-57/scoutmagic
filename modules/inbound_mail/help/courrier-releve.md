---
id: courrier-releve
title: Relever une adresse et lire ses erreurs
summary: Ce que vérifie « Vérifier et enregistrer », ce que dit la liste des adresses, et comment lire une erreur de relève.
category: Configuration
role_min: superadmin
question: Pourquoi l'adresse est-elle refusée à l'enregistrement ?
question: Que veut dire l'erreur affichée sur une adresse ?
paths: /config/courrier-entrant
related: courrier-entrant, courrier-unite
---

## La vérification avant d'enregistrer

« Vérifier et enregistrer » ouvre une connexion et lit la liste des
dossiers. Si elle échoue, **rien n'est enregistré** : l'adresse n'est pas
ajoutée, ou garde ses réglages précédents. Les champs restent remplis,
sauf le mot de passe, à retaper.

La vérification ne lit aucun message et ne touche pas à la date de
dernière relève. Quand elle réussit, la modification est enregistrée et
l'erreur affichée sur l'adresse disparaît : elle portait sur les anciens
réglages. Si la relève suivante échoue encore, la liste le dira.

## Les dossiers lus

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

## La liste des adresses

Chaque adresse dit quand elle a été relevée. En cas d'échec, une phrase
dit ce qui ne va pas : corrigez le réglage avec « Modifier ». Pour
relever tout de suite, sans attendre le prochain passage, utilisez
« Relever le courrier maintenant » dans « Courrier reçu ».

## Lire une erreur de relève

L'erreur dit à quelle étape la relève s'est arrêtée :

- **le serveur n'a pas répondu** : la connexion elle-même n'a pas pu
  s'établir — vérifiez le serveur, le port et que l'hébergement autorise
  les connexions sortantes ;
- **authentification refusée** : le mot de passe a changé, ou ce n'est
  pas un mot de passe d'application ;
- **certificat non vérifié** : vérifiez le nom du serveur et le
  chiffrement ; il n'existe pas d'option pour passer outre ;
- **dossier introuvable** : le dossier nommé n'existe plus sous ce nom
  exact. Ouvrez l'adresse avec « Modifier » : la liste des dossiers le
  signale, et l'enregistrement le retire ;
- **une étape après une connexion réussie** (liste des dossiers,
  ouverture d'un dossier, lecture des messages) : les identifiants sont
  bons, c'est le serveur qui a refusé ou coupé. Le nom technique entre
  parenthèses est celui que le support vous demandera.

## Retrouver le diagnostic

Après une relève en échec, consultez **Journal d'événements**, catégorie
« inbound_mail ». Chaque échec manuel y figure avec la boîte concernée,
l'étape, les classes d'erreurs et les codes disponibles. Pour les relèves
automatiques, seul le premier échec ou un changement de cause produit un
événement ; un autre indique le rétablissement.
Si le journal refuse un diagnostic automatique, son écriture est retentée
à la relève suivante si la même panne persiste.

La réponse du serveur, lorsqu'elle est disponible, est limitée à 300
caractères après masquage des identifiants connus, mots de passe, adresses
e-mail et commandes d'authentification. Le débogage IMAP reste désactivé.
Ces diagnostics font aussi partie des archives de support : un secret
inconnu du site, comme un jeton propre au serveur, peut malgré tout
échapper au masquage.
