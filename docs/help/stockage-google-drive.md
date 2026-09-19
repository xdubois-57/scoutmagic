---
id: stockage-google-drive
title: Un Google Drive pour les galeries
summary: Ce qu'un Drive sait faire pour un album, ce qu'il refuse, et les deux conséquences à connaître avant de le choisir.
category: Configuration
role_min: superadmin
question: Puis-je héberger les photos d'un album sur Google Drive ?
question: Pourquoi le site refuse-t-il d'envoyer une vidéo sur mon Drive ?
question: Pourquoi le site ne voit-il pas les photos déjà dans mon dossier Drive ?
paths: /config/stockage/emplacements/nouveau, /config/stockage/emplacements/*/modification, /config/gallery
related: stockage, stockage-webdav, stockage-espace
---

Un emplacement **Google Drive** peut recevoir les photos d'un album,
comme n'importe quel autre. Trois choses le distinguent, et mieux vaut
les connaître avant de le choisir plutôt que de les découvrir à l'usage.

## Les vidéos y sont refusées

Un lecteur se déplace dans un film en demandant des morceaux précis du
fichier. Drive ne sait pas répondre à ce genre de demande : le film
démarre, mais on ne peut jamais y avancer — et sur un téléphone, cela
veut souvent dire qu'il ne se lit pas du tout.

Le site **refuse donc l'envoi d'une vidéo** vers un album posé sur un
Drive, au moment du dépôt, avec un message qui le dit. C'est un refus
volontaire : accepter le fichier reviendrait à le ranger là où personne
ne pourra le regarder.

Les **photos**, elles, sont acceptées normalement.

## Chaque photo passe par le site

Drive ne remet rien directement au visiteur. Chaque photo affichée est
une requête que le site fait à Google, puis transmet. Un album consulté
par trente parents le même soir se sent donc sur l'hébergement, là où un
stockage S3 aurait remis les images lui-même.

Pour une galerie très fréquentée, un
[partage WebDAV](stockage-webdav) ou un stockage S3 vaut mieux.

## Le site ne voit que ce qu'il a déposé

L'autorisation demandée à Google est volontairement la plus étroite qui
existe : le site peut lire et écrire **les fichiers qu'il a lui-même
créés**, et rien d'autre du Drive de la personne.

La contrepartie est visible : des photos déjà présentes dans le dossier
avant le branchement resteront invisibles depuis le site. Ce n'est pas
une panne, c'est le prix d'une autorisation qui ne donne pas accès au
reste du compte.

## Ce que Drive fait bien

Il sait dire la place qui reste, et il sait reprendre un envoi
interrompu — ce qui en fait une bonne destination de **sauvegarde hors
site**, là où un S3 ou un WebDAV sont refusés pour cet usage.
