---
id: locations-finances
title: Les finances d'une réservation
summary: La page Finances d'une réservation — le prix, ce qui est reçu et dû, l'acompte et la caution.
category: Espace membres
role_min: identified
discovery: 3
question: Où trouver le prix et les paiements d'une réservation ?
question: Comment accorder une remise sur une location ?
question: Comment enregistrer la restitution de la caution ?
paths: /mes-locations/*/reservations/*/finances
related: locations-reservation, locations-reglages, locations-documents
---

La page « Finances » d'une réservation tient en deux boîtes : le
**prix**, tel qu'il se négocie avec le locataire, et les **paiements**.

## Le prix

Le prix part du tarif du bien, ligne par ligne. Chaque ligne se
modifie — libellé, quantité, montant — et une ligne corrigée à la main
porte « Modifiée à la main » : elle ne sera plus recalculée, même si
les dates changent. Une remise s'ajoute comme sa propre ligne, d'un
montant négatif, dans « Nouvelle ligne » : le locataire la voit telle
quelle.

Sur une réservation déjà confirmée, un avertissement rappelle ce que
le changement entraîne : le contrat est à régénérer, la créance est
mise à jour et le locataire voit le nouveau prix tout de suite. Les
paiements déjà reçus ne bougent pas ; seul le solde change.

## Les paiements

Si le module « Finances » est actif et les paiements activés pour le
bien, la boîte dit le « Total de la location », ce qui est « Reçu », le
« Restant dû » et la communication à utiliser. L'acompte n'est pas un
paiement à part : c'est un seuil sur la même créance, avec la même
communication, et son badge passe à « Acompte reçu » dès que les
virements l'atteignent.

## La caution

La caution est une **créance distincte**, avec sa propre communication,
et elle n'entre jamais dans le revenu de la location. Sa restitution se
saisit à la main — montant, date et « Motif d'une retenue » s'il y en a
une —, puis « Enregistrer » : les virements sortants ne se rapprochent
pas tout seuls.
