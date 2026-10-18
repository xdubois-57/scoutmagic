---
id: points-attention-liste
title: Ce que signalent les points d'attention
summary: Les points que le site et ses modules savent détecter, et ce qui les fait disparaître.
category: Espace chefs d'U
role_min: admin
discovery: off
question: Pourquoi le site dit-il qu'aucun import Desk n'est enregistré ?
question: Pourquoi un point sur l'adresse du bailleur d'une location ?
paths: /admin/points-attention
related: points-attention, import-desk, annee-scoute
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

Un module ne signale rien tant qu'il est désactivé : ses points
apparaissent le jour où on l'active, et disparaissent si on le
désactive.

Le module Réseaux sociaux signale **une connexion Facebook ou Instagram
qui ne marche plus** : l'autorisation a expiré, ou Meta a refusé la
dernière vérification. Meta ne renouvelle pas une autorisation expirée :
il faut refaire la connexion dans Configuration › Réseaux sociaux.

Le module Locations signale, pour chaque bien qui n'est pas archivé :

- **l'adresse du bailleur vide** : le contrat et la facture de ce bien
  imprimeraient « — » à sa place. Si ce bien a son propre bailleur,
  c'est son adresse à lui qu'il faut renseigner ; sinon, celle du
  bailleur commun du module, ou à défaut l'adresse postale de l'unité.
  Le point dit lequel des trois manque ;
- **une conformité déjà expirée** dans le registre du bien, avec la date
  d'expiration. Avant cette date, c'est le rappel du registre qui
  prévient, pas cette page.

Le module Discussions signale, groupe par groupe, **les contenus signalés
qui attendent encore une décision** : tant qu'un modérateur ne les a ni
rétablis ni supprimés, ils restent comptés, même déjà masqués.

Le module Inscriptions signale, en une seule ligne, **les demandes en
attente depuis plus de 15 jours**, à partir du 1er juin et jusqu'à la fin
de l'année scoute. Le jour, le mois et la durée se règlent dans
Configuration › Paramètres, section Inscriptions.
