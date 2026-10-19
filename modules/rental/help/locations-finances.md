---
id: locations-finances
title: Les finances d'une réservation
summary: La page Finances d'une réservation — ce qui est attendu, reçu et dû, l'acompte et la caution.
category: Espace membres
role_min: identified
discovery: 3
question: Où trouver les paiements d'une réservation ?
question: Comment enregistrer la restitution de la caution ?
paths: /mes-locations/*/reservations/*/finances
related: locations-reservation, locations-modifications, locations-reglages, locations-documents
---

La page « Finances » d'une réservation montre ce qui est attendu, reçu
et dû, l'acompte et la caution.

Le prix lui-même se change sur la page « Modifications » : les lignes du
tarif y suivent les dates et les participants, et une remise s'y ajoute
comme sa propre ligne. Une fois un contrat envoyé, le nouveau prix ne
s'applique qu'à la contresignature du contrat de remplacement ; la
créance passe alors au nouveau montant. Les paiements déjà reçus ne
bougent pas ; seul le solde change.

## Les paiements

Si le module « Finances » est actif et les paiements activés pour le
bien, la réservation a **une seule créance** : le prix de la location
plus la caution, avec **une seule communication** pour l'acompte, le
solde et la caution. Elle existe dès le premier contrat généré, qui
imprime cette communication. La boîte dit le « Montant attendu », ce qui
est « Reçu », le « Restant dû » et la communication à utiliser.

L'acompte n'est pas un paiement à part : c'est un seuil sur cette
créance, et son badge passe à « Acompte reçu » dès que les virements
l'atteignent. Il est attendu quelques jours après le premier envoi du
contrat ; le solde et la caution, quelques jours avant l'arrivée.

Une réservation refusée, annulée ou expirée garde sa créance, ramenée à
0 € : un virement déjà reçu apparaît en « Trop-perçu ». Le décompte
final validé la ramène au montant conservé.

## La caution

La caution fait partie du « Montant attendu » et n'a pas d'échéance à
elle. Sa restitution se saisit à la main — montant, date et « Motif
d'une retenue » s'il y en a une —, puis « Enregistrer ».
