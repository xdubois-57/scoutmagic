---
id: publipostage
title: Faire un publipostage depuis Excel
summary: Un e-mail personnalisé par ligne d'un fichier Excel, variables comprises.
category: Espace animateurs
role_min: chief
paths: /mass-mail
related: envoi-de-mails, membres-admin, publier-une-actualite
---

Le publipostage envoie un e-mail personnalisé par ligne d'un fichier
Excel : rappels de paiement avec le montant de chacun, convocations
nominatives... Choisissez la liste « Publipostage » dans le dialogue
de composition, puis importez votre fichier.

## Le fichier attendu

Un fichier Excel (.xlsx), première feuille, première ligne d'en-têtes.
Deux colonnes ont un sens particulier :

- **Tiers** : l'identifiant fédération du membre — l'e-mail part vers
  toutes ses adresses connues ;
- **Email** : une adresse libre (plusieurs possibles, séparées par
  « ; »).

Chaque ligne doit avoir l'une ou l'autre. Les fichiers Excel exportés
par le site — membres par section, recherche de membres, réponses de
formulaire — se réimportent tels quels, sans retouche.

Pour les réponses d'un formulaire d'article, vous n'avez d'ailleurs
plus besoin du détour par Excel : le bouton « Écrire aux répondants »,
sur l'onglet Réponses de l'article, prépare le brouillon directement.

Un fichier fautif est **refusé en entier**, avec la liste complète des
lignes en cause (Tiers inconnu, adresse invalide, ligne vide...) :
corrigez, puis réimportez. Rien n'est envoyé à moitié.

## Les variables

Chaque colonne du fichier devient une variable : écrivez
{{Prenom}} ou {{Montant}} dans le sujet ou le message — le menu
« Variable » de l'éditeur les insère sans faute de frappe. Une
variable inconnue n'est pas remplacée et reste visible : l'aperçu de
test la signale.

## Vérifier ligne par ligne

En mode test, un aperçu fait défiler les lignes du fichier avec les
vraies valeurs substituées ; l'e-mail de test part avec la ligne
affichée. C'est le moment de repérer une colonne mal nommée ou une
cellule vide.

À la différence des autres listes, le publipostage envoie **un e-mail
par ligne** : une personne présente deux fois recevra deux messages.
Les destinataires extérieurs à l'unité disposent du même lien de
désinscription que les membres, et le fichier importé est effacé
automatiquement après usage — les données ne restent pas
indéfiniment sur le site.
