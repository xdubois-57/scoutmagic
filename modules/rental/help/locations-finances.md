---
id: locations-finances
title: Les finances d'une réservation
summary: La page Finances d'une réservation — le résumé, les mouvements du compte, le QR de paiement et le remboursement de la caution.
category: Espace membres
role_min: identified
discovery: 3
question: Où trouver les paiements d'une réservation ?
question: Comment rembourser la caution au locataire ?
paths: /mes-locations/*/reservations/*/finances
related: locations-reservation, locations-modifications, locations-reglages, locations-documents, locations-virement
---

La page « Finances » d'une réservation montre, dans l'ordre, le
« Résumé » des paiements, les « Mouvements du compte » et les
« Informations de paiement ».

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
imprime cette communication.

Le « Résumé » dit le « Montant attendu », ce qui est « Reçu », le
« Restant dû » ou le « Trop-perçu », l'acompte et le solde. L'acompte
est un seuil sur la créance : il passe à « Acompte reçu » dès que les
virements l'atteignent. Une réservation refusée, annulée ou expirée
garde sa créance, ramenée à 0 € ; le décompte final validé la ramène au
montant conservé. Ce qui a été versé en plus apparaît en trop-perçu.

« Mouvements du compte » liste les virements rapprochés de cette
réservation, après l'import d'un extrait bancaire. Un paiement arrivé
sans la communication s'y ajoute avec « Rapprocher un virement ».

« Informations de paiement » reprend le bénéficiaire, l'IBAN, la
communication et le montant à verser maintenant, avec son QR code
SEPA : « Agrandir le QR code » l'affiche en grand, à scanner avec une
application bancaire. Le locataire voit le même bloc sur sa page de
suivi.

## Rembourser la caution

Quand il y a un trop-perçu — la caution après le décompte, ou ce
qu'avait payé une réservation abandonnée —, la page dit « À rembourser ».
Faites le virement depuis la banque **avec la communication de la
réservation** : il est rapproché tout seul à l'import de l'extrait, et
l'étape « Caution restituée » se coche quand plus rien n'est dû au
locataire.

Parti sans la communication, le virement se retrouve avec « Rapprocher
le remboursement » : choisissez le débit dans la liste, puis
« Rapprocher ». Aucun e-mail n'est envoyé.

Sans Finances, la restitution se note à la main sur cette page :
montant, date et « Motif d'une retenue » s'il y en a une, puis
« Enregistrer ».
