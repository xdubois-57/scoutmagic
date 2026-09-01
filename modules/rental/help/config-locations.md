---
id: config-locations
title: Créer les biens à louer
summary: Déclarer un bien, désigner ses gestionnaires, l'archiver.
category: Espace chefs d'U
role_min: admin
question: Comment mettre un local en location sur le site ?
question: Comment désigner qui gère les locations d'un bien ?
paths: /admin/locations
related: gerer-les-locations, locations
---

La page « Locations » de l'espace chefs d'U répond à une seule
question : quels biens existent, et qui s'en occupe. Tout le reste —
tarifs, règles, documents — appartient aux gestionnaires de chaque
bien, dans « Mes locations ».

## Créer un bien

« Ajouter un bien » demande son nom, son type, sa capacité, sa
quantité (huit tentes = quantité 8), ses heures d'arrivée et de
départ, et son **mode de location** : à la nuit (le jour du départ se
libère) ou à la journée pleine (le jour du retour reste occupé). Ce
mode se choisit à la création car il gouverne à la fois le calendrier,
le prix et les disponibilités.

La case « Rendre ce bien visible publiquement » publie sa page dans la
liste des locations, du côté visiteur. Un bien sans tarif y répondra
« Tarif sur demande » — la page vous le signale jusqu'à ce qu'un
gestionnaire remplisse la tarification.

## Désigner les gestionnaires

Cherchez un membre par nom ou totem : la liste montre nom, section et
fonction — jamais d'adresse. Seuls les membres ayant l'âge minimum
configuré sont proposés : un gestionnaire accède aux identités des
locataires, à l'argent et aux contrats. L'interrupteur « Contact du
locataire » décide qui apparaît sur la page de suivi du locataire.

Un gestionnaire absent du dernier import de la fédération est signalé
« Désactivé » : son accès est coupé mais sa désignation conservée —
retirez-la explicitement si le départ est définitif.

## Le compte des paiements

Le compte bancaire sur lequel un bien attend ses paiements se choisit
ici — c'est le seul réglage d'argent réservé au Staff d'Unité, la
liste des comptes portant les IBAN de l'unité. Sans IBAN, aucun code
QR de virement ne peut être proposé aux locataires.

## Archiver

Un bien qui a un historique s'archive — il disparaît du public et des
créations, l'historique reste. La suppression définitive n'est
possible que pour un bien sans passé.
