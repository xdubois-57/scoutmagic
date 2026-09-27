---
id: locations-documents
title: Le contrat et la facture d'une réservation
summary: La page Documents d'une réservation — rédiger le contrat et la facture, générer le PDF, l'envoyer, et ce que l'envoi verrouille.
category: Espace membres
role_min: identified
question: Comment modifier le contrat d'une location déjà envoyé ?
question: Pourquoi ne puis-je plus modifier le texte de ma facture ?
question: Comment envoyer le contrat de location au locataire ?
paths: /mes-locations/*/reservations/*/document/*, /mes-locations/*/reservations/*/documents
related: gerer-les-locations, locations-reglages, locations-courrier
---

La page « Documents » d'une réservation réunit ses documents — contrat,
facture, copies signées, pièces reçues par e-mail — et les « Coordonnées
de facturation » du locataire, que la facture reprend. Un document reçu
par e-mail et rangé « Non classé » se reclasse depuis sa ligne.

Un contrat et une facture se construisent en trois temps : le **gabarit du
bien**, la **copie de cette réservation**, puis le **PDF**.

## Le texte

Le gabarit du bien est le point de départ commun. Cette page-ci n'édite que
la copie de **cette** réservation : la modifier ne change aucune autre
réservation, et retoucher le gabarit du bien ensuite ne revient pas dessus.

Les mots-clés — en bleu — sont remplacés par les valeurs de la réservation
au moment de la génération. Insérez-les depuis la liste plutôt que de les
taper : ils forment un bloc insécable, qu'une mise en forme ne peut plus
couper en deux. Si un mot-clé n'est pas reconnu, la page vous le dit avant
que le document ne parte.

## Le bailleur

L'en-tête du contrat et de la facture nomme le bailleur. Par défaut,
c'est l'unité : son nom et son adresse postale (Paramètres, cœur du
site). Si les locaux appartiennent à une ASBL distincte, renseignez son
nom, son adresse et son numéro d'entreprise dans Paramètres › Locations.
Un bien qui appartient à quelqu'un d'autre a son propre bailleur, dans la
section « Bailleur » de ses réglages. Si l'adresse du bailleur manque, la
page « Documents » vous prévient avant la génération.

## Générer, puis envoyer

Générer produit un PDF. Chaque génération crée une version de plus **sans
écraser la précédente** : une version déjà signée reste intacte.

> **« Envoyer » verrouille.** Tant que rien n'est parti, le texte reste
> modifiable ; une fois envoyé, il passe en lecture seule. Le locataire en a
> une copie, et ce qu'elle disait ne doit pas changer dans son dos. La
> confirmation vous le dit avant, pas après.

Envoyer le contrat ne verrouille que le contrat : la facture reste
modifiable tant qu'elle n'est pas partie elle-même.

Le locataire ne télécharge jamais rien depuis le site — ses documents lui
parviennent par email, et un email perdu se renvoie.
