---
id: gerer-les-locations
title: Gérer les locations d'un bien
summary: Les réservations, les paiements, les documents et le séjour.
category: Espace membres
role_min: identified
question: Comment traiter une demande de location reçue ?
question: Comment envoyer le contrat de location au locataire ?
question: Comment savoir si un locataire a payé son acompte ?
question: Pourquoi une réservation confirmée apparaît-elle dans « À traiter » ?
paths: /mes-locations, /mes-locations/*, /mes-locations/*/calendrier, /mes-locations/*/gabarits, /mes-locations/*/reservations, /mes-locations/*/reservations/*/sejour, /mes-locations/*/reservations/*/document/*
related: locations, locations-reservation, locations-courrier, config-locations, courrier-entrant, locations-reglages, locations-conformite
---

« Mes locations » est l'espace des gestionnaires : les personnes
désignées sur chaque bien, plus le Staff d'Unité qui voit tout. La
page d'accueil rassemble ce qui attend une action, tous biens
confondus.

## « À traiter »

Une seule question : **quelqu'un attend-il ?** Soit le dossier en est à
une étape où vous devez décider, soit le locataire a demandé une
modification, soit votre proposition attend sa réponse. Chaque ligne dit
laquelle — les deux dernières ne se lisent pas dans le statut, et une
réservation confirmée portant une demande reçue hier n'apparaissait donc
nulle part. Le chiffre en haut de page et le filtre de la liste des
réservations comptent la même chose.

## Traiter une demande

Chaque réservation a sa fiche, décrite dans « La fiche d'une
réservation » : elle se lit en quatre temps, du détail de la location
à un dossier replié, en passant par la seule chose qui vous attend.
Les boutons y agissent sans recharger, et ceux qui écrivent au
locataire vous laissent ajouter un mot.

## Le prix et les paiements

L'estimation faite par le visiteur reste figée ; le prix **convenu**
se construit sur la fiche, ligne par ligne — une ligne retouchée à la
main n'est plus jamais recalculée. Avec le module Finances, l'acompte
et le solde se pointent automatiquement grâce à la communication
structurée ; la caution a la sienne, séparée, et sa restitution se
note à la main. Le décompte final, après séjour, ajoute ses lignes
sans jamais réécrire le prix convenu.

## Contrat et facture

« Générer le contrat » ou « la facture » produit un PDF depuis le
gabarit du bien ; chaque génération crée une nouvelle version, sans
écraser la précédente — une version signée reste intacte. « Envoyer »
l'expédie au locataire : celui-ci ne télécharge jamais rien depuis le
site.

## Le courrier reçu

Décrit dans « Le courrier d'une réservation » : les e-mails rattachés,
les propositions à confirmer, et ce que « Détacher » fait exactement.

## Le séjour

La page Séjour consigne les relevés de compteurs (photo à l'appui),
l'état des lieux figé à la confirmation, et les incidents — rien ne se
facture sans qu'un gestionnaire tranche : ajouter au décompte, retenir
sur la caution, ou ne pas facturer. Cette page ne fonctionne qu'en
ligne : sur place sans réseau, photographiez, saisissez au retour.

Les réglages du bien — règles de réservation, tarifs, acompte,
gabarits, échéances de conformité — vivent dans les onglets du bien,
et ne changent jamais une réservation déjà existante.
