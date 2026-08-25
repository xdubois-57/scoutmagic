---
id: import-desk
title: Importer le fichier Desk
summary: Mettre à jour les membres du site depuis l'export CSV de la fédération.
category: Espace chefs d'U
role_min: admin
paths: /admin/import
related: annee-scoute, config-desk, membres-admin, import-historique
---

Le site ne s'alimente pas à la main : les membres, sections et
fonctions viennent du fichier CSV exporté depuis Desk, la plateforme de
la fédération. Importer ce fichier met le site à jour d'un coup.

## Importer

1. Dans Desk, exportez la liste des membres au format CSV.
2. Sur cette page, choisissez l'année scoute cible — l'année publique
   est proposée d'office, mais vous pouvez importer l'année prochaine
   sans rien changer à ce que le public voit.
3. Sélectionnez le fichier et touchez « Importer ».

Le fichier doit être l'export complet de Desk : si des colonnes
manquent, l'import s'arrête en les nommant, sans rien modifier. Un
import réussi affiche le nombre de membres traités.

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

## Les nouvelles fonctions

Une fonction Desk jamais vue est créée avec le rôle « Animé », sans
aucun accès particulier : **un import n'accorde jamais de droits tout
seul**. La page vous signale les fonctions à confirmer ; leur rôle réel
(Animé, Intendant, Chef, Chef d'Unité) s'attribue ensuite sur la page
« Config Desk », et prend effet à la prochaine connexion des personnes
concernées.

## Quand importer

Après chaque mise à jour notable dans Desk — nouvelles inscriptions,
changements de section — et, à la rentrée, dans le cadre du parcours
guidé de la page « Année scoute ». Le dernier import de l'année
publique est rappelé en bas de page, et chaque import est consigné au
journal.
