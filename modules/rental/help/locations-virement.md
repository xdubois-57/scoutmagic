---
id: locations-virement
title: Rapprocher un virement sans communication
summary: Retrouver le paiement arrivé sans la communication de la réservation — celui de l'assurance du locataire — et le rapprocher, ou le détacher.
category: Espace membres
role_min: identified
question: Comment rapprocher le paiement de l'assurance du locataire ?
question: Un virement est arrivé sans la communication, comment le retrouver ?
question: Comment annuler un virement rapproché par erreur ?
paths: /mes-locations/*/reservations/*/finances
related: locations-finances, locations-incidents, locations-facture
---

Un virement qui porte la communication de la réservation est rapproché
tout seul à l'import de l'extrait bancaire. Celui qui arrive sans elle —
l'assurance du locataire qui paie un dégât, un parent qui paie pour le
groupe — reste parmi les virements non rapprochés du compte : il se
retrouve depuis la page « Finances » de la réservation.

## Chercher le virement

Sous « Mouvements du compte », choisissez « Rapprocher un virement ».
Indiquez le « Montant reçu » — obligatoire — et, si vous le connaissez,
le « Nom du payeur », puis « Chercher ».

Le site ne propose que les virements entrants du compte du bien qui ne
sont rapprochés de rien, reçus depuis le premier contrat envoyé (à
défaut, depuis la demande), **de ce montant exact**, et, avec un nom,
ceux dont le payeur le contient, sans tenir compte des majuscules ni des
accents. Jamais la liste complète du compte : vous ne voyez pas les
paiements des autres familles. Un virement qui porte la communication
d'une autre créance n'est jamais proposé.

Pour chacun : la date, le montant, le payeur et la communication libre.
Un virement n'apparaît qu'après l'import, dans les Finances, de l'extrait
qui le contient ; sans extrait récent, la fenêtre dit « Aucun extrait
importé depuis le … ».

## Rapprocher

« Rapprocher », puis confirmez. Le virement compte **en entier** comme un
paiement de la réservation, même au-delà de ce qui reste dû : l'excédent
se lit en trop-perçu jusqu'au décompte final, qui facture le dégât qu'il
paie. Le restant dû et les étapes de la réservation suivent. Aucun e-mail
n'est envoyé.

Dans « Mouvements du compte », le virement porte « Rapproché à la main
par … », avec la date.

## Détacher

Un virement choisi par erreur se reprend avec « Détacher », puis
confirmez : il redevient un virement non rapproché du compte, et ce qu'il
payait est à nouveau attendu. Aucun e-mail n'est envoyé. « Détacher »
disparaît dès qu'un décompte validé après le rapprochement en tient
compte.
