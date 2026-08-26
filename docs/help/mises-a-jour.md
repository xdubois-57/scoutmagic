---
id: mises-a-jour
title: Mettre à jour le site
summary: Vérifier, installer et automatiser les mises à jour.
category: Configuration
role_min: admin
paths: /config/maintenance
related: sauvegardes, reinitialisation
---

Le site se met à jour depuis ses versions officielles publiées en
ligne, sans manipulation de fichiers. Le bloc « Mise à jour » de la
page Maintenance affiche la version installée, ses notes de version,
et la date de la dernière vérification.

## Vérifier et installer

« Vérifier maintenant » interroge la source des versions. Si une
version plus récente existe, ses notes s'affichent avec un bouton
« Installer ». Avant toute installation, le site prend seul une
sauvegarde complète de sécurité ; pendant l'installation, les
visiteurs voient une page « Mise à jour en cours » qui se rafraîchit
d'elle-même.

Ce bouton propose toujours la dernière version disponible, quel que
soit le niveau choisi dans « Types de versions à installer » : ce
réglage ne concerne que les installations automatiques, pas ce que
vous décidez d'installer vous-même. Deux exceptions :

- en mode « Développement », c'est le dernier état de la branche
  surveillée qui est proposé, quel qu'il soit ;
- si plusieurs versions **majeures** sont parues depuis la vôtre, le
  site propose la première d'entre elles plutôt que la toute
  dernière, et l'indique (« étape intermédiaire vers la version… »).
  Les versions majeures s'installent ainsi une par une : chaque étape
  a sa propre sauvegarde de sécurité et ses propres migrations de
  données, contrôlées séparément. Relancez « Vérifier maintenant »
  après chaque étape pour passer à la suivante.

Si l'installation échoue, le site se restaure automatiquement depuis
la sauvegarde de sécurité — l'historique du bas de bloc l'indique
alors « Échouée — restaurée automatiquement ». L'installation
elle-même demande le rôle d'administrateur du site.

## Automatiser

Le bloc « Mises à jour automatiques » installe les nouvelles versions
sans intervention, au créneau hebdomadaire que vous choisissez (jour
et heure, heure belge). Trois niveaux :

- **Patch uniquement** : corrections de bugs et de sécurité.
- **Patch + Mineur** (recommandé) : ajoute les nouvelles
  fonctionnalités compatibles.
- **Patch + Mineur + Majeur** : y compris les versions qui changent
  le fonctionnement en profondeur — déconseillé en automatique sur un
  site utilisé.

Le quatrième mode, « Développement », installe chaque évolution
immédiatement : il est réservé aux sites de test et le dit clairement.

Chaque installation automatique se signale ensuite, réussie ou non, par
une notification dans la cloche : active d'office pour les super
administrateurs, éteinte et disponible pour un administrateur du site,
dans ses préférences de notification. « Installer maintenant » ne
prévient que vous.

> Restez sur « Patch + Mineur » pour un site d'unité : vous profitez
> des corrections et des nouveautés sans risquer un changement majeur
> non préparé un lundi à 3 h du matin.

## Si les nouvelles versions ne sont pas détectées

La détection automatique repose sur un lien entre votre site et la
source des versions ; la page vous avertit s'il n'est pas configuré.
« Vérifier maintenant » fonctionne toujours à la main, quel que soit
cet état.
