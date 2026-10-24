---
id: publipostage
title: Faire un publipostage depuis Excel
summary: Écrire à chaque ligne d'un fichier Excel avec ses propres valeurs, variables comprises.
category: Espace animateurs
role_min: chief
discovery: 1
question: Comment envoyer un mail personnalisé depuis un fichier Excel ?
question: Comment mettre le prénom de chacun dans un e-mail groupé ?
question: Pourquoi la colonne Email est-elle ignorée quand il y a un Tiers ?
paths: /mass-mail
related: envoi-de-mails, membres-admin, publier-une-actualite
---

Le publipostage écrit à chaque ligne d'un fichier Excel avec ses
propres valeurs : rappels de paiement avec le montant de chacun, convocations
nominatives... Choisissez la liste « Publipostage » sur la page de
composition, puis déposez votre fichier dans la zone prévue.

## Le fichier attendu

Un fichier Excel (.xlsx), première feuille, première ligne d'en-têtes.
Deux colonnes ont un sens particulier :

- **Tiers** : l'identifiant fédération de l'animé — un message à
  chacune de ses adresses connues. Un ancien identifiant, ou celui d'un
  membre fusionné, mène à l'animé actuel ;
- **Email** : des adresses libres, séparées par « ; » ou par une
  virgule — un message par adresse, la même adresse écrite deux fois
  n'en reçoit qu'un.

Chaque ligne a l'une ou l'autre. Avec les deux, la colonne Email est
ignorée pour l'envoi (elle reste une variable) et l'import le signale.
Les fichiers Excel exportés par le site se réimportent tels quels.

Pour les réponses d'un formulaire d'article, le bouton « Écrire aux
répondants » prépare le brouillon sans détour par Excel.

Un fichier fautif est **refusé en entier**, avec la liste des lignes
en cause (Tiers inconnu, adresse invalide, ligne vide...).

## Une ligne, une communication

Chaque ligne est un envoi à part : une adresse présente sur deux lignes
reçoit deux messages, chacun avec ses propres valeurs. Aucune adresse
n'est jamais visible d'une autre.

Une ligne avec Tiers apparaît dans l'historique de la fiche de l'animé :
une entrée par ligne, quel que soit son nombre d'adresses. Une ligne
sans Tiers n'est rattachée à aucune fiche, même si l'adresse est celle
d'un membre.

## Les variables

Chaque colonne devient une variable : écrivez {{Prenom}} ou
{{Montant}} dans le sujet ou le message, ou insérez-la par le menu
« Variable ». Une variable inconnue reste visible telle quelle, et
l'aperçu de test la signale.

## Qui est l'expéditeur

Le message part sous le nom de votre section suivi de celui de l'unité,
depuis l'adresse du site : seule une adresse du domaine d'envoi peut
être signée, les autres (Telenet, Gmail…) finissent refusées ou en
indésirables. L'adresse de la section est mise en adresse de réponse,
donc « Répondre » arrive chez vous. L'aperçu de test montre cet
expéditeur réel ; un Chef d'Unité peut corriger l'adresse.

## Vérifier ligne par ligne

En mode test, l'écran montre l'e-mail final — expéditeur,
destinataire, sujet, message, pièces jointes — tel qu'il partira.
L'aperçu s'ouvre sur **une ligne prise au hasard**, pas sur celle que
vous aviez sous les yeux en écrivant, et les flèches font défiler le
fichier. L'e-mail de test part avec la ligne affichée : repérez-y une
colonne mal nommée ou une cellule vide.

Pour modifier le texte, utilisez « Repasser en brouillon ». L'onglet
« Destinataires » montre le fichier importé, avec toutes ses colonnes.

Les destinataires extérieurs à l'unité disposent du même lien de
désinscription que les membres, et le fichier importé est effacé
automatiquement après usage — les données ne restent pas
indéfiniment sur le site.
