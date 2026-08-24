---
id: config-retro
title: Configurer les rétrospectives
summary: Qui peut créer et clôturer, les valeurs par défaut, la modération IA.
category: Espace chefs d'U
role_min: admin
paths: /config/retro
related: retrospectives, connecteur-ia
---

Cette page fixe les règles communes à toutes les rétrospectives de
l'unité ; chaque tableau garde ensuite ses propres réglages à sa
création.

## Qui crée, qui clôture

Deux rôles minimums se choisissent ici : celui qui permet de **créer**
un tableau (les intendants, d'origine) et celui qui permet de le
**clôturer** (les animateurs, d'origine). Élargissez ou resserrez
selon la confiance et les habitudes de vos staffs — clôturer arrête
définitivement les contributions, d'où un seuil séparé.

## Les valeurs par défaut

La longueur maximale d'un mot, le budget de points de vote et le
rythme de rafraîchissement du tableau à l'écran se règlent ici. Ce ne
sont que les valeurs proposées : chaque tableau peut s'en écarter à
sa création.

## La modération automatique

Si le connecteur IA du site est actif, chaque mot déposé peut être
vérifié avant publication. Trois modes :

- **Désactivée** : aucun contrôle automatique.
- **Avertissement** : un mot jugé blessant déclenche une mise en
  garde et une reformulation proposée — mais la personne reste libre
  de publier son texte tel quel.
- **Obligatoire** : la reformulation est exigée, le texte d'origine
  ne peut pas être publié.

Sans connecteur IA configuré, le choix est grisé et rien n'est
contrôlé.

> Les tableaux sont anonymes par construction : même en cas de mot
> déplacé, personne ne peut retrouver son auteur. La modération
> automatique et le masquage par le chef d'unité sont donc les deux
> seuls garde-fous — sur un tableau ouvert à de jeunes participants,
> le mode « Avertissement » est un bon minimum.
