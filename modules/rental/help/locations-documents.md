---
id: locations-documents
title: Le contrat et la facture d'une réservation
summary: La page Documents d'une réservation — rédiger le contrat et la facture, générer le PDF, l'envoyer, et ce que l'envoi verrouille.
category: Espace membres
role_min: identified
question: Comment modifier le contrat d'une location déjà envoyé ?
question: Pourquoi ne puis-je plus modifier le texte de ma facture ?
question: Comment envoyer le contrat de location au locataire ?
question: Pourquoi le contrat de location ne se génère-t-il pas ?
paths: /mes-locations/*/reservations/*/document/*, /mes-locations/*/reservations/*/documents
related: gerer-les-locations, locations-modifications, locations-signatures, locations-reglages, locations-courrier, locations-facture
---

La page « Documents » d'une réservation réunit ses documents — contrat,
facture, copies signées, pièces jointes des e-mails, jamais en double.
Rangés « Non classé », ils se reclassent depuis leur ligne. La facture se
génère sur la page « Facture ».

Un contrat et une facture se construisent en trois temps : le **gabarit du
bien**, la **copie de cette réservation**, puis le **PDF**.

## Le texte

Cette page-ci n'édite que la copie de **cette** réservation : la modifier
ne change aucune autre réservation, et retoucher ensuite le gabarit du bien
ne revient pas dessus.

Les mots-clés — en bleu — sont remplacés par les valeurs de la réservation
à la génération. Insérez-les depuis la liste : ils forment un bloc
insécable. Un mot-clé non reconnu vous est signalé avant l'envoi.

## Ce que le contrat doit dire

Le contrat standard suit l'annexe 8.1 du Code wallon du tourisme. Son
en-tête nomme le bailleur, réglé dans la section « Bailleur » des réglages
du bien ou dans Paramètres › Locations.

**Il ne se génère pas** sans le nom et l'adresse du locataire ni l'adresse
du bien ; le message dit quoi compléter. Le locataire les donne dans « Vos
coordonnées de facturation » sur sa page de suivi, vous dans
« Coordonnées de facturation » sur la page « Facture », avec le responsable
du groupe sur place. La section « Contrat » des réglages du bien règle son
adresse et la « Date limite de désistement sans indemnité ».

Le compte imprimé est celui du bien dans Finances ; sans compte, le contrat
dit que le bailleur le communique. Le lien vers les conditions acceptées
est cliquable.

## Générer, puis envoyer

**Le contrat se génère et s'envoie depuis le tableau de bord**, à son
étape : générez, relisez le PDF, puis envoyez. Il imprime la
communication structurée de la réservation, la même pour tous ses
paiements. Cette page-ci les liste avec les autres documents et les
renvoie.

Chaque génération crée une version de plus **sans écraser la
précédente**. Le contrat renvoie à la version des conditions acceptée avec
la demande, jamais à celle en vigueur.

> **« Envoyer » verrouille.** Tant que rien n'est parti, le texte reste
> modifiable ; une fois envoyé, il passe en lecture seule : le locataire en
> a une copie. La confirmation vous le dit avant.

Envoyer le contrat ne verrouille que le contrat : la facture reste
modifiable tant qu'elle n'est pas partie elle-même.

Le locataire ne télécharge que le contrat signé par les deux parties : ses
autres documents lui parviennent par e-mail, et un e-mail perdu se renvoie.

## Le contrat de remplacement

Un contrat envoyé ne se corrige pas : un changement passe par la page
« Modifications » (« Modifier une réservation »). Pendant une
modification, la boîte « Contrat » montre le contrat en vigueur et, à
côté, le contrat de remplacement avec la copie du locataire,
« Refuser la copie » et « Contresigner » ; un bandeau « Une modification
est en cours » le rappelle sur cette page.
