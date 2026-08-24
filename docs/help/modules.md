---
id: modules
title: Activer et désactiver les modules
summary: Choisir les fonctions du site, gérer leurs dépendances et leur ordre dans les menus.
category: Configuration
role_min: superadmin
paths: /config/modules
related: reglages
---

Les modules sont les fonctions optionnelles du site : calendrier,
galerie, groupes, finances, inscriptions... La page Modules les liste
avec leur version, leur description et un interrupteur.

## Activer

Basculer l'interrupteur prépare la base de données du module, met en
place ses réglages par défaut, puis fait apparaître ses pages dans les
menus — la page se recharge pour en tenir compte. Certains modules en
nécessitent d'autres : la ligne l'indique (« Nécessite : ... ») et
l'interrupteur reste bloqué tant que le module requis n'est pas actif.

## Désactiver

Désactiver un module retire ses pages des menus et rend ses adresses
introuvables — mais **ne supprime rien** : données et réglages sont
conservés, et tout revient à la réactivation. Un module dont un autre
dépend refuse de se désactiver tant que ce dernier est actif ; le
message vous dit lequel désactiver d'abord.

## L'ordre des menus

Glissez les lignes (ou utilisez les flèches Monter/Descendre sur
téléphone) pour choisir l'ordre des pages de modules dans les menus.
Les pages du cœur du site passent toujours en premier ; l'ordre choisi
ne joue qu'entre modules.

## Les badges d'état

- **Actif / Inactif** : l'état de l'interrupteur.
- **Erreur** : le module est mal formé — son détail s'affiche au
  survol du badge. Il ne peut pas être activé en l'état.
- **Manquant** : le module était installé mais ses fichiers ont
  disparu du serveur, généralement après une mise à jour ou une
  manipulation de fichiers. Ses données restent en base.

Certains modules n'apparaissent que sur des installations
particulières (outils de test, tableau de bord support) : leur absence
de la liste sur votre site est normale.
