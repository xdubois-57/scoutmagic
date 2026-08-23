---
id: reglages
title: Modifier les réglages du site
summary: La page Réglages : les paramètres du site et des modules, groupe par groupe.
category: Configuration
role_min: superadmin
paths: /config/settings
related: modules, installation-serveur
---

La page Réglages rassemble les paramètres du cœur du site et de chaque
module actif, en sections repliables. Le badge de chaque section
indique combien de réglages elle contient.

## Modifier une valeur

Touchez une ligne : une fenêtre s'ouvre avec la description du réglage
et un champ adapté — case à cocher, liste de choix, couleur, texte.
« Enregistrer » applique la valeur immédiatement, et chaque
modification est consignée au journal avec l'ancienne et la nouvelle
valeur.

Une ligne grisée avec un cadenas est en lecture seule : ce réglage se
gère depuis une page dédiée, ou décrit un état du serveur qui ne se
change pas ici.

## Ce qu'on ne trouve pas ici

Certains paramètres ont volontairement leur propre page, parce qu'un
simple champ ne suffirait pas à en expliquer les conséquences :

- l'identité du site, la base de données, l'e-mail et le logo :
  page « Installation & serveur » ;
- les mises à jour automatiques et les sauvegardes : page
  « Maintenance » ;
- les statistiques d'utilisation : page « Support » ;
- l'activation des fonctions du site : page « Modules ».

Les valeurs secrètes (mots de passe, clés) n'apparaissent jamais sur
cette page.

## Deux réglages à connaître

- La **plage d'heures calmes par défaut** des notifications : elle
  s'applique à tous les membres qui n'ont pas défini la leur.
- Les **couleurs de l'application installée** : changer la couleur de
  fond régénère automatiquement les icônes de l'application à partir
  du logo de l'unité.

En cas de doute sur un réglage, sa description dit à quoi il sert ; ne
changez que ce que vous comprenez — la plupart des valeurs par défaut
conviennent à toutes les unités.
