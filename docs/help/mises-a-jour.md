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

> Restez sur « Patch + Mineur » pour un site d'unité : vous profitez
> des corrections et des nouveautés sans risquer un changement majeur
> non préparé un lundi à 3 h du matin.

## Si les nouvelles versions ne sont pas détectées

La détection automatique repose sur un lien entre votre site et la
source des versions ; la page vous avertit s'il n'est pas configuré.
« Vérifier maintenant » fonctionne toujours à la main, quel que soit
cet état.
