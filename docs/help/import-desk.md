---
id: import-desk
title: Importer le fichier Desk
summary: Mettre à jour les membres du site depuis l'export CSV de la fédération.
category: Espace chefs d'U
role_min: admin
question: Comment mettre à jour les membres depuis la fédération ?
question: Où récupérer le fichier CSV à importer dans le site ?
question: Que se passe-t-il si j'importe deux fois le même fichier ?
paths: /admin/import
related: import-desk-annee, import-desk-lignes-refusees, annee-scoute, config-desk, membres-admin, import-historique
---

Le site ne s'alimente pas à la main : les membres, sections et
fonctions viennent du fichier CSV exporté depuis Desk, la plateforme de
la fédération. Importer ce fichier met le site à jour d'un coup.

## Importer

1. Dans Desk, exportez la liste des membres au format CSV.
2. Vérifiez l'« Année scoute cible » : la page ne propose que les
   années importables aujourd'hui et choisit la bonne (voir « Quelle
   année scoute importer, et quand »).
3. Sélectionnez le fichier et touchez « Importer ».

Le fichier doit être l'export complet de Desk : si des colonnes
manquent, l'import s'arrête en les nommant, sans rien modifier. Il
s'arrête de même sur la moindre valeur illisible — une date de
naissance absente, un VRAI à la place de true — et liste alors toutes
les lignes à corriger (voir « Un fichier Desk refusé ligne par ligne »).
Un import réussi affiche le nombre de membres traités.

## Si le site refuse le fichier

L'erreur la plus courante est d'exporter une seule section au lieu de
tous les membres. Le site s'en aperçoit avant d'écrire quoi que ce soit
et vous montre ce que cet import ferait : combien de membres seraient
désactivés, quelles sections disparaîtraient des sélecteurs, et si vous
perdriez votre propre accès. Rien n'est modifié tant que vous n'avez
rien confirmé.

Dans la plupart des cas, refaire l'export depuis Desk sans filtre de
section suffit. Si le fichier est bien celui que vous vouliez, une zone
« Forcer l'import malgré tout » vous demande de le redéposer et de taper
**REMPLACER**. Le fichier refusé n'est pas conservé, d'où le nouveau
dépôt.

> Un import qui retirerait le dernier chef d'unité est refusé même avec
> le mot de confirmation : plus personne ne pourrait alors ouvrir la
> Configuration pour réparer.

## Ce que l'import fait

Pour l'année choisie, l'import remplace l'état du site par celui du
fichier : les membres présents sont mis à jour, les absents deviennent
inactifs. Une section sans aucun membre disparaît des sélecteurs du
site — elle n'est pas supprimée et revient dès qu'un import lui redonne
des membres. Tout se fait d'un bloc : en cas d'erreur, rien n'est
appliqué à moitié.

Corriger l'adresse e-mail d'un membre dans Desk puis importer retire à
l'ancienne adresse tout accès à sa fiche, session ouverte comprise.

## Les nouvelles fonctions

À leur première apparition, même lors d'un import ultérieur, les fonctions
standard reçoivent leurs rôles confirmés : voyez
[Correspondances Desk](/aide/config-desk). Une fonction inconnue reste
« Animé », non confirmée, à configurer sur cette page. Les correspondances
déjà enregistrées et vos choix de responsable sont conservés, même si
une ancienne fonction n'est pas encore confirmée.

## Quand importer

Après chaque mise à jour notable dans Desk — nouvelles inscriptions,
changements de section — et, à la rentrée, dans le cadre du parcours
guidé de la page « Année scoute ». Le dernier import de l'année
sélectionnée est rappelé en bas de page, avec son historique, et
chaque import est consigné au journal.
