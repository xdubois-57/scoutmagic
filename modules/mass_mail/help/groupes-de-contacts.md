---
id: groupes-de-contacts
title: Écrire à quelqu'un qui n'est pas membre
summary: Les groupes de contacts d'une liste de diffusion, leur fichier Excel et la désinscription.
category: Espace chefs d'U
role_min: admin
question: Comment écrire à la commune ou au propriétaire du terrain de camp ?
question: Comment ajouter une adresse à une liste de diffusion ?
question: Pourquoi mon fichier de contacts est-il refusé ?
question: Pourquoi une adresse désinscrite reste-t-elle affichée ?
paths: /admin/listes-de-diffusion
related: listes-de-diffusion, se-desinscrire
---

La commune, la paroisse, le propriétaire d'un terrain de camp, un ancien
membre que la fédération ne connaît plus : ces gens-là n'ont pas de fiche,
donc aucun groupe de membres ne les désigne. Une liste les reçoit par un
**groupe de contacts** : un nom et un fichier Excel d'adresses. Une liste
peut en compter autant qu'il faut, à côté de ses groupes de membres.

## Le fichier est l'outil

Un groupe de contacts se modifie **uniquement par son fichier**. Pour
ajouter une personne, on ajoute une ligne au fichier ; pour en retirer une,
on retire sa ligne. Il n'y a pas d'ajout ni de correction à l'unité.

Le fichier a deux colonnes lues, « Nom » et « Adresse ». « Exporter en
Excel » en ajoute une troisième, « Désinscrit », pour information : elle
n'est jamais relue. Les colonnes sont reconnues **par leur en-tête**,
jamais par leur position : vous pouvez les déplacer ou en ajouter d'autres,
mais pas renommer celles-là. Un en-tête manquant fait refuser le fichier
entier, en le disant — lu par position, le même fichier remplacerait vos
adresses par une colonne de noms, sans rien signaler.

Pour un nouveau groupe, « Télécharger un modèle vide » donne un fichier qui
porte déjà les bons en-têtes.

## Rien n'est écrit avant « Enregistrer »

Le fichier est **analysé dès qu'il est choisi** — « Choisir un fichier »
pour un nouveau groupe, « Choisir un fichier corrigé » pour un groupe
existant — et le dialogue dit ce qui se passerait : tant de contacts
ajoutés, retirés, inchangés, de doublons fusionnés, et chaque ligne ignorée
avec sa raison. Une adresse invalide est laissée de côté ; les autres
passent. Deux lignes portant la même adresse n'en font qu'une.

« Enregistrer » applique ensemble le nom et le fichier. « Annuler »
abandonne les deux. Le fichier **remplace** tous les contacts du groupe :
ce qui n'y figure plus est retiré.

Un groupe existant montre ses contacts en lecture seule. La recherche
ignore la casse et les accents, « Désinscrites seulement » isole les
adresses désinscrites, et la liste s'affiche par tranches, avec « Afficher
plus ».

## Une désinscription vaut pour toute l'unité

Quand quelqu'un clique sur le lien de désinscription au bas d'un e-mail,
son adresse est désinscrite dans **tous** les groupes de toutes les listes :
il s'adresse à l'unité, pas à une liste.

La ligne reste ensuite visible, grisée. Aucun fichier ne la retire ni ne la
réabonne : un désabonnement qu'un tableur peut annuler ne vaut rien. Elle
reste vraie même si le groupe est retiré de la liste.

Quelqu'un présent dans deux blocs d'une liste, ou à la fois contact et
membre, ne reçoit qu'un seul e-mail : le site compare les adresses.

> Un groupe ne peut pas contenir un nombre illimité de contacts. Le plafond
> est le réglage « Contacts par groupe (maximum) », et sa description
> explique pourquoi il existe : le déchiffrement des adresses a un coût, et
> une liste achetée abîmerait la réputation d'envoi de tout le domaine.
