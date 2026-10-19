---
id: locations-modifications
title: Modifier une réservation
summary: Changer les dates, les participants, les unités ou le prix d'une réservation, avant et après l'envoi du contrat.
category: Espace membres
role_min: identified
discovery: 3
question: Comment changer les dates d'une réservation déjà confirmée ?
question: Comment accorder une remise sur une location ?
question: Qu'est-ce qu'un contrat de remplacement ?
question: Le locataire veut changer ses dates, que faire ?
paths: /mes-locations/*/reservations/*/modifications, /mes-locations/*/reservations/*/modifications/*
related: locations-reservation, locations-signatures, locations-documents, locations-finances
---

Les dates, les participants, les unités ou une remise sur le prix se
changent sur la page « Modifications » de la réservation. Le locataire
ne modifie rien lui-même : il contacte l'unité, et vous faites le
changement ici.

## Avant le premier envoi du contrat

Tant qu'aucun contrat n'a été envoyé au locataire, le bouton « Modifier
les dates, les participants ou le prix » ouvre un formulaire, et
« Enregistrer » applique le changement tout de suite, sans e-mail ni
signature. Il reste dans l'historique, « Appliquée directement ».

Un contrat déjà généré mais pas encore envoyé porte alors « À régénérer » :
générez-en un qui correspond à la réservation avant de l'envoyer.

## Après le premier envoi : le contrat de remplacement

« Créer une modification » prépare un **projet** : les nouvelles
valeurs, et le prix qui en découle.

Le projet bloque aussi ses nouvelles dates, en plus des anciennes ; si
elles sont déjà occupées par une autre réservation, l'enregistrement est
refusé.

Le projet suit quatre étapes, visibles en haut de sa page :

1. « Préparer les nouvelles conditions » — vous ajustez et enregistrez
   autant de fois que nécessaire ;
2. « Envoyer le contrat de remplacement » — un clic sur « Générer et
   envoyer le contrat de remplacement », et le locataire le reçoit par
   e-mail et le retrouve sur sa page de suivi ;
3. « Recevoir la copie signée » — il dépose sa copie, ou vous la déposez
   vous-même dans « Documents » si elle arrive autrement ;
4. « Contresigner : la modification s'applique » — après « Vérifier et
   contresigner », la réservation prend les nouvelles valeurs, l'ancien contrat et ses copies sont
   marqués « Remplacé le », et la créance passe au nouveau montant.

Une copie refusée laisse le projet en cours. Il n'y a qu'un projet à
la fois par réservation.

## Le prix

Les lignes du tarif se lisent sans se modifier : elles suivent les dates,
les unités et les participants. Pour un geste commercial, remplissez la
ligne « Remise — libellé » et son « Montant » ; elle s'affiche telle
quelle au locataire. Un prix devenu inférieur à ce qui est déjà payé
laisse un trop-perçu à rembourser.

## Annuler un projet

« Annuler la modification » ferme le projet sans toucher à la
réservation et libère les dates qu'il bloquait. Si le contrat de
remplacement avait été envoyé, le locataire en est prévenu par e-mail ;
ses documents restent visibles, marqués « Modification annulée le ».
Un projet se ferme aussi tout seul quand la réservation est annulée,
refusée ou expire.

L'historique, en bas de la page « Modifications », garde chaque
changement avec son statut et son auteur ; « Consulter » ouvre le
détail, même pour un projet annulé.

> Dès qu'un contrat est parti, la réservation ne change plus que par la
> contresignature d'un contrat de remplacement. Et annuler un projet dont
> le contrat a été envoyé prévient le locataire par e-mail.
