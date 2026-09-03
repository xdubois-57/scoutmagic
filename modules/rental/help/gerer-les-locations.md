---
id: gerer-les-locations
title: Gérer les locations d'un bien
summary: Les réservations, les paiements, les documents et le séjour.
category: Espace membres
role_min: identified
question: Comment traiter une demande de location reçue ?
question: Comment envoyer le contrat de location au locataire ?
question: Comment savoir si un locataire a payé son acompte ?
paths: /mes-locations, /mes-locations/*, /mes-locations/*/calendrier, /mes-locations/*/gabarits, /mes-locations/*/reservations, /mes-locations/*/reservations/*, /mes-locations/*/reservations/*/sejour, /mes-locations/*/reservations/*/document/*
related: locations, locations-courrier, config-locations, courrier-entrant, locations-reglages, locations-conformite
---

« Mes locations » est l'espace des gestionnaires : les personnes
désignées sur chaque bien, plus le Staff d'Unité qui voit tout. La
page d'accueil rassemble ce qui attend une action, tous biens
confondus.

## Traiter une demande

Chaque réservation a sa fiche : le parcours du dossier (jalons
calculés d'après les faits — rien à cocher : envoyer le contrat coche
« Contrat envoyé », terminer un état des lieux coche le sien, et une
ligne grisée « sans objet » signale ce qui n'a pas lieu d'être ici,
comme une caution non demandée), l'état, le prix, les paiements, les
documents et l'historique. Les boutons de la page agissent sans
recharger : la fiche se met à jour d'elle-même, jalons compris, et un
message confirme chaque action. Les boutons d'état font
avancer le dossier — confirmer, refuser, demander des informations,
proposer autre chose — et ceux qui écrivent au locataire vous laissent
ajouter un mot. Poser une **option** bloque les dates jusqu'à une
échéance : dépassée, la réservation expire et libère les dates toute
seule.

Le **lien de suivi** du locataire n'est affiché nulle part, pas même
pour vous : le posséder suffit pour ouvrir le dossier. Il ne se
retrouve donc pas, il se remplace — « Régénérer le lien de suivi », sur
la fiche du locataire, en envoie un nouveau par email et désactive
l'ancien sur-le-champ. C'est la réponse à un lien perdu comme à un lien
transmis à la mauvaise personne.

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

Les e-mails que le site rattache à la réservation, les propositions à
confirmer et ce que « Détacher » fait exactement sont décrits dans
« Le courrier d'une réservation ».

## Le séjour

La page Séjour consigne les relevés de compteurs (photo à l'appui),
l'état des lieux figé à la confirmation, et les incidents — rien ne se
facture sans qu'un gestionnaire tranche : ajouter au décompte, retenir
sur la caution, ou ne pas facturer. Cette page ne fonctionne qu'en
ligne : sur place sans réseau, photographiez, saisissez au retour.

Les réglages du bien — règles de réservation, tarifs, acompte,
gabarits, échéances de conformité — vivent dans les onglets du bien,
et ne changent jamais une réservation déjà existante.
