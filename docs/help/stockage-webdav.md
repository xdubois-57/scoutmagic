---
id: stockage-webdav
title: Brancher un partage WebDAV
summary: Nextcloud, kDrive, Koofr ou une Storage Box comme emplacement : ce qu'il faut saisir, et ce que ça change pour les visiteurs.
category: Configuration
role_min: superadmin
question: Comment brancher un Nextcloud ou un kDrive sur le site ?
question: Quel mot de passe donner au site pour mon cloud ?
question: Pourquoi le site refuse-t-il l'adresse de mon partage ?
paths: /config/stockage/emplacements/nouveau, /config/stockage/emplacements/*/modification
related: stockage, stockage-espace, stockage-copie-de-secours
---

C'est le type d'emplacement qui demande le moins : une **adresse**, un
**identifiant**, un **mot de passe**. Rien à créer chez un fournisseur,
aucune console de développeur, aucun écran de consentement à republier.
Nextcloud, kDrive, Koofr et les Storage Box parlent tous ce langage.

## L'adresse

C'est celle du dossier où le site écrira, pas celle de la page web du
cloud. Nextcloud la donne dans « Paramètres › Personnel › Général », en
bas de page ; ajoutez-y le sous-dossier que vous réservez au site.

Elle doit être en **https** et joignable depuis l'extérieur. Le site
refuse une adresse en clair ou une adresse interne, et ce n'est pas une
formalité : votre identifiant et votre mot de passe y voyagent à chaque
requête.

## Le mot de passe

Presque tous ces hébergeurs demandent ici un **mot de passe
d'application**, créé dans les réglages de sécurité du compte, et non
celui avec lequel vous vous connectez.

C'est plus sûr et c'est plus commode : ce mot de passe-là se révoque
pour le site seul, le jour où vous changez d'avis, sans toucher au
vôtre.

Sur la fiche de modification, le champ reste vide et le site conserve le
mot de passe déjà enregistré. Ne le remplissez que pour le remplacer.

## Ce que ça change pour les visiteurs

Les **vidéos fonctionnent** : on peut avancer dedans, contrairement à
Google Drive. Et le partage sait dire **combien de place il reste**.

En revanche, un partage ne sert jamais rien directement : **chaque photo
et chaque vidéo est lue par le serveur du site, puis transmise au
visiteur**. C'est exactement ce que fait déjà le disque du serveur, donc
rien de nouveau — mais sur un hébergement mutualisé modeste, un album
très consulté fait travailler ce serveur davantage qu'un stockage objet,
qui lui répond au visiteur sans passer par le site.

## Si le test échoue

Le bouton de test écrit un fichier témoin, le relit et le supprime.
Trois refus reviennent souvent :

- **identifiants refusés** — c'est presque toujours le mot de passe de
  connexion saisi à la place d'un mot de passe d'application ;
- **dossier introuvable** — l'adresse pointe un dossier qui n'existe pas
  encore ; créez-le dans le cloud d'abord ;
- **partage plein** — il n'y a plus de place sur le compte.
