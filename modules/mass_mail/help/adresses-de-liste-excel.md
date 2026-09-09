---
id: adresses-de-liste-excel
title: L'aller-retour Excel des adresses
summary: Exporter les adresses d'une liste, les modifier, et les renvoyer.
category: Espace chefs d'U
role_min: admin
question: Comment importer beaucoup d'adresses d'un coup ?
question: Pourquoi mon fichier d'adresses est-il refusé ?
question: Que devient une adresse désinscrite quand je réimporte le fichier ?
paths: /admin/listes-de-diffusion
related: adresses-de-liste, listes-de-diffusion
---

Pour ajouter, corriger ou retirer beaucoup d'adresses d'un coup, on passe
par un tableur. C'est l'outil prévu pour ça : l'écran, lui, sert à changer
une adresse ou deux.

## Le fichier

« Exporter en Excel » télécharge trois colonnes : « Nom », « Adresse » et
« Désinscrit ». Les deux premières se modifient. La troisième est là pour
information et n'est jamais relue : aucun import ne désinscrit ni ne
réabonne qui que ce soit.

Les colonnes sont reconnues **par leur en-tête**, jamais par leur
position. Vous pouvez donc les déplacer ou en ajouter d'autres, mais pas
renommer celles-là. Un en-tête que le site ne reconnaît pas fait refuser le
fichier entier, en le disant — c'est exactement ce que cette règle évite :
lu par position, le même fichier remplacerait vos adresses par une colonne
de noms, sans rien signaler.

## Le renvoi se fait en deux temps

« Remplacer depuis Excel » **remplace** les adresses de la liste : ce qui
ne figure pas dans le fichier est retiré. C'est la seule opération de cet
écran sans retour en arrière, alors elle demande deux gestes.

Le dépôt du fichier ne fait que l'analyser. Le site vous montre ce qui se
passerait — tant d'adresses ajoutées, tant d'inchangées, tant de supprimées
— et **rien n'est écrit** tant que vous n'avez pas confirmé. « Annuler »
ferme l'aperçu sans rien changer.

Les lignes dont l'adresse est invalide sont signalées une par une et
laissées de côté ; les autres passent. Deux lignes portant la même adresse
n'en font qu'une.

> Une adresse désinscrite reste désinscrite, qu'elle figure ou non dans le
> fichier. Elle n'est ni retirée par son absence, ni réabonnée par sa
> présence : un désabonnement qu'un tableur peut annuler ne vaut rien.
