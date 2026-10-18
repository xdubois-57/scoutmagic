---
id: points-attention-liste
title: Ce que signalent les points d'attention
summary: Les points que le site détecte de lui-même, et ce qui les fait disparaître.
category: Espace chefs d'U
role_min: admin
discovery: off
question: Pourquoi le site dit-il qu'aucun import Desk n'est enregistré ?
question: Pourquoi un point sur la sauvegarde hors site ?
paths: /admin/points-attention
related: points-attention, points-attention-modules, import-desk, annee-scoute
---

Chaque point de la page Points d'attention vient d'un contributeur : le
site lui-même, ou un module activé. Voici ceux qui ne vont pas de soi.

## Ce que le site signale de lui-même

Trois points ne dépendent d'aucun module :

- **L'année publique est terminée** et personne n'a basculé vers la
  suivante. Le site continue à l'afficher ; le point mène à la page
  Année scoute.
- **Aucun import Desk n'est enregistré**, ce qui est le cas d'une
  installation neuve : le site n'a encore ni membres, ni sections, ni
  fonctions. Ou bien **le dernier import est trop ancien** : 60 jours par
  défaut, un délai que le superadmin règle dans Configuration ›
  Paramètres (« Délai avant de signaler un import Desk ancien »).
- **L'adresse postale de l'unité est vide**. Les documents officiels,
  comme le contrat et la facture d'une location, impriment « — » à sa
  place.

## Ce que signale la maintenance

Quatre réglages laissés de côté, avant que quoi que ce soit n'échoue :

- **la sauvegarde automatique désactivée** ;
- **aucune sauvegarde hors site** : les sauvegardes restent sur le
  serveur, et partiraient avec lui. Ce point s'affiche dès l'installation,
  et aussi quand la destination choisie est un dossier du disque du site ;
- **les mises à jour automatiques désactivées**, avec la version
  disponible quand le site en connaît une ;
- **une version qui ne s'installera pas d'elle-même**, parce qu'elle
  change plus que le niveau choisi n'accepte (une version majeure sous
  le niveau « Patch + Mineur », par exemple).

## Ce que signalent les modules

Chaque module activé ajoute ses propres points. Ceux qui ne vont pas de
soi — locations, discussions, inscriptions, courrier entrant,
intelligence artificielle, téléphone d'urgence, réseaux sociaux — sont
détaillés dans [Ce que signalent les modules](points-attention-modules).
